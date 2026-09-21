<?php

declare(strict_types=1);

namespace Phramark;

/**
 * Collects every recorded result in benchmark/results into one structure:
 * the latest guest run per stack × version × JIT × offered rate and the
 * latest admin run per stack × version × JIT, with the PHP-side and
 * frontend-side memory figures. The version of a row is what the stack
 * was actually built from, read from the components its container reported
 * (versions.php --attach): "3.5.8", "3.5.9 ../evolution@3f9ea9220",
 * "3.5.8 · aLatteX 0.5.0", "11.4.7". A default build and a run pinned to
 * the same release (matrix --versions=evo@3.5.8) therefore land in one
 * cell; the ref as the user gave it stays in "ref".
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
        // The exact versions the containers reported (benchmark/scripts/versions.php).
        $versions = is_file($dir . '/versions.json') ? json_decode((string) file_get_contents($dir . '/versions.json'), true) : null;
        $versions = is_array($versions) ? $versions : null;

        $runs = [];
        foreach (glob($dir . '/guest-*-rps-*.txt') ?: [] as $file) {
            $row = self::guestRow($file);
            if ($row === null) {
                continue;
            }
            $row = self::withResolvedVersion($row, $versions);
            $runs[$row['stack'] . '|' . $row['version'] . '|' . $row['jit'] . '|' . $row['rate']][] = $row;
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
        uasort($guest, static fn (array $a, array $b): int => [$a['rate'], $order($a['stack']), strnatcmp($a['version'], $b['version']), $a['jit']] <=> [$b['rate'], $order($b['stack']), 0, $b['jit']]);

        $runs = [];
        foreach (glob($dir . '/admin-*.json') ?: [] as $file) {
            if (str_ends_with($file, '.memory.json')) {
                continue;
            }
            $row = self::adminRow($file);
            if ($row === null) {
                continue;
            }
            $row = self::withResolvedVersion($row, $versions);
            $runs[$row['stack'] . '|' . $row['version'] . '|' . $row['jit']][] = $row;
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
        uasort($admin, static fn (array $a, array $b): int => [$order($a['stack']), strnatcmp($a['version'], $b['version']), $a['jit']] <=> [$order($b['stack']), 0, $b['jit']]);

        return [
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'stacks' => RuntimeProfile::adapters(),
            'versions' => $versions,
            'guest' => array_values($guest),
            'admin' => array_values($admin),
        ];
    }

    /**
     * One wrk2 result file (raw output plus the ---- memory ---- and
     * ---- versions ---- sections). The name is
     * guest-<stack>[~<version tag>]-jit-<mode>-rps-<rate>[-<timestamp>].txt.
     *
     * @return array<string, mixed>|null
     */
    public static function guestRow(string $file): ?array
    {
        if (preg_match('#guest-([a-z0-9-]+?)(?:~([^/\\\\]+?))?-jit-(off|tracing)-rps-(\d+)(?:-(\d{4}-\d{2}-\d{2}T\d{2}-\d{2}-\d{2}Z))?\.txt$#', $file, $m) !== 1) {
            return null;
        }
        [, $stack, $version, $jit, $rate] = $m;
        $stamp = $m[5] ?? '';
        $text = (string) file_get_contents($file);
        // The label line carries the version as given (the file name holds a
        // sanitised form: "latte@../evo/aLatteX" becomes "latte@.._evo_aLatteX").
        preg_match('#^---- versions ----\n(?:label: (.*)\n)?(\[.*\])$#m', $text, $versions);
        if (isset($versions[1]) && $versions[1] !== '') {
            $version = $versions[1];
        }
        preg_match('#^\s*50\.000%\s+(\S+)#m', $text, $p50);
        preg_match('#^\s*99\.000%\s+(\S+)#m', $text, $p99);
        preg_match('#Requests/sec:\s+([\d.]+)#', $text, $rps);
        preg_match('#Non-2xx or 3xx responses:\s+(\d+)#', $text, $errors);
        preg_match('#PHP memory \(per request, allocator peak\): median (\S+) MiB, p95 (\S+) MiB, max (\S+) MiB over (\d+) requests.*script peak median (\S+) MiB#', $text, $mem);
        preg_match('#container peak RSS: ([\d.]+) MiB#', $text, $rss);

        return [
            'stack' => $stack,
            'version' => $version,
            'jit' => $jit,
            'rate' => (int) $rate,
            // Timestamped names carry the run time; older names fall back to mtime.
            'recordedAt' => $stamp !== '' ? preg_replace('/T(\d{2})-(\d{2})-(\d{2})Z$/', 'T$1:$2:$3Z', $stamp) : gmdate('Y-m-d\TH:i:s\Z', (int) filemtime($file)),
            'file' => basename($file),
            'components' => isset($versions[2]) ? json_decode($versions[2], true) : null,
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
            'version' => (string) ($report['version'] ?? ''),
            'jit' => $report['jit'],
            'components' => isset($report['components']) && is_array($report['components']) ? $report['components'] : null,
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
     * The components that name a stack's build, in the order they are shown:
     * the CMS first (bare version), then the extensions the harness adds.
     */
    private const BUILD_COMPONENTS = [
        'evo-parser' => ['Evolution CMS' => ''],
        'evo-latte' => ['Evolution CMS' => '', 'elcreator/alattex' => 'aLatteX'],
        'evo-latte-parser' => ['Evolution CMS' => '', 'elcreator/alattex' => 'aLatteX'],
        'evo-phalcon' => ['Evolution CMS' => '', 'elcreator/alattex' => 'aLatteX', 'elcreator/aphalcon' => 'aPhalcon'],
        'drupal' => ['drupal/core' => ''],
        'typo3' => ['typo3/cms-core' => ''],
        'winter' => ['winter/wn-cms-module' => ''],
        'modx' => ['MODX Revolution' => ''],
        'wordpress-gantry' => ['WordPress' => '', 'gantry5' => 'Gantry'],
    ];

    /**
     * The version a row is shown and grouped under: the build its components
     * describe (buildVersion), or, for a run recorded before components were
     * attached, the versions.json snapshot of that stack when that is a
     * release build (a path or branch build there says nothing about an old
     * run). Otherwise the label as given. The given label is kept as "ref".
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $versions
     * @return array<string, mixed>
     */
    public static function withResolvedVersion(array $row, ?array $versions): array
    {
        $row['ref'] = $row['version'];
        $resolved = is_array($row['components'] ?? null) ? self::buildVersion($row['stack'], $row['components']) : null;
        if ($resolved === null && $row['version'] === '' && is_array($versions['stacks'][$row['stack']] ?? null)) {
            $snapshot = self::buildVersion($row['stack'], $versions['stacks'][$row['stack']]);
            if ($snapshot !== null && !str_contains($snapshot, '@')) {
                $resolved = $snapshot;
            }
        }
        if ($resolved !== null) {
            $row['version'] = $resolved;
        }

        return $row;
    }

    /**
     * "3.5.8", "3.5.9 ../evolution@3f9ea9220", "3.5.x@1a2b3c4" (a branch),
     * "3.5.8 · aLatteX 0.5.0", "7.1.1 · Gantry 5.6.4", "… · NO_SESSION" for a
     * site without a front-end session; null when the components do not
     * name the stack's CMS.
     *
     * @param list<array{name: string, version: string}> $components
     */
    public static function buildVersion(string $stack, array $components): ?string
    {
        $byName = array_column($components, 'version', 'name');
        $parts = [];
        foreach (self::BUILD_COMPONENTS[$stack] ?? [] as $name => $label) {
            if (!isset($byName[$name])) {
                if ($label === '') {
                    return null;
                }
                continue;
            }
            $version = self::shortVersion((string) $byName[$name]);
            $parts[] = $label === '' ? $version : $label . ' ' . $version;
        }
        // A site set up without a front-end session (EVO_NO_SESSION=1) is
        // another build of the same code: it stays a cell of its own.
        if ($parts !== [] && str_contains((string) ($byName['front-end session'] ?? ''), 'NO_SESSION')) {
            $parts[] = 'NO_SESSION';
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * A component version as versions.php records it, shortened: a leading
     * "v" dropped; Evolution's "(tag X@sha, date)" dropped (the version says
     * it), "(branch X@sha, date)" kept as "X@sha", "(path /host/dir@sha …)"
     * as "../dir@sha"; an extension's "dev-local (dir@fingerprint)" as
     * "../dir@fingerprint" (eight characters of it).
     */
    public static function shortVersion(string $version): string
    {
        $version = preg_replace('/^v(?=\d)/', '', trim($version)) ?? $version;
        // An extension from a host directory: "dev-local (evo/aLatteX@<fingerprint>)".
        if (preg_match('/^dev-local \((\S+)@([0-9a-f]+)\)$/', $version, $m) === 1) {
            return '../' . $m[1] . '@' . substr($m[2], 0, 8);
        }
        if (preg_match('/^(\S+) \((tag|branch|path) (\S+?)(?:@([0-9a-f]+))?(?:[ ,)].*)?$/', $version, $m) === 1) {
            return match ($m[2]) {
                'tag' => $m[1],
                'branch' => $m[1] . ' ' . $m[3] . (isset($m[4]) ? '@' . $m[4] : ''),
                'path' => $m[1] . ' ' . preg_replace('#^/host/#', '../', $m[3]) . (isset($m[4]) ? '@' . $m[4] : ''),
            };
        }

        return $version;
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
        $name = static fn (array $row): string => $row['stack'] . ($row['version'] !== '' ? ' (' . $row['version'] . ')' : '');
        $out .= "## Guest workload (wrk2, constant offered rate)\n\n";
        $out .= "| Stack | JIT | Offered | Achieved rps | p50 | p99 | Non-2xx | PHP peak/request (script median) | PHP alloc peak p95 | FPM container peak RSS |\n";
        $out .= "| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |\n";
        foreach ($set['guest'] as $row) {
            $out .= sprintf("| %s | %s | %d | %s | %s | %s | %d | %s | %s | %s |\n", $name($row), $row['jit'], $row['rate'], $number($row['rps']), $ms($row['p50Ms']), $ms($row['p99Ms']), $row['errors'], $mib($row['phpScriptMedianMb']), $mib($row['phpAllocP95Mb']), $mib($row['containerPeakMb']));
        }

        $out .= "\n## Admin workload (Playwright; medians per action, wall / server)\n\n";
        $out .= "| Stack | JIT | login | open-edit | save-edit | open-create | save-create | logout | Total wall |\n";
        $out .= "| --- | --- | --- | --- | --- | --- | --- | --- | --- |\n";
        foreach ($set['admin'] as $row) {
            $cells = [];
            foreach (self::ACTIONS as $action) {
                $cells[] = $number($row['actions'][$action]['ms']) . ' / ' . $number($row['actions'][$action]['serverMs']) . ' ms';
            }
            $out .= sprintf("| %s | %s | %s | %d ms |\n", $name($row), $row['jit'], implode(' | ', $cells), $row['totalWallMs']);
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
                $out .= sprintf("| %s | %s | %s | %s | %s |\n", $name($row), $row['jit'], $side, implode(' | ', $cells), $peak);
            }
        }

        return $out;
    }
}
