<?php

declare(strict_types=1);

// Admin workload fixture for TYPO3: an "Admin workload" root page with the
// seeded pages the Playwright session edits, each with one text content
// element whose bodytext is the page body. Titles/bodies mirror
// Phramark\FixturePlan::adminPage*(); page uids are stable (ADMIN_ROOT_ID + n)
// so the workload can address them directly.
//
// Usage: php typo3-admin-seed.php HOST DATABASE USER PASSWORD

require '/opt/phramark/src/FixturePlan.php';

use Phramark\FixturePlan;

$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $argv[1], $argv[2]), $argv[3], $argv[4], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$now = 1_704_067_200;

$pdo->beginTransaction();
$pdo->prepare('DELETE FROM tt_content WHERE pid = ? OR pid IN (SELECT uid FROM (SELECT uid FROM pages WHERE pid = ?) p)')->execute([FixturePlan::ADMIN_ROOT_ID, FixturePlan::ADMIN_ROOT_ID]);
// Created pages are soft-deleted by the backend at most; remove them for real.
$pdo->prepare('DELETE FROM tt_content WHERE uid >= ?')->execute([FixturePlan::adminPageId(1)]);
$pdo->prepare('DELETE FROM pages WHERE uid = ? OR pid = ?')->execute([FixturePlan::ADMIN_ROOT_ID, FixturePlan::ADMIN_ROOT_ID]);

$page = $pdo->prepare('INSERT INTO pages (uid, pid, tstamp, crdate, sorting, title, slug, doktype, is_siteroot, hidden, deleted, perms_userid, perms_groupid, perms_user, perms_group, perms_everybody) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, 0, 0, 1, 1, 31, 27, 0)');
$page->execute([FixturePlan::ADMIN_ROOT_ID, 0, $now, $now, 256, 'Admin workload', '/' . FixturePlan::ADMIN_ROOT_ALIAS, 1]);
// The element gets the same uid as its page, so the workload can open both
// records in one FormEngine form without a lookup.
$content = $pdo->prepare('INSERT INTO tt_content (uid, pid, tstamp, crdate, sorting, CType, header, bodytext, colPos, hidden, deleted) VALUES (?, ?, ?, ?, 256, ?, ?, ?, 0, 0, 0)');
for ($number = 1; $number <= FixturePlan::ADMIN_PAGE_COUNT; $number++) {
    $uid = FixturePlan::adminPageId($number);
    $page->execute([$uid, FixturePlan::ADMIN_ROOT_ID, $now, $now, 256 * $number, FixturePlan::adminPageTitle($number), '/' . FixturePlan::adminPageAlias($number), 0]);
    $content->execute([$uid, $uid, $now, $now, 'text', FixturePlan::adminPageTitle($number), FixturePlan::adminPageContent($number)]);
}
$pdo->commit();

printf("typo3 admin pages: %d-%d under %d\n", FixturePlan::adminPageId(1), FixturePlan::adminPageId(FixturePlan::ADMIN_PAGE_COUNT), FixturePlan::ADMIN_ROOT_ID);
