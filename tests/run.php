<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/FixturePlan.php';
require dirname(__DIR__) . '/src/RuntimeProfile.php';
require dirname(__DIR__) . '/src/PageContract.php';
require dirname(__DIR__) . '/src/ResultSet.php';

use Phramark\FixturePlan;
use Phramark\PageContract;
use Phramark\ResultSet;
use Phramark\RuntimeProfile;

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
expect(RuntimeProfile::jitMode('off'), ['opcache_jit' => '0', 'jit_buffer_size' => '0'], 'JIT-off profile changed.');
expect(RuntimeProfile::jitMode('tracing'), ['opcache_jit' => 'tracing', 'jit_buffer_size' => '128M'], 'JIT profile changed.');
expect(RuntimeProfile::resultName('admin', 'evo-latte', 'tracing', '2026-01-01'), 'admin-evo-latte-jit-tracing-2026-01-01', 'Result names must carry workload, stack and JIT mode.');
expect(RuntimeProfile::adapters()['drupal-11']['components'], ['Drupal core', 'Symfony', 'Twig'], 'Drupal must be classified by the components its request path executes.');
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
foreach (['evo-parser', 'evo-latte', 'evo-latte-parser', 'evo-phalcon', 'drupal-11', 'typo3', 'winter', 'modx', 'wordpress-gantry'] as $adapter) {
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
    'benchmark/implementations/drupal-11/modules/custom/phramark_benchmark/templates/category-page.html.twig',
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
expect(str_contains($setup, "SHOW TABLES LIKE 'site_site_content'"), true, 'Fixture setup must be safely repeatable for each JIT run.');
expect(str_contains($setup, 'Phramark benchmark'), true, 'The parser workload must implement the shared visible page contract.');
expect(str_contains($setup, 'site_tmplvar_templates'), true, 'TVs must be assigned to the benchmark template or Evolution renders empty cards.');
expect(str_contains($setup, "'content' => '[[benchmarkCategory]]'"), true, 'The parser template must not wrap the page contract.');
expect(str_contains($setup, "'seostrict' => 0"), true, 'Evolution must answer the canonical URL without a redirect.');
expect(str_contains(repositoryFile('benchmark/implementations/evo-latte/core/custom/config/alattex.php'), "'evo_tags' => false"), true, 'evo-latte measures the Latte view without the EVO pass.');
expect(str_contains(repositoryFile('benchmark/implementations/evo-latte-parser/core/custom/config/alattex.php'), "'evo_tags' => true"), true, 'evo-latte-parser measures the Latte view with the EVO pass.');
expect(repositoryFile('benchmark/implementations/evo-latte-parser/views/benchmark-category.latte'), repositoryFile('benchmark/implementations/evo-latte/views/benchmark-category.latte'), 'Both Latte stacks must render the identical view; only the pass differs.');
expect(RuntimeProfile::adapters()['evo-latte-parser']['port'], 8085, 'evo-latte-parser takes the port October used.');
expect(str_contains(repositoryFile('benchmark/workloads/category.lua'), '"/articles/category-%03d"'), true, 'The load workload must use the canonical URL form.');
expect(str_contains(repositoryFile('benchmark/implementations/drupal-11/modules/custom/phramark_benchmark/phramark_benchmark.routing.yml'), "path: '/articles/{category}'"), true, 'Drupal placeholders must span a whole path segment.');
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
expect(str_contains($crossSetup, 'composer create-project wintercms/winter'), true, 'Winter is installed from its licence-free Composer distribution.');
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
expect(str_contains($crossSetup, 'wordpress-pkg_gantry5_v$GANTRY_VERSION.zip') && str_contains($crossSetup, 'wordpress-tpl_g5_hydrogen_v$GANTRY_VERSION.zip'), true, 'WordPress is installed with the Gantry 5 plugin and a Gantry theme from the same release: the framework is only measured through its theme.');
expect(str_contains($crossSetup, 'wp core download --version="$WORDPRESS_VERSION"') && str_contains($crossSetup, 'wp core install'), true, 'WordPress core is installed through WP-CLI at a pinned version.');
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
file_put_contents($resultsDir . '/guest-modx-jit-off-rps-30-2026-09-20T12-00-00Z.txt', "Running 1m test\n 50.000%  100.00ms\n 99.000%  200.00ms\nRequests/sec:     29.90\n");
file_put_contents($resultsDir . '/guest-modx-jit-off-rps-30-2026-09-20T12-00-00Z.memory.log', implode("\n", array_map(static fn (int $i): string => json_encode(['t' => 1000 + $i, 'step' => null, 'status' => 200, 'peak' => (1 + $i * 0.1) * 1048576, 'peak_real' => 2097152]), range(0, 59))) . "\n" . json_encode(['t' => 999, 'step' => 'warmup', 'status' => 200, 'peak' => 99 * 1048576, 'peak_real' => 1]) . "\n");
file_put_contents($resultsDir . '/guest-modx-jit-off-rps-30-2026-09-20T12-00-00Z.rss.log', "1000 100MiB\n1002 101MiB\n1005 0.1GiB\n1030 104MiB\n");
$resultSet = ResultSet::collect($resultsDir);
expect(array_keys($resultSet), ['generatedAt', 'stacks', 'versions', 'guest', 'admin'], 'The result set carries the stack profiles, the tested versions and both workloads.');
expect(count($resultSet['guest']), 2, 'One guest row per stack × JIT × rate, including a run without numbers.');
$guestRow = $resultSet['guest'][0];
expect($guestRow['file'], 'guest-modx-jit-off-rps-30-2026-09-20T12-00-00Z.txt', 'The row is the latest repetition (timestamped name after the mtime-dated one).');
expect($guestRow['recordedAt'], '2026-09-20T12:00:00Z', 'A timestamped name carries its run time.');
expect($guestRow['repeats']['n'], 2, 'Every repetition of a cell counts toward the spread.');
expect($guestRow['repeats']['metrics']['p50Ms'], ['median' => 110.25, 'min' => 100.0, 'max' => 120.5, 'cv' => 0.1315], 'The spread across repetitions is median, min, max and coefficient of variation.');
expect($guestRow['repeats']['metrics']['containerPeakMb'], ['median' => 80.5, 'min' => 80.5, 'max' => 80.5, 'cv' => null], 'A metric only one repetition recorded has no variation estimate.');
expect(count($guestRow['series']['phpPeak']['points']), 40, 'The per-request memory log is cut into 40 time slices.');
expect([$guestRow['series']['phpPeak']['points'][0]['requests'], $guestRow['series']['phpPeak']['points'][0]['maxMb']], [2, 1.1], 'The first slice holds the earliest requests.');
expect($guestRow['series']['phpPeak']['trend'], ['slopeMbPerMin' => 6.0, 'firstQuarterMb' => 1.7, 'lastQuarterMb' => 6.2, 'growth' => 2.6471], 'A per-request peak that grows 0.1 MiB per second is a 6 MiB/min slope; warm-up requests are excluded.');
expect($guestRow['series']['containerRss']['points'], [['t' => 0, 'mb' => 100.0], ['t' => 2, 'mb' => 101.0], ['t' => 5, 'mb' => 102.4], ['t' => 30, 'mb' => 104.0]], 'RSS samples convert to MiB and sit on their timestamps.');
expect($guestRow['series']['containerRss']['trend']['slopeMbPerMin'] > 5 && $guestRow['series']['containerRss']['trend']['slopeMbPerMin'] < 9, true, '4 MiB over 30 s is about 8 MiB/min.');
file_put_contents($resultsDir . '/legacy.rss.log', "100MiB\n104MiB\n");
expect(ResultSet::sampleSeries($resultsDir . '/legacy.rss.log')['trend']['slopeMbPerMin'], null, 'Samples without timestamps get no slope: docker stats takes 1-3 s per sample.');
expect($resultSet['guest'][1]['series'], ['phpPeak' => null, 'containerRss' => null], 'A run without logs has no series, not an error.');
expect(ResultSet::trend([[0, 5.0], [1, 5.0], [2, 5.0]]), ['slopeMbPerMin' => 0.0, 'firstQuarterMb' => 5.0, 'lastQuarterMb' => 5.0, 'growth' => 0.0], 'A flat series has zero slope and growth.');
$guestRow = $resultSet['guest'][0];
$guestRow = ResultSet::guestRow($resultsDir . '/guest-modx-jit-off-rps-30.txt');
expect([$guestRow['stack'], $guestRow['jit'], $guestRow['rate'], $guestRow['rps'], $guestRow['p50Ms'], $guestRow['p99Ms'], $guestRow['errors'], $guestRow['requests'], $guestRow['phpScriptMedianMb'], $guestRow['phpAllocP95Mb'], $guestRow['phpAllocMaxMb'], $guestRow['containerPeakMb']], ['modx', 'off', 30, 30.1, 120.5, 1020.0, 3, 1800, 2.1, 4.0, 6.0, 80.5], 'Guest rows must be numeric so the site can sort them.');
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
expect(str_contains($markdown, '| modx | off | 30 | 29.9 | 100.00ms | 200.00ms | 0 | - | - | - |'), true, 'The guest Markdown row keeps the README format and shows the latest repetition.');
expect(str_contains($markdown, '| modx | tracing | 30 | - | - | - | 0 | - | - | - |'), true, 'A guest run without numbers renders dashes.');
expect(str_contains($markdown, '| modx | off | 700.5 / 300.25 ms | - / - ms | - / - ms | - / - ms | - / - ms | - / - ms | 901 ms |'), true, 'The admin Markdown row keeps the README format.');
expect(str_contains($markdown, '| modx | off | PHP peak (script) | 4.5 MiB | - | - | - | - | - | 90.5 MiB |'), true, 'The admin memory Markdown row keeps the README format.');
expect(str_contains($markdown, '## Versions tested') && str_contains($markdown, '[MODX Revolution](https://github.com/modxcms/revolution) 3.2.4-pl, xpdo/xpdo v3.1.7'), true, 'The Markdown must name the exact versions tested, linked to their repositories.');
expect(json_decode(json_encode($resultSet, JSON_THROW_ON_ERROR), true)['guest'][0]['repeats']['metrics']['p50Ms']['max'], 120.5, 'The result set must round-trip through JSON for the site.');
array_map('unlink', glob($resultsDir . '/*') ?: []);
rmdir($resultsDir);
expect(str_contains(repositoryFile('benchmark/scripts/matrix'), 'summary.php --json > docs/results.json') && str_contains(repositoryFile('benchmark/scripts/matrix'), 'versions.php'), true, 'A matrix run must record the versions and refresh the results site data.');
expect(trim(repositoryFile('docs/CNAME')), 'phramark.artur.work', 'The results site is published at phramark.artur.work.');
expect(str_contains(repositoryFile('benchmark/scripts/matrix'), 'REPEATS') && str_contains(repositoryFile('benchmark/scripts/run'), '-${stamp}.txt') && str_contains(repositoryFile('benchmark/scripts/run'), '.rss.log'), true, 'Cells can be repeated for an error estimate; every guest run keeps its own file and its RSS samples.');
expect(str_contains(repositoryFile('docs/site.js'), "from './charts.js'") && str_contains(repositoryFile('docs/charts.js'), 'export function renderSmallMultiples'), true, 'The results site draws the memory series as small multiples.');
expect(str_contains(repositoryFile('docs/index.html'), 'src="site.js"') && str_contains(repositoryFile('docs/site.js'), "fetch('results.json'"), true, 'The results site renders docs/results.json with docs/site.js.');
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

fwrite(STDOUT, "Phramark tests passed.\n");
