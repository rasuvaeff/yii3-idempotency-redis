<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyRedis\Tests\Integration;

use Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3IdempotencyRedis\Redis\PhpRedisScriptRunner;
use Rasuvaeff\Yii3IdempotencyRedis\RedisIdempotencyStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The phpredis path of {@see PhpRedisScriptRunner} — the EVALSHA-first
 * behaviour, the NOSCRIPT fallback, `clearLastError()` and the integer reply
 * coercion — over a real server. The predis twin runs in
 * {@see RedisIntegrationTest}; this one needs `ext-redis` as well as
 * REDIS_HOST, which only the CI integration job provides.
 */
#[Test]
#[Covers(PhpRedisScriptRunner::class)]
final class PhpRedisIntegrationTest
{
    private const string FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

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

        $this->storage = new RedisIdempotencyStorage(
            runner: new PhpRedisScriptRunner($client),
            clock: new SystemClock(),
            keyPrefix: 'test-idem-phpredis:',
        );
    }

    public function claimStoreLoadCycleThroughPhpRedis(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $key = new IdempotencyKey('cycle-' . bin2hex(random_bytes(6)));

        Assert::true($this->storage->claim($key, new IdempotencyFingerprint(self::FINGERPRINT)));
        Assert::null($this->storage->load($key));
        Assert::false($this->storage->claim($key, new IdempotencyFingerprint(self::FINGERPRINT)));
    }

    public function secondCallRunsFromTheScriptCache(): void
    {
        if (!isset($this->storage)) {
            return;
        }

        $first = new IdempotencyKey('cache-1-' . bin2hex(random_bytes(6)));
        $second = new IdempotencyKey('cache-2-' . bin2hex(random_bytes(6)));

        Assert::true($this->storage->claim($first, new IdempotencyFingerprint(self::FINGERPRINT)));
        Assert::true($this->storage->claim($second, new IdempotencyFingerprint(self::FINGERPRINT)));
    }
}
