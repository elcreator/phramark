<?php

declare(strict_types=1);

use Phramark\RuntimeProfile;

require dirname(__DIR__, 2) . '/src/RuntimeProfile.php';

// Records the exact versions the running stacks were built from, read from
// the containers themselves (Composer's installed.json, the CMS version
// files, WP-CLI, the PHP and MySQL binaries), so a result set names what it
// measured rather than what the setup scripts asked for. Written to
// benchmark/results/versions.json, which summary.php folds into the
// Markdown tables and docs/results.json.
//
// Usage: php benchmark/scripts/versions.php [OUTPUT]

const SOURCES = [
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
    $commit = inContainer($container, 'cd /var/www/html && git config --global --add safe.directory /var/www/html >/dev/null 2>&1; git rev-parse --short HEAD && git log -1 --format=%cs');
    $versions = ['Evolution CMS' => ($core['version'] ?? '?') . ($commit !== '' ? ' (3.5.x@' . str_replace("\n", ', ', $commit) . ')' : '')];
    $versions += composerVersions($container, 'core/vendor/composer/installed.json', $packages);
    if ($stack === 'evo-phalcon') {
        $versions['phalcon (extension)'] = inContainer($container, 'php -r "echo phpversion(\"phalcon\");"');
    }

    return components($versions);
};

$stacks = [];
foreach (array_keys(RuntimeProfile::adapters()) as $stack) {
    $container = container($stack);
    if (inContainer($container, 'echo up') !== 'up') {
        fwrite(STDERR, "$stack: container $container not running, skipped\n");
        continue;
    }
    $stacks[$stack] = match ($stack) {
        'evo-parser' => $evolution($stack, ['illuminate/database']),
        'evo-latte', 'evo-latte-parser' => $evolution($stack, ['elcreator/alattex', 'latte/latte', 'illuminate/database']),
        'evo-phalcon' => $evolution($stack, ['elcreator/alattex', 'elcreator/aphalcon', 'latte/latte', 'illuminate/database']),
        'drupal-11' => components(composerVersions($container, 'vendor/composer/installed.json', ['drupal/core', 'symfony/http-kernel', 'symfony/http-foundation', 'twig/twig', 'drush/drush'])),
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
    };
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

$output = $argv[1] ?? dirname(__DIR__) . '/results/versions.json';
file_put_contents($output, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo "Versions written to $output\n";
foreach ($stacks as $stack => $components) {
    printf("%-18s %s\n", $stack, implode(', ', array_map(static fn (array $c): string => $c['name'] . ' ' . $c['version'], $components)));
}
