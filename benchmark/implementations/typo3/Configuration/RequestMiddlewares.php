<?php

declare(strict_types=1);

// The adapter answers before TYPO3 resolves a site: the benchmark install
// creates no site configuration, so anything later in the stack is a 404.
return [
    'frontend' => [
        'phramark/typo3-benchmark-category' => [
            'target' => \Phramark\Typo3Benchmark\Middleware\CategoryMiddleware::class,
            'after' => ['typo3/cms-frontend/timetracker'],
            'before' => ['typo3/cms-frontend/site'],
        ],
    ],
];
