<?php declare(strict_types=1);

/**
 * shop.php — THE ARCHIVE DATABASE (Product Listing v3.9 — Responsive All-Screen Fix)
 * Path: /home2/asqrtyte/public_html/shop.php
 *
 * FIXES/APPLIED (v3.9):
 * - ✅ Removed “black/dim” image look caused by CSS filters from product-grid.css + inline styles.
 *   (Overrides now force product card images to render with NO grayscale/brightness filters.)
 * - ✅ Keeps your clean URLs, category tabs, pagination, skeleton loader, reveal animations.
 * - ✅ Retains your image URL normalizer + local existence fallback to placeholder.
 *
 * NOTES:
 * - This page links /css/product-grid.css which applies grayscale/brightness filters to .card-image img/video.
 *   That was making product images look dark/black. We override it BELOW with !important rules.
 */

$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
    || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
    || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on');

$domain = (string)($_SERVER['HTTP_HOST'] ?? '');
$domain = preg_replace('/:\d+$/', '', $domain);

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    $cookieParams = [
        'lifetime' => 86400 * 7,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax'
    ];
    if ($domain !== '') {
        $cookieParams['domain'] = $domain;
    }
    session_set_cookie_params($cookieParams);

    session_start([
        'cookie_httponly' => true,
        'cookie_secure'   => $https,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
        'gc_maxlifetime'  => 86400 * 7,
        'cookie_lifetime' => 86400 * 7
    ]);
} elseif (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 1; mode=block');

    // CSP is intentionally permissive for your current inline styles/scripts + Google Fonts.
    $csp = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline'",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
        "font-src 'self' https://fonts.gstatic.com data:",
        // allow http: too (best to store relative or https URLs to avoid mixed-content blocks on https pages)
        "img-src 'self' data: https: http:",
        "media-src 'self' https: http:",
        "connect-src 'self' https: http:",
        "frame-ancestors 'self'",
        "base-uri 'self'",
        "form-action 'self'",
    ];
    header("Content-Security-Policy: " . implode('; ', $csp));
}

/* ─────────────────────────────────────────────────────────────────────────────
   1) CONFIG + DB
───────────────────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/db_connect.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>DATABASE_OFFLINE | DIAMONDS OUTTA DIRT</title>';
    echo '<style>body{margin:0;background:#000;color:#fff;font-family:ui-monospace,Menlo,Consolas,monospace;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px} .box{max-width:720px;border:1px solid #333;background:#050505;padding:22px;border-radius:10px} .h{color:#00ff9d;letter-spacing:2px;font-weight:800;margin:0 0 10px} .p{color:#bbb;line-height:1.5;margin:0 0 10px} a{color:#00ff9d}</style>';
    @include_once __DIR__ . '/includes/bg_styles.php';
    echo '</head><body><div class="box"><div class="h">DATABASE_OFFLINE</div><p class="p">The archive can\'t load right now because the database connection isn\'t available.</p><p class="p">Check <code>db_connect.php</code> credentials + HostGator MySQL status, then refresh.</p><p class="p"><a href="/">Return Home</a></p></div></body></html>';
    exit;
}

const PRODUCTS_PER_PAGE = 24;
const CURRENCY = 'USD';
const SITE_NAME = 'DIAMONDS OUTTA DIRT';
const VERSION = 'v3.9';

/* ─────────────────────────────────────────────────────────────────────────────
   2) HELPERS + INPUTS (Filter Removed)
───────────────────────────────────────────────────────────────────────────── */
if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
if (!function_exists('dod_starts_with')) {
    function dod_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'default' => 1]
]);
$page = max(1, (int)$page);

$limit  = PRODUCTS_PER_PAGE;
$offset = ($page - 1) * $limit;

$sort = 'newest';
$rawSort = filter_input(INPUT_GET, 'sort', FILTER_UNSAFE_RAW);
if (is_string($rawSort) && $rawSort !== '') {
    $candidateSort = strtolower(trim($rawSort));
    $validSorts = ['newest', 'price_low', 'price_high'];
    if (in_array($candidateSort, $validSorts, true)) {
        $sort = $candidateSort;
    }
}

$rawInStock = filter_input(INPUT_GET, 'in_stock', FILTER_UNSAFE_RAW);
$onlyInStock = is_string($rawInStock) && ($rawInStock === '1' || strtolower($rawInStock) === 'true');

$selectedCategory = 'ALL';
$rawCat = filter_input(INPUT_GET, 'category', FILTER_UNSAFE_RAW);
if (is_string($rawCat) && $rawCat !== '') {
    $selectedCategory = strtoupper(trim($rawCat));
}

/* ─────────────────────────────────────────────────────────────────────────────
   3) QUERY: COUNTS + PRODUCTS (Category + optional stock)
───────────────────────────────────────────────────────────────────────────── */
$VALID_CATEGORIES = ['ALL'];
$categoryCounts = ['ALL' => 0];

$totalProductsFiltered = 0;
$totalPages = 1;
$products = [];
$reviewMap = [];
$dbTotalAll = 0;

try {
    $featuredColumnExists = false;
    $productImagesTableExists = false;
    $slugColumnExists = false;

    try {
        $checkFeatured = $pdo->query("SHOW COLUMNS FROM products LIKE 'featured'");
        $featuredColumnExists = (bool)$checkFeatured->fetch();
    } catch (Throwable $e) {
        error_log("Column check failed (featured): " . $e->getMessage());
    }

    try {
        $checkSlug = $pdo->query("SHOW COLUMNS FROM products LIKE 'slug'");
        $slugColumnExists = (bool)$checkSlug->fetch();
    } catch (Throwable $e) {
        error_log("Column check failed (slug): " . $e->getMessage());
    }

    try {
        $checkImagesTable = $pdo->query("SHOW TABLES LIKE 'product_images'");
        $productImagesTableExists = (bool)$checkImagesTable->fetch();
    } catch (Throwable $e) {
        error_log("Table check failed (product_images): " . $e->getMessage());
    }

    try {
        $dbTotalAll = (int)($pdo->query("SELECT COUNT(*) FROM products")->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $dbTotalAll = 0;
    }

    // Categories
    $catsSql = "
        SELECT DISTINCT UPPER(category) AS cat
        FROM products
        WHERE category IS NOT NULL AND category != ''
    ";
    if ($onlyInStock) $catsSql .= " AND stock > 0";
    $catsSql .= " ORDER BY cat ASC";

    $catsStmt = $pdo->query($catsSql);
    $dbCats = $catsStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($dbCats as $c) {
        $c = strtoupper((string)$c);
        if ($c !== '' && $c !== 'ALL') {
            $VALID_CATEGORIES[] = $c;
        }
    }

    if (!in_array($selectedCategory, $VALID_CATEGORIES, true)) {
        $selectedCategory = 'ALL';
    }

    $categoryCounts = array_fill_keys($VALID_CATEGORIES, 0);

    $countByCatSql = "
        SELECT UPPER(category) AS cat, COUNT(*) AS cnt
        FROM products
        WHERE category IS NOT NULL AND category != ''
    ";
    if ($onlyInStock) $countByCatSql .= " AND stock > 0";
    $countByCatSql .= " GROUP BY UPPER(category)";

    $catStmt = $pdo->query($countByCatSql);
    $pairs = $catStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    foreach ($pairs as $k => $v) {
        $k = strtoupper((string)$k);
        if (isset($categoryCounts[$k])) {
            $categoryCounts[$k] = (int)$v;
        }
    }

    $allCountSql = "SELECT COUNT(*) FROM products WHERE 1=1";
    if ($onlyInStock) $allCountSql .= " AND stock > 0";
    $categoryCounts['ALL'] = (int)($pdo->query($allCountSql)->fetchColumn() ?: 0);

    // WHERE
    $where = [];
    $params = [];

    if ($selectedCategory !== 'ALL') {
        $where[] = "UPPER(p.category) = :cat";
        $params[':cat'] = $selectedCategory;
    }
    if ($onlyInStock) $where[] = "p.stock > 0";

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Count for pagination (must use same alias as main query for WHERE clauses)
    $countSql = "SELECT COUNT(*) FROM products p $whereSql";
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
    $countStmt->execute();
    $totalProductsFiltered = (int)$countStmt->fetchColumn();

    $totalPages = max(1, (int)ceil($totalProductsFiltered / $limit));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $limit;
    }

    // Sort mode
    switch ($sort) {
        case 'price_low':
            $orderSql = 'ORDER BY p.price ASC, p.id DESC';
            break;
        case 'price_high':
            $orderSql = 'ORDER BY p.price DESC, p.id DESC';
            break;
        case 'newest':
        default:
            $orderSql = $featuredColumnExists
                ? 'ORDER BY COALESCE(p.featured, 0) DESC, p.id DESC'
                : 'ORDER BY p.id DESC';
            break;
    }

    // Avoid N+1 image lookup by joining first product_images row per product.
    $imageJoinSql = '';
    $imageSelectSql = 'p.image_url AS image_url';
    if ($productImagesTableExists) {
        $imageJoinSql = "
            LEFT JOIN product_images pi
                ON pi.id = (
                    SELECT pi2.id
                    FROM product_images pi2
                    WHERE pi2.product_id = p.id
                      AND pi2.image_url IS NOT NULL
                      AND pi2.image_url != ''
                    ORDER BY pi2.position ASC, pi2.id ASC
                    LIMIT 1
                )
        ";
        $imageSelectSql = "COALESCE(NULLIF(TRIM(p.image_url), ''), pi.image_url) AS image_url";
    }
    $slugSelectSql = $slugColumnExists ? 'p.slug AS slug' : 'NULL AS slug';

    // Inline LIMIT/OFFSET for native prepares
    $limitInt = (int)$limit;
    $offsetInt = (int)$offset;
    $sql = $featuredColumnExists ? "
        SELECT p.id, $slugSelectSql, p.name, p.price, p.stock, p.category, $imageSelectSql, p.description, p.available_sizes,
               COALESCE(p.featured, 0) as featured
        FROM products p
        $imageJoinSql
        $whereSql
        $orderSql
        LIMIT $limitInt OFFSET $offsetInt
    " : "
        SELECT p.id, $slugSelectSql, p.name, p.price, p.stock, p.category, $imageSelectSql, p.description, p.available_sizes,
               0 as featured
        FROM products p
        $imageJoinSql
        $whereSql
        $orderSql
        LIMIT $limitInt OFFSET $offsetInt
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Batch-fetch review ratings for exactly the products on this page
    // (one query total, not one per card) so the grid can show a star
    // rating without needing to visit each product page first.
    $reviewMap = [];
    if (!empty($products)) {
        try {
            $reviewsTableExists = (bool)$pdo->query(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_reviews' LIMIT 1"
            )->fetchColumn();

            if ($reviewsTableExists) {
                $productIds = array_map(static fn($p) => (int)$p['id'], $products);
                $placeholders = implode(',', array_fill(0, count($productIds), '?'));
                $revStmt = $pdo->prepare("
                    SELECT product_id, AVG(rating) AS avg_rating, COUNT(*) AS review_count
                    FROM product_reviews
                    WHERE approved = 1 AND product_id IN ($placeholders)
                    GROUP BY product_id
                ");
                $revStmt->execute($productIds);
                foreach ($revStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rev) {
                    $reviewMap[(int)$rev['product_id']] = [
                        'avg' => round((float)$rev['avg_rating'], 1),
                        'count' => (int)$rev['review_count'],
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log("REVIEW_MAP_FETCH_FAIL(shop.php): " . $e->getMessage());
        }
    }

} catch (Throwable $e) {
    error_log("ARCHIVE_FETCH_FAIL(shop.php): " . $e->getMessage());
    http_response_code(500);
    $products = [];
}

/* ─────────────────────────────────────────────────────────────────────────────
   4) HELPERS (PLACEHOLDER + URL NORMALIZER + CACHE-BUST + CLEAN URL BUILDER)
───────────────────────────────────────────────────────────────────────────── */
function dodPlaceholder(): string {
    $svg = rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="800">'
        . '<rect width="100%" height="100%" fill="#0a0a0f"/>'
        . '<text x="50%" y="46%" fill="#00ff9d" font-size="22" text-anchor="middle" font-family="monospace">DIAMONDS OUTTA DIRT</text>'
        . '<text x="50%" y="54%" fill="#00ff9d" font-size="14" text-anchor="middle" font-family="monospace">ARCHIVE ITEM</text>'
        . '</svg>'
    );
    return 'data:image/svg+xml;charset=UTF-8,' . $svg;
}

function dod_current_scheme_is_https(): bool {
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on');
    return $https;
}

function dod_encode_path(string $path): string {
    $path = str_replace('\\', '/', $path);
    $parts = explode('/', $path);
    foreach ($parts as $i => $seg) {
        if ($seg === '' || $seg === '.' || $seg === '..') continue;
        $parts[$i] = rawurlencode($seg);
    }
    return implode('/', $parts);
}

function dod_local_exists(string $webPath): ?bool {
    $webPath = trim($webPath);
    if ($webPath === '' || $webPath[0] !== '/') return null;

    $docroot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    $docroot = rtrim(str_replace('\\', '/', $docroot), '/');
    if ($docroot === '') return null;

    $fs = $docroot . $webPath;
    return @is_file($fs);
}

/**
 * Normalize DB path into a web path usable in <img src>.
 * Supports:
 *  - https://(remote) or data:
 *  - /images/...  images/...
 *  - /uploads/... uploads/...
 *  - /admin/uploads/... admin/uploads/...
 *  - filesystem paths like:
 *      /home2/USER/public_html/uploads/...
 *      /home2/USER/public_html/images/...
 *      /public_html/uploads/...
 *      C:\path\to\public_html\uploads\...
 *
 * Also:
 *  - Upgrades http:// to https:// when the current request is https.
 *  - If local and missing, tries /uploads <-> /admin/uploads swap before placeholder.
 */
function productImage(string $path): string {
    $path = trim($path);
    if ($path === '') return dodPlaceholder();

    // data:
    if (dod_starts_with($path, 'data:')) return $path;

    // Protocol-relative URLs
    if (preg_match('#^//#', $path)) {
        $scheme = dod_current_scheme_is_https() ? 'https:' : 'http:';
        $path = $scheme . $path;
    }

    // Remote URLs (upgrade http->https when on https)
    if (preg_match('#^https?://#i', $path)) {
        if (dod_current_scheme_is_https() && preg_match('#^http://#i', $path)) {
            $path = preg_replace('#^http://#i', 'https://', $path);
        }
        return $path;
    }

    // Normalize slashes
    $p = str_replace('\\', '/', $path);

    // Strip everything before /public_html/ if present
    $pubPos = strpos($p, '/public_html/');
    if ($pubPos !== false) {
        $p = substr($p, $pubPos + strlen('/public_html')); // keep leading slash on remainder
    } else {
        // If it contains a known web folder, take the tail from the first known marker
        $markers = ['/admin/uploads/', '/uploads/', '/images/', '/assets/', '/img/'];
        $bestPos = null;
        foreach ($markers as $m) {
            $pos = strpos($p, $m);
            if ($pos !== false && ($bestPos === null || $pos < $bestPos)) {
                $bestPos = $pos;
            }
        }
        if ($bestPos !== null) {
            $p = substr($p, (int)$bestPos);
        }
    }

    // Ensure it starts with a single leading slash web-path
    $p = '/' . ltrim($p, '/');

    // Normalize to known public folders when embedded deeper
    $markers2 = ['/admin/uploads/', '/uploads/', '/images/'];
    foreach ($markers2 as $m) {
        $pos = strpos($p, $m);
        if ($pos !== false) {
            $p = substr($p, $pos);
            break;
        }
    }
    $p = '/' . ltrim($p, '/');

    // Encode path segments (fixes spaces/special chars)
    $p = dod_encode_path($p);

    // Best-effort existence check + alternates
    $exists = dod_local_exists($p);
    if ($exists === true) return $p;

    $alts = [];
    if (dod_starts_with($p, '/uploads/')) {
        $alts[] = '/admin' . $p; // /admin/uploads/...
    } elseif (dod_starts_with($p, '/admin/uploads/')) {
        $alts[] = substr($p, strlen('/admin')); // /uploads/...
    }

    foreach ($alts as $alt) {
        $alt = dod_encode_path('/' . ltrim($alt, '/'));
        $altExists = dod_local_exists($alt);
        if ($altExists === true) return $alt;
    }

    // If docroot unknown, return the computed web path (don’t force placeholder).
    if ($exists === null) return $p;

    // Verified missing locally, fallback to placeholder
    return dodPlaceholder();
}

/**
 * Stable cache-busting:
 * - For local "/images/..." or "/uploads/..." use filemtime(DOCUMENT_ROOT . path)
 * - Otherwise hash the URL
 */
function assetVersion(string $src): string {
    $src = trim($src);
    if ($src === '' || dod_starts_with($src, 'data:')) return '';

    if (preg_match('#^https?://#i', $src)) {
        return substr(sha1($src), 0, 10);
    }

    $docroot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    $docroot = rtrim(str_replace('\\', '/', $docroot), '/');
    if ($docroot !== '' && dod_starts_with($src, '/')) {
        $fs = $docroot . $src;
        if (@is_file($fs)) {
            $mt = @filemtime($fs);
            if ($mt !== false) return (string)(int)$mt;
        }
    }

    return substr(sha1($src), 0, 10);
}

function buildUrlWithParams(array $overrides = []): string {
    $base = '/shop';

    $q = [];
    $keys = ['category', 'page', 'sort', 'in_stock'];
    foreach ($keys as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') {
            $q[$k] = (string)$_GET[$k];
        }
    }
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') unset($q[$k]);
        else $q[$k] = (string)$v;
    }

    if (isset($q['category']) && strtoupper($q['category']) === 'ALL') unset($q['category']);
    if (isset($q['page'])) {
        $p = (int)$q['page'];
        if ($p <= 1) unset($q['page']);
        else $q['page'] = (string)$p;
    }
    if (isset($q['sort']) && strtolower((string)$q['sort']) === 'newest') unset($q['sort']);
    if (isset($q['in_stock']) && (string)$q['in_stock'] !== '1') unset($q['in_stock']);

    return $base . (empty($q) ? '' : ('?' . http_build_query($q)));
}

function pageUrl(int $page, string $category): string {
    return buildUrlWithParams([
        'page' => max(1, $page),
        'category' => strtoupper($category),
    ]);
}

function renderPagination(int $page, int $totalPages, string $selectedCategory, string $navAttrs = ''): string {
    if ($totalPages <= 1) {
        return '';
    }

    ob_start();
    ?>
    <nav class="pagination" aria-label="Product pagination"<?= $navAttrs ?>>
        <?php if ($page > 1): ?>
            <a href="<?= h(pageUrl($page - 1, $selectedCategory)) ?>" class="page-link" aria-label="Previous page">&larr; PREV</a>
        <?php else: ?>
            <span class="page-link disabled" aria-hidden="true">&larr; PREV</span>
        <?php endif; ?>

        <?php
            $maxButtons = 7;
            $start = max(1, $page - 3);
            $end = min($totalPages, $start + ($maxButtons - 1));
            $start = max(1, $end - ($maxButtons - 1));
        ?>

        <?php if ($start > 1): ?>
            <a href="<?= h(pageUrl(1, $selectedCategory)) ?>" class="page-link" aria-label="Page 1">1</a>
            <?php if ($start > 2): ?>
                <span class="page-link disabled" aria-hidden="true">&hellip;</span>
            <?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $start; $i <= $end; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="page-link current" aria-current="page"><?= $i ?></span>
            <?php else: ?>
                <a href="<?= h(pageUrl($i, $selectedCategory)) ?>" class="page-link" aria-label="Page <?= $i ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($end < $totalPages): ?>
            <?php if ($end < $totalPages - 1): ?>
                <span class="page-link disabled" aria-hidden="true">&hellip;</span>
            <?php endif; ?>
            <a href="<?= h(pageUrl($totalPages, $selectedCategory)) ?>" class="page-link" aria-label="Page <?= $totalPages ?>"><?= $totalPages ?></a>
        <?php endif; ?>

        <?php if ($page < $totalPages): ?>
            <a href="<?= h(pageUrl($page + 1, $selectedCategory)) ?>" class="page-link" aria-label="Next page">NEXT &rarr;</a>
        <?php else: ?>
            <span class="page-link disabled" aria-hidden="true">NEXT &rarr;</span>
        <?php endif; ?>
    </nav>
    <?php

    return (string)ob_get_clean();
}

function formatPrice(float $price): string {
    return number_format($price, 2, '.', ',');
}

/**
 * Build a clean product URL:
 * - Prefer /product/{slug} when available
 * - Fallback to /product/{id}
 */
function getProductUrl(int $id, ?string $slug = null): string {
    $id = max(0, $id);
    $slug = is_string($slug) ? trim($slug) : '';
    if ($slug !== '') {
        return '/product/' . rawurlencode($slug);
    }
    return '/product/' . $id;
}

/* Cart badge (read-only) */
$cartCount = 0;
$cartTotal = 0.00;

if (isset($_SESSION['cart']['items']) && is_array($_SESSION['cart']['items'])) {
    foreach ($_SESSION['cart']['items'] as $item) {
        $quantity = max(0, (int)($item['quantity'] ?? 0));
        $price = max(0.00, (float)($item['price'] ?? 0));
        $cartCount += $quantity;
        $cartTotal += ($price * $quantity);
    }
} elseif (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        if (is_array($item)) {
            $quantity = max(0, (int)($item['quantity'] ?? 0));
            $price = max(0.00, (float)($item['price'] ?? 0));
            $cartCount += $quantity;
            $cartTotal += ($price * $quantity);
        } elseif (is_numeric($item)) {
            $cartCount += (int)$item;
        }
    }
}

$host = (string)($_SERVER['HTTP_HOST'] ?? '');
$hostSafe = preg_replace('/[^a-zA-Z0-9\.\-:]/', '', $host);
$scheme = $https ? 'https' : 'http';
$reqUri = (string)($_SERVER['REQUEST_URI'] ?? '/shop');
$currentUrl = $scheme . '://' . $hostSafe . $reqUri;

$metaDescription = SITE_NAME . ' ' . VERSION . ' | Displaying ' . count($products) . ' of ' . $totalProductsFiltered . ' items';
?>
<!DOCTYPE html>
<html lang="en" class="tech-archive">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <title>ARCHIVE_DATABASE | <?= h(SITE_NAME) ?> <?= h(VERSION) ?></title>
    <?php if (is_file(__DIR__ . '/includes/tracking_pixels.php')) { require_once __DIR__ . '/includes/tracking_pixels.php'; } ?>

    <meta name="description" content="<?= h($metaDescription) ?>">
    <meta name="keywords" content="apparel, art, streetwear, cyberpunk, fashion, design">
    <meta name="author" content="<?= h(SITE_NAME) ?>">
    <meta name="robots" content="index, follow">
    <meta name="version" content="<?= h(VERSION) ?>">

    <meta property="og:title" content="ARCHIVE_DATABASE | <?= h(SITE_NAME) ?>">
    <meta property="og:description" content="<?= h($metaDescription) ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= h($currentUrl) ?>">
    <meta property="og:site_name" content="<?= h(SITE_NAME) ?>">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="ARCHIVE_DATABASE | <?= h(SITE_NAME) ?>">
    <meta name="twitter:description" content="<?= h($metaDescription) ?>">

    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link rel="stylesheet" href="/css/master.css">
    <link rel="stylesheet" href="/css/product-grid.css">
    <link rel="stylesheet" href="/css/navigation.css">

    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Inter:wght@300;400;700;900&family=Space+Mono:wght@400;700&family=Syncopate:wght@400;700;900&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg-outer: #030712;
            --bg-inner: #050b1a;
            --bg-alt: rgba(14, 22, 42, 0.92);
            --accent: #7c5cff;
            --accent-soft: rgba(124, 92, 255, 0.35);
            --accent-2: #28e0b9;
            --text: #f9fafb;
            --text-dim: #9ca3af;
            --border: rgba(148, 163, 184, 0.35);
            --card-radius: 16px;
            --shadow-soft: 0 24px 80px rgba(15, 23, 42, 0.9);
            --ui-panel: rgba(7, 12, 24, 0.9);
            --ui-panel-strong: rgba(4, 9, 20, 0.96);
            --ui-border: rgba(148, 163, 184, 0.4);
            --ui-muted: rgba(148, 163, 184, 0.82);
            --content-accent: #8fd7ff;
        }

        .tech-archive {
            min-height: 100vh;
            color: var(--text);
            background:
                radial-gradient(circle at 0% 0%, rgba(40, 224, 185, 0.25), transparent 55%),
                radial-gradient(circle at 100% 100%, rgba(124, 92, 255, 0.4), transparent 60%),
                radial-gradient(circle at 50% 0%, rgba(15, 23, 42, 0.85), transparent 70%),
                linear-gradient(145deg, var(--bg-outer), var(--bg-inner));
            background-attachment: fixed;
            position: relative;
            overflow-x: hidden;
        }

        .tech-archive::before {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image:
                linear-gradient(to right, rgba(148, 163, 184, 0.06) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(148, 163, 184, 0.06) 1px, transparent 1px);
            background-size: 72px 72px;
            mix-blend-mode: soft-light;
            opacity: 0.6;
            z-index: 0;
        }

        .sticky-nav {
            position: sticky;
            top: 0;
            z-index: 40;
            backdrop-filter: blur(14px);
            background: linear-gradient(180deg, rgba(5, 10, 20, 0.97), rgba(5, 10, 20, 0.9));
            border-bottom: 1px solid var(--ui-border);
        }

        .nav-mobile-toggle {
            display: none;
            width: calc(100% - 24px);
            margin: 0 12px 10px;
            min-height: 44px;
            border-radius: 6px;
            border: 1px solid var(--ui-border);
            background: var(--ui-panel-strong);
            color: #e9edf5;
            font-family: 'Space Mono', ui-monospace;
            font-size: 0.72rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .nav-mobile-toggle:hover,
        .nav-mobile-toggle:focus-visible {
            border-color: rgba(143, 215, 255, 0.66);
            color: var(--content-accent);
            outline: none;
        }

        .skeleton-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 28px;
            padding: 40px 0;
        }
        .skeleton-card {
            background: rgba(15, 23, 42, 0.85);
            border-radius: var(--card-radius);
            overflow: hidden;
            min-height: 340px;
            position: relative;
            box-shadow: 0 20px 70px rgba(15, 23, 42, 0.9);
            border: 1px solid rgba(15, 23, 42, 0.9);
        }
        .skeleton-img,
        .skeleton-text,
        .skeleton-price {
            border-radius: 999px;
            background: linear-gradient(90deg, #111827, #1f2937, #111827);
            background-size: 200% 100%;
            animation: skeletonPulse 1.4s ease-in-out infinite;
        }
        .skeleton-img {
            height: 220px;
            margin: 22px 22px 10px 22px;
            border-radius: 18px;
        }
        .skeleton-text {
            height: 16px;
            margin: 10px 22px;
        }
        .skeleton-price {
            height: 16px;
            width: 80px;
            margin: 10px 22px 20px 22px;
        }
        @keyframes skeletonPulse {
            0% { background-position: 200% 0; opacity: 0.35; }
            50% { background-position: 0 0; opacity: 0.9; }
            100% { background-position: -200% 0; opacity: 0.35; }
        }

        #backToTopBtn {
            position: fixed;
            bottom: 32px;
            right: 32px;
            z-index: 50;
            background: radial-gradient(circle at 30% 20%, #28e0b9, #14b8a6);
            color: #020617;
            border: none;
            border-radius: 999px;
            width: 52px;
            height: 52px;
            box-shadow: 0 18px 65px rgba(15, 23, 42, 0.95);
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease, transform 0.25s ease, box-shadow 0.25s ease;
        }
        #backToTopBtn.visible { opacity: 1; pointer-events: auto; }
        #backToTopBtn:hover { transform: translateY(-3px); box-shadow: 0 24px 80px rgba(15, 23, 42, 0.98); }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 28px;
            padding: 40px 0 20px 0;
        }

        .reveal-item {
            opacity: 0;
            transform: translateY(18px) scale(0.99);
            transition: opacity 0.5s ease, transform 0.5s ease;
        }
        .reveal-item.revealed {
            opacity: 1;
            transform: translateY(0) scale(1);
        }

        .product-card {
            --card-accent: var(--content-accent);
            --card-accent-soft: rgba(143, 215, 255, 0.68);
            --card-accent-glow: rgba(143, 215, 255, 0.34);
            display: flex;
            flex-direction: column;
            min-height: 340px;
            border-radius: var(--card-radius);
            overflow: hidden;
            text-decoration: none;
            background: linear-gradient(180deg, rgba(16, 22, 38, 0.95), rgba(10, 15, 26, 0.98));
            border: 1px solid rgba(148, 163, 184, 0.26);
            box-shadow: 0 22px 55px rgba(3, 8, 18, 0.9);
            transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
            position: relative;
        }

        .product-card::before {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            border-radius: inherit;
            border: 1px solid rgba(255, 255, 255, 0.06);
            background:
                linear-gradient(145deg, rgba(255, 255, 255, 0.07) 0%, transparent 30%),
                linear-gradient(325deg, rgba(158, 231, 255, 0.08) 0%, transparent 32%);
            opacity: 0.7;
            z-index: 2;
        }

        .product-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: -45%;
            width: 28%;
            height: 100%;
            pointer-events: none;
            background: linear-gradient(
                110deg,
                transparent 0%,
                rgba(255, 255, 255, 0.7) 46%,
                rgba(158, 231, 255, 0.26) 68%,
                transparent 100%
            );
            opacity: 0;
            z-index: 3;
            filter: blur(0.35px);
            transform: skewX(-14deg);
        }

        .product-card:focus-visible,
        .page-link:focus-visible,
        .nav-tab:focus-visible,
        .nav-link:focus-visible,
        .sector-select:focus-visible {
            outline: 2px solid var(--accent-2);
            outline-offset: 3px;
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        .sector-tabs {
            display: contents;
        }

        .sector-select {
            display: none;
            width: 100%;
            min-height: 44px;
            border-radius: 6px;
            border: 1px solid var(--ui-border);
            background: var(--ui-panel-strong);
            color: #e9edf5;
            font-family: 'Space Mono', ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', monospace;
            font-size: 0.74rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            padding: 10px 12px;
        }

        .product-card:hover,
        .product-card:focus-within {
            transform: translateY(-6px) scale(1.01);
            box-shadow: 0 32px 90px rgba(15, 23, 42, 1);
            border-color: var(--card-accent-soft);
        }

        .product-card:hover::after,
        .product-card:focus-within::after {
            opacity: 1;
            animation: cardDiamondSweep 820ms ease-out 1;
        }

        .card-image {
            position: relative;
            overflow: hidden;
            aspect-ratio: 4 / 5;
            border-bottom: 1px solid rgba(15, 23, 42, 0.9);
        }

        /* ============================================================
           CRITICAL FIX: Remove any CSS filters that make images “black”
           - product-grid.css applies grayscale/brightness to .card-image img/video
           - this override forces normal rendering
           ============================================================ */
        .card-image img,
        .card-image video,
        .product-img {
            filter: none !important;
            -webkit-filter: none !important;
            opacity: 1 !important;
        }

        /* Keep hover zoom, but do NOT alter brightness/contrast via filter */
        .product-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transform: scale(1.02);
            transition: transform 0.6s ease;
        }
        .product-card:hover .product-img,
        .product-card:focus-within .product-img {
            transform: scale(1.08);
        }

        .quick-view-overlay {
            position: absolute;
            inset: auto 0 0 0;
            padding: 16px 0 10px 0;
            background: linear-gradient(to top, rgba(15, 23, 42, 0.96), transparent);
            font-family: 'Syncopate', system-ui, sans-serif;
            font-size: 0.85rem;
            text-align: center;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: var(--accent-2);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
        }
        .product-card:hover .quick-view-overlay,
        .product-card:focus-within .quick-view-overlay { opacity: 1; }

        .card-info {
            padding: 18px 14px 14px 14px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            position: relative;
            z-index: 4;
            background: linear-gradient(180deg, rgba(11, 17, 30, 0.4), rgba(8, 13, 24, 0.72));
        }

        .card-title {
            font-family: 'Cormorant Garamond', Georgia, 'Times New Roman', serif;
            font-size: 1.15rem;
            letter-spacing: 0.03em;
            text-transform: none;
            color: var(--text);
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .product-card:hover .card-title,
        .product-card:focus-within .card-title { color: var(--card-accent); }

        .card-rating {
            font-size: 0.78rem;
            letter-spacing: 0.05em;
            color: #ffb703;
            margin-bottom: 2px;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .card-rating-count {
            font-family: 'Space Mono', ui-monospace;
            font-size: 0.66rem;
            color: var(--text-dim);
            letter-spacing: 0.03em;
        }

        .card-price {
            font-family: 'Syncopate', system-ui, sans-serif;
            font-weight: 800;
            letter-spacing: 0.16em;
            font-size: 0.9rem;
            color: var(--card-accent);
            text-shadow: 0 0 12px var(--card-accent-glow);
        }

        .card-sizes {
            font-family: 'Space Mono', ui-monospace;
            font-size: 0.72rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(148, 163, 184, 0.95);
        }

        .card-desc {
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 0.78rem;
            line-height: 1.45;
            color: var(--text-dim);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .stock-low,
        .sold-out,
        .featured-badge {
            position: absolute;
            top: 14px;
            z-index: 2;
            padding: 4px 10px;
            border-radius: 999px;
            font-family: 'Space Mono', ui-monospace;
            font-size: 0.6rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }
        .stock-low {
            right: 14px;
            background: linear-gradient(135deg, #facc15, #ea580c);
            color: #020617;
        }
        .sold-out {
            right: 14px;
            background: linear-gradient(135deg, #ef4444, #b91c1c);
            color: #f9fafb;
        }
        .featured-badge {
            left: 14px;
            background: linear-gradient(135deg, #7c5cff, #ec4899);
            color: #020617;
        }
        .featured-badge.featured-shifted {
            top: 44px;
        }

        .one-of-one-badge {
            position: absolute;
            top: 14px;
            left: 14px;
            z-index: 3;
            padding: 4px 10px;
            border-radius: 999px;
            font-family: 'Space Mono', ui-monospace;
            font-size: 0.6rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            background: linear-gradient(135deg, #c9f0ff, #8fd7ff);
            color: #02131f;
            box-shadow: 0 0 14px rgba(143, 215, 255, 0.45);
        }

        @keyframes cardDiamondSweep {
            0% { left: -45%; opacity: 0; }
            18% { opacity: 1; }
            100% { left: 128%; opacity: 0; }
        }

        #cart-count {
            font-weight: 800;
            color: var(--accent-2);
            transition: transform 0.25s ease;
        }
        .cart-updated { animation: cartPulse 0.4s ease; }
        @keyframes cartPulse { 0% { transform: scale(1);} 50% { transform: scale(1.18);} 100% { transform: scale(1);} }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 14px;
            margin-top: 70px;
            padding-top: 32px;
            border-top: 1px solid rgba(148, 163, 184, 0.35);
            flex-wrap: wrap;
        }
        .page-link {
            padding: 10px 18px;
            border-radius: 4px;
            border: 1px solid var(--ui-border);
            text-decoration: none;
            color: #e6ebf5;
            background: var(--ui-panel);
            font-family: 'Space Mono', ui-monospace;
            font-size: 0.74rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            min-height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s ease, color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }
        .page-link:hover,
        .page-link.current {
            background: linear-gradient(135deg, rgba(24, 32, 54, 0.98), rgba(12, 19, 34, 0.98));
            color: var(--content-accent);
            border-color: rgba(143, 215, 255, 0.62);
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(2, 7, 16, 0.9);
        }
        .page-link.disabled { opacity: 0.35; pointer-events: none; }

        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 80px 0;
            color: var(--text-dim);
            font-family: 'Space Mono', ui-monospace;
            letter-spacing: 0.22em;
            text-transform: uppercase;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }
        .empty-icon { font-size: 3rem; margin-bottom: 8px; }
        .empty-title { font-size: 1rem; color: var(--text); }
        .empty-desc { font-size: 0.8rem; color: var(--text-dim); }

        .diag {
            margin: 18px 0 0;
            padding: 12px 14px;
            border-radius: 6px;
            border: 1px solid var(--ui-border);
            background: var(--ui-panel);
            font-family: 'Space Mono', ui-monospace;
            font-size: 0.72rem;
            letter-spacing: 0.12em;
            color: var(--ui-muted);
        }

        .shop-controls {
            margin: 12px 0 24px;
            padding: 14px;
            border: 1px solid var(--ui-border);
            border-radius: 8px;
            background: var(--ui-panel);
        }

        .shop-controls-grid {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 12px;
            align-items: end;
        }

        .shop-controls-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .shop-controls-label {
            color: var(--ui-muted);
            font-family: 'Space Mono', ui-monospace;
            font-size: 0.64rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .shop-controls-select {
            min-height: 44px;
            border-radius: 6px;
            border: 1px solid var(--ui-border);
            background: var(--ui-panel-strong);
            color: #e9edf5;
            font-family: 'Space Mono', ui-monospace;
            font-size: 0.72rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            padding: 10px 12px;
        }

        .shop-controls-stock {
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 0 12px;
            border: 1px solid var(--ui-border);
            border-radius: 6px;
            background: var(--ui-panel-strong);
            color: #dbe7fb;
            font-family: 'Space Mono', ui-monospace;
            font-size: 0.7rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            user-select: none;
        }

        .shop-controls-actions {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .shop-controls-btn,
        .shop-controls-clear {
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            border: 1px solid var(--ui-border);
            font-family: 'Space Mono', ui-monospace;
            font-size: 0.7rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            text-decoration: none;
            padding: 0 14px;
        }

        .shop-controls-btn {
            cursor: pointer;
            background: linear-gradient(135deg, rgba(24, 32, 54, 0.98), rgba(12, 19, 34, 0.98));
            color: var(--content-accent);
        }

        .shop-controls-clear {
            color: #c9d7ef;
            background: var(--ui-panel-strong);
        }

        .shop-controls-btn:hover,
        .shop-controls-clear:hover {
            border-color: rgba(143, 215, 255, 0.66);
        }

        .shop-header {
            padding: 60px 0 40px;
            border-bottom: 1px solid var(--border);
        }

        .shop-status-label {
            color: var(--accent-2);
            font-size: 0.68rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            font-family: 'Space Mono', ui-monospace;
        }

        .shop-title {
            font-family: 'Cormorant Garamond', Georgia, 'Times New Roman', serif;
            font-size: clamp(2.3rem, 5vw, 4.3rem);
            text-transform: none;
            margin: 10px 0;
            color: #f5f0e6;
            line-height: 1;
            font-weight: 600;
            letter-spacing: 0.04em;
        }

        .diamond-glow {
            background-image: linear-gradient(
                120deg,
                #f8fdff 0%,
                #d7f2ff 20%,
                #ffffff 35%,
                #9ee7ff 50%,
                #ffffff 62%,
                #b8e9ff 78%,
                #ffffff 100%
            );
            background-size: 220% 220%;
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            position: relative;
            display: inline-block;
            text-shadow:
                0 0 8px rgba(158, 231, 255, 0.38),
                0 0 18px rgba(158, 231, 255, 0.25),
                0 0 30px rgba(143, 215, 255, 0.24);
            animation: diamondShimmer 5.5s linear infinite;
        }

        .diamond-glow::after {
            content: '';
            position: absolute;
            inset: -6% -8%;
            pointer-events: none;
            border-radius: 10px;
            background:
                radial-gradient(circle at 18% 35%, rgba(255, 255, 255, 0.36) 0 1px, transparent 2px),
                radial-gradient(circle at 72% 22%, rgba(158, 231, 255, 0.34) 0 1px, transparent 2px),
                radial-gradient(circle at 52% 74%, rgba(214, 210, 255, 0.32) 0 1px, transparent 2px);
            opacity: 0.65;
            mix-blend-mode: screen;
            animation: diamondSparkle 2.8s ease-in-out infinite alternate;
        }

        .diamond-glow:hover,
        .diamond-glow:focus-visible {
            text-shadow:
                0 0 10px rgba(214, 242, 255, 0.55),
                0 0 22px rgba(158, 231, 255, 0.4),
                0 0 44px rgba(214, 210, 255, 0.3);
        }

        .diamond-glow:hover::before,
        .diamond-glow:focus-visible::before {
            content: '';
            position: absolute;
            top: 0;
            left: -35%;
            width: 28%;
            height: 100%;
            pointer-events: none;
            background: linear-gradient(
                120deg,
                transparent 0%,
                rgba(255, 255, 255, 0.82) 48%,
                rgba(158, 231, 255, 0.22) 70%,
                transparent 100%
            );
            filter: blur(0.3px);
            animation: diamondSweep 900ms ease-out 1;
        }

        .diamond-wordmark {
            letter-spacing: 0.12em;
            font-weight: 700;
            text-transform: uppercase;
        }

        @keyframes diamondShimmer {
            0% { background-position: 0% 50%; }
            100% { background-position: 200% 50%; }
        }

        @keyframes diamondSparkle {
            0% { opacity: 0.45; transform: translateY(0); }
            100% { opacity: 0.82; transform: translateY(-1px); }
        }

        @keyframes diamondSweep {
            0% { transform: translateX(0) skewX(-14deg); opacity: 0; }
            20% { opacity: 1; }
            100% { transform: translateX(520%) skewX(-14deg); opacity: 0; }
        }

        .shop-subtitle {
            color: var(--content-accent);
            font-size: 0.72rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            font-family: 'Space Mono', ui-monospace;
            margin-top: 15px;
        }

        .shop-stats {
            color: #8e99ae;
            font-size: 0.7rem;
            letter-spacing: 0.1em;
            margin-top: 6px;
            font-family: 'Space Mono', ui-monospace;
        }

        .sub-nav {
            background: var(--ui-panel);
            border-top: 1px solid rgba(148, 163, 184, 0.16);
            border-bottom: 1px solid rgba(148, 163, 184, 0.16);
            padding-top: 8px;
            padding-bottom: 8px;
        }

        .nav-tab {
            border-radius: 4px;
            border: 1px solid var(--ui-border);
            background: var(--ui-panel-strong);
            color: #d7deeb;
            font-family: 'Space Mono', ui-monospace;
            font-size: 0.72rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            padding: 10px 14px;
            transition: all 0.2s ease;
        }

        .nav-tab:hover,
        .nav-tab.active {
            color: var(--content-accent);
            border-color: rgba(143, 215, 255, 0.66);
            background: rgba(24, 32, 54, 0.92);
        }

        .nav-label {
            color: #8d99b2;
            font-family: 'Space Mono', ui-monospace;
            font-size: 0.68rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            margin-right: 8px;
        }

        /* ============================================================
           RESPONSIVE SYSTEM v3.9
           Covers ultrawide desktop, laptop, tablet, phone, small phone,
           landscape mobile, notched screens, touch devices, and zoom.
           ============================================================ */
        html {
            -webkit-text-size-adjust: 100%;
            text-size-adjust: 100%;
            scroll-padding-top: 150px;
        }

        *, *::before, *::after {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-width: 0;
        }

        img, video, svg, canvas {
            max-width: 100%;
        }

        button, select, input, a {
            font: inherit;
        }

        .tactical-nav,
        .top-bar,
        .sub-nav,
        .nav-group,
        .nav-links,
        .shop-main,
        .lookbook-section,
        #productGridContainer,
        .product-grid,
        .skeleton-grid,
        .reveal-item,
        .product-card,
        .card-info {
            min-width: 0;
        }

        .shop-main {
            width: min(100%, 1180px);
            margin: 0 auto 120px;
            padding-inline: clamp(14px, 3vw, 32px);
        }

        .top-bar {
            width: min(100%, 1440px);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
            margin-inline: auto;
            padding-inline: max(14px, env(safe-area-inset-left));
            padding-right: max(14px, env(safe-area-inset-right));
            gap: 12px;
        }

        .logo-area {
            min-width: 0;
            display: flex;
            align-items: center;
            flex: 1 1 auto;
        }

        .logo,
        .diamond-wordmark {
            max-width: 100%;
            font-size: clamp(0.82rem, 1.8vw, 1.08rem);
            overflow-wrap: anywhere;
        }

        .system-status {
            flex: 0 1 auto;
            position: static;
            min-width: 0;
            text-align: right;
            overflow-wrap: anywhere;
        }

        .sub-nav {
            width: 100%;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding-left: max(14px, env(safe-area-inset-left));
            padding-right: max(14px, env(safe-area-inset-right));
        }

        .sub-nav .nav-group {
            display: flex;
            align-items: center;
            flex: 1 1 auto;
            gap: 8px;
        }

        .sector-tabs {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            min-width: 0;
        }

        .sub-nav .nav-links {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            flex: 0 0 auto;
            gap: 8px;
        }

        .nav-tab,
        .nav-link,
        .page-link,
        .shop-controls-btn,
        .shop-controls-clear,
        .shop-controls-stock,
        .shop-controls-select,
        .sector-select,
        .nav-mobile-toggle {
            min-height: 44px;
        }

        .nav-tab,
        .nav-link,
        .page-link,
        .shop-controls-btn,
        .shop-controls-clear,
        .nav-mobile-toggle {
            align-items: center;
            justify-content: center;
        }

        .nav-tab,
        .nav-link {
            display: inline-flex;
        }

        .lookbook-section,
        #productGridContainer {
            width: 100%;
        }

        .shop-controls,
        .diag,
        .product-card,
        .skeleton-card {
            max-width: 100%;
        }

        .shop-controls-grid {
            grid-template-columns: minmax(0, 1fr) auto auto;
        }

        .shop-controls-select,
        .sector-select {
            width: 100%;
            max-width: 100%;
        }

        .shop-controls-stock {
            white-space: nowrap;
        }

        .shop-controls-actions {
            min-width: 0;
        }

        .shop-title,
        .shop-subtitle,
        .shop-stats,
        .breadcrumb-nav,
        .diag,
        .card-title,
        .card-sizes,
        .card-desc {
            overflow-wrap: anywhere;
        }

        .shop-title {
            font-size: clamp(2rem, 6vw, 4.3rem);
        }

        .product-grid,
        .skeleton-grid {
            grid-template-columns: repeat(auto-fill, minmax(min(100%, 250px), 1fr));
            gap: clamp(18px, 2.4vw, 28px);
        }

        .reveal-item,
        .product-card {
            width: 100%;
        }

        .card-image {
            width: 100%;
            min-height: 0;
        }

        .product-img {
            display: block;
            max-width: none;
        }

        .stock-low,
        .sold-out,
        .featured-badge,
        .one-of-one-badge {
            max-width: calc(100% - 28px);
            line-height: 1.25;
            text-align: center;
            white-space: normal;
        }

        .pagination {
            max-width: 100%;
        }

        #backToTopBtn {
            right: max(16px, env(safe-area-inset-right));
            bottom: max(16px, env(safe-area-inset-bottom));
        }

        /* Large desktop / ultrawide */
        @media (min-width: 1440px) {
            .shop-main {
                width: min(100%, 1280px);
            }

            .product-grid,
            .skeleton-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }

        /* Laptop and medium desktop */
        @media (max-width: 1199px) {
            .shop-main {
                width: min(100%, 1120px);
            }
        }

        /* Tablet landscape / small laptop */
        @media (max-width: 992px) {
            .product-grid,
            .skeleton-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 22px;
            }

            .shop-controls-grid {
                grid-template-columns: minmax(0, 1fr) auto;
            }

            .shop-controls-actions {
                grid-column: 1 / -1;
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .shop-controls-btn,
            .shop-controls-clear {
                width: 100%;
            }
        }

        /* Tablet portrait and mobile navigation */
        @media (max-width: 768px) {
            html {
                scroll-padding-top: 110px;
            }

            .sticky-nav {
                backdrop-filter: blur(10px);
                -webkit-backdrop-filter: blur(10px);
            }

            .top-bar {
                align-items: center;
                padding-top: 10px;
                padding-bottom: 10px;
            }

            .nav-mobile-toggle {
                display: inline-flex;
                width: calc(100% - 28px);
                margin: 0 14px 10px;
            }

            .sub-nav {
                display: block;
                padding-top: 10px;
                padding-bottom: 10px;
            }

            .sub-nav .nav-group,
            .sub-nav .nav-links {
                width: 100%;
            }

            .sub-nav .nav-group {
                display: block;
            }

            .sub-nav .nav-links {
                margin-top: 10px;
                display: none;
                gap: 8px;
            }

            .sub-nav .nav-links.open {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .sub-nav .nav-link {
                min-height: 46px;
                padding: 10px 12px;
                border: 1px solid var(--ui-border);
                border-radius: 6px;
                background: var(--ui-panel-strong);
                justify-content: center;
                text-align: center;
            }

            .sub-nav .nav-link::after {
                display: none;
            }

            .system-status {
                font-size: 0.6rem;
                letter-spacing: 0.06em;
            }

            .sector-tabs {
                display: none;
            }

            .sector-select {
                display: block;
            }

            .nav-label {
                display: block;
                margin: 0 0 8px;
            }

            .product-grid,
            .skeleton-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 18px;
            }

            .shop-controls-grid {
                grid-template-columns: 1fr;
            }

            .shop-controls-actions {
                grid-column: auto;
                width: 100%;
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .shop-controls-stock {
                width: 100%;
                justify-content: flex-start;
            }

            .breadcrumb-nav {
                margin-top: 24px !important;
                margin-bottom: 6px !important;
                font-size: 0.72rem !important;
                line-height: 1.5;
                word-break: break-word;
            }

            .shop-header {
                padding: 40px 0 28px;
            }

            .card-info {
                padding: 15px 12px 13px;
            }
        }

        /* Phones */
        @media (max-width: 640px) {
            .tech-archive {
                background-attachment: scroll;
            }

            .shop-main {
                margin-bottom: 90px;
                padding-left: max(12px, env(safe-area-inset-left));
                padding-right: max(12px, env(safe-area-inset-right));
            }

            .product-grid,
            .skeleton-grid {
                grid-template-columns: minmax(0, 1fr);
                gap: 18px;
                padding-top: 24px;
            }

            .product-card {
                min-height: 0;
            }

            .shop-header {
                padding: 30px 0 22px;
            }

            .shop-controls {
                padding: 12px;
                margin-bottom: 18px;
            }

            .shop-subtitle {
                line-height: 1.65;
            }

            .pagination {
                flex-wrap: nowrap;
                overflow-x: auto;
                overscroll-behavior-inline: contain;
                -webkit-overflow-scrolling: touch;
                justify-content: flex-start;
                gap: 8px;
                padding: 14px 12px 10px;
                margin-left: -12px;
                margin-right: -12px;
                scroll-snap-type: x proximity;
                scrollbar-width: thin;
            }

            .page-link {
                padding: 9px 14px;
                font-size: 0.68rem;
                min-height: 44px;
                flex: 0 0 auto;
                scroll-snap-align: center;
            }

            #backToTopBtn {
                width: 46px;
                height: 46px;
                font-size: 1.35rem;
            }
        }

        /* Small phones */
        @media (max-width: 480px) {
            .top-bar {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }

            .logo,
            .diamond-wordmark {
                font-size: clamp(0.78rem, 4.2vw, 0.95rem);
                letter-spacing: 0.08em;
            }

            .system-status {
                width: 100%;
                text-align: left;
                white-space: normal;
            }

            .nav-mobile-toggle {
                width: calc(100% - 24px);
                margin-inline: 12px;
            }

            .sub-nav {
                padding-inline: 12px;
            }

            .sub-nav .nav-links.open,
            .shop-controls-actions {
                grid-template-columns: 1fr;
            }

            .shop-title {
                font-size: clamp(1.85rem, 12vw, 3rem);
                letter-spacing: 0.02em;
            }

            .shop-status-label,
            .shop-subtitle,
            .shop-stats {
                letter-spacing: 0.07em;
            }

            .card-image {
                aspect-ratio: 4 / 5;
            }

            .stock-low,
            .sold-out,
            .featured-badge,
            .one-of-one-badge {
                padding: 4px 8px;
                font-size: 0.56rem;
                letter-spacing: 0.12em;
            }
        }

        /* Very narrow screens and 200% browser zoom */
        @media (max-width: 360px) {
            .shop-main,
            .sub-nav {
                padding-left: 10px;
                padding-right: 10px;
            }

            .nav-mobile-toggle {
                width: calc(100% - 20px);
                margin-left: 10px;
                margin-right: 10px;
            }

            .shop-controls {
                padding: 10px;
            }

            .shop-controls-stock,
            .shop-controls-btn,
            .shop-controls-clear,
            .shop-controls-select,
            .sector-select {
                font-size: 0.66rem;
                letter-spacing: 0.05em;
            }

            .card-title {
                white-space: normal;
                line-height: 1.2;
            }

            .pagination {
                margin-left: -10px;
                margin-right: -10px;
                padding-left: 10px;
                padding-right: 10px;
            }
        }

        /* Mobile landscape: prevent a sticky header from consuming the screen */
        @media (max-width: 900px) and (max-height: 500px) and (orientation: landscape) {
            .sticky-nav {
                position: relative;
            }

            html {
                scroll-padding-top: 20px;
            }

            .shop-header {
                padding-top: 24px;
            }
        }

        /* Touch devices do not have a reliable hover state */
        @media (hover: none) and (pointer: coarse) {
            .product-card:hover,
            .product-card:focus-within {
                transform: none;
            }

            .quick-view-overlay {
                opacity: 1;
                padding-bottom: 8px;
                font-size: 0.72rem;
            }

            .page-link:hover {
                transform: none;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .reveal-item, .product-card, .page-link, .stock-low, .tech-archive::before, .cart-updated, .product-img,
            .skeleton-img, .skeleton-text, .skeleton-price, .diamond-glow, .diamond-glow::after, .diamond-glow::before { animation: none !important; transition: none !important; }
        }
    </style>
<script src="/js/telemetry.js" defer></script>
</head>

<body class="tech-archive">
    <a href="#main-content" class="skip-link" tabindex="1">SKIP TO MAIN CONTENT</a>

    <header class="tactical-nav sticky-nav">
        <div class="top-bar">
            <div class="logo-area">
                <span class="status-dot" aria-hidden="true"></span>
                <a href="/" class="logo diamond-glow diamond-wordmark"><?= h(SITE_NAME) ?></a>
            </div>
            <div class="system-status">
                STATUS: ONLINE // ARCHIVE_<?= h(VERSION) ?>
            </div>
        </div>
        <button class="nav-mobile-toggle" id="shopNavToggle" type="button" aria-expanded="false" aria-controls="shopNavLinks" aria-label="Toggle shop navigation links">
            MENU
        </button>
        <nav class="sub-nav" id="shopSubNav" aria-label="Archive navigation">
            <div class="nav-group" aria-label="Category filter">
                <span class="nav-label">SECTOR:</span>
                <div class="sector-tabs" role="tablist" aria-label="Category tabs">
                    <?php foreach ($VALID_CATEGORIES as $cat):
                        $isActive = ($selectedCategory === $cat);
                        $href = buildUrlWithParams([
                            'category' => $cat,
                            'page' => 1,
                        ]);
                        $count = $categoryCounts[$cat] ?? 0;
                    ?>
                        <a href="<?= h($href) ?>"
                           class="nav-tab <?= $isActive ? 'active' : '' ?>"
                           aria-current="<?= $isActive ? 'page' : 'false' ?>">
                            <?= h($cat) ?> <span class="count">(<?= (int)$count ?>)</span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <label for="sector-select" class="sr-only">Choose sector</label>
                <select id="sector-select" class="sector-select" aria-label="Choose sector"
                        onchange="if (this.value) { window.location.href = this.value; }">
                    <?php foreach ($VALID_CATEGORIES as $cat):
                        $isActive = ($selectedCategory === $cat);
                        $href = buildUrlWithParams([
                            'category' => $cat,
                            'page' => 1,
                        ]);
                        $count = $categoryCounts[$cat] ?? 0;
                    ?>
                        <option value="<?= h($href) ?>" <?= $isActive ? 'selected' : '' ?>>
                            <?= h($cat) ?> (<?= (int)$count ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="nav-links" id="shopNavLinks">
                <a href="/" class="nav-link">HOME</a>
                <a href="/cart.php" id="cart-link" class="nav-link nav-cart">
                    BAG [<span id="cart-count"><?= (int)$cartCount ?></span>]
                </a>
            </div>
        </nav>
    </header>

    <main id="main-content" class="shop-main" tabindex="-1">

        <nav aria-label="Breadcrumb" class="breadcrumb-nav" style="margin-top:40px; margin-bottom:10px; font-family:'Space Mono', monospace; font-size:0.9rem;">
            <a href="/" style="color:var(--content-accent); text-decoration:none;">Home</a>
            <span style="color:var(--text-dim);"> / </span>
            <a href="/shop" style="color:var(--content-accent); text-decoration:none;">Shop</a>
            <?php if ($selectedCategory !== 'ALL'): ?>
                <span style="color:var(--text-dim);"> / </span>
                <span style="color:var(--text); text-transform:capitalize;"> <?= strtolower(h($selectedCategory)) ?> </span>
            <?php endif; ?>
        </nav>

        <form class="shop-controls" method="get" action="/shop" aria-label="Sort and filter products">
            <?php if ($selectedCategory !== 'ALL'): ?>
                <input type="hidden" name="category" value="<?= h($selectedCategory) ?>">
            <?php endif; ?>
            <div class="shop-controls-grid">
                <div class="shop-controls-group">
                    <label for="sort-select" class="shop-controls-label">Sort</label>
                    <select id="sort-select" name="sort" class="shop-controls-select">
                        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
                        <option value="price_low" <?= $sort === 'price_low' ? 'selected' : '' ?>>Price low-high</option>
                        <option value="price_high" <?= $sort === 'price_high' ? 'selected' : '' ?>>Price high-low</option>
                    </select>
                </div>

                <label class="shop-controls-stock">
                    <input type="checkbox" name="in_stock" value="1" <?= $onlyInStock ? 'checked' : '' ?>>
                    In-stock only
                </label>

                <div class="shop-controls-actions">
                    <button type="submit" class="shop-controls-btn">Apply</button>
                    <a class="shop-controls-clear" href="<?= h(buildUrlWithParams(['sort' => null, 'in_stock' => null, 'page' => 1])) ?>">Reset</a>
                </div>
            </div>
        </form>

        <?= renderPagination($page, $totalPages, $selectedCategory, ' style="margin-bottom:30px;"') ?>

        <header class="shop-header">
            <div class="shop-status-label">
                FILE: ARCHIVE_<?= h(VERSION) ?>
            </div>
            <h1 class="shop-title diamond-glow">
                LIVE_INVENTORY
            </h1>
            <p class="shop-subtitle">
                [[ <?= h(SITE_NAME) ?> DATABASE ]]
                <?php if ($selectedCategory !== 'ALL'): ?>
                    // FILTER_ACTIVE: <?= h($selectedCategory) ?>
                <?php endif; ?>
            </p>
            <p class="shop-stats">
                DISPLAYING <?= (int)count($products) ?> OF <?= (int)$totalProductsFiltered ?> ITEMS
            </p>

            <?php if ((int)$dbTotalAll > 0 && (int)$totalProductsFiltered === 0): ?>
                <div class="diag">
                    DIAGNOSTIC: DB has <?= (int)$dbTotalAll ?> products total, but this view returned 0.
                    Likely cause: category mismatch or blank categories.
                </div>
            <?php endif; ?>
        </header>

        <section class="lookbook-section">
            <button id="backToTopBtn" title="Back to top" aria-label="Back to top">▲</button>

            <div id="productGridContainer">
                <div class="skeleton-grid" id="skeletonGrid">
                    <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-card">
                            <div class="skeleton-img"></div>
                            <div class="skeleton-text"></div>
                            <div class="skeleton-price"></div>
                            <div class="skeleton-text" style="width:70%;"></div>
                        </div>
                    <?php endfor; ?>
                </div>

                <div class="product-grid" id="realProductGrid" style="display:none;">
                    <?php if (empty($products)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">🛒</div>
                            <div class="empty-title">No Products Found</div>
                            <div class="empty-desc">Try a different category or check back later.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($products as $row):
                            $pid = (int)($row['id'] ?? 0);
                            $slugRaw = isset($row['slug']) ? (string)$row['slug'] : '';
                            $rawImage = (string)($row['image_url'] ?? '');

                            $imgSrc = productImage($rawImage);
                            $placeholder = dodPlaceholder();

                            $v = assetVersion($imgSrc);
                            $imgSrcWithV = ($v !== '') ? ($imgSrc . (strpos($imgSrc, '?') === false ? '?' : '&') . 'v=' . rawurlencode($v)) : $imgSrc;

                            $price = formatPrice((float)($row['price'] ?? 0));
                            $name = strtoupper(h((string)($row['name'] ?? '')));
                            $category = strtoupper(h((string)($row['category'] ?? '')));
                            $stock = (int)($row['stock'] ?? 0);
                            $isOneOfOne = ($stock === 1);
                            $isLowStock = ($stock > 0 && $stock <= 5);
                            $isFeatured = isset($row['featured']) && (int)$row['featured'] === 1;
                            $description = h((string)($row['description'] ?? ''));
                            $sizes = h((string)($row['available_sizes'] ?? ''));

                            $productHref = getProductUrl($pid, $slugRaw);
                        ?>
                        <div class="reveal-item"
                             data-item-category="<?= $category ?>"
                             data-item-id="<?= $pid ?>"
                             data-item-stock="<?= $stock ?>">
                            <div class="product-card">
                                <a href="<?= h($productHref) ?>" class="card-image" tabindex="0" aria-label="View details for <?= $name ?>">
                                    <?php if ($isOneOfOne): ?>
                                        <div class="one-of-one-badge" aria-hidden="true">1 OF 1</div>
                                    <?php endif; ?>

                                    <?php if ($isLowStock): ?>
                                        <div class="stock-low" aria-hidden="true">LOW_STOCK (<?= $stock ?>)</div>
                                    <?php elseif ($stock < 1): ?>
                                        <div class="sold-out">SOLD_OUT</div>
                                    <?php endif; ?>

                                    <?php if ($isFeatured): ?>
                                        <div class="featured-badge<?= $isOneOfOne ? ' featured-shifted' : '' ?>">FEATURED</div>
                                    <?php endif; ?>

                                    <img src="<?= h($imgSrcWithV) ?>"
                                         data-fallback="<?= h($placeholder) ?>"
                                         onerror="this.onerror=null; this.src=this.getAttribute('data-fallback');"
                                         alt="<?= $name ?>"
                                         class="product-img"
                                         loading="lazy"
                                         decoding="async"
                                         width="320"
                                         height="400">

                                    <div class="quick-view-overlay">View Details</div>
                                </a>

                                <div class="card-info">
                                    <div class="card-title"><?= $name ?></div>
                                    <?php $review = $reviewMap[$pid] ?? null; if ($review !== null): ?>
                                        <div class="card-rating" aria-label="<?= number_format($review['avg'], 1) ?> out of 5 stars, <?= (int)$review['count'] ?> review<?= $review['count'] === 1 ? '' : 's' ?>">
                                            <?php
                                                $fullStars = (int)floor($review['avg']);
                                                $hasHalf = ($review['avg'] - $fullStars) >= 0.25 && ($review['avg'] - $fullStars) < 0.75;
                                                if (($review['avg'] - $fullStars) >= 0.75) $fullStars++;
                                                for ($s = 0; $s < 5; $s++):
                                                    if ($s < $fullStars) { echo '&#9733;'; }
                                                    elseif ($s === $fullStars && $hasHalf) { echo '&#189;&#9733;'; }
                                                    else { echo '&#9734;'; }
                                                endfor;
                                            ?>
                                            <span class="card-rating-count">(<?= (int)$review['count'] ?>)</span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="card-price">$<?= $price ?></div>

                                    <?php if ($sizes !== ''): ?>
                                        <div class="card-sizes">Sizes: <?= $sizes ?></div>
                                    <?php endif; ?>

                                    <?php if ($description !== ''): ?>
                                        <div class="card-desc"><?= $description ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?= renderPagination($page, $totalPages, $selectedCategory) ?>
        </section>
    </main>

    <script>
    (function() {
        'use strict';

        const CART_STALE_AFTER_MS = 15000;
        const CART_FALLBACK_POLL_MS = 30000;
        const ANALYTICS_ENDPOINT = '';
        let lastCartSignalAt = 0;

        function trackEvent(eventName, payload) {
            if (!eventName) {
                return;
            }

            const eventPayload = Object.assign({
                event: eventName,
                page: '/shop',
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

            if (ANALYTICS_ENDPOINT) {
                const body = JSON.stringify(eventPayload);
                if (navigator.sendBeacon) {
                    const blob = new Blob([body], { type: 'application/json' });
                    navigator.sendBeacon(ANALYTICS_ENDPOINT, blob);
                } else {
                    fetch(ANALYTICS_ENDPOINT, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body,
                        keepalive: true,
                        credentials: 'same-origin',
                    }).catch(() => {});
                }
            }
        }

        function setCartCount(nextCount) {
            const cartCount = document.getElementById('cart-count');
            if (!cartCount || !Number.isFinite(nextCount)) {
                return;
            }
            cartCount.textContent = String(Math.max(0, Math.trunc(nextCount)));
            cartCount.classList.add('cart-updated');
            setTimeout(() => cartCount.classList.remove('cart-updated'), 500);
        }

        function setupSkeletonSwap() {
            // Content is already fully server-rendered in the HTML by the time
            // this runs -- there's nothing to actually wait for. The previous
            // fixed 900ms delay was a pure, artificial tax on every page view,
            // felt worst on mobile connections where perceived speed matters
            // most. Swap on the next frame instead of introducing a fake wait.
            requestAnimationFrame(() => {
                const skeleton = document.getElementById('skeletonGrid');
                const real = document.getElementById('realProductGrid');
                if (skeleton) skeleton.style.display = 'none';
                if (real) real.style.display = '';
            });
        }

        function setupBackToTop() {
            const btn = document.getElementById('backToTopBtn');
            if (!btn) return;

            // Detect mobile
            const isMobile = window.matchMedia('(max-width: 768px)').matches;
            if (isMobile) {
                btn.style.display = 'none';
                btn.setAttribute('tabindex', '-1');
                return;
            }

            const onScroll = () => {
                if (window.scrollY > 300) btn.classList.add('visible');
                else btn.classList.remove('visible');
            };
            window.addEventListener('scroll', onScroll, { passive: true });
            onScroll();

            btn.addEventListener('click', (e) => {
                e.preventDefault();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }

        function setupRevealObserver() {
            const items = document.querySelectorAll('.reveal-item');
            if (!items.length) {
                return;
            }
            if (!('IntersectionObserver' in window)) {
                items.forEach((el) => el.classList.add('revealed'));
                return;
            }

            const observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('revealed');
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.1 });

            items.forEach((el) => observer.observe(el));
        }

        function setupImageDrivenAccents() {
            const cards = document.querySelectorAll('.product-card');
            if (!cards.length) {
                return;
            }

            function clampColor(value) {
                return Math.max(0, Math.min(255, Math.round(value)));
            }

            function applyAccentFromImage(card, img) {
                if (!img || !img.naturalWidth || !img.naturalHeight) {
                    return;
                }

                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d', { willReadFrequently: true });
                if (!ctx) {
                    return;
                }

                const sampleSize = 28;
                canvas.width = sampleSize;
                canvas.height = sampleSize;

                try {
                    ctx.drawImage(img, 0, 0, sampleSize, sampleSize);
                    const { data } = ctx.getImageData(0, 0, sampleSize, sampleSize);

                    let sumR = 0;
                    let sumG = 0;
                    let sumB = 0;
                    let count = 0;

                    for (let i = 0; i < data.length; i += 4) {
                        const r = data[i];
                        const g = data[i + 1];
                        const b = data[i + 2];
                        const a = data[i + 3];
                        if (a < 120) continue;

                        const max = Math.max(r, g, b);
                        const min = Math.min(r, g, b);
                        const chroma = max - min;
                        const luminance = (0.2126 * r) + (0.7152 * g) + (0.0722 * b);

                        // Favor saturated, visible pixels.
                        if (chroma < 16 || luminance < 35 || luminance > 230) continue;

                        sumR += r;
                        sumG += g;
                        sumB += b;
                        count++;
                    }

                    if (count < 8) {
                        return;
                    }

                    const baseR = sumR / count;
                    const baseG = sumG / count;
                    const baseB = sumB / count;

                    const accentR = clampColor((baseR * 0.82) + (255 * 0.18));
                    const accentG = clampColor((baseG * 0.82) + (255 * 0.18));
                    const accentB = clampColor((baseB * 0.82) + (255 * 0.18));

                    card.style.setProperty('--card-accent', `rgb(${accentR} ${accentG} ${accentB})`);
                    card.style.setProperty('--card-accent-soft', `rgba(${accentR}, ${accentG}, ${accentB}, 0.72)`);
                    card.style.setProperty('--card-accent-glow', `rgba(${accentR}, ${accentG}, ${accentB}, 0.35)`);
                } catch (_err) {
                    // Cross-origin images can block canvas reads; keep fallback accent.
                }
            }

            cards.forEach((card) => {
                const img = card.querySelector('.product-img');
                if (!img) {
                    return;
                }

                if (img.complete) {
                    applyAccentFromImage(card, img);
                } else {
                    img.addEventListener('load', () => applyAccentFromImage(card, img), { once: true });
                }
            });
        }

        function setupShopMobileNav() {
            const navToggle = document.getElementById('shopNavToggle');
            const nav = document.getElementById('shopSubNav');
            const navLinks = document.getElementById('shopNavLinks');
            if (!navToggle || !nav || !navLinks) {
                return;
            }

            const mobileQuery = window.matchMedia('(max-width: 768px)');

            const closeNav = () => {
                navLinks.classList.remove('open');
                navToggle.setAttribute('aria-expanded', 'false');
            };

            navToggle.addEventListener('click', () => {
                const willOpen = !navLinks.classList.contains('open');
                navLinks.classList.toggle('open', willOpen);
                navToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });

            navLinks.querySelectorAll('a').forEach((link) => {
                link.addEventListener('click', () => {
                    if (mobileQuery.matches) {
                        closeNav();
                    }
                });
            });

            document.addEventListener('click', (e) => {
                if (!mobileQuery.matches || !navLinks.classList.contains('open')) {
                    return;
                }
                if (nav.contains(e.target) || navToggle.contains(e.target)) {
                    return;
                }
                closeNav();
            });

            window.addEventListener('resize', () => {
                if (!mobileQuery.matches) {
                    closeNav();
                }
            }, { passive: true });
        }

        function setupAnalyticsHooks() {
            const grid = document.getElementById('realProductGrid');
            if (grid) {
                grid.addEventListener('click', (e) => {
                    const link = e.target.closest('.card-image[href]');
                    if (!link) {
                        return;
                    }
                    const item = link.closest('.reveal-item');
                    trackEvent('product_card_click', {
                        product_id: item ? item.getAttribute('data-item-id') : null,
                        category: item ? item.getAttribute('data-item-category') : null,
                        stock: item ? item.getAttribute('data-item-stock') : null,
                        href: link.getAttribute('href') || '',
                    });
                });
            }

            const filterForm = document.querySelector('form.shop-controls');
            if (filterForm) {
                const sortEl = filterForm.querySelector('#sort-select');
                const stockEl = filterForm.querySelector('input[name="in_stock"]');

                if (sortEl) {
                    sortEl.addEventListener('change', () => {
                        trackEvent('shop_filter_change', {
                            control: 'sort',
                            value: sortEl.value || 'newest',
                            in_stock: stockEl ? !!stockEl.checked : false,
                        });
                    });
                }

                if (stockEl) {
                    stockEl.addEventListener('change', () => {
                        trackEvent('shop_filter_change', {
                            control: 'in_stock',
                            value: stockEl.checked ? '1' : '0',
                            sort: sortEl ? (sortEl.value || 'newest') : 'newest',
                        });
                    });
                }

                filterForm.addEventListener('submit', () => {
                    trackEvent('shop_filter_apply', {
                        sort: sortEl ? (sortEl.value || 'newest') : 'newest',
                        in_stock: stockEl ? !!stockEl.checked : false,
                    });
                });
            }

            document.querySelectorAll('.pagination .page-link[href]').forEach((link) => {
                link.addEventListener('click', () => {
                    trackEvent('shop_pagination_click', {
                        label: (link.textContent || '').trim(),
                        href: link.getAttribute('href') || '',
                        aria_label: link.getAttribute('aria-label') || '',
                    });
                });
            });

            document.addEventListener('click', (e) => {
                const quickAdd = e.target.closest('.quick-add-btn, [data-action="quick-add"], [data-analytics="quick-add"]');
                if (!quickAdd) {
                    return;
                }
                const item = quickAdd.closest('.reveal-item');
                trackEvent('quick_add_click', {
                    product_id: item ? item.getAttribute('data-item-id') : null,
                    category: item ? item.getAttribute('data-item-category') : null,
                });
            });

            window.addEventListener('dod:quick-add', (e) => {
                const detail = e && e.detail ? e.detail : {};
                trackEvent('quick_add', detail);
            });
        }

        function setupCartSync() {
            function applyCartEventCount(e) {
                const detail = e && e.detail ? e.detail : null;
                const rawCount = detail && typeof detail.count !== 'undefined'
                    ? detail.count
                    : (detail && detail.totals ? detail.totals.total_qty : null);
                const parsed = Number(rawCount);
                if (Number.isFinite(parsed)) {
                    setCartCount(parsed);
                    lastCartSignalAt = Date.now();
                }
            }

            function fetchCartState() {
                return fetch('/cart.php?action=get_cart_state&ajax=1', {
                    credentials: 'same-origin',
                    cache: 'no-store',
                })
                    .then((response) => response.json())
                    .then((data) => {
                        if (data && data.ok && data.totals && typeof data.totals.total_qty !== 'undefined') {
                            const parsed = Number(data.totals.total_qty);
                            if (Number.isFinite(parsed)) {
                                setCartCount(parsed);
                                lastCartSignalAt = Date.now();
                            }
                        }
                    })
                    .catch(() => {});
            }

            window.addEventListener('cartUpdated', applyCartEventCount);
            window.addEventListener('dod:cart:updated', applyCartEventCount);

            setTimeout(() => {
                if (Date.now() - lastCartSignalAt > CART_STALE_AFTER_MS) {
                    fetchCartState();
                }
            }, 2000);

            setInterval(() => {
                if (Date.now() - lastCartSignalAt > CART_STALE_AFTER_MS) {
                    fetchCartState();
                }
            }, CART_FALLBACK_POLL_MS);

            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible' && Date.now() - lastCartSignalAt > CART_STALE_AFTER_MS) {
                    fetchCartState();
                }
            });
        }

        function init() {
            setupShopMobileNav();
            setupSkeletonSwap();
            setupBackToTop();
            setupRevealObserver();
            setupImageDrivenAccents();
            setupAnalyticsHooks();
            setupCartSync();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }
    })();
    </script>

    <script src="/js/cart.js" defer></script>
    <script src="/js/cookie-consent-global.js" defer></script>
    <script src="/js/welcome-offer.js" defer></script>
</body>
</html>