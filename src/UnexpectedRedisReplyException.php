<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyRedis;

/**
 * Thrown when Redis answers a script with a shape this storage cannot read —
 * a truncated reply, a non-numeric integer field, or a hash whose stored
 * fields do not parse back into a record.
 *
 * A reply like that means the key space is being written by something other
 * than this package (a manual `redis-cli` edit, another tool reusing the
 * prefix), and silently answering from a half-read row would be worse than
 * failing the request.
 *
 * @api
 */
final class UnexpectedRedisReplyException extends \RuntimeException {}
