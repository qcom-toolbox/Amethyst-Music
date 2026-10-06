-- ==========================================================
--  Purple Music / Amethyst Music — Migration 1: Albums
--  Run only once on an existing database:
--    mysql -u root -p purple_music < update-db/update-1.sql
--
--  Requires MySQL 8.0.29+ or MariaDB 10.5.2+ (support for
--  "ADD COLUMN IF NOT EXISTS"). On an older version,
--  remove the "IF NOT EXISTS" from the ALTER TABLE below after
--  checking that the `album_id` column doesn't already exist.
-- ==========================================================

USE purple_music;

-- 1. Albums table
CREATE TABLE IF NOT EXISTS `albums` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(255)    NOT NULL,
    `cover`         VARCHAR(255)    DEFAULT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_album_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Attach tracks to an album (NULL = no album, default behavior)
ALTER TABLE `tracks`
    ADD COLUMN IF NOT EXISTS `album_id` INT UNSIGNED DEFAULT NULL AFTER `genre`;

-- 3. Index + foreign key (skipped if already present)
ALTER TABLE `tracks`
    ADD KEY IF NOT EXISTS `idx_album` (`album_id`);

SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tracks'
      AND CONSTRAINT_NAME = 'fk_tracks_album'
);

SET @add_fk_sql = IF(@fk_exists = 0,
    'ALTER TABLE `tracks` ADD CONSTRAINT `fk_tracks_album` FOREIGN KEY (`album_id`) REFERENCES `albums` (`id`) ON DELETE SET NULL',
    'SELECT ''fk_tracks_album already exists'''
);

PREPARE stmt FROM @add_fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ==========================================================
--  END — Quick check
-- ==========================================================

SELECT 'Migration 1 (albums) appliquée avec succès ✔' AS statut;
SHOW COLUMNS FROM tracks LIKE 'album_id';
SHOW TABLES LIKE 'albums';
