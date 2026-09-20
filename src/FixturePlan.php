<?php

declare(strict_types=1);

namespace Phramark;

final class FixturePlan
{
    public const CATEGORY_COUNT = 100;
    public const ARTICLES_PER_CATEGORY = 100;
    public const SEED = 424242;
    public const ARTICLES_ROOT_PARENT = 0;
    public const ADMIN_PAGE_COUNT = 5;
    public const ADMIN_ROOT_ID = 10103;
    public const ADMIN_ROOT_ALIAS = 'admin-workload';

    /** @return array{categories: int, articles: int, admin_pages: int, documents: int} */
    public static function counts(): array
    {
        $articles = self::CATEGORY_COUNT * self::ARTICLES_PER_CATEGORY;

        return [
            'categories' => self::CATEGORY_COUNT,
            'articles' => $articles,
            'admin_pages' => self::ADMIN_PAGE_COUNT,
            'documents' => $articles + self::CATEGORY_COUNT + 2 + 1 + self::ADMIN_PAGE_COUNT,
        ];
    }

    public static function articleId(int $number): int
    {
        self::assertBetween($number, 1, self::CATEGORY_COUNT * self::ARTICLES_PER_CATEGORY, 'article');

        return 102 + $number;
    }

    public static function adminPageId(int $number): int
    {
        self::assertBetween($number, 1, self::ADMIN_PAGE_COUNT, 'admin page');

        return self::ADMIN_ROOT_ID + $number;
    }

    public static function adminPageAlias(int $number): string
    {
        self::assertBetween($number, 1, self::ADMIN_PAGE_COUNT, 'admin page');

        return sprintf('admin-page-%d', $number);
    }

    public static function adminPageTitle(int $number): string
    {
        self::assertBetween($number, 1, self::ADMIN_PAGE_COUNT, 'admin page');

        return sprintf('Admin page %d', $number);
    }

    public static function adminPageContent(int $number): string
    {
        self::assertBetween($number, 1, self::ADMIN_PAGE_COUNT, 'admin page');

        return sprintf('<p>Admin workload page %d body.</p>', $number);
    }

    /**
     * Visible-page contract for one category: what every adapter must render
     * for the shared fixture, derived from the deterministic TV presence.
     *
     * @return array{title: string, articles: int, authors: int, reading_times: int, hero_images: int}
     */
    public static function expectedCategoryPage(int $category): array
    {
        self::assertBetween($category, 1, self::CATEGORY_COUNT, 'category');
        $first = ($category - 1) * self::ARTICLES_PER_CATEGORY + 1;
        $authors = 0;
        $readingTimes = 0;
        for ($number = $first; $number < $first + 20; $number++) {
            $presence = self::tvPresence($number);
            $authors += $presence['author'] ? 1 : 0;
            $readingTimes += $presence['reading_time'] ? 1 : 0;
        }

        return [
            'title' => self::categoryTitle($category),
            'articles' => 20,
            'authors' => $authors,
            'reading_times' => $readingTimes,
            'hero_images' => 20,
        ];
    }

    public static function categoryAlias(int $number): string
    {
        self::assertBetween($number, 1, self::CATEGORY_COUNT, 'category');

        return sprintf('category-%03d', $number);
    }

    public static function articleAlias(int $number): string
    {
        self::assertBetween($number, 1, self::CATEGORY_COUNT * self::ARTICLES_PER_CATEGORY, 'article');

        return sprintf('article-%06d', $number);
    }

    public static function categoryUrl(int $number): string
    {
        return '/articles/' . self::categoryAlias($number);
    }

    public static function categoryTitle(int $number): string
    {
        return sprintf('Category %03d practical PHP performance', $number);
    }

    public static function categoryDescription(): string
    {
        return 'A deterministic benchmark category with one hundred articles.';
    }

    public static function categoryIntrotext(): string
    {
        return 'A stable category description used by every benchmark stack.';
    }

    public static function articleTitle(int $number): string
    {
        self::assertBetween($number, 1, self::CATEGORY_COUNT * self::ARTICLES_PER_CATEGORY, 'article');

        return sprintf('Article %06d: a deterministic CMS benchmark fixture', $number);
    }

    /** @return array<string, bool> */
    public static function tvPresence(int $articleNumber): array
    {
        self::assertBetween($articleNumber, 1, self::CATEGORY_COUNT * self::ARTICLES_PER_CATEGORY, 'article');
        $hash = self::hash($articleNumber);

        return [
            'hero_image' => true,
            'author' => $hash % 100 < 95,
            'reading_time' => intdiv($hash, 101) % 100 < 95,
            'featured' => intdiv($hash, 10_201) % 100 < 10,
            'seo_title' => intdiv($hash, 1_030_301) % 100 < 30,
            'seo_description' => intdiv($hash, 7_103) % 100 < 40,
            'external_url' => intdiv($hash, 97) % 100 < 5,
            'rating' => intdiv($hash, 13) % 100 < 50,
        ];
    }

    private static function hash(int $value): int
    {
        return (int) sprintf('%u', crc32((string) (self::SEED . ':' . $value)));
    }

    private static function assertBetween(int $value, int $minimum, int $maximum, string $name): void
    {
        if ($value < $minimum || $value > $maximum) {
            throw new \InvalidArgumentException(sprintf('%s must be between %d and %d.', $name, $minimum, $maximum));
        }
    }
}
