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
        public ?bool $commentsEnabled,
        public ?array $requestMethods,
        public ?string $responseType,
        public string $accessRule,
        public ?string $action = null,
        public ?string $changefreq = null,
        public ?int $updated = null,
        public ?string $termVocabulary = null,
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
            commentsEnabled: false,
            requestMethods: $requestMethods,
            responseType: 'json',
            accessRule: $accessRule,
            action: $action,
        );
    }
}
