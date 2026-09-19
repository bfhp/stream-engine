UPDATE pages
SET action = 'article.show-id',
    feed_type = NULL,
    list_feed_type = NULL,
    term_vocabulary = NULL
WHERE action = 'article.show' AND feed_id IS NOT NULL;

UPDATE pages
SET action = 'article.show-slug',
    feed_id = NULL,
    list_feed_type = NULL,
    term_vocabulary = NULL
WHERE action = 'article.show';

UPDATE pages
SET action = 'community.show-id',
    feed_type = NULL,
    list_feed_type = NULL,
    term_vocabulary = NULL
WHERE action = 'community.show' AND feed_id IS NOT NULL;

UPDATE pages
SET action = 'community.show-slug',
    feed_id = NULL,
    list_feed_type = NULL,
    term_vocabulary = NULL
WHERE action = 'community.show';

UPDATE pages
SET action = 'user.post-show-id',
    feed_type = NULL,
    list_feed_type = NULL,
    term_vocabulary = NULL
WHERE action = 'user.post-show' AND feed_id IS NOT NULL;

UPDATE pages
SET action = 'user.post-show-slug',
    feed_id = NULL,
    list_feed_type = NULL,
    term_vocabulary = NULL
WHERE action = 'user.post-show';

UPDATE pages
SET action = 'community.post-show-id',
    feed_type = NULL,
    list_feed_type = NULL,
    term_vocabulary = NULL
WHERE action = 'community.post-show' AND feed_id IS NOT NULL;

UPDATE pages
SET action = 'community.post-show-slug',
    feed_id = NULL,
    list_feed_type = NULL,
    term_vocabulary = NULL
WHERE action = 'community.post-show';
