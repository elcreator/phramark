<?php
/**
 * phramarkCategory: the 20 newest articles of the requested category, one
 * phramarkArticle chunk per row. The category number comes from the alias
 * of the resource MODX resolved for the request (articles/category-NNN), so
 * the routing is MODX's own alias map; the rows come from the shared
 * phramark_article table through xPDO (Phramark\Model\Article).
 *
 * Called uncached ([[!phramarkCategory]]) from the "Benchmark category"
 * template. MODX wraps snippet code in a function, so no "use" statements.
 *
 * @var \MODX\Revolution\modX $modx
 */
if (preg_match('/^category-(\d{3})$/', (string) $modx->resource->get('alias'), $matches) !== 1) {
    return '';
}
$category = (int) $matches[1];
if ($category < 1 || $category > 100) {
    return '';
}

$modx->addPackage('Phramark\\Model', MODX_CORE_PATH . 'components/phramark/src/', '', 'Phramark\\');
$query = $modx->newQuery(\Phramark\Model\Article::class);
$query->where(['category' => $category]);
$query->sortby('published_at', 'DESC');
$query->limit(20);

$cards = [];
foreach ($modx->getCollection(\Phramark\Model\Article::class, $query) as $article) {
    $row = $article->toArray();
    // A NULL value would leave the placeholder tag unprocessed until the
    // parser's final pass; every stack renders a missing value as empty.
    $row['author'] = (string) $row['author'];
    $row['reading_time'] = $row['reading_time'] === null ? '' : (string) $row['reading_time'];
    $row['categoryAlias'] = $matches[0];
    $cards[] = $modx->getChunk('phramarkArticle', $row);
}

return implode("\n", $cards);
