<?php

namespace Phramark\Benchmark\Components;

use Cms\Classes\ComponentBase;
use Winter\Storm\Support\Facades\DB;

/**
 * Loads the 20 newest articles of one category for the theme page. The page
 * URL is "/articles/:slug" with a regex restricting the slug to
 * category-NNN; Winter's router matches whole segments only, so the number
 * cannot be a parameter of its own (Drupal has the same rule).
 */
class Category extends ComponentBase
{
    public function componentDetails(): array
    {
        return [
            'name' => 'Benchmark category',
            'description' => 'Renders one category of the Phramark fixture.',
        ];
    }

    public function onRun()
    {
        if (preg_match('/^category-(\d{3})$/', (string) $this->param('slug'), $matches) !== 1) {
            return $this->controller->run('404');
        }

        $category = (int) $matches[1];
        if ($category < 1 || $category > 100) {
            return $this->controller->run('404');
        }

        $this->page['category'] = sprintf('Category %03d practical PHP performance', $category);
        $this->page['categoryAlias'] = sprintf('category-%03d', $category);
        $this->page['description'] = 'A deterministic benchmark category with one hundred articles.';
        $this->page['introtext'] = 'A stable category description used by every benchmark stack.';
        $this->page['articles'] = DB::table('phramark_article')
            ->select(['title', 'introtext', 'alias', 'hero_image', 'author', 'reading_time', 'published_at'])
            ->where('category', $category)
            ->orderByDesc('published_at')
            ->limit(20)
            ->get();
    }
}
