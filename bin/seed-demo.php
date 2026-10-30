<?php

declare(strict_types=1);

/*
 * Demo content for a fresh installation: section pages, a forum with a topic
 * and replies, a blog post with comments, a community with a post, a second
 * member, a conversation between the two users, and a folder and two small
 * images in the uploads directory (so the file browser has something to list).
 *
 *   php bin/seed-demo.php
 *
 * Used by the accessibility scan in CI and useful for trying the site out
 * locally. Content goes through the engine's own services, so it is
 * validated, purified and linked like content made in the interface.
 *
 * Safe to run more than once: missing section pages are always added, and
 * the content itself is created only once (the `demo.seeded` setting
 * records that). It never deletes or changes existing content. Do not run it
 * on a production site.
 */

use StreamEngine\Core\Formatter;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Modules\Users\BlogPostService;
use StreamEngine\Modules\Users\CommunityService;
use StreamEngine\Repository\FeedRepository;
use StreamEngine\Repository\FeedTermRepository;
use StreamEngine\Repository\MembershipRepository;
use StreamEngine\Repository\PageRepository;
use StreamEngine\Repository\SettingsRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Service\FeedService;
use StreamEngine\StreamEngine;

$autoload = realpath($GLOBALS['_composer_autoload_path'] ?? __DIR__.'/../vendor/autoload.php');
if ($autoload === false) {
    fwrite(STDERR, "Composer autoloader not found. Run composer install first.\n");
    exit(1);
}
require $autoload;

$root = dirname($autoload, 2);
Dotenv\Dotenv::createImmutable($root)->load();

const SEEDED_SETTING = 'demo.seeded';
const MEMBER_EMAIL = 'demo-member@example.com';

/**
 * StreamEngine wires every service in its constructor and keeps them private;
 * reading them back avoids duplicating that wiring here.
 */
function service(StreamEngine $engine, string $property): mixed
{
    return (fn () => $this->{$property})->call($engine);
}

function say(string $message): void
{
    fwrite(STDOUT, $message.PHP_EOL);
}

try {
    $engine = new StreamEngine();
    /** @var PdoDatabase $db */
    $db = service($engine, 'db');

    // 1. Section pages. The engine reads pages once at boot and registers
    //    the runtime pages (topics, posts, communities) below these mounts,
    //    so a new engine is built after adding any.
    $pages = new PageRepository($db);
    $existing = array_column($pages->findAllForAdmin(), 'id', 'action');
    $added = 0;
    $add = function (string $action, int $parentId, string $pattern, string $name, string $access = 'public', ?string $feedType = null, ?string $changefreq = null) use ($pages, &$existing, &$added): int {
        if (isset($existing[$action])) {
            return (int) $existing[$action];
        }
        $row = $pages->createFromAdminData([
            'parentId' => $parentId,
            'pattern' => $pattern,
            'action' => $action,
            'pageName' => $name,
            'settings' => '{}',
            'feedType' => $feedType,
            'listFeedType' => null,
            'termVocabulary' => null,
            'feedId' => null,
            'changefreq' => $changefreq,
            'accessRule' => $access,
        ]);
        $existing[$action] = (int) $row['id'];
        $added++;
        say("Added page /{$pattern} ({$action}).");

        return (int) $row['id'];
    };

    $add('forums.list', 1, 'forums', 'Forums');
    $usersPage = $add('users.list', 1, 'users', 'Users');
    $add('user.show', $usersPage, '{username}', 'User', feedType: 'blog');
    $add('community.main', 1, 'community', 'Communities');
    $add('messages.inbox', 1, 'messages', 'Messages', 'authenticated', changefreq: 'noindex');
    $add('search.results', 1, 'search', 'Search', changefreq: 'noindex');
    $add('user.register', 1, 'register', 'Register', changefreq: 'noindex');
    $add('user.retrieve', 1, 'forgot-password', 'Forgot password', changefreq: 'noindex');
    $add('feedback.show', 1, 'feedback', 'Feedback');

    if ($added > 0) {
        service($engine, 'cache')->flush();
        $engine = new StreamEngine();
        $db = service($engine, 'db');
    }

    // 1b. Uploads for the file browser: a folder and two 1 x 1 images.
    //     Like the pages, only what is missing is added; nothing is
    //     overwritten.
    $uploads = service($engine, 'config')->uploadsPath($root);
    if ($uploads !== '') {
        $pixel = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', true);
        foreach (['demo-folder' => null, 'demo-image-1.png' => $pixel, 'demo-image-2.png' => $pixel] as $name => $content) {
            $target = $uploads.DIRECTORY_SEPARATOR.$name;
            if (file_exists($target)) {
                continue;
            }
            if (! is_dir($uploads) && ! mkdir($uploads, 0775, true) && ! is_dir($uploads)) {
                throw new RuntimeException("Cannot create the uploads directory {$uploads}.");
            }
            $made = $content === null ? mkdir($target, 0775) : file_put_contents($target, $content) !== false;
            if (! $made) {
                throw new RuntimeException("Cannot create {$target}.");
            }
            say("Added uploads/{$name}.");
        }
    }

    /** @var \StreamEngine\Service\SettingsService $settings */
    $settings = service($engine, 'settings');
    if ($settings->getString(SEEDED_SETTING) !== '') {
        say('Demo content already exists; pages are up to date.');
        exit(0);
    }

    // 2. Users: the first administrator and a second, active member.
    $users = new UserRepository($db);
    $adminRow = $db->fetchOne("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
    if ($adminRow === null) {
        throw new RuntimeException('No administrator found. Install the site first.');
    }
    $admin = $users->findById((int) $adminRow['id']) ?? throw new RuntimeException('Administrator not found.');

    $memberRow = $users->findByEmail(MEMBER_EMAIL);
    if ($memberRow === null) {
        $memberId = service($engine, 'userService')->register(MEMBER_EMAIL, bin2hex(random_bytes(16)), false);
        if (! $users->existsUsername('demo-member')) {
            $users->setUsername($memberId, 'demo-member');
        }
        $users->updateProfilePersonal($memberId, 'Demo Member', 'A member account made by the demo seed.', '', '', null, '');
        say('Added member '.MEMBER_EMAIL.'.');
    } else {
        $memberId = (int) $memberRow['id'];
    }
    $member = $users->findById($memberId) ?? throw new RuntimeException('Member not found.');

    /** @var FeedService $feeds */
    $feeds = service($engine, 'feedService');
    $feedRepository = new FeedRepository($db);
    $memberships = new MembershipRepository($db);
    $tm = service($engine, 'tm');
    $pageTree = service($engine, 'pageTree');
    $urls = service($engine, 'urlGenerator');

    $paragraphs = static fn (string ...$texts): string => implode('', array_map(
        static fn (string $text): string => '<div>'.$text.'</div>',
        $texts,
    ));

    // 3. Forums: a category, a forum in it, a topic with varied markup and
    //    two replies.
    $category = $feeds->createFeed('Community', 'demo-community-forums', 'forum', null, 'Talk about the site.', null, '', $admin);
    $forum = $feeds->createFeed('Introductions', 'demo-introductions', 'forum', $category->id, 'Say hello to everyone.', null, '', $admin);

    $topicTitle = 'Hello from the demo';
    $topic = $feeds->createFeed(
        title: $topicTitle,
        slug: Formatter::slugify($topicTitle, '-', FeedService::MAX_SLUG_LENGTH),
        type: 'forum-post',
        parentId: $forum->id,
        description: null,
        imageUrl: null,
        content: $paragraphs(
            'This topic shows the kinds of content the forum renders: <strong>bold</strong>, <em>emphasis</em> and a <a href="https://www.w3.org/WAI/">link to the W3C accessibility pages</a>.',
            '<ul><li>A first item</li><li>A second item</li></ul>',
        ).'<blockquote>A quoted line from an earlier post.</blockquote>'
            .'<figure class="attachment attachment--preview"><img src="/apple-touch-icon.png" width="180" height="180">'
            .'<figcaption class="attachment__caption">The site icon, as an example of a captioned image</figcaption></figure>',
        user: $member,
    );
    $feeds->createComment($topic->id, $paragraphs('Welcome! Glad to have you here.'), $admin, true);
    $feeds->createComment($topic->id, $paragraphs('Thanks, happy to be here.', 'A second paragraph in the same reply.'), $member, true);
    say('Added forum "Introductions" with a topic and replies.');

    // 4. A blog post by the administrator with two comments.
    $blogs = new BlogPostService($feeds, $feedRepository, $urls, $tm, $pageTree, new FeedTermRepository($db));
    $post = $blogs->createBlogPost(
        title: 'A demo blog post',
        content: $paragraphs(
            'Blog posts support the same formatting as forum topics.',
            'This one exists so the blog pages have something to show.',
        ),
        description: 'A short post for trying out the blog.',
        imageUrl: null,
        user: $admin,
        tags: ['demo', 'accessibility'],
    );
    $comment = $feeds->createComment($post->id, "Nice post!\nLooking forward to more.", $member);
    $feeds->createComment($comment->id, 'Thank you.', $admin);
    say('Added a blog post with comments.');

    // 5. An open community the member joins and posts in.
    $communities = new CommunityService(
        $feeds,
        $feedRepository,
        $memberships,
        $tm,
        $pageTree,
        $urls,
        service($engine, 'notificationService'),
        service($engine, 'config'),
    );
    $community = $communities->createCommunity($admin, 'Demo community', 'An open community for trying things out.', null, CommunityService::MEMBERSHIP_TYPE_OPEN);
    $communities->join($member, $community);
    $blogs->createBlogPost(
        title: 'First community post',
        content: $paragraphs('Posts in a community appear on its page.'),
        description: null,
        imageUrl: null,
        user: $member,
        parent: $community,
    );
    say('Added community "Demo community" with a member and a post.');

    // 6. A direct conversation.
    $messages = service($engine, 'messageService');
    $conversation = $messages->createOrGetDirect($admin->id, $member->id);
    $messages->send($conversation, $member->id, 'Hi! Is this the right place to ask questions?');
    $messages->send($conversation, $admin->id, 'Yes, ask away.');
    say('Added a conversation between the administrator and the member.');

    (new SettingsRepository($db))->set(SEEDED_SETTING, (string) time());
    service($engine, 'cache')->flush();
    say('Demo content is ready.');
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);
    exit(1);
}
