<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Idempotency\IdempotencyStorage;
use Rasuvaeff\Yii3IdempotencyRedis\IdempotencyScriptRunner;
use Rasuvaeff\Yii3IdempotencyRedis\RedisIdempotencyStorage;

/** @var array $params */

return [
    // Binds the backend, not the facade: exactly one of yii3-idempotency-db /
    // yii3-idempotency-redis may sit next to the core — one vendor per DI key
    // is a yiisoft/config rule, and installing both is the documented error.
    // The runner is deliberately not bound here: it belongs to the
    // application, which already owns the Redis client (see README).
    IdempotencyStorage::class => static function (
        IdempotencyScriptRunner $runner,
        ClockInterface $clock,
    ) use ($params): RedisIdempotencyStorage {
        $config = $params['rasuvaeff/yii3-idempotency-redis'] ?? [];

        return new RedisIdempotencyStorage(
            runner: $runner,
            clock: $clock,
            keyPrefix: (string) ($config['keyPrefix'] ?? 'idempotency:'),
            claimTtlSeconds: (int) ($config['claimTtlSeconds'] ?? 3600),
        );
    },
];
