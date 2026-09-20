<?php

declare(strict_types=1);

// Admin workload fixture for WordPress + Gantry 5: the "Admin workload" page
// (ADMIN_ROOT_ID) with the seeded child pages the Playwright session edits.
// Titles and bodies mirror Phramark\FixturePlan::adminPage*(); ids are
// stable (ADMIN_ROOT_ID + n) so the workload can address them directly.
// Pages the workload created under the container, their revisions and
// auto-drafts, and the revisions the edits produced are removed.
//
// Usage: php wordpress-admin-seed.php HOST DATABASE USER PASSWORD

require '/opt/phramark/src/FixturePlan.php';

use Phramark\FixturePlan;

const PREFIX = 'wp_';

$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $argv[1], $argv[2]), $argv[3], $argv[4], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$date = '2024-01-01 00:00:00';
$posts = '`' . PREFIX . 'posts`';
$meta = '`' . PREFIX . 'postmeta`';

$pdo->beginTransaction();
// The container, its children, and any revision of those (revisions keep
// the page as post_parent), in that order so the sub-select sees them.
$pdo->prepare("DELETE FROM {$meta} WHERE post_id IN (SELECT ID FROM {$posts} WHERE ID = ? OR post_parent = ? OR post_parent IN (SELECT ID FROM (SELECT ID FROM {$posts} WHERE post_parent = ?) AS pages))")->execute([FixturePlan::ADMIN_ROOT_ID, FixturePlan::ADMIN_ROOT_ID, FixturePlan::ADMIN_ROOT_ID]);
$pdo->prepare("DELETE FROM {$posts} WHERE post_parent IN (SELECT ID FROM (SELECT ID FROM {$posts} WHERE post_parent = ?) AS pages)")->execute([FixturePlan::ADMIN_ROOT_ID]);
$pdo->prepare("DELETE FROM {$posts} WHERE ID = ? OR post_parent = ?")->execute([FixturePlan::ADMIN_ROOT_ID, FixturePlan::ADMIN_ROOT_ID]);
// Auto-drafts (the "new page" form, the dashboard's Quick Draft) never saved.
$pdo->exec("DELETE FROM {$posts} WHERE post_status = 'auto-draft'");
$page = $pdo->prepare("INSERT INTO {$posts} (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) VALUES (?, 1, ?, ?, ?, ?, '', 'publish', 'closed', 'closed', '', ?, '', '', ?, ?, '', ?, ?, ?, 'page', '', 0)");
$page->execute([FixturePlan::ADMIN_ROOT_ID, $date, $date, '', 'Admin workload', FixturePlan::ADMIN_ROOT_ALIAS, $date, $date, 0, 'http://127.0.0.1:8088/?page_id=' . FixturePlan::ADMIN_ROOT_ID, 0]);
for ($number = 1; $number <= FixturePlan::ADMIN_PAGE_COUNT; $number++) {
    $id = FixturePlan::adminPageId($number);
    $page->execute([$id, $date, $date, FixturePlan::adminPageContent($number), FixturePlan::adminPageTitle($number), FixturePlan::adminPageAlias($number), $date, $date, FixturePlan::ADMIN_ROOT_ID, 'http://127.0.0.1:8088/?page_id=' . $id, $number]);
}
$pdo->commit();

printf("wordpress admin pages: %d-%d under %d\n", FixturePlan::adminPageId(1), FixturePlan::adminPageId(FixturePlan::ADMIN_PAGE_COUNT), FixturePlan::ADMIN_ROOT_ID);
