<?php

declare(strict_types=1);

// Admin workload fixture for Drupal (run with drush php:script): a "page"
// content type with a plain-text body, and the seeded pages the Playwright
// session edits. Mirrors Phramark\FixturePlan::adminPage*() titles/bodies.

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\filter\Entity\FilterFormat;

require '/opt/phramark/src/FixturePlan.php';

if (FilterFormat::load('plain_text') === null) {
    FilterFormat::create(['format' => 'plain_text', 'name' => 'Plain text', 'weight' => 10, 'filters' => []])->save();
}

if (NodeType::load('page') === null) {
    NodeType::create(['type' => 'page', 'name' => 'Basic page', 'new_revision' => true])->save();
}
if (FieldStorageConfig::loadByName('node', 'body') === null) {
    FieldStorageConfig::create(['field_name' => 'body', 'entity_type' => 'node', 'type' => 'text_with_summary'])->save();
}
if (FieldConfig::loadByName('node', 'page', 'body') === null) {
    FieldConfig::create(['field_name' => 'body', 'entity_type' => 'node', 'bundle' => 'page', 'label' => 'Body'])->save();
    // The node form only renders configured components; the default form
    // display is created on first use, so make sure body and title are on it.
    \Drupal::service('entity_display.repository')->getFormDisplay('node', 'page')
        ->setComponent('title', ['type' => 'string_textfield', 'weight' => 0])
        ->setComponent('body', ['type' => 'text_textarea_with_summary', 'weight' => 1])
        ->save();
    \Drupal::service('entity_display.repository')->getViewDisplay('node', 'page')
        ->setComponent('body', ['type' => 'text_default'])
        ->save();
}

$storage = \Drupal::entityTypeManager()->getStorage('node');
// Seeded pages are the N lowest "page" nodes (see drupal-admin-reset.php and
// the adapter); only the missing ones are created, whatever their titles are.
$existing = $storage->loadByProperties(['type' => 'page']);
for ($page = count($existing) + 1; $page <= \Phramark\FixturePlan::ADMIN_PAGE_COUNT; $page++) {
    Node::create([
        'type' => 'page',
        'title' => \Phramark\FixturePlan::adminPageTitle($page),
        'body' => ['value' => \Phramark\FixturePlan::adminPageContent($page), 'format' => 'plain_text'],
        'status' => 1,
        'uid' => 1,
    ])->save();
}
$ids = array_map('intval', array_keys($storage->loadByProperties(['type' => 'page'])));
sort($ids);
echo 'drupal admin pages: ' . implode(',', array_slice($ids, 0, \Phramark\FixturePlan::ADMIN_PAGE_COUNT)) . PHP_EOL;
