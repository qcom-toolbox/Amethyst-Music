-- ==========================================================
--  Purple Music / Amethyst Music — Migration 3: Listening history
--  Run only once on an existing database:
--    mysql -u root -p purple_music < update-db/update-3.sql
--
--  Basis of the recommendation engine (action=recommend in api.php):
--  every playback of a track by a logged-in user is
--  recorded here (see action=increment_play), then used to
--  derive a genre/artist/album affinity weighted by recency.
-- ==========================================================

USE purple_music;

CREATE TABLE IF NOT EXISTS `listen_history` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED    NOT NULL,
    `track_id`      INT UNSIGNED    NOT NULL,
    `played_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_lh_user`  (`user_id`),
    KEY `idx_lh_track` (`track_id`),
    CONSTRAINT `fk_listen_history_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_listen_history_track`
        FOREIGN KEY (`track_id`) REFERENCES `tracks` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================
--  END — Quick check
-- ==========================================================

SELECT 'Migration 3 (historique d''écoute) appliquée avec succès ✔' AS statut;
SHOW TABLES LIKE 'listen_history';
