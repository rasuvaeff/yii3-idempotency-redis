<?php

declare(strict_types=1);

use Rasuvaeff\RectorNamedLiterals\AddNameToLiteralArgumentRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodParameterRector;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withRules([AddNameToLiteralArgumentRector::class])
    ->withSkip([
        // `@var mixed` on the Redis reply is load-bearing: it suppresses
        // Psalm's MixedAssignment at the untyped client boundary.
        RemoveUselessVarTagRector::class,
        // `$x === null` reads clearer than `!$x instanceof FQCN` in guards;
        // keep the null comparison.
        FlipTypeControlToUseExclusiveTypeRector::class,
        // `ConfigWiringTest::loadDi()` hands `$params` to the `require`d
        // `config/di.php` through variable scope, which rector cannot see: it
        // reads the parameter as dead and strips it, silently turning every
        // params-driven wiring assertion into a test of the defaults.
        RemoveUnusedPrivateMethodParameterRector::class => [
            __DIR__ . '/tests/Integration/ConfigWiringTest.php',
        ],
    ]);
