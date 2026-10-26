# rasuvaeff/yii3-idempotency-redis

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-idempotency-redis.svg?label=stable)](https://packagist.org/packages/rasuvaeff/yii3-idempotency-redis)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-idempotency-redis.svg)](https://packagist.org/packages/rasuvaeff/yii3-idempotency-redis)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-idempotency-redis/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-idempotency-redis/actions)
[![Static analysis](https://img.shields.io/badge/psalm-level-1-blue)](https://psalm.dev)
[![Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen)](https://github.com/rasuvaeff/yii3-idempotency-redis)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-idempotency-redis/php)](https://packagist.org/packages/rasuvaeff/yii3-idempotency-redis)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-idempotency-redis.svg)](https://github.com/rasuvaeff/yii3-idempotency-redis/blob/master/LICENSE.md)
[English version](README.md)

Redis-бэкенд идемпотентности для Yii3 API — хранилище для
[`rasuvaeff/yii3-idempotency`](https://github.com/rasuvaeff/yii3-idempotency)
поверх Redis/Valkey. Кросс-хостовые claim, атомарные Lua-скрипты,
ownership-токен, фенсирующий зомби-обработчики, и серверный TTL ключей вместо
сборщика мусора.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can feed to the LLM.

## Требования

- PHP 8.3+
- `rasuvaeff/yii3-idempotency` ^2.2
- `psr/clock` ^1.0
- Redis-клиент, один из:
  - `ext-redis` (phpredis) → `PhpRedisScriptRunner`
  - `predis/predis` ^2.2 → `PredisScriptRunner` (чистый PHP)

Работает с Redis 2.6+ (Lua `EVAL`) и любым Valkey.

## Установка

```bash
composer require rasuvaeff/yii3-idempotency-redis
```

## Использование

Забиндите раннер поверх собственного Redis-клиента приложения — пакет забиндит
хранилище сам:

```php
// config/common/di/idempotency-redis.php
use Predis\ClientInterface;
use Rasuvaeff\Yii3IdempotencyRedis\IdempotencyScriptRunner;
use Rasuvaeff\Yii3IdempotencyRedis\Redis\PredisScriptRunner;

return [
    // раннер принадлежит приложению: клиент, его настройки и failover уже
    // ваши. Пакет его не биндит никогда.
    IdempotencyScriptRunner::class => static fn (ClientInterface $client): IdempotencyScriptRunner
        => new PredisScriptRunner($client),
];
```

С `ext-redis`:

```php
use Rasuvaeff\Yii3IdempotencyRedis\Redis\PhpRedisScriptRunner;

IdempotencyScriptRunner::class => static fn (\Redis $redis): IdempotencyScriptRunner
    => new PhpRedisScriptRunner($redis),
```

`config/di.php` пакета биндит `IdempotencyStorage` из параметров (дефолты — в
собственном `config/params.php` пакета):

```php
// config/params.php (переопределение на уровне приложения)
'rasuvaeff/yii3-idempotency-redis' => [
    'keyPrefix' => 'idempotency:',
    'claimTtlSeconds' => 3600,
],
```

Рядом с ядром может стоять ровно один бэкенд — этот пакет или
`rasuvaeff/yii3-idempotency-db`. Установка обоих валит `yiisoft/config` на
дубликате ключа `IdempotencyStorage` — это задуманная ошибка.

### Как это работает

| Аспект | Поведение |
|---|---|
| Модель данных | Один HASH на ключ: поля claim (`fingerprint`, `claimed`, `claim_token`, `claim_deadline`) во время обработки, поля записи (`status_code`, `headers` JSON, сырой `body`, `expires_at`) после сохранения |
| Атомарность | `claim()` / `store()` / `release()` — каждый ровно один Lua-скрипт: проверка и запись не перемежаются с другим клиентом |
| Владение | Случайный токен на каждый claim: `store()` пишет только в свой claim, `release()` удаляет только свой |
| Зомби-фенс | Обработчик, переживший TTL своего claim, не может перезаписать запись конкурента или удалить его claim — токен уже ничего не матчит |
| Истечение | Серверный TTL ключа: claim ставит `claimTtlSeconds`, сохранённая запись — остаток record TTL. Никакого GC, никакого sweeper |
| Capabilities | Реализует `ClaimedFingerprintProvider` (422 против 409 mid-flight) и `ClaimedDeadlineProvider` (`Retry-After` на in-flight 409) из ядра |
| Кодирование body | Никакого: поля hash бинарно-безопасны, body хранится как есть |

Таблицу поведения middleware (что видит клиент) см. в
[пакете-ядре](https://github.com/rasuvaeff/yii3-idempotency#how-it-works).

### Конфигурация

| Параметр | По умолчанию | Значение |
|---|---|---|
| `keyPrefix` | `idempotency:` | Пространство имён hash: несколько приложений могут делить один Redis |
| `claimTtlSeconds` | `3600` | Дедлайн in-flight claim. Обработчик обязан завершиться с запасом — после дедлайна ключ освобождается, и конкурент-дубликат может исполнить эффект второй раз |

## Безопасность / эксплуатация

- **`maxmemory-policy noeviction`** (или выделенный инстанс): любая политика
  `volatile-*`/`allkeys-*` может эвиктнуть hash идемпотентности и
  переоткрыть ключ — дубликат исполнит эффект второй раз. Если общий инстанс
  обязан эвиктить, `volatile-ttl` уронит короткоживущие claim первыми —
  худший возможный порядок.
- **AOF-персистентность**: рестарт со снапшота RDB (или без персистентности)
  теряет свежие claim — эффект может исполниться повторно при ретрае клиента.
  Практический компромисс — AOF `everysec`.
- **Асинхронная репликация**: failover может потерять последние claim.
- **`claimTtlSeconds` ограничивает обработчик**: он должен превышать самый
  долгий допустимый runtime обработчика, иначе поздний `store()` зомби
  фенсится (by design — два ответа не должны доставляться никогда), а
  дубликат уже исполнил эффект.

## Public API

| Класс | Описание |
|---|---|
| `RedisIdempotencyStorage` | `IdempotencyStorage` поверх Redis: атомарные Lua claim/store/release, обе capability ядра |
| `IdempotencyScriptRunner` | Интерфейс: исполняет Lua-скрипт на сервере |
| `Redis\PhpRedisScriptRunner` | Раннер поверх `ext-redis` (`\Redis`) |
| `Redis\PredisScriptRunner` | Раннер поверх `predis/predis` |
| `UnexpectedRedisReplyException` | Ответ не читается — key space пишет кто-то другой |

## Примеры

См. [examples/](examples/): подключение обоих раннеров и хранилище за
middleware.

## Разработка

```bash
composer build       # validate → normalize → require-checker → cs → psalm → test
composer test        # unit-сьют (мок раннера, Redis не нужен)
vendor/bin/testo --suite=Integration   # нужен REDIS_HOST
composer mutation    # нужен REDIS_HOST + pcov
```

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
