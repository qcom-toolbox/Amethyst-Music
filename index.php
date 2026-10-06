<?php
session_start();

$configFile = __DIR__ . '/config.php';
$isInstalled = file_exists($configFile);

// ===========================================================
//  INSTALL MODE (FIRST LAUNCH)
// ===========================================================
if (!$isInstalled) {
    if (isset($_POST['install'])) {
        $admin_user = trim($_POST['admin_username'] ?? '');
        $admin_pass = $_POST['admin_password'] ?? '';
        $site_name  = trim($_POST['site_name'] ?? 'Purple Music');

        // MySQL settings
        $db_host = trim($_POST['db_host'] ?? '127.0.0.1');
        $db_port = trim($_POST['db_port'] ?? '3306');
        $db_name = trim($_POST['db_name'] ?? 'purple_music');
        $db_user = trim($_POST['db_user'] ?? 'purple_music_user');
        $db_pass = $_POST['db_pass'] ?? '';

        $color_bg      = safeColor($_POST['inst_color_bg'] ?? '', '#0f0c1d');
        $color_panel   = safeColor($_POST['inst_color_panel'] ?? '', '#1b1429');
        $color_primary = safeColor($_POST['inst_color_primary'] ?? '', '#8e44ad');
        $color_accent  = safeColor($_POST['inst_color_accent'] ?? '', '#bb86fc');
        $color_text    = safeColor($_POST['inst_color_text'] ?? '', '#e0e0e0');

        if (empty($admin_user) || empty($admin_pass)) {
            $install_error = "Identifiant et mot de passe admin requis.";
        } else {
            try {
                $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
                $pdo = new PDO($dsn, $db_user, $db_pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);

                // ── Table creation ─────────────────────────────────────
                $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
                    `setting_key` VARCHAR(100) NOT NULL,
                    `value` TEXT NOT NULL,
                    PRIMARY KEY (`setting_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `username` VARCHAR(60) NOT NULL,
                    `password` VARCHAR(255) NOT NULL,
                    `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_username` (`username`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                $pdo->exec("CREATE TABLE IF NOT EXISTS `genres` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `name` VARCHAR(100) NOT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_genre_name` (`name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                $pdo->exec("CREATE TABLE IF NOT EXISTS `tracks` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `filename` VARCHAR(255) NOT NULL,
                    `title` VARCHAR(255) NOT NULL,
                    `artist` VARCHAR(255) NOT NULL DEFAULT 'Artiste inconnu',
                    `cover` VARCHAR(255) NOT NULL DEFAULT 'default.png',
                    `genre` VARCHAR(100) NOT NULL DEFAULT 'Autre',
                    `uploader_id` INT UNSIGNED NOT NULL,
                    `upload_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `play_count` INT UNSIGNED NOT NULL DEFAULT 0,
                    `duration` INT UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY (`id`),
                    KEY `idx_play_count` (`play_count`),
                    KEY `idx_uploader` (`uploader_id`),
                    CONSTRAINT `fk_tracks_user`
                        FOREIGN KEY (`uploader_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                $pdo->exec("CREATE TABLE IF NOT EXISTS `playlists` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `name` VARCHAR(255) NOT NULL,
                    `creator_id` INT UNSIGNED NOT NULL,
                    `song_ids` TEXT,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_creator` (`creator_id`),
                    CONSTRAINT `fk_playlists_user`
                        FOREIGN KEY (`creator_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                // ── Folders ──────────────────────────────────────────────
                if (!is_dir(__DIR__ . '/music'))  mkdir(__DIR__ . '/music',  0755, true);
                if (!is_dir(__DIR__ . '/covers')) mkdir(__DIR__ . '/covers', 0755, true);

                // ── Uploaded assets ─────────────────────────────────────
                if (!empty($_FILES['inst_favicon']['name'])) {
                    $ext = strtolower(pathinfo($_FILES['inst_favicon']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['png', 'ico'])) {
                        move_uploaded_file($_FILES['inst_favicon']['tmp_name'], __DIR__ . '/favicon.png');
                    }
                }
                if (!empty($_FILES['inst_default_cover']['name'])) {
                    $ext = strtolower(pathinfo($_FILES['inst_default_cover']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['png', 'jpg', 'jpeg'])) {
                        move_uploaded_file($_FILES['inst_default_cover']['tmp_name'], __DIR__ . '/covers/default.png');
                    }
                }

                // ── Settings ────────────────────────────────────────────
                $defaultSettings = [
                    'site_name'           => $site_name,
                    'color_bg'            => $color_bg,
                    'color_panel'         => $color_panel,
                    'color_primary'       => $color_primary,
                    'color_accent'        => $color_accent,
                    'color_text'          => $color_text,
                    'color_text_muted'    => '#a196b4',
                    'color_border'        => '#3d2b56',
                    'color_search_bg'     => '#241b36',
                    'color_header_bg'     => 'rgba(27, 20, 41, 0.85)',
                    'color_player_bg'     => 'rgba(30, 24, 45, 0.85)',
                    'color_mob_nav_bg'    => 'rgba(21, 16, 32, 0.95)',
                    'color_fp_gradient_1' => '#302b63',
                    'color_fp_gradient_2' => '#0f0c29',
                    'default_cover'       => 'default.png',
                    'favicon'             => 'favicon.png',
                ];
                $stmtSet = $pdo->prepare(
                    "INSERT INTO `settings` (`setting_key`, `value`) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
                );
                foreach ($defaultSettings as $k => $v) $stmtSet->execute([$k, $v]);

                // ── Genres ──────────────────────────────────────────────
                $defaultGenres = ['Phonk/Funk','Rap','Pop','Rock','Electro','Hyperpop','Nightcore','Qualité inférieure','Autre'];
                $stmtGen = $pdo->prepare("INSERT IGNORE INTO `genres` (`name`) VALUES (?)");
                foreach ($defaultGenres as $g) $stmtGen->execute([$g]);

                // ── Admin ───────────────────────────────────────────────
                $hash   = password_hash($admin_pass, PASSWORD_DEFAULT);
                $stmtU  = $pdo->prepare("INSERT INTO `users` (`username`, `password`, `is_admin`) VALUES (?, ?, 1)");
                $stmtU->execute([$admin_user, $hash]);
                $adminId = $pdo->lastInsertId();

                // ── Folder protection ───────────────────────────────────
                file_put_contents(__DIR__ . '/music/.htaccess',
                    "RemoveHandler .php .phtml .phps\nOptions -ExecCGI\n<Files *>\nSetHandler default-handler\n</Files>");
                file_put_contents(__DIR__ . '/covers/.htaccess',
                    "RemoveHandler .php .phtml .phps\nOptions -ExecCGI\n<Files *>\nSetHandler default-handler\n</Files>");

                // ── config.php ──────────────────────────────────────────
                $configContent = "<?php\n"
                    . "define('DB_HOST', '" . addslashes($db_host) . "');\n"
                    . "define('DB_PORT', '" . addslashes($db_port) . "');\n"
                    . "define('DB_NAME', '" . addslashes($db_name) . "');\n"
                    . "define('DB_USER', '" . addslashes($db_user) . "');\n"
                    . "define('DB_PASS', '" . addslashes($db_pass) . "');\n"
                    . "define('MUSIC_DIR',  __DIR__ . '/music');\n"
                    . "define('COVER_DIR',  __DIR__ . '/covers');\n";
                file_put_contents($configFile, $configContent);

                $_SESSION['user_id']  = (int)$adminId;
                $_SESSION['username'] = $admin_user;
                $_SESSION['is_admin'] = 1;
                // Required for authenticated calls to api.php right after
                // installation (see the normal login further down in the file).
                $_SESSION['api_pw']   = $admin_pass;
                header("Location: " . $_SERVER['PHP_SELF']); exit;

            } catch (Exception $e) {
                @unlink($configFile);
                $install_error = "Erreur : " . $e->getMessage();
            }
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Installation — Purple Music</title>
        <style>
            body { background:#0f0c1d; color:#e0e0e0; font-family:system-ui,sans-serif; display:flex; justify-content:center; align-items:flex-start; min-height:100vh; margin:0; padding:40px 20px; }
            .box { background:#1b1429; padding:40px; border-radius:24px; box-shadow:0 10px 40px rgba(0,0,0,.6); width:100%; max-width:520px; box-sizing:border-box; }
            h2,h3 { color:#bb86fc; text-align:center; margin-top:0; }
            h3 { border-bottom:1px solid #3d2b56; padding-bottom:8px; margin-top:25px; font-size:1.05em; text-align:left; }
            label { font-size:.9em; color:#a196b4; display:block; margin-top:10px; }
            input[type=text],input[type=password],input[type=file],input[type=number] { width:100%; padding:12px; margin:6px 0 14px; background:#140f1f; border:1px solid #3d2b56; color:#fff; border-radius:10px; box-sizing:border-box; outline:none; }
            .db-grid { display:grid; grid-template-columns:1fr 80px; gap:12px; }
            .color-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; margin-bottom:10px; }
            .color-item { background:#140f1f; padding:10px; border-radius:10px; border:1px solid #3d2b56; display:flex; align-items:center; justify-content:space-between; }
            input[type=color] { border:none; width:40px; height:30px; background:transparent; cursor:pointer; }
            button { width:100%; padding:14px; background:#8e44ad; border:none; color:#fff; font-weight:700; font-size:1em; border-radius:50px; cursor:pointer; margin-top:20px; }
            button:hover { background:#9b59b6; }
            .error { color:#ff4757; text-align:center; font-size:.9em; margin-bottom:15px; }
            .hint { font-size:.78em; color:#6b5e85; margin-top:-10px; margin-bottom:10px; }
        </style>
    </head>
    <body>
    <div class="box">
        <h2>⚙ Configuration Initiale</h2>
        <?php if (isset($install_error)) echo '<div class="error">' . htmlspecialchars($install_error) . '</div>'; ?>
        <form method="post" enctype="multipart/form-data">
            <h3>Général</h3>
            <label>Nom du site</label>
            <input type="text" name="site_name" value="Purple Music" required>

            <h3>Connexion MySQL</h3>
            <p class="hint">La base de données et l'utilisateur doivent déjà exister (voir <code>setup_purple_music.sql</code>).</p>
            <div class="db-grid">
                <div>
                    <label>Hôte MySQL</label>
                    <input type="text" name="db_host" value="127.0.0.1" required>
                </div>
                <div>
                    <label>Port</label>
                    <input type="number" name="db_port" value="3306" required>
                </div>
            </div>
            <label>Nom de la base</label>
            <input type="text" name="db_name" value="purple_music" required>
            <label>Utilisateur MySQL</label>
            <input type="text" name="db_user" value="purple_music_user" required>
            <label>Mot de passe MySQL</label>
            <input type="password" name="db_pass" placeholder="Mot de passe de l'utilisateur MySQL">

            <h3>Compte Administrateur</h3>
            <label>Identifiant admin</label>
            <input type="text" name="admin_username" placeholder="ex: Axolat" required>
            <label>Mot de passe admin</label>
            <input type="password" name="admin_password" required>

            <h3>Thème</h3>
            <div class="color-grid">
                <div class="color-item"><span>Arrière-plan</span><input type="color" name="inst_color_bg" value="#0f0c1d"></div>
                <div class="color-item"><span>Panneaux</span><input type="color" name="inst_color_panel" value="#1b1429"></div>
                <div class="color-item"><span>Primaire</span><input type="color" name="inst_color_primary" value="#8e44ad"></div>
                <div class="color-item"><span>Accent</span><input type="color" name="inst_color_accent" value="#bb86fc"></div>
            </div>
            <label>Couleur texte</label>
            <input type="color" name="inst_color_text" value="#e0e0e0" style="width:100%;height:40px;background:#140f1f;padding:5px;border:1px solid #3d2b56;border-radius:10px;">

            <h3>Assets</h3>
            <label>Favicon (.png/.ico)</label>
            <input type="file" name="inst_favicon" accept="image/png,image/x-icon">
            <label>Cover par défaut</label>
            <input type="file" name="inst_default_cover" accept="image/*">

            <button type="submit" name="install">Installer et démarrer</button>
        </form>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// ===========================================================
//  MySQL CONNECTION & BOOTSTRAP
// ===========================================================
require_once $configFile;

// ===========================================================
//  LANGUAGE (FR / EN)
// ===========================================================
if (isset($_GET['setlang']) && in_array($_GET['setlang'], ['fr', 'en'], true)) {
    setcookie('am_lang', $_GET['setlang'], time() + 60 * 60 * 24 * 365, '/');
    $qs = $_GET;
    unset($qs['setlang']);
    header('Location: ' . $_SERVER['PHP_SELF'] . (!empty($qs) ? '?' . http_build_query($qs) : ''));
    exit;
}
$LANG = (($_COOKIE['am_lang'] ?? '') === 'en') ? 'en' : 'fr';

$I18N = [
    'login_username_ph'       => ['fr' => 'Utilisateur',                    'en' => 'Username'],
    'login_password_ph'       => ['fr' => 'Mot de passe',                   'en' => 'Password'],
    'login_submit'            => ['fr' => 'Connexion',                      'en' => 'Log in'],
    'register_submit'         => ['fr' => 'Créer un compte',                'en' => 'Create account'],
    'rgpd_link_label'         => ['fr' => 'Politique de confidentialité (RGPD)', 'en' => 'Privacy Policy (GDPR)'],
    'nav_library'             => ['fr' => 'Bibliothèque',                   'en' => 'Library'],
    'nav_playlists'           => ['fr' => 'Playlists',                      'en' => 'Playlists'],
    'nav_albums'              => ['fr' => 'Albums',                         'en' => 'Albums'],
    'nav_artists'             => ['fr' => 'Artistes',                       'en' => 'Artists'],
    'nav_admin'               => ['fr' => 'Panel Admin',                    'en' => 'Admin Panel'],
    'header_settings'         => ['fr' => 'Paramètres',                     'en' => 'Settings'],
    'header_history'          => ['fr' => 'Historique',                     'en' => 'History'],
    'header_upload'           => ['fr' => 'Upload',                         'en' => 'Upload'],
    'header_mix'              => ['fr' => 'Mix',                            'en' => 'Mix'],
    'eq_preset_rock'          => ['fr' => 'Rock',                           'en' => 'Rock'],
    'eq_preset_pop'           => ['fr' => 'Pop',                            'en' => 'Pop'],
    'history_title'           => ['fr' => "Historique d'écoute",            'en' => 'Listening History'],
    'header_logout'           => ['fr' => 'Sortir',                         'en' => 'Log out'],
    'search_placeholder'      => ['fr' => 'Rechercher titre, artiste...',   'en' => 'Search title, artist...'],
    'btn_clear_search'        => ['fr' => 'Effacer la recherche',           'en' => 'Clear search'],
    'sidebar_toggle_title'    => ['fr' => 'Réduire/agrandir le menu',       'en' => 'Collapse/expand menu'],
    'admin_title'             => ['fr' => 'Configuration Système',          'en' => 'System Configuration'],
    'admin_section_general'   => ['fr' => 'Général',                        'en' => 'General'],
    'admin_section_theme'     => ['fr' => 'Thème Visuel',                   'en' => 'Visual Theme'],
    'admin_section_assets'    => ['fr' => 'Assets Médias',                  'en' => 'Media Assets'],
    'admin_section_genres'    => ['fr' => 'Gestionnaire des Genres',        'en' => 'Genre Manager'],
    'admin_app_name'          => ['fr' => "Nom de l'application",           'en' => 'Application name'],
    'color_bg'                => ['fr' => 'Arrière-plan',                   'en' => 'Background'],
    'color_panel'             => ['fr' => 'Panneaux',                       'en' => 'Panels'],
    'color_primary'           => ['fr' => 'Primaire',                       'en' => 'Primary'],
    'color_accent'            => ['fr' => 'Accent',                         'en' => 'Accent'],
    'color_text'              => ['fr' => 'Texte Principal',                'en' => 'Main Text'],
    'color_text_muted'        => ['fr' => 'Texte Muted',                    'en' => 'Muted Text'],
    'color_border'            => ['fr' => 'Bordures',                       'en' => 'Borders'],
    'color_search_bg'         => ['fr' => 'Fond Recherche',                 'en' => 'Search Background'],
    'color_fp_gradient_1'     => ['fr' => 'Gradient FP 1',                  'en' => 'FP Gradient 1'],
    'color_fp_gradient_2'     => ['fr' => 'Gradient FP 2',                  'en' => 'FP Gradient 2'],
    'color_header_bg_label'   => ['fr' => 'Header (supporte rgba)',         'en' => 'Header (supports rgba)'],
    'color_player_bg_label'   => ['fr' => 'Mini-Lecteur (supporte rgba)',   'en' => 'Mini Player (supports rgba)'],
    'color_mob_nav_bg_label'  => ['fr' => 'Nav Mobile (supporte rgba)',     'en' => 'Mobile Nav (supports rgba)'],
    'admin_new_favicon'       => ['fr' => 'Nouveau Favicon (.png/.ico)',    'en' => 'New Favicon (.png/.ico)'],
    'admin_new_cover'         => ['fr' => 'Nouvelle Cover par défaut',      'en' => 'New Default Cover'],
    'admin_create_genre'      => ['fr' => 'Créer un genre',                 'en' => 'Create a genre'],
    'admin_genre_ph'          => ['fr' => 'ex: Ambient, Jazz...',           'en' => 'e.g. Ambient, Jazz...'],
    'admin_active_genres'     => ['fr' => 'Genres actifs :',                'en' => 'Active genres:'],
    'confirm_delete_genre'    => ['fr' => 'Supprimer ce genre ?',           'en' => 'Delete this genre?'],
    'btn_cancel'              => ['fr' => 'Annuler',                        'en' => 'Cancel'],
    'btn_save'                => ['fr' => 'Enregistrer',                    'en' => 'Save'],
    'section_all_tracks'      => ['fr' => 'Toutes les pistes',              'en' => 'All tracks'],
    'songs_title'             => ['fr' => 'Chansons',                       'en' => 'Songs'],
    'sort_title'              => ['fr' => 'Trier',                          'en' => 'Sort'],
    'sort_popular'            => ['fr' => 'Les plus écoutés',               'en' => 'Most played'],
    'sort_date_desc'          => ['fr' => 'Ajouts récents',                 'en' => 'Recently added'],
    'sort_date_asc'           => ['fr' => 'Ajouts anciens',                 'en' => 'Oldest added'],
    'sort_alpha_asc'          => ['fr' => 'Nom (A-Z)',                      'en' => 'Name (A-Z)'],
    'sort_alpha_desc'         => ['fr' => 'Nom (Z-A)',                      'en' => 'Name (Z-A)'],
    'sort_artist'             => ['fr' => 'Par Artiste',                    'en' => 'By artist'],
    'playlists_title'         => ['fr' => 'Tes Mixs',                       'en' => 'Your Mixes'],
    'albums_title'            => ['fr' => 'Albums',                         'en' => 'Albums'],
    'grid_sort_name_asc'      => ['fr' => 'Nom (A-Z)',                      'en' => 'Name (A-Z)'],
    'grid_sort_name_desc'     => ['fr' => 'Nom (Z-A)',                      'en' => 'Name (Z-A)'],
    'grid_sort_recent'        => ['fr' => 'Plus récent',                    'en' => 'Most recent'],
    'search_artist_ph'        => ['fr' => 'Rechercher un artiste…',         'en' => 'Search an artist…'],
    'search_album_ph'         => ['fr' => 'Rechercher un album…',           'en' => 'Search an album…'],
    'no_albums_found'         => ['fr' => 'Aucun album pour le moment',     'en' => 'No albums yet'],
    'artists_title'           => ['fr' => 'Artistes',                       'en' => 'Artists'],
    'no_artists_found'        => ['fr' => 'Aucun artiste pour le moment',   'en' => 'No artists yet'],
    'btn_back_library'        => ['fr' => 'Retour à la bibliothèque',       'en' => 'Back to library'],
    'btn_back_playlists'      => ['fr' => 'Retour aux playlists',           'en' => 'Back to playlists'],
    'created_by'              => ['fr' => 'Créé par',                       'en' => 'Created by'],
    'btn_play'                => ['fr' => '▶ Écouter',                      'en' => '▶ Play'],
    'btn_shuffle_play'        => ['fr' => 'Aléatoire',                      'en' => 'Shuffle'],
    'btn_play_album'          => ['fr' => 'Écouter',                        'en' => 'Play'],
    'btn_edit'                => ['fr' => 'Éditer',                         'en' => 'Edit'],
    'btn_delete_short'        => ['fr' => 'Suppr',                          'en' => 'Delete'],
    'btn_add_song'            => ['fr' => 'Ajouter',                        'en' => 'Add songs'],
    'mobnav_library'          => ['fr' => 'Biblio',                         'en' => 'Library'],
    'mobnav_mixes'            => ['fr' => 'Mixs',                           'en' => 'Mixes'],
    'mobnav_admin'            => ['fr' => 'Admin',                          'en' => 'Admin'],
    'mobnav_upload'           => ['fr' => 'Upload',                         'en' => 'Upload'],
    'settings_title'          => ['fr' => 'Filtres & Paramètres',           'en' => 'Filters & Settings'],
    'settings_language_label' => ['fr' => 'Langue :',                       'en' => 'Language:'],
    'settings_theme_label'    => ['fr' => 'Thème :',                        'en' => 'Theme:'],
    'settings_adaptive_fallback_label' => ['fr' => 'Thème adaptatif par défaut (aucune lecture en cours) :', 'en' => 'Default adaptive theme (when nothing is playing):'],
    'settings_hide_genres_pre'   => ['fr' => 'Genres à',                    'en' => 'Genres to'],
    'settings_hide_genres_word'  => ['fr' => 'masquer',                     'en' => 'hide'],
    'settings_hide_admin_label'  => ['fr' => 'Masquer le Panel Admin de la navigation', 'en' => 'Hide Admin Panel from navigation'],
    'settings_hide_edit_delete_label' => ['fr' => 'Masquer les boutons modifier/supprimer sur les pistes', 'en' => 'Hide edit/delete buttons on tracks'],
    'settings_open_eq'        => ['fr' => "🎚 Ouvrir l'égaliseur",          'en' => '🎚 Open equalizer'],
    'btn_close'               => ['fr' => 'Fermer',                         'en' => 'Close'],
    'eq_title'                => ['fr' => 'Égaliseur',                      'en' => 'Equalizer'],
    'eq_enable'               => ['fr' => "Activer l'égaliseur",            'en' => 'Enable equalizer'],
    'eq_preset_flat'          => ['fr' => 'Plat',                           'en' => 'Flat'],
    'eq_preset_bass'          => ['fr' => 'Basses',                         'en' => 'Bass'],
    'eq_preset_treble'        => ['fr' => 'Aigus',                          'en' => 'Treble'],
    'eq_preset_vocal'         => ['fr' => 'Voix',                           'en' => 'Vocal'],
    'eq_band_bassboost'       => ['fr' => 'Boost Basses',                   'en' => 'Bass Boost'],
    'upload_title_ph'         => ['fr' => 'Titre (auto-détecté si vide)',   'en' => 'Title (auto-detected if empty)'],
    'upload_artist_ph'        => ['fr' => 'Artiste (auto-détecté si vide)', 'en' => 'Artist (auto-detected if empty)'],
    'upload_album_ph'         => ['fr' => 'Album (auto-détecté si vide)',   'en' => 'Album (auto-detected if empty)'],
    'edit_album_ph'           => ['fr' => 'Album (vide = aucun)',           'en' => 'Album (empty = none)'],
    'label_genre'             => ['fr' => 'Genre',                          'en' => 'Genre'],
    'label_audio_file'        => ['fr' => 'Fichier Audio',                  'en' => 'Audio File'],
    'label_cover_optional'    => ['fr' => 'Cover (optionnel)',              'en' => 'Cover (optional)'],
    'btn_publish'             => ['fr' => 'Publier',                        'en' => 'Publish'],
    'edit_track_title_h2'     => ['fr' => 'Modifier la piste',              'en' => 'Edit track'],
    'label_title_ph'          => ['fr' => 'Titre',                          'en' => 'Title'],
    'label_artist_ph'         => ['fr' => 'Artiste',                        'en' => 'Artist'],
    'label_new_cover'         => ['fr' => 'Nouvelle cover',                 'en' => 'New cover'],
    'playlist_default_title'  => ['fr' => 'Playlist',                       'en' => 'Playlist'],
    'mix_name_ph'             => ['fr' => 'Nom du mix',                     'en' => 'Mix name'],
    'search_dots_ph'          => ['fr' => '🔍 Rechercher...',               'en' => '🔍 Search...'],
    'select_tracks_label'     => ['fr' => 'Sélectionnez les titres :',      'en' => 'Select tracks:'],
    'ready_to_play'           => ['fr' => 'Prêt à écouter',                 'en' => 'Ready to play'],
    'stopped'                 => ['fr' => 'Arrêté',                         'en' => 'Stopped'],
    'title_lyrics'            => ['fr' => 'Paroles',                        'en' => 'Lyrics'],
    'title_queue'             => ['fr' => "File d'attente",                 'en' => 'Queue'],
    'queue_empty'             => ['fr' => 'File vide...',                   'en' => 'Queue is empty...'],
    'no_track_playing'        => ['fr' => 'Aucune piste en cours.',         'en' => 'No track playing.'],
    'normal_playback'         => ['fr' => 'Lecture normale',                'en' => 'Normal playback'],
    'selected_suffix'         => ['fr' => ' sélectionné(s)',                'en' => ' selected'],
    'word_and'                => ['fr' => 'et',                             'en' => 'and'],
    'playlist_public_label'   => ['fr' => 'Playlist publique (visible par tous)', 'en' => 'Public playlist (visible to everyone)'],
    'playlist_private_badge'  => ['fr' => 'Privée',                         'en' => 'Private'],
];
function t(string $key): string {
    global $I18N, $LANG;
    return $I18N[$key][$LANG] ?? $I18N[$key]['fr'] ?? $key;
}

// api.php already stores HTML-escaped text (sanitize_text on the API side), so a
// "&" or an apostrophe typed at upload ends up as a literal "&amp;"/"&#039;"
// in the database. htmlspecialchars() would escape them a second time on display,
// which would show the raw text "&amp;"/"&#039;" instead of the intended character.
// Only used for displayed text (never for value/onclick attributes,
// which must keep the exact raw value to match on the server side).
function fixEntities(string $s): string {
    $s = str_replace('&#039;', "'", $s);
    // "&" (the joining word) is translated into the current language via the
    // i18n dictionary (key word_and) rather than a hard-coded fr/en ternary,
    // so that adding a new language to $I18N is enough to cover it here.
    $s = str_replace('&amp;', t('word_and'), $s);
    return $s;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function checkRateLimit(string $action, int $limitSeconds): bool {
    $key = 'last_' . $action . '_time';
    if (isset($_SESSION[$key]) && (time() - $_SESSION[$key]) < $limitSeconds) return false;
    $_SESSION[$key] = time();
    return true;
}

// Only lets through real CSS colors (#rgb, #rrggbb, #rrggbbaa, rgb()/rgba()
// with numbers) and falls back to $default otherwise. Theme colors are printed
// raw inside the page's <style> block, so without this check a crafted value
// like "red;}</style><script>…" would inject markup/script for every visitor.
function safeColor($value, string $default): string {
    $value = is_string($value) ? trim($value) : '';
    if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value)) return $value;
    if (preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/i', $value)) return $value;
    return $default;
}

// Serves the "r,g,b" variants of the theme colors to allow
// rgba(var(--x-rgb), alpha) in the CSS — needed so that tinted glows/
// highlights keep following the chosen color (site or client theme)
// instead of staying stuck on the original purple.
function hexToRgbTriplet(string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return '142,68,173';
    $int = hexdec($hex);
    return sprintf('%d,%d,%d', ($int >> 16) & 255, ($int >> 8) & 255, $int & 255);
}

// ===========================================================
//  api.php CLIENT — index.php never talks directly to the
//  `users`/`tracks`/`playlists` tables anymore: all business
//  logic (auth, upload, playback, playlists…) goes through api.php,
//  exactly like the Amethyst Music Android client does.
//  Only the site settings (server theme, genres) are still
//  handled here: api.php has no notion of these tables.
// ===========================================================
function api_request(string $action, array $params = []): array {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $url = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/api.php?action=' . urlencode($action);
    $body = http_build_query($params);
    $fallback = ['status' => 'error', 'message' => 'api.php injoignable'];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
    } else {
        $context = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body,
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($url, false, $context);
    }

    if ($response === false) return $fallback;
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : $fallback;
}

// ── PDO MySQL (site settings: theme & genres only) ──
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        DB_HOST, DB_PORT, DB_NAME
    );
    $db = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $settingsRaw = $db->query("SELECT `setting_key`, `value` FROM `settings`")
                      ->fetchAll(PDO::FETCH_KEY_PAIR);

    $site_name           = $settingsRaw['site_name']           ?? 'Purple Music';
    $color_bg            = safeColor($settingsRaw['color_bg']            ?? '', '#0f0c1d');
    $color_panel         = safeColor($settingsRaw['color_panel']         ?? '', '#1b1429');
    $color_primary       = safeColor($settingsRaw['color_primary']       ?? '', '#8e44ad');
    $color_accent        = safeColor($settingsRaw['color_accent']        ?? '', '#bb86fc');
    $color_text          = safeColor($settingsRaw['color_text']          ?? '', '#e0e0e0');
    $color_text_muted    = safeColor($settingsRaw['color_text_muted']    ?? '', '#a196b4');
    $color_border        = safeColor($settingsRaw['color_border']        ?? '', '#3d2b56');
    $color_search_bg     = safeColor($settingsRaw['color_search_bg']     ?? '', '#241b36');
    $color_header_bg     = safeColor($settingsRaw['color_header_bg']     ?? '', 'rgba(27, 20, 41, 0.85)');
    $color_player_bg     = safeColor($settingsRaw['color_player_bg']     ?? '', 'rgba(30, 24, 45, 0.85)');
    $color_mob_nav_bg    = safeColor($settingsRaw['color_mob_nav_bg']    ?? '', 'rgba(21, 16, 32, 0.95)');
    $color_fp_gradient_1 = safeColor($settingsRaw['color_fp_gradient_1'] ?? '', '#302b63');
    $color_fp_gradient_2 = safeColor($settingsRaw['color_fp_gradient_2'] ?? '', '#0f0c29');
    $default_cover       = $settingsRaw['default_cover']       ?? 'default.png';
    $favicon_file        = $settingsRaw['favicon']             ?? 'favicon.png';

    $genresList = $db->query("SELECT `name` FROM `genres` ORDER BY `name` ASC")
                     ->fetchAll(PDO::FETCH_COLUMN);
    if (empty($genresList)) {
        $genresList = ['Phonk/Funk','Rap','Pop','Rock','Electro','Hyperpop','Nightcore','Qualité inférieure','Autre'];
    }

} catch (Exception $e) {
    die("Erreur BDD : " . $e->getMessage());
}

// ===========================================================
//  AUTHENTICATION — delegated to api.php (action=login/register)
// ===========================================================
if (isset($_POST['register'])) {
    if (!checkRateLimit('register', 30)) {
        $error = "Veuillez patienter avant de vous réinscrire.";
    } else {
        $res = api_request('register', [
            'username' => trim($_POST['username'] ?? ''),
            'password' => $_POST['password'] ?? '',
        ]);
        if (($res['status'] ?? '') === 'success') {
            $info = "Compte créé avec succès, tu peux te connecter.";
        } else {
            $error = $res['message'] ?? "Inscription impossible.";
        }
    }
}

if (isset($_POST['login'])) {
    $res = api_request('login', [
        'username' => trim($_POST['username'] ?? ''),
        'password' => $_POST['password'] ?? '',
    ]);
    if (($res['status'] ?? '') === 'success') {
        $_SESSION['user_id']  = (int)$res['user_id'];
        $_SESSION['username'] = $res['username'];
        $_SESSION['is_admin'] = !empty($res['is_admin']) ? 1 : 0;
        // api.php has no session: every authenticated call must resend
        // the password. We keep it on the server (never in localStorage)
        // to inject it into the page and let the JS call api.php.
        $_SESSION['api_pw'] = $_POST['password'] ?? '';
        header("Location: " . $_SERVER['PHP_SELF']); exit;
    } else {
        $error = $res['message'] ?? "Identifiants incorrects.";
    }
}

if (isset($_GET['logout'])) { session_destroy(); header("Location: " . $_SERVER['PHP_SELF']); exit; }

$user_id  = $_SESSION['user_id']  ?? null;
$username = $_SESSION['username'] ?? null;
$is_admin = !empty($_SESSION['is_admin']);
$api_pw   = $_SESSION['api_pw'] ?? '';

// ===========================================================
//  CONTROLS (logged-in user)
// ===========================================================
if ($user_id) {
    // CSRF check for all POST requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            die("Jeton CSRF invalide.");
        }
    }

    // ── Save admin settings ──────────────────────────────────
    if ($is_admin && isset($_POST['save_admin_settings'])) {
        $upsert = $db->prepare(
            "INSERT INTO `settings` (`setting_key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
        );
        $fields = [
            'site_name'           => trim($_POST['adm_site_name']),
            'color_bg'            => safeColor($_POST['adm_color_bg'] ?? '', '#0f0c1d'),
            'color_panel'         => safeColor($_POST['adm_color_panel'] ?? '', '#1b1429'),
            'color_primary'       => safeColor($_POST['adm_color_primary'] ?? '', '#8e44ad'),
            'color_accent'        => safeColor($_POST['adm_color_accent'] ?? '', '#bb86fc'),
            'color_text'          => safeColor($_POST['adm_color_text'] ?? '', '#e0e0e0'),
            'color_text_muted'    => safeColor($_POST['adm_color_text_muted'] ?? '', '#a196b4'),
            'color_border'        => safeColor($_POST['adm_color_border'] ?? '', '#3d2b56'),
            'color_search_bg'     => safeColor($_POST['adm_color_search_bg'] ?? '', '#241b36'),
            'color_header_bg'     => safeColor($_POST['adm_color_header_bg'] ?? '', 'rgba(27, 20, 41, 0.85)'),
            'color_player_bg'     => safeColor($_POST['adm_color_player_bg'] ?? '', 'rgba(30, 24, 45, 0.85)'),
            'color_mob_nav_bg'    => safeColor($_POST['adm_color_mob_nav_bg'] ?? '', 'rgba(21, 16, 32, 0.95)'),
            'color_fp_gradient_1' => safeColor($_POST['adm_color_fp_gradient_1'] ?? '', '#302b63'),
            'color_fp_gradient_2' => safeColor($_POST['adm_color_fp_gradient_2'] ?? '', '#0f0c29'),
        ];
        foreach ($fields as $k => $v) $upsert->execute([$k, $v]);

        if (!empty($_FILES['adm_favicon']['name'])) {
            $ext = strtolower(pathinfo($_FILES['adm_favicon']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png','ico'])) move_uploaded_file($_FILES['adm_favicon']['tmp_name'], __DIR__ . '/favicon.png');
        }
        if (!empty($_FILES['adm_default_cover']['name'])) {
            $ext = strtolower(pathinfo($_FILES['adm_default_cover']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png','jpg','jpeg'])) move_uploaded_file($_FILES['adm_default_cover']['tmp_name'], __DIR__ . '/covers/default.png');
        }
        if (!empty($_POST['adm_new_genre'])) {
            $db->prepare("INSERT IGNORE INTO `genres` (`name`) VALUES (?)")->execute([trim($_POST['adm_new_genre'])]);
        }
        header("Location: " . $_SERVER['PHP_SELF']); exit;
    }

    // POST only (covered by the CSRF check above): a plain GET link could be
    // triggered by any page that gets a logged-in admin to open it.
    if ($is_admin && isset($_POST['delete_genre'])) {
        $db->prepare("DELETE FROM `genres` WHERE `name` = ?")->execute([$_POST['delete_genre']]);
        header("Location: " . $_SERVER['PHP_SELF']); exit;
    }

    // Upload, track editing/deletion, and playlist management are
    // now handled entirely on the client via fetch() calls to
    // api.php (see the <script> further down) — index.php no longer duplicates
    // this logic (ID3 extraction, duration computation, image compression…
    // all of that already lives in api.php).
}

// ===========================================================
//  RENDERING DATA — read from api.php
// ===========================================================
$all_tracks    = $user_id ? api_request('list') : [];
if (!is_array($all_tracks) || (isset($all_tracks['status']))) $all_tracks = [];

$all_playlists = $user_id
    ? api_request('playlists', ['username' => $username, 'password' => $api_pw])
    : [];
if (!is_array($all_playlists) || (isset($all_playlists['status']))) $all_playlists = [];

// Track index by id: used to find the first 4 covers of each
// playlist (mosaic preview) without an extra API call.
$tracksById = [];
foreach ($all_tracks as $t) $tracksById[(string)$t['id']] = $t;

?>
<!DOCTYPE html>
<html lang="<?php echo $LANG; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($site_name); ?></title>
    <link rel="icon" href="<?php echo htmlspecialchars($favicon_file); ?>?v=<?php echo time(); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($site_name); ?>">
    <meta property="og:description" content="Self hosted music platform.">
    <meta property="og:image" content="<?php echo htmlspecialchars($favicon_file); ?>">
    <meta property="og:type" content="website">
    <style>
        :root {
            --bg-dark:    <?php echo $color_bg; ?>;
            --bg-panel:   <?php echo $color_panel; ?>;
            --primary:    <?php echo $color_primary; ?>;
            --accent:     <?php echo $color_accent; ?>;
            --primary-rgb: <?php echo hexToRgbTriplet($color_primary); ?>;
            --accent-rgb:  <?php echo hexToRgbTriplet($color_accent); ?>;
            --text:       <?php echo $color_text; ?>;
            --text-muted: <?php echo $color_text_muted; ?>;
            --border-color:  <?php echo $color_border; ?>;
            --border-color-rgb: <?php echo hexToRgbTriplet($color_border); ?>;
            --search-bg:     <?php echo $color_search_bg; ?>;
            --header-bg:     <?php echo $color_header_bg; ?>;
            --mob-nav-bg:    <?php echo $color_mob_nav_bg; ?>;
            --player-bg:     <?php echo $color_player_bg; ?>;
            --fp-gradient-1: <?php echo $color_fp_gradient_1; ?>;
            --fp-gradient-2: <?php echo $color_fp_gradient_2; ?>;
            /* Surfaces/text not exposed in the admin panel, but still
               following the theme chosen by the user (see applyTheme). */
            --modal-bg:    #1e162e;
            --input-bg:    #140f1f;
            --elevated-bg: #2d2444;
            --player-text: #ffffff;
            --danger: #ff4757;
            --danger-rgb: 255,71,87;
            --radius-sm: 8px; --radius-md: 16px; --radius-lg: 24px; --radius-full: 9999px;
        }
        * { box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
        body { margin:0; font-family:'Segoe UI',system-ui,-apple-system,sans-serif; background:var(--bg-dark); color:var(--text); padding-bottom:96px; padding-left:260px; padding-top:70px; overflow-x:hidden; transition:padding-left .3s cubic-bezier(.2,.8,.2,1); }
        body.login-page { padding:0; display:flex; align-items:center; justify-content:center; min-height:100vh; }
        ::-webkit-scrollbar { width:8px; } ::-webkit-scrollbar-track { background:var(--bg-dark); } ::-webkit-scrollbar-thumb { background:var(--border-color); border-radius:var(--radius-full); } ::-webkit-scrollbar-thumb:hover { background:var(--primary); }

        /* YouTube Music-style left sidebar (desktop) */
        header { display:flex; flex-direction:column; align-items:stretch; justify-content:flex-start; gap:8px; padding:25px 16px; background:var(--header-bg); backdrop-filter:blur(15px); border-right:1px solid rgba(var(--border-color-rgb),.5); border-bottom:none; position:fixed; top:70px; left:0; z-index:100; width:260px; height:calc(100vh - 70px - 72px); box-sizing:border-box; overflow-y:auto; overflow-x:hidden; transition:width .3s cubic-bezier(.2,.8,.2,1),padding .3s cubic-bezier(.2,.8,.2,1); }
        nav { display:flex; flex-direction:column; gap:4px; margin-left:0; flex-grow:1; width:100%; }
        nav span { display:flex; align-items:center; gap:14px; cursor:pointer; font-weight:600; color:var(--text-muted); transition:.3s; white-space:nowrap; padding:12px 14px; border-radius:var(--radius-sm); width:100%; box-sizing:border-box; }
        .nav-icon { flex-shrink:0; }
        .nav-icon-emoji { font-size:1.1em; line-height:1; width:20px; text-align:center; }
        nav span:hover { color:var(--text); background:rgba(255,255,255,.05); }
        nav span.active { color:var(--accent); background:rgba(var(--accent-rgb),.1); }
        .header-actions { display:flex; flex-direction:column; gap:10px; align-items:stretch; width:100%; margin-top:auto; padding-top:20px; border-top:1px solid rgba(255,255,255,.05); }
        .btn-icon { flex-shrink:0; }

        /* Collapsed left sidebar: icons only, labels hidden */
        #sidebar-toggle { position:fixed; top:96px; left:246px; z-index:5010; width:28px; height:28px; border-radius:50%; background:var(--elevated-bg); border:1px solid var(--border-color); color:var(--text-muted); cursor:pointer; display:flex; align-items:center; justify-content:center; transition:left .3s cubic-bezier(.2,.8,.2,1),transform .3s cubic-bezier(.2,.8,.2,1); }
        #sidebar-toggle:hover { color:var(--text); border-color:var(--accent); }
        body.sidebar-collapsed { padding-left:88px; }
        body.sidebar-collapsed header { width:88px; padding-left:6px; padding-right:6px; }
        body.sidebar-collapsed #sidebar-toggle { left:74px; transform:rotate(180deg); }
        body.sidebar-collapsed nav span { flex-direction:column; justify-content:center; padding:10px 2px; gap:4px; }
        body.sidebar-collapsed .nav-label { display:block; font-size:.62em; line-height:1.15; white-space:normal; text-align:center; }
        body.sidebar-collapsed .header-actions { flex-direction:column; }
        body.sidebar-collapsed .header-actions .btn-labeled { flex-direction:column; width:100%; height:auto; padding:8px 2px; border-radius:var(--radius-sm); gap:4px; }
        body.sidebar-collapsed .btn-label { display:block; font-size:.62em; line-height:1.15; white-space:normal; text-align:center; }

        .btn { padding:10px 20px; border-radius:var(--radius-full); border:none; cursor:pointer; font-weight:700; transition:all .2s ease; text-decoration:none; display:inline-flex; align-items:center; gap:8px; font-size:.9em; white-space:nowrap; justify-content:center; }
        .btn:active { transform:scale(.96); }
        .btn-primary { background:var(--primary); color:white; box-shadow:0 4px 15px rgba(var(--primary-rgb),.3); }
        .btn-primary:hover { filter:brightness(1.15); }
        .btn-outline { background:transparent; border:1px solid var(--primary); color:var(--accent); }
        .btn-outline:hover { background:rgba(var(--primary-rgb),.1); }
        .btn-danger { background:rgba(var(--danger-rgb),.1); color:var(--danger); font-size:.75em; border:1px solid rgba(var(--danger-rgb),.3); padding:6px 12px; }
        .lang-switch { display:flex; align-items:center; border:1px solid rgba(255,255,255,.1); border-radius:var(--radius-full); overflow:hidden; flex-shrink:0; }
        .lang-switch a { padding:8px 12px; font-size:.78em; font-weight:700; color:var(--text-muted); text-decoration:none; transition:.2s; }
        .lang-switch a.active { background:var(--primary); color:#fff; }
        .lang-switch a:not(.active):hover { color:var(--text); background:rgba(255,255,255,.05); }

        main { padding:30px; max-width:1600px; margin:auto; }
        /* Reading column for the Settings/Admin pages: a full-width (1600px)
           settings form would be unreadable (text fields and color pickers
           stretched across the whole width); we keep the reading comfort of a
           modal card without going back to a pop-up. */
        .settings-page-wrap { max-width:600px; margin:0 auto; }
        .controls-container { display:flex; align-items:center; justify-content:space-between; gap:15px; margin-bottom:25px; }
        .section-title { border-left:5px solid var(--primary); padding-left:15px; margin-bottom:20px; font-size:1.5em; border-radius:2px; }
        .search-row { display:flex; align-items:center; gap:15px; width:100%; }
        .search-container { flex-grow:1; position:relative; }
        .search-input { width:100%; height:50px; padding:0 25px; border-radius:50px; border:1px solid rgba(var(--border-color-rgb),.5); background:var(--search-bg); color:var(--text); font-size:1em; outline:none; transition:all .3s; box-shadow:0 4px 10px rgba(0,0,0,.2); }
        .search-input:focus { border-color:var(--accent); background:var(--elevated-bg); box-shadow:0 0 0 3px rgba(var(--accent-rgb),.2); }
        .search-input::placeholder { color:var(--text-muted); }

        /* Content top bar: app name + search, always visible (sticky) */
        #content-topbar { position:fixed; top:0; left:0; width:100%; height:70px; z-index:110; box-sizing:border-box; display:grid; grid-template-columns:1fr auto 1fr; align-items:center; gap:25px; padding:0 30px; background:var(--header-bg); backdrop-filter:blur(15px); border-bottom:1px solid rgba(var(--border-color-rgb),.5); }
        .topbar-appname { grid-column:1; justify-self:start; font-weight:800; font-size:1.3em; color:var(--accent); white-space:nowrap; letter-spacing:-.5px; flex-shrink:0; }
        .topbar-search { grid-column:2; justify-self:center; width:480px; max-width:90vw; position:relative; }
        /* The generic "input[type=text],..." rule (modal forms, further
           down) has equal or higher specificity than .search-input alone and is
           declared after it in the stylesheet: without these explicit resets
           (higher specificity thanks to .topbar-search .search-input), it
           imposed its margin (10px/20px, which off-centered the ✕ button), its padding,
           its border-radius and its background — breaking the intended search pill. */
        .topbar-search .search-input { height:44px; margin:0; padding:0 40px 0 25px; border-radius:50px; background:var(--search-bg); }
        .search-clear-btn { display:none; position:absolute; right:6px; top:50%; transform:translateY(-50%); width:28px; height:28px; padding:0; align-items:center; justify-content:center; background:none; border:none; cursor:pointer; color:var(--text-muted); border-radius:50%; }
        .search-clear-btn.visible { display:flex; }
        .search-clear-btn:hover { color:var(--text); background:rgba(255,255,255,.08); }

        .filter-wrapper { position:relative; width:50px; height:50px; flex-shrink:0; }
        .filter-icon-visual { width:100%; height:100%; background:var(--search-bg); border:1px solid rgba(var(--border-color-rgb),.5); border-radius:50%; display:flex; align-items:center; justify-content:center; color:var(--accent); box-shadow:0 4px 10px rgba(0,0,0,.2); transition:.3s; }
        .filter-select-overlay { position:absolute; top:0; left:0; width:100%; height:100%; opacity:0; cursor:pointer; appearance:none; -webkit-appearance:none; z-index:10; }
        .filter-wrapper:hover .filter-icon-visual { border-color:var(--accent); background:var(--elevated-bg); transform:translateY(-2px); }

        .track-list { background:var(--bg-panel); border-radius:24px; overflow:hidden; border:1px solid var(--border-color); min-height:200px; box-shadow:0 10px 30px rgba(0,0,0,.2); }
        .track-item { display:grid; grid-template-columns:40px 50px 1fr auto; align-items:center; padding:15px 25px; border-bottom:1px solid rgba(255,255,255,.03); gap:20px; transition:background .2s; }
        .track-item:last-child { border-bottom:none; }
        .track-item:hover { background:rgba(255,255,255,.07); }
        .mini-cover { width:50px; height:50px; border-radius:12px; object-fit:cover; box-shadow:0 4px 8px rgba(0,0,0,.3); }
        .track-index { color:var(--primary); font-weight:700; opacity:.7; }
        .track-item.pl-editable { grid-template-columns:24px 40px 50px 1fr auto; }
        .track-item.pl-editable.dragging { opacity:.4; background:rgba(var(--primary-rgb),.08); }
        .pl-drag-handle { cursor:grab; color:var(--text-muted); display:flex; align-items:center; justify-content:center; }
        .pl-drag-handle:active { cursor:grabbing; }
        .pl-icon-btn { background:none; border:none; color:var(--text-muted); cursor:pointer; padding:5px; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:.85em; line-height:1; transition:.15s; }
        .pl-icon-btn:hover { color:var(--text); background:rgba(255,255,255,.08); }
        .pl-icon-btn:disabled { opacity:.2; cursor:default; }
        .pl-icon-btn:disabled:hover { background:none; color:var(--text-muted); }
        #load-more-trigger { height:40px; text-align:center; color:var(--text-muted); padding-top:15px; font-size:.9em; }

        /* Home carousels (Recommended / Popular / Hidden gems) */
        .carousel-section { margin-bottom:35px; }
        .carousel-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:15px; gap:15px; }
        .carousel-title { font-size:1.3em; font-weight:800; margin:0; border-left:5px solid var(--primary); padding-left:15px; border-radius:2px; }
        .carousel-nav { display:flex; gap:8px; flex-shrink:0; }
        .carousel-nav-btn { background:var(--bg-panel); border:1px solid rgba(var(--border-color-rgb),.5); color:var(--text-muted); width:34px; height:34px; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:.2s; }
        .carousel-nav-btn:hover { color:var(--text); border-color:var(--accent); }
        .carousel-track { display:flex; gap:16px; overflow-x:auto; scroll-snap-type:x proximity; scroll-behavior:smooth; padding:2px 2px 12px; scrollbar-width:thin; scrollbar-color:var(--border-color) transparent; }
        .carousel-track::-webkit-scrollbar { height:6px; }
        .carousel-track::-webkit-scrollbar-track { background:transparent; }
        .carousel-track::-webkit-scrollbar-thumb { background:var(--border-color); border-radius:var(--radius-full); }
        .carousel-track::-webkit-scrollbar-thumb:hover { background:var(--primary); }
        .cards-wrap { display:grid; grid-template-columns:repeat(auto-fill,160px); gap:20px; }
        .carousel-card { flex:0 0 160px; width:160px; min-width:0; scroll-snap-align:start; cursor:pointer; }
        .carousel-card .cc-cover-wrap { position:relative; }
        .carousel-card img { width:160px; height:160px; border-radius:12px; object-fit:cover; box-shadow:0 8px 20px rgba(0,0,0,.3); transition:transform .2s; display:block; }
        .carousel-card:hover img { transform:scale(1.04); }
        .carousel-card.artist-card img { border-radius:50%; }
        .carousel-card.artist-card .cc-title { text-align:center; }
        .carousel-card .cc-title { font-weight:700; font-size:.9em; margin-top:8px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .carousel-card .cc-artist { font-size:.78em; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

        /* Animates covers until they have finished loading (instead of
           a flat/transparent square) and the card skeletons shown
           while we are still waiting for data (e.g. action=recommend) —
           the same sliding gradient, so the two steps flow into each other
           visually without a jolt. */
        @keyframes coverShimmer { 0%{background-position:-135% 0} 100%{background-position:135% 0} }
        .carousel-card img.cc-loading,
        .carousel-card .cc-cover-skeleton,
        .carousel-card .cc-title-skeleton,
        .carousel-card .cc-artist-skeleton {
            background:linear-gradient(90deg,rgba(255,255,255,.05) 25%,rgba(255,255,255,.13) 50%,rgba(255,255,255,.05) 75%);
            background-size:250% 100%; animation:coverShimmer 1.4s ease-in-out infinite;
        }
        .carousel-card .cc-cover-skeleton { width:160px; height:160px; border-radius:12px; }
        .carousel-card.artist-card .cc-cover-skeleton { border-radius:50%; }
        .carousel-card .cc-title-skeleton { width:80%; height:.9em; border-radius:4px; margin-top:8px; }
        .carousel-card .cc-artist-skeleton { width:55%; height:.78em; border-radius:4px; margin-top:5px; }

        .artist-link { cursor:pointer; }
        .artist-link:hover { color:var(--text); text-decoration:underline; }
        .artist-page-back { cursor:pointer; color:var(--text-muted); font-size:.9em; display:inline-flex; align-items:center; gap:6px; margin-bottom:12px; }
        .artist-page-back:hover { color:var(--text); }

        .artist-hero { position:relative; border-radius:24px; overflow:hidden; margin-bottom:22px; min-height:200px; display:flex; align-items:flex-end; padding:30px; box-sizing:border-box; box-shadow:0 10px 30px rgba(0,0,0,.2); }
        .artist-hero-bg { position:absolute; inset:0; z-index:0; background:var(--bg-panel); }
        .artist-hero-bg img { width:100%; height:100%; object-fit:cover; filter:blur(45px) saturate(1.4) brightness(.5); transform:scale(1.15); opacity:0; transition:opacity .6s ease; }
        .artist-hero-bg img.loaded { opacity:1; }
        .artist-hero-bg::after { content:''; position:absolute; inset:0; background:rgba(0,0,0,.35); }
        .artist-hero-content { position:relative; z-index:1; display:flex; align-items:center; gap:24px; }
        .artist-pfp { width:120px; height:120px; border-radius:50%; object-fit:cover; flex-shrink:0; box-shadow:0 8px 24px rgba(0,0,0,.4); border:4px solid rgba(255,255,255,.15); }
        .artist-pfp.album-cover-pfp { border-radius:16px; }
        .artist-hero-info .section-title { border-left:none; padding-left:0; margin:0 0 6px; }
        .artist-hero-info p { margin:0; color:rgba(255,255,255,.75); }
        .playlist-title-input { display:block; font-size:1.5em; font-weight:800; font-family:inherit; color:#fff; background:rgba(255,255,255,.1); border:none; border-bottom:2px solid var(--accent); border-radius:6px 6px 0 0; padding:2px 8px; margin:0 0 6px; width:100%; max-width:420px; }
        .playlist-title-input:focus { outline:none; background:rgba(255,255,255,.16); }
        .artist-bio { color:var(--text-muted); font-size:.92em; line-height:1.6; margin:0 0 25px; max-width:900px; }
        .artist-bio a { color:var(--accent); text-decoration:none; }
        .artist-bio a:hover { text-decoration:underline; }

        /* Genre carousel (filters the 3 carousels below) */
        .genre-pill-track { display:flex; gap:10px; overflow-x:auto; scroll-behavior:smooth; padding:2px 2px 16px; scrollbar-width:thin; scrollbar-color:var(--border-color) transparent; }
        .genre-pill-track::-webkit-scrollbar { height:6px; }
        .genre-pill-track::-webkit-scrollbar-track { background:transparent; }
        .genre-pill-track::-webkit-scrollbar-thumb { background:var(--border-color); border-radius:var(--radius-full); }
        .genre-pill-track::-webkit-scrollbar-thumb:hover { background:var(--primary); }
        .genre-pill { flex:0 0 auto; padding:9px 20px; border-radius:999px; background:var(--bg-panel); border:1px solid rgba(var(--border-color-rgb),.5); color:var(--text-muted); font-weight:700; font-size:.88em; cursor:pointer; white-space:nowrap; transition:.2s; }
        .genre-pill:hover { color:var(--text); border-color:var(--accent); }
        .genre-pill.active { background:var(--primary); border-color:var(--primary); color:#fff; }

        .playlist-grid { display:grid; grid-template-columns:repeat(auto-fill,170px); gap:16px; }
        .playlist-card { background:var(--bg-panel); border-radius:16px; padding:14px; border:1px solid rgba(var(--border-color-rgb),.5); transition:transform .3s,box-shadow .3s; cursor:pointer; }
        .playlist-card:hover { transform:translateY(-4px); box-shadow:0 12px 24px rgba(0,0,0,.35); border-color:var(--primary); }
        .playlist-card-title { margin:0 0 4px; font-size:.95em; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .playlist-card-creator { font-size:.78em; color:var(--text-muted); margin:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .playlist-cover-collage { display:grid; grid-template-columns:1fr 1fr; grid-template-rows:1fr 1fr; gap:2px; width:100%; aspect-ratio:1; border-radius:12px; overflow:hidden; margin-bottom:10px; background:var(--elevated-bg); box-shadow:0 6px 16px rgba(0,0,0,.3); }
        .playlist-cover-collage.single { grid-template-columns:1fr; grid-template-rows:1fr; }
        .playlist-cover-collage img { width:100%; height:100%; object-fit:cover; display:block; }
        .playlist-page-hero-collage { width:120px; height:120px; aspect-ratio:auto; flex-shrink:0; margin-bottom:0; box-shadow:0 8px 24px rgba(0,0,0,.4); }
        .playlist-private-badge { display:inline-flex; align-items:center; gap:4px; font-size:.75em; font-weight:700; color:var(--text-muted); background:rgba(var(--border-color-rgb),.4); padding:2px 9px; border-radius:99px; margin-left:8px; vertical-align:middle; }

        .queue-item { display:flex; align-items:center; gap:12px; padding:10px; border-radius:12px; margin-bottom:8px; cursor:pointer; border:1px solid transparent; transition:.2s; }
        .queue-item.active { background:rgba(var(--primary-rgb),.15); border-color:var(--primary); }
        .queue-item:hover { background:rgba(255,255,255,.05); }

        /* YouTube Music-style playback bar: progress line stuck to the
           top edge (full width), then a row with three zones —
           transport+time on the left, cover/title centered, volume+options on the
           right — identical in normal mode (#player-bar) and fullscreen
           (.fp-bottombar), which share the same .pb-* classes. */
        #player-bar { position:fixed; bottom:0; left:0; width:100%; height:72px; background:var(--player-bg); backdrop-filter:blur(20px) saturate(180%); padding:0 24px; border-radius:0; display:grid; grid-template-columns:1fr auto 1fr; align-items:center; column-gap:20px; z-index:1000; border-top:1px solid rgba(255,255,255,.1); box-shadow:0 -4px 20px rgba(0,0,0,.3); box-sizing:border-box; cursor:pointer; }
        /* The fullscreen player has its own bottom bar (.fp-bottombar) that
           takes up exactly the same rectangle; without this rule, the mini
           bar still stayed displayed underneath and, since .fp-bottombar
           is not fully opaque, you could see both overlapping
           (double timer display, covers blending together). */
        #player-bar:has(~ #full-player.active) { display:none; }
        .progress-bg.pb-seek { position:absolute; top:0; left:0; width:100%; height:3px; border-radius:0; z-index:2; }
        .pb-seek .progress-fill { border-radius:0; }
        .pb-transport { display:flex; align-items:center; gap:10px; justify-self:start; }
        .pb-time { display:flex; align-items:center; gap:4px; margin-left:6px; font-size:.72em; color:var(--text-muted); font-family:monospace; white-space:nowrap; }
        .pb-time-sep { opacity:.5; }
        .pb-right { display:flex; align-items:center; gap:14px; justify-self:end; }
        .player-info { display:flex; align-items:center; gap:12px; justify-self:center; max-width:320px; min-width:0; overflow:hidden; cursor:pointer; }
        #player-cover { width:44px; height:44px; border-radius:8px; object-fit:cover; box-shadow:0 4px 10px rgba(0,0,0,.3); flex-shrink:0; }
        .progress-bg { background:rgba(255,255,255,.1); height:6px; border-radius:10px; cursor:pointer; position:relative; overflow:hidden; }
        .progress-fill { background:linear-gradient(90deg,var(--primary),var(--accent)); height:100%; width:0%; border-radius:10px; }
        .control-btn { background:none; border:none; color:var(--player-text); cursor:pointer; opacity:.8; transition:.2s; padding:8px; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        /* #loopBtn did not have position:relative unlike #fp-loopBtn:
           .loop-badge (position:absolute) then climbed up to #player-bar
           (the next positioned ancestor) instead of sticking to the button,
           making the "1"/repeat badge appear in a different spot
           between the normal bar and the fullscreen player. */
        #loopBtn, #fp-loopBtn { position:relative; }
        .control-btn svg { width:20px; height:20px; fill:var(--player-text); display:block; transition:transform .15s ease; }
        .control-btn:hover { background:rgba(255,255,255,.1); opacity:1; }
        .control-btn:active svg { transform:scale(.85); }
        .control-btn.active { color:var(--accent); opacity:1; position:relative; }
        .control-btn.active svg { fill:var(--accent); }
        .control-btn.active::after { content:''; position:absolute; bottom:0; left:50%; transform:translateX(-50%); width:4px; height:4px; background:var(--accent); border-radius:50%; }
        .loop-badge { display:none; position:absolute; top:-2px; right:-2px; width:14px; height:14px; border-radius:50%; background:var(--accent); color:var(--bg-dark); font-size:9px; font-weight:800; line-height:1; align-items:center; justify-content:center; font-family:system-ui,sans-serif; box-shadow:0 0 0 2px var(--bg-panel); }
        .loop-badge.show { display:flex; }
        /* Explicit opacity:1: #fp-masterPlay also has the .control-btn class
           (for the 18px icon shared via .fp-bb-play), which sets opacity:.8 — without
           this reset, the white circle of the fullscreen player looked duller/
           greyer than the mini player's one (which doesn't have this class). */
        #masterPlay, #fp-masterPlay { background:white; border:none; width:38px; height:38px; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center; opacity:1; transition:transform .2s,box-shadow .2s; box-shadow:0 0 20px rgba(255,255,255,.3); flex-shrink:0; }
        #masterPlay:hover, #fp-masterPlay:hover { background:white; opacity:1; transform:scale(1.1); box-shadow:0 0 30px rgba(255,255,255,.5); }
        #masterPlay:active, #fp-masterPlay:active { transform:scale(.9); }
        #masterPlay svg, #fp-masterPlay svg { fill:#0f0c1d; width:18px; height:18px; }
        .volume-container { display:flex; align-items:center; gap:8px; width:100px; }
        input[type=range].vol-slider { -webkit-appearance:none; width:100%; height:4px; background:linear-gradient(90deg,var(--accent) 100%,rgba(255,255,255,.2) 100%); border-radius:5px; outline:none; cursor:pointer; }
        input[type=range].vol-slider::-webkit-slider-thumb { -webkit-appearance:none; width:12px; height:12px; background:#fff; border-radius:50%; cursor:pointer; transition:.2s; }

        /* Above #full-player (z-index:5000, .fp-bottombar:5001,
           #sidebar-toggle:5010): otherwise modals (Mix, Upload, etc.)
           visually open behind the fullscreen player when it
           is active. */
        .modal { display:none; position:fixed; z-index:6000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,.6); backdrop-filter:blur(8px); opacity:0; transition:opacity .25s ease; }
        .modal.show { opacity:1; }
        .modal-content { background:var(--modal-bg); margin:5% auto; padding:30px; width:90%; max-width:550px; border-radius:28px; border:1px solid rgba(255,255,255,.1); box-shadow:0 25px 80px rgba(0,0,0,.5); max-height:85vh; overflow-y:auto; transform:scale(.9); opacity:0; transition:transform .25s cubic-bezier(.175,.885,.32,1.275),opacity .25s ease; }
        .modal.show .modal-content { transform:scale(1); opacity:1; }

        input[type=text],input[type=password],input[type=file],select { width:100%; padding:14px; margin:10px 0 20px 0; background:var(--input-bg); border:1px solid var(--border-color); color:var(--text); border-radius:12px; outline:none; transition:.3s; }
        input[type=text]:focus,input[type=password]:focus,select:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(var(--primary-rgb),.2); }

        .adm-accordion-item { border:1px solid var(--border-color); border-radius:14px; margin-bottom:12px; background:rgba(0,0,0,.15); overflow:hidden; }
        .adm-accordion-header { background:rgba(255,255,255,.02); padding:16px 20px; font-weight:bold; font-size:1.05em; color:var(--accent); cursor:pointer; display:flex; justify-content:space-between; align-items:center; user-select:none; transition:background .2s; }
        .adm-accordion-header:hover { background:rgba(255,255,255,.05); }
        .adm-accordion-header::after { content:'▼'; font-size:.8em; opacity:.7; transition:transform .3s ease; }
        .adm-accordion-item.open .adm-accordion-header::after { transform:rotate(-180deg); color:var(--text); }
        .adm-accordion-content { padding:20px; display:none; border-top:1px solid rgba(255,255,255,.05); }

        .extended-color-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:12px; margin-bottom:10px; }
        .extended-color-item { background:var(--input-bg); padding:12px 15px; border-radius:12px; border:1px solid var(--border-color); display:flex; align-items:center; justify-content:space-between; gap:10px; }
        .extended-color-item span { font-size:.85em; color:var(--text-muted); font-weight:500; }
        .extended-color-item input[type=color] { border:none; width:45px !important; height:35px !important; background:transparent; cursor:pointer; padding:0; border-radius:6px; margin:0; flex-shrink:0; }

        .track-menu-btn { background:none; border:none; color:var(--text-muted); cursor:pointer; padding:6px 4px; border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .track-menu-btn:hover { background:rgba(128,128,128,.15); color:var(--text); }
        .track-ctx-menu { position:fixed; z-index:2000; background:var(--bg-panel); border:1px solid var(--border-color); border-radius:12px; min-width:210px; max-width:260px; max-height:320px; overflow-y:auto; box-shadow:0 10px 30px rgba(0,0,0,.45); padding:6px; display:none; }
        .track-ctx-menu.open { display:block; }
        .track-menu-item { padding:10px 12px; border-radius:8px; cursor:pointer; font-size:.9em; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .track-menu-item:hover { background:rgba(var(--primary-rgb),.15); }
        .track-menu-item.disabled { opacity:.5; cursor:default; }
        .track-menu-item.disabled:hover { background:none; }
        .track-menu-item.danger { color:var(--danger); }
        .track-menu-divider { height:1px; background:var(--border-color); margin:6px 4px; }
        .track-menu-back { font-weight:600; color:var(--text-muted); }
        .track-menu-item.has-icon { display:flex; align-items:center; gap:12px; }
        .track-menu-item.has-icon svg { flex-shrink:0; opacity:.85; }
        .track-menu-item.has-icon span { overflow:hidden; text-overflow:ellipsis; }

        .song-select-container { max-height:300px; overflow-y:auto; margin-top:15px; border:1px solid var(--border-color); border-radius:16px; background:var(--input-bg); }
        .song-select-item { display:flex; align-items:center; padding:12px; border-bottom:1px solid rgba(255,255,255,.05); cursor:pointer; transition:.2s; }
        .song-select-item:hover { background:rgba(255,255,255,.05); }
        .song-select-item.selected { background:rgba(var(--primary-rgb),.2); }

        .theme-swatch-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(72px,1fr)); gap:14px; margin:10px 0 20px; }
        .theme-swatch { display:flex; flex-direction:column; align-items:center; gap:6px; cursor:pointer; }
        .theme-swatch .swatch-circle { width:38px; height:38px; border-radius:50%; border:2px solid transparent; box-shadow:0 2px 10px rgba(0,0,0,.4); transition:.2s; }
        .theme-swatch:hover .swatch-circle { transform:scale(1.08); }
        .theme-swatch.active .swatch-circle { border-color:var(--accent); box-shadow:0 0 0 3px rgba(var(--accent-rgb),.3); }
        .theme-swatch span { font-size:.65em; color:var(--text-muted); text-align:center; line-height:1.2; }

        .eq-row { display:grid; grid-template-columns:70px 1fr 55px; align-items:center; gap:12px; margin-bottom:14px; }
        .eq-label { font-size:.8em; color:var(--text-muted); font-weight:600; }
        .eq-val { font-size:.75em; color:var(--accent); font-family:monospace; text-align:right; }
        .eq-preset-row { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:22px; }

        .lyric-line { color:rgba(255,255,255,.5); font-size:1.05em; font-weight:600; padding:9px 0; cursor:pointer; transition:.2s; }
        .lyric-line:hover { color:rgba(255,255,255,.8); }
        .lyric-line.active { color:var(--player-text); font-size:1.25em; }
        .lyrics-status { color:rgba(255,255,255,.6); margin-top:20px; padding:0 10px; text-align:center; }

        /* YouTube Music-style fullscreen player: cover centered on the left,
           "Queue / Lyrics" panel on the right (always visible on
           desktop), full-width control bar at the bottom. The sidebar
           stays visible on the left (the player only covers the content area). */
        #full-player { position:fixed; top:100%; left:260px; width:calc(100% - 260px); height:calc(100% - 70px); background:radial-gradient(circle at top right,var(--fp-gradient-1),var(--fp-gradient-2)); z-index:5000; transition:top .4s cubic-bezier(.2,.8,.2,1),left .3s cubic-bezier(.2,.8,.2,1),width .3s cubic-bezier(.2,.8,.2,1); display:flex; flex-direction:column; box-sizing:border-box; color:var(--player-text); overflow:hidden; }
        #full-player.active { top:70px; }
        /* During the closing animation, we go back under #player-bar (z-index
           900 < 1000) so the panel slides "under" the mini playback bar
           instead of covering it while it moves off screen. */
        #full-player.closing { z-index:900; }
        body.sidebar-collapsed #full-player { left:88px; width:calc(100% - 88px); }

        /* Ambient background: the current track's cover, blurred and darkened,
           Spotify/Apple Music style — replaces the fixed theme gradient as soon as
           a cover is loaded. */
        #fp-bg { position:absolute; inset:-60px; z-index:0; overflow:hidden; }
        #fp-bg-img { width:100%; height:100%; object-fit:cover; filter:blur(50px) saturate(1.4) brightness(.55); transform:scale(1.15); opacity:0; transition:opacity .8s ease; }
        #fp-bg-img.loaded { opacity:1; }
        #fp-bg::after { content:''; position:absolute; inset:0; background:rgba(0,0,0,.2); }

        .fp-mobile-tabs-toggle .fp-btn { background:rgba(0,0,0,.3); border:none; cursor:pointer; padding:10px; border-radius:50%; display:flex; }
        .fp-mobile-tabs-toggle { display:none; position:absolute; top:20px; right:20px; z-index:10; gap:8px; }
        .fp-btn.active { background:rgba(var(--primary-rgb),.6); }
        .fp-body, .fp-bottombar { position:relative; z-index:1; }

        .fp-body { flex:1; min-height:0; display:flex; flex-direction:row; padding-bottom:72px; }
        .fp-main { flex:1; min-width:0; display:flex; align-items:center; justify-content:center; padding:40px; box-sizing:border-box; }
        .fp-art-container { width:100%; max-width:560px; }
        #fp-cover { width:100%; height:auto; aspect-ratio:1/1; object-fit:cover; border-radius:8px; box-shadow:0 20px 60px rgba(0,0,0,.5); display:block; }

        /* transform:translateZ(0) forces Safari/WebKit to put this blurred panel
           (backdrop-filter) on its own compositing layer: without it, a
           content change (lyrics loaded/missing) may not be
           repainted and the Queue/Lyrics tab bar stays visually frozen
           (invisible) until the next repaint forced by something else. */
        #fp-sidebar { width:400px; flex-shrink:0; background:rgba(0,0,0,.25); backdrop-filter:blur(20px); border-left:1px solid rgba(255,255,255,.08); box-sizing:border-box; overflow:hidden; display:flex; flex-direction:column; transition:width .3s cubic-bezier(.2,.8,.2,1); transform:translateZ(0); -webkit-transform:translateZ(0); }
        .fp-sidebar-tabs { display:flex; align-items:center; flex-shrink:0; border-bottom:1px solid rgba(255,255,255,.08); transform:translateZ(0); }
        .fp-tab-btn { flex:1; background:none; border:none; padding:20px 10px; color:var(--player-text); opacity:.55; cursor:pointer; font-weight:700; font-size:.75em; letter-spacing:1px; text-transform:uppercase; white-space:nowrap; border-bottom:2px solid transparent; transition:.2s; }
        .fp-tab-btn:hover { opacity:.85; }
        .fp-tab-btn.active { opacity:1; border-bottom-color:var(--accent); color:var(--accent); }
        .fp-sidebar-close { display:none; background:none; border:none; cursor:pointer; padding:0 14px; opacity:.6; flex-shrink:0; }
        .fp-sidebar-close:hover { opacity:1; }
        .fp-sidebar-content { flex:1; overflow-y:auto; padding:15px 10px; overflow-anchor:none; }
        .fp-tab-pane { display:none; }
        .fp-tab-pane.active { display:block; }

        /* Full-width control bar at the bottom (YouTube Music style): detached
           from the #full-player container (which stops to the right of the sidebar)
           to take up the whole screen width, exactly like #player-bar
           in normal mode. Hidden by default; only shown when the fullscreen
           player is active, via the #full-player.active selector below. */
        .fp-bottombar { position:fixed; left:0; bottom:0; width:100%; height:72px; display:none; grid-template-columns:1fr auto 1fr; align-items:center; column-gap:20px; padding:0 24px; border-top:1px solid rgba(255,255,255,.1); background:var(--player-bg); backdrop-filter:blur(20px) saturate(180%); box-sizing:border-box; z-index:5001; cursor:pointer; }
        #full-player.active .fp-bottombar { display:grid; }
        .fp-bb-track { display:flex; align-items:center; gap:12px; justify-self:center; max-width:320px; min-width:0; overflow:hidden; cursor:pointer; }
        .fp-bb-track img { width:44px; height:44px; border-radius:8px; object-fit:cover; flex-shrink:0; box-shadow:0 4px 10px rgba(0,0,0,.3); }
        .fp-bb-info { overflow:hidden; }
        #fp-title { font-size:.95em; font-weight:700; white-space:nowrap; overflow:hidden; position:relative; mask-image:linear-gradient(to right,transparent 0%,black 5%,black 95%,transparent 100%); -webkit-mask-image:linear-gradient(to right,transparent 0%,black 5%,black 95%,transparent 100%); }
        #fp-title span { display:inline-block; }
        .scrolling-active { padding-left:100%; animation:marquee 12s linear infinite; }
        @keyframes marquee { 0%{transform:translate(0,0)} 100%{transform:translate(-100%,0)} }

        .view-fade { animation:viewFadeIn .35s cubic-bezier(.2,.8,.2,1); }
        @keyframes viewFadeIn { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
        .track-item { animation:trackFadeIn .3s cubic-bezier(.2,.8,.2,1) backwards; }
        @keyframes trackFadeIn { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:translateY(0)} }
        #fp-artist { font-size:.75em; color:var(--accent); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .fp-bb-play svg { width:18px !important; height:18px !important; }

        #mobile-bottom-nav { display:none; position:fixed; bottom:0; left:0; width:100%; background:var(--mob-nav-bg); backdrop-filter:blur(15px); border-top:1px solid rgba(255,255,255,.05); z-index:3000; justify-content:space-around; padding:10px 0 15px 0; height:70px; box-sizing:border-box; }
        .mob-nav-item { display:flex; flex-direction:column; align-items:center; justify-content:center; color:var(--text-muted); font-size:.7em; background:none; border:none; gap:5px; font-weight:600; width:20%; }
        .mob-nav-item svg { width:24px; height:24px; fill:currentColor; transition:transform .2s; }
        .mob-nav-item.active { color:var(--accent); }
        .mobile-settings-btn { display:none; }
        .settings-grid { display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:25px; }
        .settings-grid label { display:flex; align-items:center; gap:10px; cursor:pointer; font-size:.95em; }
        .adm-genre-item { display:flex; justify-content:space-between; align-items:center; padding:8px; background:rgba(0,0,0,.2); margin-bottom:5px; border-radius:8px; border:1px solid var(--border-color); }

        @media(max-width:768px){
            body { padding-bottom:240px; padding-left:0; padding-top:0; }
            header { display:none; }
            #sidebar-toggle { display:none; }
            .mobile-settings-btn { display:flex; order:2; flex-shrink:0; margin-left:auto; background:none; border:none; padding:5px; cursor:pointer; align-items:center; }
            .mobile-settings-btn svg { width:22px; height:22px; fill:var(--text-muted); }
            main { padding:20px; width:100%; box-sizing:border-box; }
            #content-topbar { position:sticky; top:0; left:auto; width:auto; height:auto; z-index:90; display:flex; flex-wrap:wrap; padding:12px 15px; gap:10px; }
            .topbar-appname { order:1; font-size:1.1em; }
            .topbar-search { order:3; width:auto; flex:1 1 100%; max-width:none; }
            #full-player { left:0; width:100%; height:100%; }
            #full-player.active { top:0; }
            .track-item { grid-template-columns:50px 1fr auto; padding:12px 10px; gap:12px; }
            .track-index { display:none; }
            .carousel-card { flex-basis:120px; width:120px; }
            .carousel-card img { width:120px; height:120px; }
            .cards-wrap { grid-template-columns:repeat(auto-fill,120px); }
            .carousel-nav { display:none; }
            #player-bar { width:calc(100% - 20px); max-width:100%; height:auto; bottom:80px; left:50%; transform:translateX(-50%); border-radius:16px; display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; row-gap:8px; padding:14px 15px 12px; box-sizing:border-box; }
            .player-info { width:100%; order:1; max-width:none; justify-content:flex-start; }
            .pb-transport { order:2; }
            .pb-right { display:contents; }
            #masterPlay, #fp-masterPlay { width:45px; height:45px; }
            .volume-container { display:none; }
            #loopBtn, #shuffleBtn, #fp-loopBtn, #fp-shuffleBtn { order:3; }
            .modal-content { width:90%; margin:8% auto; padding:25px; }
            .settings-grid { grid-template-columns:1fr; }
            #mobile-bottom-nav { display:flex; }
            .extended-color-grid { grid-template-columns:1fr; }
            .fp-mobile-tabs-toggle { display:flex; }
            .fp-art-container { max-width:280px; }
            .fp-main { padding:20px; }
            #fp-sidebar { position:absolute; inset:0; width:0; height:100%; z-index:20; border-left:none; transition:width .3s cubic-bezier(.2,.8,.2,1); }
            #fp-sidebar.open { width:100%; }
            .fp-sidebar-close { display:block; }
            .fp-body { padding-bottom:0; }
            .fp-bottombar { position:static; height:auto; z-index:1; display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; row-gap:8px; padding:10px 15px 16px; }
            #full-player.active .fp-bottombar { display:flex; }
            .fp-bb-track { width:100%; order:1; max-width:none; }
        }
    </style>
</head>
<body<?php echo !$user_id ? ' class="login-page"' : ''; ?>>

<?php if (!$user_id): ?>
<div class="lang-switch" style="position:fixed;top:20px;right:20px;">
    <a href="?setlang=fr" class="<?php echo $LANG === 'fr' ? 'active' : ''; ?>">FR</a>
    <a href="?setlang=en" class="<?php echo $LANG === 'en' ? 'active' : ''; ?>">EN</a>
</div>
<div style="max-width:350px;width:90%;text-align:center;">
    <div class="logo" style="font-size:3em;margin-bottom:30px;"><?php echo htmlspecialchars($site_name); ?></div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <?php if (isset($error)) echo "<p style='color:var(--danger);'>" . htmlspecialchars($error) . "</p>"; ?>
        <?php if (isset($info))  echo "<p style='color:#2ecc71;'>" . htmlspecialchars($info) . "</p>"; ?>
        <input type="text" name="username" placeholder="<?php echo htmlspecialchars(t('login_username_ph')); ?>" required style="padding:15px;border-radius:12px;">
        <input type="password" name="password" placeholder="<?php echo htmlspecialchars(t('login_password_ph')); ?>" required style="padding:15px;border-radius:12px;">
        <button type="submit" name="login" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:10px;padding:15px;"><?php echo htmlspecialchars(t('login_submit')); ?></button>
        <button type="submit" name="register" class="btn btn-outline" style="width:100%;justify-content:center;margin-top:15px;padding:15px;"><?php echo htmlspecialchars(t('register_submit')); ?></button>
    </form>
    <?php $rgpd_file = $LANG === 'en' ? 'PRIVACY.md' : 'RGPD.md'; ?>
    <a href="https://github.com/qcom-toolbox/Amethyst-Music/blob/main/<?php echo $rgpd_file; ?>" target="_blank" rel="noopener noreferrer" style="display:inline-block;margin-top:20px;font-size:.8em;color:var(--text-muted);text-decoration:underline;"><?php echo htmlspecialchars(t('rgpd_link_label')); ?></a>
</div>
<?php else: ?>

<header>
    <nav>
        <span id="nav-accueil" class="active" onclick="showSection('accueil')" title="<?php echo htmlspecialchars(t('nav_library')); ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/></svg>
            <span class="nav-label"><?php echo htmlspecialchars(t('nav_library')); ?></span>
        </span>
        <span id="nav-playlists" onclick="showSection('playlists')" title="<?php echo htmlspecialchars(t('nav_playlists')); ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M15 6H3v2h12V6zm0 4H3v2h12v-2zM3 16h8v-2H3v2zM17 6v8.18c-.31-.11-.65-.18-1-.18-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3V8h3V6h-5z"/></svg>
            <span class="nav-label"><?php echo htmlspecialchars(t('nav_playlists')); ?></span>
        </span>
        <span id="nav-artists" onclick="showSection('artists')" title="<?php echo htmlspecialchars(t('nav_artists')); ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
            <span class="nav-label"><?php echo htmlspecialchars(t('nav_artists')); ?></span>
        </span>
        <span id="nav-albums" onclick="showSection('albums')" title="<?php echo htmlspecialchars(t('nav_albums')); ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zm0 15.5A6.5 6.5 0 1 1 12 5.5a6.5 6.5 0 0 1 0 13zM12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z"/></svg>
            <span class="nav-label"><?php echo htmlspecialchars(t('nav_albums')); ?></span>
        </span>
        <?php if ($is_admin): ?>
            <span id="nav-admin-page" class="admin-nav-btn" onclick="showSection('admin-page')" title="<?php echo htmlspecialchars(t('nav_admin')); ?>">
                <svg class="nav-icon" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M19.4 13c0-.3.1-.6.1-1s0-.7-.1-1l2.1-1.7c.2-.2.2-.4.1-.6l-2-3.5c-.1-.2-.3-.3-.6-.2l-2.5 1c-.5-.4-1.1-.7-1.7-1l-.4-2.7c0-.2-.2-.4-.5-.4h-4c-.3 0-.5.2-.5.4l-.4 2.7c-.6.2-1.2.6-1.7 1l-2.5-1c-.2-.1-.5 0-.6.2l-2 3.5c-.1.2-.1.5.1.6L4.6 11c-.1.3-.1.6-.1 1s0 .7.1 1l-2.1 1.7c-.2.2-.2.4-.1.6l2 3.5c.1.2.3.3.6.2l2.5-1c.5.4 1.1.7 1.7 1l.4 2.7c0 .2.2.4.5.4h4c.3 0 .5-.2.5-.4l.4-2.7c.6-.2 1.2-.6 1.7-1l2.5 1c.2.1.5 0 .6-.2l2-3.5c.1-.2.1-.5-.1-.6L19.4 13zM12 15.5c-1.9 0-3.5-1.6-3.5-3.5s1.6-3.5 3.5-3.5 3.5 1.6 3.5 3.5-1.6 3.5-3.5 3.5z"/></svg>
                <span class="nav-label"><?php echo htmlspecialchars(t('nav_admin')); ?></span>
            </span>
        <?php endif; ?>
    </nav>
    <div class="header-actions">
        <button class="btn btn-primary btn-labeled" onclick="openCreateModal()" title="<?php echo htmlspecialchars(t('header_mix')); ?>">
            <svg class="btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            <span class="btn-label"><?php echo htmlspecialchars(t('header_mix')); ?></span>
        </button>
        <button class="btn btn-outline btn-labeled" onclick="openModal('uploadModal')" title="<?php echo htmlspecialchars(t('header_upload')); ?>">
            <svg class="btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z"/></svg>
            <span class="btn-label"><?php echo htmlspecialchars(t('header_upload')); ?></span>
        </button>
        <button class="btn btn-outline btn-labeled" onclick="showHistoryPage()" title="<?php echo htmlspecialchars(t('header_history')); ?>">
            <svg class="btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M13 3a9 9 0 0 0-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42A8.954 8.954 0 0 0 13 21a9 9 0 0 0 0-18zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z"/></svg>
            <span class="btn-label"><?php echo htmlspecialchars(t('header_history')); ?></span>
        </button>
        <button class="btn btn-outline btn-labeled" onclick="showSection('settings-page')" title="<?php echo htmlspecialchars(t('header_settings')); ?>">
            <svg class="btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M19.4 13c0-.3.1-.6.1-1s0-.7-.1-1l2.1-1.7c.2-.2.2-.4.1-.6l-2-3.5c-.1-.2-.3-.3-.6-.2l-2.5 1c-.5-.4-1.1-.7-1.7-1l-.4-2.7c0-.2-.2-.4-.5-.4h-4c-.3 0-.5.2-.5.4l-.4 2.7c-.6.2-1.2.6-1.7 1l-2.5-1c-.2-.1-.5 0-.6.2l-2 3.5c-.1.2-.1.5.1.6L4.6 11c-.1.3-.1.6-.1 1s0 .7.1 1l-2.1 1.7c-.2.2-.2.4-.1.6l2 3.5c.1.2.3.3.6.2l2.5-1c.5.4 1.1.7 1.7 1l.4 2.7c0 .2.2.4.5.4h4c.3 0 .5-.2.5-.4l.4-2.7c.6-.2 1.2-.6 1.7-1l2.5 1c.2.1.5 0 .6-.2l2-3.5c.1-.2.1-.5-.1-.6L19.4 13zM12 15.5c-1.9 0-3.5-1.6-3.5-3.5s1.6-3.5 3.5-3.5 3.5 1.6 3.5 3.5-1.6 3.5-3.5 3.5z"/></svg>
            <span class="btn-label"><?php echo htmlspecialchars(t('header_settings')); ?></span>
        </button>
    </div>
</header>
<button id="sidebar-toggle" onclick="toggleSidebar()" title="<?php echo htmlspecialchars(t('sidebar_toggle_title')); ?>">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
</button>

<div id="content-topbar">
    <div class="topbar-appname"><?php echo htmlspecialchars($site_name); ?></div>
    <button class="mobile-settings-btn" onclick="showSection('settings-page')">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M19.4 13c0-.3.1-.6.1-1s0-.7-.1-1l2.1-1.7c.2-.2.2-.4.1-.6l-2-3.5c-.1-.2-.3-.3-.6-.2l-2.5 1c-.5-.4-1.1-.7-1.7-1l-.4-2.7c0-.2-.2-.4-.5-.4h-4c-.3 0-.5.2-.5.4l-.4 2.7c-.6.2-1.2.6-1.7 1l-2.5-1c-.2-.1-.5 0-.6.2l-2 3.5c-.1.2-.1.5.1.6L4.6 11c-.1.3-.1.6-.1 1s0 .7.1 1l-2.1 1.7c-.2.2-.2.4-.1.6l2 3.5c.1.2.3.3.6.2l2.5-1c.5.4 1.1.7 1.7 1l.4 2.7c0 .2.2.4.5.4h4c.3 0 .5-.2.5-.4l.4-2.7c.6-.2 1.2-.6 1.7-1l2.5 1c.2.1.5 0 .6-.2l2-3.5c.1-.2.1-.5-.1-.6L19.4 13zM12 15.5c-1.9 0-3.5-1.6-3.5-3.5s1.6-3.5 3.5-3.5 3.5 1.6 3.5 3.5-1.6 3.5-3.5 3.5z"/></svg>
    </button>
    <div class="topbar-search">
        <input type="text" id="searchInput" class="search-input" placeholder="<?php echo htmlspecialchars(t('search_placeholder')); ?>" onkeyup="onSearchInput()" onkeydown="handleSearchKeydown(event)">
        <button type="button" class="search-clear-btn" id="searchClearBtn" onclick="clearSearch()" title="<?php echo htmlspecialchars(t('btn_clear_search')); ?>" aria-label="<?php echo htmlspecialchars(t('btn_clear_search')); ?>"><svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></button>
    </div>
</div>

<?php if ($is_admin): ?>
<main id="admin-page" style="display:none;">
    <h2 class="section-title" style="margin-bottom:25px;"><?php echo htmlspecialchars(t('admin_title')); ?></h2>
    <!-- Target of the genre ✕ buttons (form="delete-genre-form"): HTML forms
    can't be nested inside the admin settings form below. -->
    <form id="delete-genre-form" method="post" action="?page=admin-page">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
    </form>
    <div class="settings-page-wrap">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <div class="adm-accordion-item open">
                <div class="adm-accordion-header" onclick="toggleAccordion(this)"><?php echo htmlspecialchars(t('admin_section_general')); ?></div>
                <div class="adm-accordion-content" style="display:block;">
                    <label><?php echo htmlspecialchars(t('admin_app_name')); ?></label>
                    <input type="text" name="adm_site_name" value="<?php echo htmlspecialchars($site_name); ?>" required>
                </div>
            </div>

            <div class="adm-accordion-item">
                <div class="adm-accordion-header" onclick="toggleAccordion(this)"><?php echo htmlspecialchars(t('admin_section_theme')); ?></div>
                <div class="adm-accordion-content">
                    <div class="extended-color-grid">
                        <div class="extended-color-item"><span><?php echo htmlspecialchars(t('color_bg')); ?></span><input type="color" name="adm_color_bg" value="<?php echo $color_bg; ?>"></div>
                        <div class="extended-color-item"><span><?php echo htmlspecialchars(t('color_panel')); ?></span><input type="color" name="adm_color_panel" value="<?php echo $color_panel; ?>"></div>
                        <div class="extended-color-item"><span><?php echo htmlspecialchars(t('color_primary')); ?></span><input type="color" name="adm_color_primary" value="<?php echo $color_primary; ?>"></div>
                        <div class="extended-color-item"><span><?php echo htmlspecialchars(t('color_accent')); ?></span><input type="color" name="adm_color_accent" value="<?php echo $color_accent; ?>"></div>
                        <div class="extended-color-item"><span><?php echo htmlspecialchars(t('color_text')); ?></span><input type="color" name="adm_color_text" value="<?php echo $color_text; ?>"></div>
                        <div class="extended-color-item"><span><?php echo htmlspecialchars(t('color_text_muted')); ?></span><input type="color" name="adm_color_text_muted" value="<?php echo $color_text_muted; ?>"></div>
                        <div class="extended-color-item"><span><?php echo htmlspecialchars(t('color_border')); ?></span><input type="color" name="adm_color_border" value="<?php echo $color_border; ?>"></div>
                        <div class="extended-color-item"><span><?php echo htmlspecialchars(t('color_search_bg')); ?></span><input type="color" name="adm_color_search_bg" value="<?php echo $color_search_bg; ?>"></div>
                        <div class="extended-color-item"><span><?php echo htmlspecialchars(t('color_fp_gradient_1')); ?></span><input type="color" name="adm_color_fp_gradient_1" value="<?php echo $color_fp_gradient_1; ?>"></div>
                        <div class="extended-color-item"><span><?php echo htmlspecialchars(t('color_fp_gradient_2')); ?></span><input type="color" name="adm_color_fp_gradient_2" value="<?php echo $color_fp_gradient_2; ?>"></div>
                    </div>
                    <label style="margin-top:12px;display:block;"><?php echo htmlspecialchars(t('color_header_bg_label')); ?></label>
                    <input type="text" name="adm_color_header_bg" value="<?php echo htmlspecialchars($color_header_bg); ?>">
                    <label><?php echo htmlspecialchars(t('color_player_bg_label')); ?></label>
                    <input type="text" name="adm_color_player_bg" value="<?php echo htmlspecialchars($color_player_bg); ?>">
                    <label><?php echo htmlspecialchars(t('color_mob_nav_bg_label')); ?></label>
                    <input type="text" name="adm_color_mob_nav_bg" value="<?php echo htmlspecialchars($color_mob_nav_bg); ?>">
                </div>
            </div>

            <div class="adm-accordion-item">
                <div class="adm-accordion-header" onclick="toggleAccordion(this)"><?php echo htmlspecialchars(t('admin_section_assets')); ?></div>
                <div class="adm-accordion-content">
                    <label><?php echo htmlspecialchars(t('admin_new_favicon')); ?></label>
                    <input type="file" name="adm_favicon" accept="image/png,image/x-icon">
                    <label><?php echo htmlspecialchars(t('admin_new_cover')); ?></label>
                    <input type="file" name="adm_default_cover" accept="image/png">
                </div>
            </div>

            <div class="adm-accordion-item">
                <div class="adm-accordion-header" onclick="toggleAccordion(this)"><?php echo htmlspecialchars(t('admin_section_genres')); ?></div>
                <div class="adm-accordion-content">
                    <label><?php echo htmlspecialchars(t('admin_create_genre')); ?></label>
                    <input type="text" name="adm_new_genre" placeholder="<?php echo htmlspecialchars(t('admin_genre_ph')); ?>">
                    <label style="font-weight:bold;display:block;margin-bottom:5px;"><?php echo htmlspecialchars(t('admin_active_genres')); ?></label>
                    <div style="max-height:160px;overflow-y:auto;border:1px solid var(--border-color);padding:10px;border-radius:10px;">
                        <?php foreach ($genresList as $g): ?>
                            <div class="adm-genre-item">
                                <span><?php echo htmlspecialchars(fixEntities($g)); ?></span>
                                <button type="submit" form="delete-genre-form" name="delete_genre" value="<?php echo htmlspecialchars($g); ?>" style="color:var(--danger);background:none;border:none;padding:0;cursor:pointer;font:inherit;font-weight:bold;" onclick="return confirm('<?php echo htmlspecialchars(t('confirm_delete_genre'), ENT_QUOTES); ?>')">✕</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div style="display:flex;gap:15px;margin-top:25px;">
                <button type="button" class="btn" style="flex:1;border:1px solid rgba(255,255,255,.1);" onclick="showSection('accueil')"><?php echo htmlspecialchars(t('btn_cancel')); ?></button>
                <button type="submit" name="save_admin_settings" class="btn btn-primary" style="flex:1;"><?php echo htmlspecialchars(t('btn_save')); ?></button>
            </div>
        </form>
    </div>
</main>
<?php endif; ?>

<main id="accueil">
    <div class="genre-pill-track" id="genre-pills-track"></div>
    <div id="home-carousels"></div>
    <div id="search-artists-section" style="display:none;">
        <h2 class="section-title" style="margin-bottom:25px;"><?php echo htmlspecialchars(t('artists_title')); ?></h2>
        <div class="cards-wrap" id="search-artists-grid"></div>
    </div>
    <div id="search-albums-section" style="display:none;">
        <h2 class="section-title" style="margin-bottom:25px;"><?php echo htmlspecialchars(t('albums_title')); ?></h2>
        <div class="cards-wrap" id="search-albums-grid"></div>
    </div>
    <div class="controls-container">
        <h2 class="section-title" id="tracks-section-title" style="margin-bottom:0;"><?php echo htmlspecialchars(t('section_all_tracks')); ?></h2>
        <div class="search-row" style="width:auto;justify-content:flex-end;">
            <div class="filter-wrapper" title="<?php echo htmlspecialchars(t('sort_title')); ?>">
                <div class="filter-icon-visual">
                    <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M3 4c0-.55.45-1 1-1h10c.55 0 1 .45 1 1v1.5c0 .28-.11.53-.3.71L10 10.9v5.2c0 .28-.11.53-.29.71l-2 2c-.18.18-.43.29-.71.29s-.53-.11-.71-.29A.996.996 0 0 1 6 18.1v-7.2L3.3 6.21A.996.996 0 0 1 3 5.5V4z"/><rect x="16" y="5" width="6" height="2" rx="1"/><rect x="16" y="11" width="6" height="2" rx="1"/><rect x="16" y="17" width="6" height="2" rx="1"/></svg>
                </div>
                <select id="sortSelect" class="filter-select-overlay" onchange="filterAndSortTracks()">
                    <option value="popular" selected><?php echo htmlspecialchars(t('sort_popular')); ?></option>
                    <option value="date_desc"><?php echo htmlspecialchars(t('sort_date_desc')); ?></option>
                    <option value="date_asc"><?php echo htmlspecialchars(t('sort_date_asc')); ?></option>
                    <option value="alpha_asc"><?php echo htmlspecialchars(t('sort_alpha_asc')); ?></option>
                    <option value="alpha_desc"><?php echo htmlspecialchars(t('sort_alpha_desc')); ?></option>
                    <option value="artist"><?php echo htmlspecialchars(t('sort_artist')); ?></option>
                </select>
            </div>
        </div>
    </div>
    <div class="track-list" id="global-list"></div>
    <div id="load-more-trigger"></div>
</main>

<main id="playlists" style="display:none;">
    <h2 class="section-title" style="margin-bottom:25px;"><?php echo htmlspecialchars(t('playlists_title')); ?></h2>
    <div class="playlist-grid">
        <?php foreach ($all_playlists as $p):
            $songIds = array_filter(explode(',', (string)$p['song_ids']));
            $covers = [];
            foreach ($songIds as $sid) {
                if (isset($tracksById[$sid])) $covers[] = $tracksById[$sid]['cover_url'];
                if (count($covers) >= 4) break;
            }
            if (!$covers) $covers[] = 'covers/default.png';
            $collageSlots = count($covers) === 1 ? 1 : 4;
            $isPublic = !isset($p['is_public']) || (int)$p['is_public'] === 1;
        ?>
            <div class="playlist-card" onclick="showPlaylistPage(<?php echo (int)$p['id']; ?>)">
                <div class="playlist-cover-collage<?php echo $collageSlots === 1 ? ' single' : ''; ?>">
                    <?php for ($i = 0; $i < $collageSlots; $i++): ?>
                        <img src="<?php echo htmlspecialchars($covers[$i % count($covers)]); ?>" loading="lazy" alt="" class="cc-loading" onload="this.classList.remove('cc-loading')" onerror="this.classList.remove('cc-loading');this.onerror=null;this.src='covers/default.png'">
                    <?php endfor; ?>
                </div>
                <h3 class="playlist-card-title"><?php echo htmlspecialchars(fixEntities($p['name'])); ?><?php if (!$isPublic): ?><span class="playlist-private-badge">🔒 <?php echo htmlspecialchars(t('playlist_private_badge')); ?></span><?php endif; ?></h3>
                <p class="playlist-card-creator"><?php echo htmlspecialchars(t('created_by')); ?> <strong><?php echo htmlspecialchars(fixEntities($p['creator'])); ?></strong></p>
            </div>
        <?php endforeach; ?>
    </div>
</main>

<main id="playlist-page" style="display:none;">
    <div class="artist-page-back" onclick="showSection('playlists')">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
        <span><?php echo htmlspecialchars(t('btn_back_playlists')); ?></span>
    </div>
    <div class="artist-hero">
        <div class="artist-hero-bg"><img id="playlist-hero-bg-img" alt="" onerror="this.onerror=null;this.src='covers/default.png'"></div>
        <div class="artist-hero-content">
            <div class="playlist-cover-collage playlist-page-hero-collage" id="playlist-page-collage"></div>
            <div class="artist-hero-info">
                <h2 class="section-title" id="playlist-page-title"></h2>
                <input type="text" id="playlist-page-title-input" class="playlist-title-input" style="display:none;" onkeydown="if(event.key==='Enter'){event.preventDefault();this.blur();}" onblur="savePlaylistTitleInline()">
                <p id="playlist-page-count"></p>
                <p><span id="playlist-page-creator-text"></span><span class="playlist-private-badge" id="playlist-page-private-badge" style="display:none;">🔒 <?php echo htmlspecialchars(t('playlist_private_badge')); ?></span></p>
                <label id="playlist-page-visibility-toggle" style="display:none;align-items:center;gap:8px;margin:8px 0 0;cursor:pointer;">
                    <input type="checkbox" id="playlist-page-public-checkbox" onchange="togglePlaylistVisibility(this.checked)" style="width:auto;">
                    <span style="font-size:.85em;color:rgba(255,255,255,.75);"><?php echo htmlspecialchars(t('playlist_public_label')); ?></span>
                </label>
                <div style="display:flex;gap:12px;margin-top:15px;flex-wrap:wrap;">
                    <button class="btn btn-primary btn-labeled" onclick="playPlaylist(currentViewedPlaylist.song_ids, currentViewedPlaylist.id, false)">
                        <svg class="btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                        <span class="btn-label"><?php echo htmlspecialchars(t('btn_play_album')); ?></span>
                    </button>
                    <button class="btn btn-outline btn-labeled" onclick="playPlaylist(currentViewedPlaylist.song_ids, currentViewedPlaylist.id, true)">
                        <svg class="btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M10.59 9.17L5.41 4 4 5.41l5.17 5.17 1.42-1.41zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4h-5.5zm.33 9.41l-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04-3.13-3.13z"/></svg>
                        <span class="btn-label"><?php echo htmlspecialchars(t('btn_shuffle_play')); ?></span>
                    </button>
                    <div id="playlist-page-actions" style="display:none;gap:12px;">
                        <button class="btn btn-outline" id="playlist-page-edit-btn" onclick="togglePlaylistEditMode()"><?php echo htmlspecialchars(t('btn_edit')); ?></button>
                        <button class="btn btn-outline" onclick="openAddSongModal()"><?php echo htmlspecialchars(t('btn_add_song')); ?></button>
                        <button class="btn btn-danger" onclick="deletePlaylist(currentViewedPlaylist.id)"><?php echo htmlspecialchars(t('btn_delete_short')); ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <p id="playlist-page-reorder-hint" style="display:none;font-size:.8em;color:var(--text-muted);margin:0 0 10px;"></p>
    <div class="track-list" id="playlist-track-list"></div>
</main>

<main id="albums" style="display:none;">
    <div class="controls-container">
        <h2 class="section-title" style="margin-bottom:0;"><?php echo htmlspecialchars(t('albums_title')); ?></h2>
        <div class="search-row" style="width:auto;justify-content:flex-end;gap:12px;">
            <input type="text" id="albumSearchInput" placeholder="<?php echo htmlspecialchars(t('search_album_ph')); ?>" style="width:200px;margin:0;padding:10px 18px;border-radius:50px;" oninput="renderAlbumsGrid()">
            <select id="albumSortSelect" style="width:auto;margin:0;padding:10px 18px;border-radius:50px;" onchange="renderAlbumsGrid()">
                <option value="name_asc" selected><?php echo htmlspecialchars(t('grid_sort_name_asc')); ?></option>
                <option value="name_desc"><?php echo htmlspecialchars(t('grid_sort_name_desc')); ?></option>
                <option value="recent"><?php echo htmlspecialchars(t('grid_sort_recent')); ?></option>
            </select>
        </div>
    </div>
    <div class="cards-wrap" id="albums-grid"></div>
</main>

<main id="artists" style="display:none;">
    <div class="controls-container">
        <h2 class="section-title" style="margin-bottom:0;"><?php echo htmlspecialchars(t('artists_title')); ?></h2>
        <div class="search-row" style="width:auto;justify-content:flex-end;gap:12px;">
            <input type="text" id="artistSearchInput" placeholder="<?php echo htmlspecialchars(t('search_artist_ph')); ?>" style="width:200px;margin:0;padding:10px 18px;border-radius:50px;" oninput="renderArtistsGrid()">
            <select id="artistSortSelect" style="width:auto;margin:0;padding:10px 18px;border-radius:50px;" onchange="renderArtistsGrid()">
                <option value="name_asc" selected><?php echo htmlspecialchars(t('grid_sort_name_asc')); ?></option>
                <option value="name_desc"><?php echo htmlspecialchars(t('grid_sort_name_desc')); ?></option>
                <option value="recent"><?php echo htmlspecialchars(t('grid_sort_recent')); ?></option>
            </select>
        </div>
    </div>
    <div class="cards-wrap" id="artists-grid"></div>
</main>

<main id="artist-page" style="display:none;">
    <div class="artist-page-back" onclick="showSection('accueil')">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
        <span><?php echo htmlspecialchars(t('btn_back_library')); ?></span>
    </div>
    <div class="artist-hero">
        <div class="artist-hero-bg"><img id="artist-hero-bg-img" alt="" onerror="this.onerror=null;this.src='covers/default.png'"></div>
        <div class="artist-hero-content">
            <img id="artist-pfp" class="artist-pfp" alt="" onerror="this.onerror=null;this.src='covers/default.png'">
            <div class="artist-hero-info">
                <h2 class="section-title" id="artist-page-title"></h2>
                <p id="artist-page-count"></p>
                <div style="display:flex;gap:12px;margin-top:15px;">
                    <button class="btn btn-primary btn-labeled" onclick="playArtist(currentArtistName)">
                        <svg class="btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                        <span class="btn-label"><?php echo htmlspecialchars(t('btn_play_album')); ?></span>
                    </button>
                    <button class="btn btn-outline btn-labeled" onclick="playArtist(currentArtistName, true)">
                        <svg class="btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M10.59 9.17L5.41 4 4 5.41l5.17 5.17 1.42-1.41zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4h-5.5zm.33 9.41l-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04-3.13-3.13z"/></svg>
                        <span class="btn-label"><?php echo htmlspecialchars(t('btn_shuffle_play')); ?></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <p class="artist-bio" id="artist-page-bio"></p>
    <div class="track-list" id="artist-track-list"></div>
</main>

<main id="album-page" style="display:none;">
    <div class="artist-page-back" onclick="showSection('accueil')">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
        <span><?php echo htmlspecialchars(t('btn_back_library')); ?></span>
    </div>
    <div class="artist-hero">
        <div class="artist-hero-bg"><img id="album-hero-bg-img" alt="" onerror="this.onerror=null;this.src='covers/default.png'"></div>
        <div class="artist-hero-content">
            <img id="album-pfp" class="artist-pfp album-cover-pfp" alt="" onerror="this.onerror=null;this.src='covers/default.png'">
            <div class="artist-hero-info">
                <h2 class="section-title" id="album-page-title"></h2>
                <p id="album-page-count"></p>
                <p id="album-page-artists"></p>
                <div style="display:flex;gap:12px;margin-top:15px;">
                    <button class="btn btn-primary btn-labeled" onclick="playAlbum(currentAlbumId)">
                        <svg class="btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                        <span class="btn-label"><?php echo htmlspecialchars(t('btn_play_album')); ?></span>
                    </button>
                    <button class="btn btn-outline btn-labeled" onclick="playAlbum(currentAlbumId, true)">
                        <svg class="btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M10.59 9.17L5.41 4 4 5.41l5.17 5.17 1.42-1.41zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4h-5.5zm.33 9.41l-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04-3.13-3.13z"/></svg>
                        <span class="btn-label"><?php echo htmlspecialchars(t('btn_shuffle_play')); ?></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <div class="track-list" id="album-track-list"></div>
</main>

<main id="history" style="display:none;">
    <h2 class="section-title" style="margin-bottom:25px;"><?php echo htmlspecialchars(t('history_title')); ?></h2>
    <div class="track-list" id="history-track-list"></div>
</main>

<div id="mobile-bottom-nav">
    <button class="mob-nav-item active" id="mob-nav-accueil" onclick="showSection('accueil')">
        <svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg><?php echo htmlspecialchars(t('mobnav_library')); ?>
    </button>
    <button class="mob-nav-item" id="mob-nav-playlists" onclick="showSection('playlists')">
        <svg viewBox="0 0 24 24"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/></svg><?php echo htmlspecialchars(t('mobnav_mixes')); ?>
    </button>
    <?php if ($is_admin): ?>
        <button class="mob-nav-item" id="mob-nav-admin-page" onclick="showSection('admin-page')">
            <svg viewBox="0 0 24 24"><path d="M19.4 13c0-.3.1-.6.1-1s0-.7-.1-1l2.1-1.7c.2-.2.2-.4.1-.6l-2-3.5c-.1-.2-.3-.3-.6-.2l-2.5 1c-.5-.4-1.1-.7-1.7-1l-.4-2.7c0-.2-.2-.4-.5-.4h-4c-.3 0-.5.2-.5.4l-.4 2.7c-.6.2-1.2.6-1.7 1l-2.5-1c-.2-.1-.5 0-.6.2l-2 3.5c-.1.2-.1.5.1.6L4.6 11c-.1.3-.1.6-.1 1s0 .7.1 1l-2.1 1.7c-.2.2-.2.4-.1.6l2 3.5c.1.2.3.3.6.2l2.5-1c.5.4 1.1.7 1.7 1l.4 2.7c0 .2.2.4.5.4h4c.3 0 .5-.2.5-.4l.4-2.7c.6-.2 1.2-.6 1.7-1l2.5 1c.2.1.5 0 .6-.2l2-3.5c.1-.2.1-.5-.1-.6L19.4 13zM12 15.5c-1.9 0-3.5-1.6-3.5-3.5s1.6-3.5 3.5-3.5 3.5 1.6 3.5 3.5-1.6 3.5-3.5 3.5z"/></svg><?php echo htmlspecialchars(t('mobnav_admin')); ?>
        </button>
    <?php endif; ?>
    <button class="mob-nav-item" onclick="openModal('uploadModal')">
        <svg viewBox="0 0 24 24"><path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z"/></svg><?php echo htmlspecialchars(t('mobnav_upload')); ?>
    </button>
</div>

<!-- Modals -->
<main id="settings-page" style="display:none;">
    <h2 class="section-title" style="margin-bottom:25px;"><?php echo htmlspecialchars(t('settings_title')); ?></h2>
    <div class="settings-page-wrap">
        <p style="color:var(--text-muted);font-size:.9em;margin-bottom:10px;"><?php echo htmlspecialchars(t('settings_language_label')); ?></p>
        <div class="lang-switch" style="margin-bottom:20px;">
            <a href="?setlang=fr" class="<?php echo $LANG === 'fr' ? 'active' : ''; ?>">FR</a>
            <a href="?setlang=en" class="<?php echo $LANG === 'en' ? 'active' : ''; ?>">EN</a>
        </div>

        <p style="color:var(--text-muted);font-size:.9em;margin-bottom:10px;"><?php echo htmlspecialchars(t('settings_theme_label')); ?></p>
        <div class="theme-swatch-grid" id="theme-swatch-grid"></div>
        <div id="adaptive-fallback-section" style="display:none;">
            <p style="color:var(--text-muted);font-size:.9em;margin-bottom:10px;"><?php echo htmlspecialchars(t('settings_adaptive_fallback_label')); ?></p>
            <div class="theme-swatch-grid" id="adaptive-fallback-grid"></div>
        </div>

        <p style="color:var(--text-muted);font-size:.9em;margin-bottom:20px;"><?php echo htmlspecialchars(t('settings_hide_genres_pre')); ?> <strong style="color:var(--danger);"><?php echo htmlspecialchars(t('settings_hide_genres_word')); ?></strong> :</p>
        <div class="settings-grid">
            <?php foreach ($genresList as $g): ?>
                <label><input type="checkbox" class="genre-filter-cb" data-genre="<?php echo htmlspecialchars($g); ?>" onchange="toggleGenreSetting('<?php echo htmlspecialchars($g); ?>',this.checked)"> <?php echo htmlspecialchars(fixEntities($g)); ?></label>
            <?php endforeach; ?>
        </div>

        <?php if ($is_admin): ?>
        <label style="display:flex;align-items:center;gap:10px;margin-bottom:20px;cursor:pointer;">
            <input type="checkbox" id="hideAdminPanelToggle" style="width:auto;margin:0;" onchange="toggleHideAdminPanel(this.checked)">
            <?php echo htmlspecialchars(t('settings_hide_admin_label')); ?>
        </label>
        <?php endif; ?>

        <label style="display:flex;align-items:center;gap:10px;margin-bottom:20px;cursor:pointer;">
            <input type="checkbox" id="hideEditDeleteToggle" style="width:auto;margin:0;" onchange="toggleHideEditDeleteButtons(this.checked)">
            <?php echo htmlspecialchars(t('settings_hide_edit_delete_label')); ?>
        </label>

        <button class="btn btn-outline" style="width:100%;justify-content:center;margin-bottom:12px;" onclick="openModal('equalizerModal');renderEqSliders();"><?php echo htmlspecialchars(t('settings_open_eq')); ?></button>
        <a href="?logout=1" class="btn btn-outline" style="width:100%;justify-content:center;color:var(--text-muted);">
            <svg class="btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
            <?php echo htmlspecialchars(t('header_logout')); ?>
        </a>
    </div>
</main>

<div id="equalizerModal" class="modal"><div class="modal-content">
    <h2 style="margin-top:0;"><?php echo htmlspecialchars(t('eq_title')); ?></h2>
    <label style="display:flex;align-items:center;gap:10px;margin-bottom:18px;cursor:pointer;">
        <input type="checkbox" id="eqEnableToggle" style="width:auto;margin:0;" onchange="initAudioGraph();setEqEnabled(this.checked)"> <?php echo htmlspecialchars(t('eq_enable')); ?>
    </label>
    <div class="eq-preset-row">
        <button type="button" class="btn btn-outline" onclick="applyEqPreset('flat')"><?php echo htmlspecialchars(t('eq_preset_flat')); ?></button>
        <button type="button" class="btn btn-outline" onclick="applyEqPreset('bass')"><?php echo htmlspecialchars(t('eq_preset_bass')); ?></button>
        <button type="button" class="btn btn-outline" onclick="applyEqPreset('treble')"><?php echo htmlspecialchars(t('eq_preset_treble')); ?></button>
        <button type="button" class="btn btn-outline" onclick="applyEqPreset('vocal')"><?php echo htmlspecialchars(t('eq_preset_vocal')); ?></button>
        <button type="button" class="btn btn-outline" onclick="applyEqPreset('rock')"><?php echo htmlspecialchars(t('eq_preset_rock')); ?></button>
        <button type="button" class="btn btn-outline" onclick="applyEqPreset('pop')"><?php echo htmlspecialchars(t('eq_preset_pop')); ?></button>
    </div>
    <?php foreach ([t('eq_band_bassboost'),'60 Hz','230 Hz','910 Hz','3.6 kHz','14 kHz'] as $i => $lbl): ?>
    <div class="eq-row">
        <span class="eq-label"><?php echo htmlspecialchars($lbl); ?></span>
        <input type="range" min="-12" max="12" step="1" value="0" id="eq-slider-<?php echo $i; ?>" class="vol-slider"
               oninput="initAudioGraph();setEqBand(<?php echo $i; ?>,parseFloat(this.value));document.getElementById('eq-val-<?php echo $i; ?>').innerText=this.value+' dB'">
        <span class="eq-val" id="eq-val-<?php echo $i; ?>">0 dB</span>
    </div>
    <?php endforeach; ?>
    <button class="btn btn-primary" style="width:100%;justify-content:center;margin-top:10px;" onclick="closeModal('equalizerModal')"><?php echo htmlspecialchars(t('btn_close')); ?></button>
</div></div>

<div id="uploadModal" class="modal"><div class="modal-content">
    <h2 style="margin-top:0;"><?php echo htmlspecialchars(t('header_upload')); ?></h2>
    <form id="upload-form">
        <input type="text" id="upload-title" placeholder="<?php echo htmlspecialchars(t('upload_title_ph')); ?>">
        <input type="text" id="upload-artist" placeholder="<?php echo htmlspecialchars(t('upload_artist_ph')); ?>">
        <input type="text" id="upload-album" placeholder="<?php echo htmlspecialchars(t('upload_album_ph')); ?>">
        <label style="font-size:.85em;color:var(--text-muted);display:block;margin-bottom:5px;"><?php echo htmlspecialchars(t('label_genre')); ?></label>
        <select id="upload-genre">
            <?php foreach ($genresList as $g): ?>
                <option value="<?php echo htmlspecialchars($g); ?>"><?php echo htmlspecialchars(fixEntities($g)); ?></option>
            <?php endforeach; ?>
        </select>
        <label style="font-size:.85em;color:var(--text-muted);display:block;margin-bottom:5px;"><?php echo htmlspecialchars(t('label_audio_file')); ?></label>
        <input type="file" id="upload-music" accept="audio/*" required>
        <label style="font-size:.85em;color:var(--text-muted);display:block;margin-bottom:5px;"><?php echo htmlspecialchars(t('label_cover_optional')); ?></label>
        <input type="file" id="upload-cover" accept="image/*">
        <div style="display:flex;gap:15px;margin-top:20px;">
            <button type="button" class="btn" style="flex:1;justify-content:center;color:var(--text-muted);border:1px solid var(--border-color);" onclick="closeModal('uploadModal')"><?php echo htmlspecialchars(t('btn_cancel')); ?></button>
            <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;"><?php echo htmlspecialchars(t('btn_publish')); ?></button>
        </div>
    </form>
</div></div>

<div id="editTrackModal" class="modal"><div class="modal-content">
    <h2 style="margin-top:0;"><?php echo htmlspecialchars(t('edit_track_title_h2')); ?></h2>
    <form id="edit-track-form">
        <input type="hidden" id="edit-track-id">
        <input type="text" id="edit-track-title" placeholder="<?php echo htmlspecialchars(t('label_title_ph')); ?>" required>
        <input type="text" id="edit-track-artist" placeholder="<?php echo htmlspecialchars(t('label_artist_ph')); ?>">
        <input type="text" id="edit-track-album" placeholder="<?php echo htmlspecialchars(t('edit_album_ph')); ?>">
        <label style="font-size:.85em;color:var(--text-muted);display:block;margin-bottom:5px;"><?php echo htmlspecialchars(t('label_genre')); ?></label>
        <select id="edit-track-genre">
            <?php foreach ($genresList as $g): ?>
                <option value="<?php echo htmlspecialchars($g); ?>"><?php echo htmlspecialchars(fixEntities($g)); ?></option>
            <?php endforeach; ?>
        </select>
        <label style="font-size:.85em;color:var(--text-muted);display:block;margin-bottom:5px;"><?php echo htmlspecialchars(t('label_new_cover')); ?></label>
        <input type="file" id="edit-track-cover" accept="image/*">
        <div style="display:flex;gap:15px;margin-top:20px;">
            <button type="button" class="btn" style="flex:1;justify-content:center;color:var(--text-muted);border:1px solid var(--border-color);" onclick="closeModal('editTrackModal')"><?php echo htmlspecialchars(t('btn_cancel')); ?></button>
            <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;"><?php echo htmlspecialchars(t('btn_save')); ?></button>
        </div>
    </form>
</div></div>

<!-- "⋮" context menu shared by all track rows (library,
     artist, album, history, playlist): a single element repositioned and
     re-rendered for the clicked track, rather than one menu per row. -->
<div id="track-ctx-menu" class="track-ctx-menu" role="menu"></div>

<div id="playlistModal" class="modal"><div class="modal-content">
    <h2 id="modal-playlist-title" style="margin-top:0;"><?php echo htmlspecialchars(t('playlist_default_title')); ?></h2>
    <form id="playlist-form">
        <div id="playlist-form-name-group">
            <input type="text" id="form-playlist-name" placeholder="<?php echo htmlspecialchars(t('mix_name_ph')); ?>" required>
            <label style="display:flex;align-items:center;gap:8px;margin:10px 0;cursor:pointer;">
                <input type="checkbox" id="form-playlist-public" checked style="width:auto;">
                <span style="font-size:.9em;"><?php echo htmlspecialchars(t('playlist_public_label')); ?></span>
            </label>
        </div>
        <input type="text" id="playlist-search" placeholder="<?php echo htmlspecialchars(t('search_dots_ph')); ?>" onkeyup="filterPlaylistTracks()" style="margin-bottom:10px;">
        <div style="display:flex;justify-content:space-between;font-size:.85em;color:var(--text-muted);margin-bottom:10px;">
            <span><?php echo htmlspecialchars(t('select_tracks_label')); ?></span><span id="selected-count">0<?php echo htmlspecialchars(t('selected_suffix')); ?></span>
        </div>
        <div class="song-select-container">
            <?php foreach ($all_tracks as $t): ?>
                <div class="song-select-item" onclick="toggleSelection(this)" data-title="<?php echo strtolower(htmlspecialchars(fixEntities($t['title']))); ?>">
                    <input type="checkbox" class="song-cb" data-id="<?php echo $t['id']; ?>">
                    <img src="<?php echo htmlspecialchars($t['cover_url']); ?>" loading="lazy" style="width:40px;height:40px;border-radius:8px;margin-right:12px;object-fit:cover;" onerror="this.onerror=null;this.src='covers/default.png'">
                    <div style="flex:1;overflow:hidden;">
                        <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars(fixEntities($t['title'])); ?></div>
                        <div style="font-size:.85em;color:var(--text-muted);"><?php echo htmlspecialchars(fixEntities($t['artist'])); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:15px;margin-top:20px;">
            <button type="button" class="btn" style="flex:1;justify-content:center;color:var(--text-muted);border:1px solid var(--border-color);" onclick="closeModal('playlistModal')"><?php echo htmlspecialchars(t('btn_cancel')); ?></button>
            <button type="submit" class="btn btn-primary" id="playlist-form-submit-btn" style="flex:1;justify-content:center;"><?php echo htmlspecialchars(t('btn_save')); ?></button>
        </div>
    </form>
</div></div>

<!-- Player -->
<div id="player-bar" onclick="handlePlayerBarClick(event)">
    <div class="progress-bg pb-seek" id="progress-area"><div class="progress-fill" id="progress-bar"></div></div>
    <div class="pb-transport">
        <button class="control-btn" onclick="prevTrack()"><svg viewBox="0 0 24 24"><path d="M6 6h2v12H6zm3.5 6l8.5 6V6z"/></svg></button>
        <button class="control-btn fp-bb-play" id="masterPlay" onclick="togglePlay()"><svg viewBox="0 0 24 24" style="margin-left:2px;"><path d="M8 5v14l11-7z"/></svg></button>
        <button class="control-btn" onclick="nextTrack()"><svg viewBox="0 0 24 24"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/></svg></button>
        <div class="pb-time"><span id="curr-time">0:00</span><span class="pb-time-sep">/</span><span id="total-time">0:00</span></div>
    </div>
    <div class="player-info">
        <img src="covers/<?php echo htmlspecialchars($default_cover); ?>" id="player-cover" loading="lazy">
        <div style="overflow:hidden;flex:1;">
            <div id="play-title" style="font-weight:700;font-size:.95em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars(t('ready_to_play')); ?></div>
            <div id="play-status" style="font-size:.75em;color:var(--accent);margin-top:2px;"><?php echo htmlspecialchars(t('stopped')); ?></div>
        </div>
    </div>
    <div class="pb-right">
        <div class="volume-container">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="var(--text-muted)"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
            <input type="range" id="desktop-vol" class="vol-slider" min="0" max="1" step="0.01" value="1">
        </div>
        <button class="control-btn" id="loopBtn" onclick="toggleLoop()" title="<?php echo htmlspecialchars(t('normal_playback')); ?>"><svg viewBox="0 0 24 24"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg><span class="loop-badge">1</span></button>
        <button class="control-btn" id="shuffleBtn" onclick="toggleShuffle()"><svg viewBox="0 0 24 24"><path d="M10.59 9.17L5.41 4 4 5.41l5.17 5.17 1.42-1.41zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4h-5.5zm.33 9.41l-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04-3.13-3.13z"/></svg></button>
    </div>
</div>

<div id="full-player">
    <div id="fp-bg"><img id="fp-bg-img" alt=""></div>
    <div class="fp-mobile-tabs-toggle">
        <button class="fp-btn" id="lyricsBtn" onclick="toggleFpTab('lyrics')" title="<?php echo htmlspecialchars(t('title_lyrics')); ?>"><svg viewBox="0 0 24 24" style="width:20px;height:20px;fill:var(--player-text);"><path d="M20 2H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h4l4 4 4-4h4c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 9h9v2H6V9zm6 5H6v-2h6v2zm5-6H6V6h11v2z"/></svg></button>
        <button class="fp-btn" id="fpQueueBtn" onclick="toggleFpTab('queue')" title="<?php echo htmlspecialchars(t('title_queue')); ?>"><svg viewBox="0 0 24 24" style="width:20px;height:20px;fill:var(--player-text);"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg></button>
    </div>
    <div class="fp-body">
        <div class="fp-main">
            <div class="fp-art-container"><img src="covers/<?php echo htmlspecialchars($default_cover); ?>" id="fp-cover" loading="lazy"></div>
        </div>
        <div id="fp-sidebar">
            <div class="fp-sidebar-tabs">
                <button class="fp-tab-btn active" id="fpTabQueueBtn" onclick="openFpTab('queue')"><?php echo htmlspecialchars(t('title_queue')); ?></button>
                <button class="fp-tab-btn" id="fpTabLyricsBtn" onclick="openFpTab('lyrics')"><?php echo htmlspecialchars(t('title_lyrics')); ?></button>
                <button class="fp-sidebar-close" onclick="toggleFpTab(currentFpTab)" title="<?php echo htmlspecialchars(t('btn_close')); ?>"><svg viewBox="0 0 24 24" width="18" height="18" fill="var(--player-text)"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></button>
            </div>
            <div class="fp-sidebar-content">
                <div id="fp-queue-tab" class="fp-tab-pane active">
                    <div id="fp-queue-list"><p style="color:var(--text-muted);"><?php echo htmlspecialchars(t('queue_empty')); ?></p></div>
                </div>
                <div id="fp-lyrics-tab" class="fp-tab-pane">
                    <div id="lyrics-content"><p class="lyrics-status"><?php echo htmlspecialchars(t('no_track_playing')); ?></p></div>
                </div>
            </div>
        </div>
    </div>
    <div class="fp-bottombar" onclick="handlePlayerBarClick(event)">
        <div class="progress-bg pb-seek" id="fp-progress-area"><div class="progress-fill" id="fp-progress-bar"></div></div>
        <div class="pb-transport">
            <button class="control-btn" onclick="prevTrack()"><svg viewBox="0 0 24 24"><path d="M6 6h2v12H6zm3.5 6l8.5 6V6z"/></svg></button>
            <button class="control-btn fp-bb-play" id="fp-masterPlay" onclick="togglePlay()"><svg viewBox="0 0 24 24" style="margin-left:2px;"><path d="M8 5v14l11-7z"/></svg></button>
            <button class="control-btn" onclick="nextTrack()"><svg viewBox="0 0 24 24"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/></svg></button>
            <div class="pb-time"><span id="fp-curr-time">0:00</span><span class="pb-time-sep">/</span><span id="fp-total-time">0:00</span></div>
        </div>
        <div class="fp-bb-track">
            <img id="fp-bb-cover" src="covers/<?php echo htmlspecialchars($default_cover); ?>" loading="lazy">
            <div class="fp-bb-info">
                <div id="fp-title"><span id="fp-title-text"><?php echo htmlspecialchars(t('label_title_ph')); ?></span></div>
                <div id="fp-artist"><?php echo htmlspecialchars(t('label_artist_ph')); ?></div>
            </div>
        </div>
        <div class="pb-right">
            <div class="volume-container">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="var(--player-text)"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
                <input type="range" id="mobile-vol" class="vol-slider" min="0" max="1" step="0.01" value="1">
            </div>
            <button class="control-btn" id="fp-loopBtn" onclick="toggleLoop()" title="<?php echo htmlspecialchars(t('normal_playback')); ?>"><svg viewBox="0 0 24 24"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg><span class="loop-badge">1</span></button>
            <button class="control-btn" id="fp-shuffleBtn" onclick="toggleShuffle()"><svg viewBox="0 0 24 24"><path d="M10.59 9.17L5.41 4 4 5.41l5.17 5.17 1.42-1.41zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4h-5.5zm.33 9.41l-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04-3.13-3.13z"/></svg></button>
        </div>
    </div>
</div>

<audio id="mainAudio"></audio>

<script>
    const LANG = <?php echo json_encode($LANG); ?>;
    const I18N_STRINGS = {
        fr: {
            err_api_unreachable: 'Impossible de contacter api.php.',
            no_tracks_found: 'Aucune piste trouvée.',
            unknown_artist: 'Artiste inconnu',
            recommended_for_you: 'Recommandé pour vous',
            popular: 'Populaire',
            hidden_gems: 'Pépites cachées',
            prev: 'Précédent',
            next: 'Suivant',
            all: 'Tous',
            confirm_delete_track: 'Supprimer cette piste ?',
            confirm_delete_playlist: 'Supprimer cette playlist ?',
            err_delete: 'Erreur lors de la suppression.',
            wait_before_upload: 'Patiente quelques secondes avant un nouvel upload.',
            choose_audio_file: 'Choisis un fichier audio.',
            uploading_ellipsis: 'Envoi…',
            err_upload: "Erreur lors de l'upload.",
            err_edit: 'Erreur lors de la modification.',
            err_generic: 'Erreur.',
            selected_suffix: ' sélectionné(s)',
            word_and: 'et',
            new_playlist: 'Nouvelle Playlist',
            no_music_available: 'Aucune musique disponible.',
            loading_lyrics: 'Chargement des paroles…',
            no_lyrics: 'Aucune parole disponible.',
            err_lyrics: 'Erreur lors du chargement des paroles.',
            repeat_track: 'Répéter le titre',
            repeat_queue: 'Répéter la file',
            normal_playback: 'Lecture normale',
            theme_site_default: 'Site (Défaut)',
            theme_adaptive: 'Adaptatif',
            tracks_count_label: 'titre(s)',
            loading_bio: 'Chargement de la biographie…',
            no_bio_available: 'Aucune description disponible.',
            wikipedia_link: 'Voir sur Wikipédia ↗',
            created_by: 'Créé par',
            playlist_private_badge: 'Privée',
            drag_reorder_hint: 'Glisser pour réordonner',
            move_up: 'Déplacer vers le haut',
            move_down: 'Déplacer vers le bas',
            remove_from_playlist: 'Retirer de la playlist',
            btn_edit: 'Éditer',
            btn_done: 'Terminé',
            btn_add_song: 'Ajouter',
            add_song_modal_title: 'Ajouter des titres',
            section_all_tracks: 'Toutes les pistes',
            songs_title: 'Chansons',
            no_albums_found: 'Aucun album pour le moment',
            no_artists_found: 'Aucun artiste pour le moment',
            history_empty: "Vous n'avez encore rien écouté.",
            menu_add_to_queue: "Ajouter à la file d'attente",
            menu_play_next: 'Lire ensuite',
            menu_add_to_playlist: 'Ajouter à une playlist',
            menu_back: 'Retour',
            no_playlists_yet: 'Aucune playlist pour le moment',
            track_menu_title: 'Options de la piste',
        },
        en: {
            err_api_unreachable: 'Unable to reach api.php.',
            no_tracks_found: 'No tracks found.',
            unknown_artist: 'Unknown artist',
            recommended_for_you: 'Recommended for you',
            popular: 'Popular',
            hidden_gems: 'Hidden gems',
            prev: 'Previous',
            next: 'Next',
            all: 'All',
            confirm_delete_track: 'Delete this track?',
            confirm_delete_playlist: 'Delete this playlist?',
            err_delete: 'Error while deleting.',
            wait_before_upload: 'Please wait a few seconds before uploading again.',
            choose_audio_file: 'Choose an audio file.',
            uploading_ellipsis: 'Uploading…',
            err_upload: 'Error during upload.',
            err_edit: 'Error while editing.',
            err_generic: 'Error.',
            selected_suffix: ' selected',
            word_and: 'and',
            new_playlist: 'New playlist',
            no_music_available: 'No music available.',
            loading_lyrics: 'Loading lyrics…',
            no_lyrics: 'No lyrics available.',
            err_lyrics: 'Error loading lyrics.',
            repeat_track: 'Repeat track',
            repeat_queue: 'Repeat queue',
            normal_playback: 'Normal playback',
            theme_site_default: 'Site (Default)',
            theme_adaptive: 'Adaptive',
            tracks_count_label: 'track(s)',
            loading_bio: 'Loading biography…',
            no_bio_available: 'No description available.',
            wikipedia_link: 'View on Wikipedia ↗',
            created_by: 'Created by',
            playlist_private_badge: 'Private',
            drag_reorder_hint: 'Drag to reorder',
            move_up: 'Move up',
            move_down: 'Move down',
            remove_from_playlist: 'Remove from playlist',
            btn_edit: 'Edit',
            btn_done: 'Done',
            btn_add_song: 'Add songs',
            add_song_modal_title: 'Add songs',
            section_all_tracks: 'All tracks',
            songs_title: 'Songs',
            no_albums_found: 'No albums yet',
            no_artists_found: 'No artists yet',
            history_empty: "You haven't listened to anything yet.",
            menu_add_to_queue: 'Add to queue',
            menu_play_next: 'Play next',
            menu_add_to_playlist: 'Add to playlist',
            menu_back: 'Back',
            no_playlists_yet: 'No playlists yet',
            track_menu_title: 'Track options',
        },
    };
    function t(key) { return (I18N_STRINGS[LANG] && I18N_STRINGS[LANG][key]) || I18N_STRINGS.fr[key] || key; }

    const ALL_MUSIC_DATA   = <?php echo json_encode($all_tracks, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const ALL_PLAYLISTS    = <?php echo json_encode($all_playlists, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const CURRENT_USER_ID  = <?php echo json_encode($user_id); ?>;
    const IS_ADMIN         = <?php echo json_encode($is_admin); ?>;
    // Site default colors (admin panel), only used to
    // show an accurate preview of the "Site (Default)" swatch in the theme picker.
    const SERVER_PRIMARY   = <?php echo json_encode($color_primary); ?>;
    const SERVER_ACCENT    = <?php echo json_encode($color_accent); ?>;
    // Credentials re-injected on the client to talk directly to api.php,
    // exactly like the Android client does (PurpleClient.postRequest):
    // api.php has no session, every authenticated call must provide them.
    const API_AUTH = {
        username: <?php echo json_encode($username); ?>,
        password: <?php echo json_encode($api_pw); ?>
    };

    async function apiCall(action, params = {}) {
        const body = new URLSearchParams({ ...params, username: API_AUTH.username, password: API_AUTH.password });
        try {
            const res = await fetch('api.php?action=' + encodeURIComponent(action), { method: 'POST', body });
            return await res.json();
        } catch (e) {
            return { status: 'error', message: t('err_api_unreachable') };
        }
    }
    async function apiCallForm(action, formData) {
        formData.append('username', API_AUTH.username);
        formData.append('password', API_AUTH.password);
        try {
            const res = await fetch('api.php?action=' + encodeURIComponent(action), { method: 'POST', body: formData });
            return await res.json();
        } catch (e) {
            return { status: 'error', message: t('err_api_unreachable') };
        }
    }

    const audio        = document.getElementById('mainAudio');
    const progressBar  = document.getElementById('progress-bar');
    const progressArea = document.getElementById('progress-area');
    const masterPlay   = document.getElementById('masterPlay');
    const playTitle    = document.getElementById('play-title');
    const playCover    = document.getElementById('player-cover');
    const playStatus   = document.getElementById('play-status');

    let CURRENT_VIEW_DATA = []; let renderedCount = 0; const RENDER_CHUNK = 30;
    let originalQueue = []; let queue = []; let currentIndex = 0; let loopMode = 0; let isShuffle = false;
    let currentPlaylistId = null; let currentSection = 'accueil'; let currentArtistName = null; let currentAlbumId = null; let currentViewedPlaylist = null;
    let currentHistoryTracks = [];

    // ── Contextual queue engine ──────────────────────────────────────────
    // A dynamic/continuation queue must never feel short: we
    // always aim for at least ~150 upcoming tracks (like a streaming
    // service's "Up Next"), filled as we get close to the end rather than
    // generating everything at once. On a small library, buildContextualBatch
    // simply returns fewer than requested (it can't invent
    // tracks) — usedTrackIds then prevents any repeat within a batch.
    const QUEUE_AHEAD_TARGET = 150;
    const QUEUE_REFILL_THRESHOLD = 30;
    // queueMode: 'library' (fixed library order/sort, never any
    // recommendation) | 'fixed' (album/playlist/history: explicit list,
    // optionally followed by a dynamic continuation for albums) |
    // 'dynamic' (search/carousel/artist: queue generated and continuously topped up).
    let queueMode = 'library';
    // Loop boundary for repeat-queue mode: the loop must only wrap
    // over the "context" part (e.g. the album's tracks), never over the
    // generated continuation appended after it. null = loop over the whole queue.
    let loopBoundary = null;
    // A playlist/history is also in queueMode 'fixed' but must
    // NEVER receive a generated continuation (unlike an album) —
    // this boolean is what tells the two apart for refillQueueIfNeeded(),
    // since queueMode alone is not enough to make the distinction.
    let queueAllowsContinuation = false;
    // Ids already placed in the current dynamic queue, so a track
    // already suggested isn't picked again during a later incremental refill.
    let usedTrackIds = new Set();
    // In-memory (per session) cache of the user's affinity profile
    // (genre/artist/album), loaded only once from action=user_affinity.
    let userAffinityCache = null;
    let userAffinityPromise = null;

    async function getUserAffinity() {
        if (userAffinityCache) return userAffinityCache;
        if (!userAffinityPromise) {
            userAffinityPromise = apiCall('user_affinity').then(data => {
                userAffinityCache = {
                    genre: (data && data.genre) || {},
                    artist: (data && data.artist) || {},
                    album: (data && data.album) || {},
                };
                return userAffinityCache;
            }).catch(() => {
                userAffinityCache = { genre: {}, artist: {}, album: {} };
                return userAffinityCache;
            });
        }
        return userAffinityPromise;
    }

    // Weighted random sampling without replacement (exponential keys method):
    // each item gets a key -ln(rand())/weight, we keep the n
    // smallest keys. A higher weight increases the chance of being picked
    // without ever guaranteeing it — replaces a classic score sort.
    function weightedSampleWithoutReplacement(items, weightFn, n) {
        const keyed = items.map(item => {
            const w = Math.max(weightFn(item), 0.0001);
            const u = Math.max(Math.random(), 1e-9);
            return { item, key: -Math.log(u) / w };
        });
        keyed.sort((a, b) => a.key - b.key);
        return keyed.slice(0, n).map(k => k.item);
    }

    function scoreCandidate(track, seedTrack, affinity) {
        let score = 1; // base so that even a track with no signal at all can still be picked
        if (seedTrack) {
            if (track.artist === seedTrack.artist) score += 30;
            if ((track.genre || 'Autre') === (seedTrack.genre || 'Autre')) score += 12;
            if (track.album_id != null && track.album_id === seedTrack.album_id) score += 8;
        }
        if (affinity) {
            score += (affinity.genre[track.genre] || 0) * 3;
            score += (affinity.artist[track.artist] || 0) * 5;
            score += (affinity.album[track.album_id] || 0) * 4;
        }
        score += Math.log(1 + (parseInt(track.play_count) || 0)) * 1.2;
        score += Math.random() * 6; // random exploration
        return score;
    }

    // Merges the slices per bucket (same artist / related genre / discovery /
    // pure random) while avoiding placing the same artist twice in a row,
    // and avoiding, if possible, the artist used in the last 2-3 picks.
    function interleaveAvoidingRepeats(buckets, seedArtist) {
        const pools = buckets.map(b => [...b]);
        const result = [];
        const recentArtists = seedArtist ? [seedArtist] : [];
        let remaining = pools.reduce((n, p) => n + p.length, 0);
        while (remaining > 0) {
            let pickedPoolIdx = -1, pickedItemIdx = -1;
            // 1st pass: look for a track by an artist absent from the last 3 picks
            for (let p = 0; p < pools.length && pickedPoolIdx === -1; p++) {
                for (let i = 0; i < pools[p].length; i++) {
                    if (!recentArtists.slice(-3).includes(pools[p][i].artist)) { pickedPoolIdx = p; pickedItemIdx = i; break; }
                }
            }
            // 2nd pass (fallback): just avoid the immediately preceding artist
            if (pickedPoolIdx === -1) {
                for (let p = 0; p < pools.length && pickedPoolIdx === -1; p++) {
                    for (let i = 0; i < pools[p].length; i++) {
                        if (pools[p][i].artist !== recentArtists[recentArtists.length - 1]) { pickedPoolIdx = p; pickedItemIdx = i; break; }
                    }
                }
            }
            // 3rd pass: no alternative left, take whatever remains
            if (pickedPoolIdx === -1) {
                for (let p = 0; p < pools.length; p++) { if (pools[p].length) { pickedPoolIdx = p; pickedItemIdx = 0; break; } }
            }
            const [track] = pools[pickedPoolIdx].splice(pickedItemIdx, 1);
            result.push(track);
            recentArtists.push(track.artist);
            remaining--;
        }
        return result;
    }

    // Builds a batch of "relevant but surprising" tracks from
    // ALL_MUSIC_DATA, mixing several candidate sources around
    // seedTrack: ~30% same artist, ~30% related genre/taste, ~25% discovery
    // (personal affinity + popularity, excluding same artist/genre), ~15%
    // completely random. The draw is weighted (not a deterministic sort),
    // so two calls with the same seed never give exactly the same queue.
    async function buildContextualBatch(seedTrack, excludeIds, count) {
        const affinity = await getUserAffinity();
        const pool = ALL_MUSIC_DATA.filter(t => !excludeIds.has(t.id) &&
            (!hiddenGenres.length || !hiddenGenres.includes(t.genre || 'Autre')));
        if (!pool.length) return [];

        const sameArtist = seedTrack ? pool.filter(t => t.artist === seedTrack.artist) : [];
        const sameArtistSet = new Set(sameArtist.map(t => t.id));
        const related = seedTrack ? pool.filter(t => !sameArtistSet.has(t.id) && (t.genre || 'Autre') === (seedTrack.genre || 'Autre')) : [];
        const relatedSet = new Set(related.map(t => t.id));
        const discoveryBase = pool.filter(t => !sameArtistSet.has(t.id) && !relatedSet.has(t.id));
        const rest = discoveryBase;

        const quotas = {
            sameArtist: Math.round(count * 0.30),
            related:    Math.round(count * 0.30),
            discovery:  Math.round(count * 0.25),
            random:     Math.round(count * 0.15),
        };

        const pickSameArtist = weightedSampleWithoutReplacement(sameArtist, t => scoreCandidate(t, seedTrack, affinity), Math.min(quotas.sameArtist, sameArtist.length));
        const pickRelated    = weightedSampleWithoutReplacement(related, t => scoreCandidate(t, seedTrack, affinity), Math.min(quotas.related, related.length));
        const usedSoFar = new Set([...pickSameArtist, ...pickRelated].map(t => t.id));
        const discoveryPool = discoveryBase.filter(t => !usedSoFar.has(t.id));
        const pickDiscovery  = weightedSampleWithoutReplacement(discoveryPool, t => scoreCandidate(t, seedTrack, affinity), Math.min(quotas.discovery, discoveryPool.length));
        pickDiscovery.forEach(t => usedSoFar.add(t.id));
        const randomPool = rest.filter(t => !usedSoFar.has(t.id));
        const pickRandom = weightedSampleWithoutReplacement(randomPool, () => 1, Math.min(quotas.random, randomPool.length));

        // If a bucket is understaffed (small library, artist with few
        // tracks...), we fill in with what's left elsewhere rather than
        // returning a shorter batch than requested.
        let chosen = [...pickSameArtist, ...pickRelated, ...pickDiscovery, ...pickRandom];
        if (chosen.length < count) {
            const chosenIds = new Set(chosen.map(t => t.id));
            const filler = pool.filter(t => !chosenIds.has(t.id));
            const need = count - chosen.length;
            chosen = chosen.concat(weightedSampleWithoutReplacement(filler, t => scoreCandidate(t, seedTrack, affinity), Math.min(need, filler.length)));
        }

        return interleaveAvoidingRepeats([chosen], seedTrack ? seedTrack.artist : null);
    }

    // Reorders an existing queue (album/playlist/library)
    // "shuffle"-style while avoiding consecutive artist repeats:
    // classic Fisher-Yates followed by a repair pass using local swaps.
    function weightedAntiRepeatShuffle(list) {
        const arr = shuffleArray([...list]);
        for (let i = 1; i < arr.length; i++) {
            if (arr[i].artist !== arr[i - 1].artist) continue;
            for (let j = i + 1; j < arr.length; j++) {
                if (arr[j].artist !== arr[i - 1].artist && arr[j].artist !== arr[i].artist) {
                    [arr[i], arr[j]] = [arr[j], arr[i]];
                    break;
                }
            }
        }
        return arr;
    }

    // A single lock shared between the incremental refill and the
    // initial build of a dynamic/fixed+continuation queue
    // (buildDynamicQueue / buildFixedQueueWithContinuation): without it,
    // loadTrack() triggers refillQueueIfNeeded() as soon as the queue only
    // contains the seed track (so just under the threshold), at the same time
    // as the initial build call also fetches a batch — both then
    // start from the same not-yet-updated usedTrackIds and may
    // pick the same tracks, hence duplicates in the queue.
    let queueBatchInFlight = false;
    async function refillQueueIfNeeded() {
        if (!queueAllowsContinuation || queueBatchInFlight) return;
        const remaining = queue.length - 1 - currentIndex;
        if (remaining > QUEUE_REFILL_THRESHOLD) return;
        if (loopMode === 1 && loopBoundary != null) return; // we loop over the context, no need to top up
        queueBatchInFlight = true;
        try {
            const lastTrack = queue[queue.length - 1];
            // Tops up until there are ~QUEUE_AHEAD_TARGET upcoming tracks again,
            // not just a small fixed batch, so the queue never feels
            // about to stop.
            const need = Math.max(QUEUE_AHEAD_TARGET - remaining, QUEUE_REFILL_THRESHOLD);
            const batch = await buildContextualBatch(lastTrack, usedTrackIds, need);
            if (!batch.length) return;
            batch.forEach(t => usedTrackIds.add(t.id));
            originalQueue = originalQueue.concat(batch);
            queue = queue.concat(isShuffle ? weightedAntiRepeatShuffle(batch) : batch);
            updateQueueUI();
        } finally {
            queueBatchInFlight = false;
        }
    }
    let hiddenGenres = JSON.parse(localStorage.getItem('hiddenGenres') || '[]');
    // An admin's personal preference to declutter THEIR own navigation —
    // changes nothing on the server (is_admin remains the only authority
    // for admin actions), just the display of the entry in THEIR menu.
    let hideAdminPanel = (localStorage.getItem('hideAdminPanel') === '1');
    // General display preference (not admin-only, unlike
    // hideAdminPanel): hides the ✎/✕ buttons on ALL tracks,
    // including the user's own — for a clean
    // read-only view. Only affects rendering; edit_track/delete_track on the
    // server keep checking ownership/is_admin independently.
    let hideEditDeleteButtons = (localStorage.getItem('hideEditDeleteButtons') === '1');
    let adaptiveThemeEnabled = (localStorage.getItem('theme_base') === 'adaptive');
    // True until a song actually starts playing in this session. While idle, the
    // adaptive theme shows the user's default adaptive theme instead of the cover
    // colors — even if a track was restored paused from the URL (?v=).
    let adaptiveIdle = true;
    const adaptiveColorCache = new Map();

    const playIcon  = '<svg viewBox="0 0 24 24" style="margin-left:2px;"><path d="M8 5v14l11-7z"/></svg>';
    const pauseIcon = '<svg viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';

    // Single source of truth for the play/pause icon: triggered by the
    // native 'play'/'pause' events of <audio> rather than being set
    // by hand everywhere play()/pause() is called. This also covers cases
    // where the OS or a keyboard media key pauses outside our
    // own buttons — otherwise the icon gets out of sync with the real state.
    function updatePlayPauseIcons() {
        const icon = audio.paused ? playIcon : pauseIcon;
        masterPlay.innerHTML = icon;
        document.getElementById('fp-masterPlay').innerHTML = icon;
    }
    audio.addEventListener('play', updatePlayPauseIcons);
    audio.addEventListener('play', () => {
        if (!adaptiveIdle) return;
        adaptiveIdle = false;
        updateAdaptiveTheme(queue[currentIndex]);
    });
    audio.addEventListener('pause', updatePlayPauseIcons);

    const desktopVol = document.getElementById('desktop-vol');
    const mobileVol  = document.getElementById('mobile-vol');

    function toggleAccordion(header) {
        const item = header.parentElement;
        const content = item.querySelector('.adm-accordion-content');
        const isOpen = item.classList.contains('open');
        document.querySelectorAll('.adm-accordion-item').forEach(el => {
            el.classList.remove('open');
            el.querySelector('.adm-accordion-content').style.display = 'none';
        });
        if (!isOpen) { item.classList.add('open'); content.style.display = 'block'; }
    }

    function updateVolume(val) {
        audio.volume = val; desktopVol.value = val; mobileVol.value = val;
        localStorage.setItem('purpleMusicVolume', val);
        const p = val * 100;
        const bg = `linear-gradient(90deg,var(--accent) ${p}%,rgba(255,255,255,.2) ${p}%)`;
        desktopVol.style.background = bg; mobileVol.style.background = bg;
    }
    desktopVol.addEventListener('input', e => updateVolume(e.target.value));
    mobileVol.addEventListener('input',  e => updateVolume(e.target.value));

    function escapeHTML(str) {
        if (str == null) return '';
        return str.toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }

    // Metadata (title, artist, playlist...) is stored already
    // HTML-escaped by api.php (sanitize_text), so a "&" or an apostrophe
    // typed at upload ends up as a literal "&amp;"/"&#039;" in the database. Without
    // this fix, escapeHTML() would escape them a second time and the
    // browser would show the raw text "&amp;"/"&#039;" instead of the
    // intended character. For regular display (titles, lists...), "&amp;"
    // becomes the joining word ("and"/"et"/...) translated via t('word_and') —
    // pulling from the i18n dictionary rather than a hard-coded fr/en ternary
    // lets a language added later be covered automatically;
    // for lyrics, we restore the literal "&" symbol instead so we don't
    // alter the song's text.
    function fixEntities(str) {
        if (str == null) return str;
        return str.toString().split('&#039;').join("'").split('&amp;').join(t('word_and'));
    }
    function fixEntitiesLiteral(str) {
        if (str == null) return str;
        return str.toString().split('&#039;').join("'").split('&amp;').join('&');
    }
    // Escapes a string to insert it as an argument in an
    // onclick="...('...')" attribute: first JS escaping (backslash then
    // apostrophe), then HTML escaping of the attribute itself — the
    // browser decodes entities only once while parsing the attribute,
    // which restores exactly the intended JS escape sequence.
    // Needed whenever a text may contain a real apostrophe (e.g.
    // after fixEntities()): a simple .replace(/'/g,"\\'") applied after
    // escapeHTML() no longer finds a raw apostrophe to escape since
    // escapeHTML() has already turned it into "&#039;".
    function jsAttrEscape(str) {
        if (str == null) return '';
        return escapeHTML(str.toString().replace(/\\/g,'\\\\').replace(/'/g,"\\'"));
    }

    let searchTimeout;
    function onSearchInput() {
        const term = document.getElementById('searchInput').value.trim();
        if (currentSection !== 'accueil' && term !== '') showSection('accueil');
        // The home carousels (Recommended / Popular / Hidden gems) make no
        // sense during a search: they reappear as soon as the search bar
        // is cleared.
        const carousels = document.getElementById('home-carousels');
        if (carousels) carousels.style.display = term === '' ? '' : 'none';
        document.getElementById('searchClearBtn').classList.toggle('visible', term !== '');
        clearTimeout(searchTimeout); searchTimeout = setTimeout(filterAndSortTracks, 250);
    }

    function clearSearch() {
        const input = document.getElementById('searchInput');
        input.value = '';
        onSearchInput();
        input.focus();
    }

    // Some environments (e.g. macOS system keyboard shortcuts, where
    // Ctrl+A means "go to start of line" and not "select all") don't
    // let the field select all its text with Ctrl+A/Cmd+A by
    // default: we force it explicitly here for consistent behavior
    // everywhere.
    function handleSearchKeydown(e) {
        if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && (e.key === 'a' || e.key === 'A')) {
            e.preventDefault();
            e.target.select();
        }
    }

    function updateUrl() {
        const params = new URLSearchParams();
        if (currentSection !== 'accueil') params.set('page', currentSection);
        if (currentSection === 'artist-page' && currentArtistName) params.set('artist', currentArtistName);
        if (currentSection === 'album-page' && currentAlbumId) params.set('album', currentAlbumId);
        if (currentSection === 'playlist-page' && currentViewedPlaylist) params.set('playlist', currentViewedPlaylist.id);
        if (queue[currentIndex]?.id) params.set('v', queue[currentIndex].id);
        if (currentPlaylistId) params.set('list', currentPlaylistId);
        window.history.pushState({}, '', window.location.pathname + '?' + params.toString());
    }

    function toggleGenreSetting(genre, isChecked) {
        if (isChecked) { if (!hiddenGenres.includes(genre)) hiddenGenres.push(genre); }
        else { hiddenGenres = hiddenGenres.filter(g => g !== genre); }
        localStorage.setItem('hiddenGenres', JSON.stringify(hiddenGenres));
        if (selectedHomeGenre !== null && hiddenGenres.includes(selectedHomeGenre)) selectedHomeGenre = null;
        filterAndSortTracks();
        renderGenrePillsCarousel();
        renderHomeCarousels();
    }

    // Shows/hides the "Admin Panel" entry (desktop nav + mobile bar)
    // according to the saved preference — direct access by URL (?page=admin-page)
    // is still possible: this only declutters navigation, it is not an
    // access control (is_admin on the server remains the only real authority).
    function applyHideAdminPanel() {
        document.querySelectorAll('.admin-nav-btn, #mob-nav-admin-page').forEach(el => {
            el.style.display = hideAdminPanel ? 'none' : '';
        });
    }
    function toggleHideAdminPanel(isChecked) {
        hideAdminPanel = isChecked;
        localStorage.setItem('hideAdminPanel', isChecked ? '1' : '0');
        applyHideAdminPanel();
        if (isChecked && currentSection === 'admin-page') showSection('accueil');
        // The ✎/✕ buttons (edit_track/delete_track) on other people's tracks
        // also depend on hideAdminPanel (see trackRowInnerHTML): the main
        // list is already rendered in the DOM, it has to be rebuilt so
        // they appear/disappear immediately without reloading the
        // page. The other pages (artist/album/playlist/history) re-read
        // hideAdminPanel every time we navigate to them, no need to
        // rebuild them here since they aren't visible at the same time
        // as the Settings page.
        filterAndSortTracks();
    }

    function toggleHideEditDeleteButtons(isChecked) {
        hideEditDeleteButtons = isChecked;
        localStorage.setItem('hideEditDeleteButtons', isChecked ? '1' : '0');
        // Same remark as toggleHideAdminPanel: only the main list
        // (already in the DOM) needs to be rebuilt here, the other
        // pages re-read hideEditDeleteButtons on every navigation.
        filterAndSortTracks();
    }

    // Splits an "artist1, artist2 & artist3" string (or with feat./ft./x/vs)
    // into individual artist names, each pointing to its own page.
    const ARTIST_SPLIT_REGEX = /\s*,\s*|\s*&amp;\s*|\s*&\s*|\s+feat\.?\s+|\s+ft\.?\s+|\s+featuring\s+|\s+vs\.?\s+|\s+x\s+|\s+and\s+|\s+et\s+/gi;
    function splitArtistNames(str) {
        if (!str) return [];
        return str.split(ARTIST_SPLIT_REGEX).map(s => s.trim()).filter(Boolean);
    }

    // Renders a track's artist field as clickable link(s):
    // if several artists appear in the tag, each one gets its own link
    // to its page (instead of a single link to the full string).
    function artistLinksHTML(rawArtist) {
        const decoded = fixEntities(rawArtist) || t('unknown_artist');
        const names = splitArtistNames(decoded);
        if (names.length <= 1) {
            return `<span class="artist-link" onclick="event.stopPropagation();showArtistPage('${jsAttrEscape(decoded)}')">${escapeHTML(decoded)}</span>`;
        }
        const links = names.map(n => `<span class="artist-link" onclick="event.stopPropagation();showArtistPage('${jsAttrEscape(n)}')">${escapeHTML(n)}</span>`);
        // "A & B" (so "A and B" once fixEntities() is applied) must read
        // "A and B", not "A, B": commas between all names except the last
        // two, joined by the localized joining word (word_and).
        const last = links.pop();
        return links.length ? links.join(', ') + ` ${t('word_and')} ` + last : last;
    }

    // Renders a track's album name as a clickable link to its
    // album page (nothing is rendered if the track belongs to no album).
    function albumLinkHTML(track) {
        if (!track.album || !track.album_id) return '';
        const decoded = fixEntities(track.album);
        return ` <span style="opacity:.6;">•</span> <span class="artist-link" onclick="event.stopPropagation();showAlbumPage(${parseInt(track.album_id)})">${escapeHTML(decoded)}</span>`;
    }

    // ── "⋮" context menu (queue / playlists) ───────────────────────────────
    // A single shared element (#track-ctx-menu, in the HTML) rather than one
    // menu per row: repositioned and re-rendered for the track that
    // was clicked (ctxMenuTrackId).
    let ctxMenuTrackId = null;
    let ctxMenuCanEditPlaylist = false;

    // Google Material Symbols icons (Outlined), viewBox 0 -960 960 960
    const TRACK_MENU_ICONS = {
        queue: 'M640-160q-50 0-85-35t-35-85q0-50 35-85t85-35q11 0 21 1.5t19 6.5v-328h200v80H760v360q0 50-35 85t-85 35ZM120-320v-80h320v80H120Zm0-160v-80h480v80H120Zm0-160v-80h480v80H120Z',
        playNext: 'M120-320v-80h320v80H120Zm0-160v-80h480v80H120Zm0-160v-80h480v80H120Zm520 520v-320l240 160-240 160Z',
        addToPlaylist: 'M120-320v-80h280v80H120Zm0-160v-80h440v80H120Zm0-160v-80h440v80H120Zm520 480v-160H480v-80h160v-160h80v160h160v80H720v160h-80Z',
        remove: 'm576-80-56-56 104-104-104-104 56-56 104 104 104-104 56 56-104 104 104 104-56 56-104-104L576-80ZM120-320v-80h280v80H120Zm0-160v-80h440v80H120Zm0-160v-80h440v80H120Z'
    };

    function trackMenuIcon(path) {
        return `<svg viewBox="0 -960 960 960" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="${path}"/></svg>`;
    }

    function trackMenuButtonHTML(trackId, canEditPlaylist = false) {
        return `<button class="track-menu-btn" onclick="event.stopPropagation();openTrackMenu(event,${trackId},${canEditPlaylist})" title="${t('track_menu_title')}" aria-label="${t('track_menu_title')}">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>
        </button>`;
    }

    function openTrackMenu(evt, trackId, canEditPlaylist = false) {
        evt.stopPropagation();
        ctxMenuTrackId = Number(trackId);
        ctxMenuCanEditPlaylist = !!canEditPlaylist;
        renderTrackMenuMain();
        document.getElementById('track-ctx-menu').classList.add('open');
        positionTrackMenu(evt.currentTarget);
    }

    function closeTrackMenu() {
        const menu = document.getElementById('track-ctx-menu');
        menu.classList.remove('open');
        menu.innerHTML = '';
        ctxMenuTrackId = null;
    }

    // "fixed" position anchored to the clicked button, recomputed after rendering (we
    // need the menu's real dimensions) and flipped if it would overflow
    // the viewport rather than clipping the menu.
    function positionTrackMenu(anchorEl) {
        const menu = document.getElementById('track-ctx-menu');
        const btnRect = anchorEl.getBoundingClientRect();
        requestAnimationFrame(() => {
            const rect = menu.getBoundingClientRect();
            let left = btnRect.right - rect.width;
            let top = btnRect.bottom + 4;
            if (left < 8) left = 8;
            if (left + rect.width > window.innerWidth - 8) left = window.innerWidth - rect.width - 8;
            if (top + rect.height > window.innerHeight - 8) top = btnRect.top - rect.height - 4;
            if (top < 8) top = 8;
            menu.style.left = left + 'px';
            menu.style.top = top + 'px';
        });
    }
    // Only re-adjusts the vertical position after a content change
    // (e.g. switching to the "playlists" submenu, longer than the main menu),
    // without asking again for the original button's position (not kept).
    function reclampTrackMenuVertical() {
        const menu = document.getElementById('track-ctx-menu');
        requestAnimationFrame(() => {
            const rect = menu.getBoundingClientRect();
            if (rect.bottom > window.innerHeight - 8) menu.style.top = Math.max(8, window.innerHeight - rect.height - 8) + 'px';
        });
    }

    document.addEventListener('click', (e) => {
        const menu = document.getElementById('track-ctx-menu');
        if (menu.classList.contains('open') && !menu.contains(e.target) && !e.target.closest('.track-menu-btn')) closeTrackMenu();
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeTrackMenu(); });
    // A menu positioned `fixed` on its button gets out of sync as soon as
    // the list scrolls underneath: simpler and safer to close it.
    window.addEventListener('scroll', () => closeTrackMenu(), true);

    function renderTrackMenuMain() {
        const menu = document.getElementById('track-ctx-menu');
        // event.stopPropagation() is essential here: the click replaces the
        // menu's content (innerHTML) BEFORE bubbling up to the document
        // listener that closes the menu on outside clicks — without stopping it, this
        // listener receives an e.target already detached from the DOM (so not "contained"
        // in the menu as far as it knows) and closes the menu right when it has just
        // changed content.
        const removeItem = ctxMenuCanEditPlaylist
            ? `<div class="track-menu-divider"></div><div class="track-menu-item has-icon danger" onclick="event.stopPropagation();menuRemoveFromPlaylist()">${trackMenuIcon(TRACK_MENU_ICONS.remove)}<span>${t('remove_from_playlist')}</span></div>`
            : '';
        menu.innerHTML = `
            <div class="track-menu-item has-icon" onclick="event.stopPropagation();menuAddToQueueEnd()">${trackMenuIcon(TRACK_MENU_ICONS.queue)}<span>${t('menu_add_to_queue')}</span></div>
            <div class="track-menu-item has-icon" onclick="event.stopPropagation();menuPlayNext()">${trackMenuIcon(TRACK_MENU_ICONS.playNext)}<span>${t('menu_play_next')}</span></div>
            <div class="track-menu-item has-icon" onclick="event.stopPropagation();renderTrackMenuPlaylists()">${trackMenuIcon(TRACK_MENU_ICONS.addToPlaylist)}<span>${t('menu_add_to_playlist')}</span></div>
            ${removeItem}`;
    }

    // "Add to a playlist" sub-panel: lists the playlists the user
    // can edit (their own, or all of them if admin — same rule as
    // playlist_mod on the server), with a check mark on those that already
    // contain this track (not clickable: playlist_mod 'add' is idempotent
    // on the server but we might as well avoid the useless network call).
    function renderTrackMenuPlaylists() {
        const menu = document.getElementById('track-ctx-menu');
        const eligible = ALL_PLAYLISTS.filter(p => p.creator_id == CURRENT_USER_ID || IS_ADMIN);
        const rows = eligible.map(p => {
            const already = String(p.song_ids || '').split(',').filter(Boolean).map(Number).includes(ctxMenuTrackId);
            return `<div class="track-menu-item${already ? ' disabled' : ''}" ${already ? '' : `onclick="event.stopPropagation();menuAddToExistingPlaylist(${p.id})"`}>${escapeHTML(fixEntities(p.name))}${already ? ' ✓' : ''}</div>`;
        }).join('') || `<div class="track-menu-item disabled">${t('no_playlists_yet')}</div>`;
        menu.innerHTML = `
            <div class="track-menu-item track-menu-back" onclick="event.stopPropagation();renderTrackMenuMain()">‹ ${t('menu_back')}</div>
            <div class="track-menu-divider"></div>
            <div class="track-menu-item" onclick="event.stopPropagation();menuCreatePlaylistWithTrack()">+ ${t('new_playlist')}</div>
            <div class="track-menu-divider"></div>
            ${rows}`;
        reclampTrackMenuVertical();
    }

    function menuAddToQueueEnd() { addTrackToQueueEnd(ctxMenuTrackId); closeTrackMenu(); }
    function menuPlayNext() { addTrackToQueuePlayNext(ctxMenuTrackId); closeTrackMenu(); }

    async function menuAddToExistingPlaylist(playlistId) {
        const trackId = ctxMenuTrackId;
        const res = await apiCall('playlist_mod', { playlist_id: playlistId, mode: 'add', track_id: trackId });
        if (res.status === 'success') {
            const p = ALL_PLAYLISTS.find(pl => pl.id == playlistId);
            if (p) {
                const ids = String(p.song_ids || '').split(',').filter(Boolean).map(Number);
                if (!ids.includes(trackId)) ids.push(trackId);
                p.song_ids = ids.join(',');
                if (currentViewedPlaylist && currentViewedPlaylist.id == playlistId) {
                    currentViewedPlaylist.song_ids = p.song_ids;
                    renderPlaylistPageContent();
                }
            }
        } else alert(res.message || t('err_generic'));
        closeTrackMenu();
    }

    // Reuses the existing playlist creation modal (multiple track
    // selection) rather than building a dedicated one: we open it
    // and simply pre-check the track the menu came from.
    function menuCreatePlaylistWithTrack() {
        const trackId = ctxMenuTrackId;
        closeTrackMenu();
        openCreateModal();
        const cb = document.querySelector(`.song-cb[data-id="${trackId}"]`);
        if (cb) {
            cb.checked = true;
            cb.closest('.song-select-item')?.classList.add('selected');
            updateSelectedCount();
        }
    }

    function menuRemoveFromPlaylist() {
        const trackId = ctxMenuTrackId;
        closeTrackMenu();
        removeFromPlaylist(trackId);
    }

    // ── Direct mutations of the current queue, independent of the context
    // that built it (search/library/album...). `queue` (actual playback
    // order) and `originalQueue` (canonical reference used by
    // toggleShuffle/refillQueueIfNeeded) are kept in sync: without
    // that, a manually added track would disappear on the next
    // (un)shuffle, since `toggleShuffle` only keeps the ids present in
    // `originalQueue`.
    // Removes occurrences already present further in the queue (upcoming
    // only, never the already-played history nor the current track) before
    // reinserting: without that, "Play next"/"Add to queue" on a
    // track already scheduled further down would duplicate it, and leave
    // originalQueue with two occurrences while `queue` has only one —
    // a desync that resurfaces (duplicate or loss) on the next
    // (un)shuffle, since toggleShuffle only filters originalQueue by id.
    function removeFromUpcomingQueue(trackId) {
        const curId = queue[currentIndex] ? queue[currentIndex].id : null;
        if (trackId === curId) return;
        for (let i = queue.length - 1; i > currentIndex; i--) {
            if (queue[i].id === trackId) queue.splice(i, 1);
        }
        for (let i = originalQueue.length - 1; i >= 0; i--) {
            if (originalQueue[i].id === trackId) originalQueue.splice(i, 1);
        }
    }
    function addTrackToQueueEnd(trackId) {
        const track = ALL_MUSIC_DATA.find(tr => tr.id === Number(trackId));
        if (!track) return;
        if (!queue.length) { buildFixedQueueNoContinuation([track], { startId: track.id, autoPlay: true }); return; }
        removeFromUpcomingQueue(track.id);
        queue.push(track);
        originalQueue.push(track);
        usedTrackIds.add(track.id);
        updateQueueUI();
    }
    function addTrackToQueuePlayNext(trackId) {
        const track = ALL_MUSIC_DATA.find(tr => tr.id === Number(trackId));
        if (!track) return;
        if (!queue.length) { buildFixedQueueNoContinuation([track], { startId: track.id, autoPlay: true }); return; }
        removeFromUpcomingQueue(track.id);
        queue.splice(currentIndex + 1, 0, track);
        const curId = queue[currentIndex] ? queue[currentIndex].id : null;
        const origIdx = originalQueue.findIndex(tr => tr.id === curId);
        originalQueue.splice(origIdx === -1 ? originalQueue.length : origIdx + 1, 0, track);
        usedTrackIds.add(track.id);
        updateQueueUI();
    }

    function trackRowInnerHTML(t, idx, context = null) {
        const safeTitle  = escapeHTML(fixEntities(t.title));
        const artistHTML = artistLinksHTML(t.artist);
        const albumHTML  = albumLinkHTML(t);
        // The raw (undecoded) genre must exactly match the value attribute
        // of the <option>s in the edit modal's "Genre" select
        // (openEditTrackModal). displayGenre is the decoded version shown
        // in the list.
        const rawGenre     = t.genre || 'Autre';
        const displayGenre = escapeHTML(fixEntities(rawGenre));
        const safeCover  = escapeHTML(t.cover_url);
        const jsTitle    = jsAttrEscape(fixEntities(t.title));
        const jsArtist   = jsAttrEscape(fixEntities(t.artist));
        const jsGenre    = jsAttrEscape(rawGenre);
        const jsAlbum    = jsAttrEscape(fixEntities(t.album || ''));
        let editButtons  = '';
        // hideEditDeleteButtons hides ✎/✕ for everyone, even on their
        // own tracks (clean read-only view); hideAdminPanel only
        // hides the admin override on OTHER people's tracks — an admin
        // who checked it wants to stop seeing these controls on what they didn't
        // upload themselves, without losing editing on their own uploads.
        // In both cases, nothing changes on the server: delete_track/
        // edit_track always re-check ownership/is_admin independently there.
        if (!hideEditDeleteButtons && (t.uploader_id == CURRENT_USER_ID || (IS_ADMIN && !hideAdminPanel))) {
            editButtons = `
                <button class="btn btn-outline" style="font-size:.7em;padding:6px 10px;border-radius:8px;" onclick="openEditTrackModal(${t.id},'${jsTitle}','${jsArtist}','${jsGenre}','${jsAlbum}')">✎</button>
                <button class="btn btn-danger" style="border-radius:8px;" onclick="deleteTrack(${t.id})">✕</button>`;
        }
        // context tells which list this track comes from (artist/album page)
        // so that the click builds the queue from THAT list
        // rather than CURRENT_VIEW_DATA, which may be another list (e.g.
        // search results) that has nothing to do with the displayed page anymore.
        const clickHandler = context === 'artist'  ? `playTrackInArtist(${t.id})`
                            : context === 'album'   ? `playTrackInAlbum(${t.id})`
                            : context === 'history' ? `playTrackInHistory(${t.id})`
                            : `playTrackById(${t.id})`;
        return `
            <div class="track-index">${idx}</div>
            <img src="${safeCover}" loading="lazy" class="mini-cover" onerror="this.onerror=null;this.src='covers/default.png'">
            <div style="cursor:pointer;overflow:hidden;" onclick="${clickHandler}">
                <div style="font-weight:700;font-size:1.05em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:3px;">${safeTitle}</div>
                <div style="font-size:.85em;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                    ${artistHTML}${albumHTML} <span style="opacity:.6;font-size:.9em;">• ${displayGenre} • ▶ ${t.play_count||0}</span>
                </div>
            </div>
            <div style="display:flex;gap:4px;align-items:center;">${editButtons}${trackMenuButtonHTML(t.id)}</div>`;
    }

    function renderTracksChunk() {
        const listContainer = document.getElementById('global-list');
        const chunk = CURRENT_VIEW_DATA.slice(renderedCount, renderedCount + RENDER_CHUNK);
        if (renderedCount === 0) listContainer.innerHTML = '';
        if (chunk.length === 0 && renderedCount === 0) {
            listContainer.innerHTML = `<div style="padding:40px;text-align:center;color:var(--text-muted);">${t('no_tracks_found')}</div>`; return;
        }
        const fragment = document.createDocumentFragment();
        chunk.forEach((t, index) => {
            const idx = renderedCount + index + 1;
            const div = document.createElement('div'); div.className = 'track-item';
            div.style.animationDelay = (Math.min(index, 14) * 18) + 'ms';
            div.innerHTML = trackRowInnerHTML(t, idx);
            fragment.appendChild(div);
        });
        listContainer.appendChild(fragment); renderedCount += chunk.length;
    }

    // ── Artist page: lists all tracks where this artist name appears
    // (including tracks credited to several artists) ────────────
    let artistBioToken = 0;
    function showArtistPage(name, doUrl = true) {
        closeFullPlayer();
        currentArtistName = name;
        const norm = name.trim().toLowerCase();
        const tracks = ALL_MUSIC_DATA.filter(tr => splitArtistNames(fixEntities(tr.artist)).some(n => n.toLowerCase() === norm));

        document.getElementById('artist-page-title').innerText = name;
        document.getElementById('artist-page-count').innerText = tracks.length + ' ' + t('tracks_count_label');

        // Cover of the most recent track (highest id, same convention
        // as the "Recently added" sort) used as the profile picture and as the
        // blurred background of the artist header.
        const mostRecent = tracks.length ? tracks.reduce((a, b) => (b.id > a.id ? b : a)) : null;
        const heroCover  = mostRecent ? mostRecent.cover_url : 'covers/default.png';
        const pfpImg    = document.getElementById('artist-pfp');
        const heroBgImg = document.getElementById('artist-hero-bg-img');
        pfpImg.src = heroCover;
        heroBgImg.classList.remove('loaded');
        heroBgImg.onload = () => heroBgImg.classList.add('loaded');
        heroBgImg.src = heroCover;

        const listContainer = document.getElementById('artist-track-list');
        if (!tracks.length) {
            listContainer.innerHTML = `<div style="padding:40px;text-align:center;color:var(--text-muted);">${t('no_tracks_found')}</div>`;
        } else {
            listContainer.innerHTML = tracks.map((tr, i) => `<div class="track-item" style="animation-delay:${Math.min(i, 14) * 18}ms">${trackRowInnerHTML(tr, i + 1, 'artist')}</div>`).join('');
        }
        fetchArtistBio(name);
        showSection('artist-page', doUrl);
    }

    // ── Album page: lists the tracks attached to this album_id ────────
    function showAlbumPage(albumId, doUrl = true) {
        closeFullPlayer();
        albumId = parseInt(albumId);
        currentAlbumId = albumId;
        // Album track order = upload order (oldest = track 1),
        // not the order of ALL_MUSIC_DATA (sorted by popularity).
        const tracks = ALL_MUSIC_DATA.filter(tr => parseInt(tr.album_id) === albumId).sort((a, b) => a.id - b.id);
        const albumName = tracks.length ? fixEntities(tracks[0].album) : '';

        document.getElementById('album-page-title').innerText = albumName;
        document.getElementById('album-page-count').innerText = tracks.length + ' ' + t('tracks_count_label');

        // Artists featured on the album: de-duplicated (case-insensitive)
        // union of each track's artists, including featurings split
        // by splitArtistNames (e.g. "A feat. B" -> A, B both clickable).
        const artistMap = new Map();
        tracks.forEach(tr => {
            splitArtistNames(fixEntities(tr.artist)).forEach(n => {
                const key = n.toLowerCase();
                if (!artistMap.has(key)) artistMap.set(key, n);
            });
        });
        const artistsEl = document.getElementById('album-page-artists');
        if (artistMap.size) {
            const links = [...artistMap.values()]
                .map(n => `<span class="artist-link" onclick="showArtistPage('${jsAttrEscape(n)}')">${escapeHTML(n)}</span>`)
                .join(', ');
            artistsEl.innerHTML = links;
        } else {
            artistsEl.innerHTML = '';
        }

        // Same fallback logic as the backend (action=album_cover): a manually
        // imported album cover takes priority, otherwise the cover of the
        // album's most recent track is used.
        const heroCover = 'api.php?action=album_cover&q=' + albumId + '&t=' + Date.now();
        const pfpImg    = document.getElementById('album-pfp');
        const heroBgImg = document.getElementById('album-hero-bg-img');
        pfpImg.src = heroCover;
        heroBgImg.classList.remove('loaded');
        heroBgImg.onload = () => heroBgImg.classList.add('loaded');
        heroBgImg.src = heroCover;

        const listContainer = document.getElementById('album-track-list');
        if (!tracks.length) {
            listContainer.innerHTML = `<div style="padding:40px;text-align:center;color:var(--text-muted);">${t('no_tracks_found')}</div>`;
        } else {
            listContainer.innerHTML = tracks.map((tr, i) => `<div class="track-item" style="animation-delay:${Math.min(i, 14) * 18}ms">${trackRowInnerHTML(tr, i + 1, 'album')}</div>`).join('');
        }
        showSection('album-page', doUrl);
    }

    // ── Playlist page: details of a playlist (title, creator, visibility,
    // tracks in song_ids order) — opened by clicking its card.
    let playlistEditMode = false;
    let currentPlaylistCanEdit = false;

    function showPlaylistPage(id, doUrl = true) {
        closeFullPlayer();
        id = parseInt(id);
        const playlist = ALL_PLAYLISTS.find(p => parseInt(p.id) === id);
        if (!playlist) { showSection('playlists', doUrl); return; }
        currentViewedPlaylist = playlist;
        currentPlaylistCanEdit = playlist.creator_id == CURRENT_USER_ID || (IS_ADMIN && !hideAdminPanel);
        playlistEditMode = false;
        renderPlaylistPageContent();
        showSection('playlist-page', doUrl);
    }

    // ── History page: tracks actually listened to by the user
    // (listen_history table on the server), one per track, from most recent to
    // oldest — not a random draw nor a list derived from a sort.
    async function showHistoryPage(doUrl = true) {
        closeFullPlayer();
        showSection('history', doUrl);
        const listContainer = document.getElementById('history-track-list');
        listContainer.innerHTML = '';
        const res = await apiCall('history', { limit: 100 });

        // The page may have been left while waiting for the response.
        if (currentSection !== 'history') return;

        if (!Array.isArray(res)) {
            console.error('action=history failed:', res);
            currentHistoryTracks = [];
            listContainer.innerHTML = `<div style="padding:40px;text-align:center;color:var(--text-muted);">${escapeHTML(res?.message || t('err_api_unreachable'))}</div>`;
            return;
        }
        const tracks = res;
        currentHistoryTracks = tracks;

        if (!tracks.length) {
            listContainer.innerHTML = `<div style="padding:40px;text-align:center;color:var(--text-muted);">${t('history_empty')}</div>`;
            return;
        }
        const fragment = document.createDocumentFragment();
        tracks.forEach((tr, index) => {
            const div = document.createElement('div'); div.className = 'track-item';
            div.style.animationDelay = (Math.min(index, 14) * 18) + 'ms';
            div.innerHTML = trackRowInnerHTML(tr, index + 1, 'history');
            fragment.appendChild(div);
        });
        listContainer.appendChild(fragment);
    }

    // ── Rebuilds the whole playlist page content (hero, mosaic,
    // list) from currentViewedPlaylist / ALL_MUSIC_DATA, without navigating
    // or touching playlistEditMode — used after every local mutation
    // (reorder, remove, rename) for instant YouTube Music-style rendering,
    // without a page reload.
    function renderPlaylistPageContent() {
        const playlist = currentViewedPlaylist;
        if (!playlist) return;

        const songIds = String(playlist.song_ids || '').split(',').filter(Boolean).map(Number);
        const tracks = songIds.map(tid => ALL_MUSIC_DATA.find(tr => tr.id === tid)).filter(Boolean);

        document.getElementById('playlist-page-title').innerText = fixEntities(playlist.name);
        document.getElementById('playlist-page-count').innerText = tracks.length + ' ' + t('tracks_count_label');
        document.getElementById('playlist-page-creator-text').innerText = t('created_by') + ' ' + fixEntities(playlist.creator);

        const isPublic = !('is_public' in playlist) || Number(playlist.is_public) === 1;
        document.getElementById('playlist-page-private-badge').style.display = isPublic ? 'none' : 'inline-flex';

        document.getElementById('playlist-page-actions').style.display = currentPlaylistCanEdit ? 'flex' : 'none';
        updatePlaylistEditModeUI();

        // Cover mosaic (up to 4, repeated if fewer), same
        // convention as the playlist grid cards.
        const covers = tracks.slice(0, 4).map(tr => tr.cover_url);
        if (!covers.length) covers.push('covers/default.png');
        const slots = covers.length === 1 ? 1 : 4;
        const collage = document.getElementById('playlist-page-collage');
        collage.classList.toggle('single', slots === 1);
        collage.innerHTML = Array.from({ length: slots }, (_, i) =>
            `<img src="${escapeHTML(covers[i % covers.length])}" loading="lazy" alt="" class="cc-loading" onload="this.classList.remove('cc-loading')" onerror="this.classList.remove('cc-loading');this.onerror=null;this.src='covers/default.png'">`
        ).join('');

        const heroBgImg = document.getElementById('playlist-hero-bg-img');
        heroBgImg.classList.remove('loaded');
        heroBgImg.onload = () => heroBgImg.classList.add('loaded');
        heroBgImg.src = covers[0];

        renderPlaylistTrackList(tracks, currentPlaylistCanEdit && playlistEditMode);
    }

    // ── Toggles the playlist page's edit mode: this is what makes
    // the drag-and-drop handles / arrows / ✕ appear on the list,
    // and turns the title into an in-place editable field (no popup).
    function togglePlaylistEditMode() {
        if (!currentPlaylistCanEdit) return;
        playlistEditMode = !playlistEditMode;
        renderPlaylistPageContent();
        if (playlistEditMode) {
            const input = document.getElementById('playlist-page-title-input');
            input.focus(); input.select();
        }
    }

    function updatePlaylistEditModeUI() {
        const btn = document.getElementById('playlist-page-edit-btn');
        if (btn) {
            btn.classList.toggle('btn-primary', playlistEditMode);
            btn.classList.toggle('btn-outline', !playlistEditMode);
            btn.innerText = playlistEditMode ? t('btn_done') : t('btn_edit');
        }
        const titleEl = document.getElementById('playlist-page-title');
        const inputEl = document.getElementById('playlist-page-title-input');
        if (playlistEditMode) {
            inputEl.value = fixEntities(currentViewedPlaylist.name);
            titleEl.style.display = 'none';
            inputEl.style.display = 'block';
        } else {
            inputEl.style.display = 'none';
            titleEl.style.display = '';
        }

        const visToggle = document.getElementById('playlist-page-visibility-toggle');
        if (visToggle) {
            visToggle.style.display = playlistEditMode ? 'inline-flex' : 'none';
            const isPublic = !('is_public' in currentViewedPlaylist) || Number(currentViewedPlaylist.is_public) === 1;
            document.getElementById('playlist-page-public-checkbox').checked = isPublic;
        }
    }

    // ── Visibility (public/private): toggles immediately when clicking the
    // checkbox, only available in edit mode — no popup either.
    async function togglePlaylistVisibility(isPublic) {
        if (!currentViewedPlaylist) return;
        const res = await apiCall('playlist_mod', { playlist_id: currentViewedPlaylist.id, mode: 'visibility', is_public: isPublic ? '1' : '0' });
        if (res.status === 'success') {
            currentViewedPlaylist.is_public = isPublic ? 1 : 0;
            document.getElementById('playlist-page-private-badge').style.display = isPublic ? 'none' : 'inline-flex';
        } else {
            alert(res.message || t('err_generic'));
            document.getElementById('playlist-page-public-checkbox').checked = !isPublic;
        }
    }

    // ── In-place rename: saves on blur/Enter of the title field,
    // only if the name actually changed — no popup.
    async function savePlaylistTitleInline() {
        if (!currentViewedPlaylist || !playlistEditMode) return;
        const input = document.getElementById('playlist-page-title-input');
        const newName = input.value.trim();
        if (!newName || newName === fixEntities(currentViewedPlaylist.name)) {
            input.value = fixEntities(currentViewedPlaylist.name);
            return;
        }
        const res = await apiCall('playlist_mod', { playlist_id: currentViewedPlaylist.id, mode: 'rename', new_name: newName });
        if (res.status === 'success') {
            currentViewedPlaylist.name = newName;
            document.getElementById('playlist-page-title').innerText = fixEntities(currentViewedPlaylist.name);
        } else {
            alert(res.message || t('err_edit'));
            input.value = fixEntities(currentViewedPlaylist.name);
        }
    }

    // ── A playlist's track list, with YouTube Music-style
    // reordering when the user has the rights (owner/admin):
    // drag-and-drop (mouse) + ▲▼ arrows (accessible/touch), all
    // persisted instantly via playlist_mod/mode=reorder, without a page
    // reload — only the list (and the cover mosaic) updates.
    function renderPlaylistTrackList(tracks, canEdit) {
        const listContainer = document.getElementById('playlist-track-list');
        const hint = document.getElementById('playlist-page-reorder-hint');
        if (hint) {
            hint.innerText = t('drag_reorder_hint');
            hint.style.display = (canEdit && tracks.length > 1) ? 'block' : 'none';
        }

        if (!tracks.length) {
            listContainer.innerHTML = `<div style="padding:40px;text-align:center;color:var(--text-muted);">${t('no_tracks_found')}</div>`;
            return;
        }
        listContainer.innerHTML = tracks.map((tr, i) => `
            <div class="track-item${canEdit ? ' pl-editable' : ''}" data-track-id="${tr.id}" ${canEdit ? 'draggable="true"' : ''} style="animation-delay:${Math.min(i, 14) * 18}ms">
                ${renderPlaylistTrackRow(tr, i + 1, canEdit, tracks.length)}
            </div>`).join('');
        if (canEdit) attachPlaylistDragHandlers(listContainer);
    }

    function renderPlaylistTrackRow(tr, idx, canEdit, total) {
        const safeTitle  = escapeHTML(fixEntities(tr.title));
        const artistHTML = artistLinksHTML(tr.artist);
        const albumHTML  = albumLinkHTML(tr);
        const safeCover  = escapeHTML(tr.cover_url);
        const dragHandle = canEdit ? `
            <div class="pl-drag-handle" title="${t('drag_reorder_hint')}">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>
            </div>` : '';
        const actions = canEdit ? `
            <div style="display:flex;align-items:center;gap:2px;">
                <button class="pl-icon-btn" onclick="event.stopPropagation();movePlaylistTrack(${tr.id},-1)" ${idx === 1 ? 'disabled' : ''} title="${t('move_up')}">▲</button>
                <button class="pl-icon-btn" onclick="event.stopPropagation();movePlaylistTrack(${tr.id},1)" ${idx === total ? 'disabled' : ''} title="${t('move_down')}">▼</button>
                <button class="pl-icon-btn" onclick="event.stopPropagation();removeFromPlaylist(${tr.id})" title="${t('remove_from_playlist')}">✕</button>
            </div>` : '';
        return `
            ${dragHandle}
            <div class="track-index">${idx}</div>
            <img src="${safeCover}" loading="lazy" class="mini-cover" onerror="this.onerror=null;this.src='covers/default.png'">
            <div style="cursor:pointer;overflow:hidden;" onclick="playTrackInPlaylist(${tr.id})">
                <div style="font-weight:700;font-size:1.05em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:3px;">${safeTitle}</div>
                <div style="font-size:.85em;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${artistHTML}${albumHTML}</div>
            </div>
            ${actions}
            ${trackMenuButtonHTML(tr.id, currentPlaylistCanEdit)}`;
    }

    // ── Native drag-and-drop (mouse): reorders the DOM live during
    // the drag, only persists (reorder) on release.
    function attachPlaylistDragHandlers(container) {
        let dragEl = null;
        container.querySelectorAll('.track-item.pl-editable').forEach(item => {
            item.addEventListener('dragstart', () => {
                dragEl = item;
                requestAnimationFrame(() => item.classList.add('dragging'));
            });
            item.addEventListener('dragend', () => {
                item.classList.remove('dragging');
                dragEl = null;
                renumberPlaylistRows(container);
                persistPlaylistOrder();
            });
            item.addEventListener('dragover', e => {
                e.preventDefault();
                if (!dragEl) return;
                const after = getDragAfterElement(container, e.clientY);
                if (after == null) container.appendChild(dragEl);
                else container.insertBefore(dragEl, after);
            });
        });
    }

    function getDragAfterElement(container, y) {
        const els = [...container.querySelectorAll('.track-item.pl-editable:not(.dragging)')];
        return els.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closest.offset) return { offset, element: child };
            return closest;
        }, { offset: -Infinity, element: null }).element;
    }

    function renumberPlaylistRows(container) {
        container.querySelectorAll('.track-item').forEach((row, i) => {
            const idxEl = row.querySelector('.track-index');
            if (idxEl) idxEl.innerText = i + 1;
        });
    }

    // ── Reordering via the ▲▼ arrows: recomputes song_ids and
    // re-renders the page from the data already in memory (no network
    // call for display, only persistence goes through the API).
    function movePlaylistTrack(trackId, dir) {
        if (!currentViewedPlaylist) return;
        const ids = String(currentViewedPlaylist.song_ids).split(',').filter(Boolean).map(Number);
        const i = ids.indexOf(trackId);
        const j = i + dir;
        if (i === -1 || j < 0 || j >= ids.length) return;
        [ids[i], ids[j]] = [ids[j], ids[i]];
        currentViewedPlaylist.song_ids = ids.join(',');
        renderPlaylistPageContent();
        persistPlaylistOrder();
    }

    async function persistPlaylistOrder() {
        if (!currentViewedPlaylist) return;
        const container = document.getElementById('playlist-track-list');
        const ids = [...container.querySelectorAll('.track-item[data-track-id]')].map(el => el.dataset.trackId);
        const csv = ids.join(',');
        currentViewedPlaylist.song_ids = csv;
        const res = await apiCall('playlist_mod', { playlist_id: currentViewedPlaylist.id, mode: 'reorder', song_ids: csv });
        if (res.status !== 'success') alert(res.message || t('err_generic'));
    }

    async function removeFromPlaylist(trackId) {
        if (!currentViewedPlaylist) return;
        const res = await apiCall('playlist_mod', { playlist_id: currentViewedPlaylist.id, mode: 'remove', track_id: trackId });
        if (res.status === 'success') {
            const ids = String(currentViewedPlaylist.song_ids).split(',').filter(Boolean).map(Number).filter(id => id !== trackId);
            currentViewedPlaylist.song_ids = ids.join(',');
            renderPlaylistPageContent();
        } else alert(res.message || t('err_delete'));
    }

    // ── Wikipedia biography: public REST API (open CORS), queried
    // directly from the browser — involves no call to api.php.
    // A token prevents a late response from an old lookup from overwriting
    // the bio of the artist displayed in the meantime.
    async function fetchArtistBio(name) {
        const bioEl = document.getElementById('artist-page-bio');
        const myToken = ++artistBioToken;
        bioEl.innerText = t('loading_bio');
        const lang = LANG === 'en' ? 'en' : 'fr';
        try {
            let data = await fetchWikipediaSummary(name, lang);
            if ((!data || !data.extract) && lang !== 'en') data = await fetchWikipediaSummary(name, 'en');
            if (myToken !== artistBioToken) return;
            if (data && data.extract) {
                const pageUrl = data.content_urls?.desktop?.page;
                bioEl.innerHTML = escapeHTML(data.extract) +
                    (pageUrl ? ` <a href="${escapeHTML(pageUrl)}" target="_blank" rel="noopener noreferrer">${escapeHTML(t('wikipedia_link'))}</a>` : '');
            } else {
                bioEl.innerText = t('no_bio_available');
            }
        } catch (e) {
            if (myToken === artistBioToken) bioEl.innerText = t('no_bio_available');
        }
    }

    async function fetchWikipediaSummary(name, lang) {
        try {
            const res = await fetch(`https://${lang}.wikipedia.org/api/rest_v1/page/summary/${encodeURIComponent(name)}`);
            if (res.ok) {
                const data = await res.json();
                if (data.type !== 'disambiguation' && data.extract) return data;
            }
        } catch (e) { /* retry via the search below */ }
        // Ambiguous name (disambiguation page) or not found as is: we look
        // among the results for the first matching "standard" page
        // (e.g. "Drake" → disambiguation page → "Drake (musician)").
        try {
            const searchRes = await fetch(`https://${lang}.wikipedia.org/w/rest.php/v1/search/page?q=${encodeURIComponent(name)}&limit=5`);
            if (!searchRes.ok) return null;
            const searchData = await searchRes.json();
            for (const page of (searchData.pages || [])) {
                if (page.title.toLowerCase() === name.toLowerCase()) continue;
                const sumRes = await fetch(`https://${lang}.wikipedia.org/api/rest_v1/page/summary/${encodeURIComponent(page.title)}`);
                if (!sumRes.ok) continue;
                const sumData = await sumRes.json();
                if (sumData.type !== 'disambiguation' && sumData.extract) return sumData;
            }
        } catch (e) { /* no bio available */ }
        return null;
    }

    function filterAndSortTracks() {
        const term  = document.getElementById('searchInput').value.toLowerCase();
        const sort  = document.getElementById('sortSelect').value;
        renderSearchCategoryResults(term.trim());
        let filtered = ALL_MUSIC_DATA.filter(t => {
            if (hiddenGenres.includes(t.genre || 'Autre')) return false;
            return t.title.toLowerCase().includes(term) || t.artist.toLowerCase().includes(term);
        });
        filtered.sort((a, b) => {
            if (sort === 'popular')    return (b.play_count||0) - (a.play_count||0) || b.id - a.id;
            if (sort === 'date_desc')  return b.id - a.id;
            if (sort === 'date_asc')   return a.id - b.id;
            if (sort === 'alpha_asc')  return a.title.localeCompare(b.title);
            if (sort === 'alpha_desc') return b.title.localeCompare(a.title);
            if (sort === 'artist')     return a.artist.localeCompare(b.artist);
            return 0;
        });
        CURRENT_VIEW_DATA = filtered; renderedCount = 0; renderTracksChunk();
    }

    // Global search: groups results by category, in the order
    // Artists → Albums → Songs (the latter being the filtered list
    // rendered by renderTracksChunk just below).
    function renderSearchCategoryResults(term) {
        const artistsSection = document.getElementById('search-artists-section');
        const albumsSection  = document.getElementById('search-albums-section');
        const tracksTitle    = document.getElementById('tracks-section-title');

        if (!term) {
            artistsSection.style.display = 'none';
            albumsSection.style.display = 'none';
            tracksTitle.innerText = t('section_all_tracks');
            return;
        }

        const artistsMap = new Map();
        ALL_MUSIC_DATA.forEach(tr => {
            splitArtistNames(fixEntities(tr.artist)).forEach(n => {
                const key = n.toLowerCase();
                if (!key.includes(term)) return;
                if (!artistsMap.has(key)) artistsMap.set(key, { name: n, cover: tr.cover_url, latestId: tr.id });
                const entry = artistsMap.get(key);
                if (tr.id > entry.latestId) { entry.latestId = tr.id; entry.cover = tr.cover_url; }
            });
        });
        const artists = [...artistsMap.values()].sort((a, b) => a.name.localeCompare(b.name));
        const artistsGrid = document.getElementById('search-artists-grid');
        if (artists.length) {
            artistsGrid.innerHTML = artists.map(a => `<div class="carousel-card artist-card" onclick="showArtistPage('${jsAttrEscape(a.name)}')">
                <div class="cc-cover-wrap"><img src="${escapeHTML(a.cover)}" loading="lazy" alt="" class="cc-loading" onload="this.classList.remove('cc-loading')" onerror="this.classList.remove('cc-loading');this.onerror=null;this.src='covers/default.png'"></div>
                <div class="cc-title">${escapeHTML(a.name)}</div>
            </div>`).join('');
            artistsSection.style.display = '';
        } else {
            artistsGrid.innerHTML = '';
            artistsSection.style.display = 'none';
        }

        const albumsMap = new Map();
        ALL_MUSIC_DATA.forEach(tr => {
            if (!tr.album_id) return;
            const name = fixEntities(tr.album);
            if (!name.toLowerCase().includes(term)) return;
            const id = parseInt(tr.album_id);
            if (!albumsMap.has(id)) albumsMap.set(id, { id, name, cover: tr.cover_url, latestId: tr.id, artists: new Map() });
            const entry = albumsMap.get(id);
            if (tr.id > entry.latestId) { entry.latestId = tr.id; entry.cover = tr.cover_url; }
            splitArtistNames(fixEntities(tr.artist)).forEach(n => {
                const key = n.toLowerCase();
                if (!entry.artists.has(key)) entry.artists.set(key, n);
            });
        });
        const albums = [...albumsMap.values()].sort((a, b) => a.name.localeCompare(b.name));
        const albumsGrid = document.getElementById('search-albums-grid');
        if (albums.length) {
            albumsGrid.innerHTML = albums.map(a => {
                const cover = 'api.php?action=album_cover&q=' + a.id + '&t=' + Date.now();
                const artistHTML = [...a.artists.values()].map(n => escapeHTML(n)).join(', ');
                return `<div class="carousel-card" onclick="showAlbumPage(${a.id})">
                    <div class="cc-cover-wrap"><img src="${cover}" loading="lazy" alt="" class="cc-loading" onload="this.classList.remove('cc-loading')" onerror="this.classList.remove('cc-loading');this.onerror=null;this.src='covers/default.png'"></div>
                    <div class="cc-title">${escapeHTML(a.name)}</div>
                    <div class="cc-artist">${artistHTML}</div>
                </div>`;
            }).join('');
            albumsSection.style.display = '';
        } else {
            albumsGrid.innerHTML = '';
            albumsSection.style.display = 'none';
        }

        tracksTitle.innerText = t('songs_title');
    }

    // tracks === null: animated skeleton (fixed number of cards, while still
    // waiting for the data, e.g. during the action=recommend call).
    function trackCardsHtml(tracks) {
        if (tracks === null) {
            return Array.from({ length: 6 }, () => `<div class="carousel-card">
                <div class="cc-cover-wrap"><div class="cc-cover-skeleton"></div></div>
                <div class="cc-title-skeleton"></div>
                <div class="cc-artist-skeleton"></div>
            </div>`).join('');
        }
        // playTrackFromIds (not playTrackById): a click on a carousel
        // (popular, hidden gems, recommended...) must start a dynamic
        // contextual queue seeded on THAT specific track (see
        // buildDynamicQueue), not replay CURRENT_VIEW_DATA (main list,
        // sorted/searched) which often has nothing to do with this carousel.
        const idsAttr = tracks.map(tr => tr.id).join(',');
        return tracks.map(t => {
            const safeTitle  = escapeHTML(fixEntities(t.title));
            const artistHTML = artistLinksHTML(t.artist);
            const safeCover  = escapeHTML(t.cover_url);
            return `<div class="carousel-card" onclick="playTrackFromIds(${t.id},'${idsAttr}')">
                <div class="cc-cover-wrap"><img src="${safeCover}" loading="lazy" alt="" class="cc-loading" onload="this.classList.remove('cc-loading')" onerror="this.classList.remove('cc-loading');this.onerror=null;this.src='covers/default.png'"></div>
                <div class="cc-title">${safeTitle}</div>
                <div class="cc-artist">${artistHTML}</div>
            </div>`;
        }).join('');
    }

    // tracks === null shows an animated skeleton instead (section never
    // hidden in this case, unlike an empty list once loaded).
    function renderCarouselSection(id, title, tracks) {
        if (tracks !== null && !tracks.length) return '';
        return `<section class="carousel-section">
            <div class="carousel-header">
                <h3 class="carousel-title">${title}</h3>
                <div class="carousel-nav">
                    <button class="carousel-nav-btn" onclick="scrollCarousel('${id}',-1)" title="${t('prev')}">‹</button>
                    <button class="carousel-nav-btn" onclick="scrollCarousel('${id}',1)" title="${t('next')}">›</button>
                </div>
            </div>
            <div class="carousel-track" id="${id}">${trackCardsHtml(tracks)}</div>
        </section>`;
    }

    function scrollCarousel(id, dir) {
        const track = document.getElementById(id);
        if (track) track.scrollBy({ left: dir * (track.clientWidth * 0.8), behavior: 'smooth' });
    }

    let selectedHomeGenre = null;

    function renderGenrePillsCarousel() {
        const track = document.getElementById('genre-pills-track');
        if (!track) return;
        const genres = [...new Set(
            ALL_MUSIC_DATA
                .map(t => t.genre || 'Autre')
                .filter(g => !hiddenGenres.includes(g))
        )].sort((a, b) => a.localeCompare(b));

        if (!genres.length) { track.innerHTML = ''; return; }

        const allPill = `<div class="genre-pill${selectedHomeGenre === null ? ' active' : ''}" onclick="filterHomeByGenre(null)">${t('all')}</div>`;
        const pills = genres.map(g => {
            // The filter compares selectedHomeGenre to the raw value of t.genre:
            // the onclick argument must therefore stay undecoded, only the displayed label
            // goes through fixEntities().
            const matchArg = jsAttrEscape(g);
            const displayGenre = escapeHTML(fixEntities(g));
            const isActive = selectedHomeGenre === g;
            return `<div class="genre-pill${isActive ? ' active' : ''}" onclick="filterHomeByGenre('${matchArg}')">${displayGenre}</div>`;
        }).join('');
        track.innerHTML = allPill + pills;
    }

    function filterHomeByGenre(genre) {
        selectedHomeGenre = genre;
        renderGenrePillsCarousel();
        renderHomeCarousels();
    }

    // Caches the recommendations computed on the server (genre/
    // artist/album affinity derived from the user's playlists + popularity) to
    // avoid an API call on every genre filter change on the home page.
    let recommendedCache = null;

    async function fetchRecommended() {
        if (recommendedCache) return recommendedCache;
        try {
            const res = await apiCall('recommend', { limit: 30 });
            recommendedCache = Array.isArray(res) ? res : [];
        } catch (e) {
            recommendedCache = [];
        }
        return recommendedCache;
    }

    async function renderHomeCarousels() {
        const container = document.getElementById('home-carousels');
        if (!container) return;
        let visible = ALL_MUSIC_DATA.filter(t => !hiddenGenres.includes(t.genre || 'Autre'));
        if (selectedHomeGenre !== null) visible = visible.filter(t => (t.genre || 'Autre') === selectedHomeGenre);
        if (!visible.length) { container.innerHTML = ''; return; }

        // Popular: already sorted by play_count DESC by the API.
        const popular = visible.slice(0, 15);

        // Hidden gems: random selection among the 30 least-played tracks.
        const leastPopularPool = [...visible]
            .sort((a, b) => (a.play_count||0) - (b.play_count||0))
            .slice(0, 30);
        const hiddenGems = shuffleArray([...leastPopularPool]).slice(0, 15);

        function buildRecommended(list) {
            let recommended = list.filter(t => !hiddenGenres.includes(t.genre || 'Autre'));
            if (selectedHomeGenre !== null) recommended = recommended.filter(t => (t.genre || 'Autre') === selectedHomeGenre);
            recommended = recommended.slice(0, 15);
            if (!recommended.length) recommended = shuffleArray([...visible]).slice(0, 15);
            return recommended;
        }

        // Popular/Hidden gems are derived locally (no network wait
        // needed) and show up right away; Recommended shows
        // an animated skeleton during the action=recommend call, instead of
        // blocking the display of all three carousels behind that single call.
        // If the cache is already warm (e.g. coming back from a genre filter),
        // no need for a skeleton, the real list is already available.
        const cacheReady = recommendedCache !== null;
        container.innerHTML =
            renderCarouselSection('carousel-recommended', t('recommended_for_you'), cacheReady ? buildRecommended(recommendedCache) : null) +
            renderCarouselSection('carousel-popular', t('popular'), popular) +
            renderCarouselSection('carousel-hidden-gems', t('hidden_gems'), hiddenGems);

        if (cacheReady) return;

        const allRecommended = await fetchRecommended();

        // The page may have been left while waiting.
        const track = document.getElementById('carousel-recommended');
        if (!track) return;
        track.innerHTML = trackCardsHtml(buildRecommended(allRecommended));
    }

    // ── "Albums" grid: one album per distinct album_id, derived from
    // ALL_MUSIC_DATA (like the artist/album pages) — no separate API call.
    // Shared sort for the Albums/Artists grids: 'name_asc' (default, historical
    // behavior unchanged), 'name_desc', or 'recent' (approximated by the highest
    // track id of the album/artist — no date added is
    // exposed on the client, but ids are assigned in upload order so they serve
    // as a reliable proxy for recency).
    function sortGridEntries(items, sortMode) {
        if (sortMode === 'name_desc') return items.sort((a, b) => b.sortName.localeCompare(a.sortName));
        if (sortMode === 'recent')    return items.sort((a, b) => b.latestId - a.latestId);
        return items.sort((a, b) => a.sortName.localeCompare(b.sortName));
    }

    function renderAlbumsGrid() {
        const container = document.getElementById('albums-grid');
        if (!container) return;

        const albumsMap = new Map();
        ALL_MUSIC_DATA.forEach(tr => {
            if (!tr.album_id) return;
            const id = parseInt(tr.album_id);
            if (!albumsMap.has(id)) albumsMap.set(id, { id, name: tr.album, sortName: fixEntities(tr.album), count: 0, latestId: tr.id, artists: new Map() });
            const entry = albumsMap.get(id);
            entry.count++;
            if (tr.id > entry.latestId) entry.latestId = tr.id;
            splitArtistNames(fixEntities(tr.artist)).forEach(n => {
                const key = n.toLowerCase();
                if (!entry.artists.has(key)) entry.artists.set(key, n);
            });
        });

        if (!albumsMap.size) {
            container.innerHTML = `<div style="padding:40px;text-align:center;color:var(--text-muted);">${t('no_albums_found')}</div>`;
            return;
        }

        const searchEl = document.getElementById('albumSearchInput');
        const sortEl   = document.getElementById('albumSortSelect');
        const term     = searchEl ? searchEl.value.trim().toLowerCase() : '';
        const sortMode = sortEl ? sortEl.value : 'name_asc';

        let albums = [...albumsMap.values()];
        if (term) albums = albums.filter(a => a.sortName.toLowerCase().includes(term));
        albums = sortGridEntries(albums, sortMode);
        if (!albums.length) {
            container.innerHTML = `<div style="padding:40px;text-align:center;color:var(--text-muted);">${t('no_albums_found')}</div>`;
            return;
        }
        container.innerHTML = albums.map(a => {
            const safeName   = escapeHTML(fixEntities(a.name));
            const artistHTML = [...a.artists.values()]
                .map(n => `<span class="artist-link" onclick="event.stopPropagation();showArtistPage('${jsAttrEscape(n)}')">${escapeHTML(n)}</span>`)
                .join(', ');
            const cover = 'api.php?action=album_cover&q=' + a.id + '&t=' + Date.now();
            return `<div class="carousel-card" onclick="showAlbumPage(${a.id})">
                <div class="cc-cover-wrap"><img src="${cover}" loading="lazy" alt="" class="cc-loading" onload="this.classList.remove('cc-loading')" onerror="this.classList.remove('cc-loading');this.onerror=null;this.src='covers/default.png'"></div>
                <div class="cc-title">${safeName}</div>
                <div class="cc-artist">${artistHTML}</div>
            </div>`;
        }).join('');
    }

    // ── "Artists" grid: one artist per distinct name (featurings split
    // via splitArtistNames), derived from ALL_MUSIC_DATA like the albums grid.
    // The displayed cover is the one from the artist's most recent track,
    // same convention as showArtistPage().
    function renderArtistsGrid() {
        const container = document.getElementById('artists-grid');
        if (!container) return;

        const artistsMap = new Map();
        ALL_MUSIC_DATA.forEach(tr => {
            splitArtistNames(fixEntities(tr.artist)).forEach(n => {
                const key = n.toLowerCase();
                if (!artistsMap.has(key)) artistsMap.set(key, { name: n, sortName: n, count: 0, cover: tr.cover_url, latestId: tr.id });
                const entry = artistsMap.get(key);
                entry.count++;
                if (tr.id > entry.latestId) { entry.latestId = tr.id; entry.cover = tr.cover_url; }
            });
        });

        if (!artistsMap.size) {
            container.innerHTML = `<div style="padding:40px;text-align:center;color:var(--text-muted);">${t('no_artists_found')}</div>`;
            return;
        }

        const searchEl = document.getElementById('artistSearchInput');
        const sortEl   = document.getElementById('artistSortSelect');
        const term     = searchEl ? searchEl.value.trim().toLowerCase() : '';
        const sortMode = sortEl ? sortEl.value : 'name_asc';

        let artists = [...artistsMap.values()];
        if (term) artists = artists.filter(a => a.name.toLowerCase().includes(term));
        artists = sortGridEntries(artists, sortMode);
        if (!artists.length) {
            container.innerHTML = `<div style="padding:40px;text-align:center;color:var(--text-muted);">${t('no_artists_found')}</div>`;
            return;
        }
        container.innerHTML = artists.map(a => {
            const safeName = escapeHTML(a.name);
            return `<div class="carousel-card artist-card" onclick="showArtistPage('${jsAttrEscape(a.name)}')">
                <div class="cc-cover-wrap"><img src="${escapeHTML(a.cover)}" loading="lazy" alt="" class="cc-loading" onload="this.classList.remove('cc-loading')" onerror="this.classList.remove('cc-loading');this.onerror=null;this.src='covers/default.png'"></div>
                <div class="cc-title">${safeName}</div>
            </div>`;
        }).join('');
    }

    const _observer = new IntersectionObserver(entries => {
        if (entries[0].isIntersecting && renderedCount < CURRENT_VIEW_DATA.length) renderTracksChunk();
    }, { rootMargin: '200px' });

    document.addEventListener('DOMContentLoaded', () => {
        const savedVol = localStorage.getItem('purpleMusicVolume');
        updateVolume(savedVol !== null ? savedVol : 1);
        document.querySelectorAll('.genre-filter-cb').forEach(cb => {
            if (hiddenGenres.includes(cb.dataset.genre)) cb.checked = true;
        });
        const hideAdminCb = document.getElementById('hideAdminPanelToggle');
        if (hideAdminCb) hideAdminCb.checked = hideAdminPanel;
        applyHideAdminPanel();
        const hideEditDeleteCb = document.getElementById('hideEditDeleteToggle');
        if (hideEditDeleteCb) hideEditDeleteCb.checked = hideEditDeleteButtons;
        _observer.observe(document.getElementById('load-more-trigger'));
        filterAndSortTracks();
        renderGenrePillsCarousel();
        renderHomeCarousels();
        renderAlbumsGrid();
        renderArtistsGrid();
        const p = new URLSearchParams(window.location.search);
        if (p.get('page') === 'artist-page' && p.get('artist')) showArtistPage(p.get('artist'), false);
        else if (p.get('page') === 'album-page' && p.get('album')) showAlbumPage(parseInt(p.get('album')), false);
        else if (p.get('page') === 'playlist-page' && p.get('playlist')) showPlaylistPage(parseInt(p.get('playlist')), false);
        else if (p.get('page') === 'history') showHistoryPage(false);
        else if (p.get('page')) showSection(p.get('page'), false);
        if (p.get('list')) currentPlaylistId = p.get('list');
        if (p.get('v'))    playTrackById(p.get('v'), false);

        renderThemeSwatches();
        const savedTheme = localStorage.getItem('theme_base');
        if (savedTheme === 'adaptive') { if (adaptiveIdle) applyAdaptiveFallback(); }
        else if (savedTheme) applyTheme(savedTheme);
        else applyThemeColors(null);
        renderEqSliders();

        document.getElementById('upload-form').addEventListener('submit', handleUploadSubmit);
        document.getElementById('edit-track-form').addEventListener('submit', handleEditTrackSubmit);
        document.getElementById('playlist-form').addEventListener('submit', handlePlaylistSubmit);
    });

    window.onpopstate = () => window.location.reload();

    function formatTime(s) {
        if (isNaN(s) || !isFinite(s)) return '0:00';
        return Math.floor(s/60) + ':' + String(Math.floor(s%60)).padStart(2,'0');
    }
    function toggleSidebar() {
        const collapsed = document.body.classList.toggle('sidebar-collapsed');
        localStorage.setItem('sidebar_collapsed', collapsed ? '1' : '0');
    }
    if (localStorage.getItem('sidebar_collapsed') === '1') document.body.classList.add('sidebar-collapsed');
    function openSmartPlayer() {
        const fp = document.getElementById('full-player');
        fp.classList.remove('closing');
        fp.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeFullPlayer() {
        const fp = document.getElementById('full-player');
        // Goes under #player-bar (see the .closing rule) while the
        // slide-down animation finishes, then returns to the normal z-index for the
        // next opening.
        fp.classList.add('closing');
        fp.classList.remove('active');
        document.body.style.overflow = 'auto';
        fp.addEventListener('transitionend', function onEnd(e) {
            if (e.propertyName !== 'top') return;
            fp.classList.remove('closing');
            fp.removeEventListener('transitionend', onEnd);
        });
    }
    function handlePlayerBarClick(e) {
        // We use composedPath() rather than e.target.closest(): some
        // buttons (masterPlay) replace their own innerHTML (play/
        // pause icon) on click, which detaches the target node from the DOM before
        // the event bubbles up here — closest() would then fail to
        // find the ancestor <button> and would wrongly trigger the toggle.
        const path = e.composedPath ? e.composedPath() : [e.target];
        const hitsControl = path.some(el => el.nodeType === 1 && (el.tagName === 'BUTTON' || el.tagName === 'INPUT' || (el.classList && el.classList.contains('pb-seek'))));
        if (hitsControl) return;
        if (document.getElementById('full-player').classList.contains('active')) closeFullPlayer();
        else openSmartPlayer();
    }

    function updateQueueUI() {
        const container = document.getElementById('fp-queue-list');
        if (!container) return;
        if (!queue.length) {
            container.innerHTML = `<p style="color:var(--text-muted);">${t('queue_empty')}</p>`;
            return;
        }
        container.innerHTML = '';
        queue.forEach((track, index) => {
            const div = document.createElement('div'); div.className = `queue-item ${index === currentIndex ? 'active' : ''}`;
            div.innerHTML = `
                <img src="${escapeHTML(track.cover_url)}" loading="lazy" style="width:36px;height:36px;border-radius:8px;object-fit:cover;">
                <div style="flex:1;overflow:hidden;">
                    <div style="font-size:.9em;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escapeHTML(fixEntities(track.title))}</div>
                    <div style="font-size:.75em;color:var(--text-muted);">${escapeHTML(fixEntities(track.artist))}</div>
                </div>
                ${index === currentIndex ? '<span style="color:var(--accent);font-size:1.5em;">•</span>' : ''}`;
            div.onclick = () => { currentIndex = index; loadTrack(true); };
            container.appendChild(div);
        });
    }

    // ── Library: clicking a track with no active search → the queue
    // is exactly CURRENT_VIEW_DATA (current sort/filter), frozen as
    // is and rotated to start on the clicked track; nothing is
    // appended after it. Active search → "sticking to the typed term"
    // matters less than listening to THE chosen track: we start
    // a dynamic contextual queue on it (see buildDynamicQueue),
    // exactly like clicking a track from a carousel/artist page.
    function playTrackById(id, autoPlay = true) {
        const term = document.getElementById('searchInput').value.trim();
        if (term) {
            const seed = CURRENT_VIEW_DATA.find(t => t.id == id) || ALL_MUSIC_DATA.find(t => t.id == id);
            if (seed) { buildDynamicQueue(seed, autoPlay); return; }
        }
        currentPlaylistId = null;
        queueMode = 'library';
        loopBoundary = null;
        queueAllowsContinuation = false;
        const startIdx = CURRENT_VIEW_DATA.findIndex(t => t.id == id);
        originalQueue = startIdx > 0
            ? [...CURRENT_VIEW_DATA.slice(startIdx), ...CURRENT_VIEW_DATA.slice(0, startIdx)]
            : [...CURRENT_VIEW_DATA];
        queue = isShuffle ? weightedAntiRepeatShuffle(originalQueue) : [...originalQueue];
        currentIndex = isShuffle ? queue.findIndex(t => t.id == id) : 0;
        if (currentIndex === -1) currentIndex = 0;
        loadTrack(autoPlay);
    }

    // ── "DJ" contextual queue: starts on seedTrack (immediate playback),
    // then tops up in the background with a batch mixing same artist/
    // related genre/discovery/random (buildContextualBatch). Used for
    // any playback whose starting point is ONE specific track rather
    // than an explicit list to follow (search, carousels, artist).
    async function buildDynamicQueue(seedTrack, autoPlay = true) {
        currentPlaylistId = null;
        queueMode = 'dynamic';
        loopBoundary = null;
        queueAllowsContinuation = true;
        usedTrackIds = new Set([seedTrack.id]);
        originalQueue = [seedTrack];
        queue = [seedTrack];
        currentIndex = 0;
        // loadTrack() triggers refillQueueIfNeeded() (the queue only contains
        // the seed, so it's under the threshold): the lock below prevents it from
        // running at the same time as the initial batch below and picking
        // the same tracks (duplicates).
        queueBatchInFlight = true;
        loadTrack(autoPlay);

        const batch = await buildContextualBatch(seedTrack, usedTrackIds, QUEUE_AHEAD_TARGET);
        queueBatchInFlight = false;
        if (!batch.length) return;
        batch.forEach(tr => usedTrackIds.add(tr.id));
        originalQueue = originalQueue.concat(batch);
        queue = queue.concat(isShuffle ? weightedAntiRepeatShuffle(batch) : batch);
        updateQueueUI();
    }

    // Variant for the carousels (popular, hidden gems, recommended...):
    // their cards only carry ids in their onclick attribute (not the
    // full track objects), so we resolve them from ALL_MUSIC_DATA.
    function playTrackFromIds(id, idsStr) {
        const seed = ALL_MUSIC_DATA.find(t => t.id === Number(id));
        if (seed) buildDynamicQueue(seed, true);
    }

    // ── Artist page: first the other tracks by THIS artist (in
    // the order they're shown on their page, like playTrackInAlbum),
    // then a generated contextual continuation once their catalog is
    // exhausted — rather than a "radio" mix that could have brought in
    // another artist from the 2nd track.
    function playTrackInArtist(id) {
        if (!currentArtistName) { playTrackById(id); return; }
        const norm = currentArtistName.trim().toLowerCase();
        const tracks = ALL_MUSIC_DATA.filter(tr => splitArtistNames(fixEntities(tr.artist)).some(n => n.toLowerCase() === norm));
        buildFixedQueueWithContinuation(tracks, { startId: id, autoPlay: true });
    }

    function playTrackInAlbum(id) {
        if (currentAlbumId == null) { playTrackById(id); return; }
        const tracks = ALL_MUSIC_DATA.filter(tr => parseInt(tr.album_id) === currentAlbumId).sort((a, b) => a.id - b.id);
        buildFixedQueueWithContinuation(tracks, { startId: id, autoPlay: true });
    }

    // ── History page: the list can't be derived from ALL_MUSIC_DATA
    // (chronological order specific to the user), so we replay from the
    // list cached by showHistoryPage(). No generated continuation:
    // it's browsing history, not a "radio" starting point.
    function playTrackInHistory(id) {
        if (!currentHistoryTracks.length) { playTrackById(id); return; }
        buildFixedQueueNoContinuation(currentHistoryTracks, { startId: id, autoPlay: true });
    }

    // ── A playlist is never topped up with a generated continuation:
    // its tracks are the whole queue, from start to end (or looping on
    // itself if repeat mode is on) — never any recommendations
    // appended after its last track.
    function playTrackInPlaylist(id) {
        if (!currentViewedPlaylist) { playTrackById(id); return; }
        const idList = String(currentViewedPlaylist.song_ids).split(',').map(Number).filter(Boolean);
        const tracks = idList.map(tid => ALL_MUSIC_DATA.find(t => t.id === tid)).filter(Boolean);
        if (!tracks.length) return;
        buildFixedQueueNoContinuation(tracks, { startId: id, autoPlay: true, playlistId: currentViewedPlaylist.id });
    }

    async function playPlaylist(ids, pId = null, forceShuffle = null) {
        const idList = String(ids).split(',').map(Number).filter(Boolean);
        let data = idList.map(id => ALL_MUSIC_DATA.find(t => t.id === id)).filter(Boolean);
        if (hiddenGenres.length) data = data.filter(t => !hiddenGenres.includes(t.genre || 'Autre'));
        if (!data.length) { alert(t('no_music_available')); return; }
        if (forceShuffle !== null) {
            isShuffle = forceShuffle;
            document.getElementById('shuffleBtn').classList.toggle('active', isShuffle);
            document.getElementById('fp-shuffleBtn').classList.toggle('active', isShuffle);
        }
        buildFixedQueueNoContinuation(data, { autoPlay: true, playlistId: pId });
    }

    // Builds a "fixed" queue (order of an explicit list: playlist or
    // history) without ever appending a generated continuation. `startId`
    // missing = we start at index 0 of the queue (possibly shuffled),
    // like a playlist's "Play" button; present = we start/jump to
    // exactly that track, like clicking a specific track.
    function buildFixedQueueNoContinuation(tracks, opts = {}) {
        const { startId = null, autoPlay = true, playlistId = null } = opts;
        if (!tracks.length) return;
        let rotated = tracks;
        if (startId != null) {
            const startIdx = tracks.findIndex(t => t.id == startId);
            rotated = startIdx > 0 ? [...tracks.slice(startIdx), ...tracks.slice(0, startIdx)] : tracks;
        }
        currentPlaylistId = playlistId;
        queueMode = 'fixed';
        loopBoundary = null;
        queueAllowsContinuation = false;
        usedTrackIds = new Set(rotated.map(t => t.id));
        originalQueue = [...rotated];
        queue = isShuffle ? weightedAntiRepeatShuffle(originalQueue) : [...originalQueue];
        if (startId != null) {
            currentIndex = isShuffle ? queue.findIndex(t => t.id == startId) : 0;
            if (currentIndex === -1) currentIndex = 0;
        } else {
            currentIndex = 0;
        }
        loadTrack(autoPlay);
    }

    // ── Album playback: the queue is the album's exact content (in
    // order or shuffled among themselves), followed by a generated contextual
    // continuation (buildContextualBatch, seeded on the album's last
    // track) so the music keeps going instead of stopping. Repeat-queue
    // mode only loops over the album's tracks (loopBoundary),
    // never over the continuation — see nextTrack().
    async function buildFixedQueueWithContinuation(contextTracks, opts = {}) {
        const { startId = null, autoPlay = true } = opts;
        if (!contextTracks.length) return;
        let rotated = contextTracks;
        if (startId != null) {
            const startIdx = contextTracks.findIndex(t => t.id == startId);
            rotated = startIdx > 0 ? [...contextTracks.slice(startIdx), ...contextTracks.slice(0, startIdx)] : contextTracks;
        }
        currentPlaylistId = null;
        queueMode = 'fixed';
        loopBoundary = rotated.length;
        queueAllowsContinuation = true;
        usedTrackIds = new Set(rotated.map(t => t.id));
        originalQueue = [...rotated];
        queue = isShuffle ? weightedAntiRepeatShuffle(originalQueue) : [...originalQueue];
        if (startId != null) {
            currentIndex = isShuffle ? queue.findIndex(t => t.id == startId) : 0;
            if (currentIndex === -1) currentIndex = 0;
        } else {
            currentIndex = 0;
        }
        // See buildDynamicQueue: prevents refillQueueIfNeeded (triggered by
        // loadTrack) from running in parallel with the continuation batch below.
        queueBatchInFlight = true;
        loadTrack(autoPlay);

        const seed = rotated[rotated.length - 1];
        const batch = await buildContextualBatch(seed, usedTrackIds, QUEUE_AHEAD_TARGET);
        queueBatchInFlight = false;
        if (!batch.length) return;
        batch.forEach(tr => usedTrackIds.add(tr.id));
        originalQueue = originalQueue.concat(batch);
        queue = queue.concat(isShuffle ? weightedAntiRepeatShuffle(batch) : batch);
        updateQueueUI();
    }

    function playAlbum(albumId, forceShuffle = null) {
        albumId = parseInt(albumId);
        const albumTracks = ALL_MUSIC_DATA.filter(tr => parseInt(tr.album_id) === albumId).sort((a, b) => a.id - b.id);
        if (!albumTracks.length) return;
        if (forceShuffle !== null) {
            isShuffle = forceShuffle;
            document.getElementById('shuffleBtn').classList.toggle('active', isShuffle);
            document.getElementById('fp-shuffleBtn').classList.toggle('active', isShuffle);
        }
        buildFixedQueueWithContinuation(albumTracks, { autoPlay: true });
    }

    // ── Playing an artist's tracks via the "Play"/"Shuffle" button:
    // same logic as playAlbum — their catalog first (shuffled among themselves
    // if requested), then a generated contextual continuation once
    // exhausted, instead of a "radio" mix right from the start.
    function playArtist(artistName, forceShuffle = null) {
        if (!artistName) return;
        const norm = artistName.trim().toLowerCase();
        const artistTracks = ALL_MUSIC_DATA.filter(tr => splitArtistNames(fixEntities(tr.artist)).some(n => n.toLowerCase() === norm));
        if (!artistTracks.length) return;
        if (forceShuffle !== null) {
            isShuffle = forceShuffle;
            document.getElementById('shuffleBtn').classList.toggle('active', isShuffle);
            document.getElementById('fp-shuffleBtn').classList.toggle('active', isShuffle);
        }
        buildFixedQueueWithContinuation(artistTracks, { autoPlay: true });
    }

    // ── Listen counting: a track is only counted (play_count +
    // listen_history) after MIN_LISTEN_SECONDS of actual playback. Time
    // is accumulated from timeupdate deltas; a jump of more than one
    // second (seeking in the progress bar) is not counted.
    const MIN_LISTEN_SECONDS = 5;
    let listenTrack = null, listenSeconds = 0, listenLastTime = 0, listenCounted = false;

    function resetListenTracking(track) {
        listenTrack = track; listenSeconds = 0; listenLastTime = 0; listenCounted = false;
    }

    function trackListenProgress() {
        const now = audio.currentTime, delta = now - listenLastTime;
        listenLastTime = now;
        if (!listenTrack || listenCounted || audio.paused || delta <= 0 || delta > 1) return;
        listenSeconds += delta;
        if (listenSeconds < MIN_LISTEN_SECONDS) return;
        listenCounted = true;
        const track = listenTrack;
        apiCall('increment_play', { track_id: track.id }).catch(() => {});
        track.play_count = (parseInt(track.play_count) || 0) + 1;
        const g = ALL_MUSIC_DATA.find(t => t.id == track.id); if (g && g !== track) g.play_count = track.play_count;
    }

    function loadTrack(autoPlay = true) {
        if (!queue[currentIndex]) return;
        const track = queue[currentIndex]; audio.src = track.stream_url;
        resetListenTracking(track);

        playTitle.innerText = fixEntities(track.title);
        playCover.src       = track.cover_url;
        playStatus.innerHTML = artistLinksHTML(track.artist) + albumLinkHTML(track);
        updateAdaptiveTheme(track);

        const fpTitle = document.getElementById('fp-title');
        fpTitle.innerHTML = `<span id="fp-title-text">${escapeHTML(fixEntities(track.title))}</span>`;
        document.getElementById('fp-artist').innerHTML = artistLinksHTML(track.artist) + albumLinkHTML(track);
        document.getElementById('fp-cover').src = track.cover_url;
        document.getElementById('fp-bb-cover').src = track.cover_url;
        const bgImg = document.getElementById('fp-bg-img');
        bgImg.classList.remove('loaded');
        bgImg.onload = () => bgImg.classList.add('loaded');
        bgImg.src = track.cover_url;

        const titleSpan = document.getElementById('fp-title-text');
        titleSpan.classList.remove('scrolling-active');
        if (titleSpan.scrollWidth > fpTitle.clientWidth) titleSpan.classList.add('scrolling-active');

        ['curr-time','fp-curr-time'].forEach(id => document.getElementById(id).innerText = '0:00');
        ['total-time','fp-total-time'].forEach(id => document.getElementById(id).innerText = '0:00');
        ['progress-bar','fp-progress-bar'].forEach(id => document.getElementById(id).style.width = '0%');

        if ('mediaSession' in navigator) {
            navigator.mediaSession.metadata = new MediaMetadata({
                title: fixEntities(track.title), artist: fixEntities(track.artist) || 'Purple Music',
                artwork: [{ src: track.cover_url, sizes: '96x96', type: 'image/png' }]
            });
        }
        updateUrl();
        if (lyricsVisible) fetchLyrics(track); else { currentParsedLyrics = []; }
        if (autoPlay) {
            initAudioGraph();
            if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume();
            audio.play().catch(e => console.error(e));
        } else {
            audio.pause();
        }
        updateQueueUI();
        refillQueueIfNeeded();
    }

    audio.onloadedmetadata = () => {
        const t = formatTime(audio.duration);
        document.getElementById('total-time').innerText = t;
        document.getElementById('fp-total-time').innerText = t;
    };

    function nextTrack() {
        if (loopMode === 2) { audio.currentTime = 0; audio.play(); return; }
        // In "repeat queue" mode, a context with a continuation (album) must
        // only loop over its own tracks (loopBoundary), never
        // over the generated continuation appended after — see buildFixedQueueWithContinuation.
        const effectiveLength = (loopMode === 1 && loopBoundary != null) ? loopBoundary : queue.length;
        if (currentIndex < effectiveLength - 1) { currentIndex++; loadTrack(true); }
        else if (loopMode === 1) { currentIndex = 0; loadTrack(true); }
        else {
            audio.pause(); audio.currentTime = 0;
        }
    }
    // ── Spotify/YouTube Music-style "previous" button/key: within the first 5
    // seconds of a track, it really acts as "previous" (we
    // go back in the queue); after that, we assume the user
    // wants to replay the current track rather than skip to the previous one —
    // so it just goes back to 0. If there is no previous track (already at the
    // very start of the queue), we also fall back to simply going back to 0.
    const PREV_TRACK_RESTART_THRESHOLD = 5;
    function prevTrack() {
        if (audio.currentTime >= PREV_TRACK_RESTART_THRESHOLD || currentIndex <= 0) {
            audio.currentTime = 0;
            return;
        }
        currentIndex--;
        loadTrack(true);
    }

    // Without these handlers, the previous/next media keys (keyboard,
    // Bluetooth headphones, system media notification) do
    // absolutely nothing: unlike play/pause, a browser has no
    // default behavior for previoustrack/nexttrack as long as no
    // handler is registered — this wasn't wired up at all before.
    if ('mediaSession' in navigator) {
        navigator.mediaSession.setActionHandler('previoustrack', prevTrack);
        navigator.mediaSession.setActionHandler('nexttrack', nextTrack);
    }

    function togglePlay() {
        if (!audio.src) return;
        initAudioGraph();
        if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume();
        if (audio.paused) audio.play(); else audio.pause();
    }

    // YouTube/Spotify-style play/pause keyboard shortcuts (Space or K),
    // disabled while typing text so they don't interfere with search,
    // modal forms, etc.
    document.addEventListener('keydown', (e) => {
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        if (e.key !== ' ' && e.key.toLowerCase() !== 'k') return;
        const ae = document.activeElement;
        const tag = ae && ae.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (ae && ae.isContentEditable)) return;
        e.preventDefault();
        togglePlay();
    });

    function toggleShuffle() {
        isShuffle = !isShuffle;
        document.getElementById('shuffleBtn').classList.toggle('active', isShuffle);
        document.getElementById('fp-shuffleBtn').classList.toggle('active', isShuffle);
        if (queue.length) {
            // The current track (and everything already played before it)
            // must never move when shuffle is toggled — only what's
            // still upcoming is reordered. Reshuffling the entire
            // queue (as before) moved the current track anywhere
            // in it, making it look like it "slid down" the queue.
            const played = queue.slice(0, currentIndex + 1);
            const upcomingIds = new Set(queue.slice(currentIndex + 1).map(t => t.id));
            // Starts again from the canonical order (originalQueue) for the upcoming
            // portion: a shuffled→normal→shuffled round trip lands on a
            // consistent order rather than compounding shuffles on top of each other.
            const upcomingCanonical = originalQueue.filter(t => upcomingIds.has(t.id));
            // A real shuffle (Fisher-Yates) rather than just re-displaying
            // sorted by popularity, with an anti-artist-repeat pass:
            // see rule 8 (shuffle must really feel shuffled).
            const upcoming = isShuffle ? weightedAntiRepeatShuffle(upcomingCanonical) : upcomingCanonical;
            queue = [...played, ...upcoming];
            updateQueueUI();
        }
    }

    function toggleLoop() {
        loopMode = (loopMode + 1) % 3;
        const active = loopMode > 0;
        const single = loopMode === 2;
        const label  = single ? t('repeat_track') : (active ? t('repeat_queue') : t('normal_playback'));
        document.querySelectorAll('#loopBtn, #fp-loopBtn').forEach(btn => {
            btn.classList.toggle('active', active);
            btn.title = label;
            btn.setAttribute('aria-label', label);
            btn.querySelector('.loop-badge').classList.toggle('show', single);
        });
    }

    function shuffleArray(arr) {
        for (let i = arr.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [arr[i], arr[j]] = [arr[j], arr[i]];
        }
        return arr;
    }

    audio.ontimeupdate = () => {
        const pct = (audio.currentTime / audio.duration) * 100 || 0;
        document.getElementById('progress-bar').style.width = pct + '%';
        document.getElementById('fp-progress-bar').style.width = pct + '%';
        document.getElementById('curr-time').innerText = formatTime(audio.currentTime);
        document.getElementById('fp-curr-time').innerText = formatTime(audio.currentTime);
        if (audio.duration) {
            document.getElementById('total-time').innerText = formatTime(audio.duration);
            document.getElementById('fp-total-time').innerText = formatTime(audio.duration);
        }
        updateLyricsHighlight();
        trackListenProgress();
    };
    audio.onended = nextTrack;

    progressArea.onclick = e => {
        const r = progressArea.getBoundingClientRect(); audio.currentTime = ((e.clientX - r.left) / r.width) * audio.duration;
    };
    document.getElementById('fp-progress-area').onclick = e => {
        const r = document.getElementById('fp-progress-area').getBoundingClientRect();
        audio.currentTime = ((e.clientX - r.left) / r.width) * audio.duration;
    };

    function showSection(id, doUrl = true) {
        if (document.getElementById('full-player').classList.contains('active')) closeFullPlayer();
        ['accueil', 'playlists', 'albums', 'artists', 'artist-page', 'album-page', 'playlist-page', 'history', 'settings-page', 'admin-page'].forEach(sectionId => {
            const el = document.getElementById(sectionId);
            if (sectionId === id) {
                el.style.display = 'block';
                el.classList.remove('view-fade');
                void el.offsetWidth;
                el.classList.add('view-fade');
            } else {
                el.style.display = 'none';
            }
        });
        document.querySelectorAll('nav span').forEach(s => s.classList.remove('active'));
        document.getElementById('nav-' + id)?.classList.add('active');
        document.querySelectorAll('.mob-nav-item').forEach(s => s.classList.remove('active'));
        document.getElementById('mob-nav-' + id)?.classList.add('active');
        if (id !== 'artist-page') currentArtistName = null;
        if (id !== 'album-page') currentAlbumId = null;
        if (id !== 'playlist-page') currentViewedPlaylist = null;
        window.scrollTo(0, 0); currentSection = id; if (doUrl) updateUrl();
    }

    function openModal(id) {
        const modal = document.getElementById(id);
        modal.style.display = 'block';
        requestAnimationFrame(() => modal.classList.add('show'));
    }
    function closeModal(id) {
        const modal = document.getElementById(id);
        modal.classList.remove('show');
        setTimeout(() => { if (!modal.classList.contains('show')) modal.style.display = 'none'; }, 250);
    }

    function openEditTrackModal(id, title, artist, genre, album) {
        document.getElementById('edit-track-id').value     = id;
        document.getElementById('edit-track-title').value  = title;
        document.getElementById('edit-track-artist').value = artist;
        document.getElementById('edit-track-album').value  = album || '';
        document.getElementById('edit-track-cover').value  = '';
        if (genre) document.getElementById('edit-track-genre').value = genre;
        openModal('editTrackModal');
    }

    // ── Track / playlist deletion (via api.php) ──────────────────
    async function deleteTrack(id) {
        if (!confirm(t('confirm_delete_track'))) return;
        const res = await apiCall('delete_track', { track_id: id });
        if (res.status === 'success') location.reload();
        else alert(res.message || t('err_delete'));
    }
    async function deletePlaylist(id) {
        if (!confirm(t('confirm_delete_playlist'))) return;
        const res = await apiCall('playlist_mod', { playlist_id: id, mode: 'delete' });
        if (res.status === 'success') location.reload();
        else alert(res.message || t('err_delete'));
    }

    // ── Upload (via api.php) ─────────────────────────────────────
    let lastUploadTime = 0;
    async function handleUploadSubmit(e) {
        e.preventDefault();
        if (Date.now() - lastUploadTime < 15000) { alert(t('wait_before_upload')); return; }
        const musicFile = document.getElementById('upload-music').files[0];
        if (!musicFile) { alert(t('choose_audio_file')); return; }
        const fd = new FormData();
        fd.append('title',  document.getElementById('upload-title').value);
        fd.append('artist', document.getElementById('upload-artist').value);
        fd.append('album',  document.getElementById('upload-album').value);
        fd.append('genre',  document.getElementById('upload-genre').value);
        fd.append('music',  musicFile);
        const coverFile = document.getElementById('upload-cover').files[0];
        if (coverFile) fd.append('cover', coverFile);

        const btn = e.target.querySelector('button[type=submit]');
        btn.disabled = true; const oldLabel = btn.innerText; btn.innerText = t('uploading_ellipsis');
        lastUploadTime = Date.now();
        const res = await apiCallForm('upload', fd);
        if (res.status === 'success') { location.reload(); return; }
        btn.disabled = false; btn.innerText = oldLabel;
        alert(res.message || t('err_upload'));
    }

    // ── Track editing (via api.php) ──────────────────────────────
    async function handleEditTrackSubmit(e) {
        e.preventDefault();
        const fd = new FormData();
        fd.append('track_id',  document.getElementById('edit-track-id').value);
        fd.append('title',     document.getElementById('edit-track-title').value);
        fd.append('artist',    document.getElementById('edit-track-artist').value);
        fd.append('new_album', document.getElementById('edit-track-album').value);
        fd.append('new_genre', document.getElementById('edit-track-genre').value);
        const coverFile = document.getElementById('edit-track-cover').files[0];
        if (coverFile) fd.append('new_cover', coverFile);
        const res = await apiCallForm('edit_track', fd);
        if (res.status === 'success') location.reload();
        else alert(res.message || t('err_edit'));
    }

    // ── Playlists (via api.php) ───────────────────────────────────
    // playlistModal serves two purposes: creating a mix ('create' mode, with
    // name + visibility + initial selection) or adding songs to an
    // already open playlist ('add-songs' mode, triggered from the
    // playlist page's "Add" button — renaming and removing songs
    // are now done in place on that page, no modal needed anymore).
    let playlistModalMode = 'create';

    async function handlePlaylistSubmit(e) {
        e.preventDefault();
        const btn = e.target.querySelector('button[type=submit]');
        btn.disabled = true;
        try {
            const selectedIds = Array.from(document.querySelectorAll('.song-cb:checked')).map(cb => parseInt(cb.dataset.id, 10));

            if (playlistModalMode === 'add-songs') {
                if (!currentViewedPlaylist || !selectedIds.length) { closeModal('playlistModal'); return; }
                for (const id of selectedIds) {
                    await apiCall('playlist_mod', { playlist_id: currentViewedPlaylist.id, mode: 'add', track_id: id });
                }
                const ids = String(currentViewedPlaylist.song_ids || '').split(',').filter(Boolean).map(Number);
                selectedIds.forEach(id => { if (!ids.includes(id)) ids.push(id); });
                currentViewedPlaylist.song_ids = ids.join(',');
                closeModal('playlistModal');
                renderPlaylistPageContent();
                return;
            }

            const name = document.getElementById('form-playlist-name').value.trim();
            const isPublic = document.getElementById('form-playlist-public').checked;
            const createRes = await apiCall('playlist_create', { name, is_public: isPublic ? '1' : '0' });
            if (createRes.status !== 'success') { alert(createRes.message || t('err_generic')); return; }
            const playlists = await fetch('api.php?action=playlists').then(r => r.json());
            const mine = Array.isArray(playlists)
                ? playlists.filter(p => p.name === name && p.creator === API_AUTH.username)
                : [];
            const created = mine.sort((a, b) => b.id - a.id)[0];
            if (created) {
                for (const id of selectedIds) {
                    await apiCall('playlist_mod', { playlist_id: created.id, mode: 'add', track_id: id });
                }
            }
            location.reload();
        } finally {
            btn.disabled = false;
        }
    }

    // In "add-songs" mode, songs already in the playlist stay
    // hidden even outside of search: you can only add, never remove,
    // from this modal (the playlist page's ✕ handles that).
    function filterPlaylistTracks() {
        const term = document.getElementById('playlist-search').value.toLowerCase();
        const existingIds = (playlistModalMode === 'add-songs' && currentViewedPlaylist)
            ? String(currentViewedPlaylist.song_ids || '').split(',').filter(Boolean).map(Number)
            : [];
        document.querySelectorAll('.song-select-item').forEach(item => {
            const id = parseInt(item.querySelector('.song-cb').dataset.id, 10);
            const matches = item.dataset.title.includes(term) && !existingIds.includes(id);
            item.style.display = matches ? 'flex' : 'none';
        });
    }

    function toggleSelection(div) {
        const cb = div.querySelector('input'); cb.checked = !cb.checked;
        cb.checked ? div.classList.add('selected') : div.classList.remove('selected');
        updateSelectedCount();
    }
    function updateSelectedCount() {
        document.getElementById('selected-count').innerText = document.querySelectorAll('.song-cb:checked').length + t('selected_suffix');
    }

    function openCreateModal() {
        playlistModalMode = 'create';
        document.getElementById('modal-playlist-title').innerText = t('new_playlist');
        document.getElementById('playlist-form-name-group').style.display = '';
        document.getElementById('form-playlist-name').required = true;
        document.getElementById('form-playlist-name').value = '';
        document.getElementById('form-playlist-public').checked = true;
        document.getElementById('playlist-search').value = '';
        document.getElementById('playlist-form-submit-btn').innerText = t('btn_save');
        document.querySelectorAll('.song-select-item').forEach(div => { div.classList.remove('selected'); div.style.display = 'flex'; });
        document.querySelectorAll('.song-cb').forEach(cb => cb.checked = false);
        updateSelectedCount(); openModal('playlistModal');
    }

    // ── Adding songs to the currently open playlist: songs already
    // present are pre-hidden by filterPlaylistTracks(), so everything
    // checked here is necessarily new.
    function openAddSongModal() {
        if (!currentViewedPlaylist) return;
        playlistModalMode = 'add-songs';
        document.getElementById('modal-playlist-title').innerText = t('add_song_modal_title');
        document.getElementById('playlist-form-name-group').style.display = 'none';
        document.getElementById('form-playlist-name').required = false;
        document.getElementById('playlist-search').value = '';
        document.getElementById('playlist-form-submit-btn').innerText = t('btn_add_song');
        document.querySelectorAll('.song-select-item').forEach(div => { div.classList.remove('selected'); div.querySelector('.song-cb').checked = false; });
        filterPlaylistTracks();
        updateSelectedCount(); openModal('playlistModal');
    }

    // ===========================================================
    //  EQUALIZER (Web Audio API) — web equivalent of the 5-band EQ +
    //  bass boost of the Android client (EqualizerManager.kt).
    // ===========================================================
    let audioCtx = null, eqNodes = null;
    const EQ_BANDS = [
        { freq: 100,   type: 'lowshelf' },
        { freq: 60,    type: 'peaking' },
        { freq: 230,   type: 'peaking' },
        { freq: 910,   type: 'peaking' },
        { freq: 3600,  type: 'peaking' },
        { freq: 14000, type: 'peaking' },
    ];
    const EQ_PRESETS = {
        flat:   [0,0,0,0,0,0],
        bass:   [8,6,3,0,0,0],
        treble: [0,0,0,2,5,8],
        vocal:  [-2,-3,1,4,3,0],
        rock:   [4,3,-2,-1,2,5],
        pop:    [2,4,3,0,-1,2],
    };
    let eqSettings = JSON.parse(localStorage.getItem('eqSettings') || 'null') || { enabled: false, gains: [0,0,0,0,0,0] };

    function initAudioGraph() {
        if (audioCtx) return;
        try {
            audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            let node = audioCtx.createMediaElementSource(audio);
            eqNodes = EQ_BANDS.map((b, i) => {
                const f = audioCtx.createBiquadFilter();
                f.type = b.type; f.frequency.value = b.freq;
                f.gain.value = eqSettings.enabled ? (eqSettings.gains[i] || 0) : 0;
                if (b.type === 'peaking') f.Q.value = 1;
                node.connect(f); node = f; return f;
            });
            node.connect(audioCtx.destination);
        } catch (e) { console.warn("Égaliseur indisponible sur ce navigateur.", e); }
    }
    function setEqEnabled(on) {
        eqSettings.enabled = on;
        if (eqNodes) eqNodes.forEach((f, i) => { f.gain.value = on ? (eqSettings.gains[i] || 0) : 0; });
        localStorage.setItem('eqSettings', JSON.stringify(eqSettings));
        const toggle = document.getElementById('eqEnableToggle');
        if (toggle) toggle.checked = on;
    }
    function setEqBand(i, val) {
        eqSettings.gains[i] = val;
        if (eqSettings.enabled && eqNodes) eqNodes[i].gain.value = val;
        localStorage.setItem('eqSettings', JSON.stringify(eqSettings));
    }
    function applyEqPreset(name) {
        eqSettings.gains = [...EQ_PRESETS[name]];
        eqSettings.enabled = true;
        initAudioGraph();
        if (eqNodes) eqNodes.forEach((f, i) => { f.gain.value = eqSettings.gains[i]; });
        localStorage.setItem('eqSettings', JSON.stringify(eqSettings));
        renderEqSliders();
    }
    function renderEqSliders() {
        const toggle = document.getElementById('eqEnableToggle');
        if (toggle) toggle.checked = eqSettings.enabled;
        EQ_BANDS.forEach((b, i) => {
            const slider = document.getElementById('eq-slider-' + i);
            if (slider) slider.value = eqSettings.gains[i] || 0;
            const label = document.getElementById('eq-val-' + i);
            if (label) label.innerText = (eqSettings.gains[i] || 0) + ' dB';
        });
    }

    // ===========================================================
    //  THEMES (presets from the Android client — ThemeUtils.kt ported
    //  to JS). A single "base" color generates panel/accent/
    //  borders/text using the same HSL formulas.
    // ===========================================================
    const THEME_PRESETS = [
        { name: t('theme_site_default'), base: null },
        { name: t('theme_adaptive'), base: 'adaptive' },
        { name: 'White Mode',     base: '#FFFFFF' },
        { name: 'AMOLED',         base: '#000000' },
        { name: 'Vibrant Purple', base: '#4A148C' },
        { name: 'Electric Blue',  base: '#0D47A1' },
        { name: 'Deep Teal',      base: '#004D40' },
        { name: 'Cherry',         base: '#880E4F' },
        { name: 'Midnight',       base: '#0A0E1A' },
        { name: 'Forest',         base: '#0D140D' },
        { name: 'Crimson',        base: '#140D0D' },
        { name: 'Slate',          base: '#1A1A1B' },
        { name: 'Jet Black',      base: '#0A0A0A' },
        { name: 'Material',       base: '#121212' },
    ];

    function hexToRgb(hex) {
        hex = hex.replace('#', '');
        if (hex.length === 3) hex = hex.split('').map(c => c + c).join('');
        const num = parseInt(hex, 16);
        return { r: (num >> 16) & 255, g: (num >> 8) & 255, b: num & 255 };
    }
    function rgbToHex(r, g, b) {
        return '#' + [r, g, b].map(v => Math.round(Math.max(0, Math.min(255, v))).toString(16).padStart(2, '0')).join('');
    }
    function hexToRgba(hex, a) { const { r, g, b } = hexToRgb(hex); return `rgba(${r},${g},${b},${a})`; }
    function rgbToHsl(r, g, b) {
        r /= 255; g /= 255; b /= 255;
        const max = Math.max(r, g, b), min = Math.min(r, g, b);
        let h, s, l = (max + min) / 2;
        if (max === min) { h = s = 0; }
        else {
            const d = max - min;
            s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
            switch (max) {
                case r: h = (g - b) / d + (g < b ? 6 : 0); break;
                case g: h = (b - r) / d + 2; break;
                default: h = (r - g) / d + 4;
            }
            h /= 6;
        }
        return [h * 360, s, l];
    }
    function hslToRgb(h, s, l) {
        h /= 360; let r, g, b;
        if (s === 0) { r = g = b = l; }
        else {
            const hue2rgb = (p, q, t) => { if (t < 0) t += 1; if (t > 1) t -= 1; if (t < 1/6) return p + (q - p) * 6 * t; if (t < 1/2) return q; if (t < 2/3) return p + (q - p) * (2/3 - t) * 6; return p; };
            const q = l < 0.5 ? l * (1 + s) : l + s - l * s;
            const p = 2 * l - q;
            r = hue2rgb(p, q, h + 1/3); g = hue2rgb(p, q, h); b = hue2rgb(p, q, h - 1/3);
        }
        return [r * 255, g * 255, b * 255];
    }
    function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
    function deriveAccent(hex) {
        const { r, g, b } = hexToRgb(hex);
        let [h, s, l] = rgbToHsl(r, g, b);
        const light = l > 0.5;
        // Base with no clear hue (AMOLED, White Mode, grey, desaturated
        // cover in adaptive mode...): the accent stays neutral too rather
        // than falling back to an arbitrary hue — the old code forced
        // purple here, which made it reappear in themes that should
        // contain no trace of purple.
        if (s < 0.08) return light ? '#3a3a3a' : '#ffffff';
        if (light) { s = clamp(s + 0.5, 0.6, 1.0); l = clamp(l - 0.4, 0.3, 0.5); }
        else       { s = clamp(s + 0.4, 0.5, 0.9); l = clamp(l + 0.5, 0.6, 0.85); }
        return rgbToHex(...hslToRgb(h, s, l));
    }
    // --primary is used as a button background with white text on top (.btn-primary),
    // unlike --accent which is used as text/icon ON the dark background — two
    // opposite roles that cannot be shared. deriveAccent readily pushes toward
    // light tones (up to l=0.85, or even pure white on AMOLED) to stay readable
    // on a black background; that same light tone would make white button text
    // unreadable. derivePrimary therefore always targets a tone dark enough for
    // white text — checked with a real WCAG contrast computation rather than a
    // fixed lightness, since some hues (teal, green) still look "light" to the
    // eye even at a moderate HSL lightness.
    function relLuminance(hex) {
        const { r, g, b } = hexToRgb(hex);
        const lin = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
        return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
    }
    function contrastRatio(hexA, hexB) {
        const l1 = relLuminance(hexA) + 0.05, l2 = relLuminance(hexB) + 0.05;
        return Math.max(l1, l2) / Math.min(l1, l2);
    }
    function derivePrimary(hex) {
        const { r, g, b } = hexToRgb(hex);
        let [h, s] = rgbToHsl(r, g, b);
        // Same logic as deriveAccent: a base with no clear hue gives a
        // neutral (grey) primary, never an arbitrary hue forced onto it
        // (the old code forced purple via h=270 here).
        const neutral = s < 0.08;
        s = neutral ? 0 : clamp(Math.max(s, 0.45), 0.45, 0.85);
        let l = 0.42;
        let candidate = rgbToHex(...hslToRgb(h, s, l));
        while (l > 0.1 && contrastRatio('#ffffff', candidate) < 4.5) {
            l -= 0.02;
            candidate = rgbToHex(...hslToRgb(h, s, l));
        }
        return candidate;
    }
    function derivePanel(hex) {
        if (hex.toLowerCase() === '#ffffff') return '#f5f5f7';
        const { r, g, b } = hexToRgb(hex);
        let [h, s, l] = rgbToHsl(r, g, b);
        l = l > 0.5 ? clamp(l - 0.05, 0, 1) : clamp(l + 0.05, 0, 1);
        return rgbToHex(...hslToRgb(h, s, l));
    }
    function deriveBorder(hex) {
        if (hex.toLowerCase() === '#ffffff') return '#e0e0e0';
        const { r, g, b } = hexToRgb(hex);
        let [h, s, l] = rgbToHsl(r, g, b);
        l = l > 0.5 ? clamp(l - 0.15, 0, 1) : clamp(l + 0.15, 0, 1);
        return rgbToHex(...hslToRgb(h, s, l));
    }
    function deriveTextMuted(hex) {
        const { r, g, b } = hexToRgb(hex);
        let [h, s, l] = rgbToHsl(r, g, b);
        const light = l > 0.5;
        s = clamp(s * 0.5, 0, 1); l = light ? 0.4 : 0.7;
        return rgbToHex(...hslToRgb(h, s, l));
    }
    // Status color (red = danger) tinted to fit the theme:
    // the hue stays recognisably red but leans slightly toward the
    // theme's own hue, saturation follows the theme (soft on neutral themes
    // like AMOLED/White Mode, richer on colorful ones), and lightness is
    // pushed until the text stays readable (WCAG 4.5:1) on the background.
    function deriveStatus(hex, targetHue) {
        const { r, g, b } = hexToRgb(hex);
        const [h, s, bgL] = rgbToHsl(r, g, b);
        const light = bgL > 0.5;
        const neutral = s < 0.08;
        let hue = targetHue;
        if (!neutral) {
            let diff = h - hue;
            if (diff > 180) diff -= 360;
            if (diff < -180) diff += 360;
            hue = (hue + clamp(diff * 0.2, -8, 8) + 360) % 360;
        }
        const sat = neutral ? 0.6 : clamp(s * 0.5 + 0.45, 0.55, 0.85);
        let l = light ? 0.42 : 0.66;
        let candidate = rgbToHex(...hslToRgb(hue, sat, l));
        while (l > 0.1 && l < 0.9 && contrastRatio(hex, candidate) < 4.5) {
            l += light ? -0.02 : 0.02;
            candidate = rgbToHex(...hslToRgb(hue, sat, l));
        }
        return candidate;
    }
    function applyStatusColors(baseHex) {
        const style = document.documentElement.style;
        const danger = deriveStatus(baseHex, 355);
        const { r, g, b } = hexToRgb(danger);
        style.setProperty('--danger', danger);
        style.setProperty('--danger-rgb', `${r},${g},${b}`);
    }
    function deriveText(hex) { const { r, g, b } = hexToRgb(hex); const [, , l] = rgbToHsl(r, g, b); return l > 0.5 ? '#1a1a1b' : '#e0e0e0'; }
    // "Elevated" surface N steps away from the background (inputs, hover, focus…) —
    // same steps as derivePanel (2) but configurable for other surfaces.
    function deriveElevated(hex, steps) {
        const { r, g, b } = hexToRgb(hex);
        let [h, s, l] = rgbToHsl(r, g, b);
        const step = 0.025;
        l = l > 0.5 ? clamp(l - steps * step, 0, 1) : clamp(l + steps * step, 0, 1);
        return rgbToHex(...hslToRgb(h, s, l));
    }

    const THEME_VARS = ['--bg-dark','--bg-panel','--primary','--accent','--primary-rgb','--accent-rgb','--text','--text-muted','--border-color','--border-color-rgb','--search-bg','--header-bg','--mob-nav-bg','--player-bg','--fp-gradient-1','--fp-gradient-2','--modal-bg','--input-bg','--elevated-bg','--player-text','--danger','--danger-rgb'];
    // Applies the CSS variables computed from a base color, without touching
    // localStorage — used both by a static theme chosen by the user and by
    // adaptive mode (one color per track).
    function applyThemeColors(baseHex, gradient2Hex) {
        const style = document.documentElement.style;
        if (!baseHex) {
            THEME_VARS.forEach(v => style.removeProperty(v));
            // Site theme: tint the status colors from the admin-chosen background.
            const siteBg = getComputedStyle(document.documentElement).getPropertyValue('--bg-dark').trim();
            if (/^#[0-9a-f]{3,6}$/i.test(siteBg)) applyStatusColors(siteBg);
            return;
        }
        const panel    = derivePanel(baseHex);
        const primary  = derivePrimary(baseHex);
        const accent   = deriveAccent(baseHex);
        const border   = deriveBorder(baseHex);
        const muted    = deriveTextMuted(baseHex);
        const text     = deriveText(baseHex);
        const inputBg  = deriveElevated(baseHex, 1);
        const elevated = deriveElevated(baseHex, 3);
        const { r: pr, g: pg, b: pb } = hexToRgb(primary);
        const { r: ar, g: ag, b: ab } = hexToRgb(accent);
        const { r: br, g: bg2, b: bb } = hexToRgb(border);
        style.setProperty('--bg-dark', baseHex);
        style.setProperty('--bg-panel', panel);
        style.setProperty('--primary', primary);
        style.setProperty('--accent', accent);
        style.setProperty('--primary-rgb', `${pr},${pg},${pb}`);
        style.setProperty('--accent-rgb', `${ar},${ag},${ab}`);
        style.setProperty('--text', text);
        style.setProperty('--text-muted', muted);
        style.setProperty('--border-color', border);
        style.setProperty('--border-color-rgb', `${br},${bg2},${bb}`);
        style.setProperty('--search-bg', panel);
        style.setProperty('--header-bg', hexToRgba(panel, 0.85));
        style.setProperty('--mob-nav-bg', hexToRgba(panel, 0.95));
        style.setProperty('--player-bg', hexToRgba(panel, 0.85));
        style.setProperty('--fp-gradient-1', panel);
        style.setProperty('--fp-gradient-2', gradient2Hex || baseHex);
        style.setProperty('--modal-bg', panel);
        style.setProperty('--input-bg', inputBg);
        style.setProperty('--elevated-bg', elevated);
        style.setProperty('--player-text', text);
        applyStatusColors(baseHex);
    }
    function applyTheme(baseHex) {
        adaptiveThemeEnabled = false;
        applyThemeColors(baseHex);
        if (baseHex) localStorage.setItem('theme_base', baseHex);
        else localStorage.removeItem('theme_base');
        renderThemeSwatches();
    }
    // --- Adaptive theme: palette extracted from the current track's cover ---
    // We sample the whole image (not a single pixel), group nearby pixels into
    // color "spots" (quantization), then score each spot against several target
    // profiles (vibrant/muted × light/normal/dark — same principle as the
    // Android Palette API) to pick truly representative colors rather than an
    // isolated pixel that could be an artifact.
    const PALETTE_TARGETS = [
        { name: 'vibrant',      minS: 0.35, targetS: 1.0, minL: 0.30, targetL: 0.50, maxL: 0.70 },
        { name: 'lightVibrant', minS: 0.35, targetS: 1.0, minL: 0.55, targetL: 0.74, maxL: 1.00 },
        { name: 'darkVibrant',  minS: 0.35, targetS: 1.0, minL: 0.00, targetL: 0.26, maxL: 0.45 },
        { name: 'muted',        minS: 0.00, targetS: 0.3, minL: 0.30, targetL: 0.50, maxL: 0.70 },
        { name: 'lightMuted',   minS: 0.00, targetS: 0.3, minL: 0.55, targetL: 0.74, maxL: 1.00 },
        { name: 'darkMuted',    minS: 0.00, targetS: 0.3, minL: 0.00, targetL: 0.26, maxL: 0.45 },
    ];
    function extractPalette(imgSrc) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => {
                try {
                    const size = 100; // more sampled pixels = less noisy bucket averages
                    const canvas = document.createElement('canvas');
                    canvas.width = size; canvas.height = size;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0, size, size);
                    const data = ctx.getImageData(0, 0, size, size).data;
                    // Finer buckets (16 instead of 24): a cover with a close red and
                    // orange must not be averaged into a single brown that exists in
                    // neither — the chosen hue must stay true to the image.
                    const QUANT = 16;
                    const buckets = new Map();
                    for (let i = 0; i < data.length; i += 4) {
                        const r = data[i], g = data[i + 1], b = data[i + 2], a = data[i + 3];
                        if (a < 200) continue;
                        const [, , l] = rgbToHsl(r, g, b);
                        if (l < 0.03 || l > 0.97) continue; // skip pure black/white (borders, letterbox)
                        const key = Math.round(r / QUANT) + '_' + Math.round(g / QUANT) + '_' + Math.round(b / QUANT);
                        let bucket = buckets.get(key);
                        if (!bucket) { bucket = { count: 0, r: 0, g: 0, b: 0 }; buckets.set(key, bucket); }
                        bucket.count++; bucket.r += r; bucket.g += g; bucket.b += b;
                    }
                    if (buckets.size === 0) { resolve(null); return; }
                    const allClusters = [...buckets.values()].map(c => {
                        const r = c.r / c.count, g = c.g / c.count, b = c.b / c.count;
                        const [, s, l] = rgbToHsl(r, g, b);
                        return { hex: rgbToHex(r, g, b), count: c.count, s, l };
                    });
                    const totalPixels = allClusters.reduce((sum, c) => sum + c.count, 0);
                    // Only keep spots actually present in the image (at least ~1.5% of
                    // valid pixels) before matching target profiles: otherwise an isolated
                    // pixel that fits a profile well (e.g. a tiny, very saturated highlight)
                    // could be picked even though it is nearly invisible on the cover.
                    // Threshold lowered (1.5% → 1%) because finer buckets (QUANT=16 instead
                    // of 24) naturally spread pixels over more buckets — without this
                    // adjustment, hues that used to qualify would artificially fall back
                    // under the threshold.
                    const MIN_POPULATION_FRACTION = 0.01;
                    const dominant = allClusters.reduce((a, b) => (b.count > a.count ? b : a));
                    const clusters = allClusters
                        .filter(c => c.count / totalPixels >= MIN_POPULATION_FRACTION)
                        .sort((a, b) => b.count - a.count);
                    const maxPop = clusters.length ? clusters[0].count : dominant.count;
                    // Aligned with the actual weights of the Android Palette API (whose
                    // principle this code follows): matching the profile's target
                    // hue/lightness (vibrant/muted × light/normal/dark) must largely
                    // outweigh popularity — the old setting (POP=5 > LUMA=3 > SAT=2)
                    // essentially meant "take the most frequent bucket above the threshold",
                    // often a dull flat background, rather than the real accent color the
                    // eye would pick out on the cover.
                    const WEIGHT_SAT = 3, WEIGHT_LUMA = 6.5, WEIGHT_POP = 1;
                    const scoreFor = (target, c) => {
                        if (c.l < target.minL || c.l > target.maxL || c.s < target.minS) return -Infinity;
                        const satScore = 1 - Math.abs(c.s - target.targetS);
                        const lumScore = 1 - Math.abs(c.l - target.targetL);
                        const popScore = c.count / maxPop;
                        return satScore * WEIGHT_SAT + lumScore * WEIGHT_LUMA + popScore * WEIGHT_POP;
                    };
                    const used = new Set();
                    const palette = {};
                    PALETTE_TARGETS.forEach(target => {
                        let best = null, bestScore = -Infinity;
                        clusters.forEach(c => {
                            if (used.has(c.hex)) return;
                            const sc = scoreFor(target, c);
                            if (sc > bestScore) { bestScore = sc; best = c; }
                        });
                        if (best && bestScore > -Infinity) { palette[target.name] = best.hex; used.add(best.hex); }
                    });
                    palette.dominant = dominant.hex;
                    resolve(palette);
                } catch (e) { reject(e); }
            };
            img.onerror = reject;
            img.src = imgSrc;
        });
    }
    // Caps the saturation/lightness of an extracted color so that a very
    // saturated spot (e.g. bright red, neon orange) doesn't become the whole
    // app's background once used as --bg-dark — the adaptive theme must stay
    // dark and subtle, never garish, whatever the cover.
    function tameAdaptiveColor(hex, maxS, maxL) {
        const { r, g, b } = hexToRgb(hex);
        let [h, s, l] = rgbToHsl(r, g, b);
        s = Math.min(s, maxS);
        l = Math.min(l, maxL);
        return rgbToHex(...hslToRgb(h, s, l));
    }
    // Combines two palette spots: a dark, subtle tone as the theme base
    // (drives panel/text/contrast via applyThemeColors), and a slightly more
    // vivid tone as the second stop of the fullscreen player's ambient
    // gradient (already darkened by the CSS brightness(.55) filter on top).
    function paletteToTheme(palette) {
        if (!palette) return null;
        const rawBase = palette.darkMuted || palette.darkVibrant || palette.muted || palette.vibrant || palette.dominant;
        const rawGradient2 = palette.vibrant || palette.lightVibrant || palette.lightMuted || null;
        const base = tameAdaptiveColor(rawBase, 0.5, 0.20);
        const gradient2 = rawGradient2 ? tameAdaptiveColor(rawGradient2, 0.65, 0.55) : null;
        return { base, gradient2 };
    }
    function updateAdaptiveTheme(track) {
        if (!adaptiveThemeEnabled) return;
        if (adaptiveIdle) { applyAdaptiveFallback(); return; }
        // No cover to read: use the site theme, like a failed extraction.
        if (!track || !track.cover_url) { applyThemeColors(null); return; }
        const key = track.cover_url;
        const cached = adaptiveColorCache.get(key);
        if (cached) { applyThemeColors(cached.base, cached.gradient2); return; }
        extractPalette(key).then(palette => {
            // If extraction fails, fall back to the site theme (base: null)
            // rather than an arbitrary color — otherwise the old purple
            // fallback would reappear in a theme that is supposed to follow
            // the current cover.
            const theme = paletteToTheme(palette) || { base: null, gradient2: null };
            adaptiveColorCache.set(key, theme);
            const current = queue[currentIndex];
            if (adaptiveThemeEnabled && !adaptiveIdle && current && current.cover_url === key) applyThemeColors(theme.base, theme.gradient2);
        }).catch(() => {
            // Cover failed to load: same site-theme fallback as above, so the
            // idle default theme never lingers while a song is playing.
            adaptiveColorCache.set(key, { base: null, gradient2: null });
            const current = queue[currentIndex];
            if (adaptiveThemeEnabled && !adaptiveIdle && current && current.cover_url === key) applyThemeColors(null);
        });
    }
    function enableAdaptiveTheme() {
        adaptiveThemeEnabled = true;
        localStorage.setItem('theme_base', 'adaptive');
        const track = queue[currentIndex];
        if (track && !adaptiveIdle) updateAdaptiveTheme(track); else applyAdaptiveFallback();
        renderThemeSwatches();
    }
    // --- Default adaptive theme: only used while adaptive mode is on and no
    // song has started playing yet this session (see adaptiveIdle). Once a song
    // plays, the cover always drives the theme. Stored separately
    // from 'theme_base' so it survives switching themes back and forth.
    function getAdaptiveFallback() {
        return localStorage.getItem('theme_adaptive_fallback') || null;
    }
    function applyAdaptiveFallback() {
        applyThemeColors(getAdaptiveFallback());
    }
    function setAdaptiveFallback(baseHex) {
        if (baseHex) localStorage.setItem('theme_adaptive_fallback', baseHex);
        else localStorage.removeItem('theme_adaptive_fallback');
        if (adaptiveThemeEnabled && adaptiveIdle) applyAdaptiveFallback();
        renderThemeSwatches();
    }
    function swatchBackground(base) {
        return base === 'adaptive'
            ? 'conic-gradient(from 180deg,#ff5f6d,#ffc371,#4ecdc4,#556fff,#ff5f6d)'
            : (base || `linear-gradient(135deg,${SERVER_PRIMARY},${SERVER_ACCENT})`);
    }
    function renderThemeSwatches() {
        const grid = document.getElementById('theme-swatch-grid');
        if (!grid) return;
        const savedBase = localStorage.getItem('theme_base');
        grid.innerHTML = '';
        THEME_PRESETS.forEach(p => {
            const isActive = p.base === savedBase || (p.base === null && !savedBase);
            const div = document.createElement('div');
            div.className = 'theme-swatch' + (isActive ? ' active' : '');
            div.innerHTML = `<div class="swatch-circle" style="background:${swatchBackground(p.base)};"></div><span>${escapeHTML(p.name)}</span>`;
            div.onclick = () => p.base === 'adaptive' ? enableAdaptiveTheme() : applyTheme(p.base);
            grid.appendChild(div);
        });
        renderAdaptiveFallbackSwatches(savedBase === 'adaptive');
    }
    // Second picker, only shown while adaptive mode is active: lists every
    // static preset (not 'adaptive' itself) to choose the idle theme.
    function renderAdaptiveFallbackSwatches(visible) {
        const section = document.getElementById('adaptive-fallback-section');
        const grid = document.getElementById('adaptive-fallback-grid');
        if (!section || !grid) return;
        section.style.display = visible ? '' : 'none';
        if (!visible) return;
        const fallback = getAdaptiveFallback();
        grid.innerHTML = '';
        THEME_PRESETS.filter(p => p.base !== 'adaptive').forEach(p => {
            const div = document.createElement('div');
            div.className = 'theme-swatch' + (p.base === fallback ? ' active' : '');
            div.innerHTML = `<div class="swatch-circle" style="background:${swatchBackground(p.base)};"></div><span>${escapeHTML(p.name)}</span>`;
            div.onclick = () => setAdaptiveFallback(p.base);
            grid.appendChild(div);
        });
    }

    // ===========================================================
    //  SYNCED LYRICS (lrclib.net — like AppViewModel.kt)
    // ===========================================================
    let lyricsVisible = false;
    let currentParsedLyrics = [];
    let lyricsRequestId = 0;
    let currentFpTab = 'queue';

    // Fullscreen player side panel (Queue / Lyrics),
    // YouTube Music style: a single panel, two tabs.
    function openFpTab(tab) {
        currentFpTab = tab;
        document.getElementById('fp-sidebar').classList.add('open');
        document.getElementById('fpTabQueueBtn').classList.toggle('active', tab === 'queue');
        document.getElementById('fpTabLyricsBtn').classList.toggle('active', tab === 'lyrics');
        document.getElementById('fp-queue-tab').classList.toggle('active', tab === 'queue');
        document.getElementById('fp-lyrics-tab').classList.toggle('active', tab === 'lyrics');
        lyricsVisible = (tab === 'lyrics');
        if (lyricsVisible && queue[currentIndex]) fetchLyrics(queue[currentIndex]);
        syncFpHeaderButtons();
    }
    function toggleFpTab(tab) {
        const sidebar = document.getElementById('fp-sidebar');
        if (sidebar.classList.contains('open') && currentFpTab === tab) {
            sidebar.classList.remove('open');
            lyricsVisible = false;
            syncFpHeaderButtons();
        } else {
            openFpTab(tab);
        }
    }
    function syncFpHeaderButtons() {
        const open = document.getElementById('fp-sidebar').classList.contains('open');
        document.getElementById('lyricsBtn').classList.toggle('active', open && currentFpTab === 'lyrics');
        document.getElementById('fpQueueBtn').classList.toggle('active', open && currentFpTab === 'queue');
    }

    function parseLrc(text) {
        const lines = [];
        const re = /\[(\d{2}):(\d{2})(?:[.:](\d{1,3}))?\](.*)/;
        text.split('\n').forEach(line => {
            const m = line.match(re);
            if (!m) return;
            const min = parseInt(m[1], 10), sec = parseInt(m[2], 10);
            const ms  = m[3] ? parseInt(m[3].padEnd(3, '0'), 10) : 0;
            const content = m[4].trim();
            if (content) lines.push({ time: min * 60 + sec + ms / 1000, text: content });
        });
        lines.sort((a, b) => a.time - b.time);
        return lines;
    }

    function renderLyricsRaw(lrc) {
        const panel = document.getElementById('lyrics-content');
        const parsed = parseLrc(lrc);
        if (!parsed.length) {
            panel.innerHTML = `<p class="lyrics-status" style="white-space:pre-line;">${escapeHTML(fixEntitiesLiteral(lrc))}</p>`;
            currentParsedLyrics = [];
            resetLyricsScroll();
            return;
        }
        currentParsedLyrics = parsed;
        // Unlike fixEntities() used for titles/artists, lyrics
        // keep the literal "&" symbol rather than the word "and"/"et": replacing a
        // "&" with a word in the middle of a song line would alter the original text.
        panel.innerHTML = parsed.map(l => `<p class="lyric-line" data-time="${l.time}" onclick="seekLyric(${l.time})">${escapeHTML(fixEntitiesLiteral(l.text))}</p>`).join('');
        resetLyricsScroll();
    }

    // Scrolls the panel back to the top after a track change. Forcing it at the
    // start of fetchLyrics() (even before the network fetch) cuts short any
    // smooth scroll still in progress toward the last lines of the
    // previous track; this second pass, after the DOM update and
    // on a later frame, catches cases where the browser re-adjusts the
    // scroll afterwards (scroll anchoring, backdrop-filter reflow, etc.).
    function resetLyricsScroll() {
        const sidebarContent = document.querySelector('.fp-sidebar-content');
        if (sidebarContent) sidebarContent.scrollTop = 0;
        pinFullPlayerScroll();
        requestAnimationFrame(() => {
            if (sidebarContent) sidebarContent.scrollTop = 0;
            pinFullPlayerScroll();
        });
    }

    async function fetchLyrics(track) {
        const panel = document.getElementById('lyrics-content');
        currentParsedLyrics = [];
        // Starts from the top of the panel on every new track: without this, if we
        // had scrolled to the bottom of the previous (long) lyrics and
        // the next track has short/missing lyrics, the scroll position
        // stays at the old value. This first pass cuts short
        // any smooth scroll still in progress (see resetLyricsScroll() for
        // the second pass, after the new track is rendered).
        resetLyricsScroll();
        const myReq = ++lyricsRequestId;
        const cacheKey = 'lyrics_' + track.id;
        const cached = localStorage.getItem(cacheKey);
        if (cached !== null) { renderLyricsRaw(cached); return; }

        panel.innerHTML = `<p class="lyrics-status">${t('loading_lyrics')}</p>`;
        try {
            // lrclib doesn't find the lyrics if artist_name contains several
            // artists joined by "&"/"and"/"et" (e.g. "A & B"): we only search
            // on the main artist, like splitArtistNames() already does for
            // clickable links.
            const primaryArtist = splitArtistNames(fixEntities(track.artist || ''))[0] || track.artist || '';
            const cleanTitle = fixEntities(track.title || '');
            const url = 'https://lrclib.net/api/get?artist_name=' + encodeURIComponent(primaryArtist) + '&track_name=' + encodeURIComponent(cleanTitle);
            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            if (myReq !== lyricsRequestId) return;
            if (res.status === 404) { panel.innerHTML = `<p class="lyrics-status">${t('no_lyrics')}</p>`; return; }
            if (!res.ok) throw new Error('http ' + res.status);
            const json = await res.json();
            const lrc = json.syncedLyrics || json.plainLyrics || '';
            if (!lrc) { panel.innerHTML = `<p class="lyrics-status">${t('no_lyrics')}</p>`; return; }
            try { localStorage.setItem(cacheKey, lrc); } catch (e) {}
            renderLyricsRaw(lrc);
        } catch (e) {
            if (myReq === lyricsRequestId) panel.innerHTML = `<p class="lyrics-status">${t('err_lyrics')}</p>`;
        }
    }

    function seekLyric(t) { audio.currentTime = t; if (audio.paused) togglePlay(); }

    function updateLyricsHighlight() {
        if (!lyricsVisible || !currentParsedLyrics.length) return;
        const t = audio.currentTime;
        let activeIdx = -1;
        for (let i = 0; i < currentParsedLyrics.length; i++) {
            if (currentParsedLyrics[i].time <= t) activeIdx = i; else break;
        }
        const lines = document.querySelectorAll('#lyrics-content .lyric-line');
        lines.forEach((el, i) => el.classList.toggle('active', i === activeIdx));
        // We deliberately avoid lines[activeIdx].scrollIntoView({block:'center'}):
        // when the active line is too close to the start/end of the list to be
        // truly centered in .fp-sidebar-content, Chrome passes the rest of the
        // requested scroll on to the next ancestor containers in the chain
        // (#fp-sidebar then #full-player, both overflow:hidden) — which
        // visually shifts the whole fullscreen player instead of staying
        // confined to the lyrics panel. So we compute the target scrollTop
        // ourselves and apply it only to the panel via scrollTo().
        if (activeIdx >= 0 && lines[activeIdx]) {
            const container = document.querySelector('.fp-sidebar-content');
            const el = lines[activeIdx];
            if (container) {
                // getBoundingClientRect() rather than offsetTop: no element between
                // .fp-sidebar-content and .lyric-line has a position other than static,
                // so offsetTop would climb up to #full-player (position:fixed, the
                // first positioned ancestor) instead of the lyrics panel.
                const elRect = el.getBoundingClientRect();
                const containerRect = container.getBoundingClientRect();
                const elTopInContainer = (elRect.top - containerRect.top) + container.scrollTop;
                const target = elTopInContainer - (container.clientHeight / 2) + (elRect.height / 2);
                const max = container.scrollHeight - container.clientHeight;
                container.scrollTo({ top: Math.max(0, Math.min(target, max)), behavior: 'smooth' });
            }
            pinFullPlayerScroll();
        }
    }

    // Safeguard: #full-player, #fp-sidebar and .fp-body never have a
    // legitimate reason to scroll (all their content fits in their area, only
    // .fp-sidebar-content is meant to scroll); so we force their
    // scrollTop to 0 after every scroll manipulation in the lyrics
    // panel, in case the browser still passes some leftover
    // scroll onto them (which visually shifts the whole player and hides the
    // rest of the UI) — whatever the exact cause depending on the engine.
    function pinFullPlayerScroll() {
        ['full-player', 'fp-sidebar'].forEach(id => {
            const el = document.getElementById(id);
            if (el && el.scrollTop !== 0) el.scrollTop = 0;
        });
        const fpBody = document.querySelector('.fp-body');
        if (fpBody && fpBody.scrollTop !== 0) fpBody.scrollTop = 0;
    }
</script>

<?php endif; ?>
</body>
</html>
