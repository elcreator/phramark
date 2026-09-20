<?php

declare(strict_types=1);

namespace Phramark\Typo3Benchmark\Middleware;

use Doctrine\DBAL\ParameterType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

final readonly class CategoryMiddleware implements MiddlewareInterface
{
    public function __construct(private ConnectionPool $connections, private ViewFactoryInterface $viewFactory)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (preg_match('#^/articles/category-(\d{1,3})/?$#', $request->getUri()->getPath(), $matches) !== 1) {
            return $handler->handle($request);
        }

        $category = (int) $matches[1];
        if ($category < 1 || $category > 100) {
            return new HtmlResponse('Not found', 404);
        }

        $query = $this->connections->getQueryBuilderForTable('phramark_article');
        $articles = $query
            ->select('title', 'introtext', 'alias', 'hero_image', 'author', 'reading_time', 'published_at')
            ->from('phramark_article')
            ->where($query->expr()->eq('category', $query->createNamedParameter($category, ParameterType::INTEGER)))
            ->orderBy('published_at', 'DESC')
            ->setMaxResults(20)
            ->executeQuery()
            ->fetchAllAssociative();

        // TYPO3 13+ replaced StandaloneView with the core view factory (Fluid).
        $view = $this->viewFactory->create(new ViewFactoryData(
            templatePathAndFilename: 'EXT:phramark_benchmark/Resources/Private/Templates/Category.html',
            request: $request,
        ));
        $view->assignMultiple([
            'category' => sprintf('Category %03d practical PHP performance', $category),
            'categoryAlias' => sprintf('category-%03d', $category),
            'description' => 'A deterministic benchmark category with one hundred articles.',
            'introtext' => 'A stable category description used by every benchmark stack.',
            'articles' => $articles,
        ]);

        return new HtmlResponse($view->render(), 200, ['Cache-Control' => 'no-store']);
    }
}
