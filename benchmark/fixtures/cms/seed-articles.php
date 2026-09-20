<?php

declare(strict_types=1);

require '/opt/phramark/src/FixturePlan.php';

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $argv[1], $argv[2]);
$pdo = new PDO($dsn, $argv[3], $argv[4], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('TRUNCATE TABLE phramark_article');
$statement = $pdo->prepare('INSERT INTO phramark_article (category, title, introtext, alias, hero_image, author, reading_time, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$pdo->beginTransaction();
for ($category = 1, $number = 0; $category <= \Phramark\FixturePlan::CATEGORY_COUNT; $category++) {
    for ($position = 1; $position <= \Phramark\FixturePlan::ARTICLES_PER_CATEGORY; $position++) {
        $number++;
        $alias = \Phramark\FixturePlan::articleAlias($number);
        $tv = \Phramark\FixturePlan::tvPresence($number);
        $statement->execute([
            $category,
            sprintf('Article %06d: a deterministic CMS benchmark fixture', $number),
            str_repeat('A concise article card introduction. ', 10),
            $alias,
            '/assets/images/' . $alias . '.jpg',
            $tv['author'] ? 'Author ' . (($number % 25) + 1) : null,
            $tv['reading_time'] ? (($number % 12) + 3) : null,
            1_704_067_200 - $number,
        ]);
    }
}
$pdo->commit();
