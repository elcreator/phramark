<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/FixturePlan.php';
require dirname(__DIR__) . '/src/RuntimeProfile.php';
require dirname(__DIR__) . '/src/PageContract.php';

use Phramark\FixturePlan;
use Phramark\PageContract;
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
expect(RuntimeProfile::traceMismatch('evo-phalcon', $phalconTrace), ['missing' => [], 'unexpected' => []], 'The evo-phalcon label must match its traced request path.');
expect(RuntimeProfile::traceMismatch('evo-phalcon', $evolutionTrace), ['missing' => ['Phalcon (extension)', 'Latte'], 'unexpected' => []], 'A trace without Phalcon must be reported as a label mismatch.');
foreach (['evo-parser', 'evo-latte', 'evo-latte-parser', 'evo-phalcon', 'drupal-11', 'typo3'] as $adapter) {
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
] as $template) {
    expectTemplateContract(dirname(__DIR__) . '/' . $template);
}

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
foreach (['evolution', 'drupal', 'typo3'] as $adapter) {
    $source = repositoryFile('benchmark/workloads/admin/lib/adapters/' . $adapter . '.mjs');
    foreach (['pageIds()', 'async login()', 'async openEditor(', 'async openCreator()', 'async save(', 'async savedTitle()', 'async savedContent()', 'async logout()'] as $method) {
        expect(str_contains($source, $method), true, sprintf('The %s adapter must implement %s.', $adapter, $method));
    }
}
$index = repositoryFile('benchmark/workloads/admin/lib/adapters/index.mjs');
expect(str_contains($index, 'DrupalAdapter') && str_contains($index, 'Typo3Adapter') && str_contains($index, 'EvolutionAdapter'), true, 'All three admin adapters must be registered.');
expect(str_contains(repositoryFile('benchmark/workloads/admin/tests/admin.spec.mjs'), 'X-Phramark-Step'), true, 'Admin steps must be attributable in the PHP memory log.');
expect(str_contains(repositoryFile('benchmark/workloads/admin/tests/admin.spec.mjs'), 'frontend.snapshot()'), true, 'Admin steps must record frontend memory.');
expect(str_contains(repositoryFile('benchmark/config/php.ini'), 'auto_prepend_file=/opt/phramark/benchmark/fixtures/memory-prepend.php'), true, 'The PHP memory probe must be on for every stack.');
expect(str_contains(repositoryFile('benchmark/fixtures/memory-prepend.php'), 'memory_get_peak_usage(true)'), true, 'The PHP memory probe must record the allocator peak.');
expect(str_contains(repositoryFile('benchmark/scripts/run'), 'memory-summary.php'), true, 'Guest results must include the PHP memory summary.');
expect(str_contains(repositoryFile('benchmark/scripts/admin'), 'merge-memory.mjs'), true, 'Admin results must merge the PHP memory summary.');
$crossSetup = repositoryFile('benchmark/fixtures/setup-cross-cms.sh');
expect(str_contains($crossSetup, 'drupal-admin-seed.php') && str_contains($crossSetup, 'typo3-admin-seed.php'), true, 'Cross-CMS installs must seed the admin fixture.');
expect(str_contains($crossSetup, "trustedHostsPattern'] = '.*'"), true, 'TYPO3 must trust the compose-network host the load generator uses.');
expect(str_contains(repositoryFile('benchmark/fixtures/cms/typo3-admin-seed.php'), 'INSERT INTO tt_content (uid, pid'), true, 'TYPO3 content elements must share their page uid so one form edits both.');
expect(is_file(dirname(__DIR__) . '/benchmark/fixtures/setup-october-4.sh'), false, 'October CMS must stay removed.');
expect(str_contains(repositoryFile('benchmark/compose.yaml'), 'october'), false, 'October CMS must stay removed from compose.');

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
