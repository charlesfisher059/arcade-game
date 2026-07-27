<?php
declare(strict_types=1);

/**
 * product.php — Diamonds Outta Dirt
 * CLEAN URL + CANONICAL FIXES (reviews moved into product-details drawer)
 *
 * PATCH (TELEMETRY): /js/telemetry.js + dodTrack() for product_view,
 * add_to_cart_click, audio_enable, gallery_view, bg_preview_toggle
 *
 * PATCH (REVIEWS UI): compact collapsed drawer inside product-details keeps the background clear.
 *
 * PATCH (WISHLIST): heart button now wired to /api/wishlist_toggle.php,
 * with server-side initial state check for logged-in customers
 */

// ------------------------------------------------------------
// SECURITY HEADERS + CSP NONCE
// ------------------------------------------------------------
$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');

$csp_nonce = base64_encode(random_bytes(16));

if (!headers_sent()) {
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header(
        "Content-Security-Policy: "
        . "default-src 'self'; "
        . "base-uri 'self'; "
        . "object-src 'none'; "
        . "frame-ancestors 'self'; "
        . "form-action 'self'; "
        . "connect-src 'self' https:; "
        . "img-src 'self' data: https: blob:; "
        . "media-src 'self' https: blob:; "
        . "font-src 'self' https://fonts.gstatic.com data:; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "script-src 'self' 'nonce-{$csp_nonce}' https:; "
        . "frame-src 'self' https:; "
        . "worker-src 'self' blob:;"
    );
}

// ------------------------------------------------------------
// SAFE SESSION (needed for csrf + cart + customer wishlist state)
// ------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start([
        'cookie_secure' => $https,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

// ------------------------------------------------------------
// SAFE CACHE INCLUDE (won't fatal if missing)
// ------------------------------------------------------------
$cacheFile = __DIR__ . '/includes/cache.php';
if (is_file($cacheFile)) {
    require_once $cacheFile;
}
if (!function_exists('cache_get')) {
    function cache_get(string $key, int $ttlSeconds = 180) { return false; }
}
if (!function_exists('cache_set')) {
    function cache_set(string $key, $value): void { /* no-op */ }
}

// ------------------------------------------------------------
// DB SCHEMA CACHE HELPERS (avoid repeated SHOW TABLES/COLUMNS)
// ------------------------------------------------------------
if (!function_exists('db_table_exists')) {
    function db_table_exists(PDO $pdo, string $table): bool {
        static $memo = [];
        $key = 'schema:table:' . strtolower($table);
        if (array_key_exists($key, $memo)) return $memo[$key];

        $cached = cache_get($key, 1800);
        if (is_array($cached) && array_key_exists('v', $cached) && is_bool($cached['v'])) {
            $memo[$key] = (bool)$cached['v'];
            return $memo[$key];
        }

        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :table
            LIMIT 1
        ");
        $stmt->execute([':table' => $table]);
        $exists = (bool)$stmt->fetch();
        $memo[$key] = $exists;
        cache_set($key, ['v' => $exists]);
        return $exists;
    }
}

if (!function_exists('db_column_exists')) {
    function db_column_exists(PDO $pdo, string $table, string $column): bool {
        static $memo = [];
        $key = 'schema:column:' . strtolower($table) . ':' . strtolower($column);
        if (array_key_exists($key, $memo)) return $memo[$key];

        $cached = cache_get($key, 1800);
        if (is_array($cached) && array_key_exists('v', $cached) && is_bool($cached['v'])) {
            $memo[$key] = (bool)$cached['v'];
            return $memo[$key];
        }

        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = :table
              AND column_name = :column
            LIMIT 1
        ");
        $stmt->execute([
            ':table' => $table,
            ':column' => $column,
        ]);
        $exists = (bool)$stmt->fetch();
        $memo[$key] = $exists;
        cache_set($key, ['v' => $exists]);
        return $exists;
    }
}

// ------------------------------------------------------------
// DB connection (required)
// ------------------------------------------------------------
require_once __DIR__ . '/db_connect.php';

// Hard fail with a branded error if DB is unavailable (prevents white screen)
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>DATABASE_OFFLINE | DIAMONDS OUTTA DIRT</title>';
    echo '<style>body{margin:0;background:#000;color:#fff;font-family:ui-monospace,Menlo,Consolas,monospace;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px} .box{max-width:720px;border:1px solid #333;background:#050505;padding:22px;border-radius:10px} .h{color:#00ff9d;letter-spacing:2px;font-weight:800;margin:0 0 10px} .p{color:#bbb;line-height:1.5;margin:0 0 10px} a{color:#00ff9d}</style></head><body><div class="box"><div class="h">DATABASE_OFFLINE</div><p class="p">The product page can\'t load right now because the database connection isn\'t available.</p><p class="p">Check <code>db_connect.php</code> credentials + HostGator MySQL status, then refresh.</p><p class="p"><a href="/shop">Return to Shop</a></p></div>
</body></html>';
    exit;
}

// ------------------------------------------------------------
// REQUIRED HELPERS (only if missing)
// ------------------------------------------------------------
if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('cart_count_qty')) {
    function cart_count_qty(): int {
        $cart = $_SESSION['cart'] ?? [];
        $items = [];
        if (is_array($cart) && isset($cart['items']) && is_array($cart['items'])) {
            $items = $cart['items'];
        } elseif (is_array($cart)) {
            $items = $cart;
        }

        $qty = 0;
        foreach ($items as $item) {
            if (is_array($item) && isset($item['quantity'])) {
                $qty += max(0, (int)$item['quantity']);
            }
        }
        return $qty;
    }
}

// Helper: normalize any stored path into a public URL (supports absolute FS paths)
function media_to_public_url(string $path): string {
    $path = trim($path);
    if ($path === '') return '';

    if (strpos($path, 'data:') === 0) return $path;
    if (preg_match('#^https?://#i', $path)) return $path;

    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#(^|/)(\.\./)+#', '$1', $path);
    $path = str_replace('/./', '/', $path);

    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $dirRoot = rtrim(__DIR__, '/');

    foreach ([$docRoot, $dirRoot] as $root) {
        if ($root !== '' && (strpos($path, $root . '/') === 0 || $path === $root)) {
            $rel = substr($path, strlen($root));
            return '/' . ltrim((string)$rel, '/');
        }
    }

    return '/' . ltrim($path, '/');
}

// Enhanced file existence check with better path handling (supports absolute FS paths)
function public_file_exists(string $path): bool {
    static $existsCache = [];
    static $statCache = [];

    $path = trim($path);
    if ($path === '') return false;
    if (array_key_exists($path, $existsCache)) return $existsCache[$path];

    $remember = static function (bool $result) use (&$existsCache, $path): bool {
        $existsCache[$path] = $result;
        return $result;
    };

    $checkReadableFile = static function (string $candidate) use (&$statCache): bool {
        if ($candidate === '') return false;
        if (array_key_exists($candidate, $statCache)) return $statCache[$candidate];
        $ok = is_file($candidate) && is_readable($candidate);
        $statCache[$candidate] = $ok;
        return $ok;
    };

    if (preg_match('#^https?://#i', $path)) return $remember(true);
    if (strpos($path, 'data:') === 0) return $remember(true);

    $path = str_replace('\\', '/', $path);

    if (strpos($path, '/') === 0 && $checkReadableFile($path)) return $remember(true);

    $roots = [];
    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    if ($docRoot !== '') $roots[] = $docRoot;
    $roots[] = rtrim(__DIR__, '/');

    $public = media_to_public_url($path);
    $cleanPath = ltrim((string)parse_url($public, PHP_URL_PATH), '/');

    if ($cleanPath === '') return $remember(false);

    foreach ($roots as $root) {
        $full = $root . '/' . $cleanPath;
        if ($checkReadableFile($full)) return $remember(true);

        $vars = [
            $root . '/' . ltrim($path, '/'),
            $root . '/images/' . $cleanPath,
            $root . '/uploads/' . $cleanPath,
            $root . '/assets/' . $cleanPath,
            $root . '/products/' . $cleanPath,
            $root . '/images/products/' . basename($cleanPath),
            $root . '/webp/' . $cleanPath,
            $root . '/images/webp/' . $cleanPath
        ];
        foreach ($vars as $v) {
            if ($checkReadableFile($v)) return $remember(true);
        }
    }

    return $remember(false);
}

// Determine video MIME type from extension
function guess_video_mime(string $url): string {
    $path = parse_url($url, PHP_URL_PATH) ?: $url;
    $ext = strtolower(pathinfo((string)$path, PATHINFO_EXTENSION));
    return match ($ext) {
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'm4v' => 'video/x-m4v',
        default => '',
    };
}

// Robust video detection
function is_video_like(?string $fileType, string $url): bool {
    $t = strtolower(trim((string)$fileType));
    if ($t !== '') {
        if ($t === 'image') return false;
        if ($t === 'video') return true;
    }

    $path = parse_url($url, PHP_URL_PATH) ?: $url;
    $ext = strtolower(pathinfo((string)$path, PATHINFO_EXTENSION));
    return in_array($ext, ['mp4', 'webm', 'mov', 'm4v'], true);
}

/** Truncate text for meta description */
function truncate(string $text, int $length = 150): string {
    $text = strip_tags($text);
    if (mb_strlen($text) <= $length) return $text;
    return mb_substr($text, 0, $length - 3) . '...';
}

/** Build site base URL */
function site_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $host = preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string)$host);
    return $scheme . '://' . $host;
}

/** Get CSRF token */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf'];
}

/** Safe redirect */
function redirect_301(string $to): void {
    if (!headers_sent()) {
        header('Location: ' . $to, true, 301);
    } else {
        $safeTo = htmlspecialchars($to, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=' . $safeTo . '"></head><body><a href="' . $safeTo . '">Continue</a></body></html>';
    }
    exit;
}

// =============================================================================
// PRODUCT LOADING (ID OR SLUG) + FALLBACKS
// =============================================================================
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';

$fallbackIdFromSlug = 0;
if ($id <= 0 && $slug !== '' && preg_match('/-(\d{1,10})$/', $slug, $m)) {
    $fallbackIdFromSlug = (int)$m[1];
}

$cacheKey = $id > 0 ? ('product_id_' . $id) : ($slug !== '' ? ('product_slug_' . $slug) : '');
$cached = $cacheKey ? cache_get($cacheKey, 180) : false;

if ($cached !== false) {
    $product = $cached;
} else {
    $product = false;

    if ($slug !== '') {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE slug = :slug LIMIT 1');
        $stmt->execute([':slug' => $slug]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$product && $id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$product && $fallbackIdFromSlug > 0) {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $fallbackIdFromSlug]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($product) {
            $id = (int)$product['id'];
        }
    }

    if ($product && $cacheKey) cache_set($cacheKey, $product);
}

if (!$product) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>PRODUCT_NOT_FOUND | DIAMONDS OUTTA DIRT</title>';
    echo '<meta name="robots" content="noindex, follow">';
    echo '<style>body{margin:0;background:#000;color:#fff;font-family:ui-monospace,Menlo,Consolas,monospace;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px} .box{max-width:760px;border:1px solid #333;background:#050505;padding:22px;border-radius:10px} .h{color:#00ff9d;letter-spacing:2px;font-weight:800;margin:0 0 10px} .p{color:#bbb;line-height:1.6;margin:0 0 12px} .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px} .btn{display:inline-block;padding:10px 14px;border:1px solid #00ff9d;color:#00ff9d;text-decoration:none;font-size:12px;letter-spacing:1px;text-transform:uppercase}</style></head><body><div class="box"><div class="h">PRODUCT_NOT_FOUND</div><p class="p">This product page does not exist or is no longer available.</p><p class="p">Browse current inventory or return home.</p><div class="actions"><a class="btn" href="/shop">Browse Shop</a><a class="btn" href="/">Home</a></div></div></body></html>';
    exit;
}

// Cast to expected types
$product_id = (int)$product['id'];
$product_name = (string)($product['name'] ?? 'ITEM');
$product_category = (string)($product['category'] ?? 'UNKNOWN');
$product_price = (float)($product['price'] ?? 0);
$stock = (int)($product['stock'] ?? 0);
$is_sold_out = ($stock <= 0);

$product_slug = trim((string)($product['slug'] ?? ''));

// Canonical redirect
$reqUri = (string)($_SERVER['REQUEST_URI'] ?? '');
$isLegacyPhp = (stripos($reqUri, 'product.php') !== false);
$hasQuery = (strpos($reqUri, '?') !== false);

if ($product_slug !== '') {
    $canonicalPath = '/product/' . rawurlencode($product_slug);
    $canonicalAbs = site_base_url() . $canonicalPath;

    $slugMismatch = ($slug !== '' && $slug !== $product_slug);

    if (($slug === '' && $id > 0) || $isLegacyPhp || $hasQuery || $slugMismatch) {
        redirect_301($canonicalPath);
    }
} else {
    $canonicalAbs = site_base_url() . ($reqUri ? preg_replace('/\?.*$/', '', $reqUri) : '/product');
}

// SEO Fields
$meta_title = (string)($product['meta_title'] ?? '');
$meta_description = (string)($product['meta_description'] ?? '');
$meta_keywords = (string)($product['meta_keywords'] ?? 'Diamonds Outta Dirt, Streetwear, Archive, Fashion');

if ($meta_title === '') $meta_title = strtoupper($product_name) . ' | DIAMONDS OUTTA DIRT';
if ($meta_description === '') $meta_description = truncate((string)($product['description'] ?? ''), 150);

$meta_keywords = h($meta_keywords);

// Initialize debug trace buffer early so all later sections can safely append
$debug_trace = '';

// =============================================================================
// HERO IMAGE (safe placeholder only)
// =============================================================================
$product_image_raw = (string)($product['image_url'] ?? '');
$product_image = '';
if ($product_image_raw !== '') {
    if (public_file_exists($product_image_raw)) {
        $product_image = media_to_public_url($product_image_raw);
    } else {
        $try_paths = [
            'images/' . ltrim($product_image_raw, '/'),
            'uploads/' . ltrim($product_image_raw, '/'),
            'assets/' . ltrim($product_image_raw, '/'),
            'images/products/' . basename($product_image_raw),
            'images/webp/' . ltrim($product_image_raw, '/'),
            'webp/' . ltrim($product_image_raw, '/'),
        ];
        foreach ($try_paths as $try) {
            if (public_file_exists($try)) {
                $product_image = media_to_public_url($try);
                break;
            }
        }
    }
}
if ($product_image === '') {
    $svg = rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="750">'
        . '<rect width="100%" height="100%" fill="#0a0a0f"/>'
        . '<text x="50%" y="45%" fill="#00ff9d" font-size="20" text-anchor="middle" font-family="monospace">DIAMONDS OUTTA DIRT</text>'
        . '<text x="50%" y="53%" fill="#00ff9d" font-size="14" text-anchor="middle" font-family="monospace">NO PRODUCT IMAGE</text>'
        . '</svg>'
    );
    $product_image = 'data:image/svg+xml;charset=UTF-8,' . $svg;
    $debug_trace .= "<!-- IMAGE_NOT_FOUND: " . h($product_image_raw) . " -->\n";
}

// =============================================================================
// BACKGROUND RESOLUTION (assignment background/bg)
// =============================================================================
$bg_source = null;
$bg_type = 'none';
$debug_trace .= "<!-- BACKGROUND_RESOLUTION_TRACE v20.1 START -->\n";

try {
    if (db_table_exists($pdo, 'product_images')) {
        $bgStmt = $pdo->prepare("
            SELECT id, image_url, file_type, assignment
            FROM product_images
            WHERE product_id = :id
              AND (assignment = 'background' OR assignment = 'bg')
              AND image_url IS NOT NULL
              AND image_url != ''
            ORDER BY position ASC, id ASC
            LIMIT 1
        ");
        $bgStmt->execute([':id' => $product_id]);
        $bgRow = $bgStmt->fetch(PDO::FETCH_ASSOC);

        if ($bgRow && !empty($bgRow['image_url'])) {
            $bg_source = (string)$bgRow['image_url'];
            $bg_type = is_video_like((string)($bgRow['file_type'] ?? ''), $bg_source) ? 'video' : 'image';
            $debug_trace .= "<!-- SYSTEM_A_FOUND: " . h($bg_source) . " -->\n";

            if (!preg_match('#^https?://#i', $bg_source)) {
                if (!public_file_exists($bg_source)) {
                    $debug_trace .= "<!-- LOCAL_FILE_NOT_FOUND: " . h($bg_source) . " -->\n";
                    $variations = [
                        $bg_source,
                        '/' . ltrim($bg_source, '/'),
                        ltrim($bg_source, '/'),
                        'images/' . ltrim($bg_source, '/'),
                        'webp/' . ltrim($bg_source, '/'),
                        'images/webp/' . ltrim($bg_source, '/'),
                        'uploads/' . ltrim($bg_source, '/'),
                        str_replace('../', '', $bg_source),
                        basename($bg_source),
                    ];
                    $found = false;
                    foreach ($variations as $variation) {
                        if (public_file_exists($variation)) {
                            $bg_source = $variation;
                            $found = true;
                            $debug_trace .= "<!-- FALLBACK_FOUND: " . h($variation) . " -->\n";
                            break;
                        }
                    }
                    if (!$found) {
                        $bg_source = null;
                        $bg_type = 'none';
                        $debug_trace .= "<!-- NO_VALID_LOCAL_BACKGROUND -->\n";
                    }
                }
            } else {
                $debug_trace .= "<!-- REMOTE_URL_DETECTED: Skipping local file checks for " . h($bg_source) . " -->\n";
            }
        } else {
            $debug_trace .= "<!-- SYSTEM_A_NO_BACKGROUND_ASSIGNMENT -->\n";

            $fallbackStmt = $pdo->prepare("
                SELECT image_url, file_type
                FROM product_images
                WHERE product_id = :id
                  AND image_url IS NOT NULL
                  AND image_url != ''
                ORDER BY position ASC, id ASC
                LIMIT 1
            ");
            $fallbackStmt->execute([':id' => $product_id]);
            $fallbackRow = $fallbackStmt->fetch(PDO::FETCH_ASSOC);

            if ($fallbackRow && !empty($fallbackRow['image_url'])) {
                $bg_source = (string)$fallbackRow['image_url'];
                $bg_type = is_video_like((string)($fallbackRow['file_type'] ?? ''), $bg_source) ? 'video' : 'image';
                $debug_trace .= "<!-- SYSTEM_A_FALLBACK: " . h($bg_source) . " -->\n";
            }
        }
    } else {
        $debug_trace .= "<!-- PRODUCT_IMAGES_TABLE_NOT_FOUND -->\n";
    }
} catch (Throwable $e) {
    $debug_trace .= "<!-- SYSTEM_A_ERROR: " . h($e->getMessage()) . " -->\n";
    error_log('Background query error: ' . $e->getMessage());
}

// SYSTEM B: legacy products.bg_url override
if (!$bg_source && !empty($product['bg_url'])) {
    $bg_source = (string)$product['bg_url'];
    $bg_type = is_video_like((string)($product['bg_type'] ?? ''), $bg_source) ? 'video' : 'image';
    $debug_trace .= "<!-- SYSTEM_B_OVERRIDE: " . h($bg_source) . " -->\n";

    if ($bg_source && preg_match('#^https?://#i', $bg_source)) {
        $debug_trace .= "<!-- SYSTEM_B_REMOTE_URL: Trusting database entry -->\n";
    } elseif ($bg_source && !public_file_exists($bg_source)) {
        $debug_trace .= "<!-- SYSTEM_B_LOCAL_NOT_FOUND: " . h($bg_source) . " -->\n";
        $bg_source = null;
        $bg_type = 'none';
    }
}

// Normalize local background paths to site-root (don't break remote urls)
if (is_string($bg_source) && $bg_source !== '' && !preg_match('#^https?://#i', $bg_source)) {
    $bg_source = media_to_public_url($bg_source);
    if (!public_file_exists($bg_source)) {
        $debug_trace .= "<!-- FINAL_LOCAL_CHECK_FAILED: " . h($bg_source) . " -->\n";
        $bg_source = null;
        $bg_type = 'none';
    }
}

$debug_trace .= "<!-- FINAL_BACKGROUND: " . ($bg_source ? h($bg_source) : 'NONE') . " -->\n";
$debug_trace .= "<!-- FINAL_BG_TYPE: {$bg_type} -->\n";
$debug_trace .= "<!-- BACKGROUND_RESOLUTION_TRACE v20.1 END -->\n";

// =============================================================================
// GALLERY (WITH FALLBACK)
// =============================================================================
$gallery_items = [];

$gallery_items[] = [
    'url' => $product_image,
    'type' => 'image',
    'id' => 0,
];

try {
    if (db_table_exists($pdo, 'product_images')) {
        $debug_trace .= "<!-- GALLERY_QUERY_START: Looking for product_id = {$product_id} -->\n";

        $raw_assets = [];

        try {
            $galleryStmt = $pdo->prepare("
                SELECT id, image_url, file_type, assignment
                FROM product_images
                WHERE product_id = :product_id
                  AND assignment = 'gallery'
                  AND image_url IS NOT NULL
                  AND image_url != ''
                ORDER BY position ASC, id ASC
                LIMIT 12
            ");
            $galleryStmt->execute([':product_id' => $product_id]);
            $raw_assets = $galleryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $debug_trace .= "<!-- GALLERY_QUERY_1 (assignment='gallery'): Found " . count($raw_assets) . " rows -->\n";
        } catch (Throwable $e) {
            $debug_trace .= "<!-- GALLERY_QUERY_1_ERROR: " . h($e->getMessage()) . " -->\n";
            error_log('Gallery query 1 error: ' . $e->getMessage());
            $raw_assets = [];
        }

        if (empty($raw_assets)) {
            $debug_trace .= "<!-- GALLERY_QUERY_2: Falling back to legacy selection -->\n";
            try {
                $galleryStmt = $pdo->prepare("
                    SELECT id, image_url, file_type, assignment
                    FROM product_images
                    WHERE product_id = :product_id
                      AND (assignment IS NULL OR assignment = '' OR assignment NOT IN ('background', 'bg'))
                      AND image_url IS NOT NULL
                      AND image_url != ''
                    ORDER BY position ASC, id ASC
                    LIMIT 12
                ");
                $galleryStmt->execute([':product_id' => $product_id]);
                $raw_assets = $galleryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $debug_trace .= "<!-- GALLERY_QUERY_2: Found " . count($raw_assets) . " rows -->\n";
            } catch (Throwable $e) {
                $debug_trace .= "<!-- GALLERY_QUERY_2_ERROR: " . h($e->getMessage()) . " -->\n";
                error_log('Gallery query 2 error: ' . $e->getMessage());
                $raw_assets = [];
            }
        }

        foreach ($raw_assets as $asset) {
            $urlRaw = (string)($asset['image_url'] ?? '');
            if ($urlRaw === '') continue;

            if (!preg_match('#^https?://#i', $urlRaw) && strpos($urlRaw, 'data:') !== 0) {
                if (!public_file_exists($urlRaw)) continue;
            }

            $url = media_to_public_url($urlRaw);

            if ($url === $product_image) continue;

            $isVid = is_video_like((string)($asset['file_type'] ?? ''), $url);

            $gallery_items[] = [
                'url' => $url,
                'type' => $isVid ? 'video' : 'image',
                'id' => (int)($asset['id'] ?? 0),
            ];
        }
    }
} catch (PDOException $e) {
    $debug_trace .= "<!-- GALLERY_QUERY_FAILED: " . h($e->getMessage()) . " -->\n";
    error_log('Gallery query failed: ' . $e->getMessage());
}

$debug_trace .= "<!-- FINAL_GALLERY_ITEMS: " . (count($gallery_items) - 1) . " (excluding hero) -->\n";

if (count($gallery_items) <= 1) {
    for ($i = 1; $i <= 3; $i++) {
        $gallery_items[] = [
            'url' => $product_image,
            'type' => 'image',
            'id' => $i,
        ];
    }
}

// =============================================================================
// AUDIO
// =============================================================================
$audio_url = trim((string)($product['audio_url'] ?? ''));
$is_soundcloud = ($audio_url !== '' && stripos($audio_url, 'soundcloud.com') !== false);

// =============================================================================
// SIZES
// =============================================================================
$size_str = (string)($product['available_sizes'] ?? '');
$sizes = array_values(array_filter(array_map('trim', $size_str !== '' ? explode(',', $size_str) : [])));
if (empty($sizes)) $sizes = ['OS'];

// =============================================================================
// CART COUNT
// =============================================================================
$bag_qty = cart_count_qty();
$csrf_token = csrf_token();
$uriPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$usingCleanUrls = (strpos($uriPath, '.php') === false);
$cart_endpoint = $usingCleanUrls ? '/cart' : '/cart.php';

// =============================================================================
// WISHLIST STATE (customer_id session, matches account/auth.php)
// =============================================================================
$is_logged_in = !empty($_SESSION['customer_id']);
$is_wishlisted = false;
if ($is_logged_in) {
    try {
        $wlStmt = $pdo->prepare("SELECT 1 FROM wishlists WHERE customer_id = ? AND product_id = ? LIMIT 1");
        $wlStmt->execute([(int)$_SESSION['customer_id'], $product_id]);
        $is_wishlisted = (bool)$wlStmt->fetch();
    } catch (Throwable $e) {
        error_log('Wishlist state check failed: ' . $e->getMessage());
    }
}

// =============================================================================
// ABS IMAGE FOR OG TAGS
// =============================================================================
$base = site_base_url();
$abs_image = $product_image;
if (!preg_match('#^https?://#', $product_image) && strpos($product_image, 'data:image') !== 0) {
    $abs_image = $base . '/' . ltrim($product_image, '/');
}

// =============================================================================
// RATING + SCHEMA DATA
// =============================================================================
$ratingValue = null;
$ratingCount = 0;
$ratingValueKeys = ['rating_average', 'avg_rating', 'average_rating', 'rating'];
$ratingCountKeys = ['rating_count', 'ratings_count', 'review_count'];

foreach ($ratingValueKeys as $key) {
    if (isset($product[$key]) && is_numeric((string)$product[$key])) {
        $ratingValue = round((float)$product[$key], 2);
        break;
    }
}
foreach ($ratingCountKeys as $key) {
    if (isset($product[$key]) && is_numeric((string)$product[$key])) {
        $ratingCount = max(0, (int)$product[$key]);
        break;
    }
}

if (($ratingValue === null || $ratingCount < 1) && isset($pdo) && $pdo instanceof PDO) {
    foreach (['product_reviews', 'reviews'] as $reviewTable) {
        try {
            if (!db_table_exists($pdo, $reviewTable)) {
                continue;
            }

            $hasApproved = db_column_exists($pdo, $reviewTable, 'approved');
            $hasStatus = db_column_exists($pdo, $reviewTable, 'status');

            $reviewSql = "SELECT AVG(rating) AS avg_rating, COUNT(*) AS rating_count FROM `$reviewTable` WHERE product_id = :pid";
            if ($hasApproved) {
                $reviewSql .= " AND approved = 1";
            } elseif ($hasStatus) {
                $reviewSql .= " AND status IN ('approved','published','active')";
            }

            $reviewStmt = $pdo->prepare($reviewSql);
            $reviewStmt->execute([':pid' => $product_id]);
            $reviewRow = $reviewStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $reviewCount = isset($reviewRow['rating_count']) ? (int)$reviewRow['rating_count'] : 0;
            $reviewAvg = isset($reviewRow['avg_rating']) ? (float)$reviewRow['avg_rating'] : 0.0;

            if ($reviewCount > 0 && $reviewAvg > 0) {
                $ratingCount = $reviewCount;
                $ratingValue = round($reviewAvg, 2);
                break;
            }
        } catch (Throwable $e) {
            error_log('Review schema lookup failed: ' . $e->getMessage());
        }
    }
}

$productSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'Product',
    'name' => $product_name,
    'description' => $meta_description,
    'sku' => (string)$product_id,
    'category' => $product_category,
    'url' => $canonicalAbs,
    'brand' => [
        '@type' => 'Brand',
        'name' => 'DIAMONDS OUTTA DIRT',
    ],
    'offers' => [
        '@type' => 'Offer',
        'priceCurrency' => 'USD',
        'price' => number_format($product_price, 2, '.', ''),
        'availability' => $is_sold_out ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock',
        'itemCondition' => 'https://schema.org/NewCondition',
        'url' => $canonicalAbs,
    ],
];
if (preg_match('#^https?://#', $abs_image)) {
    $productSchema['image'] = [$abs_image];
}
if ($ratingValue !== null && $ratingCount > 0) {
    $productSchema['aggregateRating'] = [
        '@type' => 'AggregateRating',
        'ratingValue' => number_format($ratingValue, 2, '.', ''),
        'ratingCount' => (string)$ratingCount,
        'bestRating' => '5',
        'worstRating' => '1',
    ];
}

// =============================================================================
// TAGGED MODELS (OPTIONAL SOCIAL LINKS)
// =============================================================================
$taggedModels = [];
try {
    $tagsTableExists = db_table_exists($pdo, 'product_model_tags');
    if ($tagsTableExists) {
        $hasModelWebsiteUrl = false;
        try {
            $hasModelWebsiteUrl = db_column_exists($pdo, 'product_model_tags', 'model_website_url');
        } catch (Throwable $e) {
            $hasModelWebsiteUrl = false;
        }

        $websiteSelect = $hasModelWebsiteUrl ? 'model_website_url' : 'NULL AS model_website_url';
        $tagsStmt = $pdo->prepare("
            SELECT model_name, instagram_url, tiktok_url, {$websiteSelect}, image_url, sort_order
            FROM product_model_tags
            WHERE product_id = :pid
            ORDER BY sort_order ASC, id ASC
            LIMIT 8
        ");
        $tagsStmt->execute([':pid' => $product_id]);
        $tagsRaw = $tagsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($tagsRaw as $tag) {
            $name = trim((string)($tag['model_name'] ?? ''));
            $ig = trim((string)($tag['instagram_url'] ?? ''));
            $tt = trim((string)($tag['tiktok_url'] ?? ''));
            $website = trim((string)($tag['model_website_url'] ?? ''));
            $img = trim((string)($tag['image_url'] ?? ''));

            if ($name === '' && $ig === '' && $tt === '' && $website === '' && $img === '') {
                continue;
            }

            if ($ig !== '' && !preg_match('~^https?://~i', $ig)) {
                $ig = 'https://' . ltrim($ig, '/');
            }
            if ($tt !== '' && !preg_match('~^https?://~i', $tt)) {
                $tt = 'https://' . ltrim($tt, '/');
            }
            if ($ig !== '' && !filter_var($ig, FILTER_VALIDATE_URL)) {
                $ig = '';
            }
            if ($tt !== '' && !filter_var($tt, FILTER_VALIDATE_URL)) {
                $tt = '';
            }
            if ($website !== '' && !preg_match('~^https?://~i', $website)) {
                $website = 'https://' . ltrim($website, '/');
            }
            if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) {
                $website = '';
            }

            if ($img !== '' && !preg_match('#^https?://#i', $img) && strpos($img, 'data:') !== 0) {
                $img = media_to_public_url($img);
            }

            $taggedModels[] = [
                'name' => $name !== '' ? $name : 'MODEL',
                'instagram_url' => $ig,
                'tiktok_url' => $tt,
                'website_url' => $website,
                'image_url' => $img,
            ];
        }
    }
} catch (Throwable $e) {
    error_log('Tagged models query failed: ' . $e->getMessage());
}

// =============================================================================
// PRODUCT REVIEWS (approved only)
// =============================================================================
$productReviews = [];
try {
    if (db_table_exists($pdo, 'product_reviews')) {
        $revStmt = $pdo->prepare("
            SELECT user_name, rating, review, created_at
            FROM product_reviews
            WHERE product_id = :pid AND approved = 1
            ORDER BY created_at DESC
            LIMIT 50
        ");
        $revStmt->execute([':pid' => $product_id]);
        $productReviews = $revStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    error_log('Product reviews query failed: ' . $e->getMessage());
}

// =============================================================================
// RELATED PRODUCTS -- attribute-based similarity, rendered via
// related_products.php (see that file's header for scoping notes).
// Replaces the old same-category-only block.
// =============================================================================
$relatedForProductId = $product_id;

// =============================================================================
// RENDER
// =============================================================================
?>
<!DOCTYPE html>
<html lang="en" class="no-js">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">

    <title><?= h($meta_title) ?></title>

    <?php if (is_file(__DIR__ . '/includes/tracking_pixels.php')) { require_once __DIR__ . '/includes/tracking_pixels.php'; } ?>

    <meta name="description" content="<?= h($meta_description) ?>">
    <meta name="keywords" content="<?= $meta_keywords ?>">

    <meta property="og:title" content="<?= h($meta_title) ?>">
    <meta property="og:description" content="<?= h($meta_description) ?>">
    <meta property="og:image" content="<?= h($abs_image) ?>">
    <meta property="og:url" content="<?= h($canonicalAbs) ?>">
    <meta property="og:type" content="product">
    <meta property="og:site_name" content="DIAMONDS OUTTA DIRT">
    <link rel="canonical" href="<?= h($canonicalAbs) ?>">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= h($meta_title) ?>">
    <meta name="twitter:description" content="<?= h($meta_description) ?>">
    <meta name="twitter:image" content="<?= h($abs_image) ?>">
    <script nonce="<?= h($csp_nonce) ?>" type="application/ld+json"><?= json_encode($productSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>

    <link rel="preload" href="css/master.css" as="style">
    <link rel="preload" href="<?= h($product_image) ?>" as="image">

    <link rel="stylesheet" href="css/master.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@900&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">

    <?php if ($is_soundcloud) : ?>
    <link rel="preconnect" href="https://soundcloud.com">
    <script nonce="<?= h($csp_nonce) ?>" src="https://w.soundcloud.com/player/api.js"></script>
    <?php endif; ?>
    <script src="/js/telemetry.js" defer></script>

    <style>
        :root {
            --accent: #00ff9d;
            --glitch-red: #ff3e3e;
            --cyber: #00f3ff;
            --dark: #000;
            --darker: #050505;
            --border: #333;
        }

        body {
            background: transparent !important;
            color: #fff;
            margin: 0;
            font-family: 'Space Mono', monospace;
            overflow-y: auto;
            -webkit-text-size-adjust: 100%;
            -webkit-font-smoothing: antialiased;
            min-height: 100%;
        }

        html { background: #000; }

        button:focus-visible,
        a:focus-visible,
        select:focus-visible,
        input:focus-visible,
        [role="button"]:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 2px;
            box-shadow: 0 0 0 3px rgba(0, 255, 157, 0.2);
        }

        body.data-hidden { overflow: hidden; }
        body.lightbox-open { overflow: hidden; }

        .dynamic-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100vh;
            z-index: 0;
            background: var(--dark);
        }

        .fullscreen-media {
            width: 100%;
            height: 100%;
            object-fit: cover;
            filter: brightness(0.3) contrast(1.1);
            transition: filter 0.8s ease;
        }
        .bg-view-active .fullscreen-media {
            filter: none !important;
            -webkit-filter: none !important;
            opacity: 1 !important;
            image-rendering: auto;
            backface-visibility: visible;
            transform: none !important;
            transition: none !important;
        }

        body.data-hidden .tactical-nav { display: none !important; }
        body.data-hidden .hero-block { display: none !important; }
        body.data-hidden .lb { display: none !important; }
        body.data-hidden .mobile-buy-bar { display: none !important; }
        body.data-hidden .related-section { display: none !important; }

        .hero-block {
            position: relative;
            z-index: 2;
            display: flex;
            min-height: 100vh;
            padding: calc(110px + env(safe-area-inset-top)) 8% 120px;
            box-sizing: border-box;
            transition: transform 0.8s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.5s;
            gap: 60px;
        }
        @media (max-width: 900px) {
            .hero-block {
                flex-direction: column;
                padding: calc(90px + env(safe-area-inset-top)) 16px 140px;
                gap: 26px;
            }
        }

        .product-visual-slot {
            flex: 1.2;
            position: sticky;
            top: 120px;
            align-self: flex-start;
        }
        @media (max-width: 900px) {
            .product-visual-slot { position: relative; top: 0; width: 100%; }
        }

        .product-details {
            flex: 1;
            max-width: 620px;
            background: linear-gradient(145deg, rgba(6, 10, 11, 0.8), rgba(2, 3, 3, 0.62));
            border: 1px solid rgba(0, 255, 157, 0.34);
            box-shadow:
                0 12px 34px rgba(0, 0, 0, 0.42),
                0 0 0 1px rgba(0, 255, 157, 0.08) inset;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border-radius: 14px;
            padding: 24px;
        }
        @media (max-width: 900px) {
            .product-details {
                max-width: 100%;
                border-radius: 12px;
                padding: 18px;
            }
        }

        #main-media-container {
            width: 100%;
            border: 1px solid var(--accent);
            background: rgba(0, 0, 0, 0.5);
            overflow: hidden;
            box-shadow: 0 0 20px rgba(0, 255, 157, 0.12);
            cursor: zoom-in;
        }
        #main-media-container[data-type="video"] { cursor: pointer; }

        .main-asset {
            width: 100%;
            display: block;
            aspect-ratio: 1;
            object-fit: contain;
        }

        #ui-toggle {
            position: fixed;
            bottom: calc(16px + env(safe-area-inset-bottom));
            left: 50%;
            transform: translateX(-50%);
            z-index: 9999;
            background: rgba(0, 0, 0, 0.8);
            color: var(--accent);
            border: 1px solid var(--accent);
            padding: 12px 18px;
            font-family: 'Space Mono';
            font-size: 0.65rem;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: 3px;
            transition: 0.3s;
            backdrop-filter: blur(10px);
            -webkit-tap-highlight-color: transparent;
        }
        #ui-toggle:hover,
        #ui-toggle:focus-visible {
            background: var(--accent);
            color: #000;
            outline: none;
        }

        .status-label {
            display: inline-block;
            color: var(--accent);
            font-size: 0.6rem;
            letter-spacing: 2px;
            background: #000;
            padding: 4px 8px;
            margin-bottom: 10px;
            border-left: 2px solid var(--accent);
        }

        .glitch-title {
            position: relative;
            font-family: 'Inter', sans-serif;
            font-weight: 900;
            font-size: clamp(2rem, 4.2vw, 3.8rem);
            text-transform: uppercase;
            line-height: 0.92;
            letter-spacing: 0.02em;
            margin: 10px 0 14px;
        }
        .product-price {
            font-size: clamp(1.4rem, 2.5vw, 1.9rem);
            color: var(--accent);
            margin: 0 0 18px;
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: 0.02em;
        }
        .glitch-title::before,
        .glitch-title::after {
            content: attr(data-text);
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            overflow: hidden;
            opacity: 0.85;
            pointer-events: none;
        }
        .glitch-title::before {
            transform: translate(1px, 0);
            color: var(--accent);
            clip-path: inset(0 0 55% 0);
            animation: glitchTop 2.2s infinite linear alternate-reverse;
        }
        .glitch-title::after {
            transform: translate(-1px, 0);
            color: var(--glitch-red);
            clip-path: inset(45% 0 0 0);
            animation: glitchBot 1.9s infinite linear alternate-reverse;
        }
        @keyframes glitchTop {
            0% { transform: translate(1px, 0); }
            20% { transform: translate(-2px, -1px); }
            40% { transform: translate(2px, 1px); }
            60% { transform: translate(-1px, 0); }
            80% { transform: translate(3px, -1px); }
            100% { transform: translate(0, 0); }
        }
        @keyframes glitchBot {
            0% { transform: translate(-1px, 0); }
            20% { transform: translate(2px, 1px); }
            40% { transform: translate(-3px, 0); }
            60% { transform: translate(1px, -1px); }
            80% { transform: translate(-2px, 1px); }
            100% { transform: translate(0, 0); }
        }

        .dod-input {
            background: #000;
            color: #fff;
            border: 1px solid var(--accent);
            padding: 15px;
            font-family: 'Space Mono';
            width: 100%;
            margin-bottom: 15px;
            outline: none;
            text-align: center;
            font-size: 0.8rem;
            border-radius: 0;
        }

        .qty-row {
            display: flex;
            gap: 10px;
            align-items: stretch;
            margin-bottom: 15px;
        }
        @media (max-width: 420px) { .qty-row { flex-direction: column; } }

        .qty-stepper {
            display: flex;
            align-items: stretch;
            border: 1px solid var(--accent);
            background: #000;
            width: 170px;
            min-width: 170px;
        }
        @media (max-width: 900px) { .qty-stepper { width: 160px; min-width: 160px; } }
        @media (max-width: 420px) { .qty-stepper { width: 100%; min-width: 0; } }

        .qty-stepper button {
            width: 46px;
            background: transparent;
            color: var(--accent);
            border: none;
            cursor: pointer;
            font-family: 'Space Mono';
            letter-spacing: 2px;
            font-size: 0.9rem;
            -webkit-tap-highlight-color: transparent;
        }
        .qty-stepper button:hover { background: rgba(0, 255, 157, 0.12); }
        .qty-stepper button:disabled { opacity: 0.35; cursor: not-allowed; }
        .qty-stepper input {
            width: 78px;
            background: #000;
            color: #fff;
            border: none;
            text-align: center;
            font-family: 'Space Mono';
            font-size: 0.85rem;
            outline: none;
        }

        .btn-cta {
            background: var(--accent);
            color: #000;
            border: none;
            padding: 22px;
            font-weight: bold;
            width: 100%;
            cursor: pointer;
            text-transform: uppercase;
            transition: 0.3s;
            letter-spacing: 1px;
            -webkit-tap-highlight-color: transparent;
        }
        .btn-cta:hover,
        .btn-cta:focus-visible {
            background: #fff;
            box-shadow: 0 0 25px var(--accent);
            outline: none;
        }
        .btn-cta:disabled {
            opacity: 0.35;
            cursor: not-allowed;
            box-shadow: none;
        }

        #wishlist-btn {
            background: none;
            border: 1px solid rgba(0, 255, 157, 0.35);
            border-radius: 6px;
            cursor: pointer;
            font-size: 1.5rem;
            color: #ff00ff;
            outline: none;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: border-color .2s ease, transform .15s ease;
        }
        #wishlist-btn:hover { border-color: #ff00ff; }
        #wishlist-btn:active { transform: scale(0.92); }
        #wishlist-btn.wl-active #wishlist-heart::before { content: "♥"; }
        #wishlist-btn:not(.wl-active) #wishlist-heart::before { content: "♡"; }
        #wishlist-btn.wl-loading { opacity: 0.5; cursor: wait; }

        .inline-meta-panels {
            margin-top: 16px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .inline-meta-panel {
            border: 1px solid rgba(0, 255, 157, 0.35);
            background: rgba(0, 0, 0, 0.46);
            padding: 12px;
            font-size: 0.74rem;
            line-height: 1.7;
            letter-spacing: 0.3px;
        }
        .inline-meta-title {
            display: block;
            color: var(--accent);
            font-size: 0.62rem;
            margin-bottom: 6px;
            letter-spacing: 1.6px;
            text-transform: uppercase;
        }
        @media (max-width: 640px) {
            .inline-meta-panels { grid-template-columns: 1fr; }
        }

        .tagged-models {
            margin-top: 16px;
            border: 1px solid rgba(0, 255, 157, 0.25);
            background: rgba(0, 0, 0, 0.42);
            padding: 12px;
        }
        .tagged-models-title {
            margin: 0 0 10px;
            color: var(--accent);
            font-size: 0.62rem;
            letter-spacing: 2px;
            text-transform: uppercase;
        }
        .tagged-models-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .model-tag-card {
            border: 1px solid rgba(0, 255, 157, 0.2);
            background: rgba(8, 8, 8, 0.75);
            padding: 10px;
        }
        .model-tag-head {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }
        .model-tag-avatar {
            width: 28px;
            height: 28px;
            border-radius: 999px;
            object-fit: cover;
            border: 1px solid rgba(0, 255, 157, 0.35);
            background: #111;
        }
        .model-tag-name {
            font-size: 0.68rem;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #fff;
            line-height: 1.2;
        }
        .model-tag-links {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .model-social-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            border: 1px solid rgba(0, 255, 157, 0.45);
            color: var(--accent);
            padding: 5px 8px;
            font-size: 0.56rem;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .model-social-link:hover,
        .model-social-link:focus-visible {
            background: var(--accent);
            color: #000;
            outline: none;
        }
        .model-social-icon {
            width: 14px;
            height: 14px;
            border-radius: 999px;
            border: 1px solid currentColor;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.5rem;
            font-weight: 700;
            line-height: 1;
        }
        @media (max-width: 900px) {
            .tagged-models-grid { grid-template-columns: 1fr; }
        }

        .related-section {
            position: relative;
            z-index: 2;
            margin: 0 8% 140px;
            border-top: 1px solid rgba(0, 255, 157, 0.2);
            padding-top: 30px;
        }
        .related-title {
            margin: 0 0 16px;
            color: var(--accent);
            font-size: 0.72rem;
            letter-spacing: 2px;
            text-transform: uppercase;
        }
        .related-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }
        .related-card {
            display: block;
            border: 1px solid rgba(0, 255, 157, 0.25);
            background: rgba(0, 0, 0, 0.46);
            text-decoration: none;
            color: #fff;
            overflow: hidden;
            transform: translateY(0);
            box-shadow: 0 8px 22px rgba(0, 0, 0, 0.28);
            transition: border-color 0.25s ease, transform 0.25s ease, box-shadow 0.25s ease;
        }
        .related-card:hover,
        .related-card:focus-visible {
            border-color: var(--accent);
            transform: translateY(-4px);
            box-shadow: 0 14px 30px rgba(0, 0, 0, 0.42);
            outline: none;
        }
        .related-card-media {
            position: relative;
            overflow: hidden;
        }
        .related-thumb {
            width: 100%;
            aspect-ratio: 1 / 1;
            object-fit: cover;
            display: block;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            transition: transform 0.45s ease;
        }
        .related-card:hover .related-thumb,
        .related-card:focus-visible .related-thumb {
            transform: scale(1.06);
        }
        .related-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            z-index: 2;
            padding: 4px 8px;
            border: 1px solid rgba(255, 62, 62, 0.8);
            background: rgba(18, 0, 0, 0.82);
            color: #ff7c7c;
            font-size: 0.54rem;
            letter-spacing: 1.3px;
            text-transform: uppercase;
            font-weight: 700;
        }
        .related-info {
            padding: 10px;
        }
        .related-name {
            margin: 0 0 6px;
            font-size: 0.72rem;
            line-height: 1.35;
            letter-spacing: 0.8px;
            text-transform: uppercase;
        }
        .related-price {
            font-size: 0.7rem;
            color: var(--accent);
            letter-spacing: 1px;
        }
        .related-stock {
            margin-top: 4px;
            font-size: 0.58rem;
            color: #888;
            letter-spacing: 1px;
        }
        @media (max-width: 900px) {
            .related-section { margin: 0 16px 160px; }
            .related-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        /* Rating summary link (near price) */
        .rating-summary-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            margin: -8px 0 18px;
            cursor: pointer;
        }
        .rating-stars { color: var(--accent); font-size: 0.95rem; letter-spacing: 1px; }
        .rating-count { color: #999; font-size: 0.68rem; letter-spacing: 0.5px; }
        .rating-summary-link:hover .rating-count { color: var(--accent); }

        /* Reviews drawer — contained inside product-details so the background stays clear */
        .reviews-drawer {
            position: relative;
            z-index: 1;
            margin-top: 18px;
            border: 1px solid rgba(0, 255, 157, 0.34);
            border-radius: 6px;
            background: rgba(0, 0, 0, 0.52);
            overflow: hidden;
            scroll-margin-top: 110px;
        }
        .reviews-drawer[open] {
            background: rgba(0, 0, 0, 0.72);
            box-shadow: 0 0 22px rgba(0, 255, 157, 0.08);
        }
        .reviews-drawer-summary {
            min-height: 54px;
            display: grid;
            grid-template-columns: 1fr auto auto;
            align-items: center;
            gap: 10px;
            padding: 0 14px;
            cursor: pointer;
            list-style: none;
            user-select: none;
            -webkit-tap-highlight-color: transparent;
        }
        .reviews-drawer-summary::-webkit-details-marker { display: none; }
        .reviews-drawer-summary::marker { content: ''; }
        .reviews-drawer-summary:hover,
        .reviews-drawer-summary:focus-visible {
            background: rgba(0, 255, 157, 0.08);
            outline: none;
        }
        .reviews-drawer-label {
            color: var(--accent);
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
        }
        .reviews-drawer-meta {
            color: #929292;
            font-size: 0.62rem;
            letter-spacing: 0.7px;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .reviews-drawer-chevron {
            color: var(--accent);
            font-size: 1rem;
            line-height: 1;
            transition: transform 0.2s ease;
        }
        .reviews-drawer[open] .reviews-drawer-chevron {
            transform: rotate(180deg);
        }
        .reviews-drawer-content {
            max-height: min(68vh, 680px);
            overflow-y: auto;
            overscroll-behavior: contain;
            border-top: 1px solid rgba(0, 255, 157, 0.2);
            padding: 18px;
            scrollbar-width: thin;
            scrollbar-color: rgba(0, 255, 157, 0.55) rgba(0, 0, 0, 0.35);
        }
        .reviews-drawer-content::-webkit-scrollbar { width: 8px; }
        .reviews-drawer-content::-webkit-scrollbar-track { background: rgba(0, 0, 0, 0.35); }
        .reviews-drawer-content::-webkit-scrollbar-thumb {
            background: rgba(0, 255, 157, 0.5);
            border-radius: 999px;
        }

        .reviews-section {
            position: static;
            z-index: auto;
            margin: 0;
            border: 0;
            padding: 0;
            max-width: none;
        }
        .reviews-title {
            margin: 0 0 20px;
            color: var(--accent);
            font-size: 0.72rem;
            letter-spacing: 2px;
            text-transform: uppercase;
        }
        .review-card {
            border: 1px solid rgba(0, 255, 157, 0.2);
            background: rgba(0, 0, 0, 0.42);
            padding: 16px;
            margin-bottom: 14px;
            border-radius: 4px;
        }
        .review-card-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }
        .review-stars { color: var(--accent); font-size: 0.85rem; letter-spacing: 1px; }
        .review-author { color: #fff; font-size: 0.68rem; letter-spacing: 1px; text-transform: uppercase; }
        .review-date { color: #777; font-size: 0.6rem; letter-spacing: 0.5px; }
        .review-text { color: #c4c4c4; font-size: 0.82rem; line-height: 1.7; }
        .no-reviews-note { color: #888; font-size: 0.78rem; margin-bottom: 24px; }

        .review-form {
            border: 1px solid rgba(0, 255, 157, 0.35);
            background: rgba(0, 0, 0, 0.46);
            padding: 20px;
            border-radius: 4px;
            margin-top: 20px;
        }
        .review-form-title {
            color: var(--accent);
            font-size: 0.68rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 16px;
        }
        .review-star-picker {
            display: flex;
            gap: 6px;
            margin-bottom: 16px;
        }
        .review-star-btn {
            background: none;
            border: none;
            font-size: 1.6rem;
            color: #555;
            cursor: pointer;
            padding: 0;
            line-height: 1;
            transition: color 0.15s ease;
        }
        .review-star-btn.active,
        .review-star-btn:hover,
        .review-star-btn.hover-preview {
            color: var(--accent);
        }
        .review-form input[type="text"],
        .review-form textarea {
            width: 100%;
            background: #000;
            color: #fff;
            border: 1px solid rgba(0, 255, 157, 0.35);
            padding: 12px;
            font-family: 'Space Mono', monospace;
            font-size: 0.8rem;
            margin-bottom: 14px;
            box-sizing: border-box;
        }
        .review-form textarea { resize: vertical; min-height: 100px; }
        .review-submit-btn {
            background: var(--accent);
            color: #000;
            border: none;
            padding: 14px 24px;
            font-weight: bold;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-family: 'Space Mono', monospace;
            font-size: 0.75rem;
        }
        .review-submit-btn:hover { background: #fff; }
        .review-submit-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .review-form-msg {
            margin-top: 12px;
            font-size: 0.72rem;
            letter-spacing: 0.5px;
            display: none;
        }
        .review-form-msg.ok { color: var(--accent); display: block; }
        .review-form-msg.err { color: var(--glitch-red); display: block; }

        @media (max-width: 640px) {
            .reviews-drawer { margin-top: 14px; }
            .reviews-drawer-summary {
                min-height: 52px;
                padding: 0 12px;
            }
            .reviews-drawer-meta { font-size: 0.58rem; }
            .reviews-drawer-content {
                max-height: 62vh;
                padding: 14px 12px;
            }
            .review-card,
            .review-form { padding: 14px; }
            .review-submit-btn { width: 100%; min-height: 46px; }
        }

        .mobile-buy-bar {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 9997;
            display: none;
            border-top: 1px solid rgba(0, 255, 157, 0.35);
            background: rgba(0, 0, 0, 0.95);
            backdrop-filter: blur(8px);
            padding: 10px 12px calc(10px + env(safe-area-inset-bottom));
        }
        .mobile-buy-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
            gap: 10px;
            font-size: 0.7rem;
            letter-spacing: 1px;
        }
        .mobile-buy-price {
            color: var(--accent);
            font-weight: 700;
            font-size: 0.9rem;
        }
        .mobile-buy-controls {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 8px;
        }
        #sticky-size-dropdown {
            min-height: 44px;
            margin: 0;
        }
        #stickyAddToCartBtn {
            min-height: 44px;
            padding: 0 12px;
            font-size: 0.72rem;
        }
        @media (max-width: 900px) {
            .mobile-buy-bar { display: block; }
            .hero-block { padding-bottom: 220px; }
            #ui-toggle {
                bottom: calc(132px + env(safe-area-inset-bottom));
                padding: 10px 14px;
                font-size: 0.58rem;
                letter-spacing: 2px;
            }
            body.data-hidden #ui-toggle { bottom: calc(16px + env(safe-area-inset-bottom)); }
        }

        .gallery-shelf {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(70px, 1fr));
            gap: 12px;
            margin-top: 20px;
        }
        @media (max-width: 900px) {
            .gallery-shelf {
                display: flex;
                overflow-x: auto;
                overflow-y: hidden;
                gap: 10px;
                scroll-snap-type: x mandatory;
                scroll-padding-inline: 4px;
                overscroll-behavior-x: contain;
                -webkit-overflow-scrolling: touch;
                padding-bottom: 4px;
            }
            .gallery-shelf::-webkit-scrollbar { height: 5px; }
            .gallery-shelf::-webkit-scrollbar-thumb { background: rgba(0, 255, 157, 0.35); }
        }

        .shelf-item {
            width: 100%;
            height: 70px;
            border: 1px solid #222;
            object-fit: cover;
            cursor: pointer;
            filter: brightness(0.4);
            transition: 0.3s;
            background: #000;
            scroll-snap-align: center;
        }
        @media (max-width: 900px) {
            .shelf-item {
                width: 74px;
                min-width: 74px;
                height: 74px;
            }
        }

        .shelf-item.active {
            border-color: var(--accent);
            filter: brightness(1);
            box-shadow: 0 0 15px rgba(0, 255, 157, 0.3);
        }

        .product-description {
            line-height: 1.85;
            color: #c4c4c4;
            font-size: 0.92rem;
            margin: 26px 0;
            border-left: 1px solid var(--accent);
            padding-left: 20px;
            letter-spacing: 0.2px;
        }

        .lb { position: fixed; inset: 0; z-index: 10000; display: none; }
        .lb.on { display: block; }
        .lb-backdrop { position: absolute; inset: 0; background: rgba(0, 0, 0, 0.92); backdrop-filter: blur(10px); }
        .lb-panel {
            position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%);
            width: min(980px, 94vw); height: min(92vh, 820px);
            border: 1px solid rgba(0, 255, 157, 0.55); background: rgba(0, 0, 0, 0.55);
            box-shadow: 0 0 30px rgba(0, 255, 157, 0.12); display: flex; flex-direction: column;
        }
        .lb-top {
            display: flex; justify-content: space-between; align-items: center;
            padding: 12px 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.08); gap: 10px;
        }
        .lb-title {
            font-size: 0.65rem; letter-spacing: 3px; color: var(--accent);
            text-transform: uppercase; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; max-width: 60%;
        }
        @media (max-width: 900px) { .lb-title { max-width: 55%; } }
        @media (max-width: 420px) { .lb-title { display: none; } }

        .lb-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }
        .lb-btn {
            border: 1px solid rgba(0, 255, 157, 0.65); background: transparent; color: var(--accent);
            font-family: 'Space Mono'; font-size: 0.65rem; letter-spacing: 3px; padding: 10px 12px;
            cursor: pointer; text-transform: uppercase; white-space: nowrap;
        }
        .lb-btn:hover, .lb-btn:focus-visible { background: var(--accent); color: #000; outline: none; }
        .lb-btn.dim { border-color: rgba(255, 255, 255, 0.2); color: #fff; }
        .lb-btn.dim:hover { background: #fff; color: #000; }
        .lb-body { flex: 1; display: flex; align-items: center; justify-content: center; padding: 12px; overflow: hidden; position: relative; }
        #lb-media-host {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .lb-body img, .lb-body video {
            max-width: 100%; max-height: 100%; width: auto; height: auto; object-fit: contain;
            border: 1px solid rgba(255, 255, 255, 0.08); background: #000;
        }
        .lb-nav-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            z-index: 2;
            width: 44px;
            height: 44px;
            border: 1px solid rgba(0, 255, 157, 0.65);
            background: rgba(0, 0, 0, 0.55);
            color: var(--accent);
            cursor: pointer;
            font-family: 'Space Mono';
            font-size: 1rem;
            line-height: 1;
        }
        .lb-nav-btn:hover,
        .lb-nav-btn:focus-visible {
            background: var(--accent);
            color: #000;
            outline: none;
        }
        .lb-nav-btn.prev { left: 12px; }
        .lb-nav-btn.next { right: 12px; }
        .lb-nav-btn[disabled] {
            opacity: 0.35;
            cursor: not-allowed;
        }
        .lb-body img.zoomable { transform-origin: center center; cursor: grab; user-select: none; -webkit-user-drag: none; touch-action: none; }
        .lb-body img.zoomable:active { cursor: grabbing; }
        .lb-hint {
            padding: 10px 12px; border-top: 1px solid rgba(255, 255, 255, 0.08); color: #888; font-size: 0.65rem;
            letter-spacing: 2px; text-transform: uppercase; display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap;
        }

        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: 0; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }

        [style*="background: #ffff00"],
        [style*="background-color: yellow"],
        [style*="background: yellow"],
        [class*="debug"],
        [id*="debug"],
        [class*="Debug"],
        [id*="Debug"] { display: none !important; }

        @media (prefers-reduced-motion: reduce) {
            .glitch-title::before, .glitch-title::after { animation: none; display: none; }
        }
    </style>
</head>
<body class="tech-archive">
<?= $debug_trace ?>
<div id="sr-announcer" class="sr-only" aria-live="polite" aria-atomic="true"></div>

<header class="tactical-nav" style="position:fixed; width:100%; top:0; z-index:9998; transition: transform 0.6s ease;">
    <div class="top-bar" style="display:flex; justify-content:space-between; gap:16px; padding:14px 16px; background:rgba(0, 0, 0, 0.92); border-bottom:1px solid var(--border); align-items:center; flex-wrap:wrap;">
        <a href="/" class="logo" style="text-decoration:none; color:white; font-family:'Syncopate'; font-size:0.75rem; letter-spacing:2px;">DIAMONDS OUTTA DIRT</a>
        <nav aria-label="Primary">
            <div style="display:flex; gap:16px; flex-wrap:wrap; align-items:center; font-size:0.7rem; letter-spacing:2px;">
                <a href="/about" style="color:#fff; text-decoration:none;">ABOUT</a>
                <a href="/services" style="color:#fff; text-decoration:none;">SERVICES</a>
                <a href="/lookbook" style="color:#fff; text-decoration:none;">LOOKBOOK</a>
                <a href="/shop" style="color:#fff; text-decoration:none;">SHOP</a>
            </div>
        </nav>
        <a href="/cart" id="cart-link" style="color:var(--accent); text-decoration:none; font-size:0.75rem;">BAG [<span id="cart-count"><?= (int)$bag_qty ?></span>]</a>
    </div>
</header>

<button id="ui-toggle" type="button">[ HIDE_DATA ]</button>

<div class="dynamic-bg">
    <?php if ($bg_source && $bg_type !== 'none') : ?>
        <?php if ($bg_type === 'video') : ?>
            <?php $bgMime = guess_video_mime($bg_source); ?>
            <?php if ($bgMime !== '') : ?>
                <video id="bgVideo" autoplay loop muted playsinline class="fullscreen-media" preload="metadata">
                    <source src="<?= h($bg_source) ?>" type="<?= h($bgMime) ?>">
                </video>
            <?php endif; ?>
        <?php else : ?>
            <img src="<?= h($bg_source) ?>" class="fullscreen-media" alt="" loading="lazy">
        <?php endif; ?>
    <?php endif; ?>
</div>

<main class="hero-block">
    <div class="product-visual-slot">
        <div id="main-media-container" role="button" tabindex="0" aria-label="Open media fullscreen" data-type="image" data-src="<?= h($product_image) ?>">
            <img
                id="main-product-img"
                src="<?= h($product_image) ?>"
                class="main-asset"
                alt="<?= h($product_name) ?>"
                loading="eager"
                decoding="async"
            >
        </div>

        <?php if (!empty($gallery_items)) : ?>
        <div class="gallery-shelf" aria-label="Product gallery thumbnails">
            <?php foreach ($gallery_items as $index => $item) : ?>
                <?php if (($item['type'] ?? '') === 'video') : ?>
                    <?php $mime = guess_video_mime((string)$item['url']); ?>
                    <?php if ($mime !== '') : ?>
                        <video
                            class="shelf-item <?= $index === 0 ? 'active' : '' ?>"
                            data-type="video"
                            data-src="<?= h((string)$item['url']) ?>"
                            data-mime="<?= h($mime) ?>"
                            muted
                            playsinline
                            preload="metadata"
                            aria-label="Thumbnail - video"
                        >
                            <source src="<?= h((string)$item['url']) ?>" type="<?= h($mime) ?>">
                        </video>
                    <?php endif; ?>
                <?php else : ?>
                    <img
                        src="<?= h((string)$item['url']) ?>"
                        data-type="image"
                        data-src="<?= h((string)$item['url']) ?>"
                        class="shelf-item <?= $index === 0 ? 'active' : '' ?>"
                        alt="Thumbnail <?= (int)$index + 1 ?>"
                        loading="lazy"
                        decoding="async"
                    >
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="product-details">
        <div id="product-status" class="status-label">
            STATUS: <?= $is_sold_out ? 'SOLD_OUT' : 'ONLINE' ?> // <?= strtoupper(h($product_category)) ?>
        </div>

        <h1 class="glitch-title" data-text="<?= h(strtoupper($product_name)) ?>"><?= strtoupper(h($product_name)) ?></h1>

        <p class="product-price">
            $<?= number_format($product_price, 2) ?>
        </p>

        <?php if ($ratingValue !== null && $ratingCount > 0): ?>
        <a href="#reviews-section" class="rating-summary-link" data-review-drawer-trigger aria-label="<?= $ratingCount ?> reviews, average <?= number_format($ratingValue, 1) ?> out of 5 stars">
            <span class="rating-stars" aria-hidden="true"><?php
                $fullStars = (int)floor($ratingValue);
                $hasHalf = ($ratingValue - $fullStars) >= 0.5;
                for ($i = 0; $i < 5; $i++) {
                    if ($i < $fullStars) echo '★';
                    elseif ($i === $fullStars && $hasHalf) echo '⯨';
                    else echo '☆';
                }
            ?></span>
            <span class="rating-count"><?= number_format($ratingValue, 1) ?> (<?= $ratingCount ?> review<?= $ratingCount === 1 ? '' : 's' ?>)</span>
        </a>
        <?php endif; ?>

        <label class="sr-only" for="size-dropdown">Size</label>
        <label class="sr-only" for="qty-input">Quantity</label>

        <div class="qty-row">
            <select id="size-dropdown" class="dod-input" <?= $is_sold_out ? 'disabled' : '' ?>>
                <?php foreach ($sizes as $s) : ?>
                    <option value="<?= h((string)$s) ?>"><?= h((string)$s) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="qty-stepper" aria-label="Quantity selector">
                <button type="button" id="qty-minus" <?= $is_sold_out ? 'disabled' : '' ?>>-</button>
                <input
                    id="qty-input"
                    type="number"
                    inputmode="numeric"
                    min="1"
                    max="99"
                    value="1"
                    <?= $is_sold_out ? 'disabled' : '' ?>
                    aria-label="Quantity"
                >
                <button type="button" id="qty-plus" <?= $is_sold_out ? 'disabled' : '' ?>>+</button>
            </div>
        </div>

        <div style="display:flex;gap:12px;align-items:center;">
            <button
                id="addToCartBtn"
                class="btn-cta"
                data-id="<?= (int)$product['id'] ?>"
                data-name="<?= h($product_name) ?>"
                data-price="<?= h((string)$product_price) ?>"
                data-image="<?= h($product_image) ?>"
                <?= $is_sold_out ? 'disabled' : '' ?>
            >
                <?= $is_sold_out ? 'SOLD OUT' : 'ADD TO ARCHIVE' ?>
            </button>
            <button id="wishlist-btn"
                type="button"
                aria-label="<?= $is_wishlisted ? 'Remove from wishlist' : 'Add to wishlist' ?>"
                aria-pressed="<?= $is_wishlisted ? 'true' : 'false' ?>"
                class="<?= $is_wishlisted ? 'wl-active' : '' ?>"
                data-product-id="<?= (int)$product_id ?>"
                data-logged-in="<?= $is_logged_in ? '1' : '0' ?>">
                <span id="wishlist-heart"></span>
            </button>
        </div>
        <div class="inline-meta-panels" aria-label="Shipping and size information">
            <div class="inline-meta-panel">
                <span class="inline-meta-title">Shipping / Returns</span>
                U.S. delivery in 3-7 business days. Returns accepted within 14 days if unworn with original packaging.
            </div>
            <div class="inline-meta-panel">
                <span class="inline-meta-title">Size Guide</span>
                True-to-size fit. For oversized styling, go one size up. Measurements available on request via support.
            </div>
        </div>

        <?php if (!empty($taggedModels)) : ?>
        <section class="tagged-models" aria-label="Tagged models">
            <h2 class="tagged-models-title">Tagged Models</h2>
            <div class="tagged-models-grid">
                <?php foreach ($taggedModels as $model) : ?>
                    <article class="model-tag-card">
                        <div class="model-tag-head">
                            <?php if (!empty($model['image_url'])) : ?>
                                <img class="model-tag-avatar"
                                     src="<?= h((string)$model['image_url']) ?>"
                                     alt="<?= h((string)$model['name']) ?>"
                                     loading="lazy"
                                     decoding="async">
                            <?php else : ?>
                                <span class="model-tag-avatar" aria-hidden="true"></span>
                            <?php endif; ?>
                            <div class="model-tag-name"><?= h((string)$model['name']) ?></div>
                        </div>
                        <div class="model-tag-links">
                            <?php if (!empty($model['instagram_url'])) : ?>
                                <a class="model-social-link"
                                   href="<?= h((string)$model['instagram_url']) ?>"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   data-platform="instagram"
                                   data-model-name="<?= h((string)$model['name']) ?>">
                                    <span class="model-social-icon" aria-hidden="true">IG</span>
                                    Instagram
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($model['tiktok_url'])) : ?>
                                <a class="model-social-link"
                                   href="<?= h((string)$model['tiktok_url']) ?>"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   data-platform="tiktok"
                                   data-model-name="<?= h((string)$model['name']) ?>">
                                    <span class="model-social-icon" aria-hidden="true">TT</span>
                                    TikTok
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($model['website_url'])) : ?>
                                <a class="model-social-link"
                                   href="<?= h((string)$model['website_url']) ?>"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   data-platform="website"
                                   data-model-name="<?= h((string)$model['name']) ?>">
                                    <span class="model-social-icon" aria-hidden="true">WB</span>
                                    Website
                                </a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php
        $desc = (string)($product['description'] ?? '');
        if (trim($desc) === '') {
            $fallbackMeta = (string)($product['meta_description'] ?? '');
            if (trim($fallbackMeta) !== '') {
                $desc = $fallbackMeta;
            }
        }
        ?>
        <p class="product-description">
            <?php if (trim($desc) === ''): ?>
                <span style="color:#ff3e3e; font-weight:bold;">[ No product description available. ]</span>
                <!-- DESCRIPTION_MISSING -->
            <?php else: ?>
                <?= nl2br(h($desc)) ?>
            <?php endif; ?>
        </p>

        <details class="reviews-drawer" id="reviews-section">
            <summary class="reviews-drawer-summary" aria-controls="reviews-drawer-content">
                <span class="reviews-drawer-label">Reviews</span>
                <span class="reviews-drawer-meta">
                    <?= count($productReviews) ?> approved
                </span>
                <span class="reviews-drawer-chevron" aria-hidden="true">⌄</span>
            </summary>
            <div class="reviews-drawer-content" id="reviews-drawer-content">
                <section class="reviews-section" aria-labelledby="reviews-title">
                    <h2 class="reviews-title" id="reviews-title">
                        Reviews <?= !empty($productReviews) ? '(' . count($productReviews) . ')' : '' ?>
                    </h2>

                    <?php if (empty($productReviews)): ?>
                        <p class="no-reviews-note">No reviews yet. Be the first to leave one.</p>
                    <?php else: ?>
                        <?php foreach ($productReviews as $rev):
                            $revRating = max(1, min(5, (int)($rev['rating'] ?? 5)));
                            $revStars = str_repeat('★', $revRating) . str_repeat('☆', 5 - $revRating);
                            $revDate = '';
                            try { $revDate = (new DateTime((string)$rev['created_at']))->format('M j, Y'); } catch (Throwable $e) {}
                        ?>
                        <div class="review-card">
                            <div class="review-card-head">
                                <div>
                                    <span class="review-stars" aria-hidden="true"><?= h($revStars) ?></span>
                                    <span class="review-author"><?= h((string)$rev['user_name']) ?></span>
                                </div>
                                <span class="review-date"><?= h($revDate) ?></span>
                            </div>
                            <p class="review-text"><?= nl2br(h((string)$rev['review'])) ?></p>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="review-form">
                        <div class="review-form-title">Write a Review</div>
                        <form id="reviewForm">
                            <input type="hidden" id="reviewProductId" value="<?= (int)$product_id ?>">
                            <div class="review-star-picker" id="reviewStarPicker" role="radiogroup" aria-label="Rating">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <button type="button" class="review-star-btn" data-star="<?= $i ?>" aria-label="<?= $i ?> star<?= $i === 1 ? '' : 's' ?>">★</button>
                                <?php endfor; ?>
                            </div>
                            <input type="hidden" id="reviewRatingInput" value="0">
                            <input type="text" id="reviewName" placeholder="Your name" maxlength="80" required>
                            <textarea id="reviewText" placeholder="Share your thoughts on this product..." maxlength="2000" required></textarea>
                            <button type="submit" class="review-submit-btn" id="reviewSubmitBtn">SUBMIT REVIEW</button>
                            <div class="review-form-msg" id="reviewFormMsg"></div>
                        </form>
                    </div>
                </section>
            </div>
        </details>

        <?php if ($audio_url !== '') : ?>
            <div style="border:1px solid var(--accent); padding:22px; display:flex; flex-direction:column; align-items:center; gap:14px; background:rgba(0, 0, 0, 0.4);">
                <?php if ($is_soundcloud) : ?>
                    <iframe
                        id="sc-widget"
                        src="https://w.soundcloud.com/player/?url=<?= urlencode($audio_url) ?>&auto_play=false&color=00ff9d"
                        width="1"
                        height="1"
                        style="position:absolute; left:-9999px;"
                        allow="autoplay"
                    ></iframe>
                <?php else : ?>
                    <audio id="internal-audio" src="<?= h($audio_url) ?>" preload="metadata"></audio>
                <?php endif; ?>

                <button id="commBtn" type="button" class="dod-input" style="color:var(--accent); cursor:pointer; margin-bottom:0; border-style:dashed;">
                    [ ACTIVATE_SIGNAL ]
                </button>

                <div style="display:flex; align-items:center; gap:14px;">
                    <span style="font-size:0.5rem; letter-spacing:2px;">VOL:</span>
                    <input type="range" id="scVolumeSlider" min="0" max="100" value="70" style="width:120px; -webkit-appearance:none; background:#333; height:2px;">
                    <button id="muteBtn" type="button" style="width:35px; height:35px; background:transparent; color:var(--accent); border:1px solid var(--accent); cursor:pointer;">
                        M
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php require __DIR__ . '/related_products.php'; ?>

<section class="related-section" id="visually-similar-section" style="display:none;" aria-labelledby="visually-similar-title">
    <h2 class="related-title" id="visually-similar-title">Visually Similar</h2>
    <div class="related-grid" id="visually-similar-grid"></div>
</section>
<script>
(function () {
    var sourceProductId = <?= (int)$product_id ?>;

    fetch('/similar_products.php?product_id=' + encodeURIComponent(sourceProductId) + '&limit=6')
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.ok || !data.has_embedding || !Array.isArray(data.items) || data.items.length === 0) {
                return;
            }

            var section = document.getElementById('visually-similar-section');
            var grid = document.getElementById('visually-similar-grid');

            data.items.forEach(function (item) {
                var image = String(item.image_url || '');
                if (image !== '' && !/^https?:\/\//i.test(image)) {
                    image = '/' + image.replace(/^\/+/, '');
                }

                var card = document.createElement('a');
                card.className = 'related-card';
                card.href = '/product/' + encodeURIComponent(item.product_id);
                card.setAttribute('aria-label', 'View ' + (item.name || 'item'));

                var media = document.createElement('div');
                media.className = 'related-card-media';
                var img = document.createElement('img');
                img.className = 'related-thumb';
                img.loading = 'lazy';
                img.decoding = 'async';
                img.src = image;
                img.alt = String(item.name || '');
                media.appendChild(img);

                var info = document.createElement('div');
                info.className = 'related-info';
                var name = document.createElement('p');
                name.className = 'related-name';
                name.textContent = String(item.name || '');
                var price = document.createElement('div');
                price.className = 'related-price';
                price.textContent = '$' + Number(item.price || 0).toFixed(2);
                info.appendChild(name);
                info.appendChild(price);

                card.appendChild(media);
                card.appendChild(info);

                card.addEventListener('click', function () {
                    try {
                        fetch('/recommendation_track_click.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            keepalive: true,
                            body: JSON.stringify({
                                source_product_id: sourceProductId,
                                clicked_product_id: item.product_id
                            })
                        });
                    } catch (e) { /* tracking is best-effort, never block navigation */ }
                });

                grid.appendChild(card);
            });

            section.style.display = '';
        })
        .catch(function () { /* no embedding data yet or lookup failed -- fail silently */ });
})();
</script>

<div class="mobile-buy-bar" aria-label="Sticky purchase controls">
    <div class="mobile-buy-top">
        <span><?= h(strtoupper($product_name)) ?></span>
        <span class="mobile-buy-price">$<?= number_format($product_price, 2) ?></span>
    </div>
    <div class="mobile-buy-controls">
        <select id="sticky-size-dropdown" class="dod-input" <?= $is_sold_out ? 'disabled' : '' ?>>
            <?php foreach ($sizes as $s) : ?>
                <option value="<?= h((string)$s) ?>"><?= h((string)$s) ?></option>
            <?php endforeach; ?>
        </select>
        <button id="stickyAddToCartBtn" class="btn-cta" type="button" <?= $is_sold_out ? 'disabled' : '' ?>>
            <?= $is_sold_out ? 'SOLD OUT' : 'ADD TO ARCHIVE' ?>
        </button>
    </div>
</div>

<div class="lb" id="lightbox" aria-hidden="true" role="dialog" aria-modal="true" aria-label="Fullscreen media viewer">
    <div class="lb-backdrop" id="lb-backdrop"></div>
    <div class="lb-panel">
        <div class="lb-top">
            <div class="lb-title" id="lb-title">FULLSCREEN_VIEW</div>
            <div class="lb-actions">
                <button class="lb-btn dim" type="button" id="lb-prev">[ PREV ]</button>
                <button class="lb-btn dim" type="button" id="lb-next">[ NEXT ]</button>
                <button class="lb-btn dim" type="button" id="lb-zoom-out">[ - ]</button>
                <button class="lb-btn dim" type="button" id="lb-zoom-in">[ + ]</button>
                <button class="lb-btn dim" type="button" id="lb-zoom-reset">[ RESET ]</button>
                <button class="lb-btn" type="button" id="lb-close">[ CLOSE ]</button>
            </div>
        </div>
        <div class="lb-body" id="lb-body">
            <div id="lb-media-host"></div>
            <button class="lb-nav-btn prev" id="lb-prev-side" type="button" aria-label="Previous media">‹</button>
            <button class="lb-nav-btn next" id="lb-next-side" type="button" aria-label="Next media">›</button>
        </div>
        <div class="lb-hint">
            <span>ESC / CLICK_OUTSIDE = CLOSE</span>
            <span>ZOOM: <span id="lb-zoom-meta">1.00×</span> // MEDIA: <span id="lb-meta">—</span></span>
        </div>
    </div>
</div>

<script nonce="<?= h($csp_nonce) ?>">
window.DOD = (function() {
    'use strict';

    const ANALYTICS_ENDPOINT = '';
    const CART_ENDPOINT = <?= json_encode($cart_endpoint) ?>;
    const CSRF_TOKEN = <?= json_encode($csrf_token) ?>;
    const ecommerceItem = {
        item_id: <?= json_encode((string)$product_id) ?>,
        item_name: <?= json_encode($product_name) ?>,
        item_category: <?= json_encode($product_category) ?>,
        price: <?= json_encode((float)$product_price) ?>,
        currency: 'USD',
    };

    const state = {
        quantity: 1,
        isPlaying: false,
        isMuted: false,
        lastVolume: 70,
        audioPrimed: false,
        lightboxOpen: false,
        bgVideo: null,
        addToCartLock: false,
        wishlistLock: false,
        widget: null,
        internalAudio: null,
        sentViewItem: false,
    };

    const getEl = (id) => document.getElementById(id);
    const clamp = (n, min, max) => Math.max(min, Math.min(max, n));
    const postAnalytics = (eventPayload) => {
        if (!ANALYTICS_ENDPOINT) return;
        const body = JSON.stringify(eventPayload);
        if (navigator.sendBeacon) {
            const blob = new Blob([body], { type: 'application/json' });
            navigator.sendBeacon(ANALYTICS_ENDPOINT, blob);
            return;
        }
        fetch(ANALYTICS_ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body,
            keepalive: true,
            credentials: 'same-origin',
        }).catch(() => {});
    };
    const trackEcomEvent = (eventName, ecommerce) => {
        if (!eventName || !ecommerce) return;
        const payload = { event: eventName, ecommerce, page: 'product' };

        if (Array.isArray(window.dataLayer)) {
            window.dataLayer.push({ ecommerce: null });
            window.dataLayer.push(payload);
        }

        if (typeof window.gtag === 'function') {
            window.gtag('event', eventName, ecommerce);
        }

        // Mirror the same event to Meta Pixel using its event naming
        // (GA4 and Meta use different names for equivalent events)
        if (typeof window.fbq === 'function') {
            const metaEventMap = {
                view_item: 'ViewContent',
                add_to_cart: 'AddToCart',
                begin_checkout: 'InitiateCheckout',
                purchase: 'Purchase',
            };
            const metaEventName = metaEventMap[eventName];
            if (metaEventName) {
                const items = Array.isArray(ecommerce.items) ? ecommerce.items : [];
                window.fbq('track', metaEventName, {
                    content_ids: items.map((it) => String(it.item_id || '')),
                    content_type: 'product',
                    value: Number(ecommerce.value) || 0,
                    currency: ecommerce.currency || 'USD',
                });
            }
        }

        window.dispatchEvent(new CustomEvent('dod:analytics', { detail: payload }));
        postAnalytics(payload);
    };
    const trackEvent = (eventName, payload) => {
        if (!eventName) return;
        const eventPayload = Object.assign({
            event: eventName,
            page: 'product',
            ts: Date.now(),
        }, payload || {});

        if (Array.isArray(window.dataLayer)) {
            window.dataLayer.push(eventPayload);
        }

        if (typeof window.gtag === 'function') {
            const gtagPayload = Object.assign({}, eventPayload);
            delete gtagPayload.event;
            window.gtag('event', eventName, gtagPayload);
        }

        window.dispatchEvent(new CustomEvent('dod:analytics', { detail: eventPayload }));
        postAnalytics(eventPayload);
    };
    const announce = (message) => {
        const announcer = getEl('sr-announcer');
        if (announcer) {
            announcer.textContent = message;
            setTimeout(() => { announcer.textContent = ''; }, 2000);
        }
    };

    const toggleHUD = () => {
        const body = document.body;
        const btn = getEl('ui-toggle');
        const nav = document.querySelector('.tactical-nav');
        const statusLabel = getEl('product-status');

        if (!body || !btn) return;

        try {
            const lb = getEl('lightbox');
            if (lb && lb.classList.contains('on')) {
                lb.classList.remove('on');
                lb.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('lightbox-open');
                state.lightboxOpen = false;
            }
        } catch (_) {}

        const goingHidden = !body.classList.contains('data-hidden');

        if (goingHidden) {
            body.classList.add('data-hidden', 'bg-view-active');
            btn.textContent = '[ SHOW_DATA ]';
            if (nav) nav.style.transform = 'translateY(-100%)';
            if (typeof window.dodTrack === 'function') window.dodTrack('bg_preview_toggle', { id: ecommerceItem.item_id });
        } else {
            body.classList.remove('data-hidden', 'bg-view-active');
            btn.textContent = '[ HIDE_DATA ]';
            if (nav) nav.style.transform = 'translateY(0)';
        }

        if (statusLabel) {
            statusLabel.style.animation = 'none';
            void statusLabel.offsetWidth;
            statusLabel.style.animation = '';
        }
    };

    const switchMedia = (el) => {
        if (!el) return;

        const type = el.getAttribute('data-type') || (el.tagName === 'VIDEO' ? 'video' : 'image');
        const src = el.getAttribute('data-src') || el.src || '';
        const mime = el.getAttribute('data-mime') || '';

        if (!src) return;

        const container = getEl('main-media-container');
        if (!container) return;

        container.setAttribute('data-type', type);
        container.setAttribute('data-src', src);

        if (type === 'video') {
            container.innerHTML = `
                <video autoplay loop muted playsinline class="main-asset" aria-label="Main video">
                    <source src="${src}" type="${mime || 'video/mp4'}">
                </video>
            `;
        } else {
            container.innerHTML = `
                <img src="${src}" class="main-asset" alt="Selected media" loading="lazy" decoding="async">
            `;
        }

        document.querySelectorAll('.shelf-item').forEach((item) => item.classList.remove('active'));
        el.classList.add('active');
        if (typeof el.scrollIntoView === 'function') {
            el.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        }
    };

    const switchToAdjacentShelfItem = (direction) => {
        const shelfItems = Array.from(document.querySelectorAll('.gallery-shelf .shelf-item'));
        if (shelfItems.length < 2) return;

        let activeIndex = shelfItems.findIndex((item) => item.classList.contains('active'));
        if (activeIndex < 0) activeIndex = 0;

        const nextIndex = (activeIndex + direction + shelfItems.length) % shelfItems.length;
        const nextEl = shelfItems[nextIndex];
        if (nextEl) switchMedia(nextEl);
    };

    const getQuantity = () => {
        const input = getEl('qty-input');
        if (!input) return 1;
        const value = parseInt(input.value, 10);
        return clamp(isNaN(value) ? 1 : value, 1, 99);
    };

    const setQuantity = (newQty) => {
        const input = getEl('qty-input');
        if (!input) return;
        const clamped = clamp(parseInt(newQty, 10) || 1, 1, 99);
        input.value = clamped;
        state.quantity = clamped;
    };

    const addToCart = (sourceBtn = null, sourceSizeEl = null) => {
        if (state.addToCartLock) return;
        state.addToCartLock = true;
        setTimeout(() => { state.addToCartLock = false; }, 500);

        const btn = sourceBtn || getEl('addToCartBtn');
        const primaryBtn = getEl('addToCartBtn') || btn;
        const sizeEl = sourceSizeEl || getEl('size-dropdown');
        if (!btn || btn.disabled || !primaryBtn || primaryBtn.disabled) { state.addToCartLock = false; return; }

        const size = sizeEl ? sizeEl.value : 'OS';
        const qty = getQuantity();

        const form = new FormData();
        form.set('action', 'add_to_cart');
        form.set('csrf', CSRF_TOKEN);
        form.set('product_id', primaryBtn.dataset.id);
        form.set('name', primaryBtn.dataset.name);
        form.set('price', primaryBtn.dataset.price);
        form.set('image', primaryBtn.dataset.image);
        form.set('size', size);
        form.set('quantity', qty.toString());

        fetch(`${CART_ENDPOINT}?ajax=1`, {
            method: 'POST',
            body: form,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then((response) => response.json())
        .then((data) => {
            if (data && data.ok) {
                const stickyBtn = getEl('stickyAddToCartBtn');
                const originalTextMain = primaryBtn.textContent;
                const originalTextSticky = stickyBtn ? stickyBtn.textContent : '';
                const addedLabel = `ADDED x${qty} [${size}]`;
                primaryBtn.textContent = addedLabel;
                if (stickyBtn) stickyBtn.textContent = addedLabel;
                announce(`Added ${qty} × ${primaryBtn.dataset.name} (${size}) to cart`);

                if (data.total_qty) {
                    const cartCount = document.getElementById('cart-count');
                    if (cartCount) cartCount.textContent = data.total_qty;

                    window.dispatchEvent(new CustomEvent('cartUpdated', {
                        detail: { count: data.total_qty }
                    }));
                }

                trackEcomEvent('add_to_cart', {
                    currency: 'USD',
                    value: Number((Number(primaryBtn.dataset.price || 0) * qty).toFixed(2)),
                    items: [Object.assign({}, ecommerceItem, {
                        item_variant: size,
                        quantity: qty,
                    })],
                });

                if (typeof window.dodTrack === 'function') {
                    window.dodTrack('add_to_cart_click', {
                        id: primaryBtn.dataset.id,
                        name: primaryBtn.dataset.name,
                        category: ecommerceItem.item_category,
                        size: size,
                        quantity: qty,
                        value: Number((Number(primaryBtn.dataset.price || 0) * qty).toFixed(2)),
                    });
                }

                setTimeout(() => {
                    primaryBtn.textContent = originalTextMain;
                    if (stickyBtn) stickyBtn.textContent = originalTextSticky;
                }, 1500);
            } else {
                announce('Unable to add to bag. Try again.');
                console.error('Cart add failed:', data);
            }
        })
        .catch((error) => {
            console.error('Cart request failed:', error);
            announce('Unable to add to bag. Network error.');
        });
    };

    const setupWishlist = () => {
        const btn = getEl('wishlist-btn');
        if (!btn) return;

        btn.addEventListener('click', () => {
            if (state.wishlistLock) return;

            const isLoggedIn = btn.getAttribute('data-logged-in') === '1';
            if (!isLoggedIn) {
                const next = encodeURIComponent(window.location.pathname);
                window.location.href = '/account/login?next=' + next;
                return;
            }

            state.wishlistLock = true;
            btn.classList.add('wl-loading');

            const form = new FormData();
            form.set('csrf', CSRF_TOKEN);
            form.set('product_id', btn.getAttribute('data-product-id') || '');

            fetch('/api/wishlist_toggle.php?ajax=1', {
                method: 'POST',
                body: form,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then((r) => r.json())
            .then((data) => {
                btn.classList.remove('wl-loading');
                state.wishlistLock = false;

                if (data && data.ok) {
                    btn.classList.toggle('wl-active', !!data.added);
                    btn.setAttribute('aria-pressed', data.added ? 'true' : 'false');
                    btn.setAttribute('aria-label', data.added ? 'Remove from wishlist' : 'Add to wishlist');
                    announce(data.added ? 'Added to wishlist' : 'Removed from wishlist');

                    if (typeof window.dodTrack === 'function' && data.added) {
                        window.dodTrack('wishlist_add', {
                            id: ecommerceItem.item_id,
                            name: ecommerceItem.item_name,
                        });
                    }
                } else if (data && data.error === 'login_required') {
                    const next = encodeURIComponent(window.location.pathname);
                    window.location.href = '/account/login?next=' + next;
                } else {
                    announce('Unable to update wishlist. Try again.');
                }
            })
            .catch(() => {
                btn.classList.remove('wl-loading');
                state.wishlistLock = false;
                announce('Unable to update wishlist. Network error.');
            });
        });
    };

    const lightbox = {
        scale: 1, x: 0, y: 0, dragging: false, startX: 0, startY: 0,
        items: [], activeIndex: -1,

        setItems: function(items) {
            this.items = Array.isArray(items) ? items : [];
        },

        normalizeIndex: function(index) {
            const len = this.items.length;
            if (len <= 0) return -1;
            const i = Number(index);
            if (!Number.isFinite(i)) return 0;
            return ((Math.trunc(i) % len) + len) % len;
        },

        syncMainSelection: function(index) {
            if (index < 0 || index >= this.items.length) return;
            const item = this.items[index];
            if (item && item.el) switchMedia(item.el);
        },

        updateTitle: function() {
            const title = getEl('lb-title');
            if (!title) return;
            if (this.items.length > 1 && this.activeIndex >= 0) {
                title.textContent = `FULLSCREEN_VIEW ${this.activeIndex + 1}/${this.items.length}`;
                return;
            }
            title.textContent = 'FULLSCREEN_VIEW';
        },

        updateNavUI: function() {
            const hasMany = this.items.length > 1;
            ['lb-prev', 'lb-next', 'lb-prev-side', 'lb-next-side'].forEach((id) => {
                const el = getEl(id);
                if (!el) return;
                el.style.display = hasMany ? '' : 'none';
                el.disabled = !hasMany;
            });
            this.updateTitle();
        },

        setZoomUIVisible: function(visible) {
            ['lb-zoom-in', 'lb-zoom-out', 'lb-zoom-reset'].forEach((id) => {
                const el = getEl(id);
                if (el) el.style.display = visible ? '' : 'none';
            });
            const meta = getEl('lb-zoom-meta');
            if (meta && meta.parentElement) meta.parentElement.style.display = visible ? '' : 'none';
        },

        renderCurrent: function() {
            const host = getEl('lb-media-host');
            const lb = getEl('lightbox');
            const meta = getEl('lb-meta');
            if (!host || !lb) return;
            const item = this.items[this.activeIndex];
            if (!item) return;

            host.innerHTML = '';
            lb.setAttribute('data-type', item.type);
            lb.setAttribute('data-src', item.src);

            if (item.type === 'video') {
                const m = item.mime || (item.src.includes('.webm') ? 'video/webm' : 'video/mp4');
                host.innerHTML = `
                    <video controls autoplay playsinline style="max-width:100%;max-height:100%;">
                        <source src="${item.src}" type="${m}">
                    </video>
                `;
                if (meta) meta.textContent = m;
                this.setZoomUIVisible(false);
                this.scale = 1;
                this.x = 0;
                this.y = 0;
            } else {
                host.innerHTML = `<img id="lb-img" class="zoomable" src="${item.src}" alt="Fullscreen image" draggable="false">`;
                if (meta) meta.textContent = 'image';
                this.setZoomUIVisible(true);
                this.reset();
            }

            this.syncMainSelection(this.activeIndex);
            this.updateNavUI();
            this.updateZoomMeta();
        },

        open: function(type, src, mime) {
            if (!src) return;

            const lb = getEl('lightbox');
            const bgVideo = getEl('bgVideo');
            if (!lb) return;

            if (bgVideo) { bgVideo.pause(); state.bgVideo = bgVideo; }

            if (this.items.length > 0) {
                const found = this.items.findIndex((item) => item.type === type && item.src === src);
                this.activeIndex = this.normalizeIndex(found >= 0 ? found : 0);
                this.renderCurrent();
            } else {
                const host = getEl('lb-media-host');
                const meta = getEl('lb-meta');
                if (!host) return;
                host.innerHTML = '';
                lb.setAttribute('data-type', type);
                lb.setAttribute('data-src', src);
                if (type === 'video') {
                    const m = mime || (src.includes('.webm') ? 'video/webm' : 'video/mp4');
                    host.innerHTML = `<video controls autoplay playsinline style="max-width:100%;max-height:100%;"><source src="${src}" type="${m}"></video>`;
                    if (meta) meta.textContent = m;
                    this.setZoomUIVisible(false);
                } else {
                    host.innerHTML = `<img id="lb-img" class="zoomable" src="${src}" alt="Fullscreen image" draggable="false">`;
                    if (meta) meta.textContent = 'image';
                    this.setZoomUIVisible(true);
                    this.reset();
                }
                this.updateNavUI();
            }

            lb.classList.add('on');
            lb.setAttribute('aria-hidden', 'false');
            document.body.classList.add('lightbox-open');
            state.lightboxOpen = true;

            if (typeof window.dodTrack === 'function') {
                window.dodTrack('gallery_view', { id: ecommerceItem.item_id, media_type: type });
            }
        },

        openAt: function(index) {
            if (this.items.length <= 0) return;
            this.activeIndex = this.normalizeIndex(index);
            this.renderCurrent();
            const lb = getEl('lightbox');
            if (!lb.classList.contains('on')) {
                this.open(this.items[this.activeIndex].type, this.items[this.activeIndex].src, this.items[this.activeIndex].mime);
            }
        },

        navigate: function(delta) {
            if (this.items.length <= 1) return;
            this.openAt(this.activeIndex + delta);
        },

        close: function() {
            const lb = getEl('lightbox');
            const host = getEl('lb-media-host');
            if (!lb) return;

            lb.classList.remove('on');
            lb.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('lightbox-open');
            state.lightboxOpen = false;

            if (state.bgVideo) { state.bgVideo.play().catch(() => {}); state.bgVideo = null; }
            if (host) host.innerHTML = '';
            this.reset();
        },

        reset: function() { this.scale = 1; this.x = 0; this.y = 0; this.applyTransform(); },
        applyTransform: function() {
            const img = getEl('lb-img'); if (!img) return;
            img.style.transform = `translate(${this.x}px, ${this.y}px) scale(${this.scale})`;
            this.updateZoomMeta();
        },
        updateZoomMeta: function() {
            const z = getEl('lb-zoom-meta');
            if (z) z.textContent = `${this.scale.toFixed(2)}×`;
        },
        zoom: function(dir) { const step = 0.25; this.zoomTo(this.scale + (dir * step)); },
        zoomTo: function(nextScale, centerX, centerY) {
            const img = getEl('lb-img'); const body = getEl('lb-body'); if (!img || !body) return;
            const prev = this.scale; this.scale = Math.max(1, Math.min(6, nextScale));
            if (typeof centerX === 'number' && typeof centerY === 'number') {
                const rect = body.getBoundingClientRect();
                const cx = centerX - (rect.left + rect.width / 2);
                const cy = centerY - (rect.top + rect.height / 2);
                const ratio = this.scale / prev;
                this.x = (this.x - cx) * ratio + cx;
                this.y = (this.y - cy) * ratio + cy;
            }
            this.applyTransform();
        },
        startDrag: function(e) {
            const img = getEl('lb-img'); const lb = getEl('lightbox');
            if (!img || !lb || this.scale <= 1.001) return;
            if ((lb.getAttribute('data-type') || '') !== 'image') return;
            this.dragging = true; this.startX = e.clientX - this.x; this.startY = e.clientY - this.y;
            if (img.setPointerCapture) img.setPointerCapture(e.pointerId);
        },
        drag: function(e) { if (!this.dragging) return; this.x = e.clientX - this.startX; this.y = e.clientY - this.startY; this.applyTransform(); },
        endDrag: function() { this.dragging = false; },
    };

    const audio = {
        init: function() {
            const scWidgetIframe = getEl('sc-widget');
            const commBtn = getEl('commBtn');
            const volSlider = getEl('scVolumeSlider');
            const muteBtn = getEl('muteBtn');

            if (!commBtn) return;

            state.internalAudio = getEl('internal-audio');

            if (scWidgetIframe && window.SC && window.SC.Widget) {
                try {
                    state.widget = window.SC.Widget(scWidgetIframe);
                    state.widget.bind(window.SC.Widget.Events.READY, () => {
                        this.setVolume(state.lastVolume);
                    });
                } catch (e) {
                    console.error('SoundCloud widget error:', e);
                    state.widget = null;
                }
            }

            if (volSlider) {
                volSlider.addEventListener('input', (e) => {
                    const value = parseInt(e.target.value, 10);
                    state.lastVolume = value;
                    if (!state.isMuted) this.setVolume(value);
                });
                this.setVolume(state.lastVolume);
            }

            commBtn.addEventListener('click', () => this.toggle());
            if (muteBtn) muteBtn.addEventListener('click', () => this.toggleMute());
            this.prime();
        },

        prime: function() {
            if (state.audioPrimed) return;
            const volSlider = getEl('scVolumeSlider');
            if (volSlider) state.lastVolume = parseInt(volSlider.value, 10) || 70;
            state.audioPrimed = true;

            if (state.widget) {
                state.widget.setVolume(0); state.widget.play();
                setTimeout(() => { state.widget.pause(); this.setVolume(state.lastVolume); }, 220);
            } else if (state.internalAudio) {
                state.internalAudio.volume = 0;
                state.internalAudio.play().then(() => {
                    state.internalAudio.pause();
                    this.setVolume(state.lastVolume);
                }).catch(() => {});
            }
        },

        setVolume: function(value) {
            const v = Math.max(0, Math.min(100, Number(value)));
            const vol = v / 100;
            const volSlider = getEl('scVolumeSlider');
            if (volSlider && !state.isMuted) volSlider.value = v;
            if (state.widget) state.widget.setVolume(v);
            if (state.internalAudio) state.internalAudio.volume = vol;
        },

        toggle: function() {
            const commBtn = getEl('commBtn'); if (!commBtn) return;
            this.prime();

            if (!state.isPlaying) {
                if (state.widget) state.widget.play();
                else if (state.internalAudio) state.internalAudio.play().catch(() => {
                    commBtn.textContent = '[ CLICK TO PLAY ]';
                    commBtn.style.color = 'var(--glitch-red)';
                });

                commBtn.textContent = '[[ TRANSMITTING... ]]';
                commBtn.style.color = 'var(--glitch-red)';
                state.isPlaying = true;

                if (typeof window.dodTrack === 'function') {
                    window.dodTrack('audio_enable', { id: ecommerceItem.item_id });
                }
            } else {
                if (state.widget) state.widget.pause();
                else if (state.internalAudio) state.internalAudio.pause();

                commBtn.textContent = '[ ACTIVATE_SIGNAL ]';
                commBtn.style.color = 'var(--accent)';
                state.isPlaying = false;
            }
        },

        toggleMute: function() {
            const muteBtn = getEl('muteBtn');
            const volSlider = getEl('scVolumeSlider');
            state.isMuted = !state.isMuted;

            if (state.isMuted) {
                if (volSlider) state.lastVolume = parseInt(volSlider.value, 10) || 70;
                this.setVolume(0);
                if (muteBtn) { muteBtn.textContent = 'U'; muteBtn.style.color = 'var(--glitch-red)'; muteBtn.style.borderColor = 'var(--glitch-red)'; }
                if (volSlider) volSlider.style.opacity = '0.5';
            } else {
                this.setVolume(state.lastVolume);
                if (muteBtn) { muteBtn.textContent = 'M'; muteBtn.style.color = 'var(--accent)'; muteBtn.style.borderColor = 'var(--accent)'; }
                if (volSlider) volSlider.style.opacity = '1';
            }
        },
    };

    const init = () => {
        const uiToggle = getEl('ui-toggle'); if (uiToggle) uiToggle.addEventListener('click', toggleHUD);

        const reviewDrawer = getEl('reviews-section');
        document.querySelectorAll('[data-review-drawer-trigger]').forEach((trigger) => {
            trigger.addEventListener('click', (event) => {
                event.preventDefault();
                if (reviewDrawer && reviewDrawer.tagName === 'DETAILS') {
                    reviewDrawer.open = true;
                    window.requestAnimationFrame(() => {
                        reviewDrawer.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    });
                }
            });
        });

        const mainMedia = getEl('main-media-container');
        if (mainMedia) {
            const openFromMain = () => {
                if (document.body.classList.contains('data-hidden')) return;

                const type = mainMedia.getAttribute('data-type') || 'image';
                const src = mainMedia.getAttribute('data-src') || '';
                const mime = mainMedia.getAttribute('data-mime') || '';
                lightbox.open(type, src, mime);
            };
            mainMedia.addEventListener('click', openFromMain);
            mainMedia.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openFromMain(); }
            });
        }

        const shelfItems = Array.from(document.querySelectorAll('.gallery-shelf .shelf-item'));
        const galleryShelf = document.querySelector('.gallery-shelf');
        if (shelfItems.length) {
            lightbox.setItems(shelfItems.map((el) => {
                const type = el.getAttribute('data-type') || (el.tagName === 'VIDEO' ? 'video' : 'image');
                const src = el.getAttribute('data-src') || el.src || '';
                const mime = type === 'video' ? (el.getAttribute('data-mime') || '') : '';
                return { el, type, src, mime };
            }).filter((item) => item.src !== ''));
        } else if (mainMedia) {
            lightbox.setItems([{
                el: null,
                type: mainMedia.getAttribute('data-type') || 'image',
                src: mainMedia.getAttribute('data-src') || '',
                mime: mainMedia.getAttribute('data-mime') || '',
            }].filter((item) => item.src !== ''));
        }
        lightbox.updateNavUI();

        if (galleryShelf && shelfItems.length > 1) {
            let touchStartX = 0;
            let touchStartY = 0;

            galleryShelf.addEventListener('touchstart', (e) => {
                const t = e.changedTouches[0];
                touchStartX = t ? t.clientX : 0;
                touchStartY = t ? t.clientY : 0;
            }, { passive: true });

            galleryShelf.addEventListener('touchend', (e) => {
                const t = e.changedTouches[0];
                if (!t) return;

                const dx = t.clientX - touchStartX;
                const dy = t.clientY - touchStartY;
                const absX = Math.abs(dx);
                const absY = Math.abs(dy);

                if (absX < 36 || absX < (absY * 1.2)) return;
                if (dx < 0) switchToAdjacentShelfItem(1);
                else switchToAdjacentShelfItem(-1);
            }, { passive: true });
        }

        shelfItems.forEach((el) => {
            el.addEventListener('click', () => {
                if (document.body.classList.contains('data-hidden')) return;
                switchMedia(el);
            });
            el.addEventListener('dblclick', () => {
                if (document.body.classList.contains('data-hidden')) return;
                const type = el.getAttribute('data-type') || (el.tagName === 'VIDEO' ? 'video' : 'image');
                const src = el.getAttribute('data-src') || el.src || '';
                const mime = type === 'video' ? (el.getAttribute('data-mime') || '') : '';
                lightbox.open(type, src, mime);
            });
        });

        const lbClose = getEl('lb-close'); if (lbClose) lbClose.addEventListener('click', () => lightbox.close());
        const lbBackdrop = getEl('lb-backdrop'); if (lbBackdrop) lbBackdrop.addEventListener('click', () => lightbox.close());
        const lbPrev = getEl('lb-prev'); if (lbPrev) lbPrev.addEventListener('click', () => lightbox.navigate(-1));
        const lbNext = getEl('lb-next'); if (lbNext) lbNext.addEventListener('click', () => lightbox.navigate(1));
        const lbPrevSide = getEl('lb-prev-side'); if (lbPrevSide) lbPrevSide.addEventListener('click', () => lightbox.navigate(-1));
        const lbNextSide = getEl('lb-next-side'); if (lbNextSide) lbNextSide.addEventListener('click', () => lightbox.navigate(1));
        document.addEventListener('keydown', (e) => {
            if (!state.lightboxOpen) return;
            if (e.key === 'Escape') {
                lightbox.close();
                return;
            }
            if (e.key === 'ArrowLeft') {
                e.preventDefault();
                lightbox.navigate(-1);
                return;
            }
            if (e.key === 'ArrowRight') {
                e.preventDefault();
                lightbox.navigate(1);
            }
        });

        const lbZoomIn = getEl('lb-zoom-in'); if (lbZoomIn) lbZoomIn.addEventListener('click', () => lightbox.zoom(+1));
        const lbZoomOut = getEl('lb-zoom-out'); if (lbZoomOut) lbZoomOut.addEventListener('click', () => lightbox.zoom(-1));
        const lbZoomReset = getEl('lb-zoom-reset'); if (lbZoomReset) lbZoomReset.addEventListener('click', () => lightbox.reset());

        const lbBody = getEl('lb-body');
        if (lbBody) {
            lbBody.addEventListener('wheel', (e) => {
                if (!state.lightboxOpen) return;
                const lb = getEl('lightbox');
                if (!lb || (lb.getAttribute('data-type') || '') !== 'image') return;
                e.preventDefault();
                const dir = e.deltaY > 0 ? -1 : +1;
                lightbox.zoomTo(lightbox.scale + (0.2 * dir), e.clientX, e.clientY);
            }, { passive: false });

            lbBody.addEventListener('pointerdown', (e) => lightbox.startDrag(e));
            window.addEventListener('pointermove', (e) => lightbox.drag(e));
            window.addEventListener('pointerup', () => lightbox.endDrag());

            lbBody.addEventListener('dblclick', () => {
                const lb = getEl('lightbox');
                if (!lb || (lb.getAttribute('data-type') || '') !== 'image') return;
                if (lightbox.scale <= 1.01) lightbox.zoomTo(2.0);
                else lightbox.reset();
            });

            lbBody.addEventListener('click', (e) => {
                if (!state.lightboxOpen || lightbox.items.length <= 1) return;
                if (e.target !== lbBody && e.target !== getEl('lb-media-host')) return;
                const rect = lbBody.getBoundingClientRect();
                const goPrev = e.clientX < (rect.left + (rect.width / 2));
                lightbox.navigate(goPrev ? -1 : 1);
            });
        }

        const minus = getEl('qty-minus'); if (minus) minus.addEventListener('click', () => setQuantity(getQuantity() - 1));
        const plus = getEl('qty-plus'); if (plus) plus.addEventListener('click', () => setQuantity(getQuantity() + 1));
        const qtyInput = getEl('qty-input'); if (qtyInput) qtyInput.addEventListener('change', (e) => setQuantity(e.target.value));

        const addBtn = getEl('addToCartBtn');
        const stickyAddBtn = getEl('stickyAddToCartBtn');
        const mainSize = getEl('size-dropdown');
        const stickySize = getEl('sticky-size-dropdown');

        if (addBtn) {
            addBtn.addEventListener('click', () => addToCart(addBtn, mainSize));
        }
        if (stickyAddBtn) {
            stickyAddBtn.addEventListener('click', () => addToCart(stickyAddBtn, stickySize || mainSize));
        }

        if (mainSize && stickySize) {
            stickySize.value = mainSize.value;
            mainSize.addEventListener('change', () => { stickySize.value = mainSize.value; });
            stickySize.addEventListener('change', () => { mainSize.value = stickySize.value; });
        }

        setupWishlist();

        if (!state.sentViewItem) {
            state.sentViewItem = true;
            trackEcomEvent('view_item', {
                currency: 'USD',
                value: Number(ecommerceItem.price),
                items: [Object.assign({}, ecommerceItem, {
                    item_variant: mainSize ? mainSize.value : 'OS',
                    quantity: 1,
                })],
            });

            if (typeof window.dodTrack === 'function') {
                window.dodTrack('product_view', {
                    id: ecommerceItem.item_id,
                    name: ecommerceItem.item_name,
                    category: ecommerceItem.item_category,
                    value: ecommerceItem.price,
                });
            }
        }

        const modelLinkDebounceMs = 900;
        const modelLinkLastTap = new WeakMap();
        document.querySelectorAll('.model-social-link').forEach((link) => {
            link.addEventListener('click', () => {
                const now = Date.now();
                const last = modelLinkLastTap.get(link) || 0;
                if (now - last < modelLinkDebounceMs) return;
                modelLinkLastTap.set(link, now);

                trackEvent('model_link_click', {
                    platform: link.getAttribute('data-platform') || '',
                    model_name: link.getAttribute('data-model-name') || '',
                    href: link.getAttribute('href') || '',
                    product_id: ecommerceItem.item_id,
                    item_name: ecommerceItem.item_name,
                    item_category: ecommerceItem.item_category,
                });
            });
        });

        audio.init();

        // --- Review star picker + submission ---
        (function setupReviewForm() {
            const form = getEl('reviewForm');
            if (!form) return;

            const starBtns = Array.from(document.querySelectorAll('.review-star-btn'));
            const ratingInput = getEl('reviewRatingInput');
            const nameInput = getEl('reviewName');
            const textInput = getEl('reviewText');
            const submitBtn = getEl('reviewSubmitBtn');
            const msgEl = getEl('reviewFormMsg');
            const productIdEl = getEl('reviewProductId');

            function paintStars(count) {
                starBtns.forEach((btn) => {
                    const val = parseInt(btn.dataset.star, 10);
                    btn.classList.toggle('active', val <= count);
                });
            }

            starBtns.forEach((btn) => {
                btn.addEventListener('click', () => {
                    const val = parseInt(btn.dataset.star, 10);
                    ratingInput.value = String(val);
                    paintStars(val);
                });
                btn.addEventListener('mouseenter', () => {
                    paintStars(parseInt(btn.dataset.star, 10));
                });
            });
            const picker = getEl('reviewStarPicker');
            if (picker) {
                picker.addEventListener('mouseleave', () => {
                    paintStars(parseInt(ratingInput.value || '0', 10));
                });
            }

            form.addEventListener('submit', async (e) => {
                e.preventDefault();

                const rating = parseInt(ratingInput.value || '0', 10);
                const name = (nameInput.value || '').trim();
                const text = (textInput.value || '').trim();

                if (rating < 1 || rating > 5) {
                    msgEl.textContent = 'Please select a star rating.';
                    msgEl.className = 'review-form-msg err';
                    return;
                }
                if (name === '' || text.length < 5) {
                    msgEl.textContent = 'Please enter your name and a short review.';
                    msgEl.className = 'review-form-msg err';
                    return;
                }

                submitBtn.disabled = true;
                msgEl.className = 'review-form-msg';
                msgEl.textContent = '';

                try {
                    const body = new URLSearchParams();
                    body.set('product_id', productIdEl.value);
                    body.set('user_name', name);
                    body.set('rating', String(rating));
                    body.set('review', text);

                    const res = await fetch('/api/review_submit.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString()
                    });
                    const data = await res.json();

                    if (data && data.ok) {
                        msgEl.textContent = data.message || 'Review submitted for approval. Thank you!';
                        msgEl.className = 'review-form-msg ok';
                        form.reset();
                        ratingInput.value = '0';
                        paintStars(0);
                    } else {
                        msgEl.textContent = (data && data.error) || 'Something went wrong. Please try again.';
                        msgEl.className = 'review-form-msg err';
                    }
                } catch (err) {
                    msgEl.textContent = 'Network error. Please try again.';
                    msgEl.className = 'review-form-msg err';
                } finally {
                    submitBtn.disabled = false;
                }
            });
        })();

        let audioPrimedByUser = false;
        const primeAudioGlobally = () => {
            if (audioPrimedByUser) return;
            audioPrimedByUser = true;

            if (state.internalAudio) {
                state.internalAudio.volume = 0;
                state.internalAudio.play().then(() => {
                    state.internalAudio.pause();
                    state.internalAudio.volume = state.lastVolume / 100;
                }).catch(() => {});
            }
        };

        document.addEventListener('click', primeAudioGlobally, { once: true });
        document.addEventListener('touchstart', primeAudioGlobally, { once: true });
    };

    return { init };
})();

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => { setTimeout(() => window.DOD.init(), 100); });
} else {
    setTimeout(() => window.DOD.init(), 100);
}
</script>
    <script src="/js/cookie-consent-global.js" defer></script>
</body>
</html>