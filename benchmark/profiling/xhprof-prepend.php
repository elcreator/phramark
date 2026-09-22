<?php

// Opt-in xhprof wrapper around the always-on memory probe. Only a request that
// carries an X-Phramark-Profile header is profiled; the benchmark never sends
// it, so installed-but-idle xhprof costs the timed runs nothing. The dump is a
// serialized xhprof array in /tmp/xhprof/<label>-<time>.xhprof, ranked by
// benchmark/profiling/xhprof-report.php.

require '/opt/phramark/benchmark/fixtures/memory-prepend.php';

if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_X_PHRAMARK_PROFILE']) || !function_exists('xhprof_enable')) {
    return;
}

xhprof_enable(XHPROF_FLAGS_NO_BUILTINS);

register_shutdown_function(static function (): void {
    $data = xhprof_disable();
    $label = preg_replace('/[^a-z0-9_-]+/i', '_', $_SERVER['HTTP_X_PHRAMARK_PROFILE']);
    @mkdir('/tmp/xhprof', 0777, true);
    file_put_contents(sprintf('/tmp/xhprof/%s-%d.xhprof', $label, (int) (microtime(true) * 1000)), serialize($data));
});
