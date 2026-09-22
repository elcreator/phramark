<?php

declare(strict_types=1);

namespace Phramark;

/**
 * Versions of the parts a stack is built from: `--versions=evo@3.5.x,evo@3.5.8,latte@0.4.0`
 * names a product and a ref per entry. A ref is a published tag when one of
 * that name exists, otherwise a branch of that name; `latest` is the newest
 * stable tag (the default of every product when no version is given). A ref
 * that starts with `..` is a directory on the host, relative to the
 * repository root (`latte@../evo/aLatteX`: the working copy next to this
 * checkout), installed as it is; the setup reinstalls when its files change.
 *
 * Not every part of a stack can be pinned: only the products listed in
 * products() are installed by the setup scripts from a chosen ref (the CMS,
 * the extensions the harness adds, the PHP image); everything else (Illuminate,
 * Symfony, Twig …) comes with whatever those pull in and is recorded from
 * the container into the result instead.
 *
 * The plan is a cartesian product per stack: every stack runs once per
 * combination of the refs given for the products it contains, so
 * `evo@3.5.x,evo@3.5.8,latte@0.4.0` pairs aLatteX 0.4.0 with each of the two
 * Evolution refs on the Latte stacks and runs evo-parser at each Evolution
 * ref alone. Combinations that do not conflict share one provisioning round.
 */
final class VersionSpec
{
    public const LATEST = 'latest';

    private const EVO_STACKS = ['evo-parser', 'evo-latte', 'evo-latte-parser', 'evo-phalcon'];
    private const ALL_STACKS = ['evo-parser', 'evo-latte', 'evo-latte-parser', 'evo-phalcon', 'drupal', 'typo3', 'winter', 'modx', 'wordpress-gantry'];

    /**
     * kind: how the setup scripts install a ref.
     *   git       clone of a tag or branch (evo)
     *   composer  a Composer version (tag) or dev branch (`1.2.x-dev`, `dev-main`)
     *   image     the PHP image tag the FPM and setup images are built from
     *   release   a release archive per tag; a branch needs the project's own build (modx: transport build; gantry: not installable)
     *   wordpress wp core download per tag, git clone per branch
     *   wp-plugin wordpress.org release per tag, git clone per branch
     *
     * @return array<string, array{label: string, env: string, kind: string, stacks: list<string>, source: string, package?: string, repo?: string, aliases?: list<string>, default?: string}>
     */
    public static function products(): array
    {
        return [
            'php' => ['label' => 'PHP', 'env' => 'PHP_VERSION', 'kind' => 'image', 'stacks' => self::ALL_STACKS, 'source' => 'https://github.com/php/php-src', 'default' => '8.4'],
            'evo' => ['label' => 'Evolution CMS', 'env' => 'EVO_VERSION', 'kind' => 'git', 'stacks' => self::EVO_STACKS, 'source' => 'https://github.com/evolution-cms/evolution', 'repo' => 'https://github.com/evolution-cms/evolution.git', 'aliases' => ['evolution']],
            'latte' => ['label' => 'aLatteX', 'env' => 'ALATTEX_VERSION', 'kind' => 'composer', 'stacks' => ['evo-latte', 'evo-latte-parser', 'evo-phalcon'], 'source' => 'https://github.com/elcreator/aLatteX', 'package' => 'elcreator/alattex', 'aliases' => ['alattex']],
            'phalcon' => ['label' => 'aPhalcon', 'env' => 'APHALCON_VERSION', 'kind' => 'composer', 'stacks' => ['evo-phalcon'], 'source' => 'https://github.com/elcreator/aPhalcon', 'package' => 'elcreator/aphalcon', 'aliases' => ['aphalcon'], 'default' => '../evo/aPhalcon'],
            'drupal' => ['label' => 'Drupal', 'env' => 'DRUPAL_VERSION', 'kind' => 'composer', 'stacks' => ['drupal'], 'source' => 'https://github.com/drupal/core', 'package' => 'drupal/recommended-project'],
            'typo3' => ['label' => 'TYPO3', 'env' => 'TYPO3_VERSION', 'kind' => 'composer', 'stacks' => ['typo3'], 'source' => 'https://github.com/TYPO3/typo3', 'package' => 'typo3/cms-base-distribution'],
            'winter' => ['label' => 'Winter CMS', 'env' => 'WINTER_VERSION', 'kind' => 'composer', 'stacks' => ['winter'], 'source' => 'https://github.com/wintercms/winter', 'package' => 'wintercms/winter'],
            'modx' => ['label' => 'MODX Revolution', 'env' => 'MODX_VERSION', 'kind' => 'release', 'stacks' => ['modx'], 'source' => 'https://github.com/modxcms/revolution', 'repo' => 'https://github.com/modxcms/revolution.git'],
            'wordpress' => ['label' => 'WordPress', 'env' => 'WORDPRESS_VERSION', 'kind' => 'wordpress', 'stacks' => ['wordpress-gantry'], 'source' => 'https://github.com/WordPress/WordPress', 'repo' => 'https://github.com/WordPress/WordPress.git', 'aliases' => ['wp']],
            'gantry' => ['label' => 'Gantry 5', 'env' => 'GANTRY_VERSION', 'kind' => 'release', 'stacks' => ['wordpress-gantry'], 'source' => 'https://github.com/gantry/gantry5', 'repo' => 'https://github.com/gantry/gantry5.git', 'aliases' => ['gantry5']],
            'classic-editor' => ['label' => 'Classic Editor', 'env' => 'CLASSIC_EDITOR_VERSION', 'kind' => 'wp-plugin', 'stacks' => ['wordpress-gantry'], 'source' => 'https://github.com/WordPress/classic-editor', 'repo' => 'https://github.com/WordPress/classic-editor.git'],
        ];
    }

    /** The canonical product id of a name or alias, or null. */
    public static function product(string $name): ?string
    {
        $name = strtolower($name);
        foreach (self::products() as $id => $product) {
            if ($id === $name || in_array($name, $product['aliases'] ?? [], true)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * `evo@3.5.x,evo@3.5.8,latte@0.4.0` → [[product, ref], …]; duplicates are dropped.
     *
     * @return list<array{product: string, ref: string}>
     */
    public static function parse(string $list): array
    {
        $specs = [];
        foreach (array_filter(array_map('trim', explode(',', $list)), 'strlen') as $entry) {
            if (preg_match('/^([A-Za-z0-9-]+)@([A-Za-z0-9._\/+~-]+)$/', $entry, $m) !== 1) {
                throw new \InvalidArgumentException(sprintf('Version entry "%s" is not PRODUCT@REF (a tag, a branch, "latest" or a ../path).', $entry));
            }
            if (str_starts_with($m[2], '.') && !self::isPath($m[2])) {
                throw new \InvalidArgumentException(sprintf('Path "%s" must start with ../ and stay a plain relative path (it is taken from the repository root).', $m[2]));
            }
            $product = self::product($m[1]);
            if ($product === null) {
                throw new \InvalidArgumentException(sprintf('"%s" has no version to choose; the versionable parts are %s.', $m[1], implode(', ', array_keys(self::products()))));
            }
            $spec = ['product' => $product, 'ref' => $m[2]];
            if (!in_array($spec, $specs, true)) {
                $specs[] = $spec;
            }
        }

        return $specs;
    }

    /**
     * The stacks that contain any product of the specs, in matrix order.
     *
     * @param list<array{product: string, ref: string}> $specs
     * @return list<string>
     */
    public static function affectedStacks(array $specs): array
    {
        $products = self::products();
        $stacks = [];
        foreach ($specs as $spec) {
            $stacks = array_merge($stacks, $products[$spec['product']]['stacks']);
        }

        return array_values(array_intersect(self::ALL_STACKS, array_unique($stacks)));
    }

    /**
     * Provisioning rounds: every stack once per combination of the refs of
     * the products it contains; stacks whose combinations do not conflict
     * share a round (one setup run with the union of their environment).
     *
     * @param list<array{product: string, ref: string}> $specs
     * @param list<string> $stacks
     * @return list<array{env: array<string, string>, stacks: list<array{stack: string, label: string}>}>
     */
    public static function rounds(array $specs, array $stacks): array
    {
        $products = self::products();
        $refs = [];
        foreach ($specs as $spec) {
            $refs[$spec['product']][] = $spec['ref'];
        }
        $rounds = [];
        foreach ($stacks as $stack) {
            $relevant = array_values(array_filter(array_keys($refs), static fn (string $product): bool => in_array($stack, $products[$product]['stacks'], true)));
            foreach (self::combinations($relevant, $refs) as $combination) {
                $env = [];
                foreach ($combination as $product => $ref) {
                    $env[$products[$product]['env']] = $ref;
                }
                $placed = false;
                foreach ($rounds as &$round) {
                    if (self::compatible($round, $combination, $products)) {
                        $round['env'] += $env;
                        $round['stacks'][] = ['stack' => $stack, 'label' => self::label($combination)];
                        $placed = true;
                        break;
                    }
                }
                unset($round);
                if (!$placed) {
                    $rounds[] = ['env' => $env, 'stacks' => [['stack' => $stack, 'label' => self::label($combination)]]];
                }
            }
        }

        return $rounds;
    }

    /**
     * @param array<string, string> $combination product => ref
     */
    public static function label(array $combination): string
    {
        $parts = [];
        foreach (array_keys(self::products()) as $product) {
            if (isset($combination[$product])) {
                $parts[] = $product . '@' . $combination[$product];
            }
        }

        return implode('+', $parts);
    }

    /**
     * A label as a result file name segment: `guest-evo-latte~evo@3.5.x+latte@0.4.0-jit-off-…`.
     * The rule is mirrored by benchmark/workloads/admin/lib/report.mjs.
     */
    public static function fileTag(string $label): string
    {
        return (string) preg_replace('/[^A-Za-z0-9@.+_-]/', '_', $label);
    }

    /** A ref that names a directory on the host instead of a version: `../evo/aLatteX`, relative to the repository root. */
    public static function isPath(string $ref): bool
    {
        // Leading ../ steps, then plain segments (no further . or .. hops).
        return preg_match('#^(\.\./)+(?:(?!\.+/)[A-Za-z0-9._-]+/)*(?!\.+$)[A-Za-z0-9._-]+$#', $ref) === 1;
    }

    /** The products a path ref can be installed for: those the setup copies from a source tree. */
    public static function acceptsPath(string $product): bool
    {
        return !in_array(self::products()[$product]['kind'] ?? '', ['image', 'release'], true) || $product === 'modx';
    }

    /** Whether a tag names a pre-release (never "latest"). MODX's "-pl" suffix is its stable mark. */
    public static function isPrerelease(string $tag): bool
    {
        return preg_match('/(alpha|beta|rc|dev|snapshot|preview|nightly)/i', $tag) === 1;
    }

    /** Version-sorts tags ("v3.5.7", "3.5.10", "v3.2.4-pl") and returns the newest stable one. */
    public static function latestTag(array $tags): ?string
    {
        $stable = array_values(array_filter($tags, static fn (string $tag): bool => !self::isPrerelease($tag) && preg_match('/^v?\d+(\.\d+)*(-pl)?$/', $tag) === 1));
        usort($stable, static fn (string $a, string $b): int => version_compare(self::numeric($a), self::numeric($b)));

        return $stable === [] ? null : $stable[count($stable) - 1];
    }

    private static function numeric(string $tag): string
    {
        return (string) preg_replace('/^v|-pl$/', '', $tag);
    }

    /**
     * A ref against a repository's tags and branches: the tag of that name
     * when published, otherwise the branch of that name (with its head
     * commit, so a moved branch is a different version), `latest` the newest
     * stable tag.
     *
     * @param list<string> $tags
     * @param array<string, string> $heads branch => commit
     * @return array{kind: 'tag'|'branch', name: string, commit?: string}
     */
    public static function resolveGit(string $ref, array $tags, array $heads): array
    {
        if ($ref === self::LATEST) {
            $latest = self::latestTag($tags);
            if ($latest === null) {
                throw new \RuntimeException('No stable tag published to take as "latest".');
            }

            return ['kind' => 'tag', 'name' => $latest];
        }
        foreach ([$ref, 'v' . $ref, $ref . '-pl', 'v' . $ref . '-pl'] as $candidate) {
            if (in_array($candidate, $tags, true)) {
                return ['kind' => 'tag', 'name' => $candidate];
            }
        }
        if (isset($heads[$ref])) {
            return ['kind' => 'branch', 'name' => $ref, 'commit' => substr($heads[$ref], 0, 12)];
        }
        throw new \RuntimeException(sprintf('"%s" is neither a published tag nor a branch.', $ref));
    }

    /**
     * A ref as a Composer version: the tag when released (Composer lists
     * "v1.2.3" and "1.2.3" alike), otherwise the branch as its dev version
     * ("1.2.x-dev" for numeric branches, "dev-main" for the others); `latest`
     * is the newest stable release.
     *
     * @param list<string> $available versions Composer lists for the package
     */
    public static function resolveComposer(string $ref, array $available): string
    {
        if ($ref === self::LATEST) {
            $stable = array_values(array_filter($available, static fn (string $v): bool => !str_contains($v, 'dev') && !self::isPrerelease($v)));
            usort($stable, static fn (string $a, string $b): int => version_compare(ltrim($a, 'v'), ltrim($b, 'v')));
            if ($stable === []) {
                throw new \RuntimeException('No stable release to take as "latest".');
            }

            return $stable[count($stable) - 1];
        }
        foreach ([$ref, 'v' . $ref, ltrim($ref, 'v'), $ref . '-dev', 'dev-' . $ref] as $candidate) {
            if (in_array($candidate, $available, true)) {
                return $candidate;
            }
        }
        throw new \RuntimeException(sprintf('"%s" is neither a released version nor a branch (%s-dev, dev-%s) of the package.', $ref, $ref, $ref));
    }

    /**
     * @param list<string> $relevant
     * @param array<string, list<string>> $refs
     * @return list<array<string, string>>
     */
    private static function combinations(array $relevant, array $refs): array
    {
        $combinations = [[]];
        foreach ($relevant as $product) {
            $next = [];
            foreach ($combinations as $combination) {
                foreach ($refs[$product] as $ref) {
                    $next[] = $combination + [$product => $ref];
                }
            }
            $combinations = $next;
        }

        return $combinations;
    }

    /**
     * A combination fits a round when it agrees with the round's environment
     * on every product both set and adds no product that would change a
     * stack already in the round. (A product the round pins that the
     * combination leaves out cannot be part of this stack: a combination
     * covers every requested product of its stack.)
     *
     * @param array{env: array<string, string>, stacks: list<array{stack: string, label: string}>} $round
     * @param array<string, string> $combination
     */
    private static function compatible(array $round, array $combination, array $products): bool
    {
        foreach ($combination as $product => $ref) {
            $env = $products[$product]['env'];
            if (isset($round['env'][$env])) {
                if ($round['env'][$env] !== $ref) {
                    return false;
                }
                continue;
            }
            foreach ($round['stacks'] as $member) {
                if (in_array($member['stack'], $products[$product]['stacks'], true)) {
                    return false;
                }
            }
        }

        return true;
    }
}
