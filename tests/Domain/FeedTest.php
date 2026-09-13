<?php

declare(strict_types=1);

namespace Tests\Domain;

use PHPUnit\Framework\TestCase;
use StreamEngine\Domain\Feed;

final class FeedTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'id' => 5,
            'parent_id' => null,
            'owner_id' => 3,
            'type' => 'blog-post',
            'slug' => 'post',
            'title' => 'Post',
            'content' => '',
            'description' => null,
            'image_url' => null,
            'container_id' => null,
            'visibility' => 'public',
            'position' => 0,
            'created_at' => null,
            'nick' => 'Author',
            'avatar_url' => '',
        ];
    }

    /**
     * Feed is a plain Domain object with no knowledge of any default/
     * fallback asset path (that's a presentation concern, resolved by
     * Service\UserService::resolveAvatarUrl() wherever a view needs it -
     * see UsersController::buildCommunityPostCards()/showBlogPostPage()) -
     * so an empty avatar_url column should come through untouched, not
     * silently rewritten to some hardcoded default here.
     */
    public function testFromRowKeepsAuthorAvatarUrlRawWhenMissing(): void
    {
        $feed = Feed::fromRow([
            'id' => 5,
            'parent_id' => null,
            'owner_id' => 3,
            'type' => 'blog-post',
            'slug' => 'post',
            'title' => 'Post',
            'content' => '',
            'description' => null,
            'image_url' => null,
            'container_id' => null,
            'visibility' => 'public',
            'position' => 0,
            'created_at' => null,
            'nick' => 'Author',
            'avatar_url' => '',
        ]);

        $this->assertSame('', $feed->authorAvatarUrl);
    }

    public function testFromRowKeepsAuthorAvatarUrlRawWhenSet(): void
    {
        $feed = Feed::fromRow([
            'id' => 5,
            'parent_id' => null,
            'owner_id' => 3,
            'type' => 'blog-post',
            'slug' => 'post',
            'title' => 'Post',
            'content' => '',
            'description' => null,
            'image_url' => null,
            'container_id' => null,
            'visibility' => 'public',
            'position' => 0,
            'created_at' => null,
            'nick' => 'Author',
            'avatar_url' => '/uploads/avatar-3.webp',
        ]);

        $this->assertSame('/uploads/avatar-3.webp', $feed->authorAvatarUrl);
    }

    /* ===============================
       getRelevance
    =============================== */

    /**
     * Relevance is null on every feed that didn't come out of a search, and the
     * search cursor compares it numerically - `HAVING relevance < ? OR
     * (relevance = ? AND id < ?)`. A null leaking into that comparison is how
     * keyset pagination starts repeating rows, so the accessor collapses it to
     * 0.0 and the private property makes the accessor the only way in.
     */
    public function testRelevanceIsZeroRatherThanNullOutsideSearch(): void
    {
        $this->assertSame(0.0, Feed::fromRow(self::row())->getRelevance());
    }

    public function testRelevanceIsCarriedThroughAsAFloat(): void
    {
        // MySQL hands back MATCH() scores as strings; the cursor needs a float.
        $feed = Feed::fromRow(self::row(['relevance' => '12.5']));

        $this->assertSame(12.5, $feed->getRelevance());
    }

    public function testAZeroScoreIsKeptRatherThanTreatedAsAbsent(): void
    {
        // Distinct from "no relevance at all" only in intent here, but the row
        // must not be dropped from a page because its score was 0.
        $this->assertSame(0.0, Feed::fromRow(self::row(['relevance' => 0]))->getRelevance());
    }

    /* ===============================
       ratingAverage
    =============================== */

    public function testRatingAverageIsZeroWhenNobodyHasVoted(): void
    {
        // Guarded rather than divided - the obvious implementation is a
        // division by zero on every unrated feed, which is most of them.
        $feed = Feed::fromRow(self::row(['rating_sum' => 0, 'rating_count' => 0]));

        $this->assertSame(0.0, $feed->ratingAverage());
    }

    public function testRatingAverageDividesTheSumByTheCount(): void
    {
        $feed = Feed::fromRow(self::row(['rating_sum' => 9, 'rating_count' => 4]));

        // A float, not integer division - 2.25 stars, not 2.
        $this->assertSame(2.25, $feed->ratingAverage());
    }
}
