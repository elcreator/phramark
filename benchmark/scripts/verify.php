<?php

declare(strict_types=1);

use Phramark\FixturePlan;
use Phramark\PageContract;
use Phramark\RuntimeProfile;

require dirname(__DIR__, 2) . '/src/FixturePlan.php';
require dirname(__DIR__, 2) . '/src/PageContract.php';
require dirname(__DIR__, 2) . '/src/RuntimeProfile.php';

// Fairness gate: every reachable stack must answer the canonical category URL
// with HTTP 200 (no redirect) and render the same article, author, reading
// time and hero-image counts the fixture defines.
//
// Usage: php verify.php [HOST] [CATEGORY...] [--port=N]
//   HOST defaults to 127.0.0.1; limit the stacks through PHRAMARK_PORTS.
//   --port overrides the published port, e.g. when HOST is an nginx service
//   name on the compose network (port 80).

$arguments = array_slice($argv, 1);
$portOverride = null;
foreach ($arguments as $index => $argument) {
    if (str_starts_with($argument, '--port=')) {
        $portOverride = (int) substr($argument, 7);
        unset($arguments[$index]);
    }
}
$arguments = array_values($arguments);
$host = $arguments[0] ?? '127.0.0.1';
$categories = array_map('intval', array_slice($arguments, 1)) ?: [42, 1, 100];
$ports = array_map('intval', array_filter(explode(',', (string) getenv('PHRAMARK_PORTS'))));
$failed = false;

foreach (RuntimeProfile::adapters() as $id => $adapter) {
    if ($ports !== [] && !in_array($adapter['port'], $ports, true)) {
        continue;
    }
    $base = sprintf('http://%s:%d', $host, $portOverride ?? $adapter['port']);
    $probe = @fopen($base . '/', 'r', false, stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]));
    if ($probe === false) {
        printf("%-12s skipped (%s not reachable)\n", $id, $base);
        continue;
    }
    fclose($probe);
    foreach ($categories as $category) {
        $url = $base . FixturePlan::categoryUrl($category);
        $context = stream_context_create(['http' => ['timeout' => 30, 'ignore_errors' => true, 'follow_location' => 0]]);
        $html = (string) @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }
        $violations = PageContract::violations($category, $html, $status);
        if ($violations === []) {
            printf("%-12s ok   %s (%d bytes)\n", $id, $url, strlen($html));
            continue;
        }
        $failed = true;
        printf("%-12s FAIL %s\n", $id, $url);
        foreach ($violations as $violation) {
            printf("             - %s\n", $violation);
        }
    }
}

exit($failed ? 1 : 0);
