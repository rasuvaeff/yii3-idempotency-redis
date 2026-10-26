<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyRedis;

use Rasuvaeff\Yii3IdempotencyRedis\Internal\ClaimTokenMap;

/**
 * Remembers the random token each claim generated, so a later store or release
 * can tell "the claim I took" from "a claim someone else took after mine
 * expired away".
 *
 * Unlike the database adapter — whose predictable `expires_at` value doubles
 * as its ownership token — the token here is random: a Redis hash field costs
 * nothing, and an unpredictable token cannot be assumed by a competitor the
 * way a computed deadline could.
 *
 * Entries are dropped on `store()` and `release()`; the cap bounds the map in
 * a long-running worker where a claim occasionally ends in neither.
 *
 * @internal
 */
final readonly class ClaimTokens
{
    public function __construct(
        private ClaimTokenMap $map = new ClaimTokenMap(),
    ) {}

    public function remember(string $key, string $token): void
    {
        $this->map->set($key, $token);
    }

    /**
     * The token this instance generated for the key, if any, dropping it:
     * reading and spending the token is one operation, so a store that ran
     * twice cannot fence twice.
     */
    public function forget(string $key): ?string
    {
        return $this->map->remove($key);
    }
}
