# Changelog

## Unreleased

- Initial release: `RedisIdempotencyStorage` over Redis/Valkey for
  `rasuvaeff/yii3-idempotency` — atomic Lua claim/store/release, a random
  ownership token fencing zombie handlers, server-side key TTL as the only
  expiry mechanism (no GC), both core capabilities
  (`ClaimedFingerprintProvider`, `ClaimedDeadlineProvider`) implemented.
  Client-agnostic via `IdempotencyScriptRunner` (`PhpRedisScriptRunner` for
  `ext-redis`, `PredisScriptRunner` for `predis/predis`).
