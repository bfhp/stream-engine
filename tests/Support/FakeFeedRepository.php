<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use StreamEngine\Core\FeedRepositoryInterface;
use StreamEngine\Domain\Feed;

final class FakeFeedRepository implements FeedRepositoryInterface
{
    /**
     * @var array<int, Feed>
     */
    private array $feeds;

    public function __construct(array $feeds)
    {
        $this->feeds = $feeds;
    }

    /**
     * @param int[] $ids
     * @return array<int, Feed>
     */
    public function hydrateTree(array $ids): array
    {
        $result = [];
        $toLoad = $ids;
        $depth = 0;

        while (!empty($toLoad)) {

            if ($depth++ > 10) {
                throw new RuntimeException('Max depth exceeded');
            }

            $next = [];

            foreach ($toLoad as $id) {

                if (isset($result[$id])) {
                    continue;
                }

                if (!isset($this->feeds[$id])) {
                    continue;
                }

                $feed = $this->feeds[$id];
                $result[$id] = $feed;

                if ($feed->parentId !== null) {
                    $next[] = $feed->parentId;
                }
            }

            $toLoad = $next;
        }

        return $result;
    }
}
