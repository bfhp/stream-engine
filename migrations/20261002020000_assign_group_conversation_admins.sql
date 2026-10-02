-- Existing groups predate enforced participant roles. Give each group that has
-- no administrator a deterministic owner: its earliest participant.
UPDATE conversation_participants cp
JOIN (
    SELECT ranked.conversation_id, ranked.user_id
    FROM (
        SELECT
            conversation_id,
            user_id,
            ROW_NUMBER() OVER (
                PARTITION BY conversation_id
                ORDER BY joined_at, user_id
            ) AS position,
            SUM(role = 'admin') OVER (
                PARTITION BY conversation_id
            ) AS admin_count
        FROM conversation_participants
    ) ranked
    JOIN conversations c ON c.id = ranked.conversation_id
    WHERE ranked.position = 1
      AND ranked.admin_count = 0
      AND c.direct_key IS NULL
) successor
  ON successor.conversation_id = cp.conversation_id
 AND successor.user_id = cp.user_id
SET cp.role = 'admin';
