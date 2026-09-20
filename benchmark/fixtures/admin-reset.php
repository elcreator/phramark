<?php

declare(strict_types=1);

use Phramark\FixturePlan;

require '/opt/phramark/src/FixturePlan.php';

// Restores the admin workload fixture of one Evolution stack after a Playwright
// run: seeded pages get their titles and bodies back and every document the
// workload created under the admin folder is removed, so repeated admin runs
// start from the same state the canonical fixture defines.
//
// Usage: php admin-reset.php evo-parser|evo-latte|evo-latte-parser|evo-phalcon

$stack = $argv[1] ?? '';
if (!in_array($stack, ['evo-parser', 'evo-latte', 'evo-latte-parser', 'evo-phalcon'], true)) {
    fwrite(STDERR, "Usage: admin-reset.php evo-parser|evo-latte|evo-latte-parser|evo-phalcon\n");
    exit(2);
}

$prefix = 'site_';
$database = 'benchmark_' . str_replace('-', '_', $stack);
$pdo = new PDO(
    'mysql:host=' . getenv('DB_HOST') . ';dbname=' . $database,
    'root',
    (string) getenv('DB_ROOT_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$content = '`' . $prefix . 'site_content`';
$closure = '`' . $prefix . 'site_content_closure`';
$tvValues = '`' . $prefix . 'site_tmplvar_contentvalues`';
$lastSeeded = FixturePlan::adminPageId(FixturePlan::ADMIN_PAGE_COUNT);

$pdo->beginTransaction();
$restore = $pdo->prepare("UPDATE {$content} SET pagetitle = ?, longtitle = ?, content = ?, alias = ?, editedon = createdon WHERE id = ?");
for ($page = 1; $page <= FixturePlan::ADMIN_PAGE_COUNT; $page++) {
    $restore->execute([
        FixturePlan::adminPageTitle($page),
        FixturePlan::adminPageTitle($page),
        FixturePlan::adminPageContent($page),
        FixturePlan::adminPageAlias($page),
        FixturePlan::adminPageId($page),
    ]);
}
$created = $pdo->prepare("SELECT id FROM {$content} WHERE parent = ? AND id > ?");
$created->execute([FixturePlan::ADMIN_ROOT_ID, $lastSeeded]);
$ids = array_map('intval', $created->fetchAll(PDO::FETCH_COLUMN));
if ($ids !== []) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM {$tvValues} WHERE contentid IN ({$placeholders})")->execute($ids);
    $pdo->prepare("DELETE FROM {$closure} WHERE descendant IN ({$placeholders}) OR ancestor IN ({$placeholders})")->execute([...$ids, ...$ids]);
    $pdo->prepare("DELETE FROM {$content} WHERE id IN ({$placeholders})")->execute($ids);
}
$pdo->commit();

$site = '/sites/' . $stack . '/core';
if (is_dir($site)) {
    passthru('cd ' . escapeshellarg($site) . ' && php artisan cache:clear-full', $status);
}

printf("%s: restored %d admin pages, removed %d created documents.\n", $stack, FixturePlan::ADMIN_PAGE_COUNT, count($ids));
