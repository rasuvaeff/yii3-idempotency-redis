<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyRedis;

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Idempotency\ClaimedDeadlineProvider;
use Rasuvaeff\Yii3Idempotency\ClaimedFingerprintProvider;
use Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyRecord;
use Rasuvaeff\Yii3Idempotency\IdempotencyResponse;
use Rasuvaeff\Yii3Idempotency\IdempotencyStorage;
use Rasuvaeff\Yii3IdempotencyRedis\Redis\LuaScripts;

/**
 * {@see IdempotencyStorage} over Redis (or Valkey), cross-host, with the
 * server's own key expiry standing in for a garbage collector: a claim sets
 * the hash TTL to the claim TTL, a stored record to the remaining record TTL,
 * so an expired claim disappears on its own and nothing sweeps.
 *
 * `claim()`, `store()` and `release()` each run as exactly one Lua script —
 * the check and the write happen inside Redis's single-threaded script
 * execution, so two workers racing on the same key can never interleave.
 * Ownership travels as a random claim token: `store()` writes only into the
 * claim this instance took, and `release()` deletes only that claim — after
 * the claim's TTL frees the key and a competitor takes it, a late store or
 * release from the previous owner matches neither the key nor the token and
 * changes nothing. This is the zombie fence: both responses must never be
 * delivered, which is the duplication idempotency exists to prevent.
 *
 * Client-agnostic via {@see IdempotencyScriptRunner} —
 * {@see Redis\PhpRedisScriptRunner} for `ext-redis`,
 * {@see Redis\PredisScriptRunner} for `predis/predis`.
 *
 * @api
 */
final readonly class RedisIdempotencyStorage implements IdempotencyStorage, ClaimedFingerprintProvider, ClaimedDeadlineProvider
{
    private const int MIN_CLAIM_TTL_SECONDS = 1;

    private string $keyPrefix;

    private ClaimTokens $claimTokens;

    /**
     * @param non-empty-string $keyPrefix Namespaces the hashes, so several applications
     *                                   can share one Redis without colliding
     * @param int $claimTtlSeconds Deadline for an in-flight claim. The moment it passes,
     *                             the hash expires and the key is free again: a handler
     *                             must finish well within it, or a concurrent duplicate
     *                             may claim the key and run the effect a second time
     */
    public function __construct(
        private IdempotencyScriptRunner $runner,
        private ClockInterface $clock,
        string $keyPrefix = 'idempotency:',
        private int $claimTtlSeconds = 3600,
    ) {
        if (trim($keyPrefix) === '') {
            throw new \InvalidArgumentException('keyPrefix must not be empty');
        }

        if ($claimTtlSeconds < self::MIN_CLAIM_TTL_SECONDS) {
            throw new \InvalidArgumentException('Claim TTL seconds must be greater than 0');
        }

        $this->keyPrefix = $keyPrefix;
        $this->claimTokens = new ClaimTokens();
    }

    #[\Override]
    public function load(IdempotencyKey $key): ?IdempotencyRecord
    {
        /** @var mixed $reply */
        $reply = $this->runner->run(LuaScripts::LOAD, [$this->hashKey($key)], []);

        $fields = $this->rowFields($reply);

        if ($fields === []) {
            return null;
        }

        if ($this->isClaimed($fields)) {
            // The middleware fails its own claim() next and asks the
            // capability reads — an in-flight claim is not a record.
            return null;
        }

        return IdempotencyRecord::restore(
            key: $key,
            fingerprint: $this->restoreFingerprint($fields),
            response: new IdempotencyResponse(
                statusCode: $this->intField($fields, 'status_code'),
                headers: $this->restoreHeaders($fields),
                body: $this->stringField($fields, 'body'),
            ),
            expiresAt: $this->restoreExpiresAt($fields),
        );
    }

    #[\Override]
    public function claim(IdempotencyKey $key, IdempotencyFingerprint $fingerprint): bool
    {
        $token = bin2hex(random_bytes(16));
        $deadline = $this->clock->now()
            ->modify(sprintf('+%d seconds', $this->claimTtlSeconds))
            ->getTimestamp();

        /** @var mixed $reply */
        $reply = $this->runner->run(LuaScripts::CLAIM, [$this->hashKey($key)], [
            $fingerprint->hash,
            $token,
            (string) $deadline,
            (string) $this->claimTtlSeconds,
        ]);

        if ($this->intReply($reply, 'claim') !== 1) {
            return false;
        }

        $this->claimTokens->remember($key->value, $token);

        return true;
    }

    #[\Override]
    public function store(IdempotencyRecord $record): void
    {
        // Reading and spending the token is one operation: a store that ran
        // twice must not fence twice. An empty token is a store without a
        // claim in this process — the script then writes only into a free key.
        $token = $this->claimTokens->forget($record->key->value) ?? '';

        // EXPIRE of zero or less would delete the hash, so an already-expired
        // record is clamped to one second — load() then reads it as expired
        // (the expires_at field governs) and the server drops it right after.
        $ttlSeconds = max(1, $record->expiresAt->getTimestamp() - $this->clock->now()->getTimestamp());

        $headers = json_encode(value: $record->response->headers, flags: \JSON_THROW_ON_ERROR);

        /** @var mixed $reply */
        $reply = $this->runner->run(LuaScripts::STORE, [$this->hashKey($record->key)], [
            $token,
            $record->fingerprint->hash,
            (string) $record->response->statusCode,
            $headers,
            $record->response->body,
            (string) $record->expiresAt->getTimestamp(),
            (string) $ttlSeconds,
        ]);

        // The outcome is deliberately not inspected: a fenced-out store is
        // the documented silent loss — a competitor owns the key, and both
        // responses must never be delivered.
        $this->intReply($reply, 'store');
    }

    #[\Override]
    public function release(IdempotencyKey $key): void
    {
        // Only the claim this instance took. A release for a claim this
        // instance never took owns nothing to release — deleting whatever
        // claim happens to sit under the key is how a competitor's fresh
        // claim would disappear.
        $token = $this->claimTokens->forget($key->value);

        if ($token === null) {
            return;
        }

        /** @var mixed $reply */
        $reply = $this->runner->run(LuaScripts::RELEASE, [$this->hashKey($key)], [$token]);

        $this->intReply($reply, 'release');
    }

    /**
     * A pure read of the fields `claim()` wrote, so the middleware can tell an
     * in-flight duplicate (409, `Retry-After` via {@see claimedDeadline()}) from
     * a key reused with a different payload while the original request is
     * still being processed (422). Only an active claim answers; a finished
     * record has no claim to report, however fresh it is.
     */
    #[\Override]
    public function claimedFingerprint(IdempotencyKey $key): ?IdempotencyFingerprint
    {
        /** @var mixed $reply */
        $reply = $this->runner->run(LuaScripts::CLAIMED_FINGERPRINT, [$this->hashKey($key)], []);

        if ($reply === null || $reply === false) {
            return null;
        }

        if (!\is_string($reply)) {
            throw new UnexpectedRedisReplyException('Unexpected Redis reply shape for a claimed fingerprint');
        }

        return new IdempotencyFingerprint($reply);
    }

    #[\Override]
    public function claimedDeadline(IdempotencyKey $key): ?\DateTimeImmutable
    {
        /** @var mixed $reply */
        $reply = $this->runner->run(LuaScripts::CLAIMED_DEADLINE, [$this->hashKey($key)], []);

        if ($reply === null || $reply === false) {
            return null;
        }

        if (!\is_string($reply) && !\is_int($reply)) {
            throw new UnexpectedRedisReplyException('Unexpected Redis reply shape for a claimed deadline');
        }

        return new \DateTimeImmutable(sprintf('@%d', (int) $reply));
    }

    /**
     * Always non-empty in practice — the prefix is constructor-validated and
     * the key value is 1-255 chars in the core — but Psalm cannot infer a
     * non-empty concatenation from those runtime checks, and nothing
     * downstream needs the narrower type.
     */
    private function hashKey(IdempotencyKey $key): string
    {
        return $this->keyPrefix . $key->value;
    }

    /**
     * @return array<string, string> the row's fields, empty when the key is absent
     */
    private function rowFields(mixed $reply): array
    {
        if (!\is_array($reply)) {
            throw new UnexpectedRedisReplyException('Unexpected Redis reply shape for a row');
        }

        /** @var array<string, string> $fields */
        $fields = [];
        $counter = \count($reply);

        for ($i = 0; $i + 1 < $counter; $i += 2) {
            $name = $reply[$i];
            $value = $reply[$i + 1];

            if (!\is_string($name) || (!\is_string($value) && !\is_int($value))) {
                throw new UnexpectedRedisReplyException('Unexpected Redis reply shape for a row');
            }

            $fields[$name] = (string) $value;
        }

        return $fields;
    }

    /**
     * @param array<string, string> $fields
     */
    private function isClaimed(array $fields): bool
    {
        return ($fields['claimed'] ?? '') === '1';
    }

    /**
     * @param array<string, string> $fields
     */
    private function restoreFingerprint(array $fields): IdempotencyFingerprint
    {
        return new IdempotencyFingerprint($this->stringField($fields, 'fingerprint'));
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array<string, list<string>>
     */
    private function restoreHeaders(array $fields): array
    {
        $json = $this->stringField($fields, 'headers');

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, associative: true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new UnexpectedRedisReplyException(sprintf('Invalid headers JSON in Redis row: %s', $e->getMessage()), $e->getCode(), previous: $e);
        }

        return $this->validateHeaders($decoded);
    }

    /**
     * @return array<string, list<string>>
     */
    private function validateHeaders(mixed $headers): array
    {
        if (!\is_array($headers)) {
            throw new UnexpectedRedisReplyException('Headers JSON in Redis row is not an object');
        }

        if ($headers !== [] && array_is_list($headers)) {
            // an empty PHP array is the empty JSON object `{}` — only a
            // non-empty list is a JSON array instead of an object
            throw new UnexpectedRedisReplyException('Headers JSON in Redis row is not an object');
        }

        $result = [];

        foreach (array_keys($headers) as $name) {
            if (!\is_string($name)) {
                throw new UnexpectedRedisReplyException('Headers JSON in Redis row has an invalid shape');
            }

            $result[$name] = $this->validateHeaderValues($headers[$name]);
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function validateHeaderValues(mixed $values): array
    {
        if (!\is_array($values) || !array_is_list($values)) {
            throw new UnexpectedRedisReplyException('Headers JSON in Redis row has an invalid shape');
        }

        $validated = [];

        foreach ($values as $value) {
            if (!\is_string($value)) {
                throw new UnexpectedRedisReplyException('Headers JSON in Redis row has a non-string value');
            }

            $validated[] = $value;
        }

        return $validated;
    }

    /**
     * @param array<string, string> $fields
     */
    private function restoreExpiresAt(array $fields): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('@%d', $this->intField($fields, 'expires_at')));
    }

    /**
     * @param array<string, string> $fields
     */
    private function stringField(array $fields, string $name): string
    {
        $value = $fields[$name] ?? null;

        if (!\is_string($value)) {
            throw new UnexpectedRedisReplyException(sprintf('Redis row is missing the "%s" field', $name));
        }

        return $value;
    }

    /**
     * @param array<string, string> $fields
     */
    private function intField(array $fields, string $name): int
    {
        $value = $this->stringField($fields, $name);

        if (preg_match('/^[0-9]+\z/', $value) !== 1) {
            throw new UnexpectedRedisReplyException(sprintf('Redis row field "%s" is not a number', $name));
        }

        return (int) $value;
    }

    /**
     * Validates an integer script outcome (0/1) whatever the client returned.
     */
    private function intReply(mixed $reply, string $operation): int
    {
        if (\is_int($reply)) {
            return $reply;
        }

        if (\is_string($reply) && preg_match('/^[0-9]+\z/', $reply) === 1) {
            return (int) $reply;
        }

        throw new UnexpectedRedisReplyException(sprintf('Unexpected Redis reply shape for %s', $operation));
    }
}
