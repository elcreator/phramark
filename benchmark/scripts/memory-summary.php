<?php

declare(strict_types=1);

// Summarises a PHP-side memory log (benchmark/fixtures/memory-prepend.php):
// requests, peak memory percentiles, and the same per X-Phramark-Step when a
// workload attributed its requests. Prints text; --json prints the summary as
// JSON for the admin report merge.
//
// Usage: php memory-summary.php memory.log [--json]

$file = $argv[1] ?? '';
$asJson = in_array('--json', $argv, true);
$lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
if ($lines === false) {
    fwrite(STDERR, "Unreadable memory log: {$file}\n");
    exit(2);
}

$all = [];
$steps = [];
foreach ($lines as $line) {
    $entry = json_decode($line, true);
    if (!is_array($entry) || !isset($entry['peak_real'])) {
        continue;
    }
    $all[] = $entry;
    if (!empty($entry['step'])) {
        $steps[$entry['step']][] = $entry;
    }
}

function stats(array $entries, string $key): array
{
    $values = array_map(static fn (array $entry): float => (float) $entry[$key], $entries);
    sort($values);
    $count = count($values);
    $percentile = static fn (float $fraction): float => $values[max(0, min($count - 1, (int) ceil($fraction * $count) - 1))];

    return $count === 0 ? ['count' => 0] : [
        'count' => $count,
        'median' => $percentile(0.5),
        'p95' => $percentile(0.95),
        'max' => $values[$count - 1],
        'mean' => round(array_sum($values) / $count, 2),
    ];
}

$mib = static fn (float $bytes): string => sprintf('%.1f MiB', $bytes / 1048576);
$summary = [
    'requests' => count($all),
    'peak_real' => stats($all, 'peak_real'),
    'peak' => stats($all, 'peak'),
    'non_2xx' => count(array_filter($all, static fn (array $entry): bool => (int) $entry['status'] >= 300)),
    'steps' => [],
];
foreach ($steps as $step => $entries) {
    $summary['steps'][$step] = [
        'requests' => count($entries),
        'peak_real' => stats($entries, 'peak_real'),
        'peak' => stats($entries, 'peak'),
        // Password hashing over all requests of the step (the admin report
        // takes it out of the step's times).
        'hash_ms' => round(array_sum(array_map(static fn (array $entry): float => (float) ($entry['hash_ms'] ?? 0), $entries)), 2),
    ];
}

if ($asJson) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

if ($summary['requests'] === 0) {
    echo "PHP memory: no requests recorded\n";
    exit(0);
}
printf(
    "PHP memory (per request, allocator peak): median %s, p95 %s, max %s over %d requests (%d non-2xx); script peak median %s\n",
    $mib($summary['peak_real']['median']),
    $mib($summary['peak_real']['p95']),
    $mib($summary['peak_real']['max']),
    $summary['requests'],
    $summary['non_2xx'],
    $mib($summary['peak']['median']),
);
foreach ($summary['steps'] as $step => $stat) {
    printf("  %-28s %2d requests, peak max %s\n", $step, $stat['requests'], $mib($stat['peak_real']['max']));
}
