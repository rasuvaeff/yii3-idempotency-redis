<?php

declare(strict_types=1);

return [
    'rasuvaeff/yii3-idempotency-redis' => [
        // namespaces the hashes, so several applications can share one Redis
        // without colliding. Must not be empty.
        'keyPrefix' => 'idempotency:',
        // deadline for an in-flight claim. The moment it passes, the hash
        // expires and the key is free again: a handler must finish well
        // within it, or a concurrent duplicate may claim the key and run the
        // effect a second time.
        'claimTtlSeconds' => 3600,
    ],
];
