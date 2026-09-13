<?php

declare(strict_types=1);

namespace Tests\Modules\Users;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Users\BlogPostService;
use StreamEngine\Modules\Users\FriendService;
use StreamEngine\Repository\ConversationRepository;
use StreamEngine\Repository\FeedRepository;
use StreamEngine\Repository\MembershipRepository;
use StreamEngine\Repository\MessageRepository;
use StreamEngine\Repository\NotificationDeliveryRepository;
use StreamEngine\Repository\NotificationPreferenceRepository;
use StreamEngine\Repository\ParticipantRepository;
use StreamEngine\Repository\UploadRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\MessageService;
use StreamEngine\Service\NotificationService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;
use Tests\Support\FakePdoDatabase;

/**
 * FeedRepository/MembershipRepository/MessageService are all `final` (or
 * wrap other `final` classes), so they can't be createStub()'d - real
 * instances are built around a shared FakePdoDatabase instead (same
 * technique MessageServiceTest already uses for the messenger half; this
 * file extends it to also cover feeds/memberships). BlogPostService is the
 * one collaborator that *is* mockable directly, since it's a plain
 * (non-final) class.
 */
final class FriendServiceTest extends TestCase
{
    private const int A_BLOG_ID = 101;

    private const int B_BLOG_ID = 102;

    private function makeUser(int $id, string $role = AccessService::ROLE_USER, string $nick = '', string $username = ''): User
    {
        return new User(
            id: $id,
            email: "user{$id}@example.com",
            role: $role,
            nick: $nick ?: "User {$id}",
            username: $username,
        );
    }

    /**
     * A minimal 'user.show' page + real PageTree/UrlGenerator, so
     * FriendService::buildProfileUrl() has something to resolve - same
     * {username} pattern UsersControllerTest's own makeUserShowPage() uses.
     *
     * @return array{0: PageTree, 1: UrlGenerator}
     */
    private function makePageTreeAndUrlGenerator(): array
    {
        $pageTree = new PageTree([
            new Page(
                id: 1,
                parentId: null,
                pattern: '{username}',
                pageName: null,
                settings: null,
                feedType: null,
                listFeedType: null,
                feedId: null,
                commentsEnabled: false,
                requestMethods: ['GET'],
                responseType: 'html',
                accessRule: AccessService::ACCESS_PUBLIC,
                action: 'user.show',
            ),
        ]);

        return [$pageTree, new UrlGenerator($pageTree, new FakeFeedRepository([]), new ArrayCache())];
    }

    /**
     * @return array{0: FriendService, 1: FakePdoDatabase}
     */
    private function makeService(User $userA, User $userB): array
    {
        $db = new FakePdoDatabase();
        $db->addBlogFeed(self::A_BLOG_ID, $userA->id);
        $db->addBlogFeed(self::B_BLOG_ID, $userB->id);

        $feeds = new FeedRepository($db);
        $memberships = new MembershipRepository($db);

        $messageService = new MessageService(
            $db,
            new MessageRepository($db),
            new ParticipantRepository($db),
            new ConversationRepository($db),
            new UserRepository($db),
            new UserSessionRepository($db),
            new UploadRepository($db)
        );

        $blogPostService = $this->createStub(BlogPostService::class);
        $blogPostService->method('getOrCreateUserBlogFeed')->willReturnCallback(
            fn (User $user): Feed => Feed::fromRow([
                'id' => $user->id === $userA->id ? self::A_BLOG_ID : self::B_BLOG_ID,
                'parent_id' => null,
                'owner_id' => $user->id,
                'type' => 'blog',
                'slug' => null,
                'title' => 'Blog',
                'description' => null,
                'image_url' => null,
                'content' => null,
                'container_id' => null,
                'visibility' => 'public',
                'position' => 0,
                'created_at' => 1700000000,
            ])
        );

        [$pageTree, $urlGenerator] = $this->makePageTreeAndUrlGenerator();

        $service = new FriendService(
            $blogPostService,
            $feeds,
            $memberships,
            new NotificationService(
                $messageService,
                new NotificationDeliveryRepository($db),
                new NotificationPreferenceRepository($db),
                new UserRepository($db),
                (new \ReflectionClass(\StreamEngine\Service\MailService::class))->newInstanceWithoutConstructor(),
            ),
            new TranslationManager('ru', 'en'),
            $pageTree,
            $urlGenerator,
        );

        return [$service, $db];
    }

    private function trans(string $key, array $params = []): string
    {
        return (new TranslationManager('ru', 'en'))->trans($key, $params);
    }

    public function testSendRequestCreatesOneDirectionalSubscriptionAndNotifiesTarget(): void
    {
        $a = $this->makeUser(10, nick: 'Alice');
        $b = $this->makeUser(20, nick: 'Bob');
        [$service, $db] = $this->makeService($a, $b);

        $service->sendRequest($a, $b);

        $this->assertSame('subscribed', $service->getRelationshipStatus($a, $b));
        $this->assertSame('incoming', $service->getRelationshipStatus($b, $a));

        $this->assertCount(1, $db->notificationDeliveries);
        $this->assertSame($b->id, $db->notificationDeliveries[0]['recipient_user_id']);
        $this->assertSame('messenger', $db->notificationDeliveries[0]['channel']);
        $this->assertSame($this->trans('friend.new_request', ['name' => 'Alice']), $db->notificationDeliveries[0]['message_text']);
        $this->assertSame([], $db->messages);
    }

    /**
     * The notification names $actor but otherwise leaves $target no way to
     * get to their profile - sendRequest() appends a real <a href> link to
     * it when $actor has a username to build one from (see
     * FriendService::buildProfileUrl()'s own note on why it's absolute).
     * The exact markup isn't asserted byte-for-byte since it passes through
     * the queued delivery byte-for-byte; MessageService will sanitize it
     * later when the cron worker sends the delivery.
     */
    public function testSendRequestAppendsActorsProfileLinkWhenUsernameIsSet(): void
    {
        $_SERVER['SERVER_NAME'] = 'example.test';
        $a = $this->makeUser(10, nick: 'Alice', username: 'alice10');
        $b = $this->makeUser(20, nick: 'Bob');
        [$service, $db] = $this->makeService($a, $b);

        $service->sendRequest($a, $b);

        $text = $db->notificationDeliveries[0]['message_text'];
        $this->assertStringStartsWith($this->trans('friend.new_request', ['name' => 'Alice']), $text);
        $this->assertStringContainsString('href="https://example.test/alice10/"', $text);
        $this->assertStringContainsString('>Alice</a>', $text);
    }

    /**
     * Mirror of the test above: no username to build a link from (the
     * public username is only assigned by a backfill after registration -
     * see UserRepository::setUsername()'s own note) means no link, not a
     * broken one - same "no username, no link" rule
     * UsersController::buildFriendCards() already follows for the
     * friends-list UI.
     */
    public function testSendRequestOmitsProfileLinkWhenActorHasNoUsername(): void
    {
        $a = $this->makeUser(10, nick: 'Alice');
        $b = $this->makeUser(20, nick: 'Bob');
        [$service, $db] = $this->makeService($a, $b);

        $service->sendRequest($a, $b);

        $this->assertSame(
            $this->trans('friend.new_request', ['name' => 'Alice']),
            $db->notificationDeliveries[0]['message_text'],
        );
    }

    public function testSendRequestIsIdempotent(): void
    {
        $a = $this->makeUser(10);
        $b = $this->makeUser(20);
        [$service, $db] = $this->makeService($a, $b);

        $service->sendRequest($a, $b);
        $service->sendRequest($a, $b);

        $this->assertCount(1, $db->notificationDeliveries);
        $this->assertSame('subscribed', $service->getRelationshipStatus($a, $b));
    }

    public function testReciprocatingCompletesMutualFriendshipAndNotifiesOriginalRequester(): void
    {
        $a = $this->makeUser(10, nick: 'Alice');
        $b = $this->makeUser(20, nick: 'Bob');
        [$service, $db] = $this->makeService($a, $b);

        $service->sendRequest($a, $b);
        $service->sendRequest($b, $a);

        $this->assertSame('friends', $service->getRelationshipStatus($a, $b));
        $this->assertSame('friends', $service->getRelationshipStatus($b, $a));

        $this->assertCount(2, $db->notificationDeliveries);
        $this->assertSame($a->id, $db->notificationDeliveries[1]['recipient_user_id']);
        $this->assertSame(
            $this->trans('friend.became_mutual', ['name' => 'Bob']),
            $db->notificationDeliveries[1]['message_text'],
        );
    }

    public function testSendRequestThrowsValidationExceptionForSelf(): void
    {
        $a = $this->makeUser(10);
        [$service] = $this->makeService($a, $this->makeUser(20));

        $this->expectException(ValidationException::class);

        $service->sendRequest($a, $a);
    }

    public function testSendRequestThrowsForbiddenForGuestActor(): void
    {
        $guest = $this->makeUser(0, role: AccessService::ROLE_USER);
        $b = $this->makeUser(20);
        [$service] = $this->makeService($guest, $b);

        $this->expectException(ForbiddenException::class);

        $service->sendRequest($guest, $b);
    }

    public function testRemoveFriendDowngradesMutualFriendshipToOneDirectional(): void
    {
        $a = $this->makeUser(10);
        $b = $this->makeUser(20);
        [$service] = $this->makeService($a, $b);

        $service->sendRequest($a, $b);
        $service->sendRequest($b, $a);
        $this->assertSame('friends', $service->getRelationshipStatus($a, $b));

        $service->removeFriend($a, $b);

        // A's own row into B's blog is gone, but B's row into A's blog (B
        // subscribed to A) is untouched - same as unfollowing on any
        // mutual-follow platform.
        $this->assertSame('incoming', $service->getRelationshipStatus($a, $b));
        $this->assertSame('subscribed', $service->getRelationshipStatus($b, $a));
    }

    public function testRemoveFriendIsANoOpWhenTargetHasNeverPosted(): void
    {
        $a = $this->makeUser(10);
        $noBlogUser = $this->makeUser(999);
        $db = new FakePdoDatabase();
        // Only A's blog exists - $noBlogUser has never posted, so has no
        // personal blog feed at all yet.
        $db->addBlogFeed(self::A_BLOG_ID, $a->id);

        [$pageTree, $urlGenerator] = $this->makePageTreeAndUrlGenerator();

        $service = new FriendService(
            $this->createStub(BlogPostService::class),
            new FeedRepository($db),
            new MembershipRepository($db),
            new NotificationService(
                (new \ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
                new NotificationDeliveryRepository($db),
                new NotificationPreferenceRepository($db),
                new UserRepository($db),
                (new \ReflectionClass(\StreamEngine\Service\MailService::class))->newInstanceWithoutConstructor(),
            ),
            new TranslationManager('ru', 'en'),
            $pageTree,
            $urlGenerator,
        );

        // Must not throw.
        $service->removeFriend($a, $noBlogUser);
        $this->addToAssertionCount(1);
    }

    public function testGetFriendsReturnsOnlyMutualFriendsWithDisplayData(): void
    {
        $a = $this->makeUser(10, nick: 'Alice');
        $b = $this->makeUser(20, nick: 'Bob');
        $c = $this->makeUser(30, nick: 'Carol');
        [$service, $db] = $this->makeService($a, $b);
        // C subscribes to A but A never subscribes back - C should not show
        // up in A's (mutual-only) friends list.
        $db->addBlogFeed(103, $c->id);
        $db->addUser($b->id, 'Bob', 'bob');
        $db->addUser($c->id, 'Carol', 'carol');

        $service->sendRequest($a, $b);
        $service->sendRequest($b, $a); // completes mutual friendship
        $service->sendRequest($c, $a); // one-directional only, not mutual

        $friends = $service->getFriends($a, 10);

        $this->assertSame(1, $friends['total']);
        $this->assertCount(1, $friends['items']);
        $this->assertSame($b->id, $friends['items'][0]['id']);
        $this->assertSame('Bob', $friends['items'][0]['nick']);
        $this->assertSame('bob', $friends['items'][0]['username']);
    }

    public function testGetFriendsRespectsLimitAndOffset(): void
    {
        $a = $this->makeUser(10);
        $b = $this->makeUser(20);
        [$service, $db] = $this->makeService($a, $b);
        $db->addUser($b->id, 'Bob');

        $service->sendRequest($a, $b);
        $service->sendRequest($b, $a);

        $firstPage = $service->getFriends($a, 1, 0);
        $this->assertCount(1, $firstPage['items']);
        $this->assertSame(1, $firstPage['total']);

        $secondPage = $service->getFriends($a, 1, 1);
        $this->assertSame([], $secondPage['items']);
        $this->assertSame(1, $secondPage['total']);
    }

    public function testGetRelationshipStatusIsNoneForGuestOrSelf(): void
    {
        $a = $this->makeUser(10);
        $guest = $this->makeUser(0, role: AccessService::ROLE_USER);
        [$service] = $this->makeService($a, $guest);

        $this->assertSame('none', $service->getRelationshipStatus($guest, $a));
        $this->assertSame('none', $service->getRelationshipStatus($a, $a));
    }
}
