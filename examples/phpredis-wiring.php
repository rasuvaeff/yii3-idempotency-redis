<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\Yii3IdempotencyRedis\Redis\PhpRedisScriptRunner;
use Rasuvaeff\Yii3IdempotencyRedis\RedisIdempotencyStorage;

/**
 * Wiring the storage over ext-redis. The \Redis instance is NOT connected
 * here on purpose: constructing the storage and the runner is lazy — no
 * command is sent until the first claim/load — so this script runs without a
 * server. Point it at one and the calls below work unchanged.
 */
if (!\extension_loaded('redis')) {
    printf("ext-redis is not loaded — install it to use PhpRedisScriptRunner (or see predis-wiring.php)\n");

    return;
}

$runner = new PhpRedisScriptRunner(new \Redis());

$clock = new class implements \Psr\Clock\ClockInterface {
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
};

$storage = new RedisIdempotencyStorage(
    runner: $runner,
    clock: $clock,
    keyPrefix: 'idempotency:',
    claimTtlSeconds: 3600,
);

printf(
    "RedisIdempotencyStorage over PhpRedisScriptRunner, prefix=%s claimTtl=%ds — ready\n",
    'idempotency:',
    3600,
);
