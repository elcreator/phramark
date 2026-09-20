<?php

declare(strict_types=1);

use Phramark\ResultSet;

require dirname(__DIR__, 2) . '/src/RuntimeProfile.php';
require dirname(__DIR__, 2) . '/src/ResultSet.php';

// Tabulates every recorded result in benchmark/results: one guest table
// (latest run per stack × JIT × rate) and one admin table (latest run per
// stack × JIT), both with the PHP-side and frontend-side memory columns.
// As Markdown for the README, or as JSON for the static results site
// (docs/results.json, sorted in the browser by docs/site.js).
//
// Usage: php summary.php [RESULTS_DIR] [--json]

$arguments = array_slice($argv, 1);
$json = in_array('--json', $arguments, true);
$arguments = array_values(array_filter($arguments, static fn (string $argument): bool => $argument !== '--json'));
$dir = $arguments[0] ?? dirname(__DIR__) . '/results';

$set = ResultSet::collect($dir);
echo $json
    ? json_encode($set, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
    : ResultSet::markdown($set);
