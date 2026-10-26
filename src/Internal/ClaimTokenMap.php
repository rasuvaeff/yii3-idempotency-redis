<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyRedis\Internal;

/**
 * The mutable state behind {@see \Rasuvaeff\Yii3IdempotencyRedis\ClaimTokens},
 * kept in its own class so the facade over it can stay `final readonly`.
 *
 * @internal
 */
final class ClaimTokenMap
{
    private const int MAX_ENTRIES = 1024;

    /**
     * @var array<string, string>
     */
    private array $entries = [];

    public function set(string $key, string $token): void
    {
        if (\count($this->entries) >= self::MAX_ENTRIES) {
            array_shift($this->entries);
        }

        $this->entries[$key] = $token;
    }

    /**
     * The token this instance generated for the key, if any, dropping it.
     */
    public function remove(string $key): ?string
    {
        $token = $this->entries[$key] ?? null;

        unset($this->entries[$key]);

        return $token;
    }
}
