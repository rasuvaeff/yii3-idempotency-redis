<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyRedis\Redis;

/**
 * The scripts backing {@see \Rasuvaeff\Yii3IdempotencyRedis\RedisIdempotencyStorage},
 * one per mutating storage method plus the read paths. Each runs as a single
 * atomic Redis script (Redis is single-threaded, so a script always completes
 * without interleaving another client's command) — this is what makes the
 * claim / fenced store / conditional release sequence race-free across
 * processes.
 *
 * Data model — one HASH per idempotency key `K`:
 * - claim fields, present while a request is in flight:
 *   `fingerprint` (sha256 hex), `claimed` = '1', `claim_token` (random,
 *   generated client-side per claim), `claim_deadline` (epoch seconds);
 * - record fields, present once the response is stored:
 *   `status_code`, `headers` (JSON), `body` (raw bytes — Redis fields are
 *   binary-safe, no base64 dance), `expires_at` (epoch seconds), `claimed` = '0'.
 *
 * The HASH TTL is the expiry story and the reason this backend needs no
 * garbage collector: a claim sets it to `claimTtlSeconds`, a stored record to
 * the remaining record TTL. An expired claim disappears on its own — a
 * competitor then claims a free key, and a late store from the previous owner
 * matches neither the (gone) key nor the (someone else's) token, so a zombie
 * handler can never overwrite a fresh claim's record.
 *
 * A table with string keys (what HGETALL yields inside Lua) flattens to a
 * key-value list in RESP2 and clients disagree about the shape they hand
 * back, so every script that returns fields normalizes them into the array
 * part of a table — a deterministic flat list in every client.
 *
 * @internal
 */
final class LuaScripts
{
    private function __construct() {}

    /**
     * KEYS[1] = hash, ARGV[1] = fingerprint, ARGV[2] = claim token,
     * ARGV[3] = claim deadline (epoch seconds), ARGV[4] = claim TTL seconds.
     *
     * Returns 1 when the claim was taken, 0 when the key is taken — an
     * in-flight claim or a stored record; the middleware tells the two apart
     * through the capability reads below.
     */
    public const string CLAIM = <<<'LUA'
        if redis.call('EXISTS', KEYS[1]) == 1 then
            return 0
        end
        redis.call('HSET', KEYS[1],
            'fingerprint', ARGV[1],
            'claimed', '1',
            'claim_token', ARGV[2],
            'claim_deadline', ARGV[3])
        redis.call('EXPIRE', KEYS[1], tonumber(ARGV[4]))
        return 1
        LUA;

    /**
     * KEYS[1] = hash, ARGV[1] = claim token ('' when this instance never
     * claimed), ARGV[2] = fingerprint, ARGV[3] = status code,
     * ARGV[4] = headers JSON, ARGV[5] = body, ARGV[6] = expires at (epoch
     * seconds), ARGV[7] = record TTL seconds (>= 1).
     *
     * Returns 1 when the record was written, 0 when it was fenced out:
     * the key exists and its claim token is not ours — a competitor's newer
     * claim or their stored record, which is exactly what the fence exists
     * to protect.
     *
     * A key that no longer exists takes the finished record unconditionally:
     * our own claim expired away (a takeover), so the key is free — or a
     * competitor claimed it first, and the EXISTS branch above already lost.
     */
    public const string STORE = <<<'LUA'
        local function writeRecord()
            redis.call('HSET', KEYS[1],
                'fingerprint', ARGV[2],
                'status_code', ARGV[3],
                'headers', ARGV[4],
                'body', ARGV[5],
                'expires_at', ARGV[6],
                'claimed', '0')
            redis.call('EXPIRE', KEYS[1], tonumber(ARGV[7]))
            return 1
        end
        if redis.call('EXISTS', KEYS[1]) == 0 then
            return writeRecord()
        end
        if ARGV[1] ~= ''
            and redis.call('HGET', KEYS[1], 'claimed') == '1'
            and redis.call('HGET', KEYS[1], 'claim_token') == ARGV[1] then
            return writeRecord()
        end
        return 0
        LUA;

    /**
     * KEYS[1] = hash, ARGV[1] = claim token.
     *
     * Returns 1 when this instance's own claim was deleted, 0 when there was
     * nothing of ours to release. Only a live claim with our token is deleted:
     * a stored record under the key belongs to whoever stored it, and a
     * competitor's claim was taken after ours expired — deleting either is
     * how a zombie would erase someone else's work.
     */
    public const string RELEASE = <<<'LUA'
        if redis.call('EXISTS', KEYS[1]) == 1
            and redis.call('HGET', KEYS[1], 'claimed') == '1'
            and redis.call('HGET', KEYS[1], 'claim_token') == ARGV[1] then
            redis.call('DEL', KEYS[1])
            return 1
        end
        return 0
        LUA;

    /**
     * KEYS[1] = hash.
     *
     * Returns the hash fields as a flat key-value list, or an empty list when
     * the key does not exist (never claimed, or expired away).
     */
    public const string LOAD = <<<'LUA'
        local raw = redis.call('HGETALL', KEYS[1])
        local flat = {}
        for i = 1, #raw, 2 do
            flat[#flat + 1] = raw[i]
            flat[#flat + 1] = raw[i + 1]
        end
        return flat
        LUA;

    /**
     * KEYS[1] = hash.
     *
     * Returns the fingerprint of the active claim, or nil when the key
     * carries none (absent, or a finished record).
     */
    public const string CLAIMED_FINGERPRINT = <<<'LUA'
        local fields = redis.call('HMGET', KEYS[1], 'claimed', 'fingerprint')
        if fields[1] == '1' then
            return fields[2]
        end
        return false
        LUA;

    /**
     * KEYS[1] = hash.
     *
     * Returns the deadline (epoch seconds) of the active claim, or nil when
     * the key carries none.
     */
    public const string CLAIMED_DEADLINE = <<<'LUA'
        local fields = redis.call('HMGET', KEYS[1], 'claimed', 'claim_deadline')
        if fields[1] == '1' then
            return fields[2]
        end
        return false
        LUA;
}
