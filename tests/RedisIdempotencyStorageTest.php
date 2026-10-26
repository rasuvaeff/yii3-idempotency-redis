<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyRedis\Tests;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyRecord;
use Rasuvaeff\Yii3Idempotency\IdempotencyResponse;
use Rasuvaeff\Yii3IdempotencyRedis\IdempotencyScriptRunner;
use Rasuvaeff\Yii3IdempotencyRedis\Redis\LuaScripts;
use Rasuvaeff\Yii3IdempotencyRedis\RedisIdempotencyStorage;
use Rasuvaeff\Yii3IdempotencyRedis\UnexpectedRedisReplyException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(RedisIdempotencyStorage::class)]
final class RedisIdempotencyStorageTest
{
    private FakeClock $clock;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->clock = new FakeClock();
    }

    public function rejectsAnEmptyKeyPrefix(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('keyPrefix must not be empty');

        $this->storage(keyPrefix: '');
    }

    public function rejectsAWhitespaceKeyPrefix(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('keyPrefix must not be empty');

        $this->storage(keyPrefix: '  ');
    }

    public function rejectsANonPositiveClaimTtl(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Claim TTL seconds must be greater than 0');

        $this->storage(claimTtlSeconds: 0);
    }

    public function acceptsTheMinimumClaimTtl(): void
    {
        $storage = $this->storage(claimTtlSeconds: 1);

        Assert::instanceOf($storage, RedisIdempotencyStorage::class);
    }

    public function claimRunsTheClaimScriptAndReportsSuccess(): void
    {
        $runner = $this->runnerReturning(1);
        $storage = $this->storage(runner: $runner, claimTtlSeconds: 120);

        $claimed = $storage->claim($this->key(), $this->fingerprint());

        Assert::true($claimed);

        $call = $this->singleCall($runner);
        Assert::same($call->args[0], LuaScripts::CLAIM);
        Assert::same($call->args[1], ['idempotency:' . $this->key()->value]);
        Assert::same($call->args[2][0], str_repeat('a', 64));
        // the deadline and the TTL the claim was taken with
        Assert::same($call->args[2][2], (string) ($this->clock->now()->getTimestamp() + 120));
        Assert::same($call->args[2][3], '120');
    }

    public function claimGeneratesADistinctTokenPerClaim(): void
    {
        $runner = $this->runnerReturning(1);
        $storage = $this->storage(runner: $runner);

        $storage->claim(new IdempotencyKey('key-a'), $this->fingerprint());
        $storage->claim(new IdempotencyKey('key-b'), $this->fingerprint());

        $calls = $this->calls($runner);
        Assert::same(mb_strlen($calls[0]->args[2][1]), 32);
        Assert::false($calls[0]->args[2][1] === $calls[1]->args[2][1]);
    }

    public function claimReportsFailureWhenTheServerReturnsZero(): void
    {
        $storage = $this->storage(runner: $this->runnerReturning(0));

        Assert::false($storage->claim($this->key(), $this->fingerprint()));
    }

    public function claimAcceptsAStringIntegerReply(): void
    {
        $storage = $this->storage(runner: $this->runnerReturning('1'));

        Assert::true($storage->claim($this->key(), $this->fingerprint()));
    }

    public function claimRejectsAGarbageReply(): void
    {
        $storage = $this->storage(runner: $this->runnerReturning('garbage'));

        Expect::exception(UnexpectedRedisReplyException::class)
            ->withMessageContaining('Unexpected Redis reply shape for claim');

        $storage->claim($this->key(), $this->fingerprint());
    }

    public function claimRejectsAReplyWithANonNumericPrefix(): void
    {
        $storage = $this->storage(runner: $this->runnerReturning('x1'));

        Expect::exception(UnexpectedRedisReplyException::class)
            ->withMessageContaining('Unexpected Redis reply shape for claim');

        $storage->claim($this->key(), $this->fingerprint());
    }

    public function storeRejectsAGarbageReply(): void
    {
        $runner = $this->runnerReturning([1, 'broken']);
        $storage = $this->storage(runner: $runner);

        Expect::exception(UnexpectedRedisReplyException::class)
            ->withMessageContaining('Unexpected Redis reply shape for store');

        $storage->store($this->record(expiresIn: 600));
    }

    public function releaseRejectsAGarbageReply(): void
    {
        // claim succeeds, the release reply is garbage: a constant stub would
        // fail the claim first, so the runner answers per call
        $runner = $this->runnerAnsweringPerCall([1, 'broken']);
        $storage = $this->storage(runner: $runner);
        $storage->claim($this->key(), $this->fingerprint());

        Expect::exception(UnexpectedRedisReplyException::class)
            ->withMessageContaining('Unexpected Redis reply shape for release');

        $storage->release($this->key());
    }

    public function storeSendsTheRecordAndSpendsTheClaimToken(): void
    {
        $runner = $this->runnerReturning(1);
        $storage = $this->storage(runner: $runner);

        $storage->claim($this->key(), $this->fingerprint());
        $storage->store($this->record(expiresIn: 600));

        $store = $this->calls($runner)[1];
        Assert::same($store->args[0], LuaScripts::STORE);
        Assert::same($store->args[1], ['idempotency:' . $this->key()->value]);
        Assert::same($store->args[2][0], $this->calls($runner)[0]->args[2][1]);
        Assert::same($store->args[2][1], str_repeat('a', 64));
        Assert::same($store->args[2][2], '201');
        Assert::same($store->args[2][3], '{"X-Resource-Id":["42"]}');
        Assert::same($store->args[2][4], 'created');
        Assert::same(
            $store->args[2][5],
            (string) $this->clock->now()->modify('+600 seconds')->getTimestamp(),
        );
        Assert::same($store->args[2][6], '600');
    }

    public function storeWithoutAClaimSendsAnEmptyToken(): void
    {
        $runner = $this->runnerReturning(1);
        $storage = $this->storage(runner: $runner);

        $storage->store($this->record(expiresIn: 600));

        Assert::same($this->singleCall($runner)->args[2][0], '');
    }

    public function storeTwiceFencesOnlyOnce(): void
    {
        $runner = $this->runnerReturning(1);
        $storage = $this->storage(runner: $runner);

        $storage->claim($this->key(), $this->fingerprint());
        $storage->store($this->record(expiresIn: 600));
        $storage->store($this->record(expiresIn: 600));

        // the second store has no token left to spend
        Assert::same($this->calls($runner)[2]->args[2][0], '');
    }

    public function storeClampsAnAlreadyExpiredRecordToOneSecond(): void
    {
        $runner = $this->runnerReturning(1);
        $storage = $this->storage(runner: $runner);

        $storage->store($this->record(expiresIn: -30));

        Assert::same($this->singleCall($runner)->args[2][6], '1');
    }

    public function releaseWithoutAClaimTouchesNothing(): void
    {
        $runner = $this->runnerReturning(1);
        $storage = $this->storage(runner: $runner);

        $storage->release($this->key());

        Assert::same($this->calls($runner), []);
    }

    public function releaseRunsTheReleaseScriptWithTheSpentToken(): void
    {
        $runner = $this->runnerReturning(1);
        $storage = $this->storage(runner: $runner);

        $storage->claim($this->key(), $this->fingerprint());
        $storage->release($this->key());

        $release = $this->calls($runner)[1];
        Assert::same($release->args[0], LuaScripts::RELEASE);
        Assert::same($release->args[1], ['idempotency:' . $this->key()->value]);
        Assert::same($release->args[2][0], $this->calls($runner)[0]->args[2][1]);
    }

    public function loadReturnsNullForAnAbsentKey(): void
    {
        $storage = $this->storage(runner: $this->runnerReturning([]));

        Assert::null($storage->load($this->key()));
    }

    public function loadIgnoresATrailingOddField(): void
    {
        // a 3-element reply parses the one complete pair and drops the
        // dangling tail — the loop bound is `$i + 1 < count`, never `<=`
        $expiresAt = $this->clock->now()->modify('+600 seconds');
        $storage = $this->storage(runner: $this->runnerReturning([
            'fingerprint', str_repeat('a', 64),
            'status_code', '201',
            'headers', '{}',
            'body', 'created',
            'expires_at', (string) $expiresAt->getTimestamp(),
            'claimed', '0',
            'trailing-orphan',
        ]));

        $record = $storage->load($this->key());

        Assert::notNull($record);
        Assert::same($record->response->statusCode, 201);
    }

    public function loadCoercesAnIntegerFieldToString(): void
    {
        $expiresAt = $this->clock->now()->modify('+600 seconds');
        $storage = $this->storage(runner: $this->runnerReturning([
            'fingerprint', str_repeat('a', 64),
            'status_code', 201,
            'headers', '{}',
            'body', 'created',
            'expires_at', (string) $expiresAt->getTimestamp(),
            'claimed', '0',
        ]));

        $record = $storage->load($this->key());

        Assert::notNull($record);
        Assert::same($record->response->statusCode, 201);
    }

    public function loadRestoresMultipleHeadersWithMultipleValues(): void
    {
        $expiresAt = $this->clock->now()->modify('+600 seconds');
        $storage = $this->storage(runner: $this->runnerReturning([
            'fingerprint', str_repeat('a', 64),
            'status_code', '201',
            'headers', '{"X-A":["1","2"],"X-B":["3","4"]}',
            'body', 'created',
            'expires_at', (string) $expiresAt->getTimestamp(),
            'claimed', '0',
        ]));

        $record = $storage->load($this->key());

        Assert::notNull($record);
        Assert::same($record->response->headers, [
            'X-A' => ['1', '2'],
            'X-B' => ['3', '4'],
        ]);
    }

    public function loadReturnsNullForAnInFlightClaim(): void
    {
        $storage = $this->storage(runner: $this->runnerReturning([
            'fingerprint', str_repeat('a', 64),
            'claimed', '1',
            'claim_token', 'token',
            'claim_deadline', '1000',
        ]));

        Assert::null($storage->load($this->key()));
    }

    public function loadRestoresAStoredRecord(): void
    {
        $expiresAt = $this->clock->now()->modify('+600 seconds');
        $storage = $this->storage(runner: $this->runnerReturning([
            'fingerprint', str_repeat('a', 64),
            'status_code', '201',
            'headers', '{"X-Resource-Id":["42"]}',
            'body', 'created',
            'expires_at', (string) $expiresAt->getTimestamp(),
            'claimed', '0',
        ]));

        $record = $storage->load($this->key());

        Assert::notNull($record);
        Assert::same($record->key->value, $this->key()->value);
        Assert::same($record->fingerprint->hash, str_repeat('a', 64));
        Assert::same($record->response->statusCode, 201);
        Assert::same($record->response->headers, ['X-Resource-Id' => ['42']]);
        Assert::same($record->response->body, 'created');
        Assert::same($record->expiresAt->getTimestamp(), $expiresAt->getTimestamp());
    }

    public function loadRejectsANonArrayReply(): void
    {
        $storage = $this->storage(runner: $this->runnerReturning('garbage'));

        Expect::exception(UnexpectedRedisReplyException::class)
            ->withMessageContaining('Unexpected Redis reply shape for a row');

        $storage->load($this->key());
    }

    public function loadRejectsANonStringFieldName(): void
    {
        $storage = $this->storage(runner: $this->runnerReturning([1, 'value']));

        Expect::exception(UnexpectedRedisReplyException::class)
            ->withMessageContaining('Unexpected Redis reply shape for a row');

        $storage->load($this->key());
    }

    public function loadRejectsAFloatFieldValue(): void
    {
        // neither string nor int: a corrupt reply must fail the shape check,
        // not silently stringify into a record
        $storage = $this->storage(runner: $this->runnerReturning(['body', 1.5]));

        Expect::exception(UnexpectedRedisReplyException::class)
            ->withMessageContaining('Unexpected Redis reply shape for a row');

        $storage->load($this->key());
    }

    public function loadRejectsARowMissingAField(): void
    {
        $storage = $this->storage(runner: $this->runnerReturning([
            'fingerprint', str_repeat('a', 64),
            'status_code', '201',
            'claimed', '0',
        ]));

        Expect::exception(UnexpectedRedisReplyException::class)
            ->withMessageContaining('missing the "headers" field');

        $storage->load($this->key());
    }

    public function loadRejectsANonNumericIntegerField(): void
    {
        $storage = $this->storage(runner: $this->runnerReturning([
            'fingerprint', str_repeat('a', 64),
            'status_code', 'created',
            'headers', '{}',
            'body', 'x',
            'expires_at', '2000',
            'claimed', '0',
        ]));

        Expect::exception(UnexpectedRedisReplyException::class)
            ->withMessageContaining('field "status_code" is not a number');

        $storage->load($this->key());
    }

    public function loadRejectsANumericFieldWithANonNumericPrefix(): void
    {
        // '/^[0-9]+\z/' — not '/[0-9]+\z/': a field reading "x201" is not
        // the number 201 and must not slip through as one
        $storage = $this->storage(runner: $this->runnerReturning([
            'fingerprint', str_repeat('a', 64),
            'status_code', 'x201',
            'headers', '{}',
            'body', 'x',
            'expires_at', '2000',
            'claimed', '0',
        ]));

        Expect::exception(UnexpectedRedisReplyException::class)
            ->withMessageContaining('field "status_code" is not a number');

        $storage->load($this->key());
    }

    public function loadRejectsABrokenHeadersJson(): void
    {
        $storage = $this->storage(runner: $this->runnerReturning([
            'fingerprint', str_repeat('a', 64),
            'status_code', '201',
            'headers', '{broken',
            'body', 'x',
            'expires_at', '2000',
            'claimed', '0',
        ]));

        Expect::exception(UnexpectedRedisReplyException::class)
            ->withMessageContaining('Invalid headers JSON in Redis row');

        $storage->load($this->key());
    }

    public function loadRejectsNonObjectHeadersJson(): void
    {
        $storage = $this->storage(runner: $this->runnerReturning([
            'fingerprint', str_repeat('a', 64),
            'status_code', '201',
            'headers', '[1,2]',
            'body', 'x',
            'expires_at', '2000',
            'claimed', '0',
        ]));

        Expect::exception(UnexpectedRedisReplyException::class)
            ->withMessageContaining('Headers JSON in Redis row is not an object');

        $storage->load($this->key());
    }

    public function loadRejectsNonListHeaderValues(): void
    {
        $storage = $this->storage(runner: $this->runnerReturning([
            'fingerprint', str_repeat('a', 64),
            'status_code', '201',
            'headers', '{"X-A":{"x":"y"}}',
            'body', 'x',
            'expires_at', '2000',
            'claimed', '0',
        ]));

        Expect::exception(UnexpectedRedisReplyException::class)
            ->withMessageContaining('Headers JSON in Redis row has an invalid shape');

        $storage->load($this->key());
    }

    public function loadRejectsNonStringHeaderValue(): void
    {
        $storage = $this->storage(runner: $this->runnerReturning([
            'fingerprint', str_repeat('a', 64),
            'status_code', '201',
            'headers', '{"X-A":[1]}',
            'body', 'x',
            'expires_at', '2000',
            'claimed', '0',
        ]));

        Expect::exception(UnexpectedRedisReplyException::class)
            ->withMessageContaining('Headers JSON in Redis row has a non-string value');

        $storage->load($this->key());
    }

    public function claimedFingerprintReturnsTheInFlightFingerprint(): void
    {
        $storage = $this->storage(runner: $this->runnerReturning(str_repeat('b', 64)));

        $claimed = $storage->claimedFingerprint($this->key());

        Assert::notNull($claimed);
        Assert::same($claimed->hash, str_repeat('b', 64));
    }

    public function claimedFingerprintReturnsNullWithoutAClaim(): void
    {
        $storage = $this->storage(runner: $this->runnerReturning(null));

        Assert::null($storage->claimedFingerprint($this->key()));
    }

    public function claimedFingerprintRejectsAGarbageReply(): void
    {
        $storage = $this->storage(runner: $this->runnerReturning(['nested']));

        Expect::exception(UnexpectedRedisReplyException::class)
            ->withMessageContaining('Unexpected Redis reply shape for a claimed fingerprint');

        $storage->claimedFingerprint($this->key());
    }

    public function claimedDeadlineReturnsTheInFlightDeadline(): void
    {
        $deadline = $this->clock->now()->modify('+120 seconds');
        $storage = $this->storage(runner: $this->runnerReturning((string) $deadline->getTimestamp()));

        $claimed = $storage->claimedDeadline($this->key());

        Assert::notNull($claimed);
        Assert::same($claimed->getTimestamp(), $deadline->getTimestamp());
    }

    public function claimedDeadlineAcceptsAnIntegerReply(): void
    {
        $deadline = $this->clock->now()->modify('+120 seconds');
        $storage = $this->storage(runner: $this->runnerReturning($deadline->getTimestamp()));

        Assert::same(
            $storage->claimedDeadline($this->key())?->getTimestamp(),
            $deadline->getTimestamp(),
        );
    }

    public function claimedDeadlineReturnsNullWithoutAClaim(): void
    {
        $storage = $this->storage(runner: $this->runnerReturning(reply: false));

        Assert::null($storage->claimedDeadline($this->key()));
    }

    public function claimedDeadlineRejectsAGarbageReply(): void
    {
        $storage = $this->storage(runner: $this->runnerReturning(1.5));

        Expect::exception(UnexpectedRedisReplyException::class)
            ->withMessageContaining('Unexpected Redis reply shape for a claimed deadline');

        $storage->claimedDeadline($this->key());
    }

    public function usesACustomKeyPrefix(): void
    {
        $runner = $this->runnerReturning(1);
        $storage = $this->storage(runner: $runner, keyPrefix: 'payments:idem:');

        $storage->claim($this->key(), $this->fingerprint());

        Assert::same($this->singleCall($runner)->args[1], ['payments:idem:' . $this->key()->value]);
    }

    private function storage(
        ?IdempotencyScriptRunner $runner = null,
        string $keyPrefix = 'idempotency:',
        int $claimTtlSeconds = 3600,
    ): RedisIdempotencyStorage {
        return new RedisIdempotencyStorage(
            runner: $runner ?? $this->runnerReturning(1),
            clock: $this->clock,
            keyPrefix: $keyPrefix,
            claimTtlSeconds: $claimTtlSeconds,
        );
    }

    private function runnerReturning(mixed $reply): IdempotencyScriptRunner
    {
        $runner = Understudy::for(IdempotencyScriptRunner::class);
        when(fn() => $runner->run(Arg::any(), Arg::any(), Arg::any()))->returns($reply);

        return $runner;
    }

    /**
     * @param list<mixed> $replies one reply per invocation, in order
     */
    private function runnerAnsweringPerCall(array $replies): IdempotencyScriptRunner
    {
        $index = 0;
        $runner = Understudy::for(IdempotencyScriptRunner::class);
        when(fn() => $runner->run(Arg::any(), Arg::any(), Arg::any()))
            ->answers(static function (Invocation $call) use (&$index, $replies): mixed {
                return $replies[$index++] ?? null;
            });

        return $runner;
    }

    /**
     * @return list<Invocation>
     */
    private function calls(IdempotencyScriptRunner $runner): array
    {
        return Understudy::calls(fn() => $runner->run(Arg::any(), Arg::any(), Arg::any()));
    }

    private function singleCall(IdempotencyScriptRunner $runner): Invocation
    {
        $calls = $this->calls($runner);
        Assert::same(count($calls), 1);

        return $calls[0];
    }

    private function key(): IdempotencyKey
    {
        return new IdempotencyKey('scope-key-1');
    }

    private function fingerprint(): IdempotencyFingerprint
    {
        return new IdempotencyFingerprint(str_repeat('a', 64));
    }

    private function record(int $expiresIn): IdempotencyRecord
    {
        $key = $this->key();

        return IdempotencyRecord::restore(
            key: $key,
            fingerprint: $this->fingerprint(),
            response: new IdempotencyResponse(
                statusCode: 201,
                headers: ['X-Resource-Id' => ['42']],
                body: 'created',
            ),
            expiresAt: $this->clock->now()->modify(sprintf('%+d seconds', $expiresIn)),
        );
    }
}
