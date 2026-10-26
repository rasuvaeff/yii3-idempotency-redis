# AGENTS.md — yii3-idempotency-redis

Guidance for AI agents working on this package. Read before changing code.

## What this is

Redis/Valkey backend for `rasuvaeff/yii3-idempotency` (core ^2.2): one HASH per
idempotency key, claim/store/release as single atomic Lua scripts, a random
ownership token per claim, and the server's key TTL as the only expiry
mechanism. Client-agnostic through `IdempotencyScriptRunner` — the application
binds the runner over its own client, this package binds only
`IdempotencyStorage`.

Namespace: `Rasuvaeff\Yii3IdempotencyRedis`.
Public API: `RedisIdempotencyStorage`, `IdempotencyScriptRunner`,
`Redis\PhpRedisScriptRunner`, `Redis\PredisScriptRunner`,
`UnexpectedRedisReplyException`.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Every mutation of a Lua script must be mirrored in BOTH places: the script
   (`src/Redis/LuaScripts.php`) and the documented semantics.** The unit suite
   mocks the runner and cannot catch a broken script — only
   `tests/Integration` against a real Redis can. Change a script → run the
   Integration suite (`REDIS_HOST=... vendor/bin/testo --suite=Integration`).
4. **Preserve the public contract.** Update README + README.ru.md + tests with
   any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check

# Integration (real Redis):
docker run -d --name idem-redis -p 6379:6379 redis:7-alpine
docker run --rm --network host -v "$PWD":/app -w /app \
  -e REDIS_HOST=127.0.0.1 composer:2 vendor/bin/testo --suite=Integration
```

Or with Make: `make build`, `make cs-fix`, `make psalm`, `make test`,
`make test-coverage`, `make mutation`, `make release-check`.

`composer.lock` is gitignored (library).

## Invariants & gotchas

- **The claim token is random, not derived** (`bin2hex(random_bytes(16))`):
  a predictable token (a deadline, a counter) could be assumed by a
  competitor. `ClaimTokens` (over `Internal\ClaimTokenMap`, capped at 1024)
  remembers it per instance; `store()`/`release()` spend it — reading and
  spending is one operation, a store that ran twice must not fence twice.
- **A fenced-out store is a silent, documented loss.** `store()` writes only
  into this instance's claim (token match) or into a free key; when a
  competitor owns the key the script returns 0 and PHP inspects nothing. Both
  responses must never be delivered — that is the point of the fence.
- **The claim's EXPIRE is the takeover mechanism.** No Lua branch deletes a
  stale claim: the server drops the whole hash at the TTL, so a competitor
  claims a free key and the previous owner's token matches nothing. A "helpful"
  takeover branch inside CLAIM would race the server's own expiry for no gain.
- **`store()` clamps the record TTL to >= 1 second** — EXPIRE of 0 deletes the
  hash. An already-expired record lives one second and reads back as expired
  through its `expires_at` field.
- **Empty JSON object `{}` decodes to `[]`, which `array_is_list` accepts** —
  `validateHeaders` must treat an empty array as the empty object and only a
  non-empty list as "not an object".
- **Lua tables with string keys flatten differently per client** (RESP2 vs
  client parsing): every script that returns row fields normalizes them into
  the array part (a flat k/v list) — see `LuaScripts::LOAD`. Never return a
  string-keyed table directly.
- **The reply shape is validated, never trusted**: `intReply` accepts int or
  numeric string (predis vs phpredis), everything else throws
  `UnexpectedRedisReplyException`. `/^[0-9]+\z/` is anchored at both ends —
  "x201" is not the number 201.
- Unit suite = mocked runner (Understudy) over the PHP mapping only; the Lua
  semantics live in `tests/Integration` (skip without `REDIS_HOST`). The
  phpredis integration tests additionally need `ext-redis`.
- **Config family rules**: this package binds ONLY `IdempotencyStorage` — the
  core never binds it, and exactly one of `-db`/`-redis` may be installed.
  `IdempotencyScriptRunner` is deliberately unbound: the application owns the
  client. Cross-package merging is verified with
  `bin/config-merge-harness yii3-idempotency yii3-idempotency-db yii3-idempotency-redis`.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` references a 40-char commit
  SHA with a `# vN` trailing comment. Never revert to floating `@vN` tags.
  Verify with `zizmor --persona=auditor .github/`.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit;
  and `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build`; for Lua changes also the Integration suite. Paste
  the output.
