<?php
/**
 * Plugin Name: Phramark benchmark
 * Description: Routes the benchmark category pages through the active Gantry 5 theme and keeps the admin workload local (no wordpress.org feeds, update checks or cron spawns in a timed step).
 */

declare(strict_types=1);

defined('ABSPATH') or die;

// Mirrors benchmark/fixtures/cms/wordpress-seed.php: the "Articles" page and
// its one hundred child pages, which WordPress resolves through its own
// hierarchical page rewrite (articles/category-042 -> pagename).
const PHRAMARK_ARTICLES_ROOT_ID = 2;

/**
 * The category number of the resolved page, or null on any other request.
 */
function phramark_category_number(?WP_Post $post): ?int
{
    if ($post === null || $post->post_type !== 'page' || (int) $post->post_parent !== PHRAMARK_ARTICLES_ROOT_ID) {
        return null;
    }
    if (preg_match('/^category-(\d{3})$/', $post->post_name, $matches) !== 1) {
        return null;
    }
    $category = (int) $matches[1];

    return $category >= 1 && $category <= 100 ? $category : null;
}

// The category template renders through Gantry (Timber/Twig, the theme's
// outline) like the theme's own page.php; only the content block differs.
add_filter('template_include', static function (string $template): string {
    if (is_page() && phramark_category_number(get_post()) !== null) {
        return __DIR__ . '/phramark-benchmark/category.php';
    }

    return $template;
});

// The shared page contract is the bare category title; WordPress would
// append " – Phramark" (the site name) to every document title.
add_filter('document_title_parts', static function (array $parts): array {
    if (is_page() && phramark_category_number(get_post()) !== null) {
        unset($parts['site'], $parts['tagline']);
    }

    return $parts;
});

// Admin workload: the page body is edited as plain HTML in the Classic
// Editor's Text tab, the same textarea the Drupal, Evolution and Winter
// fixtures use, so every stack posts the same bytes.
add_filter('wp_default_editor', static fn (): string => 'html');

// The dashboard's "WordPress Events and News" widget fetches wordpress.org
// feeds and Site Health tests reach api.wordpress.org; the login step must
// time the CMS, not the internet (the MODX stack drops its feed widgets
// for the same reason).
add_action('wp_dashboard_setup', static function (): void {
    remove_meta_box('dashboard_primary', 'dashboard', 'side');
    remove_meta_box('dashboard_site_health', 'dashboard', 'normal');
    remove_action('welcome_panel', 'wp_welcome_panel');
});
