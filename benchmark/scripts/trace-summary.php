<?php

declare(strict_types=1);

use Phramark\RuntimeProfile;

require dirname(__DIR__, 2) . '/src/RuntimeProfile.php';

// Prints what a traced request executed and whether it matches the framework
// components RuntimeProfile claims for the stack. Exit 1 on a mismatch.
//
// Usage: php trace-summary.php benchmark/results/trace-evo-parser.json evo-parser

[$file, $adapter] = [$argv[1] ?? '', $argv[2] ?? ''];
$trace = json_decode((string) file_get_contents($file), true);
if (!is_array($trace)) {
    fwrite(STDERR, "Unreadable trace: {$file}\n");
    exit(2);
}

printf("%s %s -> HTTP %d, %d files, %d userland classes, %.1f MiB peak\n", $adapter, $trace['uri'], $trace['status'], $trace['included_files'], $trace['userland_classes'], $trace['peak_memory_mb']);
foreach (array_slice($trace['namespaces'], 0, 8, true) as $namespace => $count) {
    printf("  %-32s %4d classes\n", $namespace, $count);
}
foreach (array_slice($trace['packages'], 0, 8, true) as $package => $count) {
    printf("  %-40s %4d files\n", $package, $count);
}
printf("  request path: %s\n", implode(' + ', RuntimeProfile::classify($trace)));
$claim = RuntimeProfile::adapters()[$adapter] ?? null;
if ($claim === null) {
    exit(0);
}
printf("  label:        %s\n", $claim['framework']);
$mismatch = RuntimeProfile::traceMismatch($adapter, $trace);
if ($mismatch['missing'] === [] && $mismatch['unexpected'] === []) {
    echo "  label matches the traced request path.\n";
    exit(0);
}
if ($mismatch['missing'] !== []) {
    printf("  MISMATCH: label claims %s but the trace does not show it.\n", implode(', ', $mismatch['missing']));
}
if ($mismatch['unexpected'] !== []) {
    printf("  MISMATCH: trace shows %s that the label omits.\n", implode(', ', $mismatch['unexpected']));
}
exit(1);
