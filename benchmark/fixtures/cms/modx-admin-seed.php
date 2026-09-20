<?php

declare(strict_types=1);

// Admin workload fixture for MODX Revolution: the "Admin workload" container
// (ADMIN_ROOT_ID) with the seeded resources the Playwright session edits.
// Titles and bodies mirror Phramark\FixturePlan::adminPage*(); ids are
// stable (ADMIN_ROOT_ID + n) so the workload can address them directly.
// Resources the workload created under the container (any other id) are
// removed. The uri column is what MODX's alias map is built from.
//
// Usage: php modx-admin-seed.php HOST DATABASE USER PASSWORD

require '/opt/phramark/src/FixturePlan.php';

use Phramark\FixturePlan;

const PREFIX = 'modx_';
const TEMPLATE_ADMIN = 11;

$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $argv[1], $argv[2]), $argv[3], $argv[4], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$now = 1_704_067_200;
$content = '`' . PREFIX . 'site_content`';

$pdo->beginTransaction();
$pdo->prepare("DELETE FROM {$content} WHERE id = ? OR parent = ?")->execute([FixturePlan::ADMIN_ROOT_ID, FixturePlan::ADMIN_ROOT_ID]);
$resource = $pdo->prepare("INSERT INTO {$content} (id, type, pagetitle, longtitle, description, alias, content, richtext, published, parent, isfolder, template, menuindex, searchable, cacheable, createdby, createdon, editedon, publishedon, publishedby, hidemenu, class_key, context_key, content_type, uri, uri_override) VALUES (?, 'document', ?, ?, '', ?, ?, 0, 1, ?, ?, ?, ?, 1, 1, 1, ?, ?, ?, 1, 0, 'MODX\\\\Revolution\\\\modDocument', 'web', 1, ?, 0)");
$resource->execute([FixturePlan::ADMIN_ROOT_ID, 'Admin workload', 'Admin workload', FixturePlan::ADMIN_ROOT_ALIAS, '', 0, 1, TEMPLATE_ADMIN, 1, $now, $now, $now, FixturePlan::ADMIN_ROOT_ALIAS . '/']);
for ($number = 1; $number <= FixturePlan::ADMIN_PAGE_COUNT; $number++) {
    $resource->execute([FixturePlan::adminPageId($number), FixturePlan::adminPageTitle($number), FixturePlan::adminPageTitle($number), FixturePlan::adminPageAlias($number), FixturePlan::adminPageContent($number), FixturePlan::ADMIN_ROOT_ID, 0, TEMPLATE_ADMIN, $number, $now, $now, $now, FixturePlan::ADMIN_ROOT_ALIAS . '/' . FixturePlan::adminPageAlias($number)]);
}
$pdo->commit();

printf("modx admin pages: %d-%d under %d\n", FixturePlan::adminPageId(1), FixturePlan::adminPageId(FixturePlan::ADMIN_PAGE_COUNT), FixturePlan::ADMIN_ROOT_ID);
