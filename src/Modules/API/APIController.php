<?php

declare(strict_types=1);

namespace StreamEngine\Modules\API;

use Exception;
use Random\RandomException;
use StreamEngine\Core\AbstractController;
use StreamEngine\Core\Config;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\Security;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\Poll;
use StreamEngine\Domain\PollOption;
use StreamEngine\Domain\User;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\AuthService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\NotificationService;
use StreamEngine\Service\PollService;
use StreamEngine\Service\UploadService;
use StreamEngine\StreamEngine;
use StreamEngine\View\Breadcrumb;
use Throwable;

class APIController extends AbstractController
{
    public static function feedTypes(): array
    {
        return [
            'comment' => 'Комментарий',
        ];
    }

    public function __construct(
        PdoDatabase $db,
        RequestContext $context,
        private readonly AuthService $authService,
        private readonly AccessService $accessService,
        private readonly UploadService $uploadService,
        private readonly FeedService $feedService,
        private readonly PollService $pollService,
        private readonly PageTree $pageTree,
        private readonly Config $config,
        private readonly TranslationManager $tm,
        private readonly NotificationService $notifications,
    ) {
        parent::__construct($db, $context);
    }

    public function getBreadcrumb(Page $page): ?Breadcrumb
    {
        return null;
    }

    /**
     * @throws ValidationException
     * @throws ForbiddenException
     * @throws RandomException
     * @throws NotFoundException
     */
    public function callApi(Page $page, array $args = []): void
    {
        header('Content-Type: application/json');

        if (! $page->allowsMethod($_SERVER['REQUEST_METHOD'] ?? '')) {
            throw new ValidationException('Method not allowed', 405);
        }

        match ($page->action) {
            'api.index' => $this->handleApiRequest(),
            'api.v1.index' => $this->handleApiV1Request($page->id),
            'feeds.list' => $this->handleFeedsRequest(),
            'comments.root' => throw new ValidationException('Comment parent id is required'),
            'uploads.create' => $this->handleUploadsRequest(),
            'comments.thread' => $this->handleCommentsRequest((int) ($args['slug'] ?? 0)),
            'comments.item' => $this->handleCommentItemRequest((int) ($args['commentId'] ?? 0)),
            'feed.show' => $this->handleFeedIdRequest((int) ($args['slug'] ?? 0)),
            'feed.rating' => $this->handleRatingRequest((int) ($args['slug'] ?? 0)),
            'feed.favorite' => $this->handleFavoriteRequest((int) ($args['slug'] ?? 0)),
            'feed.poll.vote' => $this->handlePollVoteRequest((int) ($args['slug'] ?? 0)),
            'feed.readingProgress' => $this->handleReadingProgressRequest((int) ($args['slug'] ?? 0)),
            'auth.session' => $this->handleApiAuthRequest(),
            'cron.trigger' => $this->handleCronRequest(),
            default => throw new ValidationException('Unknown API action', 404),
        };
    }

    /**
     * @throws ForbiddenException
     * @throws ValidationException
     * @throws NotFoundException
     */
    private function handleFeedIdRequest(int $feedId): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $result = $this->feedService->getFeedById($feedId, $this->context->user);
            echo Formatter::json($result);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

            $input = json_decode(file_get_contents('php://input'), true);
            $input = is_array($input) ? $input : [];

            if (!$this->accessService->isAdmin($this->context->user)) {
                throw new ForbiddenException("Forbidden");
            }

            $data = [];
            foreach (['title', 'slug', 'parentId', 'type', 'description', 'imageUrl', 'content', 'metadata'] as $field) {
                if (array_key_exists($field, $input)) {
                    $data[$field] = $input[$field];
                }
            }

            $feed = $this->feedService->updateFeed(
                id: $feedId,
                data: $data,
                user: $this->context->user
            );

            echo Formatter::json($feed);
        }
    }

    /**
     * @throws ValidationException
     * @throws ForbiddenException
     */
    private function handleFeedsRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

            $input = json_decode(file_get_contents('php://input'), true);
            $input = is_array($input) ? $input : [];

            if (!$this->accessService->isAdmin($this->context->user)) {
                throw new ForbiddenException("Forbidden");
            }

            $feed = $this->feedService->createFeed(
                title: $input['title'] ?? '',
                slug: $input['slug'] ?? null,
                type: $input['type'] ?? 'article',
                parentId: isset($input['parentId']) && $input['parentId'] ? (int)$input['parentId'] : null,
                description: $input['description'] ?? '',
                imageUrl: $input['imageUrl'] ?? null,
                content: $input['content'] ?? '',
                user: $this->context->user,
                metadata: array_key_exists('metadata', $input) ? $input['metadata'] : []
            );
            echo Formatter::json($feed);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $query = $this->context->query;

            $offset = $query->int('offset');
            $type = $query->trimmed('type');
            $title = $query->trimmed('title');

            $search = $query->trimmed('search');
            $sort = $query->trimmed('sort');
            $limit = max(1, min($query->int('limit', $search ? 20 : 100), 100));

            if ($search) {
                $cursor = $query->string('cursor') ?: null;

                $result = $this->feedService->search(
                    $search,
                    $limit,
                    $cursor,
                    $this->context->user
                );
            } else {
                $result = $this->feedService->listFeeds(
                    $this->context->user,
                    $limit,
                    $offset,
                    $type !== '' ? $type : null,
                    $title !== '' ? $title : null,
                    $query->int('id') ?: null,
                    ($slug = $query->trimmed('slug')) !== '' ? $slug : null,
                    $query->int('parent_id') ?: null,
                    $query->int('owner_id') ?: null,
                    $sort !== '' ? $sort : null
                );
            }
            echo Formatter::json($result);
        }
    }

    /**
     * @throws ValidationException
     * @throws ForbiddenException
     */
    private function handleCommentsRequest(int $parentId): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

            $input = json_decode(file_get_contents('php://input'), true);
            $input = is_array($input) ? $input : [];

            $comment = $this->feedService->createComment(
                parentId: $parentId,
                content: trim((string) ($input['content'] ?? '')),
                user: $this->context->user
            );

            $this->notifyAboutCreatedComment($comment);

            http_response_code(201);
            echo Formatter::json($comment);

            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            throw new ValidationException('Method not allowed', 405);
        }

        $query = $this->context->query;

        $cursor = $query->string('cursor') ?: null;
        $limit = $query->int('limit', 5);
        $childLimit = $query->int('child_limit', 3);

        $limit = max(1, min($limit, 50));
        $childLimit = max(0, min($childLimit, 10));

        echo Formatter::json(
            $this->feedService->getComments(
                parentFeedId: $parentId,
                cursor: $cursor,
                user: $this->context->user,
                limit: $limit,
                childLimit: $childLimit
            )
        );
    }

    private function notifyAboutCreatedComment(Feed $comment): void
    {
        if ($comment->parentId === null) {
            return;
        }

        try {
            $parent = $this->feedService->getFeedById($comment->parentId, $this->context->user);
            $root = $parent;
            $visited = [$comment->id => true];

            while ($root->type === 'comment' && $root->parentId !== null && ! isset($visited[$root->id])) {
                $visited[$root->id] = true;
                $root = $this->feedService->getFeedById($root->parentId, $this->context->user);
            }

            $this->notifications->notifyCommentCreated(
                $comment,
                $parent,
                $root,
                $this->context->user,
            );
        } catch (Throwable $e) {
            // The comment is already persisted. A notification failure must
            // not turn the successful write into an API error or invite a
            // client retry that creates a duplicate comment.
            error_log('Unable to notify about comment '.$comment->id.': '.$e->getMessage());
        }
    }

    /**
     * PATCH (edit) / DELETE a single comment (or a forum topic's own
     * opening post - FeedService::editComment() allows both, but
     * deleteComment() rejects anything that isn't type='comment' - see its
     * own docblock). Ownership/window enforcement lives entirely in
     * FeedService; this handler is just the same CSRF-check + method-branch
     * + status-code shape every other mutating endpoint in this file uses.
     *
     * @throws ValidationException
     * @throws ForbiddenException
     */
    private function handleCommentItemRequest(int $commentId): void
    {
        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
            $input = json_decode(file_get_contents('php://input'), true);
            $input = is_array($input) ? $input : [];
            $content = $input['content'] ?? '';

            if (! is_scalar($content) && $content !== null) {
                throw new ValidationException('Comment content must be a scalar value');
            }

            $comment = $this->feedService->editComment(
                commentId: $commentId,
                content: trim((string) $content),
                user: $this->context->user
            );

            echo Formatter::json($comment);

            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            $this->feedService->deleteComment($commentId, $this->context->user);

            http_response_code(204);

            return;
        }

        throw new ValidationException('Method not allowed', 405);
    }

    /**
     * @throws ValidationException
     * @throws ForbiddenException
     * @throws NotFoundException
     */
    private function handleRatingRequest(int $feedId): void
    {
        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        $input = json_decode(file_get_contents('php://input'), true);
        $input = is_array($input) ? $input : [];

        $feed = $this->feedService->rateFeed(
            feedId: $feedId,
            value: (int) ($input['value'] ?? 0),
            user: $this->context->user
        );

        echo Formatter::json([
            'id' => $feed->id,
            'ratingSum' => $feed->ratingSum,
            'ratingCount' => $feed->ratingCount,
            'ratingAverage' => $feed->ratingAverage(),
        ]);
    }

    /**
     * @throws ForbiddenException
     * @throws ValidationException
     */
    private function handleFavoriteRequest(int $feedId): void
    {
        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->feedService->addFavorite($feedId, $this->context->user);

            echo Formatter::json([
                'feedId' => $feedId,
                'favorited' => true,
            ]);

            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            $this->feedService->removeFavorite($feedId, $this->context->user);

            http_response_code(204);

            return;
        }

        throw new ValidationException('Method not allowed', 405);
    }

    /**
     * POST /api/v1/feeds/{slug}/poll/vote - casts (or, if the poll allows
     * it, replaces) the current user's vote. {slug} is the poll's *feed*
     * id, same as every other /feeds/{slug}/... action here; the poll
     * itself is resolved from it via PollService::getPollForFeed() (which
     * also re-checks the viewer can access that feed at all).
     *
     * @throws ForbiddenException
     * @throws ValidationException
     * @throws NotFoundException
     */
    private function handlePollVoteRequest(int $feedId): void
    {
        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new ValidationException('Method not allowed', 405);
        }

        $poll = $this->pollService->getPollForFeed($feedId, $this->context->user);
        if ($poll === null) {
            throw new ValidationException('No poll for this feed', 404, 'not_found');
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $input = is_array($input) ? $input : [];

        $optionIds = is_array($input['optionIds'] ?? null) ? $input['optionIds'] : [];

        $poll = $this->pollService->vote($poll->id, $optionIds, $this->context->user);

        echo Formatter::json($this->pollToArray($poll, $this->context->user));
    }

    /**
     * Shapes a Poll for JSON: question/options/settings are always
     * included (needed to render the vote form regardless of results
     * visibility) plus the viewer's own selection; vote counts
     * (options[].votesCount, votersCount) are null unless
     * PollService::canSeeResults() allows the current viewer to see them
     * right now - never fabricated as 0 vs. actually-zero, so a client
     * can tell "results are hidden" apart from "no one has voted yet".
     */
    private function pollToArray(Poll $poll, User $user): array
    {
        $canSeeResults = $this->pollService->canSeeResults($poll, $user);

        return [
            'id' => $poll->id,
            'feedId' => $poll->feedId,
            'question' => $poll->question,
            'maxChoices' => $poll->maxChoices,
            'allowRevote' => $poll->allowRevote,
            'resultsVisibility' => $poll->resultsVisibility,
            'closesAt' => $poll->closesAt,
            'isClosed' => $poll->isClosed(),
            'canSeeResults' => $canSeeResults,
            'votersCount' => $canSeeResults ? $poll->votersCount : null,
            'userVotes' => $this->pollService->getUserVotes($poll->id, $user),
            'options' => array_map(
                static fn (PollOption $option): array => [
                    'id' => $option->id,
                    'text' => $option->text,
                    'votesCount' => $canSeeResults ? $option->votesCount : null,
                ],
                $poll->options
            ),
        ];
    }

    /**
     * Clears a user's/guest's saved reading progress. Deliberately available to
     * guests too - unlike favoriting/rating, this doesn't require an
     * account, since guest reading progress is tracked client-side (see
     * GuestFeedReadStore) and there's nothing to protect by gating it.
     *
     * @throws ValidationException
     */
    private function handleReadingProgressRequest(int $feedId): void
    {
        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            throw new ValidationException('Method not allowed', 405);
        }

        $this->feedService->removeReadProgressForFeed($feedId, $this->context->user);

        http_response_code(204);
    }

    private function handleApiRequest(): void
    {
        echo Formatter::json([
            'name' => 'StreamEngine API',
            'versions' => [
                'v1' => '/api/v1',
            ],
        ]);
    }

    private function handleApiV1Request(int $id): void
    {
        $resources = [];
        foreach ($this->pageTree->findChildren($id) as $page) {
            $resources[$page->pattern] = "/api/v1/" . $page->pattern;
        }
        echo Formatter::json([
            'resources' => $resources,
        ]);
    }

    /**
     * @throws ValidationException
     * @throws Exception
     */
    private function handleApiAuthRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);

            $email = trim($data['email'] ?? '');
            $password = $data['password'] ?? '';

            if (! $this->authService->login($email, $password)) {
                throw new ValidationException($this->tm->trans('auth.credentials_invalid'));
            }
            echo Formatter::json([
                'authenticated' => true,
            ]);

        } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
            echo Formatter::json([
                'authenticated' => ! $this->context->user->isGuest(),
            ]);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);
            $this->authService->logout();
            echo Formatter::json([
                'authenticated' => ! $this->context->user->isGuest(),
            ]);
        }
    }

    /**
     * @throws RandomException
     * @throws ValidationException
     */
    private function handleUploadsRequest(): void
    {
        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        if (! isset($_FILES['file'])) {
            http_response_code(400);
            echo Formatter::json(['error' => 'No file']);

            return;
        }

        if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new ValidationException('Upload failed');
        }

        $variant = $this->context->query->string('variant');

        $upload = $variant === 'avatar'
            ? $this->uploadService->uploadAvatarForUser($this->context->user, $_FILES['file'])
            : $this->uploadService->uploadForUser($this->context->user, $_FILES['file']);

        echo Formatter::json([
            'id' => $upload->id,
            'url' => '/uploads/'.$upload->path,
            'mime' => $upload->mime,
            // Added for forums.topic-new's multi-file "Attachments" list
            // (Modules\Forums\ForumsController's own attachment card,
            // via forums.ts's initTopicForm()) - it needs to show a
            // filename/size per row without a second round trip. Additive:
            // every existing caller (cover image, Trix inline attachments,
            // track upload) only ever reads 'id'/'url'/'mime' off this
            // response, so these two new fields are safe to add.
            'size' => $upload->size,
            'originalName' => $upload->originalName,
        ]);
    }

    private function handleCronRequest(): void
    {
        $expected = $this->config->cronKey();
        // string() is total, so `?key[]=x` - which used to make this an array
        // and hash_equals() a TypeError - arrives as '' and is refused below
        // like any other wrong key.
        $received = $this->context->query->string('key');

        if ($expected === '') {
            throw new ValidationException('Cron is not configured', 400);
        }

        // hash_equals rather than !==: the comparison is against a secret, and
        // !== short-circuits on the first differing byte. Guessing a key over
        // the network through timing is a stretch, but this costs one function
        // call and the same reasoning already applies in verifyCsrf().
        if (! hash_equals($expected, $received)) {
            throw new ValidationException('Bad cron key', 400);
        }
        StreamEngine::triggerCronProcess();
        print Formatter::json([]);
    }

    public static function registerApi(int $apiPageId, PageTree $pageTree): void
    {
        $feedsPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $feedsPageId,
                parentId: $apiPageId,
                pattern: 'feeds',
                requestMethods: ['GET', 'POST'],
                action: 'feeds.list',
            )
        );
        $feedsIdPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $feedsIdPageId,
                parentId: $feedsPageId,
                pattern: '{slug}',
                requestMethods: ['GET', 'PATCH'],
                action: 'feed.show',
            )
        );
        $feedRatingPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $feedRatingPageId,
                parentId: $feedsIdPageId,
                pattern: 'rating',
                requestMethods: ['POST'],
                action: 'feed.rating',
            )
        );
        $feedFavoritePageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $feedFavoritePageId,
                parentId: $feedsIdPageId,
                pattern: 'favorite',
                requestMethods: ['POST', 'DELETE'],
                action: 'feed.favorite',
            )
        );
        // 'poll' is never meant to be requested on its own (only its 'vote'
        // child is) - same real-but-unused-action placeholder reasoning
        // Modules\Forums\ForumsController::registerApi() uses for
        // 'forums'/'{topicId}', so a bare request to it 400s via callApi()'s
        // "Unknown action" instead of 500ing on a null action.
        $feedPollPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $feedPollPageId,
                parentId: $feedsIdPageId,
                pattern: 'poll',
                // GET, like every other placeholder parent
                // ('user.posts-parent', 'community-parent',
                // 'community.manage-members-parent'). It used to say POST,
                // which bought nothing - StreamEngine::handleRequest() checks
                // requestMethods on the *resolved* page only, never a parent,
                // and this action is dispatched nowhere - while making the page
                // look like a mutating endpoint. That is precisely how it
                // turned up in `composer audit:csrf`, as the one PLACEHOLDER
                // line standing between a clean run and a signal worth acting
                // on. The real endpoint is 'vote' below.
                requestMethods: ['GET'],
                action: 'feed.poll-api-parent',
            )
        );
        $pageTree->add(
            Page::api(
                id: $pageTree->getMaxPageId(),
                parentId: $feedPollPageId,
                pattern: 'vote',
                requestMethods: ['POST'],
                action: 'feed.poll.vote',
            )
        );
        $feedReadingProgressPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $feedReadingProgressPageId,
                parentId: $feedsIdPageId,
                pattern: 'reading-progress',
                requestMethods: ['DELETE'],
                action: 'feed.readingProgress',
            )
        );
        $authPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $authPageId,
                parentId: $apiPageId,
                pattern: 'auth',
                requestMethods: ['GET', 'POST', 'DELETE'],
                action: 'auth.session',
            )
        );
        $uploadsPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $uploadsPageId,
                parentId: $apiPageId,
                pattern: 'uploads',
                requestMethods: ['POST'],
                action: 'uploads.create',
                accessRule: AccessService::ACCESS_AUTHENTICATED,
            )
        );
        $commentsPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $commentsPageId,
                parentId: $apiPageId,
                pattern: 'comments',
                requestMethods: ['GET'],
                action: 'comments.root',
            )
        );
        $commentsThreadPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $commentsThreadPageId,
                parentId: $commentsPageId,
                pattern: '{slug}',
                requestMethods: ['GET', 'POST'],
                action: 'comments.thread',
            )
        );
        $commentsItemPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $commentsItemPageId,
                parentId: $commentsThreadPageId,
                // {commentId} - deliberately not {slug} again: Router
                // merges every dynamic segment's named captures into one
                // flat $params array (see Router::resolve()), so reusing
                // {slug} here would silently overwrite the parent thread's
                // own {slug} (the comment's parentId) with this segment's
                // value instead of exposing both.
                pattern: '{commentId}',
                requestMethods: ['PATCH', 'DELETE'],
                action: 'comments.item',
            )
        );
        $cronPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $cronPageId,
                parentId: $apiPageId,
                pattern: 'cron',
                requestMethods: ['GET'],
                action: 'cron.trigger',
            )
        );
    }

}
