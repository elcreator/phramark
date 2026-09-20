<?php

declare(strict_types=1);

/**
 * Summarises a benchmark/scripts/manticore-compile-all report: how many of
 * the tree's PHP files Manticore compiles as single units, per top-level
 * area (Evolution core, manager, vendor packages) and the diagnostics that
 * stop the rest. Prints Markdown; `--json` prints the same as JSON.
 *
 * Usage: php manticore-summary.php <report.tsv> [--json]
 */

/** @return array{area: string, status: string, diagnostic: string, seconds: float, cached: bool} */
function parseLine(string $line): array
{
    [$status, $file, $seconds, $diagnostic, $origin] = array_pad(explode("\t", rtrim($line, "\r\n"), 5), 5, '');
    $path = preg_replace('#^/site/#', '', $file) ?? $file;
    $area = match (true) {
        str_starts_with($path, 'core/vendor/') => 'core/vendor (' . implode('/', array_slice(explode('/', $path), 2, 1)) . ')',
        str_starts_with($path, 'core/') => 'core/' . explode('/', $path)[1],
        str_starts_with($path, 'manager/') => 'manager',
        str_starts_with($path, 'assets/') => 'assets',
        default => $path,
    };

    return ['area' => $area, 'status' => $status, 'diagnostic' => normaliseDiagnostic($diagnostic), 'seconds' => (float) $seconds, 'cached' => $origin === 'cached'];
}

function normaliseDiagnostic(string $diagnostic): string
{
    $diagnostic = preg_replace('#/tmp/manticore_\d+\.ll:\d+:\d+: #', '', $diagnostic) ?? $diagnostic;
    $diagnostic = preg_replace('#^/site/\S+\.php: #', '', $diagnostic) ?? $diagnostic;
    $diagnostic = preg_replace('#/site/\S+\.php:\d+#', '<file>', $diagnostic) ?? $diagnostic;
    $diagnostic = preg_replace('#\[\{.*$#', '', $diagnostic) ?? $diagnostic;
    $diagnostic = preg_replace('#\$\w+#', '$var', $diagnostic) ?? $diagnostic;
    $diagnostic = preg_replace('#\bin \S+ but\b#', 'in <fn> but', $diagnostic) ?? $diagnostic;
    $diagnostic = preg_replace('#line \d+, column \d+#', 'line N', $diagnostic) ?? $diagnostic;
    $diagnostic = preg_replace('#\b\d+\b#', 'N', $diagnostic) ?? $diagnostic;

    return trim($diagnostic) === '' ? '(no diagnostic; timeout or non-zero exit)' : trim($diagnostic);
}

/**
 * @param list<array{area: string, status: string, diagnostic: string, seconds: float, cached: bool}> $rows
 * @return array{total: int, ok: int, fail: int, timeout: int, cached: int, areas: array<string, array{files: int, ok: int}>, diagnostics: array<string, int>}
 */
function summarise(array $rows): array
{
    $summary = ['total' => 0, 'ok' => 0, 'fail' => 0, 'timeout' => 0, 'cached' => 0, 'areas' => [], 'diagnostics' => []];
    foreach ($rows as $row) {
        $summary['total']++;
        $summary['cached'] += $row['cached'] ? 1 : 0;
        $summary[$row['status']] = ($summary[$row['status']] ?? 0) + 1;
        $summary['areas'][$row['area']] ??= ['files' => 0, 'ok' => 0];
        $summary['areas'][$row['area']]['files']++;
        if ($row['status'] === 'ok') {
            $summary['areas'][$row['area']]['ok']++;
        } else {
            $summary['diagnostics'][$row['diagnostic']] = ($summary['diagnostics'][$row['diagnostic']] ?? 0) + 1;
        }
    }
    ksort($summary['areas']);
    arsort($summary['diagnostics']);

    return $summary;
}

if (PHP_SAPI === 'cli' && realpath($argv[0]) === __FILE__) {
    $report = $argv[1] ?? '';
    $lines = $report === '' ? false : file($report, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        fwrite(STDERR, "usage: manticore-summary.php <report.tsv> [--json]\n");
        exit(2);
    }
    $summary = summarise(array_map('parseLine', $lines));
    if (in_array('--json', $argv, true)) {
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
        exit(0);
    }
    printf("%d files: %d compile (%.1f%%), %d fail, %d time out; %d verdicts from the result cache\n\n", $summary['total'], $summary['ok'], $summary['ok'] * 100 / max(1, $summary['total']), $summary['fail'], $summary['timeout'], $summary['cached']);
    echo "| Area | Files | Compile | Share |\n| --- | ---: | ---: | ---: |\n";
    foreach ($summary['areas'] as $area => $counts) {
        printf("| `%s` | %d | %d | %.0f%% |\n", $area, $counts['files'], $counts['ok'], $counts['ok'] * 100 / $counts['files']);
    }
    echo "\n| Diagnostic (normalised) | Files |\n| --- | ---: |\n";
    foreach (array_slice($summary['diagnostics'], 0, 15, true) as $diagnostic => $count) {
        printf("| `%s` | %d |\n", str_replace('|', '\\|', $diagnostic), $count);
    }
}
