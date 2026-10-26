<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyRedis\Tests;

use Rasuvaeff\Yii3IdempotencyRedis\Internal\ClaimTokenMap;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ClaimTokenMap::class)]
final class ClaimTokenMapTest
{
    public function remembersAndForgetsAToken(): void
    {
        $map = new ClaimTokenMap();

        $map->set('key-1', 'token-1');

        Assert::same($map->remove('key-1'), 'token-1');
    }

    public function forgettingIsDropping(): void
    {
        $map = new ClaimTokenMap();
        $map->set('key-1', 'token-1');

        $map->remove('key-1');

        Assert::null($map->remove('key-1'));
    }

    public function forgetsOnlyTheAskedKey(): void
    {
        $map = new ClaimTokenMap();
        $map->set('key-1', 'token-1');
        $map->set('key-2', 'token-2');

        $map->remove('key-1');

        Assert::same($map->remove('key-2'), 'token-2');
    }

    public function forgetOfAnUnknownKeyIsNull(): void
    {
        Assert::null((new ClaimTokenMap())->remove('missing'));
    }

    public function dropsTheOldestEntryPastTheCap(): void
    {
        $map = new ClaimTokenMap();

        $map->set('first', 'token-first');

        for ($i = 0; $i < 1024; $i++) {
            $map->set("bulk-{$i}", "token-{$i}");
        }

        Assert::null($map->remove('first'));
        Assert::same($map->remove('bulk-0'), 'token-0');
        Assert::same($map->remove('bulk-1023'), 'token-1023');
    }

    public function reSettingAnExistingKeyDoesNotEvict(): void
    {
        $map = new ClaimTokenMap();

        $map->set('key-1', 'token-a');
        $map->set('key-1', 'token-b');

        Assert::same($map->remove('key-1'), 'token-b');
    }
}
