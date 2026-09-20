<?php

declare(strict_types=1);

// Guest workload fixture for WordPress + Gantry 5: the shared phramark_article
// table (filled by seed-articles.php) and the "Articles" page with one child
// page per category, so WordPress resolves /articles/category-NNN through its
// own hierarchical page rewrite (pagename) and the mu-plugin renders it
// through the Gantry theme. Re-runnable: the pages are replaced by id.
//
// Usage: php wordpress-seed.php HOST DATABASE USER PASSWORD

require '/opt/phramark/src/FixturePlan.php';

use Phramark\FixturePlan;

const PREFIX = 'wp_';
const ARTICLES_ROOT_ID = 2;

$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $argv[1], $argv[2]), $argv[3], $argv[4], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$date = '2024-01-01 00:00:00';

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

$posts = '`' . PREFIX . 'posts`';
$meta = '`' . PREFIX . 'postmeta`';
$pdo->beginTransaction();
// The install's sample post, sample page and privacy-policy draft occupy the
// low ids the category pages use.
$pdo->prepare("DELETE FROM {$meta} WHERE post_id IN (SELECT ID FROM {$posts} WHERE ID <= ? OR post_parent = ?)")->execute([ARTICLES_ROOT_ID + FixturePlan::CATEGORY_COUNT, ARTICLES_ROOT_ID]);
$pdo->prepare("DELETE FROM {$posts} WHERE ID <= ? OR post_parent = ?")->execute([ARTICLES_ROOT_ID + FixturePlan::CATEGORY_COUNT, ARTICLES_ROOT_ID]);
$pdo->exec('DELETE FROM `' . PREFIX . 'comments`');
$page = $pdo->prepare("INSERT INTO {$posts} (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) VALUES (?, 1, ?, ?, ?, ?, ?, 'publish', 'closed', 'closed', '', ?, '', '', ?, ?, '', ?, ?, ?, 'page', '', 0)");
$page->execute([ARTICLES_ROOT_ID, $date, $date, '', 'Articles', '', 'articles', $date, $date, 0, 'http://127.0.0.1:8088/?page_id=' . ARTICLES_ROOT_ID, 0]);
for ($category = 1; $category <= FixturePlan::CATEGORY_COUNT; $category++) {
    $id = ARTICLES_ROOT_ID + $category;
    $page->execute([$id, $date, $date, '', FixturePlan::categoryTitle($category), FixturePlan::categoryIntrotext(), FixturePlan::categoryAlias($category), $date, $date, ARTICLES_ROOT_ID, 'http://127.0.0.1:8088/?page_id=' . $id, $category]);
}
$pdo->commit();

printf("wordpress categories: %d-%d under %d\n", ARTICLES_ROOT_ID + 1, ARTICLES_ROOT_ID + FixturePlan::CATEGORY_COUNT, ARTICLES_ROOT_ID);
