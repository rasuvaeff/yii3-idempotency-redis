<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyRedis\Tests\Redis;

use Predis\ClientInterface;
use Predis\Command\RawCommand;
use Predis\Response\ServerException;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3IdempotencyRedis\Redis\PredisScriptRunner;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(PredisScriptRunner::class)]
final class PredisScriptRunnerTest
{
    public function executesScriptViaEvalshaFirst(): void
    {
        $client = $this->client(reply: 1);
        $runner = new PredisScriptRunner($client);

        $result = $runner->run('return 1', ['idempotency:k'], ['fp', 'token', '1000', '60']);

        Assert::same($result, 1);
        Assert::same($this->executedCommands($client), [
            ['EVALSHA', [sha1('return 1'), 1, 'idempotency:k', 'fp', 'token', '1000', '60']],
        ]);
    }

    public function executesScriptWithMultipleKeys(): void
    {
        $client = $this->client(reply: 0);
        $runner = new PredisScriptRunner($client);

        $runner->run('return 1', ['idempotency:a', 'idempotency:b'], ['x']);

        Assert::same(
            $this->executedCommands($client)[0],
            ['EVALSHA', [sha1('return 1'), 2, 'idempotency:a', 'idempotency:b', 'x']],
        );
    }

    public function fallsBackToEvalWhenScriptNotCached(): void
    {
        $client = $this->client(reply: 1, evalshaError: 'NOSCRIPT No matching script. Please use EVAL.');
        $runner = new PredisScriptRunner($client);

        $result = $runner->run('return 1', ['idempotency:k'], []);

        Assert::same($result, 1);
        Assert::same($this->executedCommands($client), [
            ['EVALSHA', [sha1('return 1'), 1, 'idempotency:k']],
            ['EVAL', ['return 1', 1, 'idempotency:k']],
        ]);
    }

    public function fallsBackToEvalWithEveryKeyKept(): void
    {
        // the fallback must spread the whole KEYS list, not its first element
        $client = $this->client(reply: 1, evalshaError: 'NOSCRIPT No matching script. Please use EVAL.');
        $runner = new PredisScriptRunner($client);

        $runner->run('return 1', ['idempotency:a', 'idempotency:b'], ['x']);

        Assert::same($this->executedCommands($client)[1], [
            'EVAL',
            ['return 1', 2, 'idempotency:a', 'idempotency:b', 'x'],
        ]);
    }

    public function rethrowsNonNoscriptServerErrors(): void
    {
        $client = $this->client(reply: 1, evalshaError: 'ERR Error running script');
        $runner = new PredisScriptRunner($client);

        Expect::exception(ServerException::class)->withMessageContaining('Error running script');

        $runner->run('return 1', ['idempotency:k'], []);
    }

    private function client(mixed $reply, ?string $evalshaError = null): ClientInterface
    {
        $client = Understudy::for(ClientInterface::class);
        when(fn() => $client->createCommand(Arg::any(), Arg::any()))
            ->answers(static fn(Invocation $call): RawCommand => new RawCommand((string) $call->args[0], (array) $call->args[1]));
        $evalsha = fn() => $client->executeCommand(Arg::which('getId', 'EVALSHA'));
        $eval = fn() => $client->executeCommand(Arg::which('getId', 'EVAL'));

        if ($evalshaError === null) {
            when($evalsha)->returns($reply);
        } else {
            when($evalsha)->throws(new ServerException($evalshaError));
        }
        when($eval)->returns($reply);

        return $client;
    }

    /**
     * @return list<array{string, array}>
     */
    private function executedCommands(ClientInterface $client): array
    {
        return array_map(
            static fn(Invocation $call): array => [(string) $call->args[0]->getId(), $call->args[0]->getArguments()],
            Understudy::calls(fn() => $client->executeCommand(Arg::any())),
        );
    }
}
