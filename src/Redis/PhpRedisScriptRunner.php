<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyRedis\Redis;

use Rasuvaeff\Yii3IdempotencyRedis\IdempotencyScriptRunner;

/**
 * phpredis-backed {@see IdempotencyScriptRunner}. Requires `ext-redis`.
 *
 * Sends EVALSHA first so the (constant) script body is not re-transmitted and
 * re-hashed by Redis on every call; falls back to EVAL once per script cache
 * miss (NOSCRIPT), which also loads the script into the cache.
 *
 * @api
 */
final readonly class PhpRedisScriptRunner implements IdempotencyScriptRunner
{
    public function __construct(
        private \Redis $client,
    ) {}

    #[\Override]
    public function run(string $script, array $keys, array $args): mixed
    {
        $packed = [...$keys, ...$args];

        // phpredis keeps the last error until it is explicitly cleared: a
        // NOSCRIPT left over from an earlier command on this client would
        // otherwise be read as this call's error and re-run the (mutating)
        // script a second time.
        $this->client->clearLastError();

        /** @var mixed $reply */
        $reply = $this->client->evalsha(sha1($script), $packed, count($keys));

        $error = $this->client->getLastError();

        if (\is_string($error) && str_contains($error, 'NOSCRIPT')) {
            $this->client->clearLastError();
            /** @var mixed $reply */
            $reply = $this->client->eval($script, $packed, count($keys));
            $error = $this->client->getLastError();
        }

        if (\is_string($error) && $error !== '') {
            $this->client->clearLastError();

            throw new \RuntimeException(sprintf('Redis script failed: %s', $error));
        }

        return $reply;
    }
}
