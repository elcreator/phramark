<?php

declare(strict_types=1);

// Restores the Drupal admin workload fixture after a Playwright run (drush
// php:script): the seeded pages are the N lowest "page" nodes (the seed
// creates them on a fresh install, so nodes 1..N, matching the adapter); they
// get their titles and bodies back, every later page node was created by the
// workload and is deleted.

use Drupal\node\Entity\Node;

require '/opt/phramark/src/FixturePlan.php';

$storage = \Drupal::entityTypeManager()->getStorage('node');
$pages = $storage->loadByProperties(['type' => 'page']);
ksort($pages);
$seeded = [];
$page = 0;
foreach ($pages as $node) {
    if (++$page > \Phramark\FixturePlan::ADMIN_PAGE_COUNT) {
        break;
    }
    $node->setTitle(\Phramark\FixturePlan::adminPageTitle($page));
    $node->set('body', ['value' => \Phramark\FixturePlan::adminPageContent($page), 'format' => 'plain_text']);
    $node->setNewRevision(false);
    $node->save();
    $seeded[] = (int) $node->id();
}

$created = array_filter(
    $storage->loadByProperties(['type' => 'page']),
    static fn ($node): bool => !in_array((int) $node->id(), $seeded, true),
);
$storage->delete($created);
printf("drupal: restored %d admin pages, removed %d created nodes.\n", count($seeded), count($created));
