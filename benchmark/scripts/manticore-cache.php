<?php

declare(strict_types=1);

/**
 * Result cache of the Manticore per-file sweep, kept in git so a repeat run
 * (same file bytes, same compiler, same clang) never recompiles.
 *
 * benchmark/results/manticore/compile-cache.tsv holds one line per
 * (content hash, toolchain) pair: sha256 \t toolchain \t status \t diagnostic.
 * The toolchain is "manticore 0.10.0 | clang 19.1.7": a compiler or clang
 * upgrade misses every entry, an unchanged file with the same toolchain
 * hits regardless of its path, mtime or which tree it came from.
 *
 *   php manticore-cache.php plan <cache.tsv> <toolchain> <tree> <hits.tsv> <misses.txt>
 *       hits.tsv: report rows (status, path, 0.00, diagnostic, cached) for
 *       every file whose hash is cached; misses.txt: the paths to compile.
 *   php manticore-cache.php merge <cache.tsv> <toolchain> <report.tsv>
 *       adds the report's compiled rows (not the cached ones) to the cache,
 *       replacing any entry with the same key, and rewrites it sorted.
 */

/** @return array<string, array{status: string, diagnostic: string}> keyed by "sha256\ttoolchain" */
function loadCache(string $path): array
{
    $entries = [];
    if (!is_file($path)) {
        return $entries;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        [$hash, $toolchain, $status, $diagnostic] = array_pad(explode("\t", $line, 4), 4, '');
        if ($hash !== '' && $toolchain !== '') {
            $entries[$hash . "\t" . $toolchain] = ['status' => $status, 'diagnostic' => $diagnostic];
        }
    }

    return $entries;
}

/** @param array<string, array{status: string, diagnostic: string}> $entries */
function saveCache(string $path, array $entries): void
{
    ksort($entries, SORT_STRING);
    $lines = [];
    foreach ($entries as $key => $entry) {
        $lines[] = $key . "\t" . $entry['status'] . "\t" . $entry['diagnostic'];
    }
    file_put_contents($path, $lines === [] ? '' : implode("\n", $lines) . "\n");
}

/** @return list<string> every .php file under $tree, sorted */
function phpFiles(string $tree): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tree, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            $files[] = $file->getPathname();
        }
    }
    sort($files, SORT_STRING);

    return $files;
}

/**
 * Splits a tree into cached rows and files to compile.
 *
 * @param array<string, array{status: string, diagnostic: string}> $entries
 * @param list<string> $files
 * @return array{hits: list<string>, misses: list<string>}
 */
function plan(array $entries, string $toolchain, array $files): array
{
    $hits = [];
    $misses = [];
    foreach ($files as $file) {
        $key = hash_file('sha256', $file) . "\t" . $toolchain;
        if (isset($entries[$key])) {
            $hits[] = implode("\t", [$entries[$key]['status'], $file, '0.00', $entries[$key]['diagnostic'], 'cached']);
        } else {
            $misses[] = $file;
        }
    }

    return ['hits' => $hits, 'misses' => $misses];
}

/**
 * Folds a sweep report into the cache: rows the sweep compiled replace the
 * entry of their content hash; rows it took from the cache are left alone.
 *
 * @param array<string, array{status: string, diagnostic: string}> $entries
 * @param list<string> $reportLines
 * @return array<string, array{status: string, diagnostic: string}>
 */
function merge(array $entries, string $toolchain, array $reportLines): array
{
    foreach ($reportLines as $line) {
        [$status, $file, , $diagnostic, $origin] = array_pad(explode("\t", rtrim($line, "\r\n"), 5), 5, '');
        if ($origin === 'cached' || $file === '' || !is_file($file) || !in_array($status, ['ok', 'fail', 'timeout'], true)) {
            continue;
        }
        $entries[hash_file('sha256', $file) . "\t" . $toolchain] = ['status' => $status, 'diagnostic' => $diagnostic];
    }

    return $entries;
}

if (PHP_SAPI === 'cli' && realpath($argv[0]) === __FILE__) {
    $command = $argv[1] ?? '';
    if ($command === 'plan' && count($argv) === 7) {
        [, , $cache, $toolchain, $tree, $hitsPath, $missesPath] = $argv;
        $result = plan(loadCache($cache), $toolchain, phpFiles($tree));
        file_put_contents($hitsPath, $result['hits'] === [] ? '' : implode("\n", $result['hits']) . "\n");
        file_put_contents($missesPath, $result['misses'] === [] ? '' : implode("\n", $result['misses']) . "\n");
        printf("cache: %d hits, %d to compile\n", count($result['hits']), count($result['misses']));
        exit(0);
    }
    if ($command === 'merge' && count($argv) === 5) {
        [, , $cache, $toolchain, $report] = $argv;
        $before = loadCache($cache);
        $after = merge($before, $toolchain, file($report, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        saveCache($cache, $after);
        printf("cache: %d entries (%+d)\n", count($after), count($after) - count($before));
        exit(0);
    }
    fwrite(STDERR, "usage: manticore-cache.php plan <cache> <toolchain> <tree> <hits> <misses> | merge <cache> <toolchain> <report>\n");
    exit(2);
}
