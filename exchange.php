<?php declare(strict_types=1);

/**
 * exchange.php — THE EXCHANGE (Market Terminal v1.0)
 * Path: /home2/asqrtyte/public_html/exchange.php
 *
 * A Grand-Exchange-styled browsing layer over the real product catalog and
 * cart. Item "slots" instead of product cards, a live trade ticker, and an
 * offer panel with a Guide Price + price-history chart built from REAL
 * completed orders (orders + order_items) -- not synthetic data.
 *
 * IMPORTANT -- what this is NOT:
 * - There is no peer-to-peer trading. "Offers" fill at the real listed
 *   price and add to the normal cart via /api/cart-add.php. No user-set
 *   pricing, no escrow, no liability beyond the existing checkout flow.
 *
 * Reuses image/url helpers from shop.php's conventions for consistency.
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

    $csp = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline'",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
        "font-src 'self' https://fonts.gstatic.com data:",
        "img-src 'self' data: https: http:",
        "media-src 'self' https: http:",
        "connect-src 'self' https: http:",
        "frame-ancestors 'self'",
        "base-uri 'self'",
        "form-action 'self'",
    ];
    header("Content-Security-Policy: " . implode('; ', $csp));
}

require_once __DIR__ . '/db_connect.php';
@include_once __DIR__ . '/includes/track_visit.php';

// Background settings
$dodBgType = 'dark'; $dodBgValue = ''; $dodBgOverlay = 0.5;
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $bgS = $pdo->prepare("SELECT bg_type, bg_value, bg_overlay FROM page_settings WHERE page_slug = 'exchange' LIMIT 1");
        $bgS->execute(); $bgR = $bgS->fetch(PDO::FETCH_ASSOC);
        if ($bgR) { $dodBgType = (string)$bgR['bg_type']; $dodBgValue = (string)($bgR['bg_value'] ?? ''); $dodBgOverlay = (float)$bgR['bg_overlay']; }
    } catch (Throwable $e) { /* ignore */ }
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>EXCHANGE_OFFLINE | DIAMONDS OUTTA DIRT</title>';
    echo '<style>body{margin:0;background:#000;color:#fff;font-family:ui-monospace,Menlo,Consolas,monospace;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px} .box{max-width:720px;border:1px solid #333;background:#050505;padding:22px;border-radius:10px} .h{color:#00ff9d;letter-spacing:2px;font-weight:800;margin:0 0 10px} .p{color:#bbb;line-height:1.5;margin:0 0 10px} a{color:#00ff9d}</style></head><body><div class="box"><div class="h">EXCHANGE_OFFLINE</div><p class="p">The Exchange can\'t open right now because the database connection isn\'t available.</p><p class="p">Check <code>db_connect.php</code> credentials + HostGator MySQL status, then refresh.</p><p class="p"><a href="/">Return Home</a></p></div></body></html>';
    exit;
}

const SITE_NAME = 'DIAMONDS OUTTA DIRT';
const EX_VERSION = 'v1.0';
const EX_TRADE_WINDOW_DAYS = 90;
const EX_COMPLETED_STATUSES = ['PAID', 'SHIPPED', 'COMPLETED', 'DELIVERED'];

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
if (!function_exists('dod_current_scheme_is_https')) {
    function dod_current_scheme_is_https(): bool {
        return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
            || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
            || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on');
    }
}
if (!function_exists('dod_encode_path')) {
    function dod_encode_path(string $path): string {
        $path = str_replace('\\', '/', $path);
        $parts = explode('/', $path);
        foreach ($parts as $i => $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') continue;
            $parts[$i] = rawurlencode($seg);
        }
        return implode('/', $parts);
    }
}
if (!function_exists('dod_local_exists')) {
    function dod_local_exists(string $webPath): ?bool {
        $webPath = trim($webPath);
        if ($webPath === '' || $webPath[0] !== '/') return null;
        $docroot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        $docroot = rtrim(str_replace('\\', '/', $docroot), '/');
        if ($docroot === '') return null;
        $fs = $docroot . $webPath;
        return @is_file($fs);
    }
}
if (!function_exists('dodPlaceholder')) {
    function dodPlaceholder(): string {
        $svg = rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="640">'
            . '<rect width="100%" height="100%" fill="#0a0a0f"/>'
            . '<text x="50%" y="46%" fill="#00ff9d" font-size="20" text-anchor="middle" font-family="monospace">DIAMONDS OUTTA DIRT</text>'
            . '<text x="50%" y="54%" fill="#00ff9d" font-size="13" text-anchor="middle" font-family="monospace">EXCHANGE ITEM</text>'
            . '</svg>'
        );
        return 'data:image/svg+xml;charset=UTF-8,' . $svg;
    }
}
if (!function_exists('productImage')) {
    function productImage(string $path): string {
        $path = trim($path);
        if ($path === '') return dodPlaceholder();
        if (dod_starts_with($path, 'data:')) return $path;

        if (preg_match('#^//#', $path)) {
            $scheme = dod_current_scheme_is_https() ? 'https:' : 'http:';
            $path = $scheme . $path;
        }
        if (preg_match('#^https?://#i', $path)) {
            if (dod_current_scheme_is_https() && preg_match('#^http://#i', $path)) {
                $path = preg_replace('#^http://#i', 'https://', $path);
            }
            return $path;
        }

        $p = str_replace('\\', '/', $path);
        $pubPos = strpos($p, '/public_html/');
        if ($pubPos !== false) {
            $p = substr($p, $pubPos + strlen('/public_html'));
        } else {
            $markers = ['/admin/uploads/', '/uploads/', '/images/', '/assets/', '/img/'];
            $bestPos = null;
            foreach ($markers as $m) {
                $pos = strpos($p, $m);
                if ($pos !== false && ($bestPos === null || $pos < $bestPos)) $bestPos = $pos;
            }
            if ($bestPos !== null) $p = substr($p, (int)$bestPos);
        }

        $p = '/' . ltrim($p, '/');
        $markers2 = ['/admin/uploads/', '/uploads/', '/images/'];
        foreach ($markers2 as $m) {
            $pos = strpos($p, $m);
            if ($pos !== false) { $p = substr($p, $pos); break; }
        }
        $p = '/' . ltrim($p, '/');
        $p = dod_encode_path($p);

        $exists = dod_local_exists($p);
        if ($exists === true) return $p;

        $alts = [];
        if (dod_starts_with($p, '/uploads/')) $alts[] = '/admin' . $p;
        elseif (dod_starts_with($p, '/admin/uploads/')) $alts[] = substr($p, strlen('/admin'));

        foreach ($alts as $alt) {
            $alt = dod_encode_path('/' . ltrim($alt, '/'));
            if (dod_local_exists($alt) === true) return $alt;
        }

        if ($exists === null) return $p;
        return dodPlaceholder();
    }
}
if (!function_exists('formatPrice')) {
    function formatPrice(float $price): string {
        return number_format($price, 2, '.', ',');
    }
}
if (!function_exists('getProductUrl')) {
    function getProductUrl(int $id, ?string $slug = null): string {
        $id = max(0, $id);
        $slug = is_string($slug) ? trim($slug) : '';
        if ($slug !== '') return '/product/' . rawurlencode($slug);
        return '/product/' . $id;
    }
}
function ex_table_exists(PDO $pdo, string $table): bool {
    // PATCH: SHOW TABLES LIKE :param fails silently with named params when
    // PDO::ATTR_EMULATE_PREPARES is false (the same bug found and fixed in
    // sellers.php/offers.php earlier). INFORMATION_SCHEMA works reliably.
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
        $stmt->execute([$table]);
        return (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}
function ex_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}
function ex_time_ago(string $datetime): string {
    $ts = strtotime($datetime);
    if ($ts === false) return '';
    $diff = max(0, time() - $ts);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return (int)floor($diff / 60) . 'm ago';
    if ($diff < 86400) return (int)floor($diff / 3600) . 'h ago';
    return (int)floor($diff / 86400) . 'd ago';
}

/* -----------------------------------------------------------------------
   QUERY: PRODUCTS (item slots)
----------------------------------------------------------------------- */
$products = [];
$guideMap = [];
$lastSoldMap = [];
$sparklineMap = [];
$ticker = [];
$dbError = false;
$acceptedOffers = []; // product_id -> expires_at for ACCEPTED pending offers
$activeBidsPanel = []; // enriched list for the customer-facing bids panel

try {
    $slugColumnExists = false;
    try {
        $checkSlug = $pdo->query("SHOW COLUMNS FROM products LIKE 'slug'");
        $slugColumnExists = (bool)$checkSlug->fetch();
    } catch (Throwable $e) { /* ignore */ }

    $productImagesTableExists = ex_table_exists($pdo, 'product_images');

    $imageJoinSql = '';
    $imageSelectSql = 'p.image_url AS image_url';
    if ($productImagesTableExists) {
        $imageJoinSql = "
            LEFT JOIN product_images pi
                ON pi.id = (
                    SELECT pi2.id FROM product_images pi2
                    WHERE pi2.product_id = p.id
                      AND pi2.image_url IS NOT NULL AND pi2.image_url != ''
                    ORDER BY pi2.position ASC, pi2.id ASC LIMIT 1
                )
        ";
        $imageSelectSql = "COALESCE(NULLIF(TRIM(p.image_url), ''), pi.image_url) AS image_url";
    }
    $slugSelectSql = $slugColumnExists ? 'p.slug AS slug' : 'NULL AS slug';

    $sellersTableExists = ex_table_exists($pdo, 'sellers');
    $sellerJoinSql = '';
    $sellerSelectSql = 'NULL AS seller_name';
    if ($sellersTableExists && ex_column_exists($pdo, 'products', 'seller_id')) {
        $sellerJoinSql = "LEFT JOIN sellers sl ON sl.id = p.seller_id AND sl.is_active = 1";
        $sellerSelectSql = 'sl.name AS seller_name';
    }

    $sql = "
        SELECT p.id, $slugSelectSql, p.name, p.price, p.stock, p.category, $imageSelectSql, p.description, p.accepts_offers, p.min_offer_price, COALESCE(p.show_on_exchange, 1) AS show_on_exchange, $sellerSelectSql
        FROM products p
        $imageJoinSql
        $sellerJoinSql
        ORDER BY p.category ASC, p.name ASC
        LIMIT 240
    ";
    // Non-admins only see items listed on the Exchange
    if (!$isAdmin) {
        $sql = str_replace(
            'ORDER BY p.category ASC',
            'WHERE COALESCE(p.show_on_exchange, 1) = 1 ORDER BY p.category ASC',
            $sql
        );
    }
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $row) {
        $products[] = $row;
    }

    /* GUIDE PRICE + TRADE COUNT per product, from real completed orders */
    if (ex_table_exists($pdo, 'orders') && ex_table_exists($pdo, 'order_items')) {
        $statusPlaceholders = implode(',', array_fill(0, count(EX_COMPLETED_STATUSES), '?'));
        $guideSql = "
            SELECT oi.product_id, AVG(oi.price) AS guide_price, COUNT(*) AS trade_count
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            WHERE o.order_status IN ($statusPlaceholders)
              AND o.created_at >= (NOW() - INTERVAL " . EX_TRADE_WINDOW_DAYS . " DAY)
            GROUP BY oi.product_id
        ";
        $guideStmt = $pdo->prepare($guideSql);
        $guideStmt->execute(EX_COMPLETED_STATUSES);
        $guideRows = $guideStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($guideRows as $gr) {
            $pid = (int)$gr['product_id'];
            $guideMap[$pid] = [
                'guide_price' => round((float)$gr['guide_price'], 2),
                'trade_count' => (int)$gr['trade_count']
            ];
        }

        /* LAST SOLD DATE per product (single batch query, not per-slot) */
        $lastSoldMap = [];
        $lastSoldSql = "
            SELECT oi.product_id, MAX(o.created_at) AS last_sold_at
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            WHERE o.order_status IN ($statusPlaceholders)
            GROUP BY oi.product_id
        ";
        $lastSoldStmt = $pdo->prepare($lastSoldSql);
        $lastSoldStmt->execute(EX_COMPLETED_STATUSES);
        foreach ($lastSoldStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $lsRow) {
            $lastSoldMap[(int)$lsRow['product_id']] = (string)$lsRow['last_sold_at'];
        }

        /* SPARKLINE: daily avg price per product, last 14 days (single batch query) */
        $sparklineMap = [];
        $sparkSql = "
            SELECT oi.product_id, DATE(o.created_at) AS day, AVG(oi.price) AS avg_price
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            WHERE o.order_status IN ($statusPlaceholders)
              AND o.created_at >= (NOW() - INTERVAL 14 DAY)
            GROUP BY oi.product_id, DATE(o.created_at)
            ORDER BY oi.product_id ASC, day ASC
        ";
        $sparkStmt = $pdo->prepare($sparkSql);
        $sparkStmt->execute(EX_COMPLETED_STATUSES);
        foreach ($sparkStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $spRow) {
            $spPid = (int)$spRow['product_id'];
            if (!isset($sparklineMap[$spPid])) $sparklineMap[$spPid] = [];
            $sparklineMap[$spPid][] = round((float)$spRow['avg_price'], 2);
        }

        /* RECENT TRADES TICKER */
        $tickerSql = "
            SELECT oi.product_name, oi.price, oi.quantity, o.created_at
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            WHERE o.order_status IN ($statusPlaceholders)
            ORDER BY o.created_at DESC, o.id DESC
            LIMIT 20
        ";
        $tickerStmt = $pdo->prepare($tickerSql);
        $tickerStmt->execute(EX_COMPLETED_STATUSES);
        $ticker = $tickerStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    // Active accepted offers -- drives the urgency timer on slots, and the
    // customer-facing "Currently Being Bid On" slide-out panel
    if (!$dbError) {
        try {
            $aoStmt = $pdo->query("
                SELECT o.product_id, o.expires_at, o.offered_price,
                       p.name AS product_name, p.image_url, p.slug
                FROM offers o
                JOIN products p ON p.id = o.product_id
                WHERE o.status = 'ACCEPTED' AND o.expires_at > NOW()
                ORDER BY o.expires_at ASC
            ");
            $aoRows = $aoStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($aoRows as $aoRow) {
                $aoPid = (int)$aoRow['product_id'];
                $acceptedOffers[$aoPid] = (string)$aoRow['expires_at'];

                $aoImg = (string)($aoRow['image_url'] ?? '');
                if ($aoImg !== '' && !preg_match('#^https?://#i', $aoImg)) {
                    $aoImg = '/' . ltrim($aoImg, '/');
                }
                $aoSlug = trim((string)($aoRow['slug'] ?? ''));

                $activeBidsPanel[] = [
                    'product_id' => $aoPid,
                    'name' => (string)$aoRow['product_name'],
                    'price' => (float)$aoRow['offered_price'],
                    'expires_at' => (string)$aoRow['expires_at'],
                    'image' => $aoImg !== '' ? $aoImg : '/images/placeholder.jpg',
                    'href' => $aoSlug !== '' ? '/product/' . rawurlencode($aoSlug) : '/product/' . $aoPid,
                ];
            }
        } catch (Throwable $e) { /* offers table may not exist yet, ignore */ }
    }
} catch (Throwable $e) {
    error_log('[EXCHANGE] ' . $e->getMessage());
    $dbError = true;
}

/* Categories for sector tabs */
$categories = ['ALL'];
foreach ($products as $p) {
    $c = strtoupper(trim((string)($p['category'] ?? '')));
    if ($c !== '' && !in_array($c, $categories, true)) $categories[] = $c;
}

/* Cart badge (read-only, mirrors header.php logic) */
$cartCount = 0;
$isAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

// Pre-load the logged-in customer's watchlist product IDs (reuses the
// existing wishlists table/toggle endpoint) so the modal's watch button
// can show correct state instantly without an API call per item opened.
$customerWatchlistIds = [];
if (!empty($_SESSION['customer_id']) && ex_table_exists($pdo, 'wishlists')) {
    try {
        $wlStmt = $pdo->prepare("SELECT product_id FROM wishlists WHERE customer_id = ?");
        $wlStmt->execute([(int)$_SESSION['customer_id']]);
        $customerWatchlistIds = array_map('intval', $wlStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) { /* non-fatal */ }
}
if (isset($_SESSION['cart']['items']) && is_array($_SESSION['cart']['items'])) {
    foreach ($_SESSION['cart']['items'] as $item) {
        if (is_array($item)) $cartCount += max(0, (int)($item['quantity'] ?? 0));
    }
} elseif (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        if (is_array($item)) $cartCount += max(0, (int)($item['quantity'] ?? 0));
        elseif (is_numeric($item)) $cartCount += (int)$item;
    }
}
?>
<!doctype html>
<html lang="en" class="tech-archive">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>THE EXCHANGE | <?= h(SITE_NAME) ?></title>
<?php if (is_file(__DIR__ . '/includes/tracking_pixels.php')) { require_once __DIR__ . '/includes/tracking_pixels.php'; } ?>
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#000000">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="DOD">
<script src="/js/pwa-init.js" defer></script>
<meta name="description" content="The Diamonds Outta Dirt Exchange -- live market terminal for the full archive, with real guide prices built from actual sales.">
<link rel="stylesheet" href="/css/master.css">
<?php
$_exBgInner = '';
switch ($dodBgType) {
    case 'grid':
        $_exBgInner = '<div style="position:absolute;inset:0;background:#000;"></div>'
            . '<div style="position:absolute;inset:0;background-image:linear-gradient(rgba(0,255,157,0.08) 1px,transparent 1px),linear-gradient(90deg,rgba(0,255,157,0.08) 1px,transparent 1px);background-size:50px 50px;"></div>';
        break;
    case 'gradient':
        $_exBgInner = '<div style="position:absolute;inset:0;background:radial-gradient(circle at 20% 20%,rgba(0,255,157,0.18),transparent 50%),radial-gradient(circle at 80% 30%,rgba(255,0,157,0.18),transparent 45%),radial-gradient(circle at 50% 80%,rgba(0,243,255,0.18),transparent 55%),#000;"></div>';
        break;
    case 'dark':
        $_exBgInner = '<div style="position:absolute;inset:0;background:#000;"></div>';
        break;
    case 'color':
        if ($dodBgValue !== '') {
            $_sc = htmlspecialchars(preg_replace('/[^a-fA-F0-9#()%., ]/','',$dodBgValue),ENT_QUOTES,'UTF-8');
            $_exBgInner = '<div style="position:absolute;inset:0;background:'.$_sc.';"></div>';
        }
        break;
    case 'image':
        if ($dodBgValue !== '') {
            $_su = htmlspecialchars($dodBgValue,ENT_QUOTES,'UTF-8');
            $_exBgInner = '<div style="position:absolute;inset:0;background:url('.$_su.') center/cover no-repeat;"></div>'
                . '<div style="position:absolute;inset:0;background:#000;opacity:'.$dodBgOverlay.';"></div>';
        }
        break;
    case 'video':
        if ($dodBgValue !== '') {
            $_su = htmlspecialchars($dodBgValue,ENT_QUOTES,'UTF-8');
            $_exBgInner = '<video autoplay muted loop playsinline style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:'.(1-$dodBgOverlay).';">'
                . '<source src="'.$_su.'"></video>'
                . '<div style="position:absolute;inset:0;background:#000;opacity:'.$dodBgOverlay.';"></div>';
        }
        break;
}
echo '<div id="dod-bg-layer" aria-hidden="true" style="position:fixed;inset:0;z-index:0;overflow:hidden;pointer-events:none;">'.$_exBgInner.'</div>';
?>
<link rel="stylesheet" href="/css/navigation.css">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Inter:wght@300;400;700;900&family=Space+Mono:wght@400;700&family=Syncopate:wght@400;700;900&display=swap" rel="stylesheet">
<style>
:root{
    --ex-bg:#040506;
    --ex-panel:#0a0b0d;
    --ex-panel2:#0d0f12;
    --ex-border:#23262b;
    --ex-border-bright:#3a3f47;
    --ex-up: var(--neon, #00ff9d);
    --ex-down:#ff4d4d;
    --ex-flat:#888;
    --ex-gold:#ffaa00;
}
*{box-sizing:border-box;}
body.tech-archive{ background:var(--ex-bg); color:#fff; font-family:'Inter',sans-serif; margin:0; }
.skip-link{ position:absolute; left:-9999px; top:0; background:var(--neon); color:#000; padding:8px 14px; z-index:999; font-family:'Space Mono',monospace; font-weight:700; }
.skip-link:focus{ left:10px; top:10px; }

.ex-header{
    position:sticky; top:0; z-index:50;
    background:rgba(4,5,6,0.92); backdrop-filter:blur(8px);
    border-bottom:1px solid var(--ex-border);
    padding:14px 5%;
    display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;
}
.ex-logo{ display:flex; align-items:center; gap:10px; text-decoration:none; }
.ex-logo-img{ height:34px; width:auto; display:block; flex-shrink:0; }
.ex-logo .dot{ width:8px; height:8px; border-radius:50%; background:var(--ex-up); box-shadow:0 0 10px var(--ex-up); animation:ex-pulse 1.6s ease-in-out infinite; }
@keyframes ex-pulse{ 0%,100%{opacity:.5;} 50%{opacity:1;} }
.ex-logo .word{ font-family:'Syncopate',sans-serif; font-size:.78rem; letter-spacing:3px; color:#fff; font-weight:700; }
.ex-title{ font-family:'Cormorant Garamond',serif; font-size:1.5rem; font-weight:600; color:var(--ex-up); letter-spacing:1px; margin-left:6px; }
.ex-nav-links{ display:flex; align-items:center; gap:18px; font-family:'Space Mono',monospace; font-size:.72rem; letter-spacing:1px; }
.ex-nav-links a{ color:#ccc; text-decoration:none; border-bottom:1px solid transparent; padding-bottom:2px; }
.ex-nav-links a:hover, .ex-nav-links a:focus-visible{ color:var(--ex-up); border-color:var(--ex-up); }
.ex-bag{ color:var(--ex-up) !important; }

.ex-ticker-wrap{
    border-bottom:1px solid var(--ex-border);
    background:#060708;
    overflow:hidden;
    position:relative;
    height:34px;
    display:flex; align-items:center;
}
.ex-ticker-label{
    flex:0 0 auto; padding:0 14px; height:100%; display:flex; align-items:center;
    background:var(--ex-up); color:#000; font-family:'Space Mono',monospace; font-weight:700;
    font-size:.65rem; letter-spacing:2px; z-index:2;
}
.ex-ticker-track{ display:flex; align-items:center; white-space:nowrap; animation:ex-scroll 38s linear infinite; }
.ex-ticker-wrap:hover .ex-ticker-track{ animation-play-state:paused; }
@keyframes ex-scroll{ 0%{ transform:translateX(0);} 100%{ transform:translateX(-50%);} }
.ex-tick{ display:inline-flex; align-items:center; gap:8px; padding:0 22px; font-family:'Space Mono',monospace; font-size:.68rem; color:#bbb; border-right:1px solid var(--ex-border); }
.ex-tick .nm{ color:#fff; }
.ex-tick .px{ color:var(--ex-up); }
.ex-tick .tm{ color:#666; }
.ex-ticker-empty{ padding:0 16px; font-family:'Space Mono',monospace; font-size:.68rem; color:#666; letter-spacing:1px; }
@media (prefers-reduced-motion: reduce){ .ex-ticker-track{ animation:none; overflow-x:auto; } }

.ex-main{ max-width:1280px; margin:0 auto; padding:28px 5% 100px; }
.ex-intro{ margin-bottom:22px; }
.ex-intro-top{ display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; position:relative; z-index:999990; }
.ex-intro h1{ font-family:'Cormorant Garamond',serif; font-size:2.2rem; margin:0 0 6px; color:#fff; }
.ex-intro p{ font-family:'Space Mono',monospace; font-size:.78rem; color:#999; margin:0; letter-spacing:.5px; }
.ex-help-btn{
    font-family:'Space Mono',monospace; font-size:.66rem; letter-spacing:1px; color:var(--ex-up);
    background:rgba(0,255,157,0.06); border:1px solid rgba(0,255,157,0.3); border-radius:4px;
    padding:8px 14px; cursor:pointer; white-space:nowrap; transition:background .15s ease;
    position:relative; z-index:999991; display:inline-block; opacity:1; visibility:visible;
    -webkit-appearance:none; appearance:none;
}
.ex-help-btn:hover{ background:rgba(0,255,157,0.14); }

/* Active Bids slide-out tab + panel */
.ex-bids-tab{
    position:fixed; top:50%; right:0; transform:translateY(-50%) rotate(0deg);
    z-index:999997; background:#0a0a0a; border:1px solid var(--ex-border); border-right:none;
    border-radius:6px 0 0 6px; padding:12px 10px; cursor:pointer;
    display:flex; flex-direction:column; align-items:center; gap:8px;
    font-family:'Space Mono',monospace; transition:right .25s ease, background .15s ease;
    box-shadow:-4px 0 16px rgba(0,0,0,0.4);
    -webkit-user-select:none; user-select:none; -webkit-touch-callout:none;
    touch-action:manipulation;
}
.ex-bids-tab:hover{ background:#111; }
.ex-bids-tab.panel-open{ right:340px; }
.ex-bids-tab-label{
    writing-mode:vertical-rl; text-orientation:mixed; font-size:.62rem; letter-spacing:2px;
    color:var(--ex-up); white-space:nowrap;
    -webkit-user-select:none; user-select:none; pointer-events:none;
}
.ex-bids-tab-count{
    background:var(--ex-up); color:#000; font-size:.62rem; font-weight:700;
    border-radius:999px; min-width:18px; height:18px; display:flex; align-items:center; justify-content:center;
    padding:0 4px;
    -webkit-user-select:none; user-select:none; pointer-events:none;
}

.ex-bids-panel{
    position:fixed; top:0; right:-340px; width:340px; height:100vh; z-index:999998;
    background:#050505; border-left:1px solid var(--ex-border);
    box-shadow:-8px 0 30px rgba(0,0,0,0.5); transition:right .25s ease;
    display:flex; flex-direction:column;
}
.ex-bids-panel.open{ right:0; }
.ex-bids-panel-head{
    display:flex; justify-content:space-between; align-items:center; gap:10px;
    padding:16px 16px; border-bottom:1px solid #1a1a1a;
    font-family:'Space Mono',monospace; font-size:.64rem; letter-spacing:1px; color:#999;
}
.ex-bids-close{
    background:none; border:1px solid #333; color:#999; width:28px; height:28px; border-radius:50%;
    cursor:pointer; font-size:1rem; line-height:1; flex-shrink:0;
}
.ex-bids-close:hover{ border-color:var(--ex-up); color:var(--ex-up); }
.ex-bids-list{ flex:1; overflow-y:auto; padding:10px; }
.ex-bids-empty{ padding:30px 16px; color:#666; font-size:.72rem; text-align:center; line-height:1.6; }
.ex-bid-row{
    display:flex; align-items:center; gap:10px; padding:10px; margin-bottom:8px;
    border:1px solid #1a1a1a; border-radius:6px; text-decoration:none; transition:border-color .15s ease;
}
.ex-bid-row:hover{ border-color:rgba(0,255,157,0.4); }
.ex-bid-row img{ width:44px; height:44px; object-fit:cover; border-radius:4px; flex-shrink:0; background:#111; }
.ex-bid-row-info{ flex:1; min-width:0; }
.ex-bid-row-name{
    color:#fff; font-family:'Space Mono',monospace; font-size:.68rem; letter-spacing:.3px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.ex-bid-row-price{ color:var(--ex-up); font-family:'Space Mono',monospace; font-size:.72rem; font-weight:700; margin-top:2px; }
.ex-bid-row-timer{
    font-family:'Space Mono',monospace; font-size:.6rem; color:var(--ex-gold); font-weight:700;
    white-space:nowrap; flex-shrink:0;
}
.ex-bid-row-timer.urgent{ color:var(--ex-down); }

@media (max-width:600px){
    .ex-bids-tab{ padding:10px 8px; }
    .ex-bids-panel{ width:100vw; right:-100vw; }
    .ex-bids-tab.panel-open{ right:100vw; }
}

/* How It Works modal */
.ex-help-overlay{
    display:none; position:fixed; inset:0; z-index:10000; background:rgba(0,0,0,0.82);
    backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:20px;
}
.ex-help-overlay.open{ display:flex; }
.ex-help-modal{
    width:min(640px, 100%); max-height:86vh; overflow-y:auto; background:#050505;
    border:1px solid rgba(0,255,157,0.35); border-radius:8px; box-shadow:0 30px 80px rgba(0,0,0,0.6);
}
.ex-help-head{
    display:flex; justify-content:space-between; align-items:center; gap:10px;
    padding:18px 22px; border-bottom:1px solid #1a1a1a; position:sticky; top:0; background:#050505;
}
.ex-help-head h2{ font-family:'Cormorant Garamond',serif; font-size:1.5rem; color:#fff; margin:0; }
.ex-help-close{
    background:none; border:1px solid #333; color:#999; width:32px; height:32px; border-radius:50%;
    cursor:pointer; font-size:1.1rem; line-height:1; flex-shrink:0;
}
.ex-help-close:hover{ border-color:var(--ex-up); color:var(--ex-up); }
.ex-help-body{ padding:22px; font-family:'Space Mono',monospace; }
.ex-help-step{ margin-bottom:22px; padding-left:16px; border-left:2px solid rgba(0,255,157,0.3); }
.ex-help-step:last-child{ margin-bottom:0; }
.ex-help-step-title{ color:var(--ex-up); font-size:.78rem; letter-spacing:1px; margin-bottom:6px; text-transform:uppercase; }
.ex-help-step-copy{ color:#bbb; font-size:.76rem; line-height:1.65; }
.ex-help-step-copy strong{ color:#fff; }

.ex-tabs{ display:flex; gap:8px; flex-wrap:wrap; margin:20px 0 22px; }
.ex-tab{
    font-family:'Space Mono',monospace; font-size:.68rem; letter-spacing:1px;
    padding:7px 14px; border:1px solid var(--ex-border); background:var(--ex-panel); color:#aaa;
    cursor:pointer; border-radius:3px;
}
.ex-tab:hover{ border-color:var(--ex-border-bright); color:#fff; }
.ex-tab.active{ border-color:var(--ex-up); color:var(--ex-up); background:rgba(0,255,157,0.06); }

.ex-grid{
    display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:14px;
}
.ex-slot{
    position:relative; background:var(--ex-panel); border:1px solid var(--ex-border); border-radius:6px;
    padding:12px 10px 10px; text-align:left; cursor:pointer; color:inherit; font:inherit;
    display:flex; flex-direction:column; gap:8px; transition:border-color .15s, transform .15s;
}
.ex-slot:hover, .ex-slot:focus-visible{ border-color:var(--ex-up); transform:translateY(-2px); outline:none; }
.ex-slot:focus-visible{ box-shadow:0 0 0 2px var(--ex-up); }
.ex-slot-imgwrap{
    aspect-ratio:1/1; background:#000; border:1px solid var(--ex-border); border-radius:4px;
    display:flex; align-items:center; justify-content:center; overflow:hidden; position:relative;
}
.ex-slot-imgwrap img{ width:100%; height:100%; object-fit:cover; }
.ex-slot-flag{
    position:absolute; top:6px; left:6px; font-family:'Space Mono',monospace; font-size:.55rem;
    letter-spacing:1px; padding:2px 6px; border-radius:2px; font-weight:700; z-index:2;
}
.ex-flag-one{ background:var(--ex-gold); color:#000; }
.ex-flag-low{ background:var(--ex-down); color:#fff; }
.ex-flag-out{ background:#222; color:#888; border:1px solid #444; }
.ex-slot-delta{
    position:absolute; top:6px; right:6px; font-family:'Space Mono',monospace; font-size:.55rem;
    padding:2px 5px; border-radius:2px; font-weight:700; z-index:2; background:rgba(0,0,0,0.7);
}
.ex-delta-up{ color:var(--ex-up); }
.ex-delta-down{ color:var(--ex-down); }
.ex-delta-flat{ color:var(--ex-flat); }
.ex-slot-name{ font-family:'Inter',sans-serif; font-size:.74rem; font-weight:700; color:#fff; line-height:1.25; min-height:2.4em; }
.ex-slot-cat{ font-family:'Space Mono',monospace; font-size:.55rem; color:#666; letter-spacing:1px; }
.ex-slot-bottom{ display:flex; align-items:center; justify-content:space-between; }
.ex-slot-price{ font-family:'Space Mono',monospace; font-size:.85rem; color:var(--ex-up); font-weight:700; }
.ex-slot-guide{ font-family:'Space Mono',monospace; font-size:.55rem; color:#666; }
.ex-slot-bid-tag{ font-family:'Space Mono',monospace; font-size:.52rem; color:var(--ex-gold); letter-spacing:.5px; margin-top:-2px; }
.ex-live-toast{
    position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(20px);
    z-index:1000000; background:#0a0a0a; border:1px solid var(--ex-up); border-radius:8px;
    padding:12px 18px; display:flex; align-items:center; gap:10px;
    font-family:'Space Mono',monospace; font-size:.7rem; color:#eee; letter-spacing:.3px;
    box-shadow:0 10px 40px rgba(0,0,0,0.5); opacity:0; transition:opacity .3s ease, transform .3s ease;
    max-width:90vw; pointer-events:none;
}
.ex-live-toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }
.ex-live-toast-dot{
    width:8px; height:8px; border-radius:50%; background:var(--ex-up); flex-shrink:0;
    box-shadow:0 0 8px var(--ex-up); animation:ex-pulse 1.2s ease-in-out infinite;
}
.ex-live-toast strong{ color:var(--ex-up); }
.ex-recent-strip{ margin:0 0 20px; }
.ex-recent-strip-label{ font-family:'Space Mono',monospace; font-size:.62rem; letter-spacing:2px; color:#777; margin-bottom:8px; }
.ex-recent-strip-items{ display:flex; gap:10px; overflow-x:auto; -webkit-overflow-scrolling:touch; padding-bottom:4px; }
.ex-recent-item{
    flex-shrink:0; width:64px; cursor:pointer; text-align:center; opacity:.85; transition:opacity .15s ease;
    background:none; border:none; padding:0; font-family:'Space Mono',monospace;
}
.ex-recent-item:hover{ opacity:1; }
.ex-recent-item img{ width:64px; height:64px; object-fit:cover; border-radius:5px; border:1px solid var(--ex-border); display:block; }
.ex-recent-item-price{ font-size:.56rem; color:var(--ex-up); margin-top:4px; }
.ex-slot-spark{ width:100%; height:22px; display:block; margin:2px 0; }
.ex-slot-meta-row{ display:flex; flex-direction:column; gap:2px; margin-top:2px; }
.ex-slot-lastsold{ font-family:'Space Mono',monospace; font-size:.5rem; color:#777; letter-spacing:.4px; }
.ex-slot-seller{ font-family:'Space Mono',monospace; font-size:.5rem; color:var(--ex-gold); letter-spacing:.4px; }
.ex-admin-bar{ display:flex; gap:5px; margin-top:4px; }
.ex-admin-remove{ font-family:'Space Mono',monospace; font-size:.55rem; padding:4px 8px; border-radius:3px; cursor:pointer; font-weight:700; letter-spacing:.5px; border:1px solid var(--ex-down); background:rgba(255,77,77,0.08); color:var(--ex-down); }
.ex-admin-remove:hover{ background:var(--ex-down); color:#fff; }
.ex-admin-restore{ font-family:'Space Mono',monospace; font-size:.55rem; padding:4px 8px; border-radius:3px; cursor:pointer; font-weight:700; letter-spacing:.5px; border:1px solid var(--ex-up); background:rgba(0,255,157,0.08); color:var(--ex-up); }
.ex-admin-restore:hover{ background:var(--ex-up); color:#000; }
.ex-slot.ex-hidden-item{ opacity:.45; border-style:dashed; }
.ex-admin-label{ font-family:'Space Mono',monospace; font-size:.5rem; color:#ff4d4d; letter-spacing:.5px; }
.ex-slot-timer{ display:flex; align-items:center; gap:5px; background:rgba(255,170,0,0.08); border:1px solid rgba(255,170,0,0.25); border-radius:3px; padding:4px 7px; margin-top:4px; }
.ex-slot-timer.urgent{ background:rgba(255,77,77,0.10); border-color:rgba(255,77,77,0.35); }
.ex-timer-dot{ width:5px; height:5px; border-radius:50%; background:var(--ex-gold); flex-shrink:0; animation:ex-pulse 1.4s ease-in-out infinite; }
.ex-slot-timer.urgent .ex-timer-dot{ background:var(--ex-down); }
.ex-timer-label{ font-family:'Space Mono',monospace; font-size:.52rem; color:var(--ex-gold); letter-spacing:.5px; }
.ex-slot-timer.urgent .ex-timer-label{ color:var(--ex-down); }
.ex-timer-count{ font-family:'Space Mono',monospace; font-size:.52rem; color:#fff; font-weight:700; margin-left:2px; }
.ex-panel-timer{ background:rgba(255,170,0,0.06); border:1px solid rgba(255,170,0,0.22); border-radius:4px; padding:10px 14px; display:none; }
.ex-panel-timer.show{ display:block; }
.ex-panel-timer.urgent{ background:rgba(255,77,77,0.06); border-color:rgba(255,77,77,0.28); }
.ex-panel-timer-label{ font-family:'Space Mono',monospace; font-size:.6rem; color:var(--ex-gold); letter-spacing:1px; margin-bottom:4px; }
.ex-panel-timer.urgent .ex-panel-timer-label{ color:var(--ex-down); }
.ex-panel-timer-count{ font-family:'Space Mono',monospace; font-size:1.1rem; font-weight:700; color:#fff; }
.ex-panel-timer-note{ font-family:'Space Mono',monospace; font-size:.58rem; color:#888; margin-top:4px; line-height:1.4; }

.ex-empty{ text-align:center; padding:80px 20px; font-family:'Space Mono',monospace; color:#666; }

/* Offer Modal */
.ex-overlay{
    position:fixed; inset:0; background:rgba(0,0,0,0.82); backdrop-filter:blur(3px);
    display:none; align-items:center; justify-content:center; z-index:200; padding:18px;
}
.ex-overlay.open{ display:flex; }
.ex-modal{
    width:100%; max-width:760px; max-height:92vh; overflow-y:auto;
    background:var(--ex-panel2); border:1px solid var(--ex-border-bright); border-radius:8px;
    box-shadow:0 20px 60px rgba(0,0,0,0.6);
}
.ex-modal-head{
    display:flex; align-items:center; justify-content:space-between; gap:12px;
    padding:14px 18px; border-bottom:1px solid var(--ex-border);
    font-family:'Space Mono',monospace; font-size:.68rem; letter-spacing:2px; color:var(--ex-up);
}
.ex-modal-close{ background:none; border:1px solid var(--ex-border); color:#aaa; width:28px; height:28px; border-radius:4px; cursor:pointer; font-size:.9rem; line-height:1; }
.ex-modal-close:hover{ color:#fff; border-color:var(--ex-up); }
.ex-modal-body{ display:grid; grid-template-columns:1fr 1.15fr; gap:0; }
/* ---- RESPONSIVE ---------------------------------------------------- */

/* Mobile hamburger button */
.ex-menu-btn{
    display:none; background:none; border:1px solid var(--ex-border); color:#ccc;
    padding:7px 11px; border-radius:4px; cursor:pointer; font-family:'Space Mono',monospace;
    font-size:.68rem; letter-spacing:1px; flex-shrink:0;
}
.ex-menu-btn:focus-visible{ outline:2px solid var(--ex-up); }

@media (max-width: 820px){
    /* Header: stack logo+title / nav */
    .ex-header{ flex-wrap:wrap; gap:10px; padding:12px 4%; }
    .ex-nav-links{ gap:12px; font-size:.68rem; }
    /* Grid: 2 columns on tablet */
    .ex-grid{ grid-template-columns:repeat(auto-fill, minmax(130px,1fr)); gap:10px; }
    /* Slot font tightening */
    .ex-slot-price{ font-size:.78rem; }
    /* Modal: shorter image pane on tablet */
    .ex-modal-img img{ max-height:240px; }
}

@media (max-width: 600px){
    /* Show hamburger, hide inline nav */
    .ex-menu-btn{ display:inline-flex; align-items:center; gap:6px; }
    .ex-nav-links{ 
        display:none; width:100%; flex-direction:column; gap:0;
        border-top:1px solid var(--ex-border); padding-top:10px; margin-top:4px;
    }
    .ex-intro-top{ flex-direction:column; align-items:flex-start; gap:10px; }
    .ex-help-btn{ width:100%; text-align:center; display:block; }
    .ex-help-modal{ max-height:90vh; }
    .ex-help-body{ padding:16px; }
    .ex-nav-links.open{ display:flex; }
    .ex-nav-links a{ padding:9px 0; border-bottom:1px solid var(--ex-border); font-size:.72rem; }

    /* Ticker: smaller font, no pause on hover */
    .ex-ticker-wrap{ height:28px; }
    .ex-tick{ font-size:.6rem; padding:0 12px; }
    .ex-ticker-label{ font-size:.6rem; padding:0 10px; }

    /* Main padding */
    .ex-main{ padding:18px 4% 80px; }
    .ex-intro h1{ font-size:1.6rem; }
    .ex-intro p{ font-size:.68rem; }

    /* Tabs: scrollable row */
    .ex-tabs{ flex-wrap:nowrap; overflow-x:auto; -webkit-overflow-scrolling:touch; padding-bottom:4px; }
    .ex-tab{ white-space:nowrap; flex-shrink:0; }

    /* Grid: 2 columns on phone */
    .ex-grid{ grid-template-columns:repeat(2,1fr); gap:8px; }
    .ex-slot{ padding:8px 8px 8px; gap:5px; }
    .ex-slot-name{ font-size:.68rem; min-height:2em; }

    /* Modal: full screen feel */
    .ex-overlay{ padding:0; align-items:flex-end; }
    .ex-modal{
        max-height:94vh; border-radius:12px 12px 0 0;
        border-left:none; border-right:none; border-bottom:none;
    }
    .ex-modal-body{ grid-template-columns:1fr; }
    .ex-modal-img{
        border-right:none; border-bottom:1px solid var(--ex-border);
        padding:12px; max-height:180px; overflow:hidden;
    }
    .ex-modal-img img{ max-height:160px; }
    .ex-modal-info{ padding:14px; gap:10px; }
    .ex-guide-price{ font-size:1.3rem; }
    .ex-panel-timer-count{ font-size:.95rem; }

    /* Bid row: stack inputs */
    .ex-bid-row{ flex-direction:column; gap:8px; }
    .ex-confirm-btn{ font-size:.72rem; padding:12px; }
    .ex-bid-btn{ font-size:.68rem; padding:11px; }
}

@media (max-width: 360px){
    .ex-grid{ grid-template-columns:repeat(2,1fr); gap:6px; }
    .ex-slot-price{ font-size:.72rem; }
}
.ex-modal-img{ background:#000; border-right:1px solid var(--ex-border); display:flex; align-items:center; justify-content:center; padding:18px; }
.ex-modal-img img{ width:100%; max-height:340px; object-fit:contain; }
.ex-modal-info{ padding:20px; display:flex; flex-direction:column; gap:14px; }
.ex-view-listing-link{
    display:inline-block; margin-top:6px; font-family:'Space Mono',monospace;
    font-size:.62rem; letter-spacing:.5px; color:var(--ex-up); text-decoration:none;
    border-bottom:1px dashed rgba(0,255,157,0.4); padding-bottom:2px; transition:opacity .15s ease;
}
.ex-view-listing-link:hover{ opacity:.75; border-bottom-color:var(--ex-up); }
.ex-modal-title-row{ display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }
.ex-watch-btn{
    background:none; border:1px solid rgba(255,0,157,0.35); border-radius:6px;
    width:40px; height:40px; flex-shrink:0; cursor:pointer; font-size:1.2rem; color:#ff4d9d;
    display:flex; align-items:center; justify-content:center; transition:border-color .15s ease, transform .1s ease;
}
.ex-watch-btn:hover{ border-color:#ff4d9d; }
.ex-watch-btn:active{ transform:scale(0.92); }
.ex-modal-name{ font-family:'Cormorant Garamond',serif; font-size:1.5rem; color:#fff; margin:0; }
.ex-modal-cat{ font-family:'Space Mono',monospace; font-size:.6rem; color:#777; letter-spacing:2px; margin-top:-10px;}
.ex-price-row{ display:flex; align-items:baseline; gap:14px; flex-wrap:wrap; }
.ex-guide-label{ font-family:'Space Mono',monospace; font-size:.6rem; color:#777; letter-spacing:1px; }
.ex-guide-price{ font-family:'Space Mono',monospace; font-size:1.6rem; font-weight:700; color:var(--ex-up); }
.ex-trade-count{ font-family:'Space Mono',monospace; font-size:.65rem; color:#666; }
.ex-chart-wrap{ border:1px solid var(--ex-border); border-radius:4px; background:#050606; padding:8px; }
.ex-chart-status{ font-family:'Space Mono',monospace; font-size:.6rem; color:#555; text-align:center; padding:30px 0; }
.ex-qty-row{ display:flex; align-items:center; gap:10px; }
.ex-qty-btn{ width:32px; height:32px; border:1px solid var(--ex-border); background:#0a0a0a; color:#fff; font-size:1rem; border-radius:4px; cursor:pointer; }
.ex-qty-btn:hover{ border-color:var(--ex-up); color:var(--ex-up); }
.ex-qty-input{ width:60px; text-align:center; background:#0a0a0a; border:1px solid var(--ex-border); color:#fff; padding:7px; border-radius:4px; font-family:'Space Mono',monospace; }
.ex-stock-note{ font-family:'Space Mono',monospace; font-size:.62rem; color:#888; }
.ex-total-row{ display:flex; justify-content:space-between; align-items:center; font-family:'Space Mono',monospace; font-size:.78rem; border-top:1px solid var(--ex-border); padding-top:12px; }
.ex-total-val{ color:var(--ex-up); font-weight:700; font-size:1rem; }
.ex-confirm-btn{
    width:100%; padding:14px; background:var(--ex-up); color:#000; border:none; border-radius:4px;
    font-family:'Space Mono',monospace; font-weight:700; letter-spacing:2px; font-size:.78rem; cursor:pointer;
}
.ex-confirm-btn:hover{ filter:brightness(1.08); }
.ex-confirm-btn:disabled{ background:#333; color:#777; cursor:not-allowed; }
.ex-feedback{ font-family:'Space Mono',monospace; font-size:.72rem; padding:10px; border-radius:4px; display:none; }
.ex-feedback.show{ display:block; }
.ex-feedback.ok{ background:rgba(0,255,157,0.08); border:1px solid var(--ex-up); color:var(--ex-up); }
.ex-feedback.err{ background:rgba(255,77,77,0.08); border:1px solid var(--ex-down); color:var(--ex-down); }

.ex-bid-section{ margin-top:6px; padding-top:16px; border-top:1px solid var(--ex-border); }
.ex-bid-divider{ text-align:center; margin-bottom:12px; position:relative; }
.ex-bid-divider span{ font-family:'Space Mono',monospace; font-size:.6rem; letter-spacing:2px; color:#666; background:var(--ex-panel2); padding:0 8px; }
.ex-bid-note{ font-family:'Space Mono',monospace; font-size:.62rem; color:var(--ex-gold); margin:0 0 10px; text-align:center; }
.ex-bid-row{ display:flex; gap:10px; margin-bottom:10px; }
.ex-bid-input{ width:100%; background:#0a0a0a; border:1px solid var(--ex-border); color:#fff; padding:8px; border-radius:4px; font-family:'Space Mono',monospace; font-size:.78rem; }
.ex-bid-input:focus{ outline:none; border-color:var(--ex-up); }
.ex-bid-btn{
    width:100%; padding:12px; background:transparent; color:var(--ex-up); border:1px solid var(--ex-up); border-radius:4px;
    font-family:'Space Mono',monospace; font-weight:700; letter-spacing:1.5px; font-size:.72rem; cursor:pointer;
}
.ex-bid-btn:hover{ background:rgba(0,255,157,0.08); }
.ex-bid-btn:disabled{ border-color:#333; color:#777; cursor:not-allowed; background:none; }
.ex-bid-disclaimer{ font-family:'Space Mono',monospace; font-size:.58rem; color:#666; text-align:center; margin:8px 0 0; line-height:1.5; }
</style>
<style>
html { background: #000 !important; }
body { background: transparent !important; }
#dod-bg-toggle{
    position:fixed;bottom:20px;right:20px;z-index:99999;
    background:rgba(0,0,0,0.85);border:1px solid #00ff9d;color:#00ff9d;
    font-family:'Space Mono',monospace;font-size:.65rem;letter-spacing:1.5px;
    padding:9px 14px;border-radius:4px;cursor:pointer;
    backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);
    transition:all .25s ease;user-select:none;
}
#dod-bg-toggle:hover{background:#00ff9d;color:#000;}
#dod-bg-toggle.active{background:#00ff9d;color:#000;}
body.dod-bg-preview > *:not(#dod-bg-layer):not(#dod-bg-toggle){
    opacity:0!important;pointer-events:none!important;transition:opacity .4s ease!important;
}
body.dod-bg-preview #dod-bg-toggle{opacity:1!important;pointer-events:auto!important;}
</style>
<button id="dod-bg-toggle" aria-label="Toggle background preview">VIEW BG</button>
<script>
(function(){
    var btn=document.getElementById("dod-bg-toggle");
    if(!btn)return;
    btn.addEventListener("click",function(){
        var on=document.body.classList.toggle("dod-bg-preview");
        btn.textContent=on?"SHOW UI":"VIEW BG";
        btn.classList.toggle("active",on);
    });
    document.addEventListener("keydown",function(e){
        if((e.key==="Escape"||e.key==="Esc")&&document.body.classList.contains("dod-bg-preview")){
            document.body.classList.remove("dod-bg-preview");
            btn.textContent="VIEW BG";btn.classList.remove("active");
        }
    });
})();
</script>
<script src="/js/telemetry.js" defer></script>
<script src="/js/search.js" defer></script>
</head>
<body class="tech-archive">

<a href="#main-content" class="skip-link">SKIP TO MAIN CONTENT</a>

<header class="ex-header">
    <div style="display:flex; align-items:center;">
        <a href="/" class="ex-logo">
            <img src="/images/logo-header.png" alt="Diamonds Outta Dirt" class="ex-logo-img">
            <span class="dot" aria-hidden="true"></span>
            <span class="word"><?= h(SITE_NAME) ?></span>
        </a>
        <span class="ex-title">THE EXCHANGE</span>
    </div>
    <button type="button" class="ex-menu-btn" id="exMenuBtn" aria-expanded="false" aria-controls="exNavLinks" aria-label="Toggle navigation">
        MENU &#9776;
    </button>
    <nav class="ex-nav-links" id="exNavLinks" aria-label="Exchange navigation">
        <a href="/">HOME</a>
        <a href="/shop">ARCHIVE</a>
        <a href="/cart.php" id="cart-link" class="ex-bag">BAG [<span id="cart-count"><?= (int)$cartCount ?></span>]</a>
    </nav>
</header>

<div class="ex-ticker-wrap" aria-label="Recent trades ticker">
    <span class="ex-ticker-label">RECENT TRADES</span>
    <?php if (!empty($ticker)): ?>
        <div class="ex-ticker-track">
            <?php
            $tickerHtml = '';
            foreach ($ticker as $t) {
                $tickerHtml .= '<span class="ex-tick"><span class="nm">' . h(strtoupper((string)$t['product_name'])) . '</span>'
                    . '<span class="px">$' . h(formatPrice((float)$t['price'])) . '</span>'
                    . '<span class="tm">' . h(ex_time_ago((string)$t['created_at'])) . '</span></span>';
            }
            echo $tickerHtml . $tickerHtml;
            ?>
        </div>
    <?php else: ?>
        <span class="ex-ticker-empty">MARKET OPENING -- NO TRADES RECORDED YET</span>
    <?php endif; ?>
</div>

<main id="main-content" tabindex="-1" class="ex-main">
    <div class="ex-intro">
        <div class="ex-intro-top">
            <h1>THE EXCHANGE</h1>
            <button type="button" class="ex-help-btn" id="exHelpBtn">? HOW IT WORKS</button>
        </div>
        <p>LIVE MARKET TERMINAL // GUIDE PRICES BUILT FROM REAL SALES // <?= count($products) ?> ITEMS LISTED</p>
    </div>

    <div class="ex-tabs" role="tablist" aria-label="Sector filter">
        <?php foreach ($categories as $i => $cat): ?>
            <button type="button" class="ex-tab <?= $i === 0 ? 'active' : '' ?>" data-cat="<?= h($cat) ?>" role="tab" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"><?= h($cat) ?></button>
        <?php endforeach; ?>
    </div>

    <?php if ($dbError): ?>
        <div class="ex-empty">EXCHANGE DATA TEMPORARILY UNAVAILABLE. PLEASE REFRESH.</div>
    <?php elseif (empty($products)): ?>
        <div class="ex-empty">NO ITEMS LISTED ON THE EXCHANGE YET.</div>
    <?php else: ?>
        <div class="ex-recent-strip" id="exRecentStrip" style="display:none;">
    <div class="ex-recent-strip-label">RECENTLY VIEWED</div>
    <div class="ex-recent-strip-items" id="exRecentStripItems"></div>
</div>

<div class="ex-grid" id="exGrid">
            <?php foreach ($products as $row):
                $pid = (int)($row['id'] ?? 0);
                $slugRaw = isset($row['slug']) ? (string)$row['slug'] : '';
                $imgSrc = productImage((string)($row['image_url'] ?? ''));
                $price = (float)($row['price'] ?? 0);
                $name = (string)($row['name'] ?? '');
                $category = strtoupper(trim((string)($row['category'] ?? '')));
                $stock = (int)($row['stock'] ?? 0);
                $description = (string)($row['description'] ?? '');
                $isOneOfOne = ($stock === 1);
                $isLowStock = ($stock > 1 && $stock <= 5);
                $isOut = ($stock <= 0);

                $guide = $guideMap[$pid] ?? null;
                $guidePrice = $guide['guide_price'] ?? $price;
                $tradeCount = $guide['trade_count'] ?? 0;

                $lastSoldAt = $lastSoldMap[$pid] ?? null;
                $lastSoldLabel = $lastSoldAt ? ex_time_ago($lastSoldAt) : '';
                $sellerName = trim((string)($row['seller_name'] ?? ''));
                $sparkPoints = $sparklineMap[$pid] ?? [];

                $delta = $guidePrice > 0 ? (($price - $guidePrice) / $guidePrice) * 100 : 0;
                if ($tradeCount === 0) {
                    $deltaClass = 'ex-delta-flat'; $deltaLabel = 'NEW';
                } elseif ($delta > 0.5) {
                    $deltaClass = 'ex-delta-up'; $deltaLabel = chr(0x25B2) . ' ' . number_format(abs($delta), 1) . '%';
                } elseif ($delta < -0.5) {
                    $deltaClass = 'ex-delta-down'; $deltaLabel = chr(0x25BC) . ' ' . number_format(abs($delta), 1) . '%';
                } else {
                    $deltaClass = 'ex-delta-flat'; $deltaLabel = '-- FLAT';
                }
            ?>
            <button type="button" class="ex-slot" data-cat="<?= h($category) ?>"
                data-id="<?= $pid ?>"
                data-slug="<?= h($slugRaw) ?>"
                data-name="<?= h($name) ?>"
                data-price="<?= h(number_format($price, 2, '.', '')) ?>"
                data-guide="<?= h(number_format((float)$guidePrice, 2, '.', '')) ?>"
                data-trades="<?= (int)$tradeCount ?>"
                data-stock="<?= $stock ?>"
                data-category="<?= h($category) ?>"
                data-image="<?= h($imgSrc) ?>"
                data-desc="<?= h($description) ?>"
                data-accepts-offers="<?= ((int)($row['accepts_offers'] ?? 0) === 1) ? '1' : '0' ?>"
                data-min-offer="<?= h($row['min_offer_price'] !== null ? number_format((float)$row['min_offer_price'], 2, '.', '') : '') ?>"
                data-expires-at="<?= isset($acceptedOffers[$pid]) ? h($acceptedOffers[$pid]) : '' ?>"
                data-show-exchange="<?= (int)($row['show_on_exchange'] ?? 1) ?>"
                <?= ((int)($row['show_on_exchange'] ?? 1) === 0) ? 'class="ex-slot ex-hidden-item"' : '' ?>>
                <?php if ($isAdmin && (int)($row['show_on_exchange'] ?? 1) === 0): ?>
                    <div class="ex-admin-label">HIDDEN FROM EXCHANGE</div>
                <?php endif; ?>
                <div class="ex-slot-imgwrap">
                    <?php if ($isOneOfOne): ?><span class="ex-slot-flag ex-flag-one">1 OF 1</span>
                    <?php elseif ($isLowStock): ?><span class="ex-slot-flag ex-flag-low"><?= $stock ?> LEFT</span>
                    <?php elseif ($isOut): ?><span class="ex-slot-flag ex-flag-out">OUT</span>
                    <?php endif; ?>
                    <span class="ex-slot-delta <?= $deltaClass ?>"><?= h($deltaLabel) ?></span>
                    <img src="<?= h($imgSrc) ?>" alt="" loading="lazy">
                </div>
                <div class="ex-slot-cat"><?= h($category) ?></div>
                <div class="ex-slot-name"><?= h($name) ?></div>
                <?php if (!empty($sparkPoints) && count($sparkPoints) >= 2):
                    $sMin = min($sparkPoints); $sMax = max($sparkPoints);
                    $sRange = ($sMax - $sMin) ?: 1;
                    $sW = 100; $sH = 24; $sCount = count($sparkPoints);
                    $sPathPts = [];
                    foreach ($sparkPoints as $si => $sv) {
                        $sx = $sCount > 1 ? ($si / ($sCount - 1)) * $sW : 0;
                        $sy = $sH - (($sv - $sMin) / $sRange) * $sH;
                        $sPathPts[] = round($sx, 1) . ',' . round($sy, 1);
                    }
                    $sTrendUp = end($sparkPoints) >= reset($sparkPoints);
                ?>
                <svg class="ex-slot-spark" viewBox="0 0 <?= $sW ?> <?= $sH ?>" preserveAspectRatio="none" aria-hidden="true">
                    <polyline points="<?= h(implode(' ', $sPathPts)) ?>" fill="none" stroke="<?= $sTrendUp ? 'var(--ex-up)' : 'var(--ex-down)' ?>" stroke-width="1.5"/>
                </svg>
                <?php endif; ?>
                <div class="ex-slot-bottom">
                    <span class="ex-slot-price">$<?= h(formatPrice($price)) ?></span>
                    <span class="ex-slot-guide"><?= $tradeCount > 0 ? 'GUIDE $' . h(formatPrice((float)$guidePrice)) : 'NO TRADES' ?></span>
                </div>
                <?php if ($lastSoldLabel !== '' || $sellerName !== ''): ?>
                <div class="ex-slot-meta-row">
                    <?php if ($lastSoldLabel !== ''): ?><span class="ex-slot-lastsold">LAST SOLD <?= h(strtoupper($lastSoldLabel)) ?></span><?php endif; ?>
                    <?php if ($sellerName !== ''): ?><span class="ex-slot-seller">SOLD BY <?= h(strtoupper($sellerName)) ?></span><?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ((int)($row['accepts_offers'] ?? 0) === 1): ?>
                    <div class="ex-slot-bid-tag">MAKES OFFERS &middot; MIN $<?= h($row['min_offer_price'] !== null ? formatPrice((float)$row['min_offer_price']) : '0.00') ?></div>
                <?php endif; ?>
                <?php if (isset($acceptedOffers[$pid])): ?>
                    <div class="ex-slot-timer" data-slot-timer="<?= h($acceptedOffers[$pid]) ?>">
                        <span class="ex-timer-dot"></span>
                        <span class="ex-timer-label">OFFER PENDING</span>
                        <span class="ex-timer-count"></span>
                    </div>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                    <div class="ex-admin-bar" onclick="event.stopPropagation();">
                        <?php if ((int)($row['show_on_exchange'] ?? 1) === 1): ?>
                            <button type="button" class="ex-admin-remove"
                                data-pid="<?= $pid ?>" data-action="hide"
                                aria-label="Remove from Exchange">REMOVE</button>
                        <?php else: ?>
                            <button type="button" class="ex-admin-restore"
                                data-pid="<?= $pid ?>" data-action="show"
                                aria-label="Restore to Exchange">RESTORE</button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<!-- Currently Being Bid On -- slide-out panel -->
<button type="button" class="ex-bids-tab" id="exBidsTab" aria-expanded="false" aria-controls="exBidsPanel">
    <span class="ex-bids-tab-label">CURRENTLY BEING BID ON</span>
    <span class="ex-bids-tab-count" id="exBidsTabCount"><?= count($activeBidsPanel) ?></span>
</button>

<div class="ex-bids-panel" id="exBidsPanel" aria-hidden="true">
    <div class="ex-bids-panel-head">
        <span>ACTIVE BIDS &middot; SOONEST EXPIRING FIRST</span>
        <button type="button" class="ex-bids-close" id="exBidsClose" aria-label="Close">&times;</button>
    </div>
    <div class="ex-bids-list" id="exBidsList">
        <?php if (empty($activeBidsPanel)): ?>
            <div class="ex-bids-empty">No items currently have an accepted offer pending payment.</div>
        <?php else: ?>
            <?php foreach ($activeBidsPanel as $bid): ?>
                <a class="ex-bid-row" href="<?= h($bid['href']) ?>" data-expires="<?= h($bid['expires_at']) ?>">
                    <img src="<?= h($bid['image']) ?>" alt="" loading="lazy" onerror="this.src='/images/placeholder.jpg'">
                    <div class="ex-bid-row-info">
                        <div class="ex-bid-row-name"><?= h($bid['name']) ?></div>
                        <div class="ex-bid-row-price">$<?= number_format($bid['price'], 2) ?></div>
                    </div>
                    <div class="ex-bid-row-timer" data-bid-timer><?php // filled by JS ?></div>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="ex-help-overlay" id="exHelpOverlay" role="presentation">
    <div class="ex-help-modal" role="dialog" aria-modal="true" aria-labelledby="exHelpTitle">
        <div class="ex-help-head">
            <h2 id="exHelpTitle">How The Exchange Works</h2>
            <button type="button" class="ex-help-close" id="exHelpClose" aria-label="Close">&times;</button>
        </div>
        <div class="ex-help-body">
            <div class="ex-help-step">
                <div class="ex-help-step-title">01 // Guide Prices</div>
                <div class="ex-help-step-copy">
                    Every item shows a <strong>guide price</strong> built from real completed sales over the last 90 days,
                    not just the listed price. Click any item to see its full price history chart and how many trades
                    it's had. Green means the current price is trading above guide, red means below.
                </div>
            </div>

            <div class="ex-help-step">
                <div class="ex-help-step-title">02 // Buy At Listed Price</div>
                <div class="ex-help-step-copy">
                    Open an item and use <strong>CONFIRM OFFER -- ADD TO BAG</strong> to purchase instantly at the
                    listed price -- no negotiation needed. Want to see more photos or the full product description first?
                    Use the <strong>VIEW FULL LISTING</strong> link at the top of the panel to jump to the complete
                    product page.
                </div>
            </div>

            <div class="ex-help-step">
                <div class="ex-help-step-title">03 // Make an Offer</div>
                <div class="ex-help-step-copy">
                    Select items marked <strong>MAKES OFFERS</strong> support custom pricing. Enter what you're willing
                    to pay (at or above the minimum shown), your name, and email, then submit. Offers are reviewed
                    manually -- nothing is charged automatically.
                </div>
            </div>

            <div class="ex-help-step">
                <div class="ex-help-step-title">04 // If Your Offer Is Accepted</div>
                <div class="ex-help-step-copy">
                    You'll receive a secure payment link by email at your accepted price. Items with an active accepted
                    offer show an <strong>OFFER PENDING</strong> countdown timer -- if payment isn't completed before it
                    expires, the item becomes available to other buyers again.
                </div>
            </div>

            <div class="ex-help-step">
                <div class="ex-help-step-title">05 // Stock Flags</div>
                <div class="ex-help-step-copy">
                    <strong>1 OF 1</strong> means it's a one-of-a-kind piece. <strong>LOW STOCK</strong> flags items with
                    5 or fewer left. Once an item sells out it's marked <strong>OUT</strong> until restocked.
                </div>
            </div>
        </div>
    </div>
</div>

<div class="ex-overlay" id="exOverlay" role="presentation">
    <div class="ex-modal" role="dialog" aria-modal="true" aria-labelledby="exModalName" id="exModal">
        <div class="ex-modal-head">
            <span>MAKE AN OFFER</span>
            <button type="button" class="ex-modal-close" id="exModalClose" aria-label="Close offer panel">&times;</button>
        </div>
        <div class="ex-modal-body">
            <div class="ex-modal-img">
                <img id="exModalImg" src="" alt="">
            </div>
            <div class="ex-modal-info">
                <div class="ex-modal-title-row">
                    <div>
                        <div class="ex-modal-cat" id="exModalCat"></div>
                        <h2 class="ex-modal-name" id="exModalName"></h2>
                        <a href="#" class="ex-view-listing-link" id="exViewListingLink" target="_blank" rel="noopener">
                            VIEW FULL LISTING &middot; BUY NOW OR SEE GALLERY &rarr;
                        </a>
                    </div>
                    <button type="button" class="ex-watch-btn" id="exWatchBtn"
                        data-logged-in="<?= !empty($_SESSION['customer_id']) ? '1' : '0' ?>"
                        aria-label="Watch this item" aria-pressed="false">
                        <span id="exWatchIcon">&#9825;</span>
                    </button>
                </div>
                <div class="ex-panel-timer" id="exPanelTimer">
                    <div class="ex-panel-timer-label">ACCEPTED OFFER PENDING PAYMENT</div>
                    <div class="ex-panel-timer-count" id="exPanelTimerCount"></div>
                    <div class="ex-panel-timer-note">If payment is not completed in time, this item may become available again.</div>
                </div>
                <div class="ex-price-row">
                    <div>
                        <div class="ex-guide-label">GUIDE PRICE</div>
                        <div class="ex-guide-price" id="exGuidePrice">$0.00</div>
                    </div>
                    <div class="ex-trade-count" id="exTradeCount"></div>
                </div>
                <div class="ex-chart-wrap">
                    <canvas id="exChart" width="600" height="120" style="width:100%; height:90px; display:block;"></canvas>
                    <div class="ex-chart-status" id="exChartStatus">LOADING PRICE HISTORY...</div>
                </div>
                <div class="ex-qty-row">
                    <button type="button" class="ex-qty-btn" id="exQtyMinus" aria-label="Decrease quantity">-</button>
                    <input type="number" class="ex-qty-input" id="exQtyInput" value="1" min="1" max="1" aria-label="Quantity">
                    <button type="button" class="ex-qty-btn" id="exQtyPlus" aria-label="Increase quantity">+</button>
                    <span class="ex-stock-note" id="exStockNote"></span>
                </div>
                <div class="ex-total-row">
                    <span>TOTAL OFFER</span>
                    <span class="ex-total-val" id="exTotal">$0.00</span>
                </div>
                <button type="button" class="ex-confirm-btn" id="exConfirmBtn">CONFIRM OFFER -- ADD TO BAG</button>
                <div class="ex-feedback" id="exFeedback"></div>

                <div class="ex-bid-section" id="exBidSection" style="display:none;">
                    <div class="ex-bid-divider"><span>OR MAKE AN OFFER</span></div>
                    <p class="ex-bid-note" id="exBidMinNote"></p>
                    <div class="ex-bid-row">
                        <div style="flex:1;">
                            <label class="ex-guide-label" for="exBidPrice">YOUR OFFER ($)</label>
                            <input type="number" step="0.01" min="0" class="ex-bid-input" id="exBidPrice" placeholder="0.00">
                        </div>
                        <div style="width:90px;">
                            <label class="ex-guide-label" for="exBidQty">QTY</label>
                            <input type="number" step="1" min="1" value="1" class="ex-bid-input" id="exBidQty">
                        </div>
                    </div>
                    <div class="ex-bid-row">
                        <div style="flex:1;">
                            <label class="ex-guide-label" for="exBidName">NAME</label>
                            <input type="text" class="ex-bid-input" id="exBidName" maxlength="255">
                        </div>
                        <div style="flex:1;">
                            <label class="ex-guide-label" for="exBidEmail">EMAIL</label>
                            <input type="email" class="ex-bid-input" id="exBidEmail" maxlength="255">
                        </div>
                    </div>
                    <input type="text" id="exBidCompany" name="company" autocomplete="off" tabindex="-1" style="position:absolute; left:-9999px; width:1px; height:1px; opacity:0;" aria-hidden="true">
                    <button type="button" class="ex-bid-btn" id="exBidSubmitBtn">SUBMIT OFFER FOR REVIEW</button>
                    <p class="ex-bid-disclaimer">Offers are reviewed manually. If accepted, you'll get a secure payment link by email -- nothing is charged until then.</p>
                    <div class="ex-feedback" id="exBidFeedback"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($isAdmin || !empty($_SESSION['customer_id'])): ?>
<script>
window.__exCsrf = <?= json_encode($_SESSION['csrf'] ?? '') ?>;
window.__exCustomerWatchlist = <?= json_encode($customerWatchlistIds) ?>;
window.__exCustomerLoggedIn = <?= !empty($_SESSION['customer_id']) ? 'true' : 'false' ?>;
</script>
<?php endif; ?>
<script>
(function () {
    'use strict';

    // Admin: remove / restore from Exchange
    if (window.__exCsrf) {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action="hide"],[data-action="show"]');
            if (!btn) return;
            e.stopPropagation();
            var pid = parseInt(btn.getAttribute('data-pid'), 10);
            var action = btn.getAttribute('data-action');
            var show = action === 'show' ? 1 : 0;
            btn.disabled = true;
            btn.textContent = '...';
            fetch('/api/exchange_toggle.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ product_id: pid, show: show, csrf: window.__exCsrf })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.ok) {
                    var slot = btn.closest('.ex-slot');
                    if (!slot) return;
                    if (show === 0) {
                        slot.classList.add('ex-hidden-item');
                        btn.className = 'ex-admin-restore';
                        btn.setAttribute('data-action', 'show');
                        btn.textContent = 'RESTORE';
                        btn.disabled = false;
                        var lbl = slot.querySelector('.ex-admin-label');
                        if (!lbl) {
                            lbl = document.createElement('div');
                            lbl.className = 'ex-admin-label';
                            slot.insertBefore(lbl, slot.firstChild);
                        }
                        lbl.textContent = 'HIDDEN FROM EXCHANGE';
                    } else {
                        slot.classList.remove('ex-hidden-item');
                        btn.className = 'ex-admin-remove';
                        btn.setAttribute('data-action', 'hide');
                        btn.textContent = 'REMOVE';
                        btn.disabled = false;
                        var lbl2 = slot.querySelector('.ex-admin-label');
                        if (lbl2) lbl2.remove();
                    }
                } else {
                    btn.textContent = 'ERROR';
                    setTimeout(function () {
                        btn.textContent = action === 'hide' ? 'REMOVE' : 'RESTORE';
                        btn.disabled = false;
                    }, 2000);
                }
            })
            .catch(function () {
                btn.textContent = 'ERROR';
                setTimeout(function () {
                    btn.textContent = action === 'hide' ? 'REMOVE' : 'RESTORE';
                    btn.disabled = false;
                }, 2000);
            });
        });
    }

    // Mobile hamburger
    var menuBtn = document.getElementById('exMenuBtn');
    var navLinks = document.getElementById('exNavLinks');
    if (menuBtn && navLinks) {
        menuBtn.addEventListener('click', function () {
            var open = navLinks.classList.toggle('open');
            menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', function (e) {
            if (!menuBtn.contains(e.target) && !navLinks.contains(e.target)) {
                navLinks.classList.remove('open');
                menuBtn.setAttribute('aria-expanded', 'false');
            }
        });
        // Close nav on link click
        navLinks.querySelectorAll('a').forEach(function (a) {
            a.addEventListener('click', function () {
                navLinks.classList.remove('open');
                menuBtn.setAttribute('aria-expanded', 'false');
            });
        });
    }

    var grid = document.getElementById('exGrid');
    var tabs = document.querySelectorAll('.ex-tab');
    var overlay = document.getElementById('exOverlay');
    var closeBtn = document.getElementById('exModalClose');
    // How It Works modal
    var helpBtn = document.getElementById('exHelpBtn');
    var helpOverlay = document.getElementById('exHelpOverlay');
    var helpClose = document.getElementById('exHelpClose');

    function openHelpModal() {
        if (!helpOverlay) return;
        // Bypass any possible CSS cascade/specificity conflict entirely by
        // setting the display directly via inline style with !important --
        // this always wins over any stylesheet rule, regardless of load
        // order or specificity from master.css/navigation.css.
        helpOverlay.style.setProperty('display', 'flex', 'important');
        helpOverlay.classList.add('open');
    }
    function closeHelpModal() {
        if (!helpOverlay) return;
        helpOverlay.style.setProperty('display', 'none', 'important');
        helpOverlay.classList.remove('open');
    }

    if (helpBtn) {
        // Redundant tap detection (click + pointerup with movement/duration
        // threshold), matching the fix that resolved an identical-feeling
        // "visible but unresponsive" issue on the 3D shop pedestals --
        // covers the case where the browser's synthetic click event is
        // being suppressed by something else on the page.
        var helpDownPos = null;
        var helpLastFired = 0;
        function fireHelpOpen() {
            var now = Date.now();
            if (now - helpLastFired < 400) return;
            helpLastFired = now;
            openHelpModal();
        }
        helpBtn.addEventListener('click', fireHelpOpen);
        helpBtn.addEventListener('pointerdown', function (e) {
            helpDownPos = { x: e.clientX, y: e.clientY };
        });
        helpBtn.addEventListener('pointerup', function (e) {
            if (!helpDownPos) return;
            var dist = Math.sqrt(Math.pow(e.clientX - helpDownPos.x, 2) + Math.pow(e.clientY - helpDownPos.y, 2));
            helpDownPos = null;
            if (dist <= 12) fireHelpOpen();
        });
    }
    if (helpClose) {
        helpClose.addEventListener('click', closeHelpModal);
    }
    if (helpOverlay) {
        helpOverlay.addEventListener('click', function (e) {
            if (e.target === helpOverlay) closeHelpModal();
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && helpOverlay && helpOverlay.classList.contains('open')) {
            closeHelpModal();
        }
    });

    var modalImg = document.getElementById('exModalImg');
    var modalCat = document.getElementById('exModalCat');
    var modalName = document.getElementById('exModalName');
    var viewListingLink = document.getElementById('exViewListingLink');
    var watchBtn = document.getElementById('exWatchBtn');
    var watchIcon = document.getElementById('exWatchIcon');
    var watchedIds = (window.__exCustomerWatchlist || []).map(function (n) { return parseInt(n, 10); });

    function updateWatchButtonState(productId) {
        if (!watchBtn || !watchIcon) return;
        var isWatched = watchedIds.indexOf(parseInt(productId, 10)) !== -1;
        watchBtn.classList.toggle('watching', isWatched);
        watchBtn.setAttribute('aria-pressed', isWatched ? 'true' : 'false');
        watchIcon.textContent = isWatched ? '\u2665' : '\u2661';
    }

    if (watchBtn) {
        watchBtn.addEventListener('click', function () {
            if (!current || !current.id) return;

            var isLoggedIn = watchBtn.getAttribute('data-logged-in') === '1';
            if (!isLoggedIn) {
                var next = encodeURIComponent(window.location.pathname);
                window.location.href = '/account/login?next=' + next;
                return;
            }

            watchBtn.disabled = true;
            var form = new FormData();
            form.set('csrf', window.__exCsrf || '');
            form.set('product_id', String(current.id));

            fetch('/api/wishlist_toggle.php?ajax=1', {
                method: 'POST',
                body: form,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                watchBtn.disabled = false;
                if (data && data.ok) {
                    var pid = parseInt(current.id, 10);
                    var idx = watchedIds.indexOf(pid);
                    if (data.added && idx === -1) watchedIds.push(pid);
                    else if (!data.added && idx !== -1) watchedIds.splice(idx, 1);
                    updateWatchButtonState(current.id);
                } else if (data && data.error === 'login_required') {
                    var next2 = encodeURIComponent(window.location.pathname);
                    window.location.href = '/account/login?next=' + next2;
                }
            })
            .catch(function () { watchBtn.disabled = false; });
        });
    }
    var guidePriceEl = document.getElementById('exGuidePrice');
    var tradeCountEl = document.getElementById('exTradeCount');
    var qtyInput = document.getElementById('exQtyInput');
    var qtyMinus = document.getElementById('exQtyMinus');
    var qtyPlus = document.getElementById('exQtyPlus');
    var stockNote = document.getElementById('exStockNote');
    var totalEl = document.getElementById('exTotal');
    var confirmBtn = document.getElementById('exConfirmBtn');
    var feedback = document.getElementById('exFeedback');
    var chartCanvas = document.getElementById('exChart');
    var chartStatus = document.getElementById('exChartStatus');
    var cartCountEl = document.getElementById('cart-count');
    var bidSection = document.getElementById('exBidSection');
    var bidMinNote = document.getElementById('exBidMinNote');
    var bidPrice = document.getElementById('exBidPrice');
    var bidQty = document.getElementById('exBidQty');
    var bidName = document.getElementById('exBidName');
    var bidEmail = document.getElementById('exBidEmail');
    var bidCompany = document.getElementById('exBidCompany');
    var bidSubmitBtn = document.getElementById('exBidSubmitBtn');
    var bidFeedback = document.getElementById('exBidFeedback');

    var current = null;
    var lastFocused = null;

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) { t.classList.remove('active'); t.setAttribute('aria-selected', 'false'); });
            tab.classList.add('active');
            tab.setAttribute('aria-selected', 'true');
            var cat = tab.getAttribute('data-cat');
            if (!grid) return;
            var slots = grid.querySelectorAll('.ex-slot');
            slots.forEach(function (slot) {
                var match = (cat === 'ALL') || (slot.getAttribute('data-cat') === cat);
                slot.style.display = match ? '' : 'none';
            });
        });
    });

    // ---- Recently Viewed strip (client-side, localStorage) ----
    var RECENT_KEY = 'dod_exchange_recently_viewed';
    var RECENT_MAX = 10;

    function getRecentlyViewed() {
        try {
            var raw = localStorage.getItem(RECENT_KEY);
            var arr = raw ? JSON.parse(raw) : [];
            return Array.isArray(arr) ? arr : [];
        } catch (e) { return []; }
    }

    function saveRecentlyViewed(item) {
        try {
            var arr = getRecentlyViewed().filter(function (x) { return x.id !== item.id; });
            arr.unshift(item);
            arr = arr.slice(0, RECENT_MAX);
            localStorage.setItem(RECENT_KEY, JSON.stringify(arr));
        } catch (e) { /* localStorage unavailable, skip silently */ }
    }

    function renderRecentStrip(excludeId) {
        var strip = document.getElementById('exRecentStrip');
        var itemsEl = document.getElementById('exRecentStripItems');
        if (!strip || !itemsEl) return;

        var items = getRecentlyViewed().filter(function (x) { return x.id !== excludeId; });

        if (items.length === 0) {
            strip.style.display = 'none';
            return;
        }

        strip.style.display = 'block';
        itemsEl.innerHTML = '';
        items.forEach(function (item) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ex-recent-item';
            btn.innerHTML = '<img src="' + item.image + '" alt="" loading="lazy" onerror="this.src=\'/images/placeholder.jpg\'">' +
                '<div class="ex-recent-item-price">$' + Number(item.price).toFixed(2) + '</div>';
            btn.addEventListener('click', function () {
                var targetSlot = document.querySelector('.ex-slot[data-id="' + item.id + '"]');
                if (targetSlot) openModal(targetSlot);
            });
            itemsEl.appendChild(btn);
        });
    }

    // ---- Live status polling: sound + haptic + toast when an item newly
    // becomes "pending" (accepted offer awaiting payment), without a full
    // page reload or any websocket infrastructure ----
    var LIVE_POLL_MS = 25000;
    var knownPendingIds = {};
    var liveStatusFirstLoad = true;

    function playPendingTone() {
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            var ctx = new Ctx();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, ctx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(1320, ctx.currentTime + 0.12);
            gain.gain.setValueAtTime(0.001, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.15, ctx.currentTime + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.4);
            osc.onended = function () { ctx.close(); };
        } catch (e) { /* audio unsupported, skip silently */ }
    }

    function pulseHaptic() {
        try {
            if (navigator.vibrate) navigator.vibrate([40, 30, 40]);
        } catch (e) { /* unsupported, skip silently */ }
    }

    function showLiveToast(itemName) {
        var toast = document.createElement('div');
        toast.className = 'ex-live-toast';
        toast.innerHTML = '<span class="ex-live-toast-dot"></span>' +
            '<span><strong>' + itemName.replace(/</g, '&lt;') + '</strong> just got an accepted offer</span>';
        document.body.appendChild(toast);
        requestAnimationFrame(function () { toast.classList.add('show'); });
        setTimeout(function () {
            toast.classList.remove('show');
            setTimeout(function () { toast.remove(); }, 300);
        }, 4500);
    }

    function refreshBidsPanelFromLiveData(items) {
        // Update slot timer badges + the slide-out panel without a full reload
        var idsNow = {};
        items.forEach(function (it) { idsNow[it.product_id] = it.expires_at; });

        document.querySelectorAll('.ex-slot').forEach(function (slot) {
            var pid = slot.getAttribute('data-id');
            var expiresAt = idsNow[pid];
            var existingBadge = slot.querySelector('[data-slot-timer]');

            if (expiresAt && !existingBadge) {
                // Item just became pending -- badge will appear on next full render;
                // at minimum, mark the data attribute so future opens are accurate.
                slot.setAttribute('data-expires-at', expiresAt);
            }
        });
    }

    function pollLiveStatus() {
        fetch('/api/exchange_live_status.php', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok || !Array.isArray(data.items)) return;

                var currentIds = {};
                data.items.forEach(function (it) { currentIds[it.product_id] = it; });

                if (!liveStatusFirstLoad) {
                    for (var pid in currentIds) {
                        if (!knownPendingIds[pid]) {
                            // Newly pending item detected
                            playPendingTone();
                            pulseHaptic();
                            showLiveToast(currentIds[pid].name);
                        }
                    }
                }

                knownPendingIds = currentIds;
                liveStatusFirstLoad = false;
                refreshBidsPanelFromLiveData(data.items);
            })
            .catch(function () { /* network hiccup, try again next poll */ });
    }

    function initLiveStatusPolling() {
        pollLiveStatus();
        setInterval(pollLiveStatus, LIVE_POLL_MS);
    }

    function openModal(slot) {
        lastFocused = document.activeElement;
        current = {
            id: parseInt(slot.getAttribute('data-id'), 10) || 0,
            slug: slot.getAttribute('data-slug') || '',
            name: slot.getAttribute('data-name') || '',
            price: parseFloat(slot.getAttribute('data-price')) || 0,
            guide: parseFloat(slot.getAttribute('data-guide')) || 0,
            trades: parseInt(slot.getAttribute('data-trades'), 10) || 0,
            stock: parseInt(slot.getAttribute('data-stock'), 10) || 0,
            category: slot.getAttribute('data-category') || '',
            image: slot.getAttribute('data-image') || '',
            desc: slot.getAttribute('data-desc') || '',
            acceptsOffers: slot.getAttribute('data-accepts-offers') === '1',
            minOffer: parseFloat(slot.getAttribute('data-min-offer')) || 0,
            expiresAt: slot.getAttribute('data-expires-at') || ''
        };

        modalImg.src = current.image;
        modalImg.alt = current.name;
        modalCat.textContent = current.category;
        modalName.textContent = current.name;
        if (viewListingLink) {
            viewListingLink.href = current.slug
                ? ('/product/' + encodeURIComponent(current.slug))
                : ('/product/' + current.id);
        }
        updateWatchButtonState(current.id);
        saveRecentlyViewed({ id: current.id, name: current.name, price: current.price, image: current.image });
        renderRecentStrip(current.id);
        guidePriceEl.textContent = '$' + current.price.toFixed(2);
        tradeCountEl.textContent = current.trades > 0 ? (current.trades + ' TRADE' + (current.trades === 1 ? '' : 'S') + ' (90D)') : 'NO TRADE HISTORY';

        var maxQty = current.stock > 0 ? Math.min(current.stock, 99) : 0;
        qtyInput.value = maxQty > 0 ? 1 : 0;
        qtyInput.max = String(Math.max(maxQty, 0));
        qtyInput.disabled = maxQty <= 0;
        qtyMinus.disabled = maxQty <= 0;
        qtyPlus.disabled = maxQty <= 0;

        if (current.stock <= 0) {
            stockNote.textContent = 'OUT OF STOCK';
            confirmBtn.disabled = true;
            confirmBtn.textContent = 'OUT OF STOCK';
        } else {
            stockNote.textContent = current.stock + ' IN STOCK';
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'CONFIRM OFFER -- ADD TO BAG';
        }

        feedback.className = 'ex-feedback';
        feedback.textContent = '';
        updateTotal();

        if (current.acceptsOffers && current.stock > 0) {
            bidSection.style.display = 'block';
            bidMinNote.textContent = current.minOffer > 0 ? ('MINIMUM OFFER: $' + current.minOffer.toFixed(2)) : 'NAME YOUR PRICE';
            bidPrice.value = '';
            bidPrice.min = current.minOffer > 0 ? current.minOffer.toFixed(2) : '0';
            bidQty.value = '1';
            bidQty.max = String(Math.min(current.stock, 99));
            bidName.value = '';
            bidEmail.value = '';
            bidCompany.value = '';
            bidSubmitBtn.disabled = false;
            bidSubmitBtn.textContent = 'SUBMIT OFFER FOR REVIEW';
            bidFeedback.className = 'ex-feedback';
            bidFeedback.textContent = '';
        } else {
            bidSection.style.display = 'none';
        }

        // Panel timer
        var panelTimer = document.getElementById('exPanelTimer');
        var panelTimerCount = document.getElementById('exPanelTimerCount');
        if (current.expiresAt && panelTimer) {
            panelTimer.classList.add('show');
            updatePanelTimer();
        } else if (panelTimer) {
            panelTimer.classList.remove('show');
        }

        chartStatus.style.display = 'block';
        chartStatus.textContent = 'LOADING PRICE HISTORY...';
        clearChart();

        overlay.classList.add('open');
        document.addEventListener('keydown', onKeydown);
        closeBtn.focus();

        loadPriceHistory(current.id);
    }

    function closeModal() {
        overlay.classList.remove('open');
        document.removeEventListener('keydown', onKeydown);
        if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
        renderRecentStrip(null);
    }

    function onKeydown(e) {
        if (e.key === 'Escape') closeModal();
    }

    if (grid) {
        grid.addEventListener('click', function (e) {
            var slot = e.target.closest('.ex-slot');
            if (slot) openModal(slot);
        });
    }
    closeBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeModal();
    });

    function updateTotal() {
        if (!current) return;
        var qty = Math.max(0, parseInt(qtyInput.value, 10) || 0);
        totalEl.textContent = '$' + (qty * current.price).toFixed(2);
    }
    qtyMinus.addEventListener('click', function () {
        var v = Math.max(parseInt(qtyInput.min || '1', 10), (parseInt(qtyInput.value, 10) || 1) - 1);
        qtyInput.value = v;
        updateTotal();
    });
    qtyPlus.addEventListener('click', function () {
        var max = parseInt(qtyInput.max || '1', 10);
        var v = Math.min(max, (parseInt(qtyInput.value, 10) || 1) + 1);
        qtyInput.value = v;
        updateTotal();
    });
    qtyInput.addEventListener('input', function () {
        var max = parseInt(qtyInput.max || '1', 10);
        var min = parseInt(qtyInput.min || '1', 10);
        var v = parseInt(qtyInput.value, 10);
        if (isNaN(v)) v = min;
        v = Math.max(min, Math.min(max, v));
        qtyInput.value = v;
        updateTotal();
    });

    confirmBtn.addEventListener('click', function () {
        if (!current || current.stock <= 0) return;
        var qty = Math.max(1, parseInt(qtyInput.value, 10) || 1);

        confirmBtn.disabled = true;
        confirmBtn.textContent = 'FILLING OFFER...';

        fetch('/api/cart-add.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                product_id: current.id,
                name: current.name,
                price: current.price,
                quantity: qty,
                image: current.image
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.success) {
                feedback.className = 'ex-feedback ok show';
                feedback.innerHTML = 'OFFER FILLED. ' + qty + ' ADDED TO BAG. <a href="/cart.php" style="color:inherit; text-decoration:underline;">VIEW BAG</a>';
                if (cartCountEl && typeof data.count === 'number') cartCountEl.textContent = data.count;
                confirmBtn.textContent = 'OFFER FILLED';
            } else {
                feedback.className = 'ex-feedback err show';
                feedback.textContent = 'OFFER FAILED -- PLEASE TRY AGAIN.';
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'CONFIRM OFFER -- ADD TO BAG';
            }
        })
        .catch(function () {
            feedback.className = 'ex-feedback err show';
            feedback.textContent = 'NETWORK ERROR -- PLEASE TRY AGAIN.';
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'CONFIRM OFFER -- ADD TO BAG';
        });
    });

    bidSubmitBtn.addEventListener('click', function () {
        if (!current) return;

        var offeredPrice = parseFloat(bidPrice.value);
        var qty = Math.max(1, parseInt(bidQty.value, 10) || 1);
        var name = (bidName.value || '').trim();
        var email = (bidEmail.value || '').trim();

        bidFeedback.className = 'ex-feedback';
        bidFeedback.textContent = '';

        if (isNaN(offeredPrice) || offeredPrice <= 0) {
            bidFeedback.className = 'ex-feedback err show';
            bidFeedback.textContent = 'ENTER A VALID OFFER AMOUNT.';
            return;
        }
        if (current.minOffer > 0 && offeredPrice < current.minOffer) {
            bidFeedback.className = 'ex-feedback err show';
            bidFeedback.textContent = 'OFFER MUST BE AT LEAST $' + current.minOffer.toFixed(2) + '.';
            return;
        }
        if (name === '') {
            bidFeedback.className = 'ex-feedback err show';
            bidFeedback.textContent = 'PLEASE ENTER YOUR NAME.';
            return;
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            bidFeedback.className = 'ex-feedback err show';
            bidFeedback.textContent = 'PLEASE ENTER A VALID EMAIL.';
            return;
        }

        bidSubmitBtn.disabled = true;
        bidSubmitBtn.textContent = 'SUBMITTING...';

        fetch('/api/submit_offer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                product_id: current.id,
                offered_price: offeredPrice,
                quantity: qty,
                customer_name: name,
                customer_email: email,
                company: bidCompany.value || ''
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.ok) {
                bidFeedback.className = 'ex-feedback ok show';
                bidFeedback.textContent = 'OFFER SUBMITTED. WE WILL EMAIL YOU IF IT IS ACCEPTED.';
                bidSubmitBtn.textContent = 'OFFER SUBMITTED';
            } else {
                bidFeedback.className = 'ex-feedback err show';
                bidFeedback.textContent = (data && data.error) ? data.error.toUpperCase() : 'OFFER FAILED -- PLEASE TRY AGAIN.';
                bidSubmitBtn.disabled = false;
                bidSubmitBtn.textContent = 'SUBMIT OFFER FOR REVIEW';
            }
        })
        .catch(function () {
            bidFeedback.className = 'ex-feedback err show';
            bidFeedback.textContent = 'NETWORK ERROR -- PLEASE TRY AGAIN.';
            bidSubmitBtn.disabled = false;
            bidSubmitBtn.textContent = 'SUBMIT OFFER FOR REVIEW';
        });
    });

    // ---- Countdown timer logic ----
    var timerInterval = null;

    function formatCountdown(ms) {
        if (ms <= 0) return 'EXPIRED';
        var s = Math.floor(ms / 1000);
        var m = Math.floor(s / 60); s %= 60;
        var h = Math.floor(m / 60); m %= 60;
        var d = Math.floor(h / 24); h %= 24;
        if (d > 0) return d + 'D ' + h + 'H ' + m + 'M';
        if (h > 0) return h + 'H ' + m + 'M ' + s + 'S';
        return m + 'M ' + s + 'S';
    }

    function isUrgent(ms) { return ms > 0 && ms < 3600000; } // under 1 hour

    function updatePanelTimer() {
        if (!current || !current.expiresAt) return;
        var exp = new Date(current.expiresAt.replace(' ', 'T') + 'Z');
        var ms = exp - Date.now();
        var count = document.getElementById('exPanelTimerCount');
        var panel = document.getElementById('exPanelTimer');
        if (count) count.textContent = formatCountdown(ms);
        if (panel) {
            if (isUrgent(ms)) panel.classList.add('urgent');
            else panel.classList.remove('urgent');
        }
    }

    function startTimerInterval() {
        if (timerInterval) clearInterval(timerInterval);
        timerInterval = setInterval(function () {
            updatePanelTimer();
            // Update all slot timers
            document.querySelectorAll('[data-slot-timer]').forEach(function (el) {
                var exp = new Date(el.getAttribute('data-slot-timer').replace(' ', 'T') + 'Z');
                var ms = exp - Date.now();
                var countEl = el.querySelector('.ex-timer-count');
                if (countEl) countEl.textContent = formatCountdown(ms);
                if (isUrgent(ms)) el.classList.add('urgent');
                else el.classList.remove('urgent');
                if (ms <= 0) el.style.display = 'none';
            });
            // Update active bids panel row timers
            document.querySelectorAll('.ex-bid-row').forEach(function (row) {
                var exp = new Date(row.getAttribute('data-expires').replace(' ', 'T') + 'Z');
                var ms = exp - Date.now();
                var timerEl = row.querySelector('[data-bid-timer]');
                if (timerEl) timerEl.textContent = formatCountdown(ms);
                if (isUrgent(ms)) timerEl && timerEl.classList.add('urgent');
                else timerEl && timerEl.classList.remove('urgent');
                if (ms <= 0) row.style.display = 'none';
            });
        }, 1000);
    }

    // Init bid row timers on load (before the 1s interval kicks in)
    document.querySelectorAll('.ex-bid-row').forEach(function (row) {
        var exp = new Date(row.getAttribute('data-expires').replace(' ', 'T') + 'Z');
        var ms = exp - Date.now();
        var timerEl = row.querySelector('[data-bid-timer]');
        if (timerEl) timerEl.textContent = formatCountdown(ms);
    });

    // Active Bids slide-out panel open/close
    var bidsTab = document.getElementById('exBidsTab');
    var bidsPanel = document.getElementById('exBidsPanel');
    var bidsClose = document.getElementById('exBidsClose');

    function openBidsPanel() {
        if (bidsPanel) bidsPanel.classList.add('open');
        if (bidsTab) { bidsTab.classList.add('panel-open'); bidsTab.setAttribute('aria-expanded', 'true'); }
        if (bidsPanel) bidsPanel.setAttribute('aria-hidden', 'false');
    }
    function closeBidsPanel() {
        if (bidsPanel) bidsPanel.classList.remove('open');
        if (bidsTab) { bidsTab.classList.remove('panel-open'); bidsTab.setAttribute('aria-expanded', 'false'); }
        if (bidsPanel) bidsPanel.setAttribute('aria-hidden', 'true');
    }
    if (bidsTab) {
        bidsTab.addEventListener('click', function () {
            if (bidsPanel && bidsPanel.classList.contains('open')) closeBidsPanel();
            else openBidsPanel();
        });
    }
    if (bidsClose) bidsClose.addEventListener('click', closeBidsPanel);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && bidsPanel && bidsPanel.classList.contains('open')) closeBidsPanel();
    });

    // Init slot timers on load
    document.querySelectorAll('[data-slot-timer]').forEach(function (el) {
        var exp = new Date(el.getAttribute('data-slot-timer').replace(' ', 'T') + 'Z');
        var ms = exp - Date.now();
        var countEl = el.querySelector('.ex-timer-count');
        if (countEl) countEl.textContent = formatCountdown(ms);
        if (ms <= 0) el.style.display = 'none';
    });

    startTimerInterval();
    renderRecentStrip(null);
    initLiveStatusPolling();

    function clearChart() {
        var ctx = chartCanvas.getContext('2d');
        ctx.clearRect(0, 0, chartCanvas.width, chartCanvas.height);
    }

    function drawChart(points, guidePrice) {
        var ctx = chartCanvas.getContext('2d');
        var w = chartCanvas.width, h = chartCanvas.height;
        ctx.clearRect(0, 0, w, h);

        if (!points || points.length < 2) return;

        var prices = points.map(function (p) { return p.price; });
        var min = Math.min.apply(null, prices);
        var max = Math.max.apply(null, prices);
        if (min === max) { min -= 1; max += 1; }
        var pad = 10;

        function xAt(i) { return pad + (i / (points.length - 1)) * (w - pad * 2); }
        function yAt(v) { return h - pad - ((v - min) / (max - min)) * (h - pad * 2); }

        ctx.strokeStyle = 'rgba(255,170,0,0.4)';
        ctx.setLineDash([4, 4]);
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(0, yAt(guidePrice));
        ctx.lineTo(w, yAt(guidePrice));
        ctx.stroke();
        ctx.setLineDash([]);

        ctx.beginPath();
        ctx.moveTo(xAt(0), h - pad);
        points.forEach(function (p, i) { ctx.lineTo(xAt(i), yAt(p.price)); });
        ctx.lineTo(xAt(points.length - 1), h - pad);
        ctx.closePath();
        var grad = ctx.createLinearGradient(0, 0, 0, h);
        grad.addColorStop(0, 'rgba(0,255,157,0.25)');
        grad.addColorStop(1, 'rgba(0,255,157,0)');
        ctx.fillStyle = grad;
        ctx.fill();

        ctx.beginPath();
        points.forEach(function (p, i) {
            var x = xAt(i), y = yAt(p.price);
            if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        });
        ctx.strokeStyle = '#00ff9d';
        ctx.lineWidth = 2;
        ctx.stroke();

        var lastX = xAt(points.length - 1), lastY = yAt(points[points.length - 1].price);
        ctx.fillStyle = '#00ff9d';
        ctx.beginPath();
        ctx.arc(lastX, lastY, 3, 0, Math.PI * 2);
        ctx.fill();
    }

    function loadPriceHistory(productId) {
        fetch('/api/exchange_price_history.php?product_id=' + encodeURIComponent(productId), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!current || current.id !== productId) return;
                if (data && data.ok) {
                    guidePriceEl.textContent = '$' + Number(data.guidePrice).toFixed(2);
                    tradeCountEl.textContent = data.tradeCount > 0
                        ? (data.tradeCount + ' TRADE' + (data.tradeCount === 1 ? '' : 'S') + ' (90D)')
                        : 'NO TRADE HISTORY';
                    if (data.hasHistory && data.points && data.points.length >= 2) {
                        chartStatus.style.display = 'none';
                        drawChart(data.points, data.guidePrice);
                    } else {
                        chartStatus.style.display = 'block';
                        chartStatus.textContent = 'NO TRADE HISTORY YET -- LISTED AT $' + Number(data.currentPrice).toFixed(2);
                        clearChart();
                    }
                } else {
                    chartStatus.textContent = 'PRICE HISTORY UNAVAILABLE';
                }
            })
            .catch(function () {
                chartStatus.textContent = 'PRICE HISTORY UNAVAILABLE';
            });
    }
})();
</script>

<?php
$footerPath = __DIR__ . '/partials/footer.php';
if (is_file($footerPath)) {
    include $footerPath;
}
?>
</body>
</html>