<?php
/**
 * Category page: the 20 newest articles of the category WordPress resolved
 * (a child page of "Articles"), read from the shared phramark_article table
 * through $wpdb and rendered through Gantry's Timber/Twig pipeline with the
 * theme's outline, exactly the way g5_hydrogen/page.php renders a page.
 */

declare(strict_types=1);

defined('ABSPATH') or die;

use Gantry\Framework\Gantry;
use Gantry\Framework\Theme;
use Timber\Timber;

$gantry = Gantry::instance();

/** @var Theme $theme */
$theme = $gantry['theme'];

// The <head> is rendered before plugin content is added (see page.php).
$context = Timber::get_context();
$context['page_head'] = $theme->render('partials/page_head.html.twig', $context);

$post = Timber::query_post();
$context['post'] = $post;
$category = phramark_category_number(get_post());

global $wpdb;
$articles = $wpdb->get_results($wpdb->prepare(
    'SELECT title, introtext, alias, hero_image, author, reading_time FROM phramark_article WHERE category = %d ORDER BY published_at DESC LIMIT 20',
    $category
), ARRAY_A);

$context['title'] = $post->post_title;
$context['introtext'] = $post->post_excerpt;
$context['categoryAlias'] = $post->post_name;
$context['articles'] = $articles;

Timber::render(['phramark-category.html.twig'], $context);
