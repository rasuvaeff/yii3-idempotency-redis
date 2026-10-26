<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyRedis;

/**
 * Runs a Lua script on the Redis server, atomically.
 *
 * Redis executes scripts single-threaded: the whole read-evaluate-write
 * sequence completes without another client's command interleaving. This is
 * what makes a claim, a fenced store or a conditional release race-free
 * across processes — the same guarantee a unique primary key gives a database
 * adapter.
 *
 * Client-agnostic on purpose: {@see Redis\PhpRedisScriptRunner} over `ext-redis`,
 * {@see Redis\PredisScriptRunner} over `predis/predis`. The application binds
 * the one matching its own client; the storage never learns which it is.
 *
 * @api
 */
interface IdempotencyScriptRunner
{
    /**
     * @param list<string> $keys the script's KEYS
     * @param list<string> $args the script's ARGV
     *
     * @return mixed the script's reply, in the client's native shape
     */
    public function run(string $script, array $keys, array $args): mixed;
}
