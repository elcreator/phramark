<?php

declare(strict_types=1);

use Phramark\RuntimeProfile;

require dirname(__DIR__, 2) . '/src/RuntimeProfile.php';

// Tabulates every recorded result in benchmark/results as Markdown: one guest
// table (latest run per stack × JIT × rate) and one admin table (latest run
// per stack × JIT), both with the PHP-side and frontend-side memory columns.
//
// Usage: php summary.php [RESULTS_DIR]

$dir = $argv[1] ?? dirname(__DIR__) . '/results';
$stacks = array_keys(RuntimeProfile::adapters());
$order = static fn (string $stack): int => (int) array_search($stack, $stacks, true);

$guest = [];
foreach (glob($dir . '/guest-*-rps-*.txt') ?: [] as $file) {
    if (preg_match('#guest-(.+)-jit-(off|tracing)-rps-(\d+)\.txt$#', $file, $m) !== 1) {
        continue;
    }
    $text = (string) file_get_contents($file);
    $row = ['stack' => $m[1], 'jit' => $m[2], 'rate' => (int) $m[3], 'mtime' => filemtime($file)];
    preg_match('#^\s*50\.000%\s+(\S+)#m', $text, $p50);
    preg_match('#^\s*99\.000%\s+(\S+)#m', $text, $p99);
    preg_match('#Requests/sec:\s+([\d.]+)#', $text, $rps);
    preg_match('#Non-2xx or 3xx responses:\s+(\d+)#', $text, $errors);
    preg_match('#PHP memory \(per request, allocator peak\): median (\S+ MiB), p95 (\S+ MiB), max (\S+ MiB) over (\d+) requests.*script peak median (\S+ MiB)#', $text, $mem);
    preg_match('#container peak RSS: ([\d.]+) MiB#', $text, $rss);
    $row += [
        'p50' => $p50[1] ?? '-', 'p99' => $p99[1] ?? '-', 'rps' => $rps[1] ?? '-', 'errors' => $errors[1] ?? '0',
        'php_script' => $mem[5] ?? '-', 'php_alloc_p95' => $mem[2] ?? '-', 'rss' => isset($rss[1]) ? $rss[1] . ' MiB' : '-',
    ];
    $key = $row['stack'] . '|' . $row['jit'] . '|' . $row['rate'];
    if (!isset($guest[$key]) || $guest[$key]['mtime'] < $row['mtime']) {
        $guest[$key] = $row;
    }
}
uasort($guest, static fn (array $a, array $b): int => [$a['rate'], $order($a['stack']), $a['jit']] <=> [$b['rate'], $order($b['stack']), $b['jit']]);

$admin = [];
foreach (glob($dir . '/admin-*.json') ?: [] as $file) {
    if (str_ends_with($file, '.memory.json')) {
        continue;
    }
    $report = json_decode((string) file_get_contents($file), true);
    if (!is_array($report) || !in_array($report['jit'] ?? 'unknown', ['off', 'tracing'], true)) {
        continue;
    }
    $key = $report['stack'] . '|' . $report['jit'];
    if (!isset($admin[$key]) || strcmp($admin[$key]['recordedAt'], $report['recordedAt']) < 0) {
        $admin[$key] = $report;
    }
}
uasort($admin, static fn (array $a, array $b): int => [$order($a['stack']), $a['jit']] <=> [$order($b['stack']), $b['jit']]);

$cell = static function (array $report, string $action, string $metric, string $unit = ''): string {
    $stats = $report['summary'][$metric][$action] ?? null;

    return $stats === null ? '-' : $stats['median'] . ($unit === '' ? '' : ' ' . $unit);
};

echo "## Guest workload (wrk2, constant offered rate)\n\n";
echo "| Stack | JIT | Offered | Achieved rps | p50 | p99 | Non-2xx | PHP peak/request (script median) | PHP alloc peak p95 | FPM container peak RSS |\n";
echo "| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |\n";
foreach ($guest as $row) {
    printf("| %s | %s | %d | %s | %s | %s | %s | %s | %s | %s |\n", $row['stack'], $row['jit'], $row['rate'], $row['rps'], $row['p50'], $row['p99'], $row['errors'], $row['php_script'], $row['php_alloc_p95'], $row['rss']);
}

echo "\n## Admin workload (Playwright; medians per action, wall / server)\n\n";
echo "| Stack | JIT | login | open-edit | save-edit | open-create | save-create | logout | Total wall |\n";
echo "| --- | --- | --- | --- | --- | --- | --- | --- | --- |\n";
foreach ($admin as $report) {
    $total = array_sum(array_column($report['steps'], 'ms'));
    $cells = [];
    foreach (['login', 'open-edit', 'save-edit', 'open-create', 'save-create', 'logout'] as $action) {
        $cells[] = $cell($report, $action, 'ms') . ' / ' . $cell($report, $action, 'serverMs') . ' ms';
    }
    printf("| %s | %s | %s | %s ms |\n", $report['stack'], $report['jit'], implode(' | ', $cells), round($total));
}

echo "\n## Admin workload memory (medians per action)\n\n";
echo "| Stack | JIT | Side | login | open-edit | save-edit | open-create | save-create | logout | FPM container peak |\n";
echo "| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |\n";
foreach ($admin as $report) {
    foreach (['PHP peak (script)' => ['phpPeakMb', 'MiB'], 'Frontend JS heap' => ['jsHeapUsedMb', 'MiB'], 'Frontend DOM nodes' => ['domNodes', '']] as $side => [$metric, $unit]) {
        $cells = [];
        foreach (['login', 'open-edit', 'save-edit', 'open-create', 'save-create', 'logout'] as $action) {
            $cells[] = $cell($report, $action, $metric, $unit);
        }
        printf("| %s | %s | %s | %s | %s |\n", $report['stack'], $report['jit'], $side, implode(' | ', $cells), $metric === 'phpPeakMb' && isset($report['containerPeakMb']) ? $report['containerPeakMb'] . ' MiB' : '');
    }
}
