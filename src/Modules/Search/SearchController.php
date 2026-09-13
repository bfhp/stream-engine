<?php

declare(strict_types=1);

namespace StreamEngine\Modules\Search;

use StreamEngine\Core\AbstractController;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Domain\Page;
use StreamEngine\View\Breadcrumb;
use StreamEngine\View\ViewModel;

class SearchController extends AbstractController
{
    public static function pageActions(): array
    {
        return [
            'search.results' => 'Search results',
        ];
    }

    private string $query;

    public function __construct(
        PdoDatabase $db,
        RequestContext $context
    ) {
        parent::__construct($db, $context);
        // Total by construction: a bare GET /search, or a ?q[]=a that makes
        // the value an array, both come back as the empty string rather than
        // as null against a non-nullable property.
        $this->query = $context->query->trimmed('q');
    }

    public function getBreadcrumb(Page $page): ?Breadcrumb
    {
        return new Breadcrumb(
            $page->pageName.': '.$this->query,
            $page->pattern
        );
    }

    public function show(Page $page, array $args = []): ?ViewModel
    {
        $data = [
            'title' => 'Search: '.$this->query,
            'head_ext' => [
                '<meta name="robots" content="noindex, follow">',
                '<script type="module" src="/assets/js/search.js" defer></script>',
            ],
            'search_string' => $this->query,
        ];

        return ViewModel::fromPage(
            $page,
            "modules/search/page.twig",
            $data
        );
    }
}
