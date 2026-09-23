<?php

declare(strict_types=1);

use Phramark\RuntimeProfile;

require dirname(__DIR__, 2) . '/src/RuntimeProfile.php';

// Records the exact versions the running stacks were built from, read from
// the containers themselves (Composer's installed.json, the CMS version
// files, WP-CLI, the PHP and MySQL binaries), so a result set names what it
// measured rather than what the setup scripts asked for. Written to
// benchmark/results/versions.json, which summary.php folds into the
// Markdown tables and docs/results.json; with --stack, the components of
// one stack, which run/admin attach to every result file they write (a
// version comparison keeps several builds of one stack side by side, so
// the result itself must say what it measured).
//
// Usage: php benchmark/scripts/versions.php [OUTPUT]
//        php benchmark/scripts/versions.php --stack=ID [--attach=RESULT] [--label=VERSION]
//   --attach appends a "---- versions ----" section (the version label of
//   the run, then one JSON line) to a guest .txt result or sets "components"
//   in an admin .json report (which carries its own label).

const SOURCES = [
    'PHP' => 'https://github.com/php/php-src',
    'Evolution CMS' => 'https://github.com/evolution-cms/evolution',
    'elcreator/alattex' => 'https://github.com/elcreator/aLatteX',
    'elcreator/aphalcon' => 'https://github.com/elcreator/aPhalcon',
    'latte/latte' => 'https://github.com/nette/latte',
    'illuminate/database' => 'https://github.com/illuminate/database',
    'laravel/framework' => 'https://github.com/laravel/framework',
    'phalcon (extension)' => 'https://github.com/phalcon/cphalcon',
    'drupal/core' => 'https://github.com/drupal/core',
    'drush/drush' => 'https://github.com/drush-ops/drush',
    'symfony/http-kernel' => 'https://github.com/symfony/http-kernel',
    'symfony/http-foundation' => 'https://github.com/symfony/http-foundation',
    'twig/twig' => 'https://github.com/twigphp/Twig',
    'typo3/cms-core' => 'https://github.com/TYPO3/typo3',
    'doctrine/dbal' => 'https://github.com/doctrine/dbal',
    'typo3fluid/fluid' => 'https://github.com/TYPO3/Fluid',
    'winter/storm' => 'https://github.com/wintercms/storm',
    'winter/wn-cms-module' => 'https://github.com/wintercms/wn-cms-module',
    'MODX Revolution' => 'https://github.com/modxcms/revolution',
    'xpdo/xpdo' => 'https://github.com/modxcms/xpdo',
    'WordPress' => 'https://github.com/WordPress/WordPress',
    'gantry5' => 'https://github.com/gantry/gantry5',
    'g5_hydrogen' => 'https://github.com/gantry/gantry5',
    'classic-editor' => 'https://github.com/WordPress/classic-editor',
    'timber/timber' => 'https://github.com/timber/timber',
];

function container(string $stack): string
{
    return 'phramark-php-' . match ($stack) {
        'evo-parser' => 'parser',
        'evo-latte' => 'latte',
        'evo-latte-parser' => 'latte-parser',
        'evo-phalcon' => 'phalcon',
        'evo-sarticles' => 'sarticles',
        default => $stack,
    } . '-1';
}

// proc_open with an argument list bypasses the host shell (cmd.exe on a
// Windows host would mangle the quotes of the embedded PHP snippets).
function capture(array $argv): string
{
    $process = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return '';
    }
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return trim($output);
}

function inContainer(string $container, string $command): string
{
    return capture(['docker', 'exec', $container, 'sh', '-c', $command]);
}

/** @return array<string, string> package => version */
function composerVersions(string $container, string $installedJson, array $names): array
{
    $script = sprintf(
        '$j=json_decode(file_get_contents(%s),true);$p=$j["packages"]??$j;$w=%s;foreach($p as $x){if(in_array($x["name"],$w,true))echo $x["name"],"=",$x["version"],"\n";}',
        var_export($installedJson, true),
        var_export($names, true)
    );
    $versions = [];
    foreach (explode("\n", capture(['docker', 'exec', '-w', '/var/www/html', $container, 'php', '-r', $script])) as $line) {
        if (str_contains($line, '=')) {
            [$name, $version] = explode('=', $line, 2);
            $versions[$name] = $version;
        }
    }

    return $versions;
}

/** @return list<array{name: string, version: string, source?: string}> */
function components(array $versions): array
{
    $components = [];
    foreach ($versions as $name => $version) {
        $component = ['name' => $name, 'version' => $version];
        if (isset(SOURCES[$name])) {
            $component['source'] = SOURCES[$name];
        }
        $components[] = $component;
    }

    return $components;
}

$evolution = static function (string $stack, array $packages): array {
    $container = container($stack);
    $core = json_decode(inContainer($container, 'cat /var/www/html/core/composer.json'), true);
    // The ref the site was built from (setup.php's marker: "evo=tag 3.5.7" or
    // "evo=branch 3.5.x <commit>") and the commit that is actually checked out.
    $marker = inContainer($container, 'sed -n "/^evo=/p" /var/www/html/.phramark-versions');
    $ref = preg_replace('/^evo=(\S+) (\S+).*$/', '$1 $2', $marker);
    // A site from a host directory carries no .git (setup.php copies the
    // project's files only): its commit is read from that checkout here on
    // the host, with the fingerprint of the files that were actually copied.
    if (preg_match('/^evo=path \/host\/(\S+) (\S+)/', $marker, $m) === 1) {
        $checkout = dirname(dirname(__DIR__), 2) . '/' . $m[1];
        $commit = capture(['git', '-C', $checkout, 'rev-parse', '--short', 'HEAD']);
        $commit = $commit === '' ? 'files ' . $m[2] : $commit . ' ' . capture(['git', '-C', $checkout, 'log', '-1', '--format=%cs']) . ', files ' . $m[2];
    } else {
        $commit = inContainer($container, 'cd /var/www/html && git config --global --add safe.directory /var/www/html >/dev/null 2>&1; git rev-parse --short HEAD && git log -1 --format=%cs');
    }
    $versions = ['Evolution CMS' => ($core['version'] ?? '?') . ($commit !== '' ? ' (' . ($ref !== '' ? $ref . '@' : '') . str_replace("\n", ', ', $commit) . ')' : '')];
    $versions += composerVersions($container, 'core/vendor/composer/installed.json', $packages);
    // An extension installed from a directory on the host ("dev-local") is
    // named by that directory and the fingerprint of its files.
    foreach (explode("
", inContainer($container, 'sed -n "s/^\(latte\|phalcon\)=path /\1 /p" /var/www/html/.phramark-versions')) as $line) {
        if (preg_match('/^(latte|phalcon) (\S+) (\S+)/', $line, $m) === 1) {
            $package = $m[1] === 'latte' ? 'elcreator/alattex' : 'elcreator/aphalcon';
            if (isset($versions[$package])) {
                $versions[$package] .= ' (' . preg_replace('#^/host/#', '', $m[2]) . '@' . $m[3] . ')';
            }
        }
    }
    if ($stack === 'evo-phalcon') {
        $versions['phalcon (extension)'] = inContainer($container, 'php -r "echo phpversion(\"phalcon\");"');
    }
    // A site set up with EVO_NO_SESSION=1 (setup.php writes the define) runs
    // the front end without a visitor session; a result must say so.
    if (inContainer($container, 'grep -qs "define(.NO_SESSION., true)" /var/www/html/core/custom/define.php && echo on') === 'on') {
        $versions['front-end session'] = 'off (NO_SESSION)';
    }

    return components($versions);
};

/** @return list<array{name: string, version: string, source?: string}>|null null when the stack's container is not running */
function stackComponents(string $stack): ?array
{
    global $evolution;
    $container = container($stack);
    if (inContainer($container, 'echo up') !== 'up') {
        return null;
    }
    $php = components(['PHP' => inContainer($container, 'php -r "echo PHP_VERSION;"')]);

    return array_merge($php, match ($stack) {
        'evo-parser' => $evolution($stack, ['illuminate/database']),
        'evo-latte', 'evo-latte-parser' => $evolution($stack, ['elcreator/alattex', 'latte/latte', 'illuminate/database']),
        'evo-phalcon' => $evolution($stack, ['elcreator/alattex', 'elcreator/aphalcon', 'latte/latte', 'illuminate/database']),
        'evo-sarticles' => $evolution($stack, ['seiger/sarticles', 'evolution-cms/evo-ui', 'livewire/livewire', 'evolution-cms-extras/tinymce5', 'illuminate/database']),
        'drupal' => components(composerVersions($container, 'vendor/composer/installed.json', ['drupal/core', 'symfony/http-kernel', 'symfony/http-foundation', 'twig/twig', 'drush/drush'])),
        'typo3' => components(composerVersions($container, 'vendor/composer/installed.json', ['typo3/cms-core', 'doctrine/dbal', 'typo3fluid/fluid', 'symfony/http-foundation'])),
        'winter' => components(composerVersions($container, 'vendor/composer/installed.json', ['winter/storm', 'winter/wn-cms-module', 'laravel/framework', 'twig/twig', 'doctrine/dbal', 'symfony/http-foundation'])),
        'modx' => components(
            ['MODX Revolution' => inContainer($container, 'cd /var/www/html && php -r \'include "core/docs/version.inc.php"; echo $v["full_version"];\'')]
            + composerVersions($container, 'core/vendor/composer/installed.json', ['xpdo/xpdo'])
        ),
        'wordpress-gantry' => (static function () use ($container): array {
            $versions = ['WordPress' => inContainer($container, 'cd /var/www/html && wp core version --allow-root')];
            foreach (['plugin', 'theme'] as $kind) {
                foreach (explode("\n", inContainer($container, "cd /var/www/html && wp $kind list --allow-root --format=csv --fields=name,version,status")) as $line) {
                    $parts = str_getcsv($line);
                    if (count($parts) === 3 && $parts[1] !== '' && $parts[1] !== 'version' && in_array($parts[2], ['active', 'must-use'], true)) {
                        $versions[$parts[0]] = $parts[1];
                    }
                }
            }
            $versions += composerVersions($container, 'wp-content/plugins/gantry5/vendor/composer/installed.json', ['timber/timber', 'twig/twig']);

            return components($versions);
        })(),
    });
}

$options = getopt('', ['stack:', 'attach:', 'label:']);
if (isset($options['stack'])) {
    $components = stackComponents((string) $options['stack']);
    if ($components === null) {
        fwrite(STDERR, $options['stack'] . ': container ' . container((string) $options['stack']) . " not running\n");
        exit(1);
    }
    $json = json_encode($components, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (isset($options['attach'])) {
        $file = (string) $options['attach'];
        if (str_ends_with($file, '.json')) {
            $report = json_decode((string) file_get_contents($file), true);
            $report['components'] = $components;
            file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        } else {
            file_put_contents($file, "\n---- versions ----\nlabel: " . ($options['label'] ?? '') . "\n" . $json . "\n", FILE_APPEND);
        }
        echo 'Versions attached to ' . $file . "\n";
    } else {
        echo $json . "\n";
    }
    exit(0);
}

$stacks = [];
foreach (array_keys(RuntimeProfile::adapters()) as $stack) {
    $components = stackComponents($stack);
    if ($components === null) {
        fwrite(STDERR, "$stack: container " . container($stack) . " not running, skipped\n");
        continue;
    }
    $stacks[$stack] = $components;
}

$anyContainer = container(array_key_first($stacks) ?? 'evo-parser');
$record = [
    'recordedAt' => gmdate('Y-m-d\TH:i:s\Z'),
    'runtime' => [
        'php' => inContainer($anyContainer, 'php -r "echo PHP_VERSION;"'),
        'mysql' => preg_replace('/^.*Ver (\S+).*$/s', '$1', capture(['docker', 'exec', 'phramark-mysql-1', 'mysql', '--version'])),
        'nginx' => preg_replace('/^nginx version: /', '', capture(['docker', 'exec', 'phramark-nginx-parser-1', 'sh', '-c', 'nginx -v 2>&1'])),
        'opcache' => 'validate_timestamps=0, memory_consumption=512, max_accelerated_files=100000',
    ],
    'stacks' => $stacks,
];

$output = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => !str_starts_with($a, '--')))[0] ?? dirname(__DIR__) . '/results/versions.json';
file_put_contents($output, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo "Versions written to $output\n";
foreach ($stacks as $stack => $components) {
    printf("%-18s %s\n", $stack, implode(', ', array_map(static fn (array $c): string => $c['name'] . ' ' . $c['version'], $components)));
}
