<?php

declare(strict_types=1);

namespace Drupal\phramark_benchmark\Controller;

use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class CategoryController implements ContainerInjectionInterface
{
    public function __construct(private Connection $database, private RendererInterface $renderer)
    {
    }

    public static function create(ContainerInterface $container): static
    {
        return new static($container->get('database'), $container->get('renderer'));
    }

    public function show(string $category): Response
    {
        $number = self::number($category);
        if ($number < 1 || $number > 100) {
            throw new NotFoundHttpException();
        }

        $articles = $this->database->select('phramark_article', 'article')
            ->fields('article', ['title', 'introtext', 'alias', 'hero_image', 'author', 'reading_time', 'published_at'])
            ->condition('category', $number)
            ->orderBy('published_at', 'DESC')
            ->range(0, 20)
            ->execute()
            ->fetchAll();

        $build = [
            '#theme' => 'phramark_category',
            '#category' => sprintf('Category %03d practical PHP performance', $number),
            '#category_alias' => sprintf('category-%03d', $number),
            '#description' => 'A deterministic benchmark category with one hundred articles.',
            '#introtext' => 'A stable category description used by every benchmark stack.',
            '#articles' => $articles,
            '#cache' => ['max-age' => 0],
        ];

        // The Twig template is the whole shared page contract. Returning it as
        // a bare response keeps Drupal level with the TYPO3 and October
        // adapters, which also skip their CMS page/theme layer.
        $response = new Response((string) $this->renderer->renderRoot($build));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    // The route captures the whole "category-042" segment (see routing.yml).
    private static function number(string $alias): int
    {
        return (int) substr($alias, strlen('category-'));
    }
}
