<?php

declare(strict_types=1);

use Rasuvaeff\Understudy\Testo\UnderstudyPlugin;
use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\FinderConfig;
use Testo\Application\Config\SuiteConfig;

return new ApplicationConfig(
    src: ['src'],
    suites: [
        new SuiteConfig(
            name: 'Unit',
            // tests/Integration needs a running Redis (skipped via REDIS_HOST);
            // keep it out of the default Unit suite so `composer build` stays
            // green with no Redis reachable.
            location: new FinderConfig(include: ['tests'], exclude: ['tests/Integration']),
            plugins: [new UnderstudyPlugin()],
        ),
        new SuiteConfig(
            name: 'Integration',
            location: ['tests/Integration'],
        ),
        new SuiteConfig(
            name: 'Benchmarks',
            location: ['benchmarks'],
        ),
    ],
);
