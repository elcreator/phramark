<?php

declare(strict_types=1);

// Guest workload fixture for MODX Revolution: the benchmark elements
// (template, snippet, chunk from benchmark/implementations/modx/elements),
// the shared phramark_article table (filled by seed-articles.php), the
// "articles" container with one resource per category so MODX resolves
// /articles/category-NNN through its own alias map, and the system settings
// every stack needs for the canonical URL form. Re-runnable: elements and
// resources are replaced by id.
//
// Usage: php modx-seed.php HOST DATABASE USER PASSWORD

require '/opt/phramark/src/FixturePlan.php';

use Phramark\FixturePlan;

const PREFIX = 'modx_';
const ELEMENTS = '/opt/phramark/benchmark/implementations/modx/elements/';
const TEMPLATE_CATEGORY = 10;
const TEMPLATE_ADMIN = 11;
const ARTICLES_ROOT_ID = 2;

$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $argv[1], $argv[2]), $argv[3], $argv[4], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$now = 1_704_067_200;

$pdo->exec('CREATE TABLE IF NOT EXISTS phramark_article (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  introtext TEXT NOT NULL,
  alias VARCHAR(64) NOT NULL,
  hero_image VARCHAR(255) NOT NULL,
  author VARCHAR(64) NULL,
  reading_time INT UNSIGNED NULL,
  published_at INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY category_published (category, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$pdo->beginTransaction();

// Friendly URLs resolved through the alias path, no page cache: like the
// Evolution stacks (enable_cache=0) MODX parses the document on every
// request and the snippet runs uncached. automatic_alias lets the manager
// derive an alias for the pages the admin workload creates.
$settings = [
    'friendly_urls' => '1',
    'use_alias_path' => '1',
    'automatic_alias' => '1',
    'cache_resource' => '0',
    'site_start' => '1',
];
$setting = $pdo->prepare('INSERT INTO `' . PREFIX . 'system_settings` (`key`, `value`, xtype, namespace, area, editedon) VALUES (?, ?, ?, ?, ?, NULL) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
foreach ($settings as $key => $value) {
    $setting->execute([$key, $value, 'textfield', 'core', 'site']);
}

// The default manager dashboard fetches modx.com news/security feeds and
// checks for updates server-side on every login; those widgets would time
// the internet, not the CMS, so the login lands on the local widgets only.
$pdo->exec("DELETE FROM `" . PREFIX . "dashboard_widget_placement` WHERE widget IN (SELECT id FROM `" . PREFIX . "dashboard_widget` WHERE name IN ('w_newsfeed', 'w_securityfeed', 'w_updates'))");

$element = static fn (string $file): string => rtrim((string) file_get_contents(ELEMENTS . $file), "\n");
$pdo->prepare('DELETE FROM `' . PREFIX . 'site_templates` WHERE id IN (?, ?) OR templatename IN (?, ?)')->execute([TEMPLATE_CATEGORY, TEMPLATE_ADMIN, 'Benchmark category', 'Admin page']);
$template = $pdo->prepare('INSERT INTO `' . PREFIX . 'site_templates` (id, templatename, description, content) VALUES (?, ?, ?, ?)');
$template->execute([TEMPLATE_CATEGORY, 'Benchmark category', 'Phramark category benchmark', $element('templates/category.html')]);
$template->execute([TEMPLATE_ADMIN, 'Admin page', 'Phramark admin workload page', $element('templates/admin-page.html')]);
$pdo->prepare('DELETE FROM `' . PREFIX . 'site_snippets` WHERE name = ?')->execute(['phramarkCategory']);
$pdo->prepare('INSERT INTO `' . PREFIX . 'site_snippets` (name, description, snippet) VALUES (?, ?, ?)')
    ->execute(['phramarkCategory', 'Phramark category articles', preg_replace('/^<\?php\s*/', '', $element('snippets/phramarkCategory.php'))]);
$pdo->prepare('DELETE FROM `' . PREFIX . 'site_htmlsnippets` WHERE name = ?')->execute(['phramarkArticle']);
$pdo->prepare('INSERT INTO `' . PREFIX . 'site_htmlsnippets` (name, description, snippet) VALUES (?, ?, ?)')
    ->execute(['phramarkArticle', 'Phramark article card', $element('chunks/phramarkArticle.html')]);

// The uri column is what the alias map is built from; MODX fills it on save
// through the manager, so a SQL seed has to write the full path itself.
$content = '`' . PREFIX . 'site_content`';
$pdo->prepare("DELETE FROM {$content} WHERE id = ? OR parent = ?")->execute([ARTICLES_ROOT_ID, ARTICLES_ROOT_ID]);
$resource = $pdo->prepare("INSERT INTO {$content} (id, type, pagetitle, longtitle, description, alias, introtext, content, richtext, published, parent, isfolder, template, menuindex, searchable, cacheable, createdby, createdon, editedon, publishedon, publishedby, hidemenu, class_key, context_key, content_type, uri, uri_override) VALUES (?, 'document', ?, ?, ?, ?, ?, ?, 0, 1, ?, ?, ?, ?, 1, 1, 1, ?, ?, ?, 1, 0, 'MODX\\\\Revolution\\\\modDocument', 'web', 1, ?, 0)");
$resource->execute([ARTICLES_ROOT_ID, 'Articles', 'Articles', '', 'articles', '', '', FixturePlan::ARTICLES_ROOT_PARENT, 1, TEMPLATE_CATEGORY, 0, $now, $now, $now, 'articles/']);
for ($category = 1; $category <= FixturePlan::CATEGORY_COUNT; $category++) {
    $title = FixturePlan::categoryTitle($category);
    $alias = FixturePlan::categoryAlias($category);
    $resource->execute([ARTICLES_ROOT_ID + $category, $title, $title, FixturePlan::categoryDescription(), $alias, FixturePlan::categoryIntrotext(), '', ARTICLES_ROOT_ID, 0, TEMPLATE_CATEGORY, $category, $now, $now, $now, 'articles/' . $alias]);
}
$pdo->commit();

printf("modx categories: %d-%d under %d\n", ARTICLES_ROOT_ID + 1, ARTICLES_ROOT_ID + FixturePlan::CATEGORY_COUNT, ARTICLES_ROOT_ID);
