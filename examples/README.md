# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `phpredis-wiring.php` | Constructing `RedisIdempotencyStorage` over `PhpRedisScriptRunner` (`ext-redis`) | No (lazy: no command runs) |
| `predis-wiring.php` | The application-side DI wiring: runner over the app's predis client | No (lazy: no command runs) |

Both scripts construct the storage and print its configuration. To exercise a
real claim/store/load cycle, point the client at your Redis and follow the
[core package's middleware wiring](https://github.com/rasuvaeff/yii3-idempotency#usage):

```php
use Rasuvaeff\Yii3Idempotency\IdempotencyMiddleware;

$middleware = new IdempotencyMiddleware(
    keyExtractor: new HeaderIdempotencyKeyExtractor(),
    storage: $storage,          // the RedisIdempotencyStorage from an example above
    responseFactory: $factory,
    clock: $clock,
    scopeResolver: new RequestAttributeScopeResolver(attribute: 'user'),
);
```

Environment: `REDIS_HOST`/`REDIS_PORT` are only needed by the Integration
test suite, not by these scripts.
