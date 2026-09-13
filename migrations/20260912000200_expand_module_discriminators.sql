ALTER TABLE feeds
  MODIFY container_type varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL;

ALTER TABLE feed_terms
  MODIFY vocabulary varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL;
