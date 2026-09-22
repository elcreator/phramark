<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/FixturePlan.php';
require dirname(__DIR__) . '/src/RuntimeProfile.php';
require dirname(__DIR__) . '/src/PageContract.php';
require dirname(__DIR__) . '/src/ResultSet.php';
require dirname(__DIR__) . '/src/VersionSpec.php';

use Phramark\FixturePlan;
use Phramark\PageContract;
use Phramark\ResultSet;
use Phramark\RuntimeProfile;
use Phramark\VersionSpec;

function expect(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function expectTemplateContract(string $path): void
{
    $template = file_get_contents($path);
    foreach (['Phramark benchmark', 'class="intro"', 'class="articles"', 'class="meta"', 'class="author"', 'class="reading-time"', 'Deterministic CMS benchmark fixture'] as $marker) {
        if ($template === false || !str_contains($template, $marker)) {
            throw new RuntimeException(sprintf('The shared visible page contract is missing %s in %s.', $marker, $path));
        }
    }
}

function repositoryFile(string $path): string
{
    $contents = file_get_contents(dirname(__DIR__) . '/' . $path);
    if ($contents === false) {
        throw new RuntimeException('Missing repository file ' . $path);
    }

    return $contents;
}

// Fixture plan: shared by the seeders of every stack.
expect(FixturePlan::counts(), ['categories' => 100, 'articles' => 10_000, 'admin_pages' => 5, 'documents' => 10_108], 'Fixture cardinality changed.');
expect(FixturePlan::categoryUrl(42), '/articles/category-042', 'The primary category URL changed.');
expect(FixturePlan::articleAlias(4213), 'article-004213', 'Article aliases must retain their fixed width.');
expect(FixturePlan::articleId(1), 103, 'Article ids follow the two root documents and the category range.');
expect(FixturePlan::categoryTitle(42), 'Category 042 practical PHP performance', 'Category titles must be shared by every adapter.');
expect(FixturePlan::articleTitle(4213), 'Article 004213: a deterministic CMS benchmark fixture', 'Article titles must be shared by every adapter.');
expect(FixturePlan::categoryIntrotext(), 'A stable category description used by every benchmark stack.', 'Category intros must be shared by every adapter.');
expect(FixturePlan::ARTICLES_ROOT_PARENT, 0, 'The /articles route must be top-level for Evolution and aPhalcon alias-path resolution.');
expect(FixturePlan::tvPresence(4213), FixturePlan::tvPresence(4213), 'TV presence must be deterministic.');
expect(FixturePlan::tvPresence(4213)['hero_image'], true, 'Every article requires a hero image.');
expect(FixturePlan::expectedCategoryPage(42), ['title' => 'Category 042 practical PHP performance', 'articles' => 20, 'authors' => 18, 'reading_times' => 20, 'hero_images' => 20], 'The visible contract of category 42 changed.');

// Admin workload fixture: a folder of editable pages apart from the guest data.
expect(FixturePlan::ADMIN_ROOT_ID > FixturePlan::articleId(10_000), true, 'The admin folder must not collide with article ids.');
expect(FixturePlan::adminPageId(1), 10104, 'Admin pages follow the admin folder id.');
expect(FixturePlan::adminPageId(5), 10108, 'Admin pages follow the admin folder id.');
expect(FixturePlan::adminPageAlias(3), 'admin-page-3', 'Admin page aliases are stable.');
expect(FixturePlan::adminPageTitle(3), 'Admin page 3', 'Admin page titles are what the reset script restores.');
expect(FixturePlan::adminPageContent(3), '<p>Admin workload page 3 body.</p>', 'Admin page bodies are what the reset script restores.');

// Runtime profile: JIT modes, result names and the traced request path.
expect(isset(RuntimeProfile::adapters()['october-4']), false, 'October CMS is out: its Composer distribution needs a licence key.');
expect(isset(RuntimeProfile::adapters()['drupal-11']), false, 'The Drupal stack is "drupal": its version is chosen per run, not part of the id.');
expect(RuntimeProfile::adapters()['drupal']['port'], 8083, 'Drupal keeps its port.');
foreach (RuntimeProfile::adapters() as $id => $adapter) {
    expect(preg_match('/\d+\.\d+/', $adapter['label'] . ' ' . $adapter['framework']), 0, sprintf('No version numbers in the label of %s: versions are recorded from the container, not hardcoded.', $id));
}
expect(RuntimeProfile::jitMode('off'), ['opcache_jit' => '0', 'jit_buffer_size' => '0'], 'JIT-off profile changed.');
expect(RuntimeProfile::jitMode('tracing'), ['opcache_jit' => 'tracing', 'jit_buffer_size' => '128M'], 'JIT profile changed.');
expect(RuntimeProfile::resultName('admin', 'evo-latte', 'tracing', '2026-01-01'), 'admin-evo-latte-jit-tracing-2026-01-01', 'Result names must carry workload, stack and JIT mode.');
expect(RuntimeProfile::adapters()['drupal']['components'], ['Drupal core', 'Symfony', 'Twig'], 'Drupal must be classified by the components its request path executes.');
expect(in_array('Laravel/Illuminate', RuntimeProfile::adapters()['evo-parser']['components'], true), true, 'Evolution 3.5 runs on Illuminate components; the label must say so.');
expect(in_array('Laravel/Illuminate', RuntimeProfile::adapters()['evo-phalcon']['components'], true), true, 'evo-phalcon still bootstraps Illuminate; it is not a pure Phalcon stack.');

$evolutionTrace = ['namespaces' => ['Illuminate' => 97, 'EvolutionCMS' => 63, 'Symfony' => 23], 'packages' => ['vendor:illuminate/database' => 51], 'extensions' => ['pdo_mysql', 'phalcon']];
expect(RuntimeProfile::classify($evolutionTrace), ['Evolution core', 'Laravel/Illuminate', 'Symfony (minor)'], 'An Evolution request on Illuminate must be classified as Laravel components.');
$phalconTrace = $evolutionTrace;
$phalconTrace['namespaces']['Latte'] = 15;
$phalconTrace['packages']['vendor:elcreator/aphalcon'] = 8;
expect(RuntimeProfile::classify($phalconTrace), ['Evolution core', 'Laravel/Illuminate', 'Symfony (minor)', 'Phalcon (extension)', 'Latte'], 'aPhalcon adds Phalcon and Latte on top of Illuminate, not instead of it.');
expect(RuntimeProfile::classify(['namespaces' => ['Drupal' => 315, 'Symfony' => 61, 'Twig' => 19]]), ['Drupal core', 'Symfony', 'Twig'], 'Drupal classification changed.');
expect(RuntimeProfile::classify(['namespaces' => ['TYPO3' => 268, 'Doctrine' => 66, 'TYPO3Fluid' => 23, 'Symfony' => 12], 'packages' => ['vendor:doctrine/dbal' => 82]]), ['TYPO3 core', 'Symfony (minor)', 'Doctrine DBAL', 'Fluid'], 'TYPO3 classification changed.');
expect(RuntimeProfile::classify(['namespaces' => ['EvolutionCMS' => 60], 'extensions' => ['phalcon']]), ['Evolution core'], 'A loaded Phalcon extension alone is not a used Phalcon request path.');
expect(RuntimeProfile::adapters()['winter']['port'], 8086, 'Winter CMS takes the next free port.');
expect(RuntimeProfile::adapters()['winter']['components'], ['Winter core', 'Laravel/Illuminate', 'Symfony (minor)', 'Doctrine DBAL (minor)', 'Twig'], 'Winter must be classified by the components its request path executes.');
$winterTrace = ['namespaces' => ['Illuminate' => 177, 'Winter' => 194, 'Cms' => 25, 'System' => 15, 'Symfony' => 35, 'Twig' => 21, 'Backend' => 3, 'Doctrine' => 5], 'packages' => ['vendor:winter/storm' => 251, 'vendor:laravel/framework' => 308, 'vendor:twig/twig' => 41, 'vendor:doctrine/dbal' => 12]];
expect(RuntimeProfile::classify($winterTrace), ['Winter core', 'Laravel/Illuminate', 'Symfony (minor)', 'Doctrine DBAL (minor)', 'Twig'], 'A Winter request on Illuminate and Twig must be classified as Winter core + Laravel components with the DBAL PDO wrapper.');
expect(RuntimeProfile::classify(['packages' => ['vendor:doctrine/dbal' => 82]]), ['Doctrine DBAL'], 'A DBAL query layer (TYPO3) is more than the PDO wrapper Laravel 9 loads.');
expect(RuntimeProfile::classify(['namespaces' => ['Illuminate' => 120, 'Winter' => 10, 'Symfony' => 20]]), ['Laravel/Illuminate', 'Symfony (minor)'], 'A few Storm helpers on a plain Laravel request are not the Winter CMS core.');
expect(RuntimeProfile::traceMismatch('winter', $winterTrace), ['missing' => [], 'unexpected' => []], 'The Winter label must match its traced request path.');
expect(RuntimeProfile::adapters()['modx']['port'], 8087, 'MODX Revolution takes the next free port.');
expect(RuntimeProfile::adapters()['modx']['components'], ['MODX core', 'xPDO'], 'MODX must be classified by the components its request path executes: its own core and ORM, no third-party framework.');
$modxTrace = ['namespaces' => ['MODX' => 111, 'xPDO' => 20, 'Composer' => 2, 'Phramark' => 2], 'packages' => ['site:core' => 132, 'vendor:xpdo/xpdo' => 23, 'vendor:symfony/polyfill-mbstring' => 2], 'extensions' => ['pdo_mysql']];
expect(RuntimeProfile::classify($modxTrace), ['MODX core', 'xPDO'], 'A MODX request must be classified as MODX core + xPDO; polyfill files are not a Symfony request path.');
expect(RuntimeProfile::classify(['namespaces' => ['MODX' => 5, 'xPDO' => 2]]), [], 'A few MODX helper classes on another stack are not the MODX request path.');
expect(RuntimeProfile::traceMismatch('modx', $modxTrace), ['missing' => [], 'unexpected' => []], 'The MODX label must match its traced request path.');
expect(RuntimeProfile::adapters()['wordpress-gantry']['port'], 8088, 'WordPress + Gantry 5 takes the next free port.');
expect(isset(RuntimeProfile::adapters()['wordpress']), false, 'WordPress is measured through the Gantry 5 framework only: one stack, not two.');
expect(RuntimeProfile::adapters()['wordpress-gantry']['components'], ['WordPress core', 'Gantry 5', 'Timber', 'Symfony (minor)', 'Twig'], 'WordPress + Gantry must be classified by the components its request path executes: core, the Gantry framework, its Timber bridge and Twig.');
$wordpressTrace = ['namespaces' => ['Gantry' => 60, 'Twig' => 40, 'Timber' => 8, 'Symfony' => 6, 'RocketTheme' => 10, 'WP_Query' => 1, 'wpdb' => 1], 'packages' => ['site:wp-includes' => 180, 'site:wp-content/plugins/gantry5' => 90, 'vendor:twig/twig' => 60, 'vendor:timber/timber' => 12, 'vendor:symfony/yaml' => 4, 'site:wp-content/themes/g5_hydrogen' => 4], 'extensions' => ['mysqli']];
expect(RuntimeProfile::classify($wordpressTrace), ['WordPress core', 'Gantry 5', 'Timber', 'Symfony (minor)', 'Twig'], 'A WordPress request through Gantry must be classified as WordPress core + Gantry 5 + Timber + Twig.');
expect(RuntimeProfile::classify(['packages' => ['site:wp-includes' => 180]]), ['WordPress core'], 'A WordPress request without Gantry is WordPress core alone; the stack must not be labelled Gantry then.');
expect(RuntimeProfile::traceMismatch('wordpress-gantry', ['packages' => ['site:wp-includes' => 180], 'namespaces' => ['Symfony' => 2]]), ['missing' => ['Gantry 5', 'Timber', 'Twig'], 'unexpected' => []], 'A trace that bypasses the Gantry theme must be reported as a label mismatch.');
expect(RuntimeProfile::traceMismatch('wordpress-gantry', $wordpressTrace), ['missing' => [], 'unexpected' => []], 'The WordPress + Gantry label must match its traced request path.');
expect(RuntimeProfile::traceMismatch('evo-phalcon', $phalconTrace), ['missing' => [], 'unexpected' => []], 'The evo-phalcon label must match its traced request path.');
expect(RuntimeProfile::traceMismatch('evo-phalcon', $evolutionTrace), ['missing' => ['Phalcon (extension)', 'Latte'], 'unexpected' => []], 'A trace without Phalcon must be reported as a label mismatch.');
foreach (['evo-parser', 'evo-latte', 'evo-latte-parser', 'evo-phalcon', 'drupal', 'typo3', 'winter', 'modx', 'wordpress-gantry'] as $adapter) {
    $trace = dirname(__DIR__) . '/benchmark/results/trace-' . $adapter . '.json';
    if (is_file($trace)) {
        expect(RuntimeProfile::traceMismatch($adapter, json_decode((string) file_get_contents($trace), true)), ['missing' => [], 'unexpected' => []], sprintf('The recorded trace of %s no longer matches its framework label.', $adapter));
    }
}

// Page contract: the fairness gate every stack's rendered page must pass.
$card = static fn (int $n, bool $author, bool $time): string => '<article><img src="/assets/images/article-' . sprintf('%06d', $n) . '.jpg" alt=""><h2>x</h2><p class="meta"><span class="author">' . ($author ? 'Author 1' : '') . '</span> · <span class="reading-time">' . ($time ? '5' : '') . ' min</span></p></article>';
$page = '<title>Category 042 practical PHP performance</title>';
for ($n = 4101; $n < 4121; $n++) {
    $presence = FixturePlan::tvPresence($n);
    $page .= $card($n, $presence['author'], $presence['reading_time']);
}
expect(PageContract::violations(42, $page), [], 'A page that renders the fixture must pass the contract.');
expect(PageContract::violations(42, str_replace('Author 1', '', $page)), ['authors: expected 18, got 0'], 'Empty TV values must fail the contract.');
expect(PageContract::violations(42, $page, 302), ['expected HTTP 200, got 302 (redirect)'], 'A redirect must fail the contract.');
expect(PageContract::inspect('<main><title>T</title></main>')['articles'], 0, 'Inspection tolerates arbitrary markup.');

// Adapter sources: the shared markup and the fixes the fairness review required.
foreach ([
    'benchmark/implementations/evo-latte/views/benchmark-category.latte',
    'benchmark/implementations/evo-latte-parser/views/benchmark-category.latte',
    'benchmark/implementations/evo-phalcon/views/benchmark-category.latte',
    'benchmark/implementations/drupal/modules/custom/phramark_benchmark/templates/category-page.html.twig',
    'benchmark/implementations/typo3/Resources/Private/Templates/Category.html',
    'benchmark/implementations/winter/themes/phramark/pages/category.htm',
    'benchmark/implementations/wordpress-gantry/theme-custom/views/phramark-category.html.twig',
] as $template) {
    expectTemplateContract(dirname(__DIR__) . '/' . $template);
}
// MODX splits the page between the template and the article chunk.
$modxPage = tempnam(sys_get_temp_dir(), 'phramark');
file_put_contents($modxPage, repositoryFile('benchmark/implementations/modx/elements/templates/category.html') . repositoryFile('benchmark/implementations/modx/elements/chunks/phramarkArticle.html'));
expectTemplateContract($modxPage);
unlink($modxPage);

$setup = repositoryFile('benchmark/fixtures/setup.php');
expect(str_contains($setup, "file_get_contents(\$marker) === \$wanted"), true, 'Fixture setup must be safely repeatable: a site is reinstalled only when its resolved versions changed.');
expect(str_contains($setup, "['git', 'clone', '--depth=1', '--branch', \$ref"), true, 'Evolution is cloned from the resolved tag or branch, never a hardcoded one.');
expect(str_contains($setup, "/core/storage/bootstrap/services.php'"), true, 'The service cache written during a package install is dropped, so every installed provider boots (aPhalcon without its provider answers 500).');
expect(preg_match("/--branch', '\\d/", $setup), 0, 'No hardcoded Evolution branch in the setup.');
$cross = repositoryFile('benchmark/fixtures/setup-cross-cms.sh');
foreach (['drupal', 'typo3', 'winter', 'modx', 'wordpress gantry classic-editor'] as $products) {
    expect(str_contains($cross, 'wanted_versions ' . $products . ')'), true, sprintf('The cross-CMS setup must resolve the versions of "%s" before installing.', $products));
}
expect(preg_match('/_VERSION:-\d/', $cross), 0, 'No hardcoded default versions in the cross-CMS setup: "latest" resolves to the newest release.');
expect(str_contains($cross, 'site_current "$site" "$wanted" && return'), true, 'A cross-CMS site is reinstalled only when its resolved versions changed.');
expect(str_contains($setup, "getenv('PHRAMARK_STACKS')") && str_contains($setup, 'is required by this run and could not be installed'), true, 'Evolution sites install independently: a version that cannot be built fails only that site unless the run needs it.');
expect(str_contains($cross, 'sh "$0" "install_') && str_contains($cross, 'is required by this run and could not be installed'), true, 'Cross-CMS sites install independently, each in its own process.');

// A working copy on the host is installed from git's view of it (tracked plus
// untracked, ignored files left behind: a developer's site config, logs and
// IDE state would otherwise become the benchmark site's), and fingerprinted
// from the same set so that dev noise does not trigger a reinstall.
$resolver = repositoryFile('benchmark/fixtures/resolve-version.php');
expect(str_contains($resolver, "'ls-files', '-z', '-co', '--exclude-standard'"), true, 'A checkout on the host is listed by git: tracked plus untracked files, ignored ones excluded.');
expect(str_contains($resolver, '$files = gitFiles($path);') && str_contains($resolver, "preg_match('#(^|/)(\\.git|vendor|node_modules)/#', \$file) !== 1"), true, 'The path fingerprint covers the git-listed files, with the same vendor/.git/node_modules exclusion as a plain directory.');
expect(str_contains($setup, 'copyHostTree($ref, $path);') && str_contains($setup, 'copyHostTree($m[2], $source);'), true, 'Evolution and the extensions are copied from a host directory through copyHostTree.');
expect(str_contains($setup, "'/.git'"), false, '.git is never copied into a site: versions.php names the commit from the host checkout.');
expect(preg_match("/'cp', '-R', \\\$ref \\. '\\/\\.'/", $setup), 0, 'A host checkout is never copied wholesale.');
expect(str_contains($setup, 'tar -C "$1" --null -T "$2" -cf - | tar -C "$3" -xf -'), true, 'copyHostTree streams the listed files with tar.');

// EVO_NO_SESSION=1 switches the Evolution front end to NO_SESSION on every
// setup run (installed sites included), and a result records it.
expect(str_contains(repositoryFile('benchmark/compose.yaml'), 'EVO_NO_SESSION: ${EVO_NO_SESSION:-}'), true, 'The setup service must take EVO_NO_SESSION from the environment.');
expect(preg_match('/writeSettings\(.*\);\s+writeDefines\(\$site\);/', $setup), 1, 'The defines are applied to every installed site on every setup run, next to the settings.');
expect(str_contains(repositoryFile('benchmark/scripts/versions.php'), "\$versions['front-end session'] = 'off (NO_SESSION)'"), true, 'versions.php must record a NO_SESSION site in the result components.');
if (preg_match('/^function writeDefines\(string \$site\): void
\{.*?^\}/ms', str_replace("
", "
", $setup), $m) !== 1) {
    throw new RuntimeException('setup.php must define writeDefines(string $site).');
}
$sitesDir = sys_get_temp_dir() . '/phramark-defines-' . getmypid();
mkdir($sitesDir . '/evo-parser/core/custom', 0777, true);
eval('const ROOT = ' . var_export($sitesDir, true) . ';' . $m[0]);
$define = $sitesDir . '/evo-parser/core/custom/define.php';
foreach (['1', 'true', 'yes'] as $on) {
    putenv('EVO_NO_SESSION=' . $on);
    writeDefines('evo-parser');
    expect(is_file($define) && str_contains((string) file_get_contents($define), "define('NO_SESSION', true);"), true, 'EVO_NO_SESSION=' . $on . ' writes core/custom/define.php with NO_SESSION.');
}
foreach (['0', '', 'no'] as $off) {
    putenv('EVO_NO_SESSION=' . $off);
    writeDefines('evo-parser');
    expect(is_file($define), false, 'EVO_NO_SESSION=' . var_export($off, true) . ' removes the define again.');
}
putenv('EVO_NO_SESSION');
rmdir($sitesDir . '/evo-parser/core/custom');
rmdir($sitesDir . '/evo-parser/core');
rmdir($sitesDir . '/evo-parser');
rmdir($sitesDir);
foreach (['benchmark/compose.yaml' => 'EVO_VERSION: ${EVO_VERSION:-latest}', 'benchmark/images/php/Dockerfile' => 'FROM php:${PHP_VERSION}-fpm-bookworm', 'benchmark/images/cms-php/Dockerfile' => 'FROM php:${PHP_VERSION}-fpm-bookworm'] as $file => $needle) {
    expect(str_contains(repositoryFile($file), $needle), true, sprintf('%s must take the version from the environment (%s).', $file, $needle));
}

// Version specs: which parts can be pinned, how a ref resolves, and the plan.
expect(array_keys(VersionSpec::products()), ['php', 'evo', 'latte', 'phalcon', 'drupal', 'typo3', 'winter', 'modx', 'wordpress', 'gantry', 'classic-editor'], 'The versionable parts of the stacks.');
expect(VersionSpec::product('alattex'), 'latte', 'aLatteX is addressed as "latte" (or "alattex").');
expect(VersionSpec::product('Evolution'), 'evo', 'Product names are case-insensitive aliases.');
expect(VersionSpec::product('illuminate'), null, 'Not every part has a version to choose: Illuminate comes with Evolution.');
expect(VersionSpec::parse('evo@3.5.x,evo@3.5.8,latte@0.4.0,evo@3.5.8'), [['product' => 'evo', 'ref' => '3.5.x'], ['product' => 'evo', 'ref' => '3.5.8'], ['product' => 'latte', 'ref' => '0.4.0']], 'Specs parse to product/ref pairs without duplicates.');
foreach (['evo', 'evo@', 'illuminate@1.0', 'evo@3.5 x', 'evo@3.5.x;rm'] as $bad) {
    try {
        VersionSpec::parse($bad);
        throw new RuntimeException('Spec "' . $bad . '" must be rejected.');
    } catch (InvalidArgumentException) {
    }
}
expect(VersionSpec::affectedStacks(VersionSpec::parse('latte@0.4.0')), ['evo-latte', 'evo-latte-parser', 'evo-phalcon'], 'Without --solutions a version selects the stacks its product is part of.');
expect(VersionSpec::affectedStacks(VersionSpec::parse('php@8.3')), array_keys(RuntimeProfile::adapters()), 'PHP is part of every stack.');
expect(VersionSpec::label(['latte' => '0.4.0', 'evo' => '3.5.8']), 'evo@3.5.8+latte@0.4.0', 'Labels list the products in a fixed order.');
// A ref starting with .. is a directory on the host, relative to the repository root.
expect(VersionSpec::parse('latte@0.2.0,latte@../evo/aLatteX'), [['product' => 'latte', 'ref' => '0.2.0'], ['product' => 'latte', 'ref' => '../evo/aLatteX']], 'A path ref is a version to compare like any other.');
expect(VersionSpec::isPath('../evo/aLatteX'), true, 'Paths start with ../.');
// The released aPhalcon 0.1.0 requires Evolution ^3.5.9 and cannot be installed
// on the 3.5.8 release; the working copy requires ^3.5.8 and runs on both.
$phalconDefault = VersionSpec::products()['phalcon']['default'] ?? '';
expect(VersionSpec::isPath($phalconDefault), true, 'aPhalcon defaults to the working copy next to this checkout, which installs on every Evolution ref the matrix runs.');
expect(VersionSpec::acceptsPath('phalcon'), true, 'aPhalcon must be installable from a directory for that default to work.');
expect(str_contains(repositoryFile('benchmark/fixtures/resolve-version.php'), "if (\$ref === VersionSpec::LATEST && isset(\$definition['default']))"), true, 'The resolver must take a product default in place of "latest".');
expect(VersionSpec::isPath('3.5.x'), false, 'A branch is not a path.');
foreach (['latte@./x', 'latte@.../x', 'latte@../', 'latte@../a/../../etc'] as $bad) {
    try {
        VersionSpec::parse($bad);
        throw new RuntimeException('Spec "' . $bad . '" must be rejected.');
    } catch (InvalidArgumentException) {
    }
}
expect(VersionSpec::acceptsPath('latte') && VersionSpec::acceptsPath('evo') && VersionSpec::acceptsPath('modx') && VersionSpec::acceptsPath('drupal'), true, 'Extensions, source trees and project directories can be installed from a path.');
expect(VersionSpec::acceptsPath('php') || VersionSpec::acceptsPath('gantry'), false, 'The PHP image and Gantry (release archives only) cannot.');
expect(VersionSpec::fileTag('latte@../evo/aLatteX'), 'latte@.._evo_aLatteX', 'A path label as a file name segment.');
$rounds = VersionSpec::rounds(VersionSpec::parse('latte@0.2.0,latte@../evo/aLatteX'), ['evo-latte-parser']);
expect(array_map(static fn (array $round): array => [$round['env'], array_column($round['stacks'], 'label')], $rounds), [[['ALATTEX_VERSION' => '0.2.0'], ['latte@0.2.0']], [['ALATTEX_VERSION' => '../evo/aLatteX'], ['latte@../evo/aLatteX']]], 'The release and the working copy are two builds of the stack.');
$setup = repositoryFile('benchmark/fixtures/setup.php');
expect(str_contains($setup, "'versions' => [\$package => 'dev-local']") && str_contains($setup, "'symlink' => false"), true, 'An extension from a directory is a copied Composer path repository pinned to dev-local, so Packagist can never satisfy it instead.');
expect(str_contains(repositoryFile('benchmark/compose.yaml'), '${PHRAMARK_SOURCES:-../..}:/host:ro'), true, 'The setup containers see the checkout parent at /host for path versions.');
expect(VersionSpec::fileTag('evo@feature/x y+latte@0.4.0'), 'evo@feature_x_y+latte@0.4.0', 'A label as a file name segment (the rule report.mjs mirrors).');

// The user's example: aLatteX 0.4.0 paired with each Evolution ref on the
// Latte stack, evo-parser at each Evolution ref alone, Drupal untouched;
// compatible combinations share a provisioning round.
$rounds = VersionSpec::rounds(VersionSpec::parse('evo@3.5.x,evo@3.5.8,latte@0.4.0'), ['evo-parser', 'evo-latte-parser', 'drupal']);
expect(count($rounds), 2, 'One round per Evolution ref.');
expect($rounds[0]['env'], ['EVO_VERSION' => '3.5.x', 'ALATTEX_VERSION' => '0.4.0'], 'The round provisions the union of its stacks\' versions.');
expect($rounds[0]['stacks'], [['stack' => 'evo-parser', 'label' => 'evo@3.5.x'], ['stack' => 'evo-latte-parser', 'label' => 'evo@3.5.x+latte@0.4.0'], ['stack' => 'drupal', 'label' => '']], 'Every stack is labelled by the versions of its own parts; a stack without any runs once, unlabelled, in the first round.');
expect($rounds[1]['env'], ['EVO_VERSION' => '3.5.8', 'ALATTEX_VERSION' => '0.4.0'], 'The second Evolution ref is its own round.');
expect($rounds[1]['stacks'], [['stack' => 'evo-parser', 'label' => 'evo@3.5.8'], ['stack' => 'evo-latte-parser', 'label' => 'evo@3.5.8+latte@0.4.0']], 'Drupal is not run again.');
$rounds = VersionSpec::rounds(VersionSpec::parse('latte@0.4.0,latte@0.5.0,phalcon@0.1.0'), ['evo-phalcon']);
expect(array_map(static fn (array $round): array => array_column($round['stacks'], 'label'), $rounds), [['latte@0.4.0+phalcon@0.1.0'], ['latte@0.5.0+phalcon@0.1.0']], 'The plan is the cartesian product of the refs of the products a stack contains.');
$rounds = VersionSpec::rounds(VersionSpec::parse('evo@3.5.8,evo@3.5.x,latte@0.4.0,latte@0.5.0'), ['evo-latte']);
expect(array_map(static fn (array $round): array => array_column($round['stacks'], 'label'), $rounds), [['evo@3.5.8+latte@0.4.0'], ['evo@3.5.8+latte@0.5.0'], ['evo@3.5.x+latte@0.4.0'], ['evo@3.5.x+latte@0.5.0']], 'Two refs of two products give four builds.');

// Ref resolution: a published tag first, else the branch of that name;
// "latest" is the newest stable tag.
$tags = ['3.5.7', '3.5.8', '3.5.10', '3.6.0-rc1', '2.0.15'];
$heads = ['3.5.x' => '851c705abcdef0123456', 'develop' => 'deadbeefdeadbeef0000'];
expect(VersionSpec::resolveGit('3.5.8', $tags, $heads), ['kind' => 'tag', 'name' => '3.5.8'], 'A published tag wins.');
expect(VersionSpec::resolveGit('3.5.x', $tags, $heads), ['kind' => 'branch', 'name' => '3.5.x', 'commit' => '851c705abcde'], 'No tag of that name: the branch, with its head commit.');
expect(VersionSpec::resolveGit('latest', $tags, $heads), ['kind' => 'tag', 'name' => '3.5.10'], '"latest" is the newest stable tag by version order, not by text order, and never a pre-release.');
expect(VersionSpec::resolveGit('3.2.4', ['v3.2.4-pl', 'v3.2.3-pl', 'v3.2.4-rc1'], []), ['kind' => 'tag', 'name' => 'v3.2.4-pl'], 'MODX tags carry a v prefix and the -pl stable suffix.');
expect(VersionSpec::latestTag(['v3.2.4-pl', 'v3.1.0-pl', 'v3.2.4-rc1', 'v3.10.0-pl', 'v3.10.1-beta1']), 'v3.10.0-pl', 'The newest stable MODX tag.');
try {
    VersionSpec::resolveGit('nope', $tags, $heads);
    throw new RuntimeException('An unknown ref must be rejected.');
} catch (RuntimeException $exception) {
    expect(str_contains($exception->getMessage(), 'neither a published tag nor a branch'), true, 'The error says what was looked for.');
}
$available = ['11.2.0', 'v11.1.0', '11.x-dev', 'dev-main', '12.0.0-beta1', '10.5.1'];
expect(VersionSpec::resolveComposer('11.2.0', $available), '11.2.0', 'A released Composer version.');
expect(VersionSpec::resolveComposer('11.1.0', $available), 'v11.1.0', 'Composer versions may carry a v prefix.');
expect(VersionSpec::resolveComposer('11.x', $available), '11.x-dev', 'A numeric branch is its -dev version.');
expect(VersionSpec::resolveComposer('main', $available), 'dev-main', 'A named branch is its dev- version.');
expect(VersionSpec::resolveComposer('latest', $available), '11.2.0', '"latest" is the newest stable release, not a dev branch or a beta.');

// Results keep the builds of a version comparison apart and record what
// each one measured.
$dir = sys_get_temp_dir() . '/phramark-versions-' . getmypid();
mkdir($dir);
$wrk = "Requests/sec:     30.03\n    50.000%  300.00ms\n    99.000%  600.00ms\n\n---- memory ----\nPHP-FPM container peak RSS: 80.0 MiB (docker stats, 1 s samples)\n\n---- versions ----\nlabel: evo@3.5.8+latte@0.4.0\n" . json_encode([['name' => 'PHP', 'version' => '8.4.25'], ['name' => 'Evolution CMS', 'version' => '3.5.8 (tag 3.5.8@abc1234, 2026-08-01)', 'source' => 'https://github.com/evolution-cms/evolution']]) . "\n";
file_put_contents($dir . '/guest-evo-latte~evo@3.5.8+latte@0.4.0-jit-off-rps-30-2026-09-21T10-00-00Z.txt', $wrk);
file_put_contents($dir . '/guest-evo-latte-jit-off-rps-30-2026-09-21T10-05-00Z.txt', str_replace(['300.00ms', 'label: evo@3.5.8+latte@0.4.0'], ['280.00ms', 'label: '], $wrk));
file_put_contents($dir . '/guest-evo-latte~latte@.._evo_aLatteX-jit-off-rps-30-2026-09-21T10-06-00Z.txt', str_replace('label: evo@3.5.8+latte@0.4.0', 'label: latte@../evo/aLatteX', $wrk));
expect(ResultSet::guestRow($dir . '/guest-evo-latte~latte@.._evo_aLatteX-jit-off-rps-30-2026-09-21T10-06-00Z.txt')['version'], 'latte@../evo/aLatteX', 'The label line restores what the file name sanitised.');
unlink($dir . '/guest-evo-latte~latte@.._evo_aLatteX-jit-off-rps-30-2026-09-21T10-06-00Z.txt');
$row = ResultSet::guestRow($dir . '/guest-evo-latte~evo@3.5.8+latte@0.4.0-jit-off-rps-30-2026-09-21T10-00-00Z.txt');
expect([$row['stack'], $row['version'], $row['jit'], $row['rate'], $row['p50Ms'], $row['recordedAt']], ['evo-latte', 'evo@3.5.8+latte@0.4.0', 'off', 30, 300.0, '2026-09-21T10:00:00Z'], 'A guest result name carries the stack and the version label.');
expect($row['components'][1]['name'], 'Evolution CMS', 'The exact components the container reported are read from the result.');
expect(ResultSet::guestRow($dir . '/guest-evo-latte-jit-off-rps-30-2026-09-21T10-05-00Z.txt')['version'], '', 'The default build has no version label.');
$report = ['workload' => 'admin', 'stack' => 'evo-latte', 'version' => 'evo@3.5.8+latte@0.4.0', 'jit' => 'off', 'recordedAt' => '2026-09-21T10:10:00Z', 'summary' => ['ms' => ['login' => ['median' => 500]]], 'steps' => [['action' => 'login', 'label' => 'round 1', 'ms' => 500]], 'components' => [['name' => 'PHP', 'version' => '8.4.25']]];
file_put_contents($dir . '/admin-evo-latte~evo@3.5.8+latte@0.4.0-jit-off-2026-09-21T10-10-00Z.json', json_encode($report));
file_put_contents($dir . '/admin-evo-latte-jit-off-2026-09-21T10-15-00Z.json', json_encode(['version' => '', 'components' => null] + $report));
$set = ResultSet::collect($dir);
// Both runs report the same build (Evolution 3.5.8), so they are one cell
// shown under the resolved version, whatever the label said: the latest run
// is the row and both count as repetitions.
expect(array_map(static fn (array $r): array => [$r['stack'], $r['version'], $r['ref'], $r['p50Ms'], $r['repeats']['n']], $set['guest']), [['evo-latte', '3.5.8', '', 280.0, 2]], 'A default build and a run pinned to the same release merge into one cell named by the resolved version.');
expect(array_map(static fn (array $r): array => [$r['version'], $r['components'][0]['version'] ?? null], $set['admin']), [['', null], ['evo@3.5.8+latte@0.4.0', '8.4.25']], 'A report whose components do not name the CMS keeps its label; one without components and without a snapshot stays the default build.');
expect(ResultSet::shortVersion('3.5.8 (tag 3.5.8@374e110, 2026-09-10)'), '3.5.8', 'A tag build is its version.');
expect(ResultSet::shortVersion('3.5.9 (path /host/evolution@3f9ea9220 2026-09-21, files 82685d73f20be0b3)'), '3.5.9 ../evolution@3f9ea9220', 'A working-copy build names the directory and commit.');
expect(ResultSet::shortVersion('3.5.9 (branch 3.5.x@abc1234, 2026-09-21)'), '3.5.9 3.5.x@abc1234', 'A branch build names the branch and commit.');
expect(ResultSet::shortVersion('v12.69.2'), '12.69.2', 'A Composer "v" prefix is dropped.');
expect(ResultSet::shortVersion('dev-local (evo/aLatteX@3cd59aae7232ddf7)'), '../evo/aLatteX@3cd59aae', 'An extension from a host directory names the directory and a short fingerprint.');
expect(ResultSet::buildVersion('evo-phalcon', [['name' => 'Evolution CMS', 'version' => '3.5.8 (tag 3.5.8@1, 2026-09-10)'], ['name' => 'elcreator/alattex', 'version' => '0.5.0'], ['name' => 'elcreator/aphalcon', 'version' => '0.1.0']]), '3.5.8 · aLatteX 0.5.0 · aPhalcon 0.1.0', 'A stack with extensions names them after the CMS.');
expect(ResultSet::buildVersion('wordpress-gantry', [['name' => 'WordPress', 'version' => '7.1.1'], ['name' => 'gantry5', 'version' => '5.6.4']]), '7.1.1 · Gantry 5.6.4', 'WordPress + Gantry names both.');
expect(ResultSet::buildVersion('drupal', [['name' => 'PHP', 'version' => '8.4.25']]), null, 'Components without the CMS name no build.');
expect(ResultSet::buildVersion('evo-parser', [['name' => 'Evolution CMS', 'version' => '3.5.9 (path /host/evolution@3f9ea9220 2026-09-21, files x)'], ['name' => 'front-end session', 'version' => 'off (NO_SESSION)']]), '3.5.9 ../evolution@3f9ea9220 · NO_SESSION', 'A site without a front-end session is its own cell.');
$snapshot = ['stacks' => ['drupal' => [['name' => 'drupal/core', 'version' => '11.4.7']], 'evo-parser' => [['name' => 'Evolution CMS', 'version' => '3.5.9 (path /host/evolution@3f9ea9220 2026-09-21, files x)']]]];
expect(ResultSet::withResolvedVersion(['stack' => 'drupal', 'version' => '', 'components' => null], $snapshot)['version'], '11.4.7', 'A run recorded before components were attached takes the snapshot of a release build.');
expect(ResultSet::withResolvedVersion(['stack' => 'evo-parser', 'version' => '', 'components' => null], $snapshot)['version'], '', 'A snapshot of a working-copy build says nothing about an old run.');
expect(ResultSet::withResolvedVersion(['stack' => 'drupal', 'version' => 'drupal@11.4.7', 'components' => null], $snapshot)['version'], 'drupal@11.4.7', 'A labelled run without components keeps its label.');
expect(str_contains(ResultSet::markdown($set), '| evo-latte (evo@3.5.8+latte@0.4.0) | off |'), true, 'The Markdown tables name the build.');
foreach (glob($dir . '/*') as $file) {
    unlink($file);
}
rmdir($dir);
expect(str_contains($setup, 'Phramark benchmark'), true, 'The parser workload must implement the shared visible page contract.');
expect(str_contains($setup, 'site_tmplvar_templates'), true, 'TVs must be assigned to the benchmark template or Evolution renders empty cards.');
expect(str_contains($setup, "'content' => '[[benchmarkCategory]]'"), true, 'The parser template must not wrap the page contract.');
expect(str_contains($setup, "'seostrict' => 0"), true, 'Evolution must answer the canonical URL without a redirect.');
expect(str_contains(repositoryFile('benchmark/implementations/evo-latte/core/custom/config/alattex.php'), "'evo_tags' => false"), true, 'evo-latte measures the Latte view without the EVO pass.');
expect(str_contains(repositoryFile('benchmark/implementations/evo-latte-parser/core/custom/config/alattex.php'), "'evo_tags' => true"), true, 'evo-latte-parser measures the Latte view with the EVO pass.');
expect(repositoryFile('benchmark/implementations/evo-latte-parser/views/benchmark-category.latte'), repositoryFile('benchmark/implementations/evo-latte/views/benchmark-category.latte'), 'Both Latte stacks must render the identical view; only the pass differs.');
expect(RuntimeProfile::adapters()['evo-latte-parser']['port'], 8085, 'evo-latte-parser takes the port October used.');
expect(str_contains(repositoryFile('benchmark/workloads/category.lua'), '"/articles/category-%03d"'), true, 'The load workload must use the canonical URL form.');
expect(str_contains(repositoryFile('benchmark/implementations/drupal/modules/custom/phramark_benchmark/phramark_benchmark.routing.yml'), "path: '/articles/{category}'"), true, 'Drupal placeholders must span a whole path segment.');
expect(is_file(dirname(__DIR__) . '/benchmark/implementations/typo3/Configuration/Services.yaml'), true, 'The TYPO3 middleware needs a Services.yaml to be autowired.');
expect(str_contains(repositoryFile('benchmark/config/php.ini'), 'opcache.max_accelerated_files'), true, 'OPcache must be sized for the largest CMS so no stack thrashes.');
$spec = repositoryFile('benchmark/workloads/admin/tests/admin.spec.mjs');
foreach (['login', 'open-edit', 'save-edit', 'open-create', 'save-create', 'logout'] as $action) {
    expect(str_contains($spec, "'" . $action . "'"), true, sprintf('The admin workload must time the %s action.', $action));
}
expect(str_contains(repositoryFile('benchmark/workloads/admin/lib/config.mjs'), (string) FixturePlan::ADMIN_ROOT_ID), true, 'The admin workload must target the seeded admin folder.');
foreach (['evolution', 'drupal', 'typo3', 'winter', 'modx', 'wordpress'] as $adapter) {
    $source = repositoryFile('benchmark/workloads/admin/lib/adapters/' . $adapter . '.mjs');
    foreach (['pageIds()', 'async login()', 'async openEditor(', 'async openCreator()', 'async save(', 'async savedTitle()', 'async savedContent()', 'async logout()'] as $method) {
        expect(str_contains($source, $method), true, sprintf('The %s adapter must implement %s.', $adapter, $method));
    }
}
$index = repositoryFile('benchmark/workloads/admin/lib/adapters/index.mjs');
expect(str_contains($index, 'DrupalAdapter') && str_contains($index, 'Typo3Adapter') && str_contains($index, 'EvolutionAdapter') && str_contains($index, 'WinterAdapter') && str_contains($index, 'ModxAdapter') && str_contains($index, 'WordPressAdapter'), true, 'All six admin adapters must be registered.');
expect(str_contains(repositoryFile('benchmark/workloads/admin/lib/document-timer.mjs'), 'x-winter-request-handler'), true, 'Winter saves through its AJAX framework; the server timer must count those handler requests.');
expect(str_contains(repositoryFile('benchmark/workloads/admin/lib/document-timer.mjs'), 'connectors'), true, 'MODX manager work is XHRs to the connector; the server timer must count them.');
expect(str_contains(repositoryFile('benchmark/workloads/admin/tests/admin.spec.mjs'), 'X-Phramark-Step'), true, 'Admin steps must be attributable in the PHP memory log.');
expect(str_contains(repositoryFile('benchmark/workloads/admin/tests/admin.spec.mjs'), 'frontend.snapshot()'), true, 'Admin steps must record frontend memory.');
expect(str_contains(repositoryFile('benchmark/config/php.ini'), 'auto_prepend_file=/opt/phramark/benchmark/fixtures/memory-prepend.php'), true, 'The PHP memory probe must be on for every stack.');
expect(str_contains(repositoryFile('benchmark/fixtures/memory-prepend.php'), 'memory_get_peak_usage(true)'), true, 'The PHP memory probe must record the allocator peak.');
expect(str_contains(repositoryFile('benchmark/scripts/run'), 'memory-summary.php'), true, 'Guest results must include the PHP memory summary.');
expect(str_contains(repositoryFile('benchmark/scripts/admin'), 'merge-memory.mjs'), true, 'Admin results must merge the PHP memory summary.');
$crossSetup = repositoryFile('benchmark/fixtures/setup-cross-cms.sh');
expect(str_contains($crossSetup, 'drupal-admin-seed.php') && str_contains($crossSetup, 'typo3-admin-seed.php') && str_contains($crossSetup, 'winter-admin-seed.php'), true, 'Cross-CMS installs must seed the admin fixture.');
expect(str_contains($crossSetup, 'composer create-project "wintercms/winter:$version"'), true, 'Winter is installed from its licence-free Composer distribution at the resolved version.');
expect(str_contains($crossSetup, 'theme:use phramark'), true, 'The Winter stack must serve the benchmark theme.');
expect(str_contains($crossSetup, 'modx.s3.amazonaws.com/releases/') && str_contains($crossSetup, 'setup/index.php --installmode=new'), true, 'MODX is installed from its release archive through the CLI installer.');
expect(str_contains($crossSetup, 'modx-seed.php') && str_contains($crossSetup, 'modx-admin-seed.php'), true, 'The MODX install must seed the guest and admin fixtures.');
expect(str_contains($crossSetup, 'sed -i "s#$site/#/var/www/html/#g"'), true, 'MODX stores absolute paths; they must point at the FPM mount.');
$modxSeed = repositoryFile('benchmark/fixtures/cms/modx-seed.php');
expect(str_contains($modxSeed, "'friendly_urls' => '1'") && str_contains($modxSeed, "'use_alias_path' => '1'"), true, 'MODX must resolve the canonical URL through its alias path.');
expect(str_contains($modxSeed, "'cache_resource' => '0'"), true, 'MODX must parse the document per request like Evolution (enable_cache=0).');
expect(str_contains($modxSeed, "'w_newsfeed', 'w_securityfeed', 'w_updates'"), true, 'The MODX dashboard must not fetch modx.com feeds during the timed login.');
expect(str_contains(repositoryFile('benchmark/implementations/modx/elements/templates/category.html'), '[[!phramarkCategory]]'), true, 'The MODX category snippet must be called uncached.');
expect(str_contains(repositoryFile('benchmark/implementations/modx/elements/snippets/phramarkCategory.php'), '$modx->newQuery(') && str_contains(repositoryFile('benchmark/implementations/modx/elements/snippets/phramarkCategory.php'), '$modx->getChunk('), true, 'The MODX snippet must read through xPDO and render through the parser (chunk), not raw PDO and string concatenation.');
expect(str_contains(repositoryFile('benchmark/implementations/modx/core/components/phramark/src/Model/mysql/Article.php'), "'package' => 'Phramark" . '\\\\' . "Model',"), true, 'The xPDO package key must match addPackage() so the fixture table keeps its unprefixed name.');
expect(str_contains(repositoryFile('benchmark/config/nginx-modx.conf'), 'absolute_redirect off'), true, 'The manager login redirect must keep the published port.');
expect(str_contains(repositoryFile('benchmark/compose.yaml'), '8087:80') && str_contains(repositoryFile('benchmark/scripts/lib.sh'), '8087) stack=modx'), true, 'The MODX stack must be published and known to the scripts.');
expect(str_contains(repositoryFile('benchmark/implementations/winter/themes/phramark/pages/category.htm'), 'url = "/articles/:slug|^category-[0-9]{3}$"'), true, 'Winter route parameters span a whole segment; the category number is validated by regex.');
expect(str_contains(repositoryFile('benchmark/implementations/winter/plugins/phramark/benchmark/controllers/pages/config_form.yaml'), 'redirect: phramark/benchmark/pages/update/:id'), true, 'A created Winter page must redirect to its editor so the workload can read its id.');
expect(str_contains(repositoryFile('benchmark/implementations/winter/plugins/phramark/benchmark/models/page/fields.yaml'), 'type: textarea'), true, 'The Winter page body is a plain textarea like the Drupal and Evolution fixtures.');
expect(str_contains(repositoryFile('benchmark/compose.yaml'), '8086:80') && str_contains(repositoryFile('benchmark/scripts/lib.sh'), '8086) stack=winter'), true, 'The Winter stack must be published and known to the scripts.');
expect(str_contains($crossSetup, "trustedHostsPattern'] = '.*'"), true, 'TYPO3 must trust the compose-network host the load generator uses.');
expect(str_contains($crossSetup, 'wordpress-pkg_gantry5_v$gantry.zip') && str_contains($crossSetup, 'wordpress-tpl_g5_hydrogen_v$gantry.zip'), true, 'WordPress is installed with the Gantry 5 plugin and a Gantry theme from the same release: the framework is only measured through its theme.');
expect(str_contains($crossSetup, 'wp core download --version="$(version_value "$wanted" wordpress)"') && str_contains($crossSetup, 'wp core install'), true, 'WordPress core is installed through WP-CLI at the resolved release (a branch is cloned instead).');
expect(str_contains($crossSetup, 'git clone --depth=1 --branch "$(version_value "$wanted" wordpress)"'), true, 'A WordPress branch is installed from the build mirror.');
expect(str_contains($crossSetup, 'php _build/transport.core.php'), true, 'A MODX branch is built with the transport build.');
expect(str_contains($crossSetup, 'wordpress-seed.php') && str_contains($crossSetup, 'wordpress-admin-seed.php'), true, 'The WordPress install must seed the guest and admin fixtures.');
expect(str_contains($crossSetup, "permalink_structure '/%postname%'"), true, 'WordPress must serve the canonical URL without a trailing-slash redirect.');
expect(str_contains($crossSetup, "define('WP_HOME', 'http://' . (\$_SERVER['HTTP_HOST']"), true, 'The WordPress site URL must follow the Host header: the browser reaches the stack by its compose service name.');
expect(str_contains($crossSetup, "define('WP_HTTP_BLOCK_EXTERNAL', true)") && str_contains($crossSetup, "define('DISABLE_WP_CRON', true)"), true, 'WordPress must not reach wordpress.org or spawn cron during a timed step.');
expect(str_contains($crossSetup, 'wp-content/themes/g5_hydrogen/custom/views/'), true, 'The category view is a Gantry theme override (custom/views), rendered by the Gantry outline.');
$wordpressPlugin = repositoryFile('benchmark/implementations/wordpress-gantry/mu-plugins/phramark-benchmark.php');
expect(str_contains($wordpressPlugin, "add_filter('template_include'") && str_contains($wordpressPlugin, "remove_meta_box('dashboard_primary'"), true, 'The WordPress mu-plugin must route category pages to the Gantry template and drop the wordpress.org feed widget from the timed login.');
expect(str_contains($wordpressPlugin, "add_filter('wp_default_editor', static fn (): string => 'html')"), true, 'The WordPress page body is edited in the plain-text tab like the Drupal, Evolution and Winter fixtures.');
$wordpressTemplate = repositoryFile('benchmark/implementations/wordpress-gantry/mu-plugins/phramark-benchmark/category.php');
expect(str_contains($wordpressTemplate, "Timber::render(['phramark-category.html.twig']") && str_contains($wordpressTemplate, '$wpdb->get_results($wpdb->prepare('), true, 'The WordPress template must read through wpdb and render through Gantry (Timber/Twig), the way the theme renders a page.');
expect(str_contains(repositoryFile('benchmark/implementations/wordpress-gantry/theme-custom/views/phramark-category.html.twig'), '{% extends "partials/page.html.twig" %}'), true, 'The category view must extend the Gantry page partial so the outline renders.');
expect(str_contains(repositoryFile('benchmark/fixtures/cms/wordpress-seed.php'), "'page'") && str_contains(repositoryFile('benchmark/fixtures/cms/wordpress-seed.php'), 'ARTICLES_ROOT_ID + $category'), true, 'WordPress resolves one child page per category through its own page hierarchy.');
expect(str_contains(repositoryFile('benchmark/config/nginx-wordpress-gantry.conf'), 'absolute_redirect off') && str_contains(repositoryFile('benchmark/config/nginx-wordpress-gantry.conf'), '/index.php?$args'), true, 'The WordPress front controller must be reachable and its redirects keep the published port.');
expect(str_contains(repositoryFile('benchmark/images/cms-php/Dockerfile'), 'mysqli'), true, 'WordPress (wpdb) needs the mysqli extension.');
expect(str_contains(repositoryFile('benchmark/compose.yaml'), '8088:80') && str_contains(repositoryFile('benchmark/scripts/lib.sh'), '8088) stack=wordpress-gantry'), true, 'The WordPress + Gantry stack must be published and known to the scripts.');
expect(str_contains(repositoryFile('benchmark/fixtures/trace-prepend.php'), 'wp-content/(?:plugins|mu-plugins|themes)/[^/]+'), true, 'The trace must key the Gantry plugin apart from the rest of wp-content.');
expect(str_contains(repositoryFile('benchmark/fixtures/cms/typo3-admin-seed.php'), 'INSERT INTO tt_content (uid, pid'), true, 'TYPO3 content elements must share their page uid so one form edits both.');
expect(is_file(dirname(__DIR__) . '/benchmark/fixtures/setup-october-4.sh'), false, 'October CMS must stay removed.');
expect(str_contains(repositoryFile('benchmark/compose.yaml'), 'october'), false, 'October CMS must stay removed from compose.');

// Result set: the README tables and docs/results.json come from one collector.
expect(ResultSet::parseLatencyMs('263.17ms'), 263.17, 'wrk latencies in ms parse as ms.');
expect(ResultSet::parseLatencyMs('1.02s'), 1020.0, 'wrk latencies in seconds (saturation) convert to ms.');
expect(ResultSet::parseLatencyMs('850.00us'), 0.85, 'wrk latencies in microseconds convert to ms.');
expect(ResultSet::parseLatencyMs('-'), null, 'A missing latency is null, not zero.');
$resultsDir = sys_get_temp_dir() . '/phramark-results-' . getmypid();
mkdir($resultsDir);
file_put_contents($resultsDir . '/guest-modx-jit-off-rps-30.txt', "Running 1m test\n 50.000%  120.50ms\n 99.000%  1.02s\nRequests/sec:     30.10\nNon-2xx or 3xx responses: 3\n---- memory ----\nPHP memory (per request, allocator peak): median 4.0 MiB, p95 4.0 MiB, max 6.0 MiB over 1800 requests (3 non-2xx); script peak median 2.1 MiB\nPHP-FPM container peak RSS: 80.5 MiB (docker stats, 1 s samples)\n");
file_put_contents($resultsDir . '/guest-modx-jit-tracing-rps-30.txt', "Running 1m test\n");
touch($resultsDir . '/guest-modx-jit-off-rps-30.txt', 1_789_900_000); // an older, mtime-dated run
file_put_contents($resultsDir . '/admin-modx-jit-off-2026-09-20T10-00-00-000Z.json', json_encode(['stack' => 'modx', 'jit' => 'off', 'rounds' => 1, 'pages' => 5, 'recordedAt' => '2026-09-20T10:00:00.000Z', 'containerPeakMb' => 90.5, 'summary' => ['ms' => ['login' => ['median' => 700.5]], 'serverMs' => ['login' => ['median' => 300.25]], 'phpPeakMb' => ['login' => ['median' => 4.5]]], 'steps' => [['action' => 'login', 'ms' => 700.5], ['action' => 'logout', 'ms' => 200.25]]]));
file_put_contents($resultsDir . '/admin-modx-jit-off-2026-09-20T09-00-00-000Z.json', json_encode(['stack' => 'modx', 'jit' => 'off', 'recordedAt' => '2026-09-20T09:00:00.000Z', 'summary' => ['ms' => ['login' => ['median' => 999]]], 'steps' => [['action' => 'login', 'ms' => 999]]]));
file_put_contents($resultsDir . '/admin-modx-jit-off-2026-09-20T10-00-00-000Z.memory.json', '{}');
file_put_contents($resultsDir . '/versions.json', json_encode(['recordedAt' => '2026-09-20T13:21:02Z', 'runtime' => ['php' => '8.4.25', 'mysql' => '8.4.7', 'nginx' => 'nginx/1.27.5', 'opcache' => 'x'], 'stacks' => ['modx' => [['name' => 'MODX Revolution', 'version' => '3.2.4-pl', 'source' => 'https://github.com/modxcms/revolution'], ['name' => 'xpdo/xpdo', 'version' => 'v3.1.7']]]]));
// Repetitions of one cell (timestamped names) and the memory series next to them.
file_put_contents($resultsDir . '/guest-modx-jit-off-rps-30-2026-09-20T12-00-00Z.txt', "Running 1m test\n 50.000%  100.00ms\n 99.000%  200.00ms\nRequests/sec:     29.90\n\n---- memory ----\nPHP-FPM peak RSS: 60.0 MiB (cgroup anon, 1 s samples)\nPHP-FPM container footprint peak: 90.0 MiB (docker stats, page cache included, 1 s samples)\n");
file_put_contents($resultsDir . '/guest-modx-jit-off-rps-30-2026-09-20T12-00-00Z.memory.log', implode("\n", array_map(static fn (int $i): string => json_encode(['t' => 1000 + $i, 'step' => null, 'status' => 200, 'peak' => (1 + $i * 0.1) * 1048576, 'peak_real' => 2097152]), range(0, 59))) . "\n" . json_encode(['t' => 999, 'step' => 'warmup', 'status' => 200, 'peak' => 99 * 1048576, 'peak_real' => 1]) . "\n");
file_put_contents($resultsDir . '/guest-modx-jit-off-rps-30-2026-09-20T12-00-00Z.rss.log', "1000 100MiB 150MiB\n1002 101MiB -\n1005 0.1GiB 152MiB\n1030 104MiB 160MiB\n");
$resultSet = ResultSet::collect($resultsDir);
expect(array_keys($resultSet), ['generatedAt', 'stacks', 'versions', 'guest', 'admin'], 'The result set carries the stack profiles, the tested versions and both workloads.');
expect(count($resultSet['guest']), 2, 'One guest row per stack × JIT × rate, including a run without numbers.');
$guestRow = $resultSet['guest'][0];
expect($guestRow['file'], 'guest-modx-jit-off-rps-30-2026-09-20T12-00-00Z.txt', 'The row is the latest repetition (timestamped name after the mtime-dated one).');
expect($guestRow['recordedAt'], '2026-09-20T12:00:00Z', 'A timestamped name carries its run time.');
expect($guestRow['repeats']['n'], 2, 'Every repetition of a cell counts toward the spread.');
expect($guestRow['repeats']['metrics']['p50Ms'], ['median' => 110.25, 'min' => 100.0, 'max' => 120.5, 'cv' => 0.1315], 'The spread across repetitions is median, min, max and coefficient of variation.');
expect($guestRow['repeats']['metrics']['containerPeakMb'], ['median' => 60.0, 'min' => 60.0, 'max' => 60.0, 'cv' => null], 'A metric only one repetition recorded has no variation estimate.');
expect([$guestRow['containerPeakMb'], $guestRow['containerFootprintMb']], [60.0, 90.0], 'A run records the FPM RSS (cgroup anon) and, when asked, the docker footprint with the page cache.');
expect(ResultSet::guestRow($resultsDir . '/guest-modx-jit-off-rps-30.txt')['containerPeakMb'], null, 'A run from before the split has no RSS…');
expect(ResultSet::guestRow($resultsDir . '/guest-modx-jit-off-rps-30.txt')['containerFootprintMb'], 80.5, '…its docker figure is a footprint.');
expect(count($guestRow['series']['phpPeak']['points']), 40, 'The per-request memory log is cut into 40 time slices.');
expect([$guestRow['series']['phpPeak']['points'][0]['requests'], $guestRow['series']['phpPeak']['points'][0]['maxMb']], [2, 1.1], 'The first slice holds the earliest requests.');
expect($guestRow['series']['phpPeak']['trend'], ['slopeMbPerMin' => 6.0, 'firstQuarterMb' => 1.7, 'lastQuarterMb' => 6.2, 'growth' => 2.6471], 'A per-request peak that grows 0.1 MiB per second is a 6 MiB/min slope; warm-up requests are excluded.');
expect($guestRow['series']['containerRss']['points'], [['t' => 0, 'mb' => 100.0], ['t' => 2, 'mb' => 101.0], ['t' => 5, 'mb' => 102.4], ['t' => 30, 'mb' => 104.0]], 'RSS samples convert to MiB and sit on their timestamps.');
expect($guestRow['series']['containerFootprint']['points'], [['t' => 0, 'mb' => 150.0], ['t' => 5, 'mb' => 152.0], ['t' => 30, 'mb' => 160.0]], 'The footprint is the third column; a missing sample is skipped.');
expect(ResultSet::sampleSeries($resultsDir . '/guest-modx-jit-off-rps-30-2026-09-20T12-00-00Z.rss.log', 4), null, 'A column that is never there is no series.');
expect($guestRow['series']['containerRss']['trend']['slopeMbPerMin'] > 5 && $guestRow['series']['containerRss']['trend']['slopeMbPerMin'] < 9, true, '4 MiB over 30 s is about 8 MiB/min.');
file_put_contents($resultsDir . '/legacy.rss.log', "100MiB\n104MiB\n");
expect(ResultSet::sampleSeries($resultsDir . '/legacy.rss.log')['trend']['slopeMbPerMin'], null, 'Samples without timestamps get no slope: docker stats takes 1-3 s per sample.');
expect($resultSet['guest'][1]['series'], ['phpPeak' => null, 'containerRss' => null, 'containerFootprint' => null], 'A run without logs has no series, not an error.');
expect(ResultSet::trend([[0, 5.0], [1, 5.0], [2, 5.0]]), ['slopeMbPerMin' => 0.0, 'firstQuarterMb' => 5.0, 'lastQuarterMb' => 5.0, 'growth' => 0.0], 'A flat series has zero slope and growth.');
$guestRow = $resultSet['guest'][0];
$guestRow = ResultSet::guestRow($resultsDir . '/guest-modx-jit-off-rps-30.txt');
expect([$guestRow['stack'], $guestRow['jit'], $guestRow['rate'], $guestRow['rps'], $guestRow['p50Ms'], $guestRow['p99Ms'], $guestRow['errors'], $guestRow['requests'], $guestRow['phpScriptMedianMb'], $guestRow['phpAllocP95Mb'], $guestRow['phpAllocMaxMb'], $guestRow['containerFootprintMb']], ['modx', 'off', 30, 30.1, 120.5, 1020.0, 3, 1800, 2.1, 4.0, 6.0, 80.5], 'Guest rows must be numeric so the site can sort them.');
expect($resultSet['guest'][1]['p50Ms'], null, 'A guest run that produced no histogram has null numbers.');
expect(count($resultSet['admin']), 1, 'Only the latest admin run per stack × JIT is kept; .memory.json files are not reports.');
$adminRow = $resultSet['admin'][0];
expect([$adminRow['recordedAt'], $adminRow['totalWallMs'], $adminRow['containerPeakMb'], $adminRow['actions']['login']['ms'], $adminRow['actions']['login']['serverMs'], $adminRow['actions']['login']['phpPeakMb'], $adminRow['actions']['logout']['ms']], ['2026-09-20T10:00:00.000Z', 901.0, 90.5, 700.5, 300.25, 4.5, null], 'Admin rows carry the medians per action and metric, null where the run has none.');
expect($adminRow['repeats']['n'], 2, 'Both admin runs of the cell count toward the spread.');
expect($adminRow['repeats']['metrics']['login.ms'], ['median' => 849.75, 'min' => 700.5, 'max' => 999, 'cv' => 0.2484], 'The admin spread covers the wall time per action across repetitions.');
expect(array_column($adminRow['series']['steps'], 'action'), ['login', 'logout'], 'The ordered steps of the latest run are the memory-over-actions series.');
expect($adminRow['series']['phpPeak'], null, 'An admin run without a memory log has no per-request series.');
expect(isset($adminRow['steps']), false, 'Steps live under series only.');
expect($resultSet['versions']['stacks']['modx'][0]['version'], '3.2.4-pl', 'The tested versions are part of the result set.');
$markdown = ResultSet::markdown($resultSet);
expect(str_contains($markdown, '| modx (3.2.4-pl) | off | 30 | 29.9 | 100.00ms | 200.00ms | 0 | - | - | 60.0 MiB | 90.0 MiB |'), true, 'The guest Markdown row keeps the README format, names the build from the versions snapshot and shows the latest repetition.');
expect(str_contains($markdown, '| modx (3.2.4-pl) | tracing | 30 | - | - | - | 0 | - | - | - | - |'), true, 'A guest run without numbers renders dashes.');
expect(str_contains($markdown, '| modx (3.2.4-pl) | off | 700.5 / 300.25 ms | - / - ms | - / - ms | - / - ms | - / - ms | - / - ms | 901 ms |'), true, 'The admin Markdown row keeps the README format.');
expect(str_contains($markdown, '| modx (3.2.4-pl) | off | PHP peak (script) | 4.5 MiB | - | - | - | - | - | 90.5 MiB |'), true, 'The admin memory Markdown row keeps the README format.');
expect(str_contains($markdown, '## Versions tested') && str_contains($markdown, '[MODX Revolution](https://github.com/modxcms/revolution) 3.2.4-pl, xpdo/xpdo v3.1.7'), true, 'The Markdown must name the exact versions tested, linked to their repositories.');
expect(json_decode(json_encode($resultSet, JSON_THROW_ON_ERROR), true)['guest'][0]['repeats']['metrics']['p50Ms']['max'], 120.5, 'The result set must round-trip through JSON for the site.');
array_map('unlink', glob($resultsDir . '/*') ?: []);
rmdir($resultsDir);
expect(str_contains(repositoryFile('benchmark/scripts/matrix'), 'summary.php --json > docs/results.json') && str_contains(repositoryFile('benchmark/scripts/matrix'), 'versions.php'), true, 'A matrix run must record the versions and refresh the results site data.');
expect(trim(repositoryFile('docs/CNAME')), 'phramark.artur.work', 'The results site is published at phramark.artur.work.');
expect(str_contains(repositoryFile('benchmark/scripts/matrix'), 'REPEATS') && str_contains(repositoryFile('benchmark/scripts/run'), '-${stamp}.txt') && str_contains(repositoryFile('benchmark/scripts/run'), '.rss.log'), true, 'Cells can be repeated for an error estimate; every guest run keeps its own file and its RSS samples.');
expect(str_contains(repositoryFile('docs/site.js'), "from './charts.js?v=") && str_contains(repositoryFile('docs/charts.js'), 'export function renderSmallMultiples'), true, 'The results site draws the memory series as small multiples.');
expect(preg_match('/src="site\.js\?v=[0-9A-Za-z]+"/', repositoryFile('docs/index.html')) === 1 && str_contains(repositoryFile('docs/site.js'), "fetch('results.json'"), true, 'The results site renders docs/results.json with a version-stamped docs/site.js.');
expect(preg_match("/from '\.\/charts\.js\?v=[0-9A-Za-z]+'/", repositoryFile('docs/site.js')), 1, 'site.js imports a version-stamped charts.js.');
$stamped = ResultSet::stampScripts('2026-09-21T22:07:57Z', '<script type="module" src="site.js?v=old"></script>', "import { a } from './charts.js';\nimport { b } from './other.js';");
expect($stamped, ['index.html' => '<script type="module" src="site.js?v=20260921T220757Z"></script>', 'site.js' => "import { a } from './charts.js?v=20260921T220757Z';\nimport { b } from './other.js';"], 'A results build stamps its time into the script URLs (cache-busting for GitHub Pages).');
expect(ResultSet::stampScripts('2026-09-21T22:07:57Z', '<script type="module" src="site.js?v=20260921T220757Z"></script>', "from './charts.js?v=20260921T220757Z'"), [], 'Already stamped files are left alone.');
expect(str_contains(repositoryFile('benchmark/scripts/summary.php'), 'ResultSet::stampScripts($set[\'generatedAt\']'), true, 'summary.php --json stamps the site scripts.');
expect(str_contains(repositoryFile('README.md'), 'https://phramark.artur.work'), true, 'The main README must link to the results site.');
foreach (['https://github.com/evolution-cms/evolution', 'https://github.com/elcreator/aLatteX', 'https://github.com/elcreator/aPhalcon', 'https://github.com/drupal/core', 'https://github.com/TYPO3/typo3', 'https://github.com/wintercms/winter', 'https://github.com/modxcms/revolution', 'https://github.com/WordPress/WordPress', 'https://github.com/gantry/gantry5', 'https://github.com/WordPress/classic-editor'] as $repository) {
    expect(str_contains(repositoryFile('README.md'), $repository), true, sprintf('The main README must link the tested repository %s.', $repository));
}

try {
    FixturePlan::categoryAlias(0);
    throw new RuntimeException('Out-of-range categories must be rejected.');
} catch (InvalidArgumentException) {
}

try {
    RuntimeProfile::jitMode('unknown');
    throw new RuntimeException('Unsupported JIT modes must be rejected.');
} catch (InvalidArgumentException) {
}

try {
    RuntimeProfile::resultName('editor', 'evo-parser', 'off', 'x');
    throw new RuntimeException('Unknown workloads must be rejected.');
} catch (InvalidArgumentException) {
}

// Manticore workload: the in-memory category page must honour the same
// contract and fixture rules as the CMS stacks, so a native-vs-PHP number
// describes the same page assembly.
define('PHRAMARK_WORKLOAD_LIB', true);
require dirname(__DIR__) . '/benchmark/workloads/manticore/category-page.php';
$manticorePage = CategoryPageWorkload::renderCategory(42);
foreach (['Phramark benchmark', 'class="intro"', 'class="articles"', 'class="meta"', 'class="author"', 'class="reading-time"', 'Deterministic CMS benchmark fixture'] as $marker) {
    expect(str_contains($manticorePage, $marker), true, 'The Manticore workload page is missing ' . $marker);
}
$manticoreContract = FixturePlan::expectedCategoryPage(42);
expect(str_contains($manticorePage, '<title>' . $manticoreContract['title'] . '</title>'), true, 'The Manticore workload must render the fixture category title.');
expect(substr_count($manticorePage, '<article>'), $manticoreContract['articles'], 'The Manticore workload must render 20 article cards.');
expect(substr_count($manticorePage, '<img src="/assets/images/article-'), $manticoreContract['hero_images'], 'Every Manticore workload card carries a hero image.');
expect(substr_count($manticorePage, '<span class="author">Author '), $manticoreContract['authors'], 'Manticore workload authors must follow the fixture TV presence.');
expect(substr_count($manticorePage, '<span class="reading-time"> min</span>'), $manticoreContract['articles'] - $manticoreContract['reading_times'], 'Manticore workload reading times must follow the fixture TV presence.');
foreach ([1, 4213, 10_000] as $articleNumber) {
    $manticoreTvs = CategoryPageWorkload::templateVariables($articleNumber);
    foreach (FixturePlan::tvPresence($articleNumber) as $name => $present) {
        expect(isset($manticoreTvs[$name]), $present, sprintf('TV %s presence of article %d differs between the Manticore workload and the fixture plan.', $name, $articleNumber));
    }
}
expect(str_contains($manticorePage, 'href="/articles/category-042/article-004101"'), true, 'Manticore workload article links must follow the fixture aliases.');
expect(CategoryPageWorkload::run(3), CategoryPageWorkload::run(3), 'The Manticore workload checksum must be deterministic.');
expect(CategoryPageWorkload::run(3)['bytes'], strlen(CategoryPageWorkload::renderCategory(1)) + strlen(CategoryPageWorkload::renderCategory(2)) + strlen(CategoryPageWorkload::renderCategory(3)), 'The Manticore workload must cycle through categories in order.');
expect(CategoryPageWorkload::run(101)['bytes'] > 0 && CategoryPageWorkload::run(1)['checksum'] < 1_000_000_007, true, 'The Manticore workload checksum must stay below its modulus.');

// Manticore compile report summary: areas and normalised diagnostics.
require dirname(__DIR__) . '/benchmark/scripts/manticore-summary.php';
$manticoreRows = array_map('parseLine', [
    "ok\t/site/core/src/Core.php\t1.20\t",
    "fail\t/site/core/src/Legacy/Cache.php\t0.70\tcompile failed: MIR.verify: dangling local \$modx read in __main but never defined",
    "fail\t/site/core/functions/nodes.php\t0.50\tcompile failed: MIR.verify: dangling local \$_lang read in ls but never defined",
    "ok\t/site/core/vendor/illuminate/support/Str.php\t2.60\t",
    "fail\t/site/manager/index.php\t0.10\t/site/manager/index.php: parse failed: expected ';' after echo at line 1, column 66",
    "timeout\t/site/core/lang/ru/global.php\t30.00\t",
]);
expect($manticoreRows[0]['area'], 'core/src', 'Evolution core files are grouped per core directory.');
expect($manticoreRows[3]['area'], 'core/vendor (illuminate)', 'Vendor files are grouped per Composer vendor.');
expect($manticoreRows[1]['diagnostic'], $manticoreRows[2]['diagnostic'], 'Variable and function names must not split one diagnostic class.');
expect($manticoreRows[4]['diagnostic'], "parse failed: expected ';' after echo at line N", 'File paths and positions are stripped from diagnostics.');
expect($manticoreRows[5]['diagnostic'], '(no diagnostic; timeout or non-zero exit)', 'Timeouts carry a placeholder diagnostic.');
$manticoreSummary = summarise($manticoreRows);
expect([$manticoreSummary['total'], $manticoreSummary['ok'], $manticoreSummary['fail'], $manticoreSummary['timeout']], [6, 2, 3, 1], 'Summary counts changed.');
expect($manticoreSummary['areas']['core/src'], ['files' => 2, 'ok' => 1], 'Per-area counts changed.');
expect(array_key_first($manticoreSummary['diagnostics']), 'compile failed: MIR.verify: dangling local $var read in <fn> but never defined', 'Diagnostics are sorted by frequency.');
expect(parseLine("ok\t/site/core/src/Core.php\t0.00\t\tcached")['cached'], true, 'The origin column marks verdicts taken from the result cache.');
expect(parseLine("fail\t/site/a.php\t0.10\tparse failed: x at line 1, column 2\tcompiled")['diagnostic'], 'parse failed: x at line N', 'The origin column must not leak into the diagnostic.');
expect(summarise([parseLine("ok\t/site/a.php\t0.00\t\tcached"), parseLine("ok\t/site/b.php\t1.00\t\tcompiled")])['cached'], 1, 'Cached verdicts are counted.');

// Manticore result cache: a verdict is keyed by content hash and toolchain,
// survives a path change, and misses on a content or toolchain change.
require dirname(__DIR__) . '/benchmark/scripts/manticore-cache.php';
$slashes = fn (string $path): string => str_replace('\\', '/', $path);
$cacheDir = $slashes(sys_get_temp_dir()) . '/phramark-cache-' . getmypid();
mkdir($cacheDir . '/tree/sub', 0777, true);
file_put_contents($cacheDir . '/tree/a.php', "<?php echo 1;\n");
file_put_contents($cacheDir . '/tree/sub/b.php', "<?php echo 2;\n");
file_put_contents($cacheDir . '/tree/notes.txt', 'not php');
$cacheFile = $cacheDir . '/compile-cache.tsv';
$toolchain = 'manticore 0.10.0 | clang 19.1.7';
$slashes = fn (string $path): string => str_replace('\\', '/', $path);
expect(array_map($slashes, phpFiles($cacheDir . '/tree')), [$cacheDir . '/tree/a.php', $cacheDir . '/tree/sub/b.php'], 'Only PHP files are planned, in sorted order.');
$cold = plan(loadCache($cacheFile), $toolchain, phpFiles($cacheDir . '/tree'));
expect([$cold['hits'], count($cold['misses'])], [[], 2], 'An absent cache misses everything.');
$report = [
    "ok\t" . $cacheDir . "/tree/a.php\t1.20\t\tcompiled",
    "fail\t" . $cacheDir . "/tree/sub/b.php\t0.30\tparse failed: expected ';' after echo at line 1, column 5\tcompiled",
];
saveCache($cacheFile, merge(loadCache($cacheFile), $toolchain, $report));
expect(count(loadCache($cacheFile)), 2, 'Compiled verdicts enter the cache.');
rename($cacheDir . '/tree/a.php', $cacheDir . '/tree/sub/renamed.php');
$warm = plan(loadCache($cacheFile), $toolchain, phpFiles($cacheDir . '/tree'));
expect($warm['misses'], [], 'Unchanged content hits regardless of its path.');
expect($slashes($warm['hits'][0]), "fail\t" . $cacheDir . "/tree/sub/b.php\t0.00\tparse failed: expected ';' after echo at line 1, column 5\tcached", 'A hit replays status and diagnostic and is marked cached.');
expect(count(plan(loadCache($cacheFile), 'manticore 0.11.0 | clang 19.1.7', phpFiles($cacheDir . '/tree'))['misses']), 2, 'Another toolchain misses.');
file_put_contents($cacheDir . '/tree/sub/b.php', "<?php echo 3;\n");
expect(array_map($slashes, plan(loadCache($cacheFile), $toolchain, phpFiles($cacheDir . '/tree'))['misses']), [$cacheDir . '/tree/sub/b.php'], 'Changed content misses.');
saveCache($cacheFile, merge(loadCache($cacheFile), $toolchain, ["ok\t" . $cacheDir . "/tree/sub/b.php\t0.50\t\tcompiled", "ok\t" . $cacheDir . "/tree/sub/renamed.php\t0.00\t\tcached"]));
expect(count(loadCache($cacheFile)), 3, 'A new content hash is added; cached rows are not re-entered.');
foreach (['/tree/sub/renamed.php', '/tree/sub/b.php', '/tree/notes.txt', '/compile-cache.tsv'] as $file) {
    unlink($cacheDir . $file);
}
rmdir($cacheDir . '/tree/sub');
rmdir($cacheDir . '/tree');
rmdir($cacheDir);

fwrite(STDOUT, "Phramark tests passed.\n");
