<?php

declare(strict_types=1);

use Phramark\FixturePlan;

require '/opt/phramark/src/FixturePlan.php';

const PREFIX = 'site_';
const ROOT = '/sites';

/** @param list<string> $command */
function run(array $command, ?string $cwd = null): void
{
    $rendered = implode(' ', array_map('escapeshellarg', $command));
    $process = proc_open($rendered, [STDIN, STDOUT, STDERR], $pipes, $cwd);
    if (!is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Command failed: ' . $rendered);
    }
}

function rootPdo(?string $database = null): PDO
{
    $suffix = $database === null ? '' : ';dbname=' . $database;

    return new PDO(
        'mysql:host=' . getenv('DB_HOST') . $suffix,
        'root',
        (string) getenv('DB_ROOT_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function install(string $name): void
{
    $path = ROOT . '/' . $name;
    $database = 'benchmark_' . str_replace('-', '_', $name);

    $schema = rootPdo($database);
    $existing = $schema->query("SHOW TABLES LIKE 'site_site_content'")->fetchColumn();
    if ($existing !== false || is_file($path . '/.phramark-installed')) {
        return;
    }

    // Evolution keeps the core vendor tree in its source distribution. Cloning
    // the supported 3.5 branch avoids Composer's post-create script relocating
    // that tree before the CLI installer runs.
    run(['git', 'clone', '--depth=1', '--branch', '3.5.x', 'https://github.com/evolution-cms/evolution.git', $path]);
    run([
        'php', 'cli-install.php', '--typeInstall=1', '--databaseType=mysql', '--databaseServer=' . getenv('DB_HOST'),
        '--database=' . $database, '--databaseUser=' . getenv('DB_USER'), '--databasePassword=' . getenv('DB_PASSWORD'),
        '--tablePrefix=' . PREFIX, '--cmsAdmin=benchmark', '--cmsAdminEmail=benchmark@example.test',
        '--cmsPassword=' . getenv('EVO_ADMIN_PASSWORD'), '--language=en', '--removeInstall=y',
    ], $path . '/install');
    touch($path . '/.phramark-installed');
}

function installPackage(string $site, string $package): void
{
    $path = ROOT . '/' . $site;
    run(['php', 'artisan', 'package:installrequire', $package, '*'], $path . '/core');
}

function createDatabases(): void
{
    $pdo = rootPdo();
    foreach (['canonical', 'evo_parser', 'evo_latte', 'evo_latte_parser', 'evo_phalcon'] as $name) {
        $pdo->exec(sprintf('CREATE DATABASE IF NOT EXISTS `benchmark_%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $name));
        $pdo->exec(sprintf("GRANT ALL PRIVILEGES ON `benchmark_%s`.* TO 'benchmark'@'%%'", $name));
    }
}

function columns(PDO $pdo, string $table): array
{
    $rows = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);

    return array_column($rows, 'Field');
}

/** @param array<string, scalar|null> $record */
function insert(PDO $pdo, string $table, array $record, array $available): void
{
    $record = array_intersect_key($record, array_flip($available));
    $fields = array_keys($record);
    $sql = sprintf('INSERT INTO `%s` (%s) VALUES (%s)', $table, implode(',', array_map(fn ($field) => '`' . $field . '`', $fields)), implode(',', array_fill(0, count($fields), '?')));
    $pdo->prepare($sql)->execute(array_values($record));
}

function seedCanonical(): void
{
    $pdo = rootPdo('benchmark_canonical');
    $contentTable = PREFIX . 'site_content';
    $closureTable = PREFIX . 'site_content_closure';
    $templateTable = PREFIX . 'site_templates';
    $tvTable = PREFIX . 'site_tmplvars';
    $tvValueTable = PREFIX . 'site_tmplvar_contentvalues';
    $tvTemplateTable = PREFIX . 'site_tmplvar_templates';
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ([$tvValueTable, $tvTemplateTable, $tvTable, $closureTable, $contentTable, $templateTable] as $table) {
        $pdo->exec('TRUNCATE TABLE `' . $table . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    $contentColumns = columns($pdo, $contentTable);
    $templateColumns = columns($pdo, $templateTable);
    $tvColumns = columns($pdo, $tvTable);
    $tvValueColumns = columns($pdo, $tvValueTable);
    $now = 1_704_067_200;
    $pdo->beginTransaction();
    // The snippet emits the whole shared page contract, so the template must
    // not wrap it: every stack has to serve byte-comparable markup.
    insert($pdo, $templateTable, ['id' => 10, 'templatename' => 'Benchmark category', 'templatealias' => 'benchmark-category', 'content' => '[[benchmarkCategory]]', 'description' => 'Phramark category benchmark'], $templateColumns);
    insert($pdo, $contentTable, ['id' => 1, 'type' => 'document', 'contentType' => 'text/html', 'pagetitle' => 'Benchmark home', 'alias' => 'benchmark', 'parent' => 0, 'isfolder' => 1, 'alias_visible' => 1, 'published' => 1, 'deleted' => 0, 'template' => 10, 'createdon' => $now, 'editedon' => $now, 'menuindex' => 0], $contentColumns);
    insert($pdo, $contentTable, ['id' => 2, 'type' => 'document', 'contentType' => 'text/html', 'pagetitle' => 'Articles', 'alias' => 'articles', 'parent' => FixturePlan::ARTICLES_ROOT_PARENT, 'isfolder' => 1, 'alias_visible' => 1, 'published' => 1, 'deleted' => 0, 'template' => 10, 'createdon' => $now, 'editedon' => $now, 'menuindex' => 0], $contentColumns);
    $closure = $pdo->prepare('INSERT INTO `' . $closureTable . '` (ancestor, descendant, depth) VALUES (?, ?, ?)');
    $closure->execute([1, 1, 0]);
    $closure->execute([2, 2, 0]);

    $tvIds = [];
    foreach (array_keys(FixturePlan::tvPresence(1)) as $index => $name) {
        $id = $index + 1;
        $tvIds[$name] = $id;
        insert($pdo, $tvTable, ['id' => $id, 'name' => $name, 'caption' => ucwords(str_replace('_', ' ', $name)), 'type' => 'text', 'elements' => '', 'default_text' => '', 'category' => 0, 'rank' => $id, 'display' => 'default'], $tvColumns);
        // Evolution only resolves a TV for a document when it is assigned to
        // the document's template; without this row getTemplateVarOutput()
        // returns nothing and the parser and Latte stacks render empty cards.
        $pdo->prepare('INSERT INTO `' . $tvTemplateTable . '` (tmplvarid, templateid, `rank`) VALUES (?, 10, ?)')->execute([$id, $id]);
    }

    // Admin workload fixture: one folder with a fixed set of editable pages,
    // kept apart from the guest category workload so edits never change it.
    insert($pdo, $contentTable, ['id' => FixturePlan::ADMIN_ROOT_ID, 'type' => 'document', 'contentType' => 'text/html', 'pagetitle' => 'Admin workload', 'alias' => FixturePlan::ADMIN_ROOT_ALIAS, 'parent' => 0, 'isfolder' => 1, 'alias_visible' => 1, 'published' => 1, 'deleted' => 0, 'template' => 10, 'createdon' => $now, 'editedon' => $now, 'menuindex' => 1], $contentColumns);
    $closure->execute([FixturePlan::ADMIN_ROOT_ID, FixturePlan::ADMIN_ROOT_ID, 0]);
    for ($page = 1; $page <= FixturePlan::ADMIN_PAGE_COUNT; $page++) {
        $pageId = FixturePlan::adminPageId($page);
        insert($pdo, $contentTable, ['id' => $pageId, 'type' => 'document', 'contentType' => 'text/html', 'pagetitle' => FixturePlan::adminPageTitle($page), 'longtitle' => FixturePlan::adminPageTitle($page), 'content' => FixturePlan::adminPageContent($page), 'alias' => FixturePlan::adminPageAlias($page), 'parent' => FixturePlan::ADMIN_ROOT_ID, 'isfolder' => 0, 'alias_visible' => 1, 'published' => 1, 'deleted' => 0, 'template' => 10, 'createdon' => $now, 'editedon' => $now, 'menuindex' => $page], $contentColumns);
        $closure->execute([$pageId, $pageId, 0]);
        $closure->execute([FixturePlan::ADMIN_ROOT_ID, $pageId, 1]);
    }

    $articleNumber = 0;
    for ($category = 1; $category <= FixturePlan::CATEGORY_COUNT; $category++) {
        $categoryId = 2 + $category;
        $categoryTitle = FixturePlan::categoryTitle($category);
        insert($pdo, $contentTable, ['id' => $categoryId, 'type' => 'document', 'contentType' => 'text/html', 'pagetitle' => $categoryTitle, 'longtitle' => $categoryTitle, 'description' => FixturePlan::categoryDescription(), 'introtext' => FixturePlan::categoryIntrotext(), 'alias' => FixturePlan::categoryAlias($category), 'parent' => 2, 'isfolder' => 1, 'alias_visible' => 1, 'published' => 1, 'deleted' => 0, 'template' => 10, 'createdon' => $now, 'editedon' => $now, 'menuindex' => $category], $contentColumns);
        $closure->execute([$categoryId, $categoryId, 0]);
        $closure->execute([2, $categoryId, 1]);
        for ($position = 1; $position <= FixturePlan::ARTICLES_PER_CATEGORY; $position++) {
            $articleNumber++;
            $id = FixturePlan::articleId($articleNumber);
            $title = FixturePlan::articleTitle($articleNumber);
            insert($pdo, $contentTable, ['id' => $id, 'type' => 'document', 'contentType' => 'text/html', 'pagetitle' => $title, 'longtitle' => $title . ' with realistic editorial metadata', 'description' => str_repeat('Benchmark description ', 9), 'introtext' => str_repeat('A concise article card introduction. ', 10), 'content' => '<p>' . str_repeat('Benchmark body content for warm-cache CMS rendering. ', 80) . '</p>', 'alias' => FixturePlan::articleAlias($articleNumber), 'parent' => $categoryId, 'isfolder' => 0, 'alias_visible' => 1, 'published' => 1, 'deleted' => 0, 'template' => 10, 'createdon' => $now - $articleNumber, 'editedon' => $now, 'pub_date' => $now - $articleNumber, 'menuindex' => $position], $contentColumns);
            $closure->execute([$id, $id, 0]);
            $closure->execute([$categoryId, $id, 1]);
            $closure->execute([2, $id, 2]);
            foreach (FixturePlan::tvPresence($articleNumber) as $name => $present) {
                if (!$present) {
                    continue;
                }
                $value = match ($name) {
                    'hero_image' => '/assets/images/' . FixturePlan::articleAlias($articleNumber) . '.jpg',
                    'author' => 'Author ' . (($articleNumber % 25) + 1),
                    'reading_time' => (string) (($articleNumber % 12) + 3),
                    'featured' => '1',
                    'seo_title' => $title,
                    'seo_description' => 'SEO ' . $title,
                    'external_url' => 'https://example.test/' . FixturePlan::articleAlias($articleNumber),
                    'rating' => (string) (($articleNumber % 5) + 1),
                };
                insert($pdo, $tvValueTable, ['tmplvarid' => $tvIds[$name], 'contentid' => $id, 'value' => $value], $tvValueColumns);
            }
        }
    }
    $pdo->commit();
}

function cloneCanonical(string $target): void
{
    $password = (string) getenv('DB_ROOT_PASSWORD');
    $source = 'benchmark_canonical';
    $target = 'benchmark_' . $target;
    $command = sprintf('MYSQL_PWD=%s mysqldump --single-transaction --add-drop-table -h %s -u root %s | MYSQL_PWD=%s mysql -h %s -u root %s', escapeshellarg($password), escapeshellarg((string) getenv('DB_HOST')), escapeshellarg($source), escapeshellarg($password), escapeshellarg((string) getenv('DB_HOST')), escapeshellarg($target));
    $status = 0;
    passthru($command, $status);
    if ($status !== 0) {
        throw new RuntimeException('Unable to clone the canonical fixture database.');
    }
}

function copyTree(string $from, string $to): void
{
    run(['cp', '-R', $from . '/.', $to]);
}

function writeSettings(string $database, bool $parser): void
{
    $pdo = rootPdo($database);
    $settings = PREFIX . 'system_settings';
    $values = [
        'site_start' => 1,
        'friendly_urls' => 1,
        'use_alias_path' => 1,
        'aliaslistingfolder' => 1,
        'full_aliaslisting' => 1,
        'enable_cache' => 0,
        // Folders would otherwise 302 the canonical /articles/category-042
        // URL to a trailing-slash form; every stack must answer 200 directly.
        'seostrict' => 0,
    ];
    foreach ($values as $name => $value) {
        $pdo->prepare('INSERT INTO `' . $settings . '` (setting_name, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$name, (string) $value]);
    }
    if ($parser) {
        $snippet = PREFIX . 'site_snippets';
        $pdo->prepare('DELETE FROM `' . $snippet . '` WHERE name = ?')->execute(['benchmarkCategory']);
        $code = <<<'PHP'
$evo = evo();
$items = $evo->getDocumentChildren((int) $evo->documentObject['id'], 1, 0, 'id,pagetitle,introtext,alias,pub_date', '', 'menuindex', 'ASC', 20);
$page = $evo->documentObject;
$escape = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $escape($page['pagetitle']) . '</title><meta name="description" content="' . $escape($page['description']) . '"></head><body><header><a href="/">Phramark benchmark</a></header><main><h1>' . $escape($page['pagetitle']) . '</h1><p class="intro">' . $escape($page['introtext']) . '</p><section class="articles">';
foreach ($items as $item) { $tvs = $evo->getTemplateVarOutput('*', (int) $item['id']); echo '<article><img src="' . $escape($tvs['hero_image'] ?? '') . '" alt=""><h2><a href="/articles/' . $escape($page['alias']) . '/' . $escape($item['alias']) . '">' . $escape($item['pagetitle']) . '</a></h2><p class="intro">' . $escape($item['introtext']) . '</p><p class="meta"><span class="author">' . $escape($tvs['author'] ?? '') . '</span> · <span class="reading-time">' . $escape($tvs['reading_time'] ?? '') . ' min</span></p></article>'; }
echo '</section></main><footer>Deterministic CMS benchmark fixture</footer></body></html>';
PHP;
        $pdo->prepare('INSERT INTO `' . $snippet . '` (name, description, snippet, category, locked) VALUES (?, ?, ?, 0, 1)')->execute(['benchmarkCategory', 'Phramark parser workload', $code]);
    }
}

try {
    createDatabases();
    foreach (['canonical', 'evo-parser', 'evo-latte', 'evo-latte-parser', 'evo-phalcon'] as $site) {
        install($site);
    }
    installPackage('evo-latte', 'elcreator/alattex');
    installPackage('evo-latte-parser', 'elcreator/alattex');
    installPackage('evo-phalcon', 'elcreator/alattex');
    installPackage('evo-phalcon', 'elcreator/aphalcon');
    seedCanonical();
    foreach (['evo_parser', 'evo_latte', 'evo_latte_parser', 'evo_phalcon'] as $target) {
        cloneCanonical($target);
    }
    copyTree('/opt/phramark/benchmark/implementations/evo-latte', ROOT . '/evo-latte');
    copyTree('/opt/phramark/benchmark/implementations/evo-latte-parser', ROOT . '/evo-latte-parser');
    copyTree('/opt/phramark/benchmark/implementations/evo-phalcon', ROOT . '/evo-phalcon');
    writeSettings('benchmark_evo_parser', true);
    writeSettings('benchmark_evo_latte', false);
    writeSettings('benchmark_evo_latte_parser', false);
    writeSettings('benchmark_evo_phalcon', false);
    foreach (['evo-parser', 'evo-latte', 'evo-latte-parser', 'evo-phalcon'] as $site) {
        run(['php', 'artisan', 'cache:clear-full'], ROOT . '/' . $site . '/core');
        run(['chown', '-R', 'www-data:www-data', ROOT . '/' . $site]);
    }
    file_put_contents('/sites/.complete', "complete\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
