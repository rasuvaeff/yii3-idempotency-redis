<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Predis\ClientInterface;
use Rasuvaeff\Yii3IdempotencyRedis\IdempotencyScriptRunner;
use Rasuvaeff\Yii3IdempotencyRedis\Redis\PredisScriptRunner;
use Rasuvaeff\Yii3IdempotencyRedis\RedisIdempotencyStorage;

/**
 * The DI wiring an application ends up with: the runner belongs to the app
 * (it already owns the predis client), the storage binds IdempotencyStorage.
 * The client here is constructed lazily by predis — no connection happens
 * until a command runs, so this script works without a server.
 */
$runnerFor = static fn(ClientInterface $client): IdempotencyScriptRunner
    => new PredisScriptRunner($client);

$clock = new class implements \Psr\Clock\ClockInterface {
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
};

$storage = new RedisIdempotencyStorage(
    runner: $runnerFor(new \Predis\Client()), // your configured client
    clock: $clock,
    keyPrefix: 'payments:idem:',
    claimTtlSeconds: 600,
);

printf(
    "RedisIdempotencyStorage over PredisScriptRunner, prefix=%s claimTtl=%ds — ready\n",
    'payments:idem:',
    600,
);
