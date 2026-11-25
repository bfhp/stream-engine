<?php

declare(strict_types=1);

namespace Tests\Support;

use StreamEngine\Domain\User;

/**
 * The row-level feed ACL exactly as FeedRepository built it before the
 * per-type rewrite (docs/TODO.md, P1, approach A): the oracle the rewritten
 * queries are compared with. The methods are a verbatim copy of
 * FeedRepository::buildAclCondition() and its helpers at that point - do not
 * edit them to follow FeedRepository; the comparison is only meaningful
 * against the old predicate.
 *
 * condition() returns the predicate on `feeds f` and its parameters, as
 * buildAclCondition() did.
 */
final class LegacyFeedAcl
{
    /**
     * @return array{0:string, 1:list<int>}
     */
    public function condition(User $user): array
    {
        return $this->buildAclCondition($user);
    }

    /**
     * Uses index: PRIMARY(id) on feeds for a comment's parent and root post.
     * Uses index: PRIMARY(container_id, user_id) on memberships.
     * Uses index: PRIMARY(id) on membership_roles.
     *
     * A comment's own visibility is deliberately ignored. Root comments use
     * their direct parent as the ACL subject; one-level nested replies use the
     * parent's parent. This matches the two-level discussion model returned by
     * findCommentsPage()/findCommentsByParents() and makes a later visibility
     * change or membership revocation take effect without rewriting comments.
     *
     * Friendship and community approval are already materialized in the
     * membership role, so every subject check remains an indexed membership
     * probe rather than deriving relationships inside the ACL.
     *
     * @return array{0:string, 1:list<int>}
     */
    private function buildAclCondition(User $user): array
    {
        // Content moderator override (admin or global moderator)
        if ($user->canModerateContent()) {
            return ['1=1', []];
        }

        $feedAcl = $this->aclSubjectCondition(
            'f.owner_id',
            'f.visibility',
            'f.container_id',
            'm',
            'mr'
        );
        [$commentAcl, $commentParams] = $this->commentAclCondition($user);
        $galleryAcl = $this->galleryAclCondition($user, 'f.id', 'f.parent_id', 'f.type');
        $containerAcl = $this->containerAclCondition($user, 'f.id', 'f.container_id');

        $sql = "
        (
            (f.type <> 'comment' AND $feedAcl AND $containerAcl AND $galleryAcl)
            OR (f.type = 'comment' AND $commentAcl)
        )
        ";

        return [
            $sql,
            [$user->id, $user->id, ...$commentParams],
        ];
    }

    /**
     * Uses feeds PRIMARY(id) and memberships PRIMARY(container_id,user_id).
     * Owning a publication does not grant access to its closed container.
     * Self-contained feeds use their own ordinary ACL without recursion.
     */
    private function containerAclCondition(User $user, string $idExpression, string $containerExpression): string
    {
        $containerAcl = str_replace('?', (string) $user->id, $this->aclSubjectCondition(
            'acl_container.owner_id',
            'acl_container.visibility',
            'acl_container.container_id',
            'acm',
            'acr',
        ));

        return "($containerExpression IS NULL OR $containerExpression = $idExpression OR EXISTS (
            SELECT 1 FROM feeds acl_container
            WHERE acl_container.id = $containerExpression AND $containerAcl
        ))";
    }

    private function galleryAclCondition(User $user, string $idExpression, string $parentExpression, string $typeExpression): string
    {
        // Numeric user IDs are trusted integers, as with the LIMIT clauses.
        $albumAcl = str_replace('?', (string) $user->id, $this->aclSubjectCondition('ga.owner_id', 'ga.visibility', 'ga.container_id', 'gam', 'gar'));
        $contextAcl = str_replace('?', (string) $user->id, $this->aclSubjectCondition('gc.owner_id', 'gc.visibility', 'gc.container_id', 'gcm', 'gcr'));
        return "($typeExpression <> 'gallery-photo' OR EXISTS (
            SELECT 1 FROM feeds ga JOIN feeds gc ON gc.id = ga.parent_id
            WHERE ga.id = $parentExpression AND ga.type = 'gallery-album' AND gc.type IN ('blog', 'community')
              AND $albumAcl AND $contextAcl
              AND EXISTS (SELECT 1 FROM feed_metadata gs WHERE gs.feed_id = $idExpression AND gs.name = 'gallery.state' AND gs.content = 'ready')
        ))";


    }

    /**
     * ACL for the two-level discussion model: a root comment inherits its
     * direct publication, and a reply inherits the publication above its
     * parent comment.
     *
     * @return array{0:string, 1:list<int>}
     */
    private function commentAclCondition(User $user): array
    {
        $subject = static fn (string $column): string =>
            "CASE WHEN acl_parent.type = 'comment' THEN acl_root.$column ELSE acl_parent.$column END";
        $subjectAcl = $this->aclSubjectCondition(
            $subject('owner_id'),
            $subject('visibility'),
            $subject('container_id'),
            'cm',
            'cmr'
        );

        $galleryAcl = $this->galleryAclCondition($user, $subject('id'), $subject('parent_id'), $subject('type'));
        $containerAcl = $this->containerAclCondition($user, $subject('id'), $subject('container_id'));

        return [
            "
            EXISTS (
                SELECT 1
                FROM feeds acl_parent
                LEFT JOIN feeds acl_root
                  ON acl_parent.type = 'comment'
                 AND acl_root.id = acl_parent.parent_id
                WHERE acl_parent.id = f.parent_id
                  AND (acl_parent.type <> 'comment' OR acl_root.type <> 'comment')
                  AND $subjectAcl AND $containerAcl AND $galleryAcl
            )
            ",
            [$user->id, $user->id],
        ];
    }

    /**
     * Builds the ordinary owner/public/container-membership rule for one ACL
     * subject. Every expression and alias is supplied only by
     * buildAclCondition(); none contains request data.
     */
    private function aclSubjectCondition(
        string $ownerExpression,
        string $visibilityExpression,
        string $containerExpression,
        string $membershipAlias,
        string $roleAlias,
    ): string {
        return "
        (
            $ownerExpression = ?
            OR $visibilityExpression = 'public'
            OR (
                $containerExpression IS NOT NULL
                AND EXISTS (
                    SELECT 1
                    FROM memberships $membershipAlias
                    JOIN membership_roles $roleAlias
                      ON $roleAlias.id = $membershipAlias.membership_role_id
                    WHERE $membershipAlias.user_id = ?
                      AND $membershipAlias.container_id = $containerExpression
                      AND (
                            (
                                $visibilityExpression = 'private'
                                AND $roleAlias.role_level >= 2
                            )
                            OR (
                                $visibilityExpression = 'members'
                                AND $roleAlias.role_level >= 1
                            )
                          )
                )
            )
        )
        ";
    }
}
