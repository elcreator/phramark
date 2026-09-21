<?php

declare(strict_types=1);

namespace Phramark;

final class RuntimeProfile
{
    /**
     * Framework labels are what a warm category request actually executes,
     * recorded by benchmark/scripts/trace-request (see classify()). The
     * "components" list is asserted against that trace, so a label cannot
     * drift away from the real request path. No version numbers here: the
     * versions a stack was built from are chosen per run (VersionSpec) and
     * recorded from the container into every result (versions.php).
     *
     * @return array<string, array{label: string, framework: string, components: list<string>, port: int}>
     */
    public static function adapters(): array
    {
        return [
            'evo-parser' => [
                'label' => 'Evolution parser',
                'framework' => 'Evolution CMS core on Laravel (Illuminate) components (+ Symfony http-foundation/finder)',
                'components' => ['Evolution core', 'Laravel/Illuminate', 'Symfony (minor)'],
                'port' => 8080,
            ],
            'evo-latte' => [
                'label' => 'Evolution + Latte',
                'framework' => 'Evolution CMS core on Laravel (Illuminate) components + Latte (+ Symfony http-foundation/finder)',
                'components' => ['Evolution core', 'Laravel/Illuminate', 'Symfony (minor)', 'Latte'],
                'port' => 8081,
            ],
            'evo-latte-parser' => [
                'label' => 'Evolution + Latte + EVO pass',
                'framework' => 'Evolution CMS core on Laravel (Illuminate) components + Latte, with the core tag pass over the view output (+ Symfony http-foundation/finder)',
                'components' => ['Evolution core', 'Laravel/Illuminate', 'Symfony (minor)', 'Latte'],
                'port' => 8085,
            ],
            'evo-phalcon' => [
                'label' => 'Evolution + aPhalcon',
                'framework' => 'Evolution CMS core on Laravel (Illuminate) components + Phalcon DB adapter + Latte (+ Symfony http-foundation/finder)',
                'components' => ['Evolution core', 'Laravel/Illuminate', 'Symfony (minor)', 'Phalcon (extension)', 'Latte'],
                'port' => 8082,
            ],
            'drupal' => [
                'label' => 'Drupal',
                'framework' => 'Drupal core on Symfony HttpKernel/Routing + Twig',
                'components' => ['Drupal core', 'Symfony', 'Twig'],
                'port' => 8083,
            ],
            'typo3' => [
                'label' => 'TYPO3',
                'framework' => 'TYPO3 core on Doctrine DBAL + Fluid (Symfony DI/translation only)',
                'components' => ['TYPO3 core', 'Doctrine DBAL', 'Fluid', 'Symfony (minor)'],
                'port' => 8084,
            ],
            'winter' => [
                'label' => 'Winter CMS',
                'framework' => 'Winter CMS (Storm + CMS module) on Laravel (Illuminate) + Twig (+ Symfony http-foundation/translation; PDO wrapped in the Doctrine DBAL PDOConnection)',
                'components' => ['Winter core', 'Laravel/Illuminate', 'Symfony (minor)', 'Doctrine DBAL (minor)', 'Twig'],
                'port' => 8086,
            ],
            'modx' => [
                'label' => 'MODX Revolution',
                'framework' => 'MODX Revolution core (modRequest/modParser) on xPDO, its own ORM over PDO; no third-party framework on the request path',
                'components' => ['MODX core', 'xPDO'],
                'port' => 8087,
            ],
            'wordpress-gantry' => [
                'label' => 'WordPress + Gantry 5',
                'framework' => 'WordPress core (wpdb, WP_Rewrite, WP_Query) with the Gantry 5 framework plugin rendering the Hydrogen theme through Timber and Twig 2 (+ Symfony yaml/event-dispatcher for the outline); no other framework on the request path',
                'components' => ['WordPress core', 'Gantry 5', 'Timber', 'Symfony (minor)', 'Twig'],
                'port' => 8088,
            ],
        ];
    }

    /**
     * Derives the components a request really used from a trace-prepend.php
     * record: userland class counts per root namespace, included files per
     * Composer package, and loaded extensions.
     *
     * @param array{namespaces?: array<string, int>, packages?: array<string, int>, extensions?: list<string>} $trace
     * @return list<string>
     */
    public static function classify(array $trace): array
    {
        $namespaces = $trace['namespaces'] ?? [];
        $packages = $trace['packages'] ?? [];
        $extensions = $trace['extensions'] ?? [];
        $classes = static fn (string $root): int => (int) ($namespaces[$root] ?? 0);
        $files = static function (string $prefix) use ($packages): int {
            $total = 0;
            foreach ($packages as $package => $count) {
                if (str_starts_with($package, $prefix)) {
                    $total += $count;
                }
            }

            return $total;
        };

        $components = [];
        if ($classes('EvolutionCMS') >= 20) {
            $components[] = 'Evolution core';
        }
        if ($classes('Drupal') >= 50) {
            $components[] = 'Drupal core';
        }
        if ($classes('TYPO3') >= 50) {
            $components[] = 'TYPO3 core';
        }
        // Winter's core is the Storm library (Winter\Storm) plus the Cms, System
        // and Backend modules; the full front controller loads dozens of each.
        if ($classes('Winter') + $classes('Cms') + $classes('System') >= 40) {
            $components[] = 'Winter core';
        }
        // MODX 3 is MODX\Revolution\* on the xPDO\* ORM; both are its own code,
        // there is no Laravel/Symfony layer in a frontend request.
        if ($classes('MODX') >= 20) {
            $components[] = 'MODX core';
        }
        if ($classes('xPDO') >= 5) {
            $components[] = 'xPDO';
        }
        // WordPress core is procedural plus global classes (WP_Query, wpdb,
        // WP_Rewrite ...), so it is recognised by its included core files.
        if ($files('site:wp-includes') >= 50) {
            $components[] = 'WordPress core';
        }
        // Gantry 5 is a theme framework: its own Gantry\* classes (outline,
        // layout, particles, streams) render the page; Timber is the bridge
        // between WordPress and Twig it ships with.
        if ($classes('Gantry') >= 20) {
            $components[] = 'Gantry 5';
        }
        if ($classes('Timber') >= 3) {
            $components[] = 'Timber';
        }
        if ($classes('Illuminate') >= 20 || $files('vendor:illuminate/') >= 50) {
            $components[] = 'Laravel/Illuminate';
        }
        if ($classes('Symfony') >= 40) {
            $components[] = 'Symfony';
        } elseif ($classes('Symfony') > 0) {
            $components[] = 'Symfony (minor)';
        }
        // TYPO3 queries through the DBAL query builder (80+ files); Laravel 9
        // merely wraps PDO in Doctrine's PDOConnection when DBAL is installed.
        if ($files('vendor:doctrine/dbal') >= 20) {
            $components[] = 'Doctrine DBAL';
        } elseif ($files('vendor:doctrine/dbal') > 0) {
            $components[] = 'Doctrine DBAL (minor)';
        }
        if (in_array('phalcon', $extensions, true) && $files('vendor:elcreator/aphalcon') > 0) {
            $components[] = 'Phalcon (extension)';
        }
        if ($classes('Latte') > 0) {
            $components[] = 'Latte';
        }
        if ($classes('Twig') > 0) {
            $components[] = 'Twig';
        }
        if ($classes('TYPO3Fluid') > 0) {
            $components[] = 'Fluid';
        }

        return $components;
    }

    /**
     * Components a stack claims that its trace does not show, and vice versa.
     *
     * @param array{namespaces?: array<string, int>, packages?: array<string, int>, extensions?: list<string>} $trace
     * @return array{missing: list<string>, unexpected: list<string>}
     */
    public static function traceMismatch(string $adapter, array $trace): array
    {
        $expected = self::adapters()[$adapter]['components'] ?? [];
        $actual = self::classify($trace);

        return [
            'missing' => array_values(array_diff($expected, $actual)),
            'unexpected' => array_values(array_diff($actual, $expected)),
        ];
    }

    /** @return array<string, array{opcache_jit: string, jit_buffer_size: string}> */
    public static function jitModes(): array
    {
        return [
            'off' => ['opcache_jit' => '0', 'jit_buffer_size' => '0'],
            'tracing' => ['opcache_jit' => 'tracing', 'jit_buffer_size' => '128M'],
        ];
    }

    /** @return array{opcache_jit: string, jit_buffer_size: string} */
    public static function jitMode(string $mode): array
    {
        $modes = self::jitModes();
        if (!isset($modes[$mode])) {
            throw new \InvalidArgumentException(sprintf('Unsupported PHP_JIT mode "%s".', $mode));
        }

        return $modes[$mode];
    }

    /**
     * Result file name for one run of the guest/admin × JIT matrix.
     */
    public static function resultName(string $workload, string $adapter, string $jit, string $suffix): string
    {
        if (!in_array($workload, ['guest', 'admin'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported workload "%s".', $workload));
        }
        self::jitMode($jit);
        if (!isset(self::adapters()[$adapter])) {
            throw new \InvalidArgumentException(sprintf('Unknown adapter "%s".', $adapter));
        }

        return sprintf('%s-%s-jit-%s-%s', $workload, $adapter, $jit, $suffix);
    }
}
