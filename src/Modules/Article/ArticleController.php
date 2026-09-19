<?php

declare(strict_types=1);

namespace StreamEngine\Modules\Article;

use StreamEngine\Core\AbstractController;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Domain\Feed;
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
            'article.show-id' => [
                'label' => 'Show a fixed article',
                'fields' => [
                    'feedId' => ['status' => 'required', 'feedTypes' => ['article']],
                ],
            ],
            'article.show-slug' => [
                'label' => 'Show an article from the route slug',
                'fields' => [
                    'feedType' => ['status' => 'required', 'values' => ['article']],
                ],
            ],
            'articles.list' => [
                'label' => 'List articles',
                'fields' => [
                    'feedType' => ['status' => 'required', 'values' => ['article-section']],
                    'listFeedType' => ['status' => 'required', 'values' => ['article']],
                ],
            ],
            'sections.list' => [
                'label' => 'List sections',
                'fields' => [
                    'feedId' => 'required',
                ],
            ],
        ];
    }

    public function __construct(
        PdoDatabase                  $db,
        RequestContext               $context,
        private readonly FeedService $feedService,
        private readonly PageTree    $pageTree,
    ) {
        parent::__construct($db, $context);
    }

    /**
     * @throws ForbiddenException
     */
    public function getBreadcrumb(Page $page): ?Breadcrumb
    {
        if ($page->action === 'articles.list' || $page->action === 'article.show-slug') {
            if (key_exists('slug', $page->params)) {
                $pageFeed = $this->resolveFeedForPage($page, (string) $page->params['slug']);
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
            'article.show-id' => $this->showArticlePage($page, $this->resolveArticleById($page)),
            'article.show-slug' => $this->showArticlePage(
                $page,
                $this->resolveFeedForPage($page, (string) ($args['slug'] ?? ''))
            ),
            default => throw new ForbiddenException('Unknown page action'),
        };
    }

    private function resolveArticleById(Page $page): ?Feed
    {
        if ($page->feedId === null) {
            return null;
        }

        return $this->feedService->getFeedById($page->feedId, $this->context->user);
    }

    /**
     * @throws ForbiddenException
     * @throws ValidationException
     */
    private function showArticlePage(Page $page, ?Feed $articleFeed): ViewModel
    {
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
        $rootFeed = $this->resolveFeedForPage($page, $slug);

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

    /**
     * Resolve a dynamic feed page in the same hierarchy as its route.
     *
     * A feed_id anchors the hierarchy. Every dynamic descendant is then
     * resolved by its parent feed, declared feed type and route slug, so the
     * same slug may safely exist in another section or feed type.
     */
    private function resolveFeedForPage(Page $page, ?string $slug = null): ?Feed
    {
        if ($page->feedId !== null) {
            return $this->feedService->getFeedById($page->feedId, $this->context->user);
        }

        if ($page->feedType === null) {
            return null;
        }

        $slug ??= isset($page->params['slug']) ? (string) $page->params['slug'] : null;
        if ($slug === null || $slug === '') {
            return null;
        }

        $parentPage = $this->findNearestFeedPage($page);
        $parentFeed = $parentPage !== null ? $this->resolveFeedForPage($parentPage) : null;

        if ($parentPage !== null && $parentFeed === null) {
            return null;
        }

        return $this->feedService->getFeedByParentAndSlug(
            $parentFeed?->id,
            $slug,
            $this->context->user,
            $page->feedType,
        );
    }

    private function findNearestFeedPage(Page $page): ?Page
    {
        $ancestors = $this->pageTree->ancestors($page);
        array_pop($ancestors);
        foreach (array_reverse($ancestors) as $parentPage) {
            if ($parentPage->feedId !== null || $parentPage->feedType !== null) {
                return $parentPage;
            }
        }

        return null;
    }
}
