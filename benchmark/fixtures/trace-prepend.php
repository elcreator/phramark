<?php

// Loaded through auto_prepend_file for a single diagnostic request. At
// shutdown it records what the request actually executed: every included
// file grouped by Composer vendor package (or CMS tree), and every userland
// class grouped by top-level namespace. That is the real request path of a
// stack, as opposed to the framework name on its label.

register_shutdown_function(static function (): void {
    $files = get_included_files();
    $packages = [];
    foreach ($files as $file) {
        if (preg_match('#/vendor/([^/]+/[^/]+)/#', $file, $match) === 1) {
            $key = 'vendor:' . $match[1];
        } elseif (preg_match('#^/var/www/html/(core|manager|web/core|web/modules|typo3|plugins|modules)/#', $file, $match) === 1) {
            $key = 'site:' . $match[1];
        } else {
            $key = 'site:other';
        }
        $packages[$key] = ($packages[$key] ?? 0) + 1;
    }
    arsort($packages);

    $namespaces = [];
    $internal = 0;
    foreach (get_declared_classes() as $class) {
        if ((new ReflectionClass($class))->isInternal()) {
            $internal++;
            continue;
        }
        $root = explode('\\', $class, 2)[0];
        $namespaces[$root] = ($namespaces[$root] ?? 0) + 1;
    }
    arsort($namespaces);

    $extensions = array_values(array_intersect(get_loaded_extensions(), ['phalcon', 'opcache', 'pdo_mysql', 'mysqli']));

    file_put_contents('/tmp/phramark-trace.json', json_encode([
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'status' => http_response_code(),
        'included_files' => count($files),
        'userland_classes' => array_sum($namespaces),
        'internal_classes' => $internal,
        'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        'extensions' => $extensions,
        'namespaces' => $namespaces,
        'packages' => $packages,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
});
