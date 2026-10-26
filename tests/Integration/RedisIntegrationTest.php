<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyRedis\Tests\Integration;

use Predis\Client;
use Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyRecord;
use Rasuvaeff\Yii3Idempotency\IdempotencyResponse;
use Rasuvaeff\Yii3IdempotencyRedis\Redis\PredisScriptRunner;
use Rasuvaeff\Yii3IdempotencyRedis\RedisIdempotencyStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The Lua scripts' real semantics, against a running Redis: the unit suite
 * mocks the runner, so only this suite can prove the scripts themselves.
 *
 * Skipped unless REDIS_HOST is set (the CI coverage/mutation job runs it with
 * a service container; `composer build` never does).
 */
#[Test]
#[Covers(RedisIdempotencyStorage::class)]
final class RedisIntegrationTest
{
    private const string FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const string OTHER_FINGERPRINT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private Client $client;

    private RedisIdempotencyStorage $storage;

    private SystemClock $clock;

    private string $prefix;

    #[BeforeTest]
    public function setUp(): void
    {
        $host = getenv('REDIS_HOST');

        if ($host === false || $host === '') {
            return;
        }

        $port = (string) (getenv('REDIS_PORT') ?: '6379');
        $this->client = new Client(['host' => $host, 'port' => $port]);
        $this->clock = new SystemClock();
        $this->prefix = 'test-idem:' . bin2hex(random_bytes(4)) . ':';
        $this->storage = new RedisIdempotencyStorage(
            runner: new PredisScriptRunner($this->client),
            clock: $this->clock,
            keyPrefix: $this->prefix,
        );
    }

    public function fullClaimStoreLoadCycle(): void
    {
        if (!$this->ready()) {
            return;
        }

        $key = $this->key('cycle');

        Assert::true($this->storage->claim($key, $this->fingerprint()));
        Assert::null($this->storage->load($key));

        $this->storage->store($this->record($key, body: "created \x00\x01 binary", expiresIn: 600));

        $loaded = $this->storage->load($key);

        Assert::notNull($loaded);
        Assert::same($loaded->response->statusCode, 201);
        Assert::same($loaded->response->headers, ['X-Resource-Id' => ['42']]);
        Assert::same($loaded->response->body, "created \x00\x01 binary");
        Assert::same($loaded->fingerprint->hash, self::FINGERPRINT);
    }

    public function onlyOneOfTwoCompetingClaimsWins(): void
    {
        if (!$this->ready()) {
            return;
        }

        $key = $this->key('compete');
        $competitor = $this->secondInstance();

        Assert::true($this->storage->claim($key, $this->fingerprint()));
        Assert::false($competitor->claim($key, $this->fingerprint()));
    }

    public function claimCapabilitiesAnswerWhileInFlight(): void
    {
        if (!$this->ready()) {
            return;
        }

        $key = $this->key('capabilities');
        $this->storage->claim($key, $this->fingerprint());

        $claimedFingerprint = $this->storage->claimedFingerprint($key);
        $deadline = $this->storage->claimedDeadline($key);

        Assert::notNull($claimedFingerprint);
        Assert::same($claimedFingerprint->hash, self::FINGERPRINT);

        Assert::notNull($deadline);
        Assert::true($deadline->getTimestamp() > $this->clock->now()->getTimestamp());
        Assert::true($deadline->getTimestamp() <= $this->clock->now()->getTimestamp() + 3600);
    }

    public function capabilitiesAnswerNullAfterStore(): void
    {
        if (!$this->ready()) {
            return;
        }

        $key = $this->key('stored');
        $this->storage->claim($key, $this->fingerprint());
        $this->storage->store($this->record($key, expiresIn: 600));

        Assert::null($this->storage->claimedFingerprint($key));
        Assert::null($this->storage->claimedDeadline($key));
    }

    public function releaseFreesTheKeyForASecondClaim(): void
    {
        if (!$this->ready()) {
            return;
        }

        $key = $this->key('release');
        $this->storage->claim($key, $this->fingerprint());
        $this->storage->release($key);

        Assert::null($this->storage->claimedFingerprint($key));
        Assert::true($this->storage->claim($key, $this->fingerprint()));
    }

    public function expiredClaimFreesTheKeyWithoutAnyoneReleasing(): void
    {
        if (!$this->ready()) {
            return;
        }

        $storage = new RedisIdempotencyStorage(
            runner: new PredisScriptRunner($this->client),
            clock: $this->clock,
            keyPrefix: 'test-idem-ttl:',
            claimTtlSeconds: 1,
        );
        $key = $this->key('ttl');

        Assert::true($storage->claim($key, $this->fingerprint()));

        sleep(2);

        Assert::null($storage->claimedFingerprint($key));
        Assert::true($storage->claim($key, $this->fingerprint()));
    }

    public function aZombieStoreCannotOverwriteACompetitorsRecord(): void
    {
        if (!$this->ready()) {
            return;
        }

        $storage = new RedisIdempotencyStorage(
            runner: new PredisScriptRunner($this->client),
            clock: $this->clock,
            keyPrefix: 'test-idem-zombie:',
            claimTtlSeconds: 1,
        );
        $zombie = $this->key('zombie');

        // the zombie takes the claim and "hangs" past its TTL
        Assert::true($storage->claim($zombie, $this->fingerprint()));

        sleep(2);

        // a competitor claims the freed key and stores its record
        $competitor = new RedisIdempotencyStorage(
            runner: new PredisScriptRunner($this->client),
            clock: $this->clock,
            keyPrefix: 'test-idem-zombie:',
            claimTtlSeconds: 3600,
        );
        Assert::true($competitor->claim($zombie, $this->fingerprint()));
        $competitor->store($this->record($zombie, body: 'competitor', expiresIn: 600));

        // the zombie wakes up and tries to store its own answer
        $storage->store($this->record($zombie, body: 'zombie', expiresIn: 600));

        $loaded = $competitor->load($zombie);

        Assert::notNull($loaded);
        Assert::same($loaded->response->body, 'competitor');
    }

    public function aZombieReleaseCannotDeleteACompetitorsClaim(): void
    {
        if (!$this->ready()) {
            return;
        }

        $storage = new RedisIdempotencyStorage(
            runner: new PredisScriptRunner($this->client),
            clock: $this->clock,
            keyPrefix: 'test-idem-zrel:',
            claimTtlSeconds: 1,
        );
        $zombie = $this->key('zombie-release');

        Assert::true($storage->claim($zombie, $this->fingerprint()));

        sleep(2);

        $competitor = new RedisIdempotencyStorage(
            runner: new PredisScriptRunner($this->client),
            clock: $this->clock,
            keyPrefix: 'test-idem-zrel:',
            claimTtlSeconds: 3600,
        );
        Assert::true($competitor->claim($zombie, $this->fingerprint()));

        // the zombie's late release must not delete the competitor's claim
        $storage->release($zombie);

        Assert::notNull($competitor->claimedFingerprint($zombie));
        Assert::same($competitor->claimedFingerprint($zombie)?->hash, self::FINGERPRINT);
    }

    public function fingerprintsSurviveARoundTripForAnyKey(): void
    {
        if (!$this->ready()) {
            return;
        }

        $key = $this->key('round-trip.' . bin2hex(random_bytes(4)));

        Assert::true($this->storage->claim($key, $this->fingerprint()));
        $this->storage->store($this->record($key, expiresIn: 600));

        $loaded = $this->storage->load($key);

        Assert::notNull($loaded);
        Assert::true($loaded->fingerprint->equals($this->fingerprint()));
        Assert::false($loaded->fingerprint->equals($this->fingerprint(self::OTHER_FINGERPRINT)));
    }

    private function ready(): bool
    {
        // setUp() leaves the storage unset when REDIS_HOST is absent — the
        // suite's skip.
        return isset($this->storage);
    }

    private function secondInstance(): RedisIdempotencyStorage
    {
        // the same prefix: a competitor is another process, not another keyspace
        return new RedisIdempotencyStorage(
            runner: new PredisScriptRunner($this->client),
            clock: $this->clock,
            keyPrefix: $this->prefix,
        );
    }

    private function key(string $name): IdempotencyKey
    {
        return new IdempotencyKey($name . '-' . bin2hex(random_bytes(6)));
    }

    private function fingerprint(string $hash = self::FINGERPRINT): IdempotencyFingerprint
    {
        return new IdempotencyFingerprint($hash);
    }

    private function record(IdempotencyKey $key, string $body = 'created', int $expiresIn = 600): IdempotencyRecord
    {
        return IdempotencyRecord::restore(
            key: $key,
            fingerprint: $this->fingerprint(),
            response: new IdempotencyResponse(
                statusCode: 201,
                headers: ['X-Resource-Id' => ['42']],
                body: $body,
            ),
            expiresAt: $this->clock->now()->modify(sprintf('%+d seconds', $expiresIn)),
        );
    }
}
