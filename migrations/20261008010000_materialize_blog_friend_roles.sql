SET @subscriber_role_id = (
  SELECT id FROM membership_roles WHERE name = 'subscriber' LIMIT 1
);

SET @member_role_id = (
  SELECT id FROM membership_roles WHERE name = 'member' LIMIT 1
);

-- Personal-blog memberships historically used the member role for both a
-- one-way request and a mutual friendship. Materialize the distinction once:
-- reciprocal rows are members; a row without its reverse is a subscriber.
UPDATE memberships relationship
JOIN feeds owner_blog
  ON owner_blog.id = relationship.container_id
 AND owner_blog.type = 'blog'
LEFT JOIN feeds subscriber_blog
  ON subscriber_blog.owner_id = relationship.user_id
 AND subscriber_blog.type = 'blog'
LEFT JOIN memberships reverse_relationship
  ON reverse_relationship.container_id = subscriber_blog.id
 AND reverse_relationship.user_id = owner_blog.owner_id
SET relationship.membership_role_id = CASE
  WHEN reverse_relationship.user_id IS NULL THEN @subscriber_role_id
  ELSE @member_role_id
END
WHERE relationship.membership_role_id IN (@subscriber_role_id, @member_role_id);
