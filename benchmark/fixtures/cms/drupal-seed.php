<?php

declare(strict_types=1);

require '/opt/phramark/src/FixturePlan.php';

$database = \Drupal::database();
$database->truncate('phramark_article')->execute();
$transaction = $database->startTransaction();
$insert = $database->insert('phramark_article')->fields([
    'category', 'title', 'introtext', 'alias', 'hero_image', 'author', 'reading_time', 'published_at',
]);

for ($category = 1, $number = 0; $category <= \Phramark\FixturePlan::CATEGORY_COUNT; $category++) {
    for ($position = 1; $position <= \Phramark\FixturePlan::ARTICLES_PER_CATEGORY; $position++) {
        $number++;
        $alias = \Phramark\FixturePlan::articleAlias($number);
        $tv = \Phramark\FixturePlan::tvPresence($number);
        $insert->values([
            $category,
            \Phramark\FixturePlan::articleTitle($number),
            str_repeat('A concise article card introduction. ', 10),
            $alias,
            '/assets/images/' . $alias . '.jpg',
            $tv['author'] ? 'Author ' . (($number % 25) + 1) : null,
            $tv['reading_time'] ? (($number % 12) + 3) : null,
            1_704_067_200 - $number,
        ]);
    }
}
$insert->execute();
unset($transaction);
