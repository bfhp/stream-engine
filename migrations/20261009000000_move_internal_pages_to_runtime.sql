DELETE FROM pages
WHERE action IN (
  'forums.topic-list',
  'forums.topic-new',
  'forums.topic-view',
  'forums.topic-edit',
  'user.post-new',
  'user.post-show-slug',
  'user.post-edit',
  'community.create',
  'community.show-slug',
  'community.post-new',
  'community.post-show-slug',
  'community.post-edit',
  'community.manage'
);
