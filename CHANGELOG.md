# Changelog

## Unreleased

- **Changed:** dev tooling: `vimeo/psalm` floor raised `^7.0` → `^7.15`: psalm 7.15 introduced the `WeakHash` check and its issueHandlers schema entry, and the Redis `EVALSHA` script digests need a scoped suppression that older psalm versions cannot parse.
- **Changed:** PHP 8.6 is supported: the `php` constraint is widened to
  `8.3 - 8.6` (#4). CI runs PHP 8.6 as a regular matrix entry; the
  backward-compatibility job runs on PHP 8.5.
- **Fixed:** psalm 7.15's WeakHash security check flags the `sha1()` digests
  the Redis `EVALSHA` protocol mandates; the check is now suppressed for the
  two Redis script runners in `psalm.xml` (#4). The digest never covers user
  data — Redis matches loaded scripts by SHA1, so there is no stronger
  algorithm to switch to.

## 1.0.0 — 2026-10-26

- Initial release: `RedisIdempotencyStorage` over Redis/Valkey for
  `rasuvaeff/yii3-idempotency` — atomic Lua claim/store/release, a random
  ownership token fencing zombie handlers, server-side key TTL as the only
  expiry mechanism (no GC), both core capabilities
  (`ClaimedFingerprintProvider`, `ClaimedDeadlineProvider`) implemented.
  Client-agnostic via `IdempotencyScriptRunner` (`PhpRedisScriptRunner` for
  `ext-redis`, `PredisScriptRunner` for `predis/predis`).
