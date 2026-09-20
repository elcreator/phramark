<?php

declare(strict_types=1);

namespace Phramark;

/**
 * Collects every recorded result in benchmark/results into one structure:
 * the latest guest run per stack × JIT × offered rate and the latest admin
 * run per stack × JIT, with the PHP-side and frontend-side memory figures.
 * The same structure feeds the Markdown tables of the README (summary.php)
 * and the JSON the static results site sorts in the browser (docs/).
 */
final class ResultSet
{
    public const ACTIONS = ['login', 'open-edit', 'save-edit', 'open-create', 'save-create', 'logout'];
    public const ADMIN_METRICS = ['ms', 'serverMs', 'phpPeakMb', 'jsHeapUsedMb', 'domNodes'];

    /**
     * @return array{generatedAt: string, stacks: array<string, array{label: string, framework: string, components: list<string>, port: int}>, guest: list<array<string, mixed>>, admin: list<array<string, mixed>>}
     */
    public static function collect(string $dir): array
    {
        $stacks = array_keys(RuntimeProfile::adapters());
        $order = static fn (string $stack): int => (int) array_search($stack, $stacks, true);

        // Every repetition of a cell is kept; the row is the latest run, with the
        // spread across repetitions (the measuring error) and the memory series
        // of the latest run attached.
        $runs = [];
        foreach (glob($dir . '/guest-*-rps-*.txt') ?: [] as $file) {
            $row = self::guestRow($file);
            if ($row === null) {
                continue;
            }
            $runs[$row['stack'] . '|' . $row['jit'] . '|' . $row['rate']][] = $row;
        }
        $guest = [];
        foreach ($runs as $key => $rows) {
            usort($rows, static fn (array $a, array $b): int => strcmp($a['recordedAt'], $b['recordedAt']));
            $latest = $rows[count($rows) - 1];
            $latest['repeats'] = self::spread($rows, ['rps', 'p50Ms', 'p99Ms', 'phpScriptMedianMb', 'phpAllocP95Mb', 'containerPeakMb']);
            $base = $dir . '/' . substr($latest['file'], 0, -4);
            $latest['series'] = [
                'phpPeak' => self::requestSeries($base . '.memory.log'),
                'containerRss' => self::sampleSeries($base . '.rss.log'),
            ];
            $guest[$key] = $latest;
        }
        uasort($guest, static fn (array $a, array $b): int => [$a['rate'], $order($a['stack']), $a['jit']] <=> [$b['rate'], $order($b['stack']), $b['jit']]);

        $runs = [];
        foreach (glob($dir . '/admin-*.json') ?: [] as $file) {
            if (str_ends_with($file, '.memory.json')) {
                continue;
            }
            $row = self::adminRow($file);
            if ($row === null) {
                continue;
            }
            $runs[$row['stack'] . '|' . $row['jit']][] = $row;
        }
        $admin = [];
        foreach ($runs as $key => $rows) {
            usort($rows, static fn (array $a, array $b): int => strcmp($a['recordedAt'], $b['recordedAt']));
            $latest = $rows[count($rows) - 1];
            $flat = static fn (array $row): array => array_merge(['totalWallMs' => $row['totalWallMs']], ...array_map(
                static fn (string $action): array => [$action . '.ms' => $row['actions'][$action]['ms'], $action . '.serverMs' => $row['actions'][$action]['serverMs']],
                self::ACTIONS
            ));
            $latest['repeats'] = self::spread(array_map($flat, $rows), array_keys($flat($latest)));
            $latest['series'] = [
                'steps' => $latest['steps'],
                'phpPeak' => self::requestSeries($dir . '/' . substr($latest['file'], 0, -5) . '.memory.log'),
            ];
            unset($latest['steps']);
            $admin[$key] = $latest;
        }
        uasort($admin, static fn (array $a, array $b): int => [$order($a['stack']), $a['jit']] <=> [$order($b['stack']), $b['jit']]);

        // The exact versions the containers reported (benchmark/scripts/versions.php).
        $versions = is_file($dir . '/versions.json') ? json_decode((string) file_get_contents($dir . '/versions.json'), true) : null;

        return [
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'stacks' => RuntimeProfile::adapters(),
            'versions' => is_array($versions) ? $versions : null,
            'guest' => array_values($guest),
            'admin' => array_values($admin),
        ];
    }

    /**
     * One wrk2 result file (raw output plus the ---- memory ---- section).
     *
     * @return array<string, mixed>|null
     */
    public static function guestRow(string $file): ?array
    {
        if (preg_match('#guest-(.+)-jit-(off|tracing)-rps-(\d+)(?:-(\d{4}-\d{2}-\d{2}T\d{2}-\d{2}-\d{2}Z))?\.txt$#', $file, $m) !== 1) {
            return null;
        }
        $text = (string) file_get_contents($file);
        preg_match('#^\s*50\.000%\s+(\S+)#m', $text, $p50);
        preg_match('#^\s*99\.000%\s+(\S+)#m', $text, $p99);
        preg_match('#Requests/sec:\s+([\d.]+)#', $text, $rps);
        preg_match('#Non-2xx or 3xx responses:\s+(\d+)#', $text, $errors);
        preg_match('#PHP memory \(per request, allocator peak\): median (\S+) MiB, p95 (\S+) MiB, max (\S+) MiB over (\d+) requests.*script peak median (\S+) MiB#', $text, $mem);
        preg_match('#container peak RSS: ([\d.]+) MiB#', $text, $rss);

        return [
            'stack' => $m[1],
            'jit' => $m[2],
            'rate' => (int) $m[3],
            // Timestamped names carry the run time; older names fall back to mtime.
            'recordedAt' => isset($m[4]) ? preg_replace('/T(\d{2})-(\d{2})-(\d{2})Z$/', 'T$1:$2:$3Z', $m[4]) : gmdate('Y-m-d\TH:i:s\Z', (int) filemtime($file)),
            'file' => basename($file),
            'rps' => isset($rps[1]) ? (float) $rps[1] : null,
            'p50Ms' => isset($p50[1]) ? self::parseLatencyMs($p50[1]) : null,
            'p99Ms' => isset($p99[1]) ? self::parseLatencyMs($p99[1]) : null,
            'errors' => (int) ($errors[1] ?? 0),
            'requests' => isset($mem[4]) ? (int) $mem[4] : null,
            'phpScriptMedianMb' => isset($mem[5]) ? (float) $mem[5] : null,
            'phpAllocMedianMb' => isset($mem[1]) ? (float) $mem[1] : null,
            'phpAllocP95Mb' => isset($mem[2]) ? (float) $mem[2] : null,
            'phpAllocMaxMb' => isset($mem[3]) ? (float) $mem[3] : null,
            'containerPeakMb' => isset($rss[1]) ? (float) $rss[1] : null,
        ];
    }

    /**
     * One admin workload report (benchmark/workloads/admin/lib/report.mjs).
     *
     * @return array<string, mixed>|null
     */
    public static function adminRow(string $file): ?array
    {
        $report = json_decode((string) file_get_contents($file), true);
        if (!is_array($report) || !in_array($report['jit'] ?? 'unknown', ['off', 'tracing'], true) || !isset($report['stack'], $report['summary'], $report['steps'])) {
            return null;
        }
        $actions = [];
        foreach (self::ACTIONS as $action) {
            foreach (self::ADMIN_METRICS as $metric) {
                $actions[$action][$metric] = $report['summary'][$metric][$action]['median'] ?? null;
            }
        }

        return [
            'stack' => $report['stack'],
            'jit' => $report['jit'],
            'rounds' => (int) ($report['rounds'] ?? 1),
            'pages' => (int) ($report['pages'] ?? 0),
            'recordedAt' => (string) ($report['recordedAt'] ?? ''),
            'file' => basename($file),
            'totalWallMs' => round(array_sum(array_column($report['steps'], 'ms'))),
            'containerPeakMb' => isset($report['containerPeakMb']) ? (float) $report['containerPeakMb'] : null,
            'actions' => $actions,
            'steps' => array_values(array_map(static fn (array $step, int $index): array => [
                'index' => $index + 1,
                'action' => $step['action'] ?? '',
                'label' => $step['label'] ?? '',
                'ms' => $step['ms'] ?? null,
                'serverMs' => $step['serverMs'] ?? null,
                'phpPeakMb' => $step['phpPeakMb'] ?? null,
                'jsHeapUsedMb' => $step['jsHeapUsedMb'] ?? null,
                'domNodes' => $step['domNodes'] ?? null,
            ], $report['steps'], array_keys($report['steps']))),
        ];
    }

    /**
     * The measuring error of a cell: for every metric, the median, min, max
     * and coefficient of variation across its repetitions. With one run the
     * spread is unknown (null), which the site shows instead of pretending.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string> $metrics
     * @return array{n: int, metrics: array<string, array{median: float, min: float, max: float, cv: float|null}|null>}
     */
    public static function spread(array $rows, array $metrics): array
    {
        $result = ['n' => count($rows), 'metrics' => []];
        foreach ($metrics as $metric) {
            $values = array_values(array_filter(array_column($rows, $metric), static fn ($value): bool => $value !== null));
            if ($values === []) {
                $result['metrics'][$metric] = null;
                continue;
            }
            sort($values);
            $count = count($values);
            $mean = array_sum($values) / $count;
            $variance = $count > 1 ? array_sum(array_map(static fn (float $v): float => ($v - $mean) ** 2, $values)) / ($count - 1) : null;
            $result['metrics'][$metric] = [
                'median' => $count % 2 === 1 ? $values[intdiv($count, 2)] : round(($values[$count / 2 - 1] + $values[$count / 2]) / 2, 3),
                'min' => $values[0],
                'max' => $values[$count - 1],
                'cv' => $variance === null || $mean == 0 ? null : round(sqrt($variance) / $mean, 4),
            ];
        }

        return $result;
    }

    /**
     * Memory over time from a per-request log (memory-prepend.php): the run
     * cut into $buckets equal time slices with the median and maximum PHP
     * peak of the requests in each, plus a trend: the least-squares slope of
     * the per-request peak over time (MiB per minute) and the ratio of the
     * last to the first quarter of the run. A growing stack leaks or caches
     * per request; a flat one does not.
     *
     * @return array{points: list<array{t: float, requests: int, medianMb: float, maxMb: float}>, trend: array{slopeMbPerMin: float, firstQuarterMb: float, lastQuarterMb: float, growth: float}}|null
     */
    public static function requestSeries(string $file, int $buckets = 40): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $entries = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry) && isset($entry['t'], $entry['peak']) && ($entry['step'] ?? null) !== 'warmup') {
                $entries[] = [(float) $entry['t'], (float) $entry['peak'] / 1048576];
            }
        }
        if (count($entries) < 2) {
            return null;
        }
        usort($entries, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $start = $entries[0][0];
        $span = max($entries[count($entries) - 1][0] - $start, 0.001);
        $slices = array_fill(0, $buckets, []);
        foreach ($entries as [$t, $mb]) {
            $slices[min($buckets - 1, (int) floor(($t - $start) / $span * $buckets))][] = $mb;
        }
        $points = [];
        foreach ($slices as $index => $values) {
            if ($values === []) {
                continue;
            }
            sort($values);
            $points[] = [
                't' => round(($index + 0.5) / $buckets * $span, 2),
                'requests' => count($values),
                'medianMb' => round($values[intdiv(count($values), 2)], 2),
                'maxMb' => round($values[count($values) - 1], 2),
            ];
        }

        return ['points' => $points, 'trend' => self::trend($entries)];
    }

    /**
     * Container RSS over time from the docker stats samples ("EPOCH 123.4MiB";
     * a sample without a timestamp, from an older run, is placed by its line
     * number and gets no slope, since docker stats takes 1–3 s per sample).
     *
     * @return array{points: list<array{t: int, mb: float}>, trend: array{slopeMbPerMin: float, firstQuarterMb: float, lastQuarterMb: float, growth: float}}|null
     */
    public static function sampleSeries(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $entries = [];
        $timed = true;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $index => $line) {
            if (preg_match('/^(?:(\d+)\s+)?([\d.]+)\s*(GiB|MiB|KiB|B)$/', trim($line), $m) !== 1) {
                continue;
            }
            $mb = (float) $m[2] * match ($m[3]) { 'GiB' => 1024, 'MiB' => 1, 'KiB' => 1 / 1024, 'B' => 1 / 1048576 };
            $timed = $timed && $m[1] !== '';
            $entries[] = [$m[1] !== '' ? (float) $m[1] : (float) $index, $mb];
        }
        if (count($entries) < 2) {
            return null;
        }
        $start = $entries[0][0];
        $trend = self::trend($entries);
        if (!$timed) {
            $trend['slopeMbPerMin'] = null;
        }

        return [
            'points' => array_map(static fn (array $e): array => ['t' => (int) ($e[0] - $start), 'mb' => round($e[1], 1)], $entries),
            'trend' => $trend,
        ];
    }

    /**
     * @param list<array{0: float, 1: float}> $entries [time, value], time-sorted
     * @return array{slopeMbPerMin: float|null, firstQuarterMb: float, lastQuarterMb: float, growth: float}
     */
    public static function trend(array $entries): array
    {
        $count = count($entries);
        $start = $entries[0][0];
        $sumT = $sumV = $sumTT = $sumTV = 0.0;
        foreach ($entries as [$t, $v]) {
            $t -= $start;
            $sumT += $t;
            $sumV += $v;
            $sumTT += $t * $t;
            $sumTV += $t * $v;
        }
        $denominator = $count * $sumTT - $sumT * $sumT;
        $slope = $denominator == 0 ? 0.0 : ($count * $sumTV - $sumT * $sumV) / $denominator;
        $quarter = max(1, intdiv($count, 4));
        $median = static function (array $part): float {
            $values = array_column($part, 1);
            sort($values);

            return $values[intdiv(count($values), 2)];
        };
        $first = $median(array_slice($entries, 0, $quarter));
        $last = $median(array_slice($entries, -$quarter));

        return [
            'slopeMbPerMin' => round($slope * 60, 4),
            'firstQuarterMb' => round($first, 2),
            'lastQuarterMb' => round($last, 2),
            'growth' => $first == 0 ? 0.0 : round($last / $first - 1, 4),
        ];
    }

    /**
     * wrk prints latencies with a unit ("263.17ms", "1.02s", "850.00us").
     */
    public static function parseLatencyMs(string $value): ?float
    {
        if (preg_match('/^([\d.]+)(us|ms|s|m)$/', $value, $m) !== 1) {
            return null;
        }
        $number = (float) $m[1];

        return match ($m[2]) {
            'us' => round($number / 1000, 3),
            'ms' => $number,
            's' => $number * 1000,
            'm' => $number * 60_000,
        };
    }

    /**
     * The Markdown tables of the README's "Local smoke numbers" section.
     *
     * @param array{guest: list<array<string, mixed>>, admin: list<array<string, mixed>>} $set
     */
    public static function markdown(array $set): string
    {
        $ms = static fn (?float $value): string => $value === null ? '-' : sprintf('%.2fms', $value);
        $mib = static fn (?float $value): string => $value === null ? '-' : sprintf('%.1f MiB', $value);
        $number = static fn (?float $value): string => $value === null ? '-' : (string) $value;
        $out = '';
        if (isset($set['versions']['stacks'])) {
            $runtime = $set['versions']['runtime'] ?? [];
            $out .= sprintf("## Versions tested\n\nRecorded from the running containers on %s: PHP %s, MySQL %s, %s, OPcache %s.\n\n", $set['versions']['recordedAt'] ?? '?', $runtime['php'] ?? '?', $runtime['mysql'] ?? '?', $runtime['nginx'] ?? '?', $runtime['opcache'] ?? '?');
            $out .= "| Stack | Components (exact versions) |\n| --- | --- |\n";
            foreach ($set['versions']['stacks'] as $stack => $components) {
                $cells = array_map(static fn (array $c): string => isset($c['source']) ? sprintf('[%s](%s) %s', $c['name'], $c['source'], $c['version']) : $c['name'] . ' ' . $c['version'], $components);
                $out .= sprintf("| %s | %s |\n", $stack, implode(', ', $cells));
            }
            $out .= "\n";
        }
        $out .= "## Guest workload (wrk2, constant offered rate)\n\n";
        $out .= "| Stack | JIT | Offered | Achieved rps | p50 | p99 | Non-2xx | PHP peak/request (script median) | PHP alloc peak p95 | FPM container peak RSS |\n";
        $out .= "| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |\n";
        foreach ($set['guest'] as $row) {
            $out .= sprintf("| %s | %s | %d | %s | %s | %s | %d | %s | %s | %s |\n", $row['stack'], $row['jit'], $row['rate'], $number($row['rps']), $ms($row['p50Ms']), $ms($row['p99Ms']), $row['errors'], $mib($row['phpScriptMedianMb']), $mib($row['phpAllocP95Mb']), $mib($row['containerPeakMb']));
        }

        $out .= "\n## Admin workload (Playwright; medians per action, wall / server)\n\n";
        $out .= "| Stack | JIT | login | open-edit | save-edit | open-create | save-create | logout | Total wall |\n";
        $out .= "| --- | --- | --- | --- | --- | --- | --- | --- | --- |\n";
        foreach ($set['admin'] as $row) {
            $cells = [];
            foreach (self::ACTIONS as $action) {
                $cells[] = $number($row['actions'][$action]['ms']) . ' / ' . $number($row['actions'][$action]['serverMs']) . ' ms';
            }
            $out .= sprintf("| %s | %s | %s | %d ms |\n", $row['stack'], $row['jit'], implode(' | ', $cells), $row['totalWallMs']);
        }

        $out .= "\n## Admin workload memory (medians per action)\n\n";
        $out .= "| Stack | JIT | Side | login | open-edit | save-edit | open-create | save-create | logout | FPM container peak |\n";
        $out .= "| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |\n";
        foreach ($set['admin'] as $row) {
            foreach (['PHP peak (script)' => ['phpPeakMb', 'MiB'], 'Frontend JS heap' => ['jsHeapUsedMb', 'MiB'], 'Frontend DOM nodes' => ['domNodes', '']] as $side => [$metric, $unit]) {
                $cells = [];
                foreach (self::ACTIONS as $action) {
                    $value = $row['actions'][$action][$metric];
                    $cells[] = $value === null ? '-' : $value . ($unit === '' ? '' : ' ' . $unit);
                }
                $peak = $metric === 'phpPeakMb' && $row['containerPeakMb'] !== null ? $row['containerPeakMb'] . ' MiB' : '';
                $out .= sprintf("| %s | %s | %s | %s | %s |\n", $row['stack'], $row['jit'], $side, implode(' | ', $cells), $peak);
            }
        }

        return $out;
    }
}
