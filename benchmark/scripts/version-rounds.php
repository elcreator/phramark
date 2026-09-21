<?php

declare(strict_types=1);

use Phramark\VersionSpec;

require dirname(__DIR__, 2) . '/src/VersionSpec.php';

// The provisioning plan of a version comparison for benchmark/scripts/matrix
// (Phramark\VersionSpec::rounds): which setup environment to provision and
// which stacks to measure under which label in each round.
//
//   php version-rounds.php SPEC [STACK...]
//     SPEC    evo@3.5.x,evo@3.5.8,latte@0.4.0
//     STACK   the stacks to run (default: every stack a listed product is part of)
//
// Output, one word-separated line each, in order:
//   round EVO_VERSION=3.5.x ALATTEX_VERSION=0.4.0 DRUPAL_VERSION= …   (every product variable; empty = its default)
//   stack evo-parser evo@3.5.x
//   stack evo-latte-parser evo@3.5.x+latte@0.4.0
//   round …

try {
    $specs = VersionSpec::parse($argv[1] ?? '');
    if ($specs === []) {
        throw new InvalidArgumentException('Usage: version-rounds.php SPEC [STACK...]');
    }
    // A path ref must exist on the host before the setup containers look for it.
    foreach ($specs as $spec) {
        if (VersionSpec::isPath($spec['ref'])) {
            if (!VersionSpec::acceptsPath($spec['product'])) {
                throw new InvalidArgumentException(sprintf('%s cannot be installed from a directory (%s).', $spec['product'], $spec['ref']));
            }
            if (!is_dir(dirname(__DIR__, 2) . '/' . $spec['ref'])) {
                throw new InvalidArgumentException(sprintf('%s: "%s" is not a directory next to this repository (paths are relative to %s).', $spec['product'], $spec['ref'], dirname(__DIR__, 2)));
            }
        }
    }
    $stacks = array_slice($argv, 2) ?: VersionSpec::affectedStacks($specs);
    $variables = array_column(VersionSpec::products(), 'env');
    foreach (VersionSpec::rounds($specs, $stacks) as $round) {
        $assignments = [];
        foreach ($variables as $variable) {
            $assignments[] = $variable . '=' . ($round['env'][$variable] ?? '');
        }
        echo 'round ', implode(' ', $assignments), "\n";
        foreach ($round['stacks'] as $member) {
            echo 'stack ', $member['stack'], ' ', $member['label'], "\n";
        }
    }
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(2);
}
