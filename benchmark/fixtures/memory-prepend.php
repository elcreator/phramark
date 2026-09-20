<?php

// Always-on PHP-side memory probe, loaded through auto_prepend_file in every
// PHP-FPM stack (identical cost for all of them). At shutdown it appends one
// JSON line per request to /var/log/phramark/memory.log: peak memory, wall
// time, and the X-Phramark-Step header a workload may send to attribute the
// request to one of its steps. The benchmark scripts truncate the log before
// a run and summarise it afterwards.

if (PHP_SAPI === 'cli') {
    return;
}

register_shutdown_function(static function (): void {
    $line = json_encode([
        't' => round(microtime(true), 3),
        'step' => $_SERVER['HTTP_X_PHRAMARK_STEP'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'status' => http_response_code(),
        'ms' => isset($_SERVER['REQUEST_TIME_FLOAT']) ? round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2) : null,
        // peak: bytes the script used; peak_real: bytes the allocator took from the OS.
        'peak' => memory_get_peak_usage(false),
        'peak_real' => memory_get_peak_usage(true),
    ], JSON_UNESCAPED_SLASHES);
    @file_put_contents('/var/log/phramark/memory.log', $line . "\n", FILE_APPEND | LOCK_EX);
});
