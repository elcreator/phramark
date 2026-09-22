<?php

// Usage: php xhprof-report.php <file.xhprof> [--top=40] [--excl] [--callees=Function::name]
// Prints functions ranked by inclusive wall time (or exclusive with --excl);
// --callees lists what a given function spends its inclusive time on.

$opts = [];
$file = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
        $opts[$k] = $v;
    } else {
        $file = $arg;
    }
}
$top = (int) ($opts['top'] ?? 40);
$data = unserialize(file_get_contents($file));

$incl = [];
$excl = [];
$calls = [];
$children = [];
foreach ($data as $edge => $m) {
    [$parent, $child] = str_contains($edge, '==>') ? explode('==>', $edge, 2) : [null, $edge];
    $incl[$child] = ($incl[$child] ?? 0) + $m['wt'];
    $excl[$child] = ($excl[$child] ?? 0) + $m['wt'];
    $calls[$child] = ($calls[$child] ?? 0) + $m['ct'];
    if ($parent !== null) {
        $excl[$parent] = ($excl[$parent] ?? 0) - $m['wt'];
        $children[$parent][$child] = ($children[$parent][$child] ?? 0) + $m['wt'];
    }
}
$total = $incl['main()'] ?? max($incl);

if (isset($opts['callees'])) {
    $fn = $opts['callees'];
    printf("%s inclusive %.1f ms, exclusive %.1f ms\n", $fn, $incl[$fn] / 1000, $excl[$fn] / 1000);
    arsort($children[$fn]);
    foreach (array_slice($children[$fn], 0, $top, true) as $c => $wt) {
        printf("  %7.1f ms %5.1f%% %6d  %s\n", $wt / 1000, $wt / $total * 100, $data["$fn==>$c"]['ct'], $c);
    }
    exit;
}

$rank = isset($opts['excl']) ? $excl : $incl;
arsort($rank);
printf("total %.1f ms  (%s)\n", $total / 1000, isset($opts['excl']) ? 'exclusive' : 'inclusive');
$n = 0;
foreach ($rank as $fn => $wt) {
    if (isset($opts['grep']) && !preg_match('~' . $opts['grep'] . '~i', $fn)) continue;
    printf("%7.1f ms %5.1f%%  %6d  %s\n", $wt / 1000, $wt / $total * 100, $calls[$fn], $fn);
    if (++$n >= $top) break;
}
