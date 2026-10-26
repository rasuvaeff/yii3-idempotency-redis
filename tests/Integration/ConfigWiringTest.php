<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyRedis\Tests\Integration;

use Psr\Clock\ClockInterface;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Idempotency\IdempotencyStorage;
use Rasuvaeff\Yii3IdempotencyRedis\IdempotencyScriptRunner;
use Rasuvaeff\Yii3IdempotencyRedis\RedisIdempotencyStorage;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Expect;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

/**
 * Exercises the package `config/di.php`, which is covered by neither cs, psalm,
 * nor the unit suite. Cross-package merging is verified separately with a real
 * yiisoft/config merge plan (`bin/config-merge-harness` on the family).
 */
#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    public function bindsOnlyItsOwnKeys(): void
    {
        Assert::same(
            array_keys($this->loadDi([])),
            [IdempotencyStorage::class],
        );
    }

    public function storageFactoryBuildsRedisStorage(): void
    {
        $storage = $this->resolveStorage([
            'rasuvaeff/yii3-idempotency-redis' => ['keyPrefix' => 'payments:idem:', 'claimTtlSeconds' => 60],
        ]);

        Assert::instanceOf($storage, RedisIdempotencyStorage::class);
    }

    public function storageFactoryUsesDefaultsWhenParamsAbsent(): void
    {
        Assert::instanceOf($this->resolveStorage([]), RedisIdempotencyStorage::class);
    }

    public function storageFactoryPassesParamsThrough(): void
    {
        // the constructor rejects an empty prefix, so the throw proves the
        // param reached it instead of being silently dropped
        Expect::exception(\InvalidArgumentException::class)->withMessage('keyPrefix must not be empty');

        $this->resolveStorage([
            'rasuvaeff/yii3-idempotency-redis' => ['keyPrefix' => ''],
        ]);
    }

    private function resolveStorage(array $params): IdempotencyStorage
    {
        $definitions = $this->loadDi($params);
        $factory = $definitions[IdempotencyStorage::class];
        Assert::true(is_callable($factory));

        $runner = Understudy::for(IdempotencyScriptRunner::class);
        when(fn() => $runner->run(Arg::any(), Arg::any(), Arg::any()))->returns(0);

        $now = new \DateTimeImmutable('2026-10-26 12:00:00');
        $clock = new readonly class ($now) implements ClockInterface {
            public function __construct(
                private \DateTimeImmutable $now,
            ) {}

            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return $this->now;
            }
        };

        $storage = $factory($runner, $clock);
        Assert::instanceOf($storage, IdempotencyStorage::class);

        return $storage;
    }

    private function loadDi(array $params): array
    {
        return require dirname(__DIR__, 2) . '/config/di.php';
    }
}
