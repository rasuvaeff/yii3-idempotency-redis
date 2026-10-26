# rasuvaeff/yii3-idempotency-redis

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-idempotency-redis.svg?label=stable)](https://packagist.org/packages/rasuvaeff/yii3-idempotency-redis)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-idempotency-redis.svg)](https://packagist.org/packages/rasuvaeff/yii3-idempotency-redis)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-idempotency-redis/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-idempotency-redis/actions)
[![Static analysis](https://img.shields.io/badge/psalm-level-1-blue)](https://psalm.dev)
[![Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen)](https://github.com/rasuvaeff/yii3-idempotency-redis)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-idempotency-redis/php)](https://packagist.org/packages/rasuvaeff/yii3-idempotency-redis)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-idempotency-redis.svg)](https://github.com/rasuvaeff/yii3-idempotency-redis/blob/master/LICENSE.md)
[Русская версия](README.ru.md)

Redis-backed idempotency storage for Yii3 APIs — the Redis/Valkey backend for
[`rasuvaeff/yii3-idempotency`](https://github.com/rasuvaeff/yii3-idempotency).
Cross-host claims, atomic Lua scripts, an ownership token that fences zombie
handlers, and the server's own key expiry standing in for a garbage collector.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can feed to the LLM.

## Requirements

- PHP 8.3+
- `rasuvaeff/yii3-idempotency` ^2.2
- `psr/clock` ^1.0
- A Redis client, either one of:
  - `ext-redis` (phpredis) → `PhpRedisScriptRunner`
  - `predis/predis` ^2.2 → `PredisScriptRunner` (pure PHP)

Works against Redis 2.6+ (Lua `EVAL`) and any Valkey.

## Installation

```bash
composer require rasuvaeff/yii3-idempotency-redis
```

## Usage

Bind the runner over your application's own Redis client, and let the package
bind the storage:

```php
// config/common/di/idempotency-redis.php
use Predis\ClientInterface;
use Rasuvaeff\Yii3IdempotencyRedis\IdempotencyScriptRunner;
use Rasuvaeff\Yii3IdempotencyRedis\Redis\PredisScriptRunner;

return [
    // the runner belongs to the application: it already owns the client,
    // its connection settings and its failover. The package never binds it.
    IdempotencyScriptRunner::class => static fn (ClientInterface $client): IdempotencyScriptRunner
        => new PredisScriptRunner($client),
];
```

With `ext-redis` instead:

```php
use Rasuvaeff\Yii3IdempotencyRedis\Redis\PhpRedisScriptRunner;

IdempotencyScriptRunner::class => static fn (\Redis $redis): IdempotencyScriptRunner
    => new PhpRedisScriptRunner($redis),
```

The package `config/di.php` binds `IdempotencyStorage` from the params
(defaults live in the package's own `config/params.php`):

```php
// config/params.php (application-level override)
'rasuvaeff/yii3-idempotency-redis' => [
    'keyPrefix' => 'idempotency:',
    'claimTtlSeconds' => 3600,
],
```

Exactly one backend may sit next to the core — this package or
`rasuvaeff/yii3-idempotency-db`. Installing both makes `yiisoft/config` fail
on the duplicate `IdempotencyStorage` key, which is the designed error.

### How it works

| Aspect | Behaviour |
|---|---|
| Data model | One HASH per key: claim fields (`fingerprint`, `claimed`, `claim_token`, `claim_deadline`) while in flight, record fields (`status_code`, `headers` JSON, raw `body`, `expires_at`) once stored |
| Atomicity | `claim()` / `store()` / `release()` each run as exactly one Lua script — the check and the write never interleave with another client |
| Ownership | A random token per claim: `store()` writes only into the claim this instance took, `release()` deletes only that claim |
| Zombie fence | A handler that outlives its claim TTL cannot overwrite a competitor's newer record or delete their claim — the token matches nothing |
| Expiry | The server's own key TTL: a claim sets it to `claimTtlSeconds`, a stored record to the remaining record TTL. No GC, no sweeper job |
| Capabilities | Implements `ClaimedFingerprintProvider` (422 vs 409 mid-flight) and `ClaimedDeadlineProvider` (`Retry-After` on the in-flight 409) from the core |
| Body encoding | None: Redis hash fields are binary-safe, the body is stored verbatim |

The middleware's behaviour table (what the client sees) lives in the
[core package](https://github.com/rasuvaeff/yii3-idempotency#how-it-works).

### Configuration

| Param | Default | Meaning |
|---|---|---|
| `keyPrefix` | `idempotency:` | Namespaces the hashes, so several applications can share one Redis |
| `claimTtlSeconds` | `3600` | Deadline for an in-flight claim. A handler must finish well within it — past it the key frees and a concurrent duplicate may run the effect a second time |

## Security / operations

- **`maxmemory-policy noeviction`** (or a dedicated instance): any `volatile-*`
  or `allkeys-*` policy may evict an idempotency hash, which re-opens the key —
  a duplicate then executes the effect a second time. If a shared instance
  must evict, `volatile-ttl` drops the short-lived claims first, which is the
  worst possible order here.
- **AOF persistence**: a restart from an RDB snapshot (or no persistence)
  loses recent claims — the effect may re-run for a client that retries. AOF
  `everysec` is the practical compromise.
- **Asynchronous replication**: a failover can lose the latest claims.
- **`claimTtlSeconds` bounds your handler**: it must exceed the longest
  handler runtime you tolerate, or a zombie handler's late `store()` is fenced
  out (by design — both responses must never be delivered) and the duplicate
  already ran the effect.

## Public API

| Class | Description |
|---|---|
| `RedisIdempotencyStorage` | `IdempotencyStorage` over Redis: atomic Lua claim/store/release, both core capabilities implemented |
| `IdempotencyScriptRunner` | Interface: runs a Lua script on the server |
| `Redis\PhpRedisScriptRunner` | Runner over `ext-redis` (`\Redis`) |
| `Redis\PredisScriptRunner` | Runner over `predis/predis` |
| `UnexpectedRedisReplyException` | Thrown when a reply cannot be read back — the key space is being written by something else |

## Examples

See [examples/](examples/): wiring both runners, and the storage behind the
middleware.

## Development

```bash
composer build       # validate → normalize → require-checker → cs → psalm → test
composer test        # unit suite (mocked runner, no Redis needed)
vendor/bin/testo --suite=Integration   # needs REDIS_HOST
composer mutation    # needs REDIS_HOST + pcov
```

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
