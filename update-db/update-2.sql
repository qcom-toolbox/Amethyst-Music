-- ==========================================================
--  Purple Music / Amethyst Music — Migration 2: Playlist visibility
--  Run only once on an existing database:
--    mysql -u root -p purple_music < update-db/update-2.sql
--
--  Requires MySQL 8.0.29+ or MariaDB 10.5.2+ (support for
--  "ADD COLUMN IF NOT EXISTS"). On an older version,
--  remove the "IF NOT EXISTS" from the ALTER TABLE below after
--  checking that the `is_public` column doesn't already exist.
-- ==========================================================

USE purple_music;

-- Each creator can choose whether their playlist is visible to everyone
-- (1, the default — keeps the current behavior where all
-- playlists are public) or only to themselves / an admin (0).
ALTER TABLE `playlists`
    ADD COLUMN IF NOT EXISTS `is_public` TINYINT(1) NOT NULL DEFAULT 1 AFTER `song_ids`;

-- ==========================================================
--  END — Quick check
-- ==========================================================

SELECT 'Migration 2 (visibilité des playlists) appliquée avec succès ✔' AS statut;
SHOW COLUMNS FROM playlists LIKE 'is_public';
