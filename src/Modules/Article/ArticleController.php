<?php

declare(strict_types=1);

namespace StreamEngine\Modules\Article;

use RuntimeException;
use StreamEngine\Core\AbstractController;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Domain\Page;
use StreamEngine\Service\FeedService;
use StreamEngine\View\Breadcrumb;
use StreamEngine\View\ViewModel;

class ArticleController extends AbstractController
{
    public static function feedTypes(): array
    {
        return [
            'article' => 'Статья',
            'article-section' => 'Раздел статьи',
        ];
    }

    public static function pageActions(): array
    {
        return [
            'article.show' => 'Show article (default)',
            'articles.list' => 'List articles',
            'sections.list' => 'List sections',
        ];
    }

    public function __construct(
        PdoDatabase                  $db,
        RequestContext               $context,
        private readonly FeedService $feedService,
    ) {
        parent::__construct($db, $context);
    }

    /**
     * @throws ForbiddenException
     */
    public function getBreadcrumb(Page $page): ?Breadcrumb
    {
        if ($page->action === 'articles.list' || $page->action === 'article.show') {
            if (key_exists('slug', $page->params)) {
                //TODO do better detection
                $feeds = $this->feedService->getFeedBySlug($page->params['slug'], $this->context->user);
                $pageFeed = array_pop($feeds);
            } else {
                return parent::getBreadcrumb($page);
            }
            if (!$pageFeed) {
                throw new ForbiddenException('Feed not found');
            }
            return new Breadcrumb($pageFeed->title, $pageFeed->slug);
        }
        return parent::getBreadcrumb($page);
    }

    /**
     * @param Page $page
     * @param array $args
     * @return ViewModel|null
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function show(Page $page, array $args = []): ?ViewModel
    {
        return match ($page->action) {
            'articles.list' => $this->showArticleListPage($page, (string) $args['slug']),
            'sections.list' => $this->showSectionsListPage($page),
            'article.show' => $this->showArticlePage($page, $page->pattern === '{slug}' ? (string) $args['slug'] : null),
            default => throw new ForbiddenException('Unknown page action'),
        };
    }

    /**
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws ValidationException
     */
    public function showArticlePage(Page $page, ?string $slug): ?ViewModel
    {
        if ($page->feedId) {
            $articleFeed = $this->feedService->getFeedById($page->feedId, $this->context->user);
        } elseif ($page->pattern === '{slug}') {
            $feeds = $this->feedService->getFeedBySlug($slug, $this->context->user);
            $articleFeed = array_pop($feeds);
        } else {
            throw new RuntimeException('Feed not defined');
        }

        if (!$articleFeed) {
            throw new ForbiddenException('Article feed not found');
        }

        if ($articleFeed->parentId) {
            $siblings = (object) $this->feedService->getPrevNext($articleFeed, $this->context->user);
        }

        if ($page->commentsEnabled) {
            $comments = $this->feedService->getComments($articleFeed->id, null, $this->context->user);
        }

        return ViewModel::fromPage(
            $page,
            'modules/article/article.show.twig',
            [
                'title' => $articleFeed->title,
                'description' => $articleFeed->description,
                'image' => $articleFeed->imageUrl,
                'canonical' => $articleFeed->canonicalUrl,
                'feed' => $articleFeed,
                'siblings' => $siblings ?? null,
                'comments' => $comments ?? null,
            ]
        );
    }

    /**
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    public function showSectionsListPage(Page $page): ?ViewModel
    {
        // A `sections.list` page with no feed_id is a misconfigured row, and
        // getFeedById() takes a non-nullable int - so without this it was a
        // TypeError, i.e. a 500 for a page that simply has nothing to show.
        if (! $page->feedId) {
            throw new NotFoundException('Root feed not defined');
        }

        $rootFeed = $this->feedService->getFeedById($page->feedId, $this->context->user);
        if (!$rootFeed) {
            throw new ForbiddenException('Root feed not found');
        }
        $sections = $this->feedService->getFeedsByParentAndType(
            $rootFeed->id,
            'article-section',
            $this->context->user
        );

        foreach ($sections as $sectionFeed) {
            $sectionFeed->children = $this->feedService->getFeedsByParentAndType(
                $sectionFeed->id,
                'article',
                $this->context->user
            );
        }

        return ViewModel::fromPage(
            $page,
            'modules/article/sections.list.twig',
            [
                'title' => $rootFeed->title,
                'description' => $rootFeed->description,
                'image' => $rootFeed->imageUrl,
                'canonical' => $rootFeed->canonicalUrl,
                'feed' => $rootFeed,
                'sections' => $sections,
            ]
        );
    }

    /**
     * @throws ForbiddenException
     */
    public function showArticleListPage(Page $page, string $slug): ?ViewModel
    {
        $feeds = $this->feedService->getFeedBySlug($slug, $this->context->user);
        $rootFeed = array_pop($feeds);

        if (!$rootFeed) {
            throw new ForbiddenException('Root feed not found');
        }

        if (isset($page->listFeedType)) {
            $subFeeds = $this->feedService->getFeedsByParentAndType(
                $rootFeed->id,
                $page->listFeedType, //TODO
                $this->context->user
            );
        }

        return ViewModel::fromPage(
            $page,
            'modules/article/articles.list.twig',
            [
                'title' => $rootFeed->title,
                'description' => $rootFeed->description,
                'image' => $rootFeed->imageUrl,
                'canonical' => $rootFeed->canonicalUrl,
                'feed' => $rootFeed,
                'subFeeds' => $subFeeds ?? null,
            ]
        );
    }
}
