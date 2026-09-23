<?php

declare(strict_types=1);

use Phramark\FixturePlan;

/**
 * The evo-sArticles fixture: the same deterministic content as every other
 * stack (100 categories, 10 000 articles, seed 424242, five editable items
 * for the admin round), but in the tables the sArticles module owns
 * (s_articles, s_article_translates, s_articles_categories,
 * s_article_categories, s_articles_authors) instead of the document tree.
 *
 * The visible values are the ones the shared page contract expects, so a
 * category page rendered from sArticles is byte-comparable with the
 * document-based stacks: cover is the hero image, the author is the
 * article's author record, and the reading time lives in the article's
 * tmplvars, where sArticles puts template-variable values.
 */

/**
 * The module's tables live on the site's connection, so Evolution's table
 * prefix applies to them as it does to the CMS tables: the models resolve
 * it through the connection, the fixture has to name it.
 */
function sArticlesTable(string $name): string
{
    return PREFIX . $name;
}

/** The articles of the admin round, kept apart from the guest fixture. */
const SARTICLES_ADMIN_FIRST_ID = 100_001;

function sArticlesAdminId(int $number): int
{
    return SARTICLES_ADMIN_FIRST_ID + $number - 1;
}

/**
 * Article ids follow the fixture's article numbers so a row can be found
 * from its number without a lookup; the admin round's articles sit far
 * above them.
 */
function seedSArticles(PDO $pdo): void
{
    $now = '2024-01-01 00:00:00';
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['s_article_categories', 's_articles_categories', 's_article_translates', 's_articles', 's_articles_authors'] as $table) {
        $pdo->exec('TRUNCATE TABLE `' . sArticlesTable($table) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->beginTransaction();

    // The 25 authors the fixture cycles through ("Author 1" … "Author 25").
    $author = $pdo->prepare('INSERT INTO `' . sArticlesTable('s_articles_authors') . '` (autid, alias, gender, image, base_name, base_lastname, base_office, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    for ($number = 1; $number <= 25; $number++) {
        $author->execute([$number, 'author-' . $number, 'man', '', 'Author ' . $number, '', '', $now, $now]);
    }

    $category = $pdo->prepare('INSERT INTO `' . sArticlesTable('s_articles_categories') . '` (catid, position, alias, cover, base, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    for ($number = 1; $number <= FixturePlan::CATEGORY_COUNT; $number++) {
        $category->execute([$number, $number, FixturePlan::categoryAlias($number), '', FixturePlan::categoryTitle($number), $now, $now]);
    }

    $article = $pdo->prepare('INSERT INTO `' . sArticlesTable('s_articles') . '` (id, published, parent, author_id, views, position, rating, alias, cover, type, relevants, tmplvars, votes, published_at, created_at, updated_at) VALUES (?, 1, 0, ?, 0, ?, 5, ?, ?, ?, NULL, ?, NULL, ?, ?, ?)');
    $translate = $pdo->prepare('INSERT INTO `' . sArticlesTable('s_article_translates') . '` (article, lang, pagetitle, longtitle, introtext, description, content, seotitle, seodescription, seorobots, builder, constructor, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $link = $pdo->prepare('INSERT INTO `' . sArticlesTable('s_article_categories') . '` (article, category) VALUES (?, ?)');

    $introtext = str_repeat('A concise article card introduction. ', 10);
    $description = str_repeat('Benchmark description ', 9);
    $content = '<p>' . str_repeat('Benchmark body content for warm-cache CMS rendering. ', 80) . '</p>';
    $articleNumber = 0;
    for ($categoryNumber = 1; $categoryNumber <= FixturePlan::CATEGORY_COUNT; $categoryNumber++) {
        for ($position = 1; $position <= FixturePlan::ARTICLES_PER_CATEGORY; $position++) {
            $articleNumber++;
            $presence = FixturePlan::tvPresence($articleNumber);
            $title = FixturePlan::articleTitle($articleNumber);
            $alias = FixturePlan::articleAlias($articleNumber);
            // Every template variable the document stacks carry, in the
            // place sArticles reads them from.
            $tmplvars = [];
            foreach ($presence as $name => $present) {
                if (!$present || $name === 'hero_image' || $name === 'author') {
                    continue;
                }
                $tmplvars[$name] = match ($name) {
                    'reading_time' => (string) (($articleNumber % 12) + 3),
                    'featured' => '1',
                    'seo_title' => $title,
                    'seo_description' => 'SEO ' . $title,
                    'external_url' => 'https://example.test/' . $alias,
                    'rating' => (string) (($articleNumber % 5) + 1),
                };
            }
            $published = date('Y-m-d H:i:s', 1_704_067_200 - $articleNumber);
            $article->execute([
                $articleNumber,
                $presence['author'] ? ($articleNumber % 25) + 1 : 0,
                $position,
                $alias,
                '/assets/images/' . $alias . '.jpg',
                'article',
                json_encode($tmplvars, JSON_UNESCAPED_SLASHES),
                $published,
                $now,
                $now,
            ]);
            $translate->execute([$articleNumber, 'base', $title, $title . ' with realistic editorial metadata', $introtext, $description, $content, '', '', 'index,follow', '[]', '[]', $now, $now]);
            $link->execute([$articleNumber, $categoryNumber]);
        }
    }

    // The admin round edits these five. They sit in a category of their own,
    // the counterpart of the document stacks' "Admin workload" folder, so an
    // editorial session never changes what the guest workload renders.
    $adminCategory = FixturePlan::CATEGORY_COUNT + 1;
    $category->execute([$adminCategory, $adminCategory, FixturePlan::ADMIN_ROOT_ALIAS, '', 'Admin workload', $now, $now]);
    for ($page = 1; $page <= FixturePlan::ADMIN_PAGE_COUNT; $page++) {
        $id = sArticlesAdminId($page);
        $article->execute([$id, 1, $page, FixturePlan::adminPageAlias($page), '', 'article', '{}', $now, $now, $now]);
        $translate->execute([$id, 'base', FixturePlan::adminPageTitle($page), FixturePlan::adminPageTitle($page), '', '', FixturePlan::adminPageContent($page), '', '', 'index,follow', '[]', '[]', $now, $now]);
        $link->execute([$id, $adminCategory]);
    }
    $pdo->commit();
}

/**
 * The category page of the sArticles stack: the same markup as the parser
 * snippet, read from the module's models through their public API (the
 * global translate scope, the categories and author relations, the
 * tmplvars), which 1.x and 2.x share. The document tree still resolves the
 * URL, exactly as it does for a site that keeps its pages in the CMS and
 * its articles in the module.
 */
function sArticlesSnippet(): string
{
    return <<<'PHP'
$evo = evo();
$page = $evo->documentObject;
$escape = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$items = \Seiger\sArticles\Models\sArticle::active()
    ->with('author')
    ->whereHas('categories', static fn ($query) => $query->where('s_articles_categories.alias', $page['alias']))
    ->orderBy('s_articles.position', 'ASC')
    ->limit(20)
    ->get();
echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $escape($page['pagetitle']) . '</title><meta name="description" content="' . $escape($page['description']) . '"></head><body><header><a href="/">Phramark benchmark</a></header><main><h1>' . $escape($page['pagetitle']) . '</h1><p class="intro">' . $escape($page['introtext']) . '</p><section class="articles">';
foreach ($items as $item) {
    $tmplvars = json_decode((string) ($item->tmplvars ?? '{}'), true) ?: [];
    echo '<article><img src="' . $escape($item->cover) . '" alt=""><h2><a href="/articles/' . $escape($page['alias']) . '/' . $escape($item->alias) . '">' . $escape($item->pagetitle) . '</a></h2><p class="intro">' . $escape($item->introtext) . '</p><p class="meta"><span class="author">' . $escape($item->author->base_name ?? '') . '</span> · <span class="reading-time">' . $escape($tmplvars['reading_time'] ?? '') . ' min</span></p></article>';
}
echo '</section></main><footer>Deterministic CMS benchmark fixture</footer></body></html>';
PHP;
}

/**
 * Restores the five articles of the admin round and removes every article
 * the round created, so repeated runs start from the same state. The guest
 * fixture is untouched: the round only ever edits these five and whatever it
 * created above them.
 */
function resetSArticlesAdminFixture(PDO $pdo): void
{
    $articles = '`' . sArticlesTable('s_articles') . '`';
    $translates = '`' . sArticlesTable('s_article_translates') . '`';
    $links = '`' . sArticlesTable('s_article_categories') . '`';
    $last = sArticlesAdminId(FixturePlan::ADMIN_PAGE_COUNT);

    $pdo->beginTransaction();
    $restoreArticle = $pdo->prepare("UPDATE {$articles} SET published = 1, alias = ?, cover = '', type = 'article' WHERE id = ?");
    $restoreText = $pdo->prepare("UPDATE {$translates} SET pagetitle = ?, longtitle = ?, introtext = '', description = '', content = ? WHERE article = ? AND lang = 'base'");
    for ($page = 1; $page <= FixturePlan::ADMIN_PAGE_COUNT; $page++) {
        $id = sArticlesAdminId($page);
        $restoreArticle->execute([FixturePlan::adminPageAlias($page), $id]);
        $restoreText->execute([FixturePlan::adminPageTitle($page), FixturePlan::adminPageTitle($page), FixturePlan::adminPageContent($page), $id]);
    }
    $created = $pdo->query("SELECT id FROM {$articles} WHERE id > {$last}")->fetchAll(PDO::FETCH_COLUMN);
    $ids = array_map('intval', $created);
    if ($ids !== []) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM {$translates} WHERE article IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM {$links} WHERE article IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM {$articles} WHERE id IN ({$placeholders})")->execute($ids);
    }
    $pdo->commit();

    printf("evo-sarticles: restored %d admin articles, removed %d created articles." . PHP_EOL, FixturePlan::ADMIN_PAGE_COUNT, count($ids));
}

/**
 * The module reads its settings from core/custom/config/seiger/settings/
 * sArticles.php, a file the manager only writes when settings are saved:
 * without it 1.x dies with "Failed opening required" on the article editor.
 * The package ships the defaults, so a fresh install gets them here.
 */
function ensureSArticlesSettings(string $site): void
{
    $target = '/sites/' . $site . '/core/custom/config/seiger/settings/sArticles.php';
    if (is_file($target)) {
        return;
    }
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0o775, true);
    }
    $defaults = '/sites/' . $site . '/core/vendor/seiger/sarticles/config/sArticlesSettings.php';
    copy(is_file($defaults) ? $defaults : '/dev/null', $target);
    if (filesize($target) === 0) {
        file_put_contents($target, "<?php

return [];
");
    }
    chmod($target, 0o664);
}

/** Evolution keeps a PHP site cache that OPcache holds; the reset clears it. */
function clearSiteCache(string $stack): void
{
    $site = '/sites/' . $stack . '/core';
    if (is_dir($site)) {
        passthru('cd ' . escapeshellarg($site) . ' && php artisan cache:clear-full', $status);
    }
}
