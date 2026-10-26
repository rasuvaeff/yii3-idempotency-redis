<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyRedis\Tests;

use Psr\Clock\ClockInterface;

/**
 * @internal
 */
final readonly class FakeClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(string $time = '2026-10-26 12:00:00')
    {
        $this->now = new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
