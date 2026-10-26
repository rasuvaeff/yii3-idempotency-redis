<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyRedis\Tests\Integration;

use Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3IdempotencyRedis\Redis\PhpRedisScriptRunner;
use Rasuvaeff\Yii3IdempotencyRedis\RedisIdempotencyStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The phpredis path of {@see PhpRedisScriptRunner} — the EVALSHA-first
 * behaviour, the NOSCRIPT fallback, `clearLastError()` and the integer reply
 * coercion — over a real server. The predis twin runs in
 * {@see RedisIntegrationTest}; this one needs `ext-redis` as well as
 * REDIS_HOST, which only the CI integration job provides.
 *
 * Every test runs inside {@see self::coldCache()}: the NOSCRIPT branch and
 * the stale-error dance are only reachable from a cold script cache, and the
 * cache is a server-global, cross-process resource. Infection's kill runs
 * spawn concurrent testo processes against one server, so the tests
 * serialise on a local file lock — without it a concurrent flush landing
 * inside another test's window turns a deterministic kill into a flaky
 * escape.
 */
#[Test]
#[Covers(PhpRedisScriptRunner::class)]
final class PhpRedisIntegrationTest
{
    private const string FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private \Redis $client;

    private PhpRedisScriptRunner $runner;

    private RedisIdempotencyStorage $storage;

    #[BeforeTest]
    public function setUp(): void
    {
        $host = getenv('REDIS_HOST');

        if ($host === false || $host === '' || !\extension_loaded('redis')) {
            return;
        }

        $client = new \Redis();
        $client->connect($host, (int) (getenv('REDIS_PORT') ?: 6379));

        $this->client = $client;
        $this->runner = new PhpRedisScriptRunner($client);
        $this->storage = new RedisIdempotencyStorage(
            runner: $this->runner,
            clock: new SystemClock(),
            keyPrefix: 'test-idem-phpredis:',
        );
    }

    public function claimStoreLoadCycleThroughPhpRedis(): void
    {
        if (!$this->ready()) {
            return;
        }

        $this->coldCache(static function (self $self): void {
            $key = new IdempotencyKey('cycle-' . bin2hex(random_bytes(6)));

            Assert::true($self->storage->claim($key, new IdempotencyFingerprint(self::FINGERPRINT)));
            Assert::null($self->storage->load($key));
            Assert::false($self->storage->claim($key, new IdempotencyFingerprint(self::FINGERPRINT)));
        });
    }

    public function secondCallRunsFromTheScriptCache(): void
    {
        if (!$this->ready()) {
            return;
        }

        $this->coldCache(static function (self $self): void {
            $first = new IdempotencyKey('cache-1-' . bin2hex(random_bytes(6)));
            $second = new IdempotencyKey('cache-2-' . bin2hex(random_bytes(6)));

            Assert::true($self->storage->claim($first, new IdempotencyFingerprint(self::FINGERPRINT)));
            Assert::true($self->storage->claim($second, new IdempotencyFingerprint(self::FINGERPRINT)));
        });
    }

    public function everyKeyReachesTheScript(): void
    {
        // KEYS must arrive whole: a runner that ships only the first key
        // corrupts every multi-key script the API allows
        if (!$this->ready()) {
            return;
        }

        $this->coldCache(static function (self $self): void {
            $result = $self->runner->run('return KEYS[1] .. "|" .. KEYS[2]', [
                'idempotency:test-a',
                'idempotency:test-b',
            ], []);

            Assert::same($result, 'idempotency:test-a|idempotency:test-b');
        });
    }

    public function doesNotInheritAStaleLastErrorFromAnEarlierCommand(): void
    {
        // a failed command leaves lastError set on the client; without the
        // initial clear the next successful script would be read as a
        // failure. The cache is warmed first so the second run takes the
        // EVALSHA path — an uncached script would overwrite the stale error
        // with its own NOSCRIPT and the test would prove nothing.
        if (!$this->ready()) {
            return;
        }

        $this->coldCache(static function (self $self): void {
            $self->runner->run('return 42', ['idempotency:test-stale'], []);

            $self->client->rawCommand('GET', 'too', 'many', 'args');
            Assert::string($self->client->getLastError())->contains('wrong number');

            $result = $self->runner->run('return 42', ['idempotency:test-stale'], []);

            Assert::same($result, 42);
        });
    }

    public function throwsWhenTheScriptFailsServerSide(): void
    {
        if (!$this->ready()) {
            return;
        }

        $this->coldCache(static function (self $self): void {
            Expect::exception(\RuntimeException::class)->withMessageContaining('Redis script failed');

            $self->runner->run('this is not lua', ['idempotency:test-broken'], []);
        });
    }

    public function theThrowPathClearsLastErrorOnItsWayOut(): void
    {
        // after the thrown failure the client must be clean: a leftover
        // compile error would fail the next script before it even runs
        if (!$this->ready()) {
            return;
        }

        $this->coldCache(static function (self $self): void {
            try {
                $self->runner->run('this is not lua', ['idempotency:test-broken'], []);
            } catch (\RuntimeException $e) {
                Assert::string($e->getMessage())->contains('Redis script failed');
            }

            Assert::null($self->client->getLastError());
        });
    }

    /**
     * True when the server-backed fixtures exist (REDIS_HOST + ext-redis);
     * false otherwise, which is the suite's skip.
     */
    private function ready(): bool
    {
        return isset($this->client, $this->runner, $this->storage);
    }

    /**
     * Runs the body with exclusive access to the server's script cache: a
     * flush first, then the body, both under a local file lock shared by all
     * concurrently running test processes.
     *
     * @param callable(self): void $body
     */
    private function coldCache(callable $body): void
    {
        $lock = fopen(sys_get_temp_dir() . '/yii3-idempotency-redis-script-cache.lock', 'c');

        flock($lock, \LOCK_EX);

        try {
            $this->client->rawCommand('SCRIPT', 'FLUSH');
            $body($this);
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }
}
