<?php

declare(strict_types=1);

use Phramark\ResultSet;

require dirname(__DIR__, 2) . '/src/RuntimeProfile.php';
require dirname(__DIR__, 2) . '/src/ResultSet.php';

// Tabulates the newest released build of each CMS plus Evolution 3.5.x:
// one guest row per stack × version × JIT × rate and one admin row per
// stack × version × JIT, with the PHP-side and frontend-side memory columns.
// As Markdown for the README, or as JSON for the static results site
// (docs/results.json, sorted in the browser by docs/site.js).
//
// Usage: php summary.php [RESULTS_DIR] [--json] [--since=2026-09-24T14:00:00Z]
//   --json also stamps the build's generatedAt into the script URLs of the
//   site (docs/index.html, docs/site.js), so browsers fetch the scripts anew.
//   --since selects current-run rows within each cell when present, so older
//   runs do not blend into that cell's spread. Cells not measured in this run
//   retain their existing history in the summary.

$arguments = array_slice($argv, 1);
$json = in_array('--json', $arguments, true);
$since = null;
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--since=')) {
        $since = substr($argument, strlen('--since='));
    }
}
$arguments = array_values(array_filter($arguments, static fn (string $argument): bool => $argument !== '--json' && !str_starts_with($argument, '--since=')));
$dir = $arguments[0] ?? dirname(__DIR__) . '/results';

$set = ResultSet::collect($dir, $since);
if ($json) {
    $docs = dirname(__DIR__, 2) . '/docs';
    foreach (ResultSet::stampScripts($set['generatedAt'], (string) file_get_contents($docs . '/index.html'), (string) file_get_contents($docs . '/site.js')) as $file => $contents) {
        file_put_contents($docs . '/' . $file, $contents);
    }
}
echo $json
    ? json_encode($set, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
    : ResultSet::markdown($set);
