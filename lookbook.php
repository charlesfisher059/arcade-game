<?php
// ============================================================================
// FILE: lookbook.php
// PURPOSE: Diamonds Outta Dirt - Cyberpunk Visual Archive / Lookbook
// VERSION: 4.1.0 (Clean Gallery + Metadata System)
// UPGRADES:
// - Added strict CSP with per-request nonce
// - Added filename search
// - Added month/year filter
// - Added flock()-based engagement read/modify/write safety
// - Added like cooldown (3s per file/user)
// - Added view debounce (10m per file/user)
// - AJAX filters now include q + month
// - Removed explicit cookie domain for better HostGator session reliability
// - Added POST rate limiting + safer CSRF failure logging
// - Added JSON corruption backup before engagement rewrites
// - Added lazy image decoding + absolute AJAX endpoint
// - Added lightbox like fallback when card button is not mounted
// - Added MySQL lookbook metadata: title, caption, tags, model credit, product/exchange links, featured/hidden/sort
// ============================================================================

declare(strict_types=1);

// ----------------------------
// SECURITY & CONFIGURATION
// ----------------------------
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Detect HTTPS (HostGator/forward proxy safe-ish)
$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)($_SERVER['SERVER_PORT']) === 443)
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');

// CSP nonce
$csp_nonce = base64_encode(random_bytes(16));

// Security headers
if (!headers_sent()) {
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header(
        "Content-Security-Policy: "
        . "default-src 'self'; "
        . "base-uri 'self'; "
        . "object-src 'none'; "
        . "frame-ancestors 'self'; "
        . "form-action 'self'; "
        . "img-src 'self' data:; "
        . "media-src 'self'; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' https://fonts.gstatic.com; "
        . "script-src 'self' 'nonce-{$csp_nonce}'; "
        . "connect-src 'self';"
    );
}

// Set session cookie parameters BEFORE starting session
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    $cookieParams = [
        'lifetime' => 86400 * 7,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax'
    ];
    // Do not set an explicit cookie domain here.
    // HostGator/cPanel sites can break sessions when the domain is forced,
    // especially across www/non-www, parked domains, or preview URLs.
    session_set_cookie_params($cookieParams);
}

// Start session (safe)
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// CSRF token
if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || strlen($_SESSION['csrf_token']) < 32) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
$customerLoggedIn = !empty($_SESSION['customer_id'] ?? null);

// Optional database connection for clean Lookbook metadata.
// The public lookbook must still work even if the DB is temporarily unavailable.
$pdo = null;
$dbFile = __DIR__ . '/db_connect.php';
if (is_file($dbFile)) {
    try {
        require_once $dbFile;
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            $pdo = null;
        }
    } catch (Throwable $e) {
        error_log('lookbook metadata DB unavailable: ' . $e->getMessage());
        $pdo = null;
    }
}


// Path configuration
define('DOC_ROOT', realpath($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) ?: __DIR__);
define('BASE_DIR', rtrim(DOC_ROOT, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
define('MEDIA_DIR', BASE_DIR . 'images/lookbook/');
define('MEDIA_URL', '/images/lookbook/');
define('DATA_DIR', BASE_DIR . '_data/');
define('ENGAGEMENT_FILE', DATA_DIR . 'engagement/lookbook.json');
define('THUMBS_DIR', BASE_DIR . 'images/thumbs/');
define('THUMBS_URL', '/images/thumbs/');

// Performance settings
define('ITEMS_PER_PAGE', 36);
define('MAX_SCAN', 2000);
define('OVERSCAN', 5);

// Cooldowns
define('LIKE_COOLDOWN_SECONDS', 3);
define('VIEW_DEBOUNCE_SECONDS', 600);

// POST abuse controls
define('POST_RATE_WINDOW_SECONDS', 60);
define('POST_RATE_MAX_REQUESTS', 40);
define('CSRF_FAIL_LOG_LIMIT_SECONDS', 60);

// Allowed file extensions
define('ALLOWED_EXT', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'mp4', 'webm', 'mov']);
define('IMAGE_EXT', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif']);
define('VIDEO_EXT', ['mp4', 'webm', 'mov']);

// ----------------------------
// HELPER FUNCTIONS
// ----------------------------
function h($text): string {
    if (!is_scalar($text) && $text !== null) {
        $text = '';
    }
    return htmlspecialchars((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function lookbook_db_available(): bool {
    return isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO;
}

function ensure_lookbook_metadata_schema(): void {
    if (!lookbook_db_available()) return;

    try {
        $GLOBALS['pdo']->exec("
            CREATE TABLE IF NOT EXISTS lookbook_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                filename VARCHAR(255) NOT NULL UNIQUE,
                title VARCHAR(180) NULL,
                caption TEXT NULL,
                collection VARCHAR(160) NULL,
                tags VARCHAR(500) NULL,
                model_name VARCHAR(160) NULL,
                model_instagram VARCHAR(255) NULL,
                photographer VARCHAR(160) NULL,
                product_url VARCHAR(255) NULL,
                exchange_url VARCHAR(255) NULL,
                is_featured TINYINT(1) NOT NULL DEFAULT 0,
                is_hidden TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_hidden_featured_sort (is_hidden, is_featured, sort_order),
                INDEX idx_filename (filename)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        error_log('lookbook metadata schema failed: ' . $e->getMessage());
    }
}

function clean_lookbook_title(string $filename): string {
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $base = preg_replace('/[_\\-]+/', ' ', $base) ?? $base;
    $base = preg_replace('/\\s+/', ' ', $base) ?? $base;
    $base = trim($base);
    if ($base === '') return 'Archive Visual';

    if (function_exists('mb_convert_case')) {
        return mb_convert_case($base, MB_CASE_TITLE, 'UTF-8');
    }
    return ucwords(strtolower($base));
}

function normalize_public_url(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (preg_match('~^https?://~i', $url)) return $url;
    if (str_starts_with($url, '/')) return $url;
    return '/' . ltrim($url, '/');
}

function normalize_instagram_url(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    if (str_starts_with($value, '@')) {
        return 'https://www.instagram.com/' . rawurlencode(ltrim($value, '@'));
    }
    if (preg_match('~^[A-Za-z0-9._]{1,30}$~', $value)) {
        return 'https://www.instagram.com/' . rawurlencode($value);
    }
    if (!preg_match('~^https?://~i', $value)) {
        $value = 'https://' . ltrim($value, '/');
    }
    return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
}

function load_lookbook_metadata_map(array $filenames): array {
    $filenames = array_values(array_unique(array_filter($filenames, static fn($v) => is_string($v) && $v !== '')));
    if (empty($filenames) || !lookbook_db_available()) return [];

    ensure_lookbook_metadata_schema();

    try {
        $placeholders = implode(',', array_fill(0, count($filenames), '?'));
        $stmt = $GLOBALS['pdo']->prepare("
            SELECT filename, title, caption, collection, tags, model_name, model_instagram,
                   photographer, product_url, exchange_url, is_featured, is_hidden, sort_order
            FROM lookbook_items
            WHERE filename IN ($placeholders)
        ");
        $stmt->execute($filenames);

        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $filename = (string)($row['filename'] ?? '');
            if ($filename === '') continue;
            $map[$filename] = [
                'title' => trim((string)($row['title'] ?? '')),
                'caption' => trim((string)($row['caption'] ?? '')),
                'collection' => trim((string)($row['collection'] ?? '')),
                'tags' => trim((string)($row['tags'] ?? '')),
                'model_name' => trim((string)($row['model_name'] ?? '')),
                'model_instagram' => normalize_instagram_url((string)($row['model_instagram'] ?? '')),
                'photographer' => trim((string)($row['photographer'] ?? '')),
                'product_url' => normalize_public_url((string)($row['product_url'] ?? '')),
                'exchange_url' => normalize_public_url((string)($row['exchange_url'] ?? '')),
                'is_featured' => (int)($row['is_featured'] ?? 0) === 1,
                'is_hidden' => (int)($row['is_hidden'] ?? 0) === 1,
                'sort_order' => (int)($row['sort_order'] ?? 0),
            ];
        }

        return $map;
    } catch (Throwable $e) {
        error_log('lookbook metadata load failed: ' . $e->getMessage());
        return [];
    }
}

function enrich_lookbook_files_with_metadata(array $files): array {
    if (empty($files)) return $files;

    $names = array_map(static fn($f) => (string)($f['name'] ?? ''), $files);
    $metaMap = load_lookbook_metadata_map($names);

    foreach ($files as &$file) {
        $name = (string)($file['name'] ?? '');
        $meta = $metaMap[$name] ?? [];

        $title = trim((string)($meta['title'] ?? ''));
        if ($title === '') {
            $title = clean_lookbook_title($name);
        }

        $file['title'] = $title;
        $file['caption'] = (string)($meta['caption'] ?? '');
        $file['collection'] = (string)($meta['collection'] ?? '');
        $file['tags'] = (string)($meta['tags'] ?? '');
        $file['model_name'] = (string)($meta['model_name'] ?? '');
        $file['model_instagram'] = (string)($meta['model_instagram'] ?? '');
        $file['photographer'] = (string)($meta['photographer'] ?? '');
        $file['product_url'] = (string)($meta['product_url'] ?? '');
        $file['exchange_url'] = (string)($meta['exchange_url'] ?? '');
        $file['is_featured'] = !empty($meta['is_featured']);
        $file['is_hidden'] = !empty($meta['is_hidden']);
        $file['sort_order'] = (int)($meta['sort_order'] ?? 0);
    }
    unset($file);

    return array_values(array_filter($files, static fn($f) => empty($f['is_hidden'])));
}

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    exit;
}

function safe_basename(string $path): string {
    $name = basename($path);

    if ($name === '' || $name === '.' || $name === '..') {
        return '';
    }

    if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
        return '';
    }

    return $name;
}

function ensure_dir(string $dir): void {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $guard = rtrim($dir, '/\\') . '/index.html';
    if (!file_exists($guard)) {
        @file_put_contents($guard, '<!-- Restricted directory -->');
    }
}

function get_file_extension(string $filename): string {
    return strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
}

function is_allowed_file(string $filename): bool {
    $ext = get_file_extension($filename);
    return in_array($ext, ALLOWED_EXT, true);
}

function get_media_type(string $filename): string {
    $ext = get_file_extension($filename);
    if (in_array($ext, VIDEO_EXT, true)) return 'video';
    if (in_array($ext, IMAGE_EXT, true)) return 'image';
    return 'other';
}

function get_thumb_path(string $filename): string {
    $ext = get_file_extension($filename);

    if (in_array($ext, IMAGE_EXT, true)) {
        $thumb = THUMBS_DIR . $filename;
        return (is_file($thumb) ? (THUMBS_URL . rawurlencode($filename)) : (MEDIA_URL . rawurlencode($filename)));
    }

    if (in_array($ext, VIDEO_EXT, true)) {
        $thumb = THUMBS_DIR . $filename . '.jpg';
        return (is_file($thumb) ? (THUMBS_URL . rawurlencode($filename) . '.jpg') : '/assets/video-thumb.png');
    }

    return '/assets/file-thumb.png';
}

function get_user_key(): string {
    $uid = $_SESSION['user_id'] ?? '';
    if (is_string($uid) && $uid !== '') return $uid;
    return session_id();
}

function get_client_ip_hash(): string {
    $raw = (string)($_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? 'unknown');

    $ip = trim(explode(',', $raw)[0]);
    return hash('sha256', $ip);
}

function get_action_fingerprint(): string {
    return hash('sha256', get_user_key() . '|' . get_client_ip_hash());
}

function get_client_user_agent(): string {
    return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 255);
}

function log_security_event(string $event, array $context = []): void {
    $safeContext = array_merge([
        'ip_hash' => get_client_ip_hash(),
        'ua' => get_client_user_agent(),
    ], $context);

    error_log('[LOOKBOOK_SECURITY] ' . $event . ' - ' . json_encode($safeContext, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG));
}

function post_rate_limit_allowed(): bool {
    if (!isset($_SESSION['lookbook_post_rate']) || !is_array($_SESSION['lookbook_post_rate'])) {
        $_SESSION['lookbook_post_rate'] = [];
    }

    $now = time();
    $windowStart = $now - POST_RATE_WINDOW_SECONDS;
    $_SESSION['lookbook_post_rate'] = array_values(array_filter(
        $_SESSION['lookbook_post_rate'],
        static fn($ts) => is_int($ts) && $ts >= $windowStart
    ));

    if (count($_SESSION['lookbook_post_rate']) >= POST_RATE_MAX_REQUESTS) {
        return false;
    }

    $_SESSION['lookbook_post_rate'][] = $now;
    return true;
}

function should_log_csrf_failure(): bool {
    $now = time();
    $key = 'lookbook_last_csrf_log';
    $last = (int)($_SESSION[$key] ?? 0);

    if ($last > 0 && ($now - $last) < CSRF_FAIL_LOG_LIMIT_SECONDS) {
        return false;
    }

    $_SESSION[$key] = $now;
    return true;
}

function backup_corrupt_engagement_file(string $raw): void {
    if (trim($raw) === '') {
        return;
    }

    ensure_dir(dirname(ENGAGEMENT_FILE) . '/backups');
    $backup = dirname(ENGAGEMENT_FILE) . '/backups/lookbook-corrupt-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
    @file_put_contents($backup, $raw, LOCK_EX);
    log_security_event('ENGAGEMENT_JSON_CORRUPT_BACKUP_CREATED', ['backup' => basename($backup)]);
}

function formatBytes(int $bytes): string {
    if ($bytes <= 0) return '0 B';
    $k = 1024;
    $sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = (int)floor(log($bytes) / log($k));
    $i = max(0, min($i, count($sizes) - 1));
    return round($bytes / pow($k, $i), 1) . ' ' . $sizes[$i];
}

function safe_media_exists(string $filename): bool {
    if ($filename === '' || !is_allowed_file($filename)) return false;
    $path = MEDIA_DIR . $filename;
    $rp = realpath($path);
    $rb = realpath(MEDIA_DIR);
    if ($rp === false || $rb === false) return false;
    $rb = rtrim($rb, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return str_starts_with($rp, $rb) && is_file($rp);
}

function normalize_month_key(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    return preg_match('/^\d{4}-\d{2}$/', $value) ? $value : '';
}

function action_allowed(string $action, string $filename, int $cooldownSeconds): bool {
    if ($filename === '' || $cooldownSeconds < 1) return true;

    if (!isset($_SESSION['lookbook_cooldowns']) || !is_array($_SESSION['lookbook_cooldowns'])) {
        $_SESSION['lookbook_cooldowns'] = [];
    }

    $key = hash('sha256', get_action_fingerprint() . '|' . $action . '|' . $filename);
    $now = time();
    $last = (int)($_SESSION['lookbook_cooldowns'][$key] ?? 0);

    if ($last > 0 && ($now - $last) < $cooldownSeconds) {
        return false;
    }

    $_SESSION['lookbook_cooldowns'][$key] = $now;
    return true;
}

// ----------------------------
// ENGAGEMENT SYSTEM (Likes + Views) with flock()
// ----------------------------
function normalize_engagement_data($data): array {
    if (!is_array($data)) {
        $data = [];
    }

    $data['likes'] = isset($data['likes']) && is_array($data['likes']) ? $data['likes'] : [];
    $data['views'] = isset($data['views']) && is_array($data['views']) ? $data['views'] : [];
    $data['users'] = isset($data['users']) && is_array($data['users']) ? $data['users'] : [];

    return $data;
}

function with_locked_engagement(callable $callback) {
    ensure_dir(dirname(ENGAGEMENT_FILE));

    $fp = @fopen(ENGAGEMENT_FILE, 'c+');
    if (!$fp) {
        return $callback(['likes' => [], 'views' => [], 'users' => []], false);
    }

    try {
        if (!@flock($fp, LOCK_EX)) {
            fclose($fp);
            return $callback(['likes' => [], 'views' => [], 'users' => []], false);
        }

        clearstatcache(true, ENGAGEMENT_FILE);
        rewind($fp);
        $raw = (string)stream_get_contents($fp);
        $decoded = json_decode($raw, true);
        if (trim($raw) !== '' && json_last_error() !== JSON_ERROR_NONE) {
            backup_corrupt_engagement_file($raw);
            $decoded = [];
        }
        $data = normalize_engagement_data($decoded);

        $result = $callback($data, true);

        if (is_array($result) && array_key_exists('__write_data', $result)) {
            $writeData = normalize_engagement_data($result['__write_data']);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($writeData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($fp);
            unset($result['__write_data']);
        }

        flock($fp, LOCK_UN);
        fclose($fp);
        return $result;
    } catch (Throwable $e) {
        @flock($fp, LOCK_UN);
        @fclose($fp);
        return $callback(['likes' => [], 'views' => [], 'users' => []], false);
    }
}

function load_engagement_data(): array {
    $result = with_locked_engagement(function(array $data, bool $locked) {
        return ['data' => $data];
    });

    return is_array($result) && isset($result['data']) && is_array($result['data'])
        ? $result['data']
        : ['likes' => [], 'views' => [], 'users' => []];
}

function increment_view(string $filename): array {
    if (!is_allowed_file($filename)) {
        return ['ok' => false, 'error' => 'Invalid file'];
    }

    if (!action_allowed('view', $filename, VIEW_DEBOUNCE_SECONDS)) {
        return [
            'ok' => true,
            'debounced' => true,
            'views' => get_view_count($filename)
        ];
    }

    $result = with_locked_engagement(function(array $data, bool $locked) use ($filename) {
        $data['views'][$filename] = (int)($data['views'][$filename] ?? 0) + 1;

        return [
            '__write_data' => $data,
            'ok' => true,
            'views' => (int)$data['views'][$filename],
            'debounced' => false
        ];
    });

    return is_array($result) ? $result : ['ok' => false, 'error' => 'Write failed'];
}

function toggle_like(string $filename, string $user_key): array {
    if (!is_allowed_file($filename)) {
        return ['ok' => false, 'error' => 'Invalid file'];
    }

    if (!action_allowed('like', $filename, LIKE_COOLDOWN_SECONDS)) {
        $data = load_engagement_data();
        $likes = (int)($data['likes'][$filename] ?? 0);
        $liked = in_array($filename, (array)($data['users'][$user_key]['likes'] ?? []), true);

        return [
            'ok' => false,
            'error' => 'Cooldown active',
            'cooldown' => true,
            'liked' => $liked,
            'likes' => $likes
        ];
    }

    $result = with_locked_engagement(function(array $data, bool $locked) use ($filename, $user_key) {
        if (!isset($data['users'][$user_key]) || !is_array($data['users'][$user_key])) {
            $data['users'][$user_key] = ['likes' => []];
        }
        if (!isset($data['users'][$user_key]['likes']) || !is_array($data['users'][$user_key]['likes'])) {
            $data['users'][$user_key]['likes'] = [];
        }

        if (!isset($data['likes'][$filename]) || !is_int($data['likes'][$filename])) {
            $data['likes'][$filename] = (int)($data['likes'][$filename] ?? 0);
        }

        $user_likes = &$data['users'][$user_key]['likes'];
        $liked = false;

        $key = array_search($filename, $user_likes, true);
        if ($key !== false) {
            array_splice($user_likes, (int)$key, 1);
            $data['likes'][$filename] = max(0, (int)$data['likes'][$filename] - 1);
        } else {
            $user_likes[] = $filename;
            $data['likes'][$filename] = (int)$data['likes'][$filename] + 1;
            $liked = true;
        }

        return [
            '__write_data' => $data,
            'ok' => true,
            'liked' => $liked,
            'likes' => (int)$data['likes'][$filename],
        ];
    });

    return is_array($result) ? $result : ['ok' => false, 'error' => 'Write failed'];
}

function get_view_count(string $filename): int {
    $data = load_engagement_data();
    return (int)($data['views'][$filename] ?? 0);
}

// ----------------------------
// FILE SCANNING & PAGINATION
// ----------------------------
function scan_media_files(): array {
    $files = [];
    if (!is_dir(MEDIA_DIR)) {
        ensure_dir(MEDIA_DIR);
        return $files;
    }

    $baseReal = realpath(MEDIA_DIR);
    if ($baseReal === false) return $files;
    $baseReal = rtrim($baseReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    $iterator = new FilesystemIterator(MEDIA_DIR, FilesystemIterator::SKIP_DOTS);
    $count = 0;

    foreach ($iterator as $fileinfo) {
        if ($count >= MAX_SCAN) break;
        if (!$fileinfo->isFile()) continue;

        $filename = $fileinfo->getFilename();
        if ($filename === '' || $filename[0] === '.') continue;
        if (!is_allowed_file($filename)) continue;

        $rp = $fileinfo->getRealPath();
        if ($rp === false || !str_starts_with($rp, $baseReal)) continue;

        $mtime = (int)$fileinfo->getMTime();
        $files[] = [
            'name' => $filename,
            'path' => MEDIA_DIR . $filename,
            'url' => MEDIA_URL . rawurlencode($filename),
            'thumb' => get_thumb_path($filename),
            'type' => get_media_type($filename),
            'size' => (int)$fileinfo->getSize(),
            'modified' => $mtime,
            'date' => date('Y-m', $mtime)
        ];
        $count++;
    }

    return $files;
}

function sort_files(array $files, string $sort = 'newest'): array {
    switch ($sort) {
        case 'oldest':
            usort($files, fn($a, $b) => ($a['modified'] ?? 0) <=> ($b['modified'] ?? 0));
            break;
        case 'name':
            usort($files, fn($a, $b) => strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
            break;
        case 'size':
            usort($files, fn($a, $b) => ($b['size'] ?? 0) <=> ($a['size'] ?? 0));
            break;
        case 'type':
            usort($files, fn($a, $b) => strcmp((string)($a['type'] ?? ''), (string)($b['type'] ?? '')));
            break;
        case 'newest':
        default:
            usort($files, fn($a, $b) => ($b['modified'] ?? 0) <=> ($a['modified'] ?? 0));
            break;
    }
    return $files;
}

function apply_filters(array $files, string $type = 'all', string $query = '', string $month = ''): array {
    $query = trim($query);
    $queryNeedle = $query !== '' ? mb_strtolower($query) : '';
    $month = normalize_month_key($month);

    return array_values(array_filter($files, function($file) use ($type, $queryNeedle, $month) {
        $fileType = (string)($file['type'] ?? '');
        $fileName = (string)($file['name'] ?? '');
        $fileDate = (string)($file['date'] ?? '');
        $searchBlob = implode(' ', [
            $fileName,
            (string)($file['title'] ?? ''),
            (string)($file['caption'] ?? ''),
            (string)($file['collection'] ?? ''),
            (string)($file['tags'] ?? ''),
            (string)($file['model_name'] ?? ''),
            (string)($file['photographer'] ?? ''),
        ]);

        if ($type !== 'all' && $fileType !== $type) {
            return false;
        }

        if ($queryNeedle !== '' && mb_stripos($searchBlob, $queryNeedle) === false) {
            return false;
        }

        if ($month !== '' && $fileDate !== $month) {
            return false;
        }

        return true;
    }));
}

function group_by_month(array $files): array {
    $groups = [];
    foreach ($files as $file) {
        $month = (string)($file['date'] ?? '');
        if ($month === '') continue;
        if (!isset($groups[$month])) {
            $groups[$month] = [
                'label' => date('M Y', strtotime($month . '-01')),
                'key' => $month,
                'items' => []
            ];
        }
        $groups[$month]['items'][] = $file;
    }
    krsort($groups);
    return $groups;
}

function get_available_months(array $files): array {
    $months = [];
    foreach ($files as $file) {
        $key = normalize_month_key((string)($file['date'] ?? ''));
        if ($key === '') continue;
        $months[$key] = date('M Y', strtotime($key . '-01'));
    }
    krsort($months);
    return $months;
}

// ----------------------------
// REQUEST HANDLING
// ----------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $filename = safe_basename((string)($_POST['filename'] ?? ''));
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!post_rate_limit_allowed()) {
        log_security_event('POST_RATE_LIMITED', ['action' => $action, 'filename' => $filename]);
        json_response(['ok' => false, 'error' => 'Too many requests'], 429);
    }

    if (!hash_equals($csrf_token, $token)) {
        if (should_log_csrf_failure()) {
            log_security_event('CSRF_INVALID', ['action' => $action, 'filename' => $filename]);
        }
        json_response(['ok' => false, 'error' => 'Invalid CSRF token'], 403);
    }

    if ($filename === '' || !safe_media_exists($filename)) {
        json_response(['ok' => false, 'error' => 'Invalid file'], 400);
    }

    switch ($action) {
        case 'like':
            json_response(toggle_like($filename, get_user_key()));
            break;

        case 'view':
            json_response(increment_view($filename));
            break;

        default:
            json_response(['ok' => false, 'error' => 'Invalid action'], 400);
    }
}

// Handle AJAX pagination requests
$is_ajax = (isset($_GET['ajax']) && (string)$_GET['ajax'] === '1');
$page = max(1, (int)($_GET['page'] ?? 1));
$type = (string)($_GET['type'] ?? 'all');
$sort = (string)($_GET['sort'] ?? 'newest');
$view = (string)($_GET['view'] ?? 'grid');
$focus = safe_basename((string)($_GET['focus'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$month = normalize_month_key((string)($_GET['month'] ?? ''));

// Normalize inputs
if (!in_array($type, ['all', 'image', 'video'], true)) $type = 'all';
if (!in_array($sort, ['newest', 'oldest', 'name', 'size', 'type', 'popular', 'likes'], true)) $sort = 'newest';
if (!in_array($view, ['grid', 'list', 'slideshow'], true)) $view = 'grid';

// Get all files
$all_files = enrich_lookbook_files_with_metadata(scan_media_files());
$available_months = get_available_months($all_files);

// Apply filters
$filtered_files = apply_filters($all_files, $type, $q, $month);

// Featured/manual order always gets first priority when set.
usort($filtered_files, function($a, $b) {
    $af = !empty($a['is_featured']) ? 1 : 0;
    $bf = !empty($b['is_featured']) ? 1 : 0;
    if ($af !== $bf) return $bf <=> $af;

    $ao = (int)($a['sort_order'] ?? 0);
    $bo = (int)($b['sort_order'] ?? 0);
    if ($ao !== $bo) return $ao <=> $bo;

    return 0;
});

// Sort
if ($sort === 'popular' || $sort === 'likes') {
    $engagement = load_engagement_data();
    $metric = ($sort === 'popular') ? 'views' : 'likes';

    usort($filtered_files, function($a, $b) use ($engagement, $metric) {
        $an = (string)($a['name'] ?? '');
        $bn = (string)($b['name'] ?? '');
        $a_count = (int)($engagement[$metric][$an] ?? 0);
        $b_count = (int)($engagement[$metric][$bn] ?? 0);

        if ($a_count === $b_count) {
            return (($b['modified'] ?? 0) <=> ($a['modified'] ?? 0));
        }

        return $b_count <=> $a_count;
    });
} else {
    $filtered_files = sort_files($filtered_files, $sort);
}

// Pagination
$total_items = count($filtered_files);
$items_per_page = ITEMS_PER_PAGE;
$total_pages = max(1, (int)ceil($total_items / $items_per_page));
$page = min($page, $total_pages);

$offset = (int)(($page - 1) * $items_per_page);
if ($offset < 0) $offset = 0;

$current_files = array_slice($filtered_files, $offset, $items_per_page);

// Add engagement data to files
$engagement_data = load_engagement_data();
$user_key = get_user_key();
$userLikes = $engagement_data['users'][$user_key]['likes'] ?? [];
if (!is_array($userLikes)) $userLikes = [];

foreach ($current_files as &$file) {
    $name = (string)($file['name'] ?? '');
    $file['likes'] = (int)($engagement_data['likes'][$name] ?? 0);
    $file['views'] = (int)($engagement_data['views'][$name] ?? 0);
    $file['liked'] = in_array($name, $userLikes, true);
    $file['sizeHuman'] = formatBytes((int)($file['size'] ?? 0));
}
unset($file);

// Group by month for API parity
$grouped_files = group_by_month($current_files);

// AJAX response
if ($is_ajax) {
    json_response([
        'ok' => true,
        'page' => $page,
        'total_pages' => $total_pages,
        'total_items' => $total_items,
        'items' => $current_files,
        'groups' => $grouped_files,
        'has_more' => $page < $total_pages
    ]);
}

// ----------------------------
// HTML OUTPUT
// ----------------------------
?>
<!DOCTYPE html>
<html lang="en" class="cyberpunk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>LOOKBOOK // DIAMONDS OUTTA DIRT</title>
    <meta name="description" content="Cyberpunk Visual Archive - Immersive Media Gallery">

    <style>
        :root {
            --neon-pink: #ff00ff;
            --neon-cyan: #00ffff;
            --neon-lime: #ccff00;
            --grid-color: rgba(0, 255, 255, 0.05);
            --scanline: rgba(255, 255, 255, 0.03);
            --bg-dark: #000;
            --bg-darker: #050508;
            --text: #e0e0ff;
            --text-muted: #8899aa;
            --card-bg: rgba(10, 15, 30, 0.7);
            --card-border: rgba(0, 255, 255, 0.2);
            --glow: 0 0 20px rgba(0, 255, 255, 0.3);
            --glow-pink: 0 0 20px rgba(255, 0, 255, 0.3);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Space Mono', monospace;
            background: var(--bg-darker);
            color: var(--text);
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background:
                linear-gradient(var(--scanline) 1px, transparent 1px) 0 0 / 100% 4px,
                linear-gradient(90deg, var(--scanline) 1px, transparent 1px) 0 0 / 4px 100%,
                radial-gradient(circle at 50% 50%, transparent 10%, rgba(0, 0, 0, 0.8) 100%);
            pointer-events: none;
            z-index: 9999;
            mix-blend-mode: overlay;
        }

        body::after {
            content: '';
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background-image:
                linear-gradient(var(--grid-color) 1px, transparent 1px),
                linear-gradient(90deg, var(--grid-color) 1px, transparent 1px);
            background-size: 40px 40px;
            opacity: 0.3;
            pointer-events: none;
            z-index: -1;
        }

        .main-nav {
            background: rgba(5, 5, 15, 0.98);
            border-bottom: 1px solid var(--neon-cyan);
            padding: 0.8rem 1.5rem;
            position: sticky;
            top: 0;
            z-index: 200;
            backdrop-filter: blur(10px);
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.5);
        }

        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 2rem;
        }

        .nav-brand { flex-shrink: 0; }

        .nav-logo {
            font-family: 'Syncopate', sans-serif;
            font-size: 1.3rem;
            color: var(--neon-pink);
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            text-shadow: 0 0 10px var(--neon-pink);
            transition: all 0.3s;
        }

        .nav-logo:hover {
            color: var(--neon-cyan);
            text-shadow: 0 0 15px var(--neon-cyan);
        }

        .nav-menu {
            display: flex;
            gap: 1.5rem;
            align-items: center;
            flex-wrap: wrap;
            justify-content: center;
            flex: 1;
        }

        .nav-link {
            color: var(--text);
            text-decoration: none;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0.5rem 0;
            position: relative;
            transition: all 0.3s;
            border-bottom: 2px solid transparent;
        }

        .nav-link:hover { color: var(--neon-cyan); }
        .nav-link.active {
            color: var(--neon-pink);
            border-bottom-color: var(--neon-pink);
        }

        .nav-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-shrink: 0;
        }

        .nav-icon {
            color: var(--text);
            text-decoration: none;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: all 0.3s;
            border: 1px solid transparent;
        }

        .nav-icon:hover {
            color: var(--neon-cyan);
            border-color: var(--neon-cyan);
            background: rgba(0, 255, 255, 0.1);
        }

        .nav-icon svg { width: 18px; height: 18px; }

        .nav-toggle {
            display: none;
            background: none;
            border: none;
            color: var(--neon-cyan);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
        }

        .terminal-header {
            background: rgba(5, 5, 15, 0.97);
            border-bottom: 1px solid var(--neon-cyan);
            padding: 0.6rem 1.5rem;
            position: sticky;
            top: 60px;
            z-index: 100;
            backdrop-filter: blur(10px);
            min-height: auto;
            max-height: 180px;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }

        .terminal-header.compact { max-height: 90px; }

        .brandline {
            font-family: 'Syncopate', sans-serif;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 0.3rem;
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
        }

        .brandline .main {
            font-size: 1.1rem;
            color: var(--neon-pink);
            text-shadow: 0 0 8px var(--neon-pink);
            line-height: 1.2;
        }

        .brandline .subline {
            font-size: 0.7rem;
            color: var(--neon-cyan);
            display: flex;
            gap: 0.8rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .brandline .subline .tag {
            background: rgba(0, 255, 255, 0.1);
            padding: 0.15rem 0.5rem;
            border-radius: 2px;
            border: 1px solid rgba(0, 255, 255, 0.2);
        }

        .lookbook_clean_note { color: var(--text-muted); font-size: 0.78rem; line-height: 1.6; margin-top: 0.45rem; max-width: 820px; }

        .controls {
            display: flex;
            gap: 0.8rem;
            flex-wrap: wrap;
            margin-top: 0.5rem;
            align-items: flex-end;
        }

        .control { position: relative; min-width: 140px; }

        .control.label-wide { min-width: 220px; }

        .control label {
            display: block;
            font-size: 0.7rem;
            color: var(--neon-lime);
            margin-bottom: 0.2rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .control select,
        .control input[type="search"] {
            background: rgba(0, 0, 0, 0.7);
            border: 1px solid var(--neon-cyan);
            color: var(--text);
            padding: 0.4rem 1.8rem 0.4rem 0.6rem;
            font-family: 'Space Mono', monospace;
            font-size: 0.8rem;
            width: 100%;
            height: 32px;
        }

        .control input[type="search"] {
            appearance: none;
            padding-right: 0.6rem;
        }

        .control select {
            appearance: none;
            cursor: pointer;
            position: relative;
        }

        .control select:focus,
        .control input[type="search"]:focus {
            outline: none;
            box-shadow: var(--glow);
        }

        .control input[type="search"]::placeholder {
            color: var(--text-muted);
        }

        .caret {
            position: absolute;
            right: 8px;
            bottom: 10px;
            border-left: 4px solid transparent;
            border-right: 4px solid transparent;
            border-top: 4px solid var(--neon-cyan);
            pointer-events: none;
        }

        .view-toggles { display: flex; gap: 0.4rem; align-items: flex-end; }

        .view-btn {
            background: rgba(0, 0, 0, 0.7);
            border: 1px solid var(--card-border);
            color: var(--text-muted);
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            padding: 0;
        }

        .view-btn:hover { border-color: var(--neon-cyan); color: var(--neon-cyan); }
        .view-btn.active { border-color: var(--neon-pink); color: var(--neon-pink); box-shadow: var(--glow-pink); }
        .view-btn svg { width: 14px; height: 14px; }

        .immersion-container { padding: 1.5rem; max-width: 1800px; margin: 0 auto; }

        .lookbook-grid.mode-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.2rem;
        }

        .lookbook-grid.mode-list {
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
        }

        .lookbook-grid.mode-slideshow {
            display: flex;
            overflow-x: auto;
            scroll-snap-type: x mandatory;
            gap: 0.8rem;
            padding: 0.8rem 0;
        }

        .lookbook-grid.mode-slideshow .card {
            flex: 0 0 90%;
            scroll-snap-align: start;
        }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 3px;
            overflow: hidden;
            position: relative;
            transition: all 0.3s;
        }

        .card:hover { border-color: var(--neon-cyan); box-shadow: var(--glow); transform: translateY(-2px); }

        .mode-list .card { display: flex; height: 100px; }
        .mode-list .card-media { width: 160px; flex-shrink: 0; }
        .mode-list .card-meta { flex: 1; padding: 0.8rem; }

        .card-media { position: relative; aspect-ratio: 4/3; overflow: hidden; }
        .mode-list .card-media { aspect-ratio: 1; }

        .media-thumb { width: 100%; height: 100%; object-fit: cover; display: block; }

        .video-marker {
            position: absolute;
            top: 8px;
            left: 8px;
            width: 28px;
            height: 28px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(0,0,0,0.65);
            border: 1px solid rgba(255,255,255,0.12);
            color: #fff;
            font-size: 14px;
            z-index: 2;
            pointer-events: none;
        }

        .card-overlay {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(to bottom, transparent 50%, rgba(0,0,0,0.8) 100%);
            opacity: 0;
            transition: opacity 0.3s;
            display: flex;
            justify-content: space-between;
            padding: 0.8rem;
        }

        .card:hover .card-overlay { opacity: 1; }

        .like-btn, .expand-btn {
            background: rgba(0, 0, 0, 0.7);
            border: 1px solid var(--neon-pink);
            color: var(--neon-pink);
            height: 30px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.2s;
            padding: 0 10px;
            gap: 6px;
        }

        .expand-btn {
            width: 30px;
            padding: 0;
            border-radius: 50%;
        }

        .like-btn:hover, .expand-btn:hover { background: var(--neon-pink); color: black; }
        .like-btn.active { background: var(--neon-pink); color: black; }

        .card-meta { padding: 0.8rem; }

        .meta-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.3rem;
            gap: 10px;
        }

        .card-title {
            font-weight: bold;
            color: var(--text);
            font-size: 0.85rem;
            line-height: 1.2;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 75%;
        }

        .card-size { font-size: 0.7rem; color: var(--text-muted); flex-shrink: 0; }

        .meta-bot {
            display: flex;
            justify-content: space-between;
            font-size: 0.7rem;
            color: var(--text-muted);
            gap: 10px;
        }

        .pagination-marker { text-align: center; padding: 2rem 0; }

        .btn-neon {
            background: transparent;
            border: 1px solid var(--neon-cyan);
            color: var(--neon-cyan);
            padding: 0.6rem 1.5rem;
            font-family: 'Space Mono', monospace;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-neon:hover { background: var(--neon-cyan); color: black; box-shadow: var(--glow); }

        .loader-spinner {
            display: inline-block;
            width: 30px;
            height: 30px;
            border: 2px solid transparent;
            border-top-color: var(--neon-cyan);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-top: 0.5rem;
        }

        @keyframes spin { 0% { transform: rotate(0deg);} 100% { transform: rotate(360deg);} }

        .hidden { display: none !important; }

        .lightbox {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.95);
            z-index: 10000;
            display: none;
            flex-direction: column;
        }

        .lightbox.active { display: flex; }

        .lb-close {
            position: absolute;
            top: 15px;
            right: 15px;
            background: none;
            border: none;
            color: var(--neon-pink);
            font-size: 2rem;
            cursor: pointer;
            z-index: 10001;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .lb-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(0, 0, 0, 0.7);
            border: 1px solid var(--neon-cyan);
            color: var(--neon-cyan);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            font-size: 1.2rem;
            cursor: pointer;
            z-index: 10001;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .lb-nav.prev { left: 15px; }
        .lb-nav.next { right: 15px; }

        .lb-media-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .lb-media {
            max-width: 90%;
            max-height: 80vh;
            object-fit: contain;
        }

        .lb-details {
            padding: 1.5rem;
            background: rgba(10, 15, 30, 0.9);
            border-top: 1px solid var(--neon-cyan);
        }

        .lb-actions {
            display: flex;
            gap: 0.8rem;
            margin-top: 0.8rem;
            flex-wrap: wrap;
        }

        .btn-icon {
            background: rgba(0, 0, 0, 0.7);
            border: 1px solid var(--neon-cyan);
            color: var(--neon-cyan);
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.9rem;
            text-decoration: none;
        }

        .btn-icon:hover { background: var(--neon-cyan); color: black; }
        .btn-icon.active { border-color: var(--neon-pink); color: var(--neon-pink); box-shadow: var(--glow-pink); }

        .selection-mode .card { cursor: pointer; }

        .selection-mode .card.selected {
            border-color: var(--neon-lime);
            box-shadow: 0 0 0 2px rgba(204, 255, 0, 0.3);
        }

        .batch-bar {
            position: fixed;
            bottom: 1.5rem;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(5, 5, 15, 0.95);
            border: 1px solid var(--neon-lime);
            padding: 0.8rem 1.5rem;
            border-radius: 3px;
            display: flex;
            gap: 0.8rem;
            align-items: center;
            z-index: 1000;
            backdrop-filter: blur(10px);
            min-width: 250px;
            justify-content: center;
        }

        .batch-count { color: var(--neon-lime); font-weight: bold; font-size: 0.9rem; }

        .btn-tiny {
            background: transparent;
            border: 1px solid var(--neon-cyan);
            color: var(--neon-cyan);
            padding: 0.2rem 0.6rem;
            font-size: 0.7rem;
            cursor: pointer;
            height: 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-tiny.ghost { border-color: var(--text-muted); color: var(--text-muted); }

        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            color: var(--text-muted);
            grid-column: 1 / -1;
        }

        .error-fallback {
            background: rgba(255, 0, 0, 0.1);
            border: 2px solid #f00;
            padding: 1.5rem;
            margin: 1.5rem;
            text-align: center;
        }

        @media (max-width: 768px) {
            .main-nav { padding: 0.6rem 1rem; }
            .nav-container { flex-wrap: wrap; gap: 1rem; }
            .nav-brand { flex: 1; }
            .nav-logo { font-size: 1.1rem; }
            .nav-toggle { display: block; }

            .nav-menu {
                display: none;
                width: 100%;
                flex-direction: column;
                gap: 0.5rem;
                padding-top: 1rem;
                border-top: 1px solid rgba(0, 255, 255, 0.2);
            }
            .nav-menu.active { display: flex; }
            .nav-link { width: 100%; text-align: center; padding: 0.5rem; }
            .nav-actions { order: 3; width: 100%; justify-content: flex-end; }

            .terminal-header { padding: 0.5rem 1rem; top: 0; position: relative; }

            .controls { gap: 0.5rem; margin-top: 0.3rem; }
            .control, .control.label-wide { min-width: 120px; width: 100%; }

            .control select,
            .control input[type="search"] {
                padding: 0.3rem 1.5rem 0.3rem 0.5rem;
                font-size: 0.75rem;
                height: 28px;
            }

            .view-btn { width: 28px; height: 28px; }

            .lookbook-grid.mode-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
                gap: 0.8rem;
            }

            .mode-list .card { flex-direction: column; height: auto; }
            .mode-list .card-media { width: 100%; }

            .immersion-container { padding: 1rem; }
            .btn-neon { padding: 0.5rem 1rem; font-size: 0.75rem; height: 28px; }
        }

        @media (max-width: 480px) {
            .controls { flex-direction: column; align-items: stretch; }
            .control { width: 100%; }
            .lookbook-grid.mode-grid { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 0.6rem; }
            .nav-logo { font-size: 1rem; }
        }

        @media (prefers-reduced-motion: reduce) {
            .card, .card-overlay { transition: none; }
            .loader-spinner { animation: none; }
        }

        /* Lookbook metadata presentation */
        .card-featured {
            position: absolute;
            top: 10px;
            left: 10px;
            z-index: 3;
            border: 1px solid rgba(0,255,157,0.42);
            background: rgba(0,0,0,0.68);
            color: var(--neon-lime);
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 0.58rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            backdrop-filter: blur(8px);
        }

        .card-caption {
            margin-top: 0.45rem;
            color: var(--text-muted);
            font-size: 0.72rem;
            line-height: 1.45;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .card-tags {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 0.55rem;
        }

        .card-tag {
            border: 1px solid rgba(255,255,255,0.12);
            color: var(--text-muted);
            border-radius: 999px;
            padding: 3px 7px;
            font-size: 0.58rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .lb-caption {
            color: var(--text);
            line-height: 1.7;
            margin-top: 0.8rem;
            max-width: 860px;
        }

        .lb-credit-row,
        .lb-link-row,
        .lb-tag-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 0.75rem;
            align-items: center;
        }

        .lb-chip,
        .lb-link-chip {
            border: 1px solid rgba(255,255,255,0.14);
            color: var(--text-muted);
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            text-decoration: none;
            background: rgba(0,0,0,0.28);
        }

        .lb-link-chip {
            color: var(--neon-cyan);
            border-color: rgba(0,255,255,0.28);
        }

        .lb-link-chip:hover {
            color: #000;
            background: var(--neon-cyan);
        }

    </style>

    <link href="https://fonts.googleapis.com/css2?family=Syncopate:wght@400;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<?php @include_once __DIR__ . '/includes/bg_styles.php'; ?>
<script src="/js/telemetry.js" defer></script>
</head>
<body>
    <nav class="main-nav">
        <div class="nav-container">
            <div class="nav-brand">
                <a href="/" class="nav-logo">DIAMONDS OUTTA DIRT</a>
            </div>

            <button class="nav-toggle" aria-label="Toggle menu">☰</button>

            <div class="nav-menu">
                <a href="/" class="nav-link">HOME</a>
                <a href="/lookbook" class="nav-link active">LOOKBOOK</a>
                <a href="/shop" class="nav-link">SHOP</a>
                <a href="/exchange" class="nav-link">EXCHANGE</a>
                <a href="/services" class="nav-link">SERVICES</a>
                <a href="/about" class="nav-link">ABOUT</a>
            </div>

            <div class="nav-actions">
                <a href="/cart" class="nav-icon" title="Bag" aria-label="Bag">BAG</a>
                <?php if ($customerLoggedIn): ?>
                    <a href="/account" class="nav-icon" title="Account" aria-label="Account">ACCOUNT</a>
                <?php else: ?>
                    <a href="/account/login" class="nav-icon" title="Sign In" aria-label="Sign In">SIGN IN</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <div class="terminal-header">
        <div class="brandline">
            <div class="main">VISUAL ARCHIVE</div>
            <div class="subline">
                <span class="tag">DIAMONDS OUTTA DIRT</span>
                <span class="tag" id="metaTag">
                    TYPE: <?= h(strtoupper($type)) ?>
                    · SORT: <?= h(strtoupper($sort)) ?>
                    <?= $month !== '' ? ' · MONTH: ' . h($month) : '' ?>
                    <?= $q !== '' ? ' · SEARCH: ' . h(mb_strtoupper($q)) : '' ?>
                </span>
                <span class="tag" id="countTag"><?= h((string)$total_items) ?> ITEMS</span>
                <span class="tag" id="viewTag">VIEW: <?= h(strtoupper($view)) ?></span>
            </div>
            <p class="lookbook_clean_note">Curated campaign visuals, model studies, process clips, event flyers, and archive moments.</p>
        </div>

        <div class="controls" id="mainControls">
            <div class="control label-wide">
                <label for="searchInput">SEARCH</label>
                <input id="searchInput" type="search" placeholder="filename contains..." value="<?= h($q) ?>">
            </div>

            <div class="control">
                <label for="monthSel">MONTH</label>
                <select id="monthSel">
                    <option value="">ALL MONTHS</option>
                    <?php foreach ($available_months as $monthKey => $monthLabel): ?>
                        <option value="<?= h($monthKey) ?>" <?= $month === $monthKey ? 'selected' : '' ?>>
                            <?= h(strtoupper($monthLabel)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="caret" aria-hidden="true"></span>
            </div>

            <div class="control">
                <label for="typeSel">TYPE</label>
                <select id="typeSel">
                    <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>ALL</option>
                    <option value="image" <?= $type === 'image' ? 'selected' : '' ?>>IMAGES</option>
                    <option value="video" <?= $type === 'video' ? 'selected' : '' ?>>VIDEOS</option>
                </select>
                <span class="caret" aria-hidden="true"></span>
            </div>

            <div class="control">
                <label for="sortSel">SORT</label>
                <select id="sortSel">
                    <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>NEWEST</option>
                    <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>OLDEST</option>
                    <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>NAME</option>
                    <option value="size" <?= $sort === 'size' ? 'selected' : '' ?>>SIZE</option>
                    <option value="popular" <?= $sort === 'popular' ? 'selected' : '' ?>>POPULAR</option>
                    <option value="likes" <?= $sort === 'likes' ? 'selected' : '' ?>>LIKES</option>
                </select>
                <span class="caret" aria-hidden="true"></span>
            </div>

            <div class="control view-toggles">
                <button class="view-btn <?= $view === 'grid' ? 'active' : '' ?>" data-view="grid" title="Grid View" type="button">
                    <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M1 1h6v6H1V1zm8 0h6v6H9V1zM1 9h6v6H1V9zm8 0h6v6H9V9z"/></svg>
                </button>
                <button class="view-btn <?= $view === 'list' ? 'active' : '' ?>" data-view="list" title="List View" type="button">
                    <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M1 2.5A1.5 1.5 0 0 1 2.5 1h11A1.5 1.5 0 0 1 15 2.5v11a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 1 13.5v-11zm1 0v11a.5.5 0 0 0 .5.5h11a.5.5 0 0 0 .5-.5v-11a.5.5 0 0 0-.5-.5h-11a.5.5 0 0 0-.5.5z"/><path d="M4 4h8v1H4V4zm0 3h8v1H4V7zm0 3h8v1H4v-1z"/></svg>
                </button>
                <button class="view-btn <?= $view === 'slideshow' ? 'active' : '' ?>" data-view="slideshow" title="Slideshow" type="button">
                    <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M6 12.796V3.204L11.481 8 6 12.796zm.659.753 5.48-4.796a1 1 0 0 0 0-1.506L6.66 2.451C6.011 1.885 5 2.345 5 3.204v9.592a1 1 0 0 0 1.659.753z"/></svg>
                </button>
            </div>

            <div class="control selection-controls">
                <button id="batchToggle" class="btn-neon" type="button">SELECT</button>
            </div>
        </div>
    </div>

    <div id="batchBar" class="batch-bar hidden">
        <span class="batch-count">0 selected</span>
        <button id="batchOpen" class="btn-tiny" type="button">OPEN</button>
        <button id="batchClear" class="btn-tiny ghost" type="button">CLEAR</button>
    </div>

    <main class="immersion-container">
        <div id="lookbookGrid" class="lookbook-grid mode-<?= h($view) ?>">
            <?php if ($total_items === 0): ?>
                <div class="empty-state">
                    <p>// NO_MEDIA_MATCHED</p>
                    <p style="margin-top:8px;font-size:0.85rem;">Upload files to <code>/images/lookbook/</code> or adjust filters</p>
                </div>
            <?php else: ?>
                <div class="empty-state" id="loadingState">
                    <div class="loader-spinner"></div>
                    <p>Loading lookbook...</p>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($page < $total_pages): ?>
            <div id="sentinel" class="pagination-marker">
                <button id="loadMoreBtn" class="btn-neon" data-next="<?= (int)($page + 1) ?>" type="button">LOAD MORE</button>
                <div class="loader-spinner hidden"></div>
            </div>
        <?php endif; ?>
    </main>

    <div id="lightbox" class="lightbox" aria-hidden="true">
        <button id="lbClose" class="lb-close" type="button" aria-label="Close">×</button>
        <button id="lbPrev" class="lb-nav prev" type="button" aria-label="Previous">‹</button>
        <button id="lbNext" class="lb-nav next" type="button" aria-label="Next">›</button>
        <div class="lb-content">
            <div id="lbMediaContainer" class="lb-media-container"></div>
            <div class="lb-details">
                <h2 id="lbTitle"></h2>
                <div class="lb-meta" style="display:flex;gap:12px;color:var(--text-muted);font-size:0.8rem;margin-top:6px;flex-wrap:wrap;">
                    <span id="lbDate"></span>
                    <span id="lbSize"></span>
                    <span id="lbViews"></span>
                </div>
                <p id="lbCaption" class="lb-caption"></p>
                <div id="lbCredits" class="lb-credit-row"></div>
                <div id="lbTags" class="lb-tag-row"></div>
                <div id="lbLinks" class="lb-link-row"></div>
                <div class="lb-actions">
                    <button id="lbLike" class="btn-icon" type="button" title="Like">♥</button>
                    <button id="lbCopyLink" class="btn-icon" type="button" title="Copy Link">🔗</button>
                    <button id="lbShareFB" class="btn-icon" type="button" title="Share on Facebook">f</button>
                    <button id="lbShareIG" class="btn-icon" type="button" title="Open in Instagram">ig</button>
                    <a id="lbDownload" href="#" download class="btn-icon" title="Download">⬇</a>
                </div>
            </div>
        </div>
    </div>

    <script nonce="<?= h($csp_nonce) ?>">
    'use strict';

    window.LOOKBOOK_CONFIG = {
        csrf: <?= json_encode($csrf_token, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        apiUrl: "/lookbook.php",
        mediaPath: <?= json_encode(MEDIA_URL, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        currentPage: <?= (int)$page ?>,
        totalPages: <?= (int)$total_pages ?>,
        currentView: <?= json_encode($view, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        currentType: <?= json_encode($type, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        currentSort: <?= json_encode($sort, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        currentQuery: <?= json_encode($q, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        currentMonth: <?= json_encode($month, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        focusFile: <?= json_encode($focus, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        itemsPerPage: <?= (int)ITEMS_PER_PAGE ?>,
        overscan: <?= (int)OVERSCAN ?>
    };

    window.LOOKBOOK_ITEMS = [
    <?php foreach ($current_files as $index => $item): ?>
        {
            name: <?= json_encode((string)$item['name'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            type: <?= json_encode((string)$item['type'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            url: <?= json_encode((string)$item['url'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            thumb: <?= json_encode((string)$item['thumb'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            size: <?= (int)$item['size'] ?>,
            modified: <?= (int)$item['modified'] ?>,
            date: <?= json_encode((string)$item['date'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            likes: <?= (int)$item['likes'] ?>,
            liked: <?= $item['liked'] ? 'true' : 'false' ?>,
            views: <?= (int)$item['views'] ?>,
            sizeHuman: <?= json_encode((string)$item['sizeHuman'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            title: <?= json_encode((string)($item['title'] ?? ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            caption: <?= json_encode((string)($item['caption'] ?? ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            collection: <?= json_encode((string)($item['collection'] ?? ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            tags: <?= json_encode((string)($item['tags'] ?? ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            model_name: <?= json_encode((string)($item['model_name'] ?? ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            model_instagram: <?= json_encode((string)($item['model_instagram'] ?? ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            photographer: <?= json_encode((string)($item['photographer'] ?? ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            product_url: <?= json_encode((string)($item['product_url'] ?? ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            exchange_url: <?= json_encode((string)($item['exchange_url'] ?? ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            is_featured: <?= !empty($item['is_featured']) ? 'true' : 'false' ?>
        }<?= $index < count($current_files) - 1 ? ',' : '' ?>
    <?php endforeach; ?>
    ];

    function formatBytes(bytes) {
        if (!bytes) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }
    window.formatBytes = formatBytes;
    </script>

    <script nonce="<?= h($csp_nonce) ?>">
    (function() {
        'use strict';

        const config = window.LOOKBOOK_CONFIG || {};
        const state = {
            items: Array.isArray(window.LOOKBOOK_ITEMS) ? window.LOOKBOOK_ITEMS.slice() : [],
            page: Number.isFinite(config.currentPage) ? config.currentPage : 1,
            totalPages: Number.isFinite(config.totalPages) ? config.totalPages : 1,
            loading: false,
            view: config.currentView || 'grid',
            selectionMode: false,
            selected: new Set(),
            filters: {
                type: config.currentType || 'all',
                sort: config.currentSort || 'newest',
                q: config.currentQuery || '',
                month: config.currentMonth || ''
            },
            lbIndex: -1,
            searchTimer: null
        };

        const grid = document.getElementById('lookbookGrid');
        const typeSel = document.getElementById('typeSel');
        const sortSel = document.getElementById('sortSel');
        const monthSel = document.getElementById('monthSel');
        const searchInput = document.getElementById('searchInput');
        const viewBtns = document.querySelectorAll('.view-btn');
        const loadMoreBtn = document.getElementById('loadMoreBtn');
        const sentinel = document.getElementById('sentinel');
        const batchToggle = document.getElementById('batchToggle');
        const batchBar = document.getElementById('batchBar');
        const batchOpen = document.getElementById('batchOpen');
        const batchClear = document.getElementById('batchClear');
        const lightbox = document.getElementById('lightbox');
        const lbClose = document.getElementById('lbClose');
        const lbPrev = document.getElementById('lbPrev');
        const lbNext = document.getElementById('lbNext');
        const lbMediaContainer = document.getElementById('lbMediaContainer');
        const lbTitle = document.getElementById('lbTitle');
        const lbDate = document.getElementById('lbDate');
        const lbSize = document.getElementById('lbSize');
        const lbViews = document.getElementById('lbViews');
        const lbCaption = document.getElementById('lbCaption');
        const lbCredits = document.getElementById('lbCredits');
        const lbTags = document.getElementById('lbTags');
        const lbLinks = document.getElementById('lbLinks');
        const lbLike = document.getElementById('lbLike');
        const lbCopyLink = document.getElementById('lbCopyLink');
        const lbShareFB = document.getElementById('lbShareFB');
        const lbShareIG = document.getElementById('lbShareIG');
        const lbDownload = document.getElementById('lbDownload');
        const terminalHeader = document.querySelector('.terminal-header');
        const navToggle = document.querySelector('.nav-toggle');
        const navMenu = document.querySelector('.nav-menu');

        if (!grid) {
            const errorDiv = document.createElement('div');
            errorDiv.className = 'error-fallback';
            errorDiv.innerHTML = '<h3>LOOKBOOK ERROR</h3><p>Grid container not found</p>';
            document.body.appendChild(errorDiv);
            return;
        }

        init();

        function init() {
            const loadingState = document.getElementById('loadingState');
            if (loadingState) loadingState.remove();

            switchView(state.view);

            if (state.items.length > 0) {
                renderItems(state.items, false);
            } else {
                if (!grid.querySelector('.empty-state')) {
                    grid.innerHTML = '<div class="empty-state"><p>No items found in gallery</p></div>';
                }
            }

            bindControls();
            bindScroll();

            if (config.focusFile) {
                setTimeout(() => openLightboxByName(config.focusFile), 500);
            }
        }

        function bindControls() {
            if (typeSel) {
                typeSel.value = state.filters.type;
                typeSel.addEventListener('change', () => {
                    state.filters.type = typeSel.value;
                    resetAndFetch();
                });
            }

            if (sortSel) {
                sortSel.value = state.filters.sort;
                sortSel.addEventListener('change', () => {
                    state.filters.sort = sortSel.value;
                    resetAndFetch();
                });
            }

            if (monthSel) {
                monthSel.value = state.filters.month;
                monthSel.addEventListener('change', () => {
                    state.filters.month = monthSel.value;
                    resetAndFetch();
                });
            }

            if (searchInput) {
                searchInput.value = state.filters.q;
                searchInput.addEventListener('input', () => {
                    state.filters.q = searchInput.value.trim();
                    clearTimeout(state.searchTimer);
                    state.searchTimer = setTimeout(() => {
                        resetAndFetch();
                    }, 250);
                });
            }

            viewBtns.forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const view = e.currentTarget.dataset.view;
                    switchView(view);

                    viewBtns.forEach(b => b.classList.remove('active'));
                    e.currentTarget.classList.add('active');

                    resetAndFetch();
                });
            });

            if (batchToggle) batchToggle.addEventListener('click', toggleSelectionMode);
            if (batchOpen) batchOpen.addEventListener('click', openSelected);
            if (batchClear) batchClear.addEventListener('click', clearSelection);

            if (lbClose) lbClose.addEventListener('click', closeLightbox);
            if (lbPrev) lbPrev.addEventListener('click', () => navigateLightbox(-1));
            if (lbNext) lbNext.addEventListener('click', () => navigateLightbox(1));

            if (navToggle && navMenu) {
                navToggle.addEventListener('click', () => navMenu.classList.toggle('active'));
            }

            document.addEventListener('keydown', (e) => {
                if (lightbox && lightbox.classList.contains('active')) {
                    if (e.key === 'Escape') closeLightbox();
                    if (e.key === 'ArrowLeft') navigateLightbox(-1);
                    if (e.key === 'ArrowRight') navigateLightbox(1);
                }
            });

            if (terminalHeader) {
                let lastScroll = 0;
                window.addEventListener('scroll', () => {
                    const currentScroll = window.pageYOffset || 0;
                    if (currentScroll > lastScroll && currentScroll > 100) {
                        terminalHeader.classList.add('compact');
                    } else if (currentScroll < 50) {
                        terminalHeader.classList.remove('compact');
                    }
                    lastScroll = currentScroll;
                });
            }

            if (lightbox) {
                lightbox.addEventListener('click', (e) => {
                    if (e.target === lightbox) closeLightbox();
                });
            }
        }

        async function resetAndFetch() {
            state.page = 1;
            state.totalPages = 1;
            state.items = [];
            state.selected.clear();
            state.lbIndex = -1;
            grid.innerHTML = '<div class="empty-state"><div class="loader-spinner"></div><p>Loading...</p></div>';
            await fetchItems(false, 1);
        }

        async function fetchItems(append, requestedPage = null) {
            if (state.loading) return false;
            state.loading = true;

            const pageToFetch = Number.isFinite(requestedPage) ? requestedPage : state.page;

            if (sentinel) {
                const sp = sentinel.querySelector('.loader-spinner');
                if (sp) sp.classList.remove('hidden');
                if (loadMoreBtn) loadMoreBtn.classList.add('hidden');
            }

            const params = new URLSearchParams({
                ajax: '1',
                type: state.filters.type,
                sort: state.filters.sort,
                page: String(pageToFetch),
                view: state.view,
                q: state.filters.q || '',
                month: state.filters.month || ''
            });

            try {
                const res = await fetch(config.apiUrl + '?' + params.toString(), { credentials: 'same-origin' });
                const data = await res.json();

                if (data && data.ok) {
                    const items = Array.isArray(data.items) ? data.items : [];
                    state.totalPages = Number(data.total_pages || 1);
                    state.page = Number(data.page || pageToFetch);

                    if (items.length > 0) {
                        renderItems(items, append);
                        state.items = append ? state.items.concat(items) : items;

                        const countTag = document.getElementById('countTag');
                        if (countTag) countTag.textContent = `${data.total_items} ITEMS`;

                        const metaTag = document.getElementById('metaTag');
                        if (metaTag) {
                            const parts = [
                                `TYPE: ${state.filters.type.toUpperCase()}`,
                                `SORT: ${state.filters.sort.toUpperCase()}`
                            ];
                            if (state.filters.month) parts.push(`MONTH: ${state.filters.month}`);
                            if (state.filters.q) parts.push(`SEARCH: ${state.filters.q.toUpperCase()}`);
                            metaTag.textContent = parts.join(' · ');
                        }

                        if (sentinel) sentinel.style.display = data.has_more ? 'block' : 'none';
                    } else if (!append) {
                        grid.innerHTML = '<div class="empty-state"><p>No items found for these filters</p></div>';
                        if (sentinel) sentinel.style.display = 'none';
                    }

                    return true;
                } else {
                    if (!append) {
                        grid.innerHTML = '<div class="empty-state"><p>Failed to load items</p></div>';
                    }
                    return false;
                }
            } catch (err) {
                if (!append) {
                    grid.innerHTML = '<div class="empty-state"><p>Failed to load items</p><button class="btn-neon" onclick="location.reload()">Reload</button></div>';
                }
                return false;
            } finally {
                state.loading = false;
                if (sentinel) {
                    const sp = sentinel.querySelector('.loader-spinner');
                    if (sp) sp.classList.add('hidden');
                    if (loadMoreBtn) loadMoreBtn.classList.remove('hidden');
                }
            }
        }

        function renderItems(items, append) {
            if (!append) grid.innerHTML = '';
            const fragment = document.createDocumentFragment();
            items.forEach(item => fragment.appendChild(createCard(item)));
            grid.appendChild(fragment);
        }

        function createCard(item) {
            const card = document.createElement('article');
            card.className = 'card lookbook-item';
            card.dataset.name = item.name || '';
            card.dataset.type = item.type || '';
            card.dataset.url = item.url || '';

            const isVideo = item.type === 'video';
            const sizeFormatted = item.sizeHuman || window.formatBytes(item.size || 0);
            const dateFormatted = item.modified
                ? new Date((item.modified || 0) * 1000).toLocaleDateString('en-US', {
                    month: 'short', day: 'numeric', year: 'numeric'
                })
                : 'Unknown';

            const likes = Number.isFinite(item.likes) ? item.likes : (parseInt(item.likes, 10) || 0);
            const views = Number.isFinite(item.views) ? item.views : (parseInt(item.views, 10) || 0);

            const displayTitle = item.title || cleanTitle(item.name || '');
            const caption = item.caption || '';
            const collection = item.collection || '';
            const tags = parseTags(item.tags || '').slice(0, 3);

            card.innerHTML = `
                <div class="card-media">
                    ${item.is_featured ? '<div class="card-featured">Featured</div>' : ''}
                    ${isVideo ? '<div class="video-marker">▶</div>' : ''}
                    <img src="${item.thumb}" alt="${escapeHtml(displayTitle)}" loading="lazy" decoding="async" class="media-thumb">
                    <div class="card-overlay">
                        <button class="like-btn ${item.liked ? 'active' : ''}" type="button" data-filename="${escapeAttr(item.name || '')}">
                            ♥ <span class="like-num">${likes}</span>
                        </button>
                        <button class="expand-btn" type="button" aria-label="Open">⤢</button>
                    </div>
                </div>
                <div class="card-meta">
                    <div class="meta-top">
                        <span class="card-title">${escapeHtml(displayTitle)}</span>
                        <span class="card-size">${escapeHtml(collection || sizeFormatted)}</span>
                    </div>
                    ${caption ? `<p class="card-caption">${escapeHtml(caption)}</p>` : ''}
                    ${tags.length ? `<div class="card-tags">${tags.map(tag => `<span class="card-tag">${escapeHtml(tag)}</span>`).join('')}</div>` : ''}
                    <div class="meta-bot">
                        <span class="card-date">${escapeHtml(dateFormatted)}</span>
                        <span class="card-views">👁 <span class="view-num">${views}</span></span>
                    </div>
                </div>
            `;

            const likeBtn = card.querySelector('.like-btn');
            const expandBtn = card.querySelector('.expand-btn');

            if (likeBtn) {
                likeBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    toggleLike(item.name, likeBtn);
                });
            }

            if (expandBtn) {
                expandBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    openLightbox(item);
                });
            }

            card.addEventListener('click', (e) => {
                if (e.target.closest('.like-btn') || e.target.closest('.expand-btn')) return;
                if (state.selectionMode) toggleSelection(item.name, card);
                else openLightbox(item);
            });

            return card;
        }

        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, c => ({
                '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
            }[c]));
        }

        function escapeAttr(s) {
            return String(s).replace(/"/g, '&quot;');
        }

        function cleanTitle(filename) {
            return String(filename || 'Archive Visual')
                .replace(/\.[^.]+$/, '')
                .replace(/[_-]+/g, ' ')
                .replace(/\s+/g, ' ')
                .trim()
                .replace(/\b\w/g, c => c.toUpperCase()) || 'Archive Visual';
        }

        function parseTags(value) {
            return String(value || '')
                .split(',')
                .map(tag => tag.trim())
                .filter(Boolean);
        }

        function linkLabel(url, fallback) {
            if (!url) return fallback;
            if (url.includes('/product/')) return 'Shop This Piece';
            if (url.includes('/exchange')) return 'View On Exchange';
            return fallback;
        }

        function toggleLike(filename, button) {
            if (!filename) return;

            const numEl = button ? button.querySelector('.like-num') : null;
            const itemIndex = state.items.findIndex(i => i.name === filename);
            const item = itemIndex !== -1 ? state.items[itemIndex] : null;
            const wasLiked = button ? button.classList.contains('active') : !!(item && item.liked);
            const currentCount = numEl
                ? (parseInt(numEl.textContent, 10) || 0)
                : (item ? (parseInt(item.likes, 10) || 0) : 0);

            const optimisticCount = Math.max(0, wasLiked ? (currentCount - 1) : (currentCount + 1));

            if (button) {
                button.classList.toggle('active', !wasLiked);
                if (numEl) numEl.textContent = String(optimisticCount);
            }
            if (lbLike && state.items[state.lbIndex] && state.items[state.lbIndex].name === filename) {
                lbLike.classList.toggle('active', !wasLiked);
            }

            fetch(config.apiUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                credentials: 'same-origin',
                body: new URLSearchParams({
                    action: 'like',
                    filename: filename,
                    csrf_token: config.csrf
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data && data.ok) {
                    const liked = !!data.liked;
                    const likes = parseInt(data.likes, 10) || 0;

                    document.querySelectorAll(`.like-btn[data-filename="${cssEscape(filename)}"]`).forEach(btn => {
                        btn.classList.toggle('active', liked);
                        const ln = btn.querySelector('.like-num');
                        if (ln) ln.textContent = String(likes);
                    });

                    const idx = state.items.findIndex(i => i.name === filename);
                    if (idx !== -1) {
                        state.items[idx].likes = likes;
                        state.items[idx].liked = liked;
                    }

                    if (state.items[state.lbIndex] && state.items[state.lbIndex].name === filename && lbLike) {
                        lbLike.classList.toggle('active', liked);
                    }
                } else {
                    if (button) {
                        button.classList.toggle('active', wasLiked);
                        if (numEl) numEl.textContent = String(currentCount);
                    }
                    if (lbLike && state.items[state.lbIndex] && state.items[state.lbIndex].name === filename) {
                        lbLike.classList.toggle('active', wasLiked);
                    }

                    if (data && data.cooldown) {
                        toast('Like cooldown active');
                    } else {
                        toast('Like failed');
                    }
                }
            })
            .catch(() => {
                if (button) {
                    button.classList.toggle('active', wasLiked);
                    if (numEl) numEl.textContent = String(currentCount);
                }
                if (lbLike && state.items[state.lbIndex] && state.items[state.lbIndex].name === filename) {
                    lbLike.classList.toggle('active', wasLiked);
                }
            });
        }

        function openLightbox(item) {
            if (!lightbox || !item || !item.name) return;

            state.lbIndex = state.items.findIndex(i => i.name === item.name);

            if (lbMediaContainer) lbMediaContainer.innerHTML = '';

            if (item.type === 'video') {
                const video = document.createElement('video');
                video.src = item.url;
                video.controls = true;
                video.autoplay = true;
                video.playsInline = true;
                video.className = 'lb-media';
                lbMediaContainer.appendChild(video);
            } else {
                const img = document.createElement('img');
                img.src = item.url;
                img.alt = item.name;
                img.loading = 'eager';
                img.decoding = 'async';
                img.className = 'lb-media';
                lbMediaContainer.appendChild(img);
            }

            const displayTitle = item.title || cleanTitle(item.name);
            if (lbTitle) lbTitle.textContent = displayTitle;
            if (lbDate) lbDate.textContent = item.modified ? new Date((item.modified || 0) * 1000).toLocaleDateString() : 'Unknown';
            if (lbSize) lbSize.textContent = item.collection || item.sizeHuman || window.formatBytes(item.size || 0);
            if (lbViews) lbViews.textContent = `👁 ${item.views || 0}`;

            if (lbCaption) {
                lbCaption.textContent = item.caption || '';
                lbCaption.style.display = item.caption ? '' : 'none';
            }

            if (lbCredits) {
                const credits = [];
                if (item.model_name) {
                    const modelText = `MODEL: ${item.model_name}`;
                    credits.push(item.model_instagram
                        ? `<a class="lb-link-chip" href="${escapeAttr(item.model_instagram)}" target="_blank" rel="noopener noreferrer">${escapeHtml(modelText)}</a>`
                        : `<span class="lb-chip">${escapeHtml(modelText)}</span>`);
                }
                if (item.photographer) credits.push(`<span class="lb-chip">PHOTO: ${escapeHtml(item.photographer)}</span>`);
                lbCredits.innerHTML = credits.join('');
                lbCredits.style.display = credits.length ? '' : 'none';
            }

            if (lbTags) {
                const tags = parseTags(item.tags || '');
                lbTags.innerHTML = tags.map(tag => `<span class="lb-chip">${escapeHtml(tag)}</span>`).join('');
                lbTags.style.display = tags.length ? '' : 'none';
            }

            if (lbLinks) {
                const links = [];
                if (item.product_url) links.push(`<a class="lb-link-chip" href="${escapeAttr(item.product_url)}">${escapeHtml(linkLabel(item.product_url, 'Shop This Piece'))}</a>`);
                if (item.exchange_url) links.push(`<a class="lb-link-chip" href="${escapeAttr(item.exchange_url)}">${escapeHtml(linkLabel(item.exchange_url, 'View On Exchange'))}</a>`);
                lbLinks.innerHTML = links.join('');
                lbLinks.style.display = links.length ? '' : 'none';
            }

            if (lbLike) {
                lbLike.classList.toggle('active', !!item.liked);
                lbLike.onclick = () => {
                    const btn = document.querySelector(`.like-btn[data-filename="${cssEscape(item.name)}"]`);
                    toggleLike(item.name, btn || null);
                };
            }

            if (lbDownload) {
                lbDownload.href = item.url;
                lbDownload.download = item.name;
            }

            if (lbCopyLink) {
                lbCopyLink.onclick = async () => {
                    const url = new URL(window.location.href);
                    url.searchParams.set('focus', item.name);
                    try {
                        await navigator.clipboard.writeText(url.toString());
                        toast('Link copied');
                    } catch (_) {
                        toast('Copy failed');
                    }
                };
            }

            if (lbShareFB) {
                lbShareFB.onclick = () => {
                    const u = new URL(window.location.href);
                    u.searchParams.set('focus', item.name);
                    const share = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(u.toString());
                    window.open(share, '_blank', 'noopener');
                };
            }

            if (lbShareIG) {
                lbShareIG.onclick = () => {
                    toast('Copy link, then share in IG');
                };
            }

            lightbox.classList.add('active');
            lightbox.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';

            fetch(config.apiUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                credentials: 'same-origin',
                body: new URLSearchParams({
                    action: 'view',
                    filename: item.name,
                    csrf_token: config.csrf
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data && data.ok) {
                    const newViews = Number(data.views || 0);

                    const idx = state.items.findIndex(i => i.name === item.name);
                    if (idx !== -1 && !data.debounced) {
                        state.items[idx].views = newViews;
                    }

                    if (lbViews) lbViews.textContent = `👁 ${newViews}`;

                    const card = grid.querySelector(`.lookbook-item[data-name="${cssEscape(item.name)}"]`);
                    if (card && !data.debounced) {
                        const vn = card.querySelector('.view-num');
                        if (vn) vn.textContent = String(newViews);
                    }
                }
            })
            .catch(() => {});
        }

        function openLightboxByName(filename) {
            const item = state.items.find(i => i.name === filename);
            if (item) openLightbox(item);
        }

        function closeLightbox() {
            if (!lightbox) return;
            lightbox.classList.remove('active');
            lightbox.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';

            const video = lbMediaContainer ? lbMediaContainer.querySelector('video') : null;
            if (video) {
                try { video.pause(); } catch (_) {}
            }
        }

        function navigateLightbox(direction) {
            if (state.items.length === 0) return;
            if (state.lbIndex < 0) {
                const currentItem = state.items[state.lbIndex] || null;
                const currentName = currentItem ? currentItem.name : '';
                state.lbIndex = state.items.findIndex(i => i.name === currentName);
            }
            const newIndex = state.lbIndex + direction;
            if (newIndex >= 0 && newIndex < state.items.length) {
                openLightbox(state.items[newIndex]);
            }
        }

        function switchView(viewName) {
            state.view = viewName;
            grid.className = `lookbook-grid mode-${viewName}`;
            const viewTag = document.getElementById('viewTag');
            if (viewTag) viewTag.textContent = `VIEW: ${String(viewName).toUpperCase()}`;
        }

        function toggleSelectionMode() {
            state.selectionMode = !state.selectionMode;
            document.body.classList.toggle('selection-mode', state.selectionMode);

            if (batchToggle) batchToggle.textContent = state.selectionMode ? 'CANCEL' : 'SELECT';
            if (batchBar) batchBar.classList.toggle('hidden', !state.selectionMode);

            if (!state.selectionMode) clearSelection();
            updateSelectionCount();
        }

        function toggleSelection(filename, element) {
            if (!filename) return;

            if (state.selected.has(filename)) state.selected.delete(filename);
            else state.selected.add(filename);

            if (element) element.classList.toggle('selected', state.selected.has(filename));
            updateSelectionCount();
        }

        function updateSelectionCount() {
            const count = state.selected.size;
            if (!batchBar) return;
            const label = batchBar.querySelector('.batch-count');
            if (label) label.textContent = `${count} selected`;
        }

        function clearSelection() {
            state.selected.clear();
            grid.querySelectorAll('.lookbook-item.selected').forEach(el => el.classList.remove('selected'));
            updateSelectionCount();
        }

        function openSelected() {
            state.selected.forEach(filename => {
                const item = state.items.find(i => i.name === filename);
                if (item && item.url) window.open(item.url, '_blank', 'noopener');
            });
        }

        function bindScroll() {
            if (!sentinel) return;

            const observer = new IntersectionObserver((entries) => {
                if (!entries[0] || !entries[0].isIntersecting) return;
                if (state.loading) return;
                if (state.totalPages && state.page >= state.totalPages) return;

                const nextPage = state.page + 1;
                fetchItems(true, nextPage);
            }, { rootMargin: '140px' });

            observer.observe(sentinel);

            if (loadMoreBtn) {
                loadMoreBtn.addEventListener('click', () => {
                    if (state.loading) return;
                    if (state.totalPages && state.page >= state.totalPages) return;

                    const nextPage = state.page + 1;
                    fetchItems(true, nextPage);
                });
            }
        }

        function toast(msg) {
            const el = document.createElement('div');
            el.textContent = msg;
            el.style.position = 'fixed';
            el.style.bottom = '18px';
            el.style.left = '50%';
            el.style.transform = 'translateX(-50%)';
            el.style.background = 'rgba(0,0,0,0.85)';
            el.style.border = '1px solid rgba(0,255,255,0.35)';
            el.style.color = '#00ffff';
            el.style.padding = '10px 12px';
            el.style.fontFamily = 'Space Mono, monospace';
            el.style.fontSize = '12px';
            el.style.zIndex = '20000';
            el.style.borderRadius = '6px';
            document.body.appendChild(el);
            setTimeout(() => { try { el.remove(); } catch(_){} }, 1200);
        }

        function cssEscape(s) {
            if (window.CSS && typeof window.CSS.escape === 'function') {
                return window.CSS.escape(String(s));
            }
            return String(s).replace(/["\\]/g, '\\$&');
        }

        window.lookbookApp = {
            state,
            config,
            reload: resetAndFetch,
            openLightbox: openLightboxByName
        };
    })();
    </script>
<script src="/js/cookie-consent-global.js" defer></script>
</body>
</html>