<?php

declare(strict_types=1);

// Admin workload fixture for Winter CMS: the seeded pages the Playwright
// session edits, in the plugin's phramark_benchmark_pages table. Titles and
// bodies mirror Phramark\FixturePlan::adminPage*(); ids are stable
// (ADMIN_ROOT_ID + n) so the workload can address them directly. Pages the
// workload created (any other id) are removed.
//
// Usage: php winter-admin-seed.php HOST DATABASE USER PASSWORD

require '/opt/phramark/src/FixturePlan.php';

use Phramark\FixturePlan;

$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $argv[1], $argv[2]), $argv[3], $argv[4], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$now = '2024-01-01 00:00:00';

$pdo->beginTransaction();
$pdo->exec('DELETE FROM phramark_benchmark_pages');
$page = $pdo->prepare('INSERT INTO phramark_benchmark_pages (id, title, content, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
for ($number = 1; $number <= FixturePlan::ADMIN_PAGE_COUNT; $number++) {
    $page->execute([FixturePlan::adminPageId($number), FixturePlan::adminPageTitle($number), FixturePlan::adminPageContent($number), $now, $now]);
}
$pdo->commit();

printf("winter admin pages: %d-%d\n", FixturePlan::adminPageId(1), FixturePlan::adminPageId(FixturePlan::ADMIN_PAGE_COUNT));
