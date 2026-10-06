-- ==========================================================
--  Purple Music / Amethyst Music — Migration 4: Indexes for
--  large-scale listening history
--  Run only once on an existing database:
--    mysql -u root -p purple_music < update-db/update-4.sql
--
--  Unlike the previous migrations, this one does NOT use the
--  short "... IF NOT EXISTS" syntax on CREATE INDEX / ADD COLUMN /
--  ADD KEY: that syntax is a MariaDB extension (10.1+/10.5+) that
--  MySQL (including 8.0 and 9.x) rejects with a plain syntax error —
--  which is actually what crashed EVERY request to api.php on a
--  MySQL database before the previous migration's fix. So here we use
--  the same portable technique as migration 1 (checking
--  via information_schema + dynamic SQL), which works identically
--  on MySQL and on MariaDB.
-- ==========================================================

USE purple_music;

-- ----------------------------------------------------------
-- 1. Covering composite index (user_id, played_at, track_id) on
--    listen_history.
--
--    This table grows by one row per playback and has no natural
--    limit: with an "unusually large" history (many users
--    active over a long period), the two queries that read it
--    (action=recommend and action=history in api.php) degrade into a full
--    table scan without a suitable index.
--
--    - action=recommend does:
--        WHERE user_id = ? ORDER BY played_at DESC LIMIT 200, SELECT track_id
--      With this index, it's a pure index scan (covering index): MySQL
--      finds the range for the right user_id, walks it already sorted by
--      played_at, and reads track_id directly from the index without ever
--      touching the table.
--    - action=history does:
--        WHERE user_id = ? GROUP BY track_id ORDER BY MAX(played_at) DESC
--      The index greatly speeds up the WHERE user_id=? (the expensive part at
--      scale), even though the final grouping is then done in
--      memory over a number of rows bounded by this user's number of
--      distinct tracks, not by the total size of the table.
-- ----------------------------------------------------------

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'listen_history'
      AND INDEX_NAME = 'idx_lh_user_played'
);

SET @add_idx_sql = IF(@idx_exists = 0,
    'ALTER TABLE `listen_history` ADD INDEX `idx_lh_user_played` (`user_id`, `played_at`, `track_id`)',
    'SELECT ''idx_lh_user_played already exists'''
);

PREPARE stmt FROM @add_idx_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------
-- 2. Drop the old simple index idx_lh_user (user_id), which became
--    redundant: everything it allowed is already covered by the new
--    composite index above (which also starts with user_id). Keeping it
--    would only slow down every INSERT (one row per playback) for
--    nothing. Only exists on databases provisioned before this migration
--    (the new schema, in setup.sql and api.php's auto-migration, no
--    longer creates it).
-- ----------------------------------------------------------

SET @old_idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'listen_history'
      AND INDEX_NAME = 'idx_lh_user'
);

SET @drop_idx_sql = IF(@old_idx_exists > 0,
    'ALTER TABLE `listen_history` DROP INDEX `idx_lh_user`',
    'SELECT ''idx_lh_user already absent'''
);

PREPARE stmt FROM @drop_idx_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ==========================================================
--  END — Quick check
-- ==========================================================

SELECT 'Migration 4 (index historique d''écoute) appliquée avec succès ✔' AS statut;
SHOW INDEX FROM listen_history;
