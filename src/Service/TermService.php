<?php

declare(strict_types=1);

namespace StreamEngine\Service;

use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\FeedTerm;
use StreamEngine\Repository\FeedTermRepository;

final readonly class TermService
{
    public function __construct(
        private FeedTermRepository $repository,
        private UrlGenerator $urlGenerator,
    ) {

    }

    /**
     * @return list<FeedTerm>
     */
    public function getTermsByVocabulary(string $vocabulary, int $limit = 20, int $offset = 0): array
    {
        $terms = $this->repository->findByVocabulary($vocabulary, $limit, $offset);
        $this->decorateTermsWithUrls($terms);

        return $terms;
    }

    public function countTermsByVocabulary(string $vocabulary): int
    {
        return $this->repository->countByVocabulary($vocabulary);
    }

    /**
     * @return list<FeedTerm>
     */
    public function getChildTerms(string $vocabulary, int $parentId): array
    {
        $terms = $this->repository->findChildren($vocabulary, $parentId);
        $this->decorateTermsWithUrls($terms);

        return $terms;
    }

    public function getTermByVocabularyAndSlug(string $vocabulary, string $slug): ?FeedTerm
    {
        $term = $this->repository->findByVocabularyAndSlug($vocabulary, $slug);

        if ($term !== null) {
            $this->decorateTermsWithUrls([$term]);
        }

        return $term;
    }

    /**
     * @param int[] $feedIds
     * @return array<int, list<FeedTerm>>
     */
    public function getTermsByFeedIdsAndVocabulary(array $feedIds, string $vocabulary): array
    {
        $termsByFeedId = $this->repository->findByFeedIdsAndVocabulary($feedIds, $vocabulary);

        foreach ($termsByFeedId as $terms) {
            $this->decorateTermsWithUrls($terms);
        }

        return $termsByFeedId;
    }

    /**
     * @param FeedTerm[] $terms
     */
    private function decorateTermsWithUrls(array $terms): void
    {
        foreach ($terms as $term) {
            $term->canonicalUrl = $this->urlGenerator->feedTerm($term);
        }
    }
}
