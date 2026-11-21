<?php

declare(strict_types=1);

namespace StreamEngine\Domain;

use StreamEngine\Service\AccessService;

final class Page
{
    public array $params = [];

    public function __construct(
        public int $id,
        public ?int $parentId,
        public string $pattern,
        public ?string $pageName,
        public ?object $settings,
        public ?string $feedType,
        public ?string $listFeedType,
        public ?int $feedId,
        public ?array $requestMethods,
        public ?string $responseType,
        public string $accessRule,
        public ?string $action = null,
        public ?string $changefreq = null,
        public ?int $updated = null,
        public ?string $termVocabulary = null,
        public bool $runtime = false,
    ) {

    }

    /**
     * Whether this page answers the given HTTP verb.
     *
     * On the Page because it is a question about the page, and because the
     * caller in StreamEngine::handleRequest() was
     * `in_array($_SERVER['REQUEST_METHOD'], $page->requestMethods, true)` -
     * and `$requestMethods` is nullable, so a row with a NULL column would
     * have been a TypeError there rather than the 405 it should be. Nothing
     * produces such a row today (PageRepository hard-codes `['GET']`), which
     * is exactly why it would have gone unnoticed.
     *
     * A page that declares no methods allows none: for a guard, "not stated"
     * has to read as "no".
     */
    public function allowsMethod(string $method): bool
    {
        return in_array($method, $this->requestMethods ?? [], true);
    }

    /**
     * Whether comments are shown on this page. The flag lives in the page's
     * `settings` JSON (edited in admin); runtime pages set it there too.
     */
    public function commentsEnabled(): bool
    {
        return (bool) ($this->settings->commentsEnabled ?? false);
    }

    /**
     * Widget placements hidden on this page (`settings.hiddenWidgets`, edited
     * in admin). Every placement is shown unless listed here, so a page
     * without the key - and a placement added later - shows everything.
     *
     * @return list<string>
     */
    public function hiddenWidgets(): array
    {
        $hidden = $this->settings->hiddenWidgets ?? [];
        if (! is_array($hidden)) {
            return [];
        }

        return array_values(array_filter($hidden, 'is_string'));
    }

    /**
     * Hidden placements in effect for the last page of a resolved route
     * (the router's breadcrumbs, root first). A runtime page - a blog post,
     * a photo, an editor registered in code under a profile, community or
     * forum - can't be edited in admin, so unless it states its own list it
     * follows its nearest admin-managed ancestor: hiding a placement on
     * user.show also hides it on that profile's posts and photos.
     *
     * @param list<Page> $chain
     * @return list<string>
     */
    public static function hiddenWidgetsAlong(array $chain): array
    {
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $page = $chain[$i];
            if (! $page->runtime || isset($page->settings->hiddenWidgets)) {
                return $page->hiddenWidgets();
            }
        }

        return [];
    }

    public function relatedArticlesEnabled(): bool
    {
        return ($this->settings->relatedArticlesEnabled ?? false) === true;
    }

    public static function api(
        int $id,
        int $parentId,
        string $pattern,
        array $requestMethods,
        ?string $action = null,
        ?object $settings = null,
        string $accessRule = AccessService::ACCESS_PUBLIC,
    ): self {
        return new self(
            id: $id,
            parentId: $parentId,
            pattern: $pattern,
            pageName: null,
            settings: $settings,
            feedType: null,
            listFeedType: null,
            feedId: null,
            requestMethods: $requestMethods,
            responseType: 'json',
            accessRule: $accessRule,
            action: $action,
        );
    }

    public static function runtime(
        int $id,
        int $parentId,
        string $pattern,
        string $action,
        string $accessRule,
        ?string $feedType,
        ?string $changefreq,
        ?object $settings,
        ?string $pageName = null,
    ): self {
        return new self(
            id: $id,
            parentId: $parentId,
            pattern: $pattern,
            pageName: $pageName,
            settings: $settings,
            feedType: $feedType,
            listFeedType: null,
            feedId: null,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: $accessRule,
            action: $action,
            changefreq: $changefreq,
            runtime: true,
        );
    }
}
