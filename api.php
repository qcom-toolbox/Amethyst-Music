<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json');

// --- SECURITY: HTTP security headers ---
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// --- CONFIGURATION BDD ---
require_once __DIR__ . '/config.php';

try {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $db = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // --- TABLES ---
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id       INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        is_admin TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS tracks (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        filename    VARCHAR(255),
        title       VARCHAR(200),
        artist      VARCHAR(200) DEFAULT 'Artiste inconnu',
        cover       VARCHAR(255) DEFAULT 'default.png',
        genre       VARCHAR(50)  DEFAULT 'Autre',
        album_id    INT          DEFAULT NULL,
        uploader_id INT,
        upload_date DATETIME     DEFAULT CURRENT_TIMESTAMP,
        play_count  INT          DEFAULT 0,
        duration    INT          DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS albums (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(255) NOT NULL,
        cover      VARCHAR(255) DEFAULT NULL,
        created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_album_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS playlists (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(100),
        creator_id INT,
        song_ids   TEXT,
        is_public  TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- SECURITY: Rate limiting table for login attempts ---
    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        ip           VARCHAR(45),
        attempt_time INT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- Per-user listening history, basis of the recommendation engine ---
    // idx_lh_user_played is a composite index (user_id, played_at, track_id):
    // it fully covers the action=recommend query (WHERE user_id=?
    // ORDER BY played_at DESC LIMIT 200, SELECT track_id — pure index scan,
    // without touching the table) and greatly speeds up the WHERE user_id=? filter
    // of action=history even at millions of rows. idx_lh_track remains
    // useful for cascading deletes (fk_listen_history_track) and
    // any future query per track rather than per user.
    $db->exec("CREATE TABLE IF NOT EXISTS listen_history (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        user_id   INT NOT NULL,
        track_id  INT NOT NULL,
        played_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_lh_user_played (user_id, played_at, track_id),
        KEY idx_lh_track (track_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- OPTIMIZATION: SQL indexing ---
    // "CREATE INDEX IF NOT EXISTS" is only supported by MariaDB (10.5.2+);
    // MySQL (including 8.0/9.x) rejects it with a plain syntax error,
    // which crashed EVERY request to api.php (including increment_play,
    // which feeds the listening history) on a MySQL database. We swallow
    // the error: a missing index is a performance problem, not a
    // correctness one, and the index was already created by setup.sql anyway.
    try { $db->exec("CREATE INDEX IF NOT EXISTS idx_play_count ON tracks(play_count)"); } catch (Exception $e) {}
    try { $db->exec("CREATE INDEX IF NOT EXISTS idx_uploader   ON tracks(uploader_id)"); } catch (Exception $e) {}

    // --- AUTOMATIC MIGRATIONS (tracks) ---
    $cols = $db->query("SHOW COLUMNS FROM tracks")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'Field');
    if (!in_array('genre',      $colNames)) $db->exec("ALTER TABLE tracks ADD COLUMN genre      VARCHAR(50) DEFAULT 'Autre'");
    if (!in_array('play_count', $colNames)) $db->exec("ALTER TABLE tracks ADD COLUMN play_count INT         DEFAULT 0");
    if (!in_array('duration',   $colNames)) $db->exec("ALTER TABLE tracks ADD COLUMN duration   INT         DEFAULT 0");
    if (!in_array('album_id',   $colNames)) $db->exec("ALTER TABLE tracks ADD COLUMN album_id   INT         DEFAULT NULL");

    // idx_album can only be created once the album_id column is guaranteed to exist above
    try { $db->exec("CREATE INDEX IF NOT EXISTS idx_album ON tracks(album_id)"); } catch (Exception $e) {}

    // --- AUTOMATIC MIGRATIONS (users) ---
    $colsUsers = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
    $colNamesUsers = array_column($colsUsers, 'Field');
    if (!in_array('is_admin', $colNamesUsers)) $db->exec("ALTER TABLE users ADD COLUMN is_admin TINYINT(1) DEFAULT 0");

} catch (Exception $e) { die(json_encode(["status" => "error", "message" => "Erreur BDD"])); }

$musicDir = MUSIC_DIR;
$coverDir = COVER_DIR;
if(!is_dir($musicDir)) mkdir($musicDir, 0777, true);
if(!is_dir($coverDir)) mkdir($coverDir, 0777, true);

$action = $_GET['action'] ?? '';
$baseUrl = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']) . "/";

// --- SECURITY: Validation constants ---
define('MAX_AUDIO_SIZE',  100 * 1024 * 1024); // 100 MB
define('MAX_IMAGE_SIZE',    5 * 1024 * 1024); // 5 MB
define('MAX_FIELD_LENGTH', 200);              // max length of text fields
define('LOGIN_MAX_ATTEMPTS', 10);             // max attempts over 15 min
define('LOGIN_WINDOW', 900);                  // 15-minute window (seconds)

// --- SECURITY: Rate limiting on logins (per IP) ---
function check_rate_limit($db) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $now = time();
    $window = $now - LOGIN_WINDOW;

    // Clean up old entries
    $db->prepare("DELETE FROM login_attempts WHERE attempt_time < ?")->execute([$window]);

    // Count recent attempts for this IP
    $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempt_time >= ?");
    $stmt->execute([$ip, $window]);
    $count = (int)$stmt->fetchColumn();

    return $count < LOGIN_MAX_ATTEMPTS;
}

function record_login_attempt($db) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $db->prepare("INSERT INTO login_attempts (ip, attempt_time) VALUES (?, ?)")->execute([$ip, time()]);
}

// --- SECURITY: Validation and sanitization of text fields ---
function sanitize_text($value, $max_length = MAX_FIELD_LENGTH) {
    $value = trim($value);
    if (mb_strlen($value) > $max_length) {
        $value = mb_substr($value, 0, $max_length);
    }
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// --- SECURITY: Check the real MIME type of an audio file ---
function is_valid_audio($path, $ext) {
    $allowedExts = ['mp3', 'wav', 'ogg', 'flac'];
    if (!in_array($ext, $allowedExts)) return false;

    $fp = fopen($path, 'rb');
    if (!$fp) return false;
    $sig = fread($fp, 12);
    fclose($fp);

    // MP3: frame sync or ID3
    if (substr($sig, 0, 3) === 'ID3') return true;
    if ((ord($sig[0]) === 0xFF) && ((ord($sig[1]) & 0xE0) === 0xE0)) return true;
    // WAV : RIFF....WAVE
    if (substr($sig, 0, 4) === 'RIFF' && substr($sig, 8, 4) === 'WAVE') return true;
    // OGG
    if (substr($sig, 0, 4) === 'OggS') return true;
    // FLAC
    if (substr($sig, 0, 4) === 'fLaC') return true;

    return false;
}

// --- SECURITY: Strict authentication function for the API ---
function authenticate_api_user($db) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        return false;
    }
    
    $stmt = $db->prepare("SELECT id, username, password, is_admin FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        return [
            'id' => $user['id'],
            'username' => $user['username'],
            'is_admin' => isset($user['is_admin']) && $user['is_admin'] == 1
        ];
    }
    return false;
}

// --- TASTE AFFINITY: listening history (weighted by recency) + playlists
// (deliberate curation, stronger fixed weight). Used by action=recommend and
// action=user_affinity to score candidate tracks by
// genre/artist/album proximity to what the user actually listens to.
function compute_user_affinity($db, $auth) {
    $genreAffinity = []; $artistAffinity = []; $albumAffinity = []; $ownedIds = [];
    if (!$auth) return [$genreAffinity, $artistAffinity, $albumAffinity, $ownedIds];

    $stmt = $db->query("SELECT tracks.id, tracks.genre, tracks.artist, tracks.album_id FROM tracks");
    $byId = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) $byId[(int)$t['id']] = $t;

    $hstmt = $db->prepare("SELECT track_id FROM listen_history WHERE user_id = ? ORDER BY played_at DESC LIMIT 200");
    $hstmt->execute([$auth['id']]);
    $history = $hstmt->fetchAll(PDO::FETCH_COLUMN);
    $histCount = count($history);
    foreach ($history as $rank => $tid) {
        $tid = (int)$tid;
        if (!isset($byId[$tid])) continue;
        $t = $byId[$tid];
        $weight = max(0.2, 1 - ($rank / max(1, $histCount)));
        if (!empty($t['genre']))    $genreAffinity[$t['genre']]     = ($genreAffinity[$t['genre']] ?? 0) + $weight;
        if (!empty($t['artist']))   $artistAffinity[$t['artist']]   = ($artistAffinity[$t['artist']] ?? 0) + $weight;
        if (!empty($t['album_id'])) $albumAffinity[$t['album_id']] = ($albumAffinity[$t['album_id']] ?? 0) + $weight;
    }

    $pstmt = $db->prepare("SELECT song_ids FROM playlists WHERE creator_id = ?");
    $pstmt->execute([$auth['id']]);
    foreach ($pstmt->fetchAll(PDO::FETCH_COLUMN) as $songIds) {
        foreach (array_filter(explode(',', (string)$songIds)) as $rawId) {
            $tid = (int)$rawId;
            if ($tid <= 0 || !isset($byId[$tid])) continue;
            $ownedIds[$tid] = true;
            $t = $byId[$tid];
            if (!empty($t['genre']))    $genreAffinity[$t['genre']]     = ($genreAffinity[$t['genre']] ?? 0) + 4;
            if (!empty($t['artist']))   $artistAffinity[$t['artist']]   = ($artistAffinity[$t['artist']] ?? 0) + 4;
            if (!empty($t['album_id'])) $albumAffinity[$t['album_id']] = ($albumAffinity[$t['album_id']] ?? 0) + 4;
        }
    }

    return [$genreAffinity, $artistAffinity, $albumAffinity, $ownedIds];
}

// --- ALBUMS: Gets an album's ID by name, creates it if missing ---
function getOrCreateAlbum($db, $name) {
    $stmt = $db->prepare("SELECT id FROM albums WHERE name = ?");
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;

    try {
        $db->prepare("INSERT INTO albums (name) VALUES (?)")->execute([$name]);
        return (int)$db->lastInsertId();
    } catch (Exception $e) {
        // Concurrency: another upload just created this album between the SELECT and the INSERT
        $stmt->execute([$name]);
        return (int)$stmt->fetchColumn();
    }
}

// --- COMPUTES THE DURATION FOR MULTIPLE FORMATS ---
function calculateAudioDuration($path) {
    if (!file_exists($path)) return 0;
    $fp = fopen($path, 'rb');
    if (!$fp) return 0;

    $signature = fread($fp, 4);
    
    // --- 1. NATIVE FLAC CASE ---
    if ($signature === 'fLaC') {
        fseek($fp, 8);
        $streamInfo = fread($fp, 34);
        fclose($fp);
        
        if (strlen($streamInfo) === 34) {
            $fields = unpack('N3', substr($streamInfo, 10, 12));
            $sampleRate = ($fields[1] >> 12) & 0xFFFFF;
            $totalSamples = (($fields[1] & 0x00F) << 32) | $fields[2];
            if ($sampleRate > 0) {
                return round($totalSamples / $sampleRate);
            }
        }
        return 0;
    }
    
    // --- 2. M4A / MP4 / AAC CONTAINER CASE ---
    if (strpos($signature, 'ftyp') !== false || substr($signature, 1, 3) === 'ftyp') {
        fseek($fp, 0);
        $content = fread($fp, 1024 * 400);
        $mvhdPos = strpos($content, 'mvhd');
        fclose($fp);
        
        if ($mvhdPos !== false) {
            $version = ord($content[$mvhdPos + 4]);
            $timeScaleOffset = ($version === 1) ? 20 : 12;
            $durationOffset = ($version === 1) ? 24 : 16;
            
            $timeScale = unpack('N', substr($content, $mvhdPos + 4 + $timeScaleOffset, 4))[1];
            $durationUnits = unpack('N', substr($content, $mvhdPos + 4 + $durationOffset, 4))[1];
            
            if ($timeScale > 0) {
                return round($durationUnits / $timeScale);
            }
        }
        return 0;
    }

    // --- 3. TRADITIONAL MP3 CASE (CBR/VBR) ---
    fseek($fp, 0);
    $header = fread($fp, 10);
    if (substr($header, 0, 3) === 'ID3') {
        $b = unpack('C*', substr($header, 6, 4));
        $tagSize = ($b[1] << 21) | ($b[2] << 14) | ($b[3] << 7) | $b[4];
        fseek($fp, $tagSize + 10);
    } else {
        fseek($fp, 0);
    }

    $data = fread($fp, 1024 * 200);
    $offset = 0;
    while ($offset < strlen($data) - 4) {
        if (ord($data[$offset]) === 0xFF && (ord($data[$offset+1]) & 0xE0) === 0xE0) {
            $byte1 = ord($data[$offset+1]);
            $byte2 = ord($data[$offset+2]);
            $mpegVersion = ($byte1 >> 3) & 0x03;
            
            $channelMode = ($byte2 >> 6) & 0x03;
            $xingOffset = ($mpegVersion === 3) ? (($channelMode === 3) ? 17 : 32) : (($channelMode === 3) ? 9 : 17);
            $vbrCheck = substr($data, $offset + 4 + $xingOffset, 4);
            
            if ($vbrCheck === 'Xing' || $vbrCheck === 'Info') {
                $flags = unpack('N', substr($data, $offset + 4 + $xingOffset + 4, 4))[1];
                if ($flags & 0x01) {
                    $frameCount = unpack('N', substr($data, $offset + 4 + $xingOffset + 8, 4))[1];
                    $srTable = [3 => [44100, 48000, 32000, 0], 2 => [22050, 24000, 16000, 0]];
                    $sampleRate = $srTable[$mpegVersion][($byte2 >> 2) & 0x03] ?? 44100;
                    $samplesPerFrame = ($mpegVersion === 3) ? 1152 : 576;
                    fclose($fp);
                    if ($sampleRate > 0) return round(($frameCount * $samplesPerFrame) / $sampleRate);
                }
            }
            
            $brTable = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0];
            $bitrate = $brTable[($byte2 >> 4) & 0x0F] ?? 128;
            fclose($fp);
            if ($bitrate > 0) return round((filesize($path) * 8) / ($bitrate * 1000));
            break;
        }
        $offset++;
    }

    fclose($fp);
    return round((filesize($path) * 8) / (128 * 1000));
}

// --- HELPER METADATA (ROBUSTE) ---
function extractMp3Data($path) {
    if (!file_exists($path)) return ['artist'=>null, 'title'=>null, 'album'=>null, 'cover'=>null];
    $f = fopen($path, 'rb');
    if (!$f) return ['artist'=>null, 'title'=>null, 'album'=>null, 'cover'=>null];

    $header = fread($f, 10);
    if (substr($header, 0, 3) !== 'ID3') { fclose($f); return ['artist'=>null, 'title'=>null, 'album'=>null, 'cover'=>null]; }

    $majorVersion = ord($header[3]);
    $b = unpack('C*', substr($header, 6, 4));
    $tagSize = ($b[1] << 21) | ($b[2] << 14) | ($b[3] << 7) | $b[4];
    // An ID3v2 tag that is present but empty (size 0) is a valid file — some
    // encoders write an ID3v2 header with no frames. fread() rejects a
    // length of 0 since PHP 8.1 (ValueError), which made the whole
    // upload fail (not just auto-detection) instead of simply finding
    // nothing to extract.
    if ($tagSize <= 0) { fclose($f); return ['artist'=>null, 'title'=>null, 'album'=>null, 'cover'=>null]; }
    $tagData = fread($f, $tagSize);
    fclose($f);

    $result = ['cover' => null, 'artist' => null, 'title' => null, 'album' => null];

    // ID3v2.2: 6-byte frame headers (3-letter ID, classic 3-byte
    // size, no flags) — a different format from v2.3/v2.4,
    // still found in old files tagged by legacy
    // tools; without this branch, TP1/TT2/TAL were never
    // recognized and artist/title/album were always left empty.
    if ($majorVersion <= 2) {
        $pos = 0;
        $names = ['TP1' => 'artist', 'TT2' => 'title', 'TAL' => 'album'];
        while ($pos < strlen($tagData) - 6) {
            $frameName = substr($tagData, $pos, 3);
            $sb = unpack('C3', substr($tagData, $pos + 3, 3));
            $frameSize = ($sb[1] << 16) | ($sb[2] << 8) | $sb[3];
            if ($frameSize <= 0 || $frameName === "\x00\x00\x00") break;

            $body = substr($tagData, $pos + 6, $frameSize);
            if (isset($names[$frameName]) && strlen($body) > 1) {
                $result[$names[$frameName]] = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', substr($body, 1)));
            } elseif ($frameName === 'PIC') {
                $jpgPos = strpos($body, "\xFF\xD8"); $pngPos = strpos($body, "\x89PNG");
                $start = false; $mime = 'image/jpeg';
                if ($jpgPos !== false && ($pngPos === false || $jpgPos < $pngPos)) { $start = $jpgPos; }
                elseif ($pngPos !== false) { $start = $pngPos; $mime = 'image/png'; }
                if ($start !== false) $result['cover'] = ['mime' => $mime, 'data' => substr($body, $start)];
            }
            $pos += 6 + $frameSize;
        }
        return $result;
    }

    // ID3v2.3 / ID3v2.4: 10-byte frame headers (4-letter ID,
    // 4-byte size, 2 bytes of flags). Only the size encoding
    // changes: a classic 32-bit integer in v2.3, "synchsafe" (7 useful
    // bits per byte, like the tag size itself) in v2.4 —
    // reading it as a classic integer in v2.4 overestimates the size
    // and shifts the reading of all following frames, which typically
    // lost the title/album as soon as an artist or a cover
    // exceeded 127 bytes.
    $pos = 0;
    while ($pos < strlen($tagData) - 10) {
        $frameHeader = substr($tagData, $pos, 10);
        $frameName = substr($frameHeader, 0, 4);
        $sizeBytes = substr($frameHeader, 4, 4);
        if ($majorVersion >= 4) {
            $sb = unpack('C4', $sizeBytes);
            $frameSize = ($sb[1] << 21) | ($sb[2] << 14) | ($sb[3] << 7) | $sb[4];
        } else {
            $s = unpack('N', $sizeBytes);
            $frameSize = $s[1];
        }

        if ($frameSize <= 0 || $frameName === "\x00\x00\x00\x00") break;

        if ($frameName === 'TPE1') {
            $body = substr($tagData, $pos + 10, $frameSize);
            if(strlen($body) > 1) $result['artist'] = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', substr($body, 1)));
        }
        if ($frameName === 'TIT2') {
            $body = substr($tagData, $pos + 10, $frameSize);
            if(strlen($body) > 1) $result['title'] = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', substr($body, 1)));
        }
        if ($frameName === 'TALB') {
            $body = substr($tagData, $pos + 10, $frameSize);
            if(strlen($body) > 1) $result['album'] = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', substr($body, 1)));
        }
        if ($frameName === 'APIC') {
            $body = substr($tagData, $pos + 10, $frameSize);
            $nullPos = strpos($body, "\x00", 1);
            if ($nullPos !== false) {
                $jpgPos = strpos($body, "\xFF\xD8");
                $pngPos = strpos($body, "\x89PNG");
                
                $start = false; $mime = 'image/jpeg';
                if($jpgPos !== false && ($pngPos === false || $jpgPos < $pngPos)) { $start = $jpgPos; }
                elseif($pngPos !== false) { $start = $pngPos; $mime = 'image/png'; }
                
                if($start !== false) {
                    $result['cover'] = ['mime' => $mime, 'data' => substr($body, $start)];
                }
            }
        }
        $pos += 10 + $frameSize;
    }
    return $result;
}

// --- OPTIMIZATION: Function to compress covers ---
function optimizeImage($sourcePath, $destinationPath, $mime = null) {
    if (!extension_loaded('gd')) return move_uploaded_file($sourcePath, $destinationPath);
    
    $info = getimagesize($sourcePath);
    if (!$info) return false;
    $mime = $mime ?? $info['mime'];
    
    switch ($mime) {
        case 'image/jpeg': $image = imagecreatefromjpeg($sourcePath); break;
        case 'image/png': $image = imagecreatefrompng($sourcePath); break;
        case 'image/webp': $image = imagecreatefromwebp($sourcePath); break;
        case 'image/gif': $image = imagecreatefromgif($sourcePath); break;
        default: return false;
    }
    
    if (!$image) return false;

    $width = imagesx($image); $height = imagesy($image); $max_size = 300;
    
    if ($width > $max_size || $height > $max_size) {
        $ratio = min($max_size / $width, $max_size / $height);
        $new_width = round($width * $ratio);
        $new_height = round($height * $ratio);
        $new_image = imagecreatetruecolor($new_width, $new_height);
        
        if ($mime == 'image/png') {
            imagealphablending($new_image, false);
            imagesavealpha($new_image, true);
        }
        imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
        imagedestroy($image);
        $image = $new_image;
    }

    $success = imagewebp($image, $destinationPath, 80);
    imagedestroy($image);
    if (!$success) move_uploaded_file($sourcePath, $destinationPath);
    return true;
}

switch($action) {
    case 'login':
        // --- SECURITY: Rate limiting on login attempts ---
        if (!check_rate_limit($db)) {
            http_response_code(429);
            echo json_encode(["status" => "error", "message" => "Trop de tentatives. Réessayez dans 15 minutes."]);
            exit;
        }
        record_login_attempt($db);

        $auth = authenticate_api_user($db);
        if ($auth) {
            echo json_encode(["status" => "success", "user_id" => $auth['id'], "username" => $auth['username'], "is_admin" => $auth['is_admin']]);
        } else {
            echo json_encode(["status" => "error", "message" => "Identifiants invalides"]);
        }
        break;

    case 'register':
        $u = $_POST['username'] ?? ''; $p = $_POST['password'] ?? '';
        if(empty($u) || empty($p)) { echo json_encode(["status" => "error", "message" => "Données manquantes"]); exit; }

        // --- SECURITY: Username/password length validation ---
        if (mb_strlen($u) > 50) { echo json_encode(["status" => "error", "message" => "Nom d'utilisateur trop long (50 caractères max)"]); exit; }
        if (mb_strlen($p) < 6)  { echo json_encode(["status" => "error", "message" => "Mot de passe trop court (6 caractères min)"]); exit; }
        if (mb_strlen($p) > 200) { echo json_encode(["status" => "error", "message" => "Mot de passe trop long"]); exit; }

        $u = htmlspecialchars(trim($u), ENT_QUOTES, 'UTF-8');
        try { 
            $db->prepare("INSERT INTO users (username, password) VALUES (?, ?)")->execute([$u, password_hash($p, PASSWORD_DEFAULT)]);
            echo json_encode(["status" => "success"]); 
        } catch(Exception $e) { 
            echo json_encode(["status" => "error", "message" => "Nom d'utilisateur déjà pris"]); 
        }
        break;

    case 'list':
        $stmt = $db->query("SELECT tracks.id, tracks.title, tracks.artist, tracks.cover, tracks.genre, tracks.album_id, albums.name AS album, tracks.play_count, tracks.duration, tracks.uploader_id FROM tracks LEFT JOIN albums ON tracks.album_id = albums.id ORDER BY play_count DESC, tracks.id DESC");
        $tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($tracks as &$t) {
            $t['cover_url'] = $baseUrl . "api.php?action=cover&q=" . $t['id'] . "&t=" . time();
            $t['stream_url'] = $baseUrl . "api.php?action=stream&q=" . $t['id'];
        }
        echo json_encode($tracks);
        break;

    case 'albums':
        $stmt = $db->query("SELECT a.id, a.name, (SELECT COUNT(*) FROM tracks t WHERE t.album_id = a.id) AS track_count FROM albums a ORDER BY a.name ASC");
        $albums = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($albums as &$a) {
            $a['cover_url'] = $baseUrl . "api.php?action=album_cover&q=" . $a['id'] . "&t=" . time();
        }
        echo json_encode($albums);
        break;

    case 'album_tracks':
        $aid = filter_var($_GET['q'] ?? 0, FILTER_VALIDATE_INT);
        $stmt = $db->prepare("SELECT tracks.id, tracks.title, tracks.artist, tracks.cover, tracks.genre, tracks.album_id, tracks.play_count, tracks.duration, tracks.uploader_id FROM tracks WHERE album_id = ? ORDER BY tracks.id ASC");
        $stmt->execute([$aid]);
        $tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($tracks as &$t) {
            $t['cover_url'] = $baseUrl . "api.php?action=cover&q=" . $t['id'] . "&t=" . time();
            $t['stream_url'] = $baseUrl . "api.php?action=stream&q=" . $t['id'];
        }
        echo json_encode($tracks);
        break;

    case 'recommend':
        // --- Taste affinity recommendation --------------------------------
        // The user's taste profile combines two signals:
        //  1. Their actual listening history (listen_history), weighted by
        //     recency: recently played tracks weigh more than
        //     older ones, and every listen counts (not just distinct ones),
        //     so the artists/genres they listen to often dominate.
        //  2. The tracks they explicitly put in their playlists,
        //     a stronger signal than a simple listen (deliberate curation).
        // Each candidate track is then scored by genre/artist/album
        // proximity to this profile, plus a bit of global popularity and
        // a random share (diversity, the list is never frozen). Without a profile
        // (anonymous user, or a new one with no history/playlist), the
        // score falls back to popularity + randomness, far more relevant
        // than a uniform draw over the whole library.
        $auth = authenticate_api_user($db);

        $limit = filter_var($_POST['limit'] ?? 15, FILTER_VALIDATE_INT);
        if ($limit === false || $limit <= 0) $limit = 15;
        $limit = min($limit, 50);

        $stmt = $db->query("SELECT tracks.id, tracks.title, tracks.artist, tracks.cover, tracks.genre, tracks.album_id, albums.name AS album, tracks.play_count, tracks.duration, tracks.uploader_id FROM tracks LEFT JOIN albums ON tracks.album_id = albums.id");
        $tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$tracks) { echo json_encode([]); break; }

        $byId = [];
        foreach ($tracks as $t) $byId[(int)$t['id']] = $t;

        // Taste affinity (history weighted by recency + playlists) via the
        // helper shared with action=user_affinity — same logic, computed
        // only once. $byId above stays unused by the helper (it
        // runs its own minimal query), but we keep it for the rest
        // of the block below that needs it.
        [$genreAffinity, $artistAffinity, $albumAffinity, $ownedIds] = compute_user_affinity($db, $auth);

        // Pure affinity score (no randomness mixed in): a small
        // jitter added to the score and then sorted isn't enough to vary
        // the result from one call to the next as soon as a real affinity signal
        // exists (the score gap between relevant tracks and the rest
        // far exceeds the jitter's amplitude, so the sort almost always
        // lands on the same order — this is what made the
        // recommendations frozen for an account with history).
        $scored = [];
        foreach ($tracks as $t) {
            if (isset($ownedIds[(int)$t['id']])) continue;
            $score  = ($genreAffinity[$t['genre']] ?? 0) * 5;
            $score += ($artistAffinity[$t['artist']] ?? 0) * 8;
            $score += ($albumAffinity[$t['album_id']] ?? 0) * 6;
            $score += log(1 + (int)$t['play_count']) * 1.5;
            $t['_score'] = $score;
            $scored[] = $t;
        }
        usort($scored, fn($a, $b) => $b['_score'] <=> $a['_score']);

        // We keep a pool of candidates larger than $limit (the best
        // scored, so still relevant), then shuffle it and draw
        // $limit from it: the selection AND its order change on every call, while
        // still being drawn from tracks that actually match the
        // taste profile rather than from the whole library.
        $poolSize = min(count($scored), max($limit * 3, $limit + 15));
        $pool = array_slice($scored, 0, $poolSize);
        shuffle($pool);
        $result = array_slice($pool, 0, $limit);

        foreach ($result as &$t) {
            unset($t['_score']);
            $t['cover_url'] = $baseUrl . "api.php?action=cover&q=" . $t['id'] . "&t=" . time();
            $t['stream_url'] = $baseUrl . "api.php?action=stream&q=" . $t['id'];
        }
        echo json_encode(array_values($result));
        break;

    case 'user_affinity':
        // --- Raw taste profile, for the client-side queue
        // engine ---------------------------------------------------------------
        // Just returns the genre/artist/album affinity maps (same computation
        // as action=recommend, via the shared helper) with no scoring or
        // track selection: the client already has the whole catalog
        // (ALL_MUSIC_DATA) and builds the contextual queue itself, it only
        // needs this small personal preference signal on top.
        // Anonymous user: empty maps (neutral behavior, no error).
        $auth = authenticate_api_user($db);
        [$genreAffinity, $artistAffinity, $albumAffinity] = compute_user_affinity($db, $auth);
        echo json_encode([
            'genre'  => (object)$genreAffinity,
            'artist' => (object)$artistAffinity,
            'album'  => (object)$albumAffinity,
        ]);
        break;

    case 'history':
        // --- User's actual listening history ------------------------------
        // One row per track (not per listen): replaying it several times
        // doesn't duplicate the entry, only the last listen date is
        // used for ordering (most recent first), like a classic "recently
        // played" rather than a raw log of every playback.
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $limit = filter_var($_POST['limit'] ?? 100, FILTER_VALIDATE_INT);
        if ($limit === false || $limit <= 0) $limit = 100;
        $limit = min($limit, 300);

        $stmt = $db->prepare("
            SELECT tracks.id, tracks.title, tracks.artist, tracks.cover, tracks.genre, tracks.album_id, albums.name AS album, tracks.play_count, tracks.duration, tracks.uploader_id, MAX(lh.played_at) AS last_played
            FROM listen_history lh
            JOIN tracks ON tracks.id = lh.track_id
            LEFT JOIN albums ON tracks.album_id = albums.id
            WHERE lh.user_id = ?
            GROUP BY tracks.id
            ORDER BY last_played DESC
            LIMIT " . (int)$limit
        );
        $stmt->execute([$auth['id']]);
        $tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tracks as &$t) {
            unset($t['last_played']);
            $t['cover_url'] = $baseUrl . "api.php?action=cover&q=" . $t['id'] . "&t=" . time();
            $t['stream_url'] = $baseUrl . "api.php?action=stream&q=" . $t['id'];
        }
        echo json_encode($tracks);
        break;

    case 'increment_play':
        // --- SECURITY: Authentication required to increment ---
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $track_id = filter_var($_POST['track_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($track_id === false || $track_id <= 0) { echo json_encode(["status" => "error", "message" => "ID invalide"]); exit; }

        $stmt = $db->prepare("UPDATE tracks SET play_count = play_count + 1 WHERE id = ?");
        $stmt->execute([$track_id]);
        $db->prepare("INSERT INTO listen_history (user_id, track_id) VALUES (?, ?)")->execute([$auth['id'], $track_id]);
        echo json_encode(["status" => "success"]);
        break;

    case 'stream':
        $stmt = $db->prepare("SELECT filename FROM tracks WHERE id = ?"); 
        $stmt->execute([$_GET['q'] ?? 0]); 
        $t = $stmt->fetch();
        
        if($t && !empty($t['filename'])) { 
            $safeFilename = basename($t['filename']);
            $path = $musicDir . '/' . $safeFilename;

            if (file_exists($path)) {
                $size = filesize($path);
                
                $fp = @fopen($path, 'rb');
                if (!$fp) { header("HTTP/1.1 500 Internal Server Error"); exit; }

                $start = 0; $end = $size - 1;

                if (isset($_SERVER['HTTP_RANGE'])) {
                    $c_start = $start; $c_end = $end;
                    list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
                    if (strpos($range, ',') !== false) { header('HTTP/1.1 416 Requested Range Not Satisfiable'); header("Content-Range: bytes $start-$end/$size"); exit; }
                    if ($range == '-') { $c_start = $size - substr($range, 1); }
                    else {
                        $range = explode('-', $range);
                        $c_start = $range[0];
                        $c_end = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $size;
                    }
                    $c_end = ($c_end > $end) ? $end : $c_end;
                    if ($c_start > $c_end || $c_start > $size - 1 || $c_end >= $size) {
                        header('HTTP/1.1 416 Requested Range Not Satisfiable'); header("Content-Range: bytes $start-$end/$size"); exit;
                    }
                    $start = $c_start; $end = $c_end; $length = $end - $start + 1;
                    fseek($fp, $start);
                    header('HTTP/1.1 206 Partial Content'); header("Content-Range: bytes $start-$end/$size");
                } else {
                    $length = $size; header('HTTP/1.1 200 OK');
                }

                header('Content-Type: audio/mpeg'); header('Accept-Ranges: bytes'); header('Content-Length: ' . $length); header('Cache-Control: no-cache, must-revalidate');
                @set_time_limit(1800); 

                $buffer = 1024 * 16;
                while(!feof($fp) && ($p = ftell($fp)) <= $end) {
                    if ($p + $buffer > $end) $buffer = $end - $p + 1;
                    echo fread($fp, $buffer); flush();
                }
                fclose($fp); exit; 
            }
        }
        header("HTTP/1.0 404 Not Found"); exit;

    case 'cover':
        $stmt = $db->prepare("SELECT cover FROM tracks WHERE id = ?"); $stmt->execute([$_GET['q']??0]); $t=$stmt->fetch();
        $coverName = ($t && !empty($t['cover'])) ? basename($t['cover']) : 'default.png';
        $path = $coverDir . '/' . $coverName;
        if(!file_exists($path)) $path = $coverDir . '/default.png';
        
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = 'image/jpeg';
        if ($ext === 'webp') $mime = 'image/webp';
        elseif ($ext === 'png') $mime = 'image/png';
        elseif ($ext === 'gif') $mime = 'image/gif';
        
        header("Content-Type: " . $mime); readfile($path); exit;

    case 'album_cover':
        $aid = filter_var($_GET['q'] ?? 0, FILTER_VALIDATE_INT);
        $stmt = $db->prepare("SELECT cover FROM albums WHERE id = ?"); $stmt->execute([$aid]); $alb = $stmt->fetch();

        $coverName = null;
        if ($alb && !empty($alb['cover'])) {
            // Cover manually imported for the album
            $coverName = basename($alb['cover']);
        } else {
            // No imported cover: use the one from the album's most recent track
            $t = $db->prepare("SELECT cover FROM tracks WHERE album_id = ? AND cover IS NOT NULL AND cover != '' ORDER BY id DESC LIMIT 1");
            $t->execute([$aid]);
            $recent = $t->fetch();
            if ($recent && !empty($recent['cover'])) $coverName = basename($recent['cover']);
        }

        $path = $coverName ? $coverDir . '/' . $coverName : null;
        if (!$path || !file_exists($path)) $path = $coverDir . '/default.png';

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = 'image/jpeg';
        if ($ext === 'webp') $mime = 'image/webp';
        elseif ($ext === 'png') $mime = 'image/png';
        elseif ($ext === 'gif') $mime = 'image/gif';

        header("Content-Type: " . $mime); readfile($path); exit;

    case 'upload':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé. Identifiants invalides."]); exit; }

        if(isset($_FILES['music'])) {
            $file = $_FILES['music'];

            // --- SECURITY: Audio file size check ---
            if ($file['size'] > MAX_AUDIO_SIZE) {
                echo json_encode(["status" => "error", "message" => "Fichier audio trop volumineux (100 Mo max)"]); exit;
            }
            
            $audioExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            // --- SECURITY: Check the real MIME type of the audio file ---
            if (!is_valid_audio($file['tmp_name'], $audioExt)) {
                echo json_encode(["status" => "error", "message" => "Format audio invalide ou non autorisé."]); exit;
            }

            $meta = extractMp3Data($file['tmp_name']);
            $fn = bin2hex(random_bytes(8)) . '.' . $audioExt;
            
            // --- SECURITY: Validation and truncation of text fields ---
            $ti = !empty($_POST['title']) ? $_POST['title'] : (!empty($meta['title']) ? $meta['title'] : pathinfo($file['name'], PATHINFO_FILENAME));
            $ar = !empty($_POST['artist']) ? $_POST['artist'] : (!empty($meta['artist']) ? $meta['artist'] : "Inconnu");
            $ge = !empty($_POST['genre']) ? $_POST['genre'] : 'Autre';
            // By default a track has no album; otherwise we try to read it from the mp3 tags
            $al = !empty($_POST['album']) ? $_POST['album'] : (!empty($meta['album']) ? $meta['album'] : null);

            $ti = sanitize_text($ti);
            $ar = sanitize_text($ar);
            $ge = sanitize_text($ge, 50);

            $albumId = null;
            if (!empty($al)) {
                $al = sanitize_text($al, 255);
                if ($al !== '') $albumId = getOrCreateAlbum($db, $al);
            }

            $cn = "default.png";
            
            if(!empty($_FILES['cover']['name'])) {
                // --- SECURITY: Image size check ---
                if ($_FILES['cover']['size'] > MAX_IMAGE_SIZE) {
                    echo json_encode(["status" => "error", "message" => "Image de couverture trop volumineuse (5 Mo max)"]); exit;
                }
                $imgExt = strtolower(pathinfo($_FILES['cover']['name'], PATHINFO_EXTENSION));
                $allowedImgExt = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
                if (in_array($imgExt, $allowedImgExt)) {
                    $cn = bin2hex(random_bytes(8)) . ".webp"; 
                    optimizeImage($_FILES['cover']['tmp_name'], $coverDir.'/'.$cn);
                }
            } elseif(!empty($meta['cover']['data'])) {
                $cn = bin2hex(random_bytes(8)) . "_meta.webp";
                $tmpImgPath = sys_get_temp_dir() . '/' . uniqid() . '.tmp';
                file_put_contents($tmpImgPath, $meta['cover']['data']);
                optimizeImage($tmpImgPath, $coverDir.'/'.$cn, $meta['cover']['mime']);
                @unlink($tmpImgPath);
            }

            // --- ALBUM: optional import of a cover dedicated to the album ---
            // (otherwise, as long as no cover is imported, the album shows the cover of its most recent track)
            if ($albumId && !empty($_FILES['album_cover']['name'])) {
                if ($_FILES['album_cover']['size'] > MAX_IMAGE_SIZE) {
                    echo json_encode(["status" => "error", "message" => "Image de couverture d'album trop volumineuse (5 Mo max)"]); exit;
                }
                $albImgExt = strtolower(pathinfo($_FILES['album_cover']['name'], PATHINFO_EXTENSION));
                $allowedImgExt = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
                if (in_array($albImgExt, $allowedImgExt)) {
                    $acn = bin2hex(random_bytes(8)) . "_album.webp";
                    if (optimizeImage($_FILES['album_cover']['tmp_name'], $coverDir.'/'.$acn)) {
                        $oldAlbumCover = $db->prepare("SELECT cover FROM albums WHERE id = ?");
                        $oldAlbumCover->execute([$albumId]);
                        $oldCoverName = $oldAlbumCover->fetchColumn();
                        if (!empty($oldCoverName) && file_exists($coverDir.'/'.$oldCoverName)) unlink($coverDir.'/'.$oldCoverName);
                        $db->prepare("UPDATE albums SET cover = ? WHERE id = ?")->execute([$acn, $albumId]);
                    }
                }
            }

            $duration = calculateAudioDuration($file['tmp_name']);

            if(move_uploaded_file($file['tmp_name'], $musicDir.'/'.$fn)) {
                $db->prepare("INSERT INTO tracks (filename, title, artist, cover, genre, album_id, uploader_id, duration) VALUES (?,?,?,?,?,?,?,?)")->execute([$fn, $ti, $ar, $cn, $ge, $albumId, $auth['id'], $duration]);
                echo json_encode(["status" => "success"]);
            } else echo json_encode(["status" => "error", "message" => "Erreur de déplacement du fichier"]);
        } else echo json_encode(["status" => "error", "message" => "Fichier audio manquant"]);
        break;

    case 'edit_track':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé. Identifiants invalides."]); exit; }

        $tid = filter_var($_POST['track_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($tid === false || $tid <= 0) { echo json_encode(["status" => "error", "message" => "ID de piste invalide"]); exit; }

        $t = $db->prepare("SELECT uploader_id, cover FROM tracks WHERE id=?"); $t->execute([$tid]); $curr = $t->fetch();
        
        if($curr && ($auth['is_admin'] || $curr['uploader_id'] == $auth['id'])) {
            $cleanTitle  = sanitize_text($_POST['title']  ?? '');
            $cleanArtist = sanitize_text($_POST['artist'] ?? '');

            $sets = ["title = ?", "artist = ?"]; $params = [$cleanTitle, $cleanArtist];
            
            if(isset($_POST['new_genre'])) {
                $sets[] = "genre = ?";
                $params[] = sanitize_text($_POST['new_genre'], 50);
            }

            // --- ALBUM: reassignment. Empty string = remove the track from its album ---
            if(isset($_POST['new_album'])) {
                $newAlbumName = sanitize_text($_POST['new_album'], 255);
                if ($newAlbumName === '') {
                    $sets[] = "album_id = NULL";
                } else {
                    $sets[] = "album_id = ?";
                    $params[] = getOrCreateAlbum($db, $newAlbumName);
                }
            }

            if(!empty($_FILES['new_cover']['name'])) {
                // --- SECURITY: Size check of the new cover ---
                if ($_FILES['new_cover']['size'] > MAX_IMAGE_SIZE) {
                    echo json_encode(["status" => "error", "message" => "Image de couverture trop volumineuse (5 Mo max)"]); exit;
                }
                $imgExt = strtolower(pathinfo($_FILES['new_cover']['name'], PATHINFO_EXTENSION));
                $allowedImgExt = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
                if (in_array($imgExt, $allowedImgExt)) {
                    $newCn = bin2hex(random_bytes(8)) . "_edit.webp";
                    if(optimizeImage($_FILES['new_cover']['tmp_name'], $coverDir.'/'.$newCn)) {
                        $sets[] = "cover = ?"; $params[] = $newCn;
                        $oldCover = basename($curr['cover']);
                        if($oldCover != 'default.png' && file_exists($coverDir.'/'.$oldCover)) unlink($coverDir.'/'.$oldCover);
                    }
                }
            }
            $params[] = $tid;
            $db->prepare("UPDATE tracks SET ".implode(', ', $sets)." WHERE id = ?")->execute($params);
            echo json_encode(["status" => "success"]);
        } else echo json_encode(["status" => "error", "message" => "Interdit : Vous n'avez pas les droits sur cette musique"]);
        break;

    case 'delete_track':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé. Identifiants invalides."]); exit; }

        $tid = filter_var($_POST['track_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($tid === false || $tid <= 0) { echo json_encode(["status" => "error", "message" => "ID de piste invalide"]); exit; }

        $t = $db->prepare("SELECT uploader_id, filename, cover FROM tracks WHERE id=?"); $t->execute([$tid]); $curr = $t->fetch();
        
        if($curr && ($auth['is_admin'] || $curr['uploader_id'] == $auth['id'])) {
            $safeMusicFile = basename($curr['filename']);
            $safeCoverFile = basename($curr['cover']);

            if(!empty($safeMusicFile) && file_exists($musicDir.'/'.$safeMusicFile)) unlink($musicDir.'/'.$safeMusicFile);
            if($safeCoverFile != 'default.png' && file_exists($coverDir.'/'.$safeCoverFile)) unlink($coverDir.'/'.$safeCoverFile);
            
            $db->prepare("DELETE FROM tracks WHERE id=?")->execute([$tid]);
            echo json_encode(["status" => "success"]);
        } else echo json_encode(["status" => "error", "message" => "Interdit : Vous n'avez pas les droits sur cette musique"]);
        break;

    case 'edit_album':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé. Identifiants invalides."]); exit; }

        $aid = filter_var($_POST['album_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($aid === false || $aid <= 0) { echo json_encode(["status" => "error", "message" => "ID d'album invalide"]); exit; }

        $a = $db->prepare("SELECT cover FROM albums WHERE id=?"); $a->execute([$aid]); $curr = $a->fetch();
        if (!$curr) { echo json_encode(["status" => "error", "message" => "Album introuvable"]); exit; }

        $sets = []; $params = [];

        if (isset($_POST['name']) && trim($_POST['name']) !== '') {
            $sets[] = "name = ?"; $params[] = sanitize_text($_POST['name'], 255);
        }

        if (!empty($_FILES['cover']['name'])) {
            if ($_FILES['cover']['size'] > MAX_IMAGE_SIZE) {
                echo json_encode(["status" => "error", "message" => "Image de couverture trop volumineuse (5 Mo max)"]); exit;
            }
            $imgExt = strtolower(pathinfo($_FILES['cover']['name'], PATHINFO_EXTENSION));
            $allowedImgExt = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
            if (in_array($imgExt, $allowedImgExt)) {
                $acn = bin2hex(random_bytes(8)) . "_album.webp";
                if (optimizeImage($_FILES['cover']['tmp_name'], $coverDir.'/'.$acn)) {
                    $sets[] = "cover = ?"; $params[] = $acn;
                    if (!empty($curr['cover']) && file_exists($coverDir.'/'.$curr['cover'])) unlink($coverDir.'/'.$curr['cover']);
                }
            }
        }

        if (empty($sets)) { echo json_encode(["status" => "error", "message" => "Aucune modification fournie"]); exit; }

        $params[] = $aid;
        try {
            $db->prepare("UPDATE albums SET ".implode(', ', $sets)." WHERE id = ?")->execute($params);
            echo json_encode(["status" => "success"]);
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => "Ce nom d'album existe déjà"]);
        }
        break;

    case 'playlists':
        // --- VISIBILITY: without valid credentials, only public
        // playlists are returned; an authenticated user also sees
        // their own private playlists; an admin sees everything. ---
        $auth = authenticate_api_user($db);
        if ($auth && $auth['is_admin']) {
            $stmt = $db->query("SELECT p.*, u.username as creator FROM playlists p JOIN users u ON p.creator_id = u.id");
        } elseif ($auth) {
            $stmt = $db->prepare("SELECT p.*, u.username as creator FROM playlists p JOIN users u ON p.creator_id = u.id WHERE p.is_public = 1 OR p.creator_id = ?");
            $stmt->execute([$auth['id']]);
        } else {
            $stmt = $db->query("SELECT p.*, u.username as creator FROM playlists p JOIN users u ON p.creator_id = u.id WHERE p.is_public = 1");
        }
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

    case 'playlist_create':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé. Identifiants invalides."]); exit; }

        $playlistName = sanitize_text($_POST['name'] ?? 'Playlist', 100);
        $isPublic = isset($_POST['is_public']) && ($_POST['is_public'] === '0' || $_POST['is_public'] === 'false') ? 0 : 1;
        $db->prepare("INSERT INTO playlists (name, creator_id, song_ids, is_public) VALUES (?, ?, '', ?)")->execute([$playlistName, $auth['id'], $isPublic]);
        echo json_encode(["status" => "success"]);
        break;

    case 'playlist_mod':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé. Identifiants invalides."]); exit; }

        $pid = filter_var($_POST['playlist_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($pid === false || $pid <= 0) { echo json_encode(["status" => "error", "message" => "ID de playlist invalide"]); exit; }

        $mode = $_POST['mode'] ?? '';
        $p = $db->prepare("SELECT song_ids, creator_id FROM playlists WHERE id=?"); $p->execute([$pid]); $curr = $p->fetch();

        if($curr && ($auth['is_admin'] || $curr['creator_id'] == $auth['id'])) {
            if ($mode === 'delete') {
                $db->prepare("DELETE FROM playlists WHERE id=?")->execute([$pid]);
            } elseif ($mode === 'rename') {
                $newName = sanitize_text($_POST['new_name'] ?? 'Playlist', 100);
                $db->prepare("UPDATE playlists SET name=? WHERE id=?")->execute([$newName, $pid]);
            } elseif ($mode === 'visibility') {
                $isPublic = isset($_POST['is_public']) && ($_POST['is_public'] === '0' || $_POST['is_public'] === 'false') ? 0 : 1;
                $db->prepare("UPDATE playlists SET is_public=? WHERE id=?")->execute([$isPublic, $pid]);
            } elseif ($mode === 'reorder') {
                // --- SECURITY: the new order must contain exactly the same
                // set of tracks as the current one (no addition/removal possible
                // via this mode, reserved for add/remove) ---
                $rawIds = array_filter(explode(',', $curr['song_ids']));
                $currentIds = array_values(array_filter(array_map('intval', $rawIds), fn($v) => $v > 0));

                $rawNewIds = array_filter(explode(',', $_POST['song_ids'] ?? ''));
                $newIds = array_values(array_filter(array_map('intval', $rawNewIds), fn($v) => $v > 0));

                $sortedCurrent = $currentIds; sort($sortedCurrent);
                $sortedNew = $newIds; sort($sortedNew);

                if ($sortedCurrent !== $sortedNew) {
                    echo json_encode(["status" => "error", "message" => "L'ordre fourni ne correspond pas aux pistes actuelles de la playlist"]); exit;
                }

                $db->prepare("UPDATE playlists SET song_ids=? WHERE id=?")->execute([implode(',', $newIds), $pid]);
            } else {
                // --- SECURITY: Strict validation of song_ids (positive integers only) ---
                $rawIds = array_filter(explode(',', $curr['song_ids']));
                $ids = array_filter(array_map('intval', $rawIds), fn($v) => $v > 0);

                $targetId = filter_var($_POST['track_id'] ?? 0, FILTER_VALIDATE_INT);
                if ($targetId === false || $targetId <= 0) {
                    echo json_encode(["status" => "error", "message" => "ID de piste invalide"]); exit;
                }

                if ($mode === 'add' && !in_array($targetId, $ids)) $ids[] = $targetId;
                if ($mode === 'remove') $ids = array_values(array_diff($ids, [$targetId]));

                $db->prepare("UPDATE playlists SET song_ids=? WHERE id=?")->execute([implode(',', $ids), $pid]);
            }
            echo json_encode(["status" => "success"]);
        } else echo json_encode(["status" => "error", "message" => "Interdit : Vous n'avez pas les droits sur cette playlist"]);
        break;
}
?>
