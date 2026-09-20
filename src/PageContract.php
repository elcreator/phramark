<?php

declare(strict_types=1);

namespace Phramark;

/**
 * Checks that a rendered category page carries the shared visible contract.
 *
 * Every adapter must render the same fixture the same way before its numbers
 * mean anything; an adapter that skips TV values or answers with a redirect
 * does less work and would otherwise look faster.
 */
final class PageContract
{
    /** @return array{title: string, articles: int, authors: int, reading_times: int, hero_images: int, redirect: bool} */
    public static function inspect(string $html, int $status = 200): array
    {
        preg_match('#<title>([^<]*)</title>#', $html, $title);

        return [
            'title' => html_entity_decode(trim($title[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'articles' => preg_match_all('#<article>#', $html),
            'authors' => preg_match_all('#<span class="author">Author \d+</span>#', $html),
            'reading_times' => preg_match_all('#<span class="reading-time">\d+ min</span>#', $html),
            'hero_images' => preg_match_all('#<img src="/assets/images/article-\d{6}\.jpg"#', $html),
            'redirect' => $status >= 300 && $status < 400,
        ];
    }

    /**
     * Human-readable differences between the expected contract and a page,
     * empty when the page satisfies it.
     *
     * @return list<string>
     */
    public static function violations(int $category, string $html, int $status = 200): array
    {
        $expected = FixturePlan::expectedCategoryPage($category);
        $actual = self::inspect($html, $status);
        $violations = [];
        if ($status !== 200) {
            $violations[] = sprintf('expected HTTP 200, got %d%s', $status, $actual['redirect'] ? ' (redirect)' : '');
        }
        foreach ($expected as $key => $value) {
            if ($actual[$key] !== $value) {
                $violations[] = sprintf('%s: expected %s, got %s', $key, var_export($value, true), var_export($actual[$key], true));
            }
        }

        return $violations;
    }
}
