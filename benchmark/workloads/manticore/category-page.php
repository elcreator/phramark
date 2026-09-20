<?php

declare(strict_types=1);

/**
 * Category-page render workload for the Manticore AOT compiler experiment.
 *
 * One self-contained file, because `manticore compile` takes a single source
 * unit and resolves no `require`: it renders the shared category page
 * contract (the markup of the Evolution `benchmarkCategory` snippet in
 * benchmark/fixtures/setup.php) for the fixture's 20 article cards per
 * category, from in-memory rows shaped like Evolution's getDocumentChildren()
 * result, with the TV presence rule of Phramark\FixturePlan. No database, no
 * CMS bootstrap: it measures the PHP-side page assembly only.
 *
 * Usage: php category-page.php [pages]   — or the compiled binary with the
 * same argument. Prints "pages=N bytes=B checksum=C"; the checksum must be
 * identical between the interpreter and the native binary (output parity).
 */
final class CategoryPageWorkload
{
    public const CATEGORY_COUNT = 100;
    public const ARTICLES_PER_CATEGORY = 100;
    public const CARDS_PER_PAGE = 20;
    public const SEED = 424242;

    /** @return array{id: int, pagetitle: string, description: string, introtext: string, alias: string} */
    public static function category(int $category): array
    {
        return [
            'id' => 2 + $category,
            'pagetitle' => sprintf('Category %03d practical PHP performance', $category),
            'description' => 'A deterministic benchmark category with one hundred articles.',
            'introtext' => 'A stable category description used by every benchmark stack.',
            'alias' => sprintf('category-%03d', $category),
        ];
    }

    /** @return list<array{id: int, pagetitle: string, introtext: string, alias: string, pub_date: int}> */
    public static function articles(int $category): array
    {
        $first = ($category - 1) * self::ARTICLES_PER_CATEGORY + 1;
        $items = [];
        for ($number = $first; $number < $first + self::CARDS_PER_PAGE; $number++) {
            $items[] = [
                'id' => 102 + $number,
                'pagetitle' => sprintf('Article %06d: a deterministic CMS benchmark fixture', $number),
                'introtext' => str_repeat('A concise article card introduction. ', 10),
                'alias' => sprintf('article-%06d', $number),
                'pub_date' => 1_704_067_200 - $number,
            ];
        }

        return $items;
    }

    /** @return array<string, string> the TV values Evolution's getTemplateVarOutput('*') returns for one article */
    public static function templateVariables(int $articleNumber): array
    {
        $hash = self::hash($articleNumber);
        $alias = sprintf('article-%06d', $articleNumber);
        $tvs = ['hero_image' => '/assets/images/' . $alias . '.jpg'];
        if ($hash % 100 < 95) {
            $tvs['author'] = 'Author ' . (($articleNumber % 25) + 1);
        }
        if (intdiv($hash, 101) % 100 < 95) {
            $tvs['reading_time'] = (string) (($articleNumber % 12) + 3);
        }
        if (intdiv($hash, 10_201) % 100 < 10) {
            $tvs['featured'] = '1';
        }
        if (intdiv($hash, 1_030_301) % 100 < 30) {
            $tvs['seo_title'] = sprintf('Article %06d: a deterministic CMS benchmark fixture', $articleNumber);
        }
        if (intdiv($hash, 7_103) % 100 < 40) {
            $tvs['seo_description'] = sprintf('SEO Article %06d: a deterministic CMS benchmark fixture', $articleNumber);
        }
        if (intdiv($hash, 97) % 100 < 5) {
            $tvs['external_url'] = 'https://example.test/' . $alias;
        }
        if (intdiv($hash, 13) % 100 < 50) {
            $tvs['rating'] = (string) (($articleNumber % 5) + 1);
        }

        return $tvs;
    }

    /**
     * The visible page contract, byte-comparable with the Evolution snippet.
     *
     * @param array{id: int, pagetitle: string, description: string, introtext: string, alias: string} $page
     * @param list<array{id: int, pagetitle: string, introtext: string, alias: string, pub_date: int}> $items
     */
    public static function render(array $page, array $items): string
    {
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . self::escape($page['pagetitle']) . '</title><meta name="description" content="' . self::escape($page['description']) . '"></head><body><header><a href="/">Phramark benchmark</a></header><main><h1>' . self::escape($page['pagetitle']) . '</h1><p class="intro">' . self::escape($page['introtext']) . '</p><section class="articles">';
        foreach ($items as $item) {
            $tvs = self::templateVariables($item['id'] - 102);
            $html .= '<article><img src="' . self::escape($tvs['hero_image'] ?? '') . '" alt=""><h2><a href="/articles/' . self::escape($page['alias']) . '/' . self::escape($item['alias']) . '">' . self::escape($item['pagetitle']) . '</a></h2><p class="intro">' . self::escape($item['introtext']) . '</p><p class="meta"><span class="author">' . self::escape($tvs['author'] ?? '') . '</span> · <span class="reading-time">' . self::escape($tvs['reading_time'] ?? '') . ' min</span></p></article>';
        }

        return $html . '</section></main><footer>Deterministic CMS benchmark fixture</footer></body></html>';
    }

    public static function renderCategory(int $category): string
    {
        return self::render(self::category($category), self::articles($category));
    }

    /** @return array{pages: int, bytes: int, checksum: int} */
    public static function run(int $pages): array
    {
        $bytes = 0;
        $checksum = 0;
        for ($index = 0; $index < $pages; $index++) {
            $html = self::renderCategory(($index % self::CATEGORY_COUNT) + 1);
            $bytes += strlen($html);
            // Every distinct page (one per category) enters the checksum
            // exactly once, then the pages repeat: full output parity, while
            // the CRC (a PHP-implemented stdlib function in Manticore) stays
            // out of the timed page assembly. Sum of 32-bit values modulo a
            // prime: far below PHP_INT_MAX, where Manticore's wrapping
            // integers and Zend's agree.
            if ($index < self::CATEGORY_COUNT) {
                $checksum = ($checksum + (int) sprintf('%u', crc32($html))) % 1_000_000_007;
            }
        }

        return ['pages' => $pages, 'bytes' => $bytes, 'checksum' => $checksum];
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function hash(int $value): int
    {
        return (int) sprintf('%u', crc32(self::SEED . ':' . $value));
    }
}

if (!defined('PHRAMARK_WORKLOAD_LIB')) {
    $pages = (int) ($argv[1] ?? 1);
    if ($pages < 1) {
        fwrite(STDERR, "usage: category-page [pages>=1]\n");
        exit(2);
    }
    $result = CategoryPageWorkload::run($pages);
    echo 'pages=', $result['pages'], ' bytes=', $result['bytes'], ' checksum=', $result['checksum'], "\n";
}
