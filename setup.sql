-- ==========================================================
--  Purple Music — MySQL creation script
--  Run as root: mysql -u root -p < setup_purple_music.sql
-- ==========================================================

-- 1. Create the database
CREATE DATABASE IF NOT EXISTS purple_music
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- 2. Create the dedicated user
--    ⚠ Replace 'VotreMotDePasseIci' with a strong password!
CREATE USER IF NOT EXISTS 'purple_music_user'@'localhost'
    IDENTIFIED BY 'VotreMotDePasseIci';

-- 3. Grant privileges (on this database only)
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP
    ON purple_music.*
    TO 'purple_music_user'@'localhost';

FLUSH PRIVILEGES;

-- 4. Select the database
USE purple_music;

-- ==========================================================
--  TABLES
-- ==========================================================

CREATE TABLE IF NOT EXISTS `settings` (
    `setting_key`   VARCHAR(100)    NOT NULL,
    `value`         TEXT            NOT NULL,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `username`      VARCHAR(60)     NOT NULL,
    `password`      VARCHAR(255)    NOT NULL,
    `is_admin`      TINYINT(1)      NOT NULL DEFAULT 0,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `genres` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(100)    NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_genre_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `albums` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(255)    NOT NULL,
    `cover`         VARCHAR(255)    DEFAULT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_album_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tracks` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `filename`      VARCHAR(255)    NOT NULL,
    `title`         VARCHAR(255)    NOT NULL,
    `artist`        VARCHAR(255)    NOT NULL DEFAULT 'Artiste inconnu',
    `cover`         VARCHAR(255)    NOT NULL DEFAULT 'default.png',
    `genre`         VARCHAR(100)    NOT NULL DEFAULT 'Autre',
    `album_id`      INT UNSIGNED    DEFAULT NULL,
    `uploader_id`   INT UNSIGNED    NOT NULL,
    `upload_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `play_count`    INT UNSIGNED    NOT NULL DEFAULT 0,
    `duration`      INT UNSIGNED    NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_play_count` (`play_count`),
    KEY `idx_uploader`   (`uploader_id`),
    KEY `idx_album`      (`album_id`),
    CONSTRAINT `fk_tracks_user`
        FOREIGN KEY (`uploader_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_tracks_album`
        FOREIGN KEY (`album_id`) REFERENCES `albums` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `playlists` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(255)    NOT NULL,
    `creator_id`    INT UNSIGNED    NOT NULL,
    `song_ids`      TEXT,
    `is_public`     TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_creator` (`creator_id`),
    CONSTRAINT `fk_playlists_user`
        FOREIGN KEY (`creator_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- idx_lh_user_played (user_id, played_at, track_id) is a covering
-- composite index: it fully serves the recommendation query (WHERE
-- user_id=? ORDER BY played_at DESC LIMIT n, SELECT track_id) without ever
-- touching the table, and speeds up the history's WHERE user_id=? filter
-- even at millions of rows. idx_lh_track is used for cascading
-- deletes and any future query per track.
CREATE TABLE IF NOT EXISTS `listen_history` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED    NOT NULL,
    `track_id`      INT UNSIGNED    NOT NULL,
    `played_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_lh_user_played` (`user_id`, `played_at`, `track_id`),
    KEY `idx_lh_track` (`track_id`),
    CONSTRAINT `fk_listen_history_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_listen_history_track`
        FOREIGN KEY (`track_id`) REFERENCES `tracks` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================
--  DEFAULT DATA — Genres & base settings
-- ==========================================================

INSERT IGNORE INTO `genres` (`name`) VALUES
    ('Phonk/Funk'),
    ('Rap'),
    ('Pop'),
    ('Rock'),
    ('Electro'),
    ('Hyperpop'),
    ('Nightcore'),
    ('Qualité inférieure'),
    ('Autre');

INSERT IGNORE INTO `settings` (`setting_key`, `value`) VALUES
    ('site_name',           'Purple Music'),
    ('color_bg',            '#0f0c1d'),
    ('color_panel',         '#1b1429'),
    ('color_primary',       '#8e44ad'),
    ('color_accent',        '#bb86fc'),
    ('color_text',          '#e0e0e0'),
    ('color_text_muted',    '#a196b4'),
    ('color_border',        '#3d2b56'),
    ('color_search_bg',     '#241b36'),
    ('color_header_bg',     'rgba(27, 20, 41, 0.85)'),
    ('color_player_bg',     'rgba(30, 24, 45, 0.85)'),
    ('color_mob_nav_bg',    'rgba(21, 16, 32, 0.95)'),
    ('color_fp_gradient_1', '#302b63'),
    ('color_fp_gradient_2', '#0f0c29'),
    ('default_cover',       'default.png'),
    ('favicon',             'favicon.png');

-- ==========================================================
--  END — Quick check
-- ==========================================================

SELECT 'Base purple_music créée avec succès ✔' AS statut;
SHOW TABLES;
