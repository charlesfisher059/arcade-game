<?php
// services.php — SERVICES + DYNAMIC PORTFOLIO + REQUEST FORM (v2.5.1)
// Path: /home2/asqrtyte/public_html/services.php
// FIX (v2.5.1 — header overlap / over-gapping "SERVICES"):
// - ✅ Dynamically measures fixed header height and sets CSS var (--headerH)
// - ✅ main padding-top now uses --headerH so H1 never sits under header (desktop + mobile)
// - ✅ Includes resize-safe recalculation (debounced)
// - ✅ UI preserved

declare(strict_types=1);

/* ─────────────────────────────
   0) SESSION (HARDENED) + HEADERS
───────────────────────────── */

// Best-effort HTTPS detection (HostGator/forward proxy safe-ish)
$HTTPS = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
      || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
      || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
      || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');

$domain = (string)($_SERVER['HTTP_HOST'] ?? '');
$domain = preg_replace('/:\d+$/', '', $domain); // strip port if present

// Align cookie params with other pages (shop/cart/product/header)
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    $cookieParams = [
        'lifetime' => 86400 * 7, // 7 days
        'path'     => '/',
        'secure'   => $HTTPS,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if ($domain !== '') {
        $cookieParams['domain'] = $domain;
    }
    session_set_cookie_params($cookieParams);

    session_start([
        'cookie_httponly' => true,
        'cookie_secure'   => $HTTPS,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
        'gc_maxlifetime'  => 86400 * 7,
        'cookie_lifetime' => 86400 * 7,
    ]);
} elseif (session_status() === PHP_SESSION_NONE) {
    // Best-effort if headers already sent
    @session_start();
}

// Security headers (best effort)
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

    // CSP: keep inline styles/scripts + Google Fonts.
    // Allow http: too (some DB-stored assets may still be http; avoids mixed-content surprises during transition).
    $csp = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline'",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
        "font-src 'self' https://fonts.gstatic.com data:",
        "img-src 'self' data: https: http:",
        "media-src 'self' https: http:",
        "connect-src 'self' https: http:",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
    ];
    header('Content-Security-Policy: ' . implode('; ', $csp));
}

/* ─────────────────────────────
   DB
───────────────────────────── */
require_once __DIR__ . '/db_connect.php';

// Graceful branded offline page if db_connect didn't yield a PDO
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>SERVICES_OFFLINE | DIAMONDS OUTTA DIRT</title>';
    echo '<style>body{margin:0;background:#000;color:#fff;font-family:ui-monospace,Menlo,Consolas,monospace;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px} .box{max-width:760px;border:1px solid #222;background:#050505;padding:22px;border-radius:12px} .h{color:#00ff9d;letter-spacing:2px;font-weight:900;margin:0 0 10px} .p{color:#bbb;line-height:1.6;margin:0 0 10px} a{color:#00ff9d;text-decoration:none} code{color:#00ff9d}</style>';
    @include_once __DIR__ . '/includes/bg_styles.php';
    echo '</head><body><div class="box">';
    echo '<div class="h">SERVICES_OFFLINE</div>';
    echo '<p class="p">The services page is up, but the database connection is currently unavailable.</p>';
    echo '<p class="p">Check <code>db_connect.php</code> and MySQL status, then refresh.</p>';
    echo '<p class="p"><a href="/">Return Home</a></p>';
    echo '</div>
</body></html>';
    exit;
}

/* ─────────────────────────────
   HELPERS
───────────────────────────── */
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function cart_count_qty(): int {
    // v5 structure: $_SESSION['cart']['items'] = [ [quantity=>..], ... ]
    if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) return 0;

    $t = 0;

    if (isset($_SESSION['cart']['items']) && is_array($_SESSION['cart']['items'])) {
        foreach ($_SESSION['cart']['items'] as $i) {
            if (!is_array($i)) continue;
            $t += max(0, (int)($i['quantity'] ?? 1));
        }
        return $t;
    }

    // Legacy: $_SESSION['cart'] might be an array of items or id=>qty
    foreach ($_SESSION['cart'] as $k => $v) {
        if ($k === 'items') continue;
        if (is_array($v)) {
            $t += max(0, (int)($v['quantity'] ?? 1));
        } elseif (is_numeric($v)) {
            $t += max(0, (int)$v);
        }
    }

    return $t;
}

function ensure_csrf_token(): string {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || strlen($_SESSION['csrf_token']) < 32) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function isWithinBase(string $path, string $baseDir): bool {
    $rp = realpath($path);
    $rb = realpath($baseDir);
    if ($rp === false || $rb === false) return false;
    $rb = rtrim($rb, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return str_starts_with($rp, $rb);
}

// Simple tag inference from filename prefixes (front-end filters read these tags)
function infer_portfolio_tag(string $filename, bool $isVideo): string {
    $f = strtolower($filename);
    if ($isVideo) return 'video';

    $map = [
        'emb_' => 'embroidery',
        'embro' => 'embroidery',
        'stitch' => 'embroidery',
        'htv_' => 'htv',
        'vinyl' => 'htv',
        'sub_' => 'sublimation',
        'subl' => 'sublimation',
        'dye_' => 'sublimation',
        'print_' => 'print',
        'dtg_' => 'print',
        'screen' => 'print',
        'sew_' => 'sewing',
        'cut_' => 'cut',
    ];

    foreach ($map as $needle => $tag) {
        if (str_starts_with($f, $needle) || str_contains($f, $needle)) {
            return $tag;
        }
    }
    return 'other';
}

$bag_qty = cart_count_qty();
$CSRF = ensure_csrf_token();

/* ─────────────────────────────
   FLASH (SUCCESS/ERROR DISPLAY)
───────────────────────────── */
$flashSuccess = (string)($_GET['success'] ?? '');
$flashError   = (string)($_GET['error'] ?? '');
$flashMsg     = (string)($_GET['message'] ?? '');
$flashId      = (string)($_GET['id'] ?? '');

/* ─────────────────────────────
   SERVICES DATA
───────────────────────────── */
$services = [
    [
        'title'    => 'Custom Apparel Printing',
        'desc'     => 'T-shirts, hoodies, tote bags, and custom garments using HTV, embroidery, and sublimation.',
        'protocol' => 'APPAREL_PRINT',
        'key'      => 'print',
    ],
    [
        'title'    => 'Embroidery Services',
        'desc'     => 'Clean, durable embroidery for logos, names, and patches.',
        'protocol' => 'THREAD_WORK',
        'key'      => 'embroidery',
    ],
    [
        'title'    => 'Sublimation Printing',
        'desc'     => 'High-detail full-color prints for apparel, accessories, and art pieces.',
        'protocol' => 'DYE_FUSION',
        'key'      => 'sublimation',
    ],
    [
        'title'    => 'Small-Batch Manufacturing',
        'desc'     => 'Limited runs for brands, artists, and events.',
        'protocol' => 'BATCH_RUN',
        'key'      => 'manufacturing',
    ],
    [
        'title'    => 'Design Assistance',
        'desc'     => 'File cleanup, vector prep, sizing, and production consulting.',
        'protocol' => 'PREP_LAB',
        'key'      => 'design',
    ],
];

/* ─────────────────────────────
   EQUIPMENT / CAPABILITIES
───────────────────────────── */
$equipment = [
    'Embroidery'   => 'Brother PE800 (5x7 hoop)',
    'HTV'          => 'Silhouette Cameo 2 + 15x15 heat press',
    'Sublimation'  => 'Epson ET-2480',
    'Sewing'       => 'Brother sewing machine',
];

/* ─────────────────────────────
   PORTFOLIO SCAN (SAFE + URL-CORRECT)
───────────────────────────── */
$portfolio = [];
$hasFiles = false;

$portfolioWebBase = '/images/services/';
$portfolioFsBase  = __DIR__ . '/images/services/';
$thumbWebBase     = '/images/thumbs/';
$thumbFsBase      = __DIR__ . '/images/thumbs/';

$allowedVideo = ['mp4', 'webm', 'mov'];
$allowedImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

$maxScan = 2500;
$scanned = 0;

if (is_dir($portfolioFsBase) && is_readable($portfolioFsBase)) {
    $files = @scandir($portfolioFsBase);
    if (is_array($files)) {
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            if ($file === '' || $file[0] === '.') continue;

            $scanned++;
            if ($scanned > $maxScan) break;

            $fsPath = $portfolioFsBase . $file;
            if (!is_file($fsPath) || !is_readable($fsPath)) continue;
            if (!isWithinBase($fsPath, $portfolioFsBase)) continue;

            $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
            $isVideo = in_array($ext, $allowedVideo, true);
            $isImage = in_array($ext, $allowedImage, true);
            if (!$isVideo && !$isImage) continue;

            $hasFiles = true;

            $webUrl = rtrim($portfolioWebBase, '/') . '/' . rawurlencode($file);

            // Poster/thumb
            $posterUrl = '';
            if ($isVideo) {
                $posterFs = $thumbFsBase . $file . '.jpg';
                if (is_file($posterFs) && is_readable($posterFs) && isWithinBase($posterFs, $thumbFsBase)) {
                    $posterUrl = rtrim($thumbWebBase, '/') . '/' . rawurlencode($file) . '.jpg';
                }
            }

            $tag = infer_portfolio_tag($file, $isVideo);

            $portfolio[] = [
                'name' => $file,
                'ext'  => $ext,
                'url'  => $webUrl,
                'is_video' => $isVideo,
                'poster' => $posterUrl,
                'mtime' => (int)@filemtime($fsPath),
                'size'  => (int)@filesize($fsPath),
                'tag'   => $tag,
            ];
        }
    }
}

if (!empty($portfolio)) {
    usort($portfolio, fn($a, $b) => ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0));
}

$siteName = 'Diamonds Outta Dirt';
$pageTitle = 'Services | Diamonds Outta Dirt';
$pageDesc = 'Custom apparel printing, embroidery, sublimation, small-batch manufacturing, and design assistance. Explore selected work and request a quote.';
$ogImage = '/images/placeholder.jpg'; // swap to a real brand image when ready

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= h($pageTitle) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="description" content="<?= h($pageDesc) ?>">
    <meta property="og:site_name" content="<?= h($siteName) ?>">
    <meta property="og:title" content="<?= h($pageTitle) ?>">
    <meta property="og:description" content="<?= h($pageDesc) ?>">
    <meta property="og:type" content="website">
    <meta property="og:image" content="<?= h($ogImage) ?>">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="stylesheet" href="css/master.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@900&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">

    <style>
        :root{
            /* default fallback; JS updates this to real header height */
            --headerH: 120px;
            --mainPad: 22px;
        }

        html{
            scroll-behavior:smooth;
            scroll-padding-top: calc(var(--headerH) + 12px);
        }

        body {
            background:#000;
            color:#fff;
            margin:0;
            font-family:'Space Mono', monospace;
        }

        /* ─────────── HERO BACKDROP (SUBTLE) ─────────── */
        body::before{
            content:"";
            position:fixed; inset:0;
            background:
                radial-gradient(800px 500px at 20% 10%, rgba(0,255,157,0.10), transparent 55%),
                radial-gradient(700px 480px at 80% 30%, rgba(0,160,255,0.10), transparent 60%),
                radial-gradient(900px 600px at 50% 90%, rgba(255,0,85,0.08), transparent 55%),
                linear-gradient(180deg, rgba(255,255,255,0.02), transparent 35%);
            pointer-events:none;
            z-index:-2;
        }
        body::after{
            content:"";
            position:fixed; inset:0;
            background:
                repeating-linear-gradient(0deg,
                    rgba(255,255,255,0.03) 0px,
                    rgba(255,255,255,0.03) 1px,
                    transparent 2px,
                    transparent 6px);
            opacity:0.06;
            mix-blend-mode:overlay;
            pointer-events:none;
            z-index:-1;
        }

        header {
            position:fixed;
            top:0;
            width:100%;
            z-index:999;
            background:rgba(0,0,0,0.92);
            border-bottom:1px solid #222;
            backdrop-filter: blur(8px);
        }

        .top-bar {
            display:flex;
            justify-content:space-between;
            align-items:center;
            flex-wrap:wrap;
            gap:10px;
            padding:12px 16px;
        }

        .nav-row{
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            align-items:center;
        }

        .top-link {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:44px;
            padding:10px 12px;
            text-decoration:none;
            border:1px solid #222;
            border-radius:10px;
            background:rgba(255,255,255,0.02);
            letter-spacing:1px;
            transition: transform .15s ease, border-color .15s ease, box-shadow .15s ease;
            color:#fff;
            white-space:nowrap;
        }
        .top-link:hover{
            transform: translateY(-1px);
            border-color: rgba(0,255,157,0.35);
            box-shadow: 0 10px 30px rgba(0,0,0,0.35);
        }

        .top-link.brand-link { color:#fff; }
        .top-link.bag-link { color:var(--neon); border-color: rgba(0,255,157,0.25); }
        .top-link.active { border-color: rgba(0,255,157,0.55); box-shadow:0 0 0 1px rgba(0,255,157,0.14); }

        /* ✅ FIX: main padding-top uses measured header height */
        main {
            padding: calc(var(--headerH) + var(--mainPad)) 8% 120px;
            max-width:1200px;
            margin:0 auto;
        }

        h1 {
            font-family:'Inter';
            font-size:3rem;
            margin:0 0 10px;
            letter-spacing:2px;
        }

        .subtitle{
            color:#9a9a9a;
            margin:0 0 26px;
            letter-spacing:1px;
            font-size:0.92rem;
            text-transform:none;
            line-height:1.6;
            max-width: 880px;
        }

        /* ─────────── FLASH ─────────── */
        .flash{
            border:1px solid #222;
            background:rgba(0,0,0,0.55);
            padding:12px 14px;
            border-radius:12px;
            margin: 0 0 18px;
            text-transform:none;
            line-height:1.5;
        }
        .flash.ok{ border-color: rgba(0,255,157,0.35); color:#bfffe2; }
        .flash.err{ border-color: rgba(255,0,85,0.35); color:#ffb4c6; }

        /* ─────────── TRUST STRIP ─────────── */
        .trust-strip{
            display:grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap:16px;
            border:1px solid rgba(255,255,255,0.10);
            background:rgba(0,0,0,0.55);
            border-radius:16px;
            padding:16px;
            margin: 18px 0 10px;
        }
        .trust-left .kicker{
            color:var(--neon);
            letter-spacing:2px;
            font-weight:800;
            margin:0 0 8px;
            font-size:0.78rem;
            text-transform:uppercase;
        }
        .trust-left .line{
            margin:0;
            color:#d0d0d0;
            text-transform:none;
            line-height:1.6;
        }
        .trust-chips{
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            align-items:flex-start;
            justify-content:flex-end;
        }
        .chip{
            display:inline-flex;
            gap:8px;
            align-items:center;
            padding:9px 10px;
            border:1px solid rgba(255,255,255,0.12);
            border-radius:999px;
            background:rgba(255,255,255,0.03);
            font-size:0.72rem;
            letter-spacing:1px;
            color:#cfcfcf;
            text-transform:none;
        }
        .chip b{ color:var(--neon); }

        /* ─────────── SECTION TITLES ─────────── */
        .section-title{
            display:flex;
            align-items:center;
            gap:12px;
            margin:60px 0 18px;
        }
        .section-title h2{
            margin:0;
            font-family:'Inter';
            letter-spacing:2px;
            font-size:1.35rem;
            color:var(--neon);
        }
        .section-title .bar{
            height:1px;
            flex:1;
            background:linear-gradient(90deg, var(--neon), transparent);
            opacity:0.75;
        }
        .section-hint{
            color:#777;
            margin:-6px 0 18px;
            font-size:0.82rem;
            letter-spacing:1px;
            text-transform:none;
        }

        /* SERVICES GRID */
        .services-grid {
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
            gap:28px;
            margin-bottom:14px;
        }

        .service-card {
            position:relative;
            border:1px solid rgba(0,255,157,0.55);
            padding:28px;
            background:rgba(0,0,0,0.55);
            border-radius:14px;
            overflow:hidden;
            transform:translateZ(0);
            transition: box-shadow .2s ease, transform .2s ease, border-color .2s ease;
        }

        .service-card::before{
            content:"";
            position:absolute; inset:-1px;
            background:
                radial-gradient(500px 200px at 20% 0%, rgba(0,255,157,0.16), transparent 60%),
                radial-gradient(520px 240px at 80% 100%, rgba(0,160,255,0.12), transparent 55%);
            opacity:0;
            transition:opacity .25s ease;
            pointer-events:none;
        }
        .service-card:hover::before{ opacity:1; }
        .service-card:hover{
            transform: translateY(-2px);
            border-color: rgba(0,255,157,0.75);
            box-shadow:0 0 0 1px rgba(0,255,157,0.18), 0 18px 55px rgba(0,0,0,0.55);
        }

        .service-card h3 {
            color:var(--neon);
            margin:0 0 12px;
            font-family:'Inter';
            letter-spacing:1px;
            font-size:1.05rem;
        }

        .service-card p{
            margin:0;
            color:#d0d0d0;
            line-height:1.6;
            text-transform:none;
        }

        .service-pill{
            display:inline-flex;
            gap:8px;
            align-items:center;
            padding:8px 10px;
            border:1px solid rgba(255,255,255,0.12);
            border-radius:999px;
            background:rgba(255,255,255,0.03);
            font-size:0.72rem;
            letter-spacing:1px;
            color:#cfcfcf;
            margin-top:14px;
            text-transform:none;
        }
        .service-pill b{ color:var(--neon); text-transform:uppercase; }

        /* EQUIPMENT */
        .equipment {
            border-left:2px solid var(--neon);
            padding-left:24px;
            margin:0 0 18px;
        }

        .equipment li {
            margin-bottom:10px;
            color:#bbb;
            font-size:0.9rem;
            text-transform:none;
        }

        /* PROCESS TIMELINE */
        .timeline{
            display:grid;
            grid-template-columns:repeat(5, minmax(0,1fr));
            gap:12px;
            margin: 16px 0 0;
        }
        .step{
            border:1px solid rgba(255,255,255,0.10);
            background:rgba(0,0,0,0.45);
            border-radius:14px;
            padding:12px 12px;
            position:relative;
            overflow:hidden;
        }
        .step::before{
            content:"";
            position:absolute; inset:0;
            background: radial-gradient(340px 180px at 10% 10%, rgba(0,255,157,0.10), transparent 60%);
            opacity:0.9;
            pointer-events:none;
        }
        .step .n{
            font-family:'Inter';
            font-weight:900;
            color:var(--neon);
            margin:0 0 6px;
            letter-spacing:2px;
            font-size:0.85rem;
        }
        .step .t{
            margin:0;
            color:#d0d0d0;
            text-transform:none;
            line-height:1.45;
            font-size:0.82rem;
        }

        /* PORTFOLIO CONTROLS */
        .portfolio-controls{
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            align-items:center;
            justify-content:space-between;
            margin: 8px 0 12px;
        }
        .filter-row, .sort-row{
            display:flex;
            flex-wrap:wrap;
            gap:8px;
            align-items:center;
        }
        .pill-btn{
            border:1px solid rgba(255,255,255,0.12);
            background:rgba(255,255,255,0.03);
            color:#ddd;
            border-radius:999px;
            padding:9px 10px;
            cursor:pointer;
            font-family:'Space Mono', monospace;
            font-size:0.72rem;
            letter-spacing:1px;
            transition: transform .15s ease, border-color .15s ease, box-shadow .15s ease;
        }
        .pill-btn:hover{
            transform: translateY(-1px);
            border-color: rgba(0,255,157,0.35);
            box-shadow:0 10px 26px rgba(0,0,0,0.35);
        }
        .pill-btn.on{
            border-color: rgba(0,255,157,0.55);
            color:#bfffe2;
            box-shadow:0 0 0 1px rgba(0,255,157,0.14);
        }

        .select{
            border:1px solid rgba(255,255,255,0.12);
            background:rgba(255,255,255,0.03);
            color:#fff;
            border-radius:12px;
            padding:10px 12px;
            font-family:'Space Mono', monospace;
            font-size:0.8rem;
            letter-spacing:1px;
        }

        /* PORTFOLIO */
        .portfolio {
            display:grid;
            grid-template-columns:repeat(auto-fill,minmax(220px,1fr));
            gap:18px;
            margin-bottom:16px;
        }

        .portfolio-item {
            position: relative;
            border: 1px solid #222;
            background: #000;
            height: 220px;
            overflow: hidden;
            cursor: pointer;
            border-radius:14px;
            transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
        }

        .portfolio-item img,
        .portfolio-item video {
            width:100%;
            height:100%;
            object-fit:cover;
            transition: transform 0.3s ease;
            display:block;
        }

        .portfolio-item:hover {
            transform: translateY(-2px);
            border-color: rgba(0,255,157,0.35);
            box-shadow: 0 18px 55px rgba(0,0,0,0.55);
        }

        .portfolio-item:hover img,
        .portfolio-item:hover video {
            transform: scale(1.05);
        }

        .portfolio-play {
            position:absolute;
            inset:0;
            display:none;
            align-items:center;
            justify-content:center;
            pointer-events:none;
            background: linear-gradient(to top, rgba(0,0,0,0.55), rgba(0,0,0,0.0));
        }
        .portfolio-item[data-is-video="1"] .portfolio-play { display:flex; }

        .portfolio-play span{
            width:54px;
            height:54px;
            border-radius:999px;
            display:flex;
            align-items:center;
            justify-content:center;
            background: rgba(0,0,0,0.65);
            border:1px solid rgba(255,255,255,0.12);
            color:#fff;
            font-size:22px;
            box-shadow: 0 10px 24px rgba(0,0,0,0.45);
        }

        .portfolio-item::after{
            content:attr(data-caption);
            position:absolute;
            left:0; right:0; bottom:0;
            padding:10px 12px;
            font-size:0.65rem;
            letter-spacing:1px;
            color:#fff;
            background:linear-gradient(to top, rgba(0,0,0,0.82), rgba(0,0,0,0));
            opacity:0;
            transform:translateY(8px);
            transition:all .22s ease;
            pointer-events:none;
            text-transform:none;
        }
        .portfolio-item:hover::after{
            opacity:1;
            transform:translateY(0);
        }

        /* LIGHTBOX */
        .lightbox {
            display:none;
            position:fixed;
            inset:0;
            z-index:9999;
            background: rgba(0,0,0,0.92);
            padding: 18px;
            align-items:center;
            justify-content:center;
        }
        .lightbox.open { display:flex; }
        .lightbox-inner{
            width:min(980px, 96vw);
            max-height: 92vh;
            position:relative;
        }
        .lightbox-close{
            position:absolute;
            top:-46px;
            right:0;
            width:44px;
            height:44px;
            border-radius:999px;
            border:1px solid rgba(255,255,255,0.14);
            background:#ff0055;
            color:#fff;
            display:flex;
            align-items:center;
            justify-content:center;
            cursor:pointer;
            font-size:18px;
        }
        .lb-nav{
            position:absolute;
            top:-46px;
            left:0;
            display:flex;
            gap:10px;
        }
        .lb-btn{
            width:44px;
            height:44px;
            border-radius:999px;
            border:1px solid rgba(255,255,255,0.14);
            background:rgba(255,255,255,0.04);
            color:#fff;
            display:flex;
            align-items:center;
            justify-content:center;
            cursor:pointer;
            font-size:16px;
        }
        .lb-btn:hover{
            border-color: rgba(0,255,157,0.35);
            box-shadow:0 10px 26px rgba(0,0,0,0.35);
        }
        .lightbox-media img,
        .lightbox-media video{
            width:100%;
            max-height: 82vh;
            border-radius:12px;
            border:1px solid rgba(255,255,255,0.10);
            background:#000;
            display:block;
        }
        .lb-meta{
            margin-top:10px;
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            align-items:center;
            justify-content:space-between;
            text-transform:none;
        }
        .lb-meta .left{
            color:#cfcfcf;
            font-size:0.82rem;
            letter-spacing:1px;
        }
        .lb-meta .right a{
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:9px 10px;
            border:1px solid rgba(255,255,255,0.12);
            border-radius:999px;
            background:rgba(255,255,255,0.03);
            color:#fff;
            text-decoration:none;
            font-size:0.72rem;
            letter-spacing:1px;
        }
        .lb-meta .right a:hover{
            border-color: rgba(0,255,157,0.35);
            box-shadow:0 10px 26px rgba(0,0,0,0.35);
        }

        /* CONFIGURATOR */
        .config{
            border:1px solid rgba(0,255,157,0.35);
            border-radius:16px;
            background:rgba(0,0,0,0.55);
            padding:16px;
            margin: 6px 0 16px;
            box-shadow:0 18px 60px rgba(0,0,0,0.45);
        }
        .config .row{
            display:grid;
            grid-template-columns:repeat(3, minmax(0,1fr));
            gap:12px;
        }
        .config label{
            display:block;
            color:#9a9a9a;
            font-size:0.72rem;
            letter-spacing:1px;
            margin:0 0 6px;
            text-transform:uppercase;
        }
        .config input, .config select{
            width:100%;
            background:#000;
            color:#fff;
            border:1px solid rgba(0,255,157,0.55);
            border-radius:12px;
            padding:12px 12px;
            font-family:'Space Mono';
            box-sizing:border-box;
        }
        .config .out{
            margin-top:12px;
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            align-items:center;
            justify-content:space-between;
        }
        .range{
            display:inline-flex;
            align-items:center;
            gap:10px;
            padding:10px 12px;
            border:1px solid rgba(255,255,255,0.12);
            background:rgba(255,255,255,0.03);
            border-radius:999px;
            color:#fff;
            font-size:0.85rem;
            letter-spacing:1px;
            text-transform:none;
        }
        .range b{ color:var(--neon); }
        .config .note{
            color:#777;
            font-size:0.78rem;
            text-transform:none;
            line-height:1.5;
        }
        .config .actions{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            align-items:center;
        }
        .mini-btn{
            border:none;
            background:var(--neon);
            color:#000;
            padding:10px 12px;
            border-radius:12px;
            cursor:pointer;
            font-family:'Space Mono', monospace;
            font-weight:800;
            letter-spacing:1px;
            text-transform:uppercase;
        }
        .mini-btn.alt{
            background:transparent;
            border:1px solid rgba(255,255,255,0.14);
            color:#fff;
            font-weight:700;
        }

        /* FORM */
        form#quoteForm {
            max-width:820px;
            margin-top:0;
            border:1px solid rgba(0,255,157,0.35);
            border-radius:16px;
            padding:18px;
            background:rgba(0,0,0,0.55);
            box-shadow:0 18px 60px rgba(0,0,0,0.45);
        }

        form#quoteForm input, form#quoteForm select, form#quoteForm textarea {
            width:100%;
            background:#000;
            color:#fff;
            border:1px solid rgba(0,255,157,0.55);
            border-radius:12px;
            padding:14px;
            font-family:'Space Mono';
            margin-bottom:16px;
            box-sizing:border-box;
        }

        form#quoteForm input:focus, form#quoteForm select:focus, form#quoteForm textarea:focus{
            outline:none;
            box-shadow:0 0 0 3px rgba(0,255,157,0.18);
        }

        .form-row{
            display:grid;
            grid-template-columns: 1fr 1fr;
            gap:12px;
        }
        .form-row .full{ grid-column: 1 / -1; }

        .file-hint{
            color:#888;
            font-size:0.8rem;
            margin:-8px 0 10px;
            text-transform:none;
        }
        .file-meta{
            display:none;
            padding:10px 12px;
            border:1px solid rgba(255,255,255,0.12);
            border-radius:12px;
            background:rgba(255,255,255,0.03);
            color:#cfcfcf;
            font-size:0.8rem;
            text-transform:none;
            margin:-6px 0 14px;
        }
        .file-meta.err{
            border-color: rgba(255,0,85,0.35);
            color:#ffb4c6;
        }

        button.big {
            background:var(--neon);
            color:#000;
            border:none;
            padding:18px;
            font-weight:bold;
            cursor:pointer;
            width:100%;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s ease;
            border-radius:14px;
            position:relative;
            overflow:hidden;
        }

        button.big::before{
            content:"";
            position:absolute; inset:0;
            background:linear-gradient(90deg, transparent, rgba(255,255,255,0.35), transparent);
            transform:translateX(-120%);
        }
        button.big:hover::before{
            transform:translateX(120%);
            transition:transform .75s ease;
        }

        button.big:hover {
            background: #fff;
            box-shadow: 0 0 15px var(--neon);
        }

        /* Sticky Quote Drawer */
        .drawer{
            position:fixed;
            right:16px;
            bottom:16px;
            z-index:9998;
            width:min(360px, calc(100vw - 32px));
            border:1px solid rgba(0,255,157,0.35);
            border-radius:16px;
            background:rgba(0,0,0,0.72);
            box-shadow:0 18px 60px rgba(0,0,0,0.55);
            overflow:hidden;
            backdrop-filter: blur(10px);
        }
        .drawer .head{
            display:flex;
            align-items:center;
            justify-content:space-between;
            padding:12px 12px;
            border-bottom:1px solid rgba(255,255,255,0.10);
        }
        .drawer .head .ttl{
            font-family:'Inter';
            letter-spacing:2px;
            font-size:0.92rem;
            margin:0;
            color:var(--neon);
        }
        .drawer .head .sub{
            margin:0;
            color:#8a8a8a;
            font-size:0.74rem;
            letter-spacing:1px;
            text-transform:none;
        }
        .drawer .head .x{
            width:40px;
            height:40px;
            border-radius:999px;
            border:1px solid rgba(255,255,255,0.14);
            background:rgba(255,255,255,0.03);
            color:#fff;
            cursor:pointer;
        }
        .drawer .body{
            padding:12px 12px;
        }
        .drawer .mini-row{
            display:grid;
            grid-template-columns: 1fr 1fr;
            gap:10px;
        }
        .drawer input, .drawer select{
            width:100%;
            background:#000;
            color:#fff;
            border:1px solid rgba(0,255,157,0.35);
            border-radius:12px;
            padding:12px;
            font-family:'Space Mono', monospace;
            box-sizing:border-box;
        }
        .drawer .btns{
            display:flex;
            gap:10px;
            margin-top:10px;
        }
        .drawer .btns button, .drawer .btns a{
            flex:1;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            border-radius:12px;
            padding:12px 12px;
            text-decoration:none;
            cursor:pointer;
            font-family:'Space Mono', monospace;
            letter-spacing:1px;
            text-transform:uppercase;
            font-weight:800;
            border:none;
        }
        .drawer .btns .go{
            background:var(--neon);
            color:#000;
        }
        .drawer .btns .open{
            background:transparent;
            border:1px solid rgba(255,255,255,0.14);
            color:#fff;
            font-weight:700;
        }
        .drawer-min{
            position:fixed;
            right:16px;
            bottom:16px;
            z-index:9998;
            display:none;
        }
        .drawer-min button{
            width:56px;
            height:56px;
            border-radius:999px;
            border:1px solid rgba(0,255,157,0.35);
            background:rgba(0,0,0,0.72);
            color:#fff;
            cursor:pointer;
            box-shadow:0 18px 60px rgba(0,0,0,0.55);
            backdrop-filter: blur(10px);
            font-size:18px;
        }

        /* Reveal animations */
        .reveal{ opacity:0; transform:translateY(14px); }
        .reveal.on{ opacity:1; transform:translateY(0); transition:opacity .5s ease, transform .5s ease; }
        @media (prefers-reduced-motion: reduce){
            .portfolio-item img, .portfolio-item video { transition:none; }
            .reveal, .reveal.on{ opacity:1; transform:none; transition:none; }
        }

        @media(max-width:900px){
            .trust-strip{ grid-template-columns:1fr; }
            .trust-chips{ justify-content:flex-start; }
            .timeline{ grid-template-columns:1fr; }
            .config .row{ grid-template-columns:1fr; }
            .form-row{ grid-template-columns:1fr; }
        }

        @media(max-width:600px){
            :root{ --mainPad: 16px; }
            h1{font-size:2.2rem;}
            main{ padding-left:16px; padding-right:16px; padding-bottom:120px; }
            .top-bar { padding:10px 12px; }
            .top-link { justify-content:center; }
            .drawer{ right:12px; bottom:12px; }
            .drawer-min{ right:12px; bottom:12px; }
        }
    </style>

    <!-- JSON-LD (LocalBusiness + Services) -->
    <script type="application/ld+json">
    <?= json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'LocalBusiness',
        'name' => $siteName,
        'url' => ($HTTPS ? 'https://' : 'http://') . ($domain !== '' ? $domain : 'diamondsouttadirt.com') . '/services',
        'description' => $pageDesc,
        'areaServed' => 'Columbus, OH',
        'makesOffer' => array_map(static function(array $s){
            return [
                '@type' => 'Offer',
                'itemOffered' => [
                    '@type' => 'Service',
                    'name' => (string)($s['title'] ?? ''),
                    'description' => (string)($s['desc'] ?? ''),
                ]
            ];
        }, $services),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>
    </script>
<script src="/js/telemetry.js" defer></script>
</head>
<body>

<header id="siteHeader">
    <div class="top-bar">
        <div class="nav-row">
            <a href="/" class="top-link brand-link">DOD_ARCHIVE</a>
            <a href="/shop" class="top-link">SHOP</a>
            <a href="/services" class="top-link active">SERVICES</a>
            <a href="/about" class="top-link">ABOUT</a>
            <a href="/lookbook" class="top-link">LOOKBOOK</a>
            <a href="/arcade" class="top-link">ARCADE</a>
        </div>
        <div class="nav-row">
            <a href="/cart" class="top-link bag-link">BAG [<?= (int)$bag_qty ?>]</a>
        </div>
    </div>
</header>

<main>
    <h1>SERVICES</h1>
    <p class="subtitle">Production + design support for small-batch streetwear, custom garments, and brand visuals. Explore selected work, then transmit a request to start your quote pipeline.</p>

    <?php if ($flashSuccess !== ''): ?>
        <div class="flash ok">
            ✅ Request received<?= $flashId !== '' ? ' (ID #' . h($flashId) . ')' : '' ?>. We’ll review and reply soon.
        </div>
    <?php elseif ($flashError !== ''): ?>
        <div class="flash err">
            ❌ <?= h($flashMsg !== '' ? $flashMsg : $flashError) ?>
        </div>
    <?php endif; ?>

    <section class="trust-strip">
        <div class="trust-left">
            <p class="kicker">QUOTE PIPELINE</p>
            <p class="line">Fast turnaround options, local pickup support, and production-ready file prep. Send specs — we’ll respond with a mock + quote.</p>
        </div>
        <div class="trust-chips">
            <span class="chip"><b>TURNAROUND</b> STANDARD / RUSH</span>
            <span class="chip"><b>PICKUP</b> COLUMBUS, OH</span>
            <span class="chip"><b>SHIPPING</b> AVAILABLE</span>
            <span class="chip"><b>PROOF</b> MOCKUP + APPROVAL</span>
        </div>
    </section>

    <div class="services-grid">
        <?php foreach ($services as $s): ?>
            <div class="service-card" data-service-key="<?= h((string)($s['key'] ?? 'other')) ?>">
                <h3><?= h($s['title']) ?></h3>
                <p><?= h($s['desc']) ?></p>
                <div class="service-pill"><b>PROTOCOL</b> <?= h((string)($s['protocol'] ?? 'OTHER')) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="section-title">
        <h2>PROCESS</h2>
        <span class="bar"></span>
    </div>
    <p class="section-hint">Clear steps = faster delivery.</p>
    <div class="timeline">
        <div class="step"><div class="n">01</div><p class="t">Submit request + references</p></div>
        <div class="step"><div class="n">02</div><p class="t">Mockup + price range</p></div>
        <div class="step"><div class="n">03</div><p class="t">Approve + invoice</p></div>
        <div class="step"><div class="n">04</div><p class="t">Production + QC</p></div>
        <div class="step"><div class="n">05</div><p class="t">Pickup / ship / deliver</p></div>
    </div>

    <div class="section-title">
        <h2>EQUIPMENT & CAPABILITIES</h2>
        <span class="bar"></span>
    </div>
    <ul class="equipment">
        <?php foreach ($equipment as $k => $v): ?>
            <li><strong><?= h($k) ?>:</strong> <?= h($v) ?></li>
        <?php endforeach; ?>
    </ul>

    <div class="section-title">
        <h2>SELECTED WORK</h2>
        <span class="bar"></span>
    </div>
    <p class="section-hint">FILTER • SORT • TAP / CLICK TO VIEW FULLSCREEN • ESC TO CLOSE</p>

    <div class="portfolio-controls">
        <div class="filter-row" aria-label="Portfolio filters">
            <button class="pill-btn on" type="button" data-filter="all">ALL</button>
            <button class="pill-btn" type="button" data-filter="embroidery">EMBROIDERY</button>
            <button class="pill-btn" type="button" data-filter="htv">HTV</button>
            <button class="pill-btn" type="button" data-filter="sublimation">SUBLIMATION</button>
            <button class="pill-btn" type="button" data-filter="print">PRINT</button>
            <button class="pill-btn" type="button" data-filter="video">VIDEO</button>
        </div>
        <div class="sort-row" aria-label="Portfolio sorting">
            <select class="select" id="portfolioSort">
                <option value="newest">SORT: NEWEST</option>
                <option value="oldest">SORT: OLDEST</option>
                <option value="random">SORT: RANDOM</option>
                <option value="most">SORT: MOST VIEWED</option>
            </select>
        </div>
    </div>

    <div class="portfolio" id="portfolioGrid">
        <?php if (!$hasFiles): ?>
            <p style="color:#666;grid-column:1/-1;">// NO_MEDIA_UPLOADED_YET</p>
        <?php else: ?>
            <?php foreach ($portfolio as $item): ?>
                <?php
                    $url = (string)($item['url'] ?? '');
                    $isVideo = !empty($item['is_video']);
                    $poster = (string)($item['poster'] ?? '');
                    $name = (string)($item['name'] ?? '');
                    $tag = (string)($item['tag'] ?? 'other');
                    $mtime = (int)($item['mtime'] ?? 0);
                    $caption = $name;
                ?>
                <div class="portfolio-item"
                     role="button"
                     tabindex="0"
                     data-src="<?= h($url) ?>"
                     data-name="<?= h($name) ?>"
                     data-tag="<?= h($tag) ?>"
                     data-mtime="<?= (int)$mtime ?>"
                     data-is-video="<?= $isVideo ? '1' : '0' ?>"
                     data-caption="<?= h($caption) ?>"
                     aria-label="Open media: <?= h($name !== '' ? $name : 'item') ?>">
                    <?php if ($isVideo): ?>
                        <video
                            muted
                            playsinline
                            preload="none"
                            data-src="<?= h($url) ?>"
                            <?= $poster !== '' ? 'poster="' . h($poster) . '"' : '' ?>
                        ></video>
                        <div class="portfolio-play"><span>▶</span></div>
                    <?php else: ?>
                        <img src="<?= h($url) ?>" alt="Service Portfolio Item" loading="lazy" decoding="async" fetchpriority="low">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="section-title" id="quoteSection">
        <h2>REQUEST A QUOTE</h2>
        <span class="bar"></span>
    </div>
    <p class="section-hint">Use the configurator for a fast estimate, then transmit the full request.</p>

    <div class="config" id="configurator">
        <div class="row">
            <div>
                <label for="cfgService">SERVICE TYPE</label>
                <select id="cfgService">
                    <option value="">Select…</option>
                    <?php foreach ($services as $s): ?>
                        <option value="<?= h($s['title']) ?>" data-key="<?= h((string)($s['key'] ?? 'other')) ?>"><?= h($s['title']) ?></option>
                    <?php endforeach; ?>
                    <option value="Other" data-key="other">Other</option>
                </select>
            </div>
            <div>
                <label for="cfgQty">QUANTITY</label>
                <input id="cfgQty" type="number" min="1" value="12" inputmode="numeric">
            </div>
            <div>
                <label for="cfgGarment">GARMENT TYPE</label>
                <select id="cfgGarment">
                    <option value="T-Shirts">T-Shirts</option>
                    <option value="Hoodies">Hoodies</option>
                    <option value="Tote Bags">Tote Bags</option>
                    <option value="Hats">Hats</option>
                    <option value="Mixed / Other">Mixed / Other</option>
                </select>
            </div>
        </div>

        <div class="row" style="margin-top:12px;">
            <div>
                <label for="cfgColors">COLORS / STITCH COUNT</label>
                <select id="cfgColors">
                    <option value="1">1 color / simple</option>
                    <option value="2">2 colors / standard</option>
                    <option value="3">3 colors / detailed</option>
                    <option value="4">4+ colors / complex</option>
                </select>
            </div>
            <div>
                <label for="cfgTurnaround">TURNAROUND</label>
                <select id="cfgTurnaround">
                    <option value="standard">Standard (5–10 days)</option>
                    <option value="rush">Rush (2–4 days)</option>
                </select>
            </div>
            <div>
                <label for="cfgNotes">NOTES</label>
                <input id="cfgNotes" type="text" placeholder="e.g. front + back, sleeve hits, patch, etc.">
            </div>
        </div>

        <div class="out">
            <div class="range" id="cfgRange">ESTIMATE: <b>—</b></div>
            <div class="actions">
                <button type="button" class="mini-btn" id="cfgApply">APPLY TO FORM</button>
                <button type="button" class="mini-btn alt" id="cfgReset">RESET</button>
            </div>
        </div>
        <p class="note">Estimate is a rough range based on typical jobs. Final quote depends on art readiness, placement count, garment sourcing, and stitch/print complexity.</p>
    </div>

    <form id="quoteForm" method="post" action="/services_request" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">

        <div class="form-row">
            <div>
                <input id="qName" name="name" placeholder="YOUR NAME / BRAND" required>
            </div>
            <div>
                <input id="qEmail" name="email" type="email" placeholder="CONTACT EMAIL" required>
            </div>
            <div class="full">
                <select id="qService" name="service" required>
                    <option value="">SELECT SERVICE PROTOCOL</option>
                    <?php foreach ($services as $s): ?>
                        <option value="<?= h($s['title']) ?>"><?= h($s['title']) ?></option>
                    <?php endforeach; ?>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div>
                <input id="qQty" name="quantity" type="number" min="1" placeholder="QUANTITY (OPTIONAL)">
            </div>
            <div>
                <input id="qGarment" name="garment" type="text" placeholder="GARMENT TYPE (OPTIONAL)">
            </div>
            <div class="full">
                <textarea id="qMessage" name="message" rows="6" placeholder="DESCRIBE PROJECT SPECS..." required></textarea>
            </div>
        </div>

        <div class="file-hint">ATTACH REFERENCE (OPTIONAL) • Max 5MB • PNG/JPG/SVG/PDF</div>
        <input id="qAttach" type="file" name="attachment" accept=".png,.jpg,.jpeg,.webp,.gif,.pdf,.svg">
        <div id="fileMeta" class="file-meta"></div>

        <button class="big" type="submit">TRANSMIT REQUEST</button>
    </form>

</main>

<div class="lightbox" id="lightbox" aria-hidden="true">
    <div class="lightbox-inner">
        <div class="lb-nav">
            <button class="lb-btn" id="lbPrev" type="button" aria-label="Previous">←</button>
            <button class="lb-btn" id="lbNext" type="button" aria-label="Next">→</button>
        </div>
        <button class="lightbox-close" id="lightboxClose" type="button" aria-label="Close">×</button>
        <div class="lightbox-media">
            <img id="lightboxImg" src="" alt="" style="display:none;">
            <video id="lightboxVid" src="" controls playsinline preload="metadata" style="display:none;"></video>
        </div>
        <div class="lb-meta">
            <div class="left" id="lbMetaLeft">—</div>
            <div class="right"><a id="lbDownload" href="#" download style="display:none;">⬇ DOWNLOAD</a></div>
        </div>
    </div>
</div>

<div class="drawer" id="quoteDrawer" aria-label="Quick quote drawer">
    <div class="head">
        <div>
            <p class="ttl" style="margin:0;">REQUEST A QUOTE</p>
            <p class="sub">Quick start • open full form</p>
        </div>
        <button class="x" id="drawerClose" type="button" aria-label="Minimize">—</button>
    </div>
    <div class="body">
        <form id="drawerForm" method="post" action="/services_request" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
            <div class="mini-row">
                <input id="dName" name="name" placeholder="NAME" required>
                <input id="dEmail" name="email" type="email" placeholder="EMAIL" required>
            </div>
            <div style="margin-top:10px;">
                <select id="dService" name="service" required>
                    <option value="">SERVICE</option>
                    <?php foreach ($services as $s): ?>
                        <option value="<?= h($s['title']) ?>"><?= h($s['title']) ?></option>
                    <?php endforeach; ?>
                    <option value="Other">Other</option>
                </select>
            </div>
            <input type="hidden" id="dMessage" name="message" value="Quick request from sticky drawer. Please reply with next steps + quote.">
            <div class="btns">
                <button class="go" type="submit">SEND</button>
                <a class="open" href="#quoteSection" id="drawerOpen">OPEN FORM</a>
            </div>
        </form>
    </div>
</div>

<div class="drawer-min" id="drawerMin">
    <button type="button" id="drawerOpenBtn" aria-label="Open quote drawer">✦</button>
</div>

<script>
(() => {
    /* ✅ FIX: measure header height so H1 never sits under it */
    const header = document.getElementById('siteHeader');
    const root = document.documentElement;

    function setHeaderVar(){
        if (!header) return;
        const h = Math.max(80, Math.ceil(header.getBoundingClientRect().height || header.offsetHeight || 0));
        root.style.setProperty('--headerH', h + 'px');
    }

    let t = null;
    function onResize(){
        if (t) clearTimeout(t);
        t = setTimeout(() => { setHeaderVar(); }, 80);
    }

    window.addEventListener('load', setHeaderVar, { once:true });
    window.addEventListener('resize', onResize);
    setHeaderVar();

    /* ---------------------------
       PORTFOLIO: FILTER + SORT
    --------------------------- */
    const grid = document.getElementById('portfolioGrid');
    const sortSel = document.getElementById('portfolioSort');
    const filterBtns = Array.from(document.querySelectorAll('.pill-btn[data-filter]'));

    function getItems(){
        if (!grid) return [];
        return Array.from(grid.querySelectorAll('.portfolio-item'));
    }

    function applyFilter(tag){
        const items = getItems();
        items.forEach(el => {
            const t = (el.getAttribute('data-tag') || 'other').toLowerCase();
            const isVideo = (el.getAttribute('data-is-video') || '0') === '1';
            const show = (tag === 'all')
                || (tag === 'video' && isVideo)
                || (tag !== 'video' && t === tag);
            el.style.display = show ? '' : 'none';
        });
    }

    function getViewsKey(src){ return 'dod_services_view_' + src; }
    function getViews(src){
        try{
            const v = localStorage.getItem(getViewsKey(src));
            return v ? parseInt(v, 10) || 0 : 0;
        }catch(_){ return 0; }
    }

    function shuffle(arr){
        for (let i = arr.length - 1; i > 0; i--){
            const j = Math.floor(Math.random() * (i + 1));
            [arr[i], arr[j]] = [arr[j], arr[i]];
        }
        return arr;
    }

    function applySort(mode){
        const items = getItems();
        const nodes = items.slice();

        if (mode === 'oldest'){
            nodes.sort((a,b) => (parseInt(a.getAttribute('data-mtime')||'0',10) || 0) - (parseInt(b.getAttribute('data-mtime')||'0',10) || 0));
        } else if (mode === 'random'){
            shuffle(nodes);
        } else if (mode === 'most'){
            nodes.sort((a,b) => getViews(a.getAttribute('data-src')||'') < getViews(b.getAttribute('data-src')||'') ? 1 : -1);
        } else {
            nodes.sort((a,b) => (parseInt(b.getAttribute('data-mtime')||'0',10) || 0) - (parseInt(a.getAttribute('data-mtime')||'0',10) || 0));
        }

        nodes.forEach(n => grid.appendChild(n));
    }

    filterBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            filterBtns.forEach(b => b.classList.remove('on'));
            btn.classList.add('on');
            applyFilter(btn.getAttribute('data-filter') || 'all');
        });
    });

    if (sortSel){
        sortSel.addEventListener('change', () => {
            applySort(sortSel.value || 'newest');
        });
    }

    /* ---------------------------
       PORTFOLIO: LIGHTBOX UPGRADE
    --------------------------- */
    const lb = document.getElementById('lightbox');
    const lbClose = document.getElementById('lightboxClose');
    const lbPrev = document.getElementById('lbPrev');
    const lbNext = document.getElementById('lbNext');
    const lbImg = document.getElementById('lightboxImg');
    const lbVid = document.getElementById('lightboxVid');
    const lbMetaLeft = document.getElementById('lbMetaLeft');
    const lbDownload = document.getElementById('lbDownload');

    let currentIndex = -1;

    function visibleItems(){
        return getItems().filter(el => el.style.display !== 'none');
    }

    function formatDate(ts){
        if (!ts) return '';
        try{
            const d = new Date(ts * 1000);
            return d.toLocaleDateString(undefined, { year:'numeric', month:'short', day:'2-digit' });
        }catch(_){ return ''; }
    }

    function setMeta(el){
        const name = el.getAttribute('data-name') || '';
        const mtime = parseInt(el.getAttribute('data-mtime')||'0',10) || 0;
        const tag = (el.getAttribute('data-tag')||'other').toUpperCase();
        const date = formatDate(mtime);

        lbMetaLeft.textContent = (name ? name + ' • ' : '') + tag + (date ? ' • ' + date : '');

        const src = el.getAttribute('data-src') || '';
        if (src.startsWith('/')){
            lbDownload.style.display = 'inline-flex';
            lbDownload.href = src;
        } else {
            lbDownload.style.display = 'none';
            lbDownload.removeAttribute('href');
        }
    }

    function openLightboxByEl(el){
        if (!el) return;

        const src = el.getAttribute('data-src') || '';
        const isVideo = (el.getAttribute('data-is-video') || '0') === '1';

        try{
            const key = getViewsKey(src);
            const cur = getViews(src);
            localStorage.setItem(key, String(cur + 1));
        }catch(_){}

        if (isVideo) {
            lbImg.style.display = 'none';
            lbImg.removeAttribute('src');

            lbVid.style.display = 'block';
            lbVid.src = src;

            const p = lbVid.play();
            if (p && typeof p.catch === 'function') p.catch(() => {});
        } else {
            try { lbVid.pause(); } catch (_) {}
            lbVid.removeAttribute('src');
            lbVid.load();
            lbVid.style.display = 'none';

            lbImg.style.display = 'block';
            lbImg.src = src;
        }

        setMeta(el);

        lb.classList.add('open');
        lb.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        lb.classList.remove('open');
        lb.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = 'auto';

        try { lbVid.pause(); } catch (_) {}
        lbVid.removeAttribute('src');
        lbVid.load();
        lbImg.removeAttribute('src');

        lbMetaLeft.textContent = '—';
        lbDownload.style.display = 'none';
    }

    function openAtIndex(i){
        const items = visibleItems();
        if (!items.length) return;
        if (i < 0) i = items.length - 1;
        if (i >= items.length) i = 0;
        currentIndex = i;
        openLightboxByEl(items[currentIndex]);
    }

    function next(){ openAtIndex(currentIndex + 1); }
    function prev(){ openAtIndex(currentIndex - 1); }

    if (grid) {
        grid.addEventListener('click', (e) => {
            const item = e.target.closest('.portfolio-item');
            if (!item) return;

            const items = visibleItems();
            currentIndex = Math.max(0, items.indexOf(item));
            openLightboxByEl(item);
        });

        grid.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            const item = e.target.closest('.portfolio-item');
            if (!item) return;
            e.preventDefault();

            const items = visibleItems();
            currentIndex = Math.max(0, items.indexOf(item));
            openLightboxByEl(item);
        });
    }

    lbClose.addEventListener('click', closeLightbox);
    lb.addEventListener('click', (e) => { if (e.target === lb) closeLightbox(); });

    lbNext.addEventListener('click', next);
    lbPrev.addEventListener('click', prev);

    document.addEventListener('keydown', (e) => {
        if (!lb.classList.contains('open')) return;
        if (e.key === 'Escape') closeLightbox();
        if (e.key === 'ArrowRight') next();
        if (e.key === 'ArrowLeft') prev();
    });

    let touchX = 0;
    let touchY = 0;
    lb.addEventListener('touchstart', (e) => {
        if (!lb.classList.contains('open')) return;
        const t = e.touches && e.touches[0];
        if (!t) return;
        touchX = t.clientX;
        touchY = t.clientY;
    }, { passive:true });

    lb.addEventListener('touchend', (e) => {
        if (!lb.classList.contains('open')) return;
        const t = e.changedTouches && e.changedTouches[0];
        if (!t) return;
        const dx = t.clientX - touchX;
        const dy = t.clientY - touchY;
        if (Math.abs(dx) > 60 && Math.abs(dy) < 80){
            if (dx < 0) next(); else prev();
        }
    }, { passive:true });

    /* ---------------------------
       PERFORMANCE: LAZY LOAD GRID VIDEOS
    --------------------------- */
    const vids = Array.from(document.querySelectorAll('.portfolio-item video[data-src]'));
    if (vids.length && 'IntersectionObserver' in window){
        const vio = new IntersectionObserver((entries) => {
            entries.forEach(en => {
                if (!en.isIntersecting) return;
                const v = en.target;
                const src = v.getAttribute('data-src');
                if (src && !v.getAttribute('src')){
                    v.setAttribute('src', src);
                }
                vio.unobserve(v);
            });
        }, { threshold: 0.15, rootMargin: '200px' });
        vids.forEach(v => vio.observe(v));
    } else {
        vids.forEach(v => {
            const src = v.getAttribute('data-src');
            if (src && !v.getAttribute('src')) v.setAttribute('src', src);
        });
    }

    /* ---------------------------
       CONFIGURATOR: ESTIMATE + AUTOFILL
    --------------------------- */
    const cfg = {
        service: document.getElementById('cfgService'),
        qty: document.getElementById('cfgQty'),
        garment: document.getElementById('cfgGarment'),
        colors: document.getElementById('cfgColors'),
        turnaround: document.getElementById('cfgTurnaround'),
        notes: document.getElementById('cfgNotes'),
        range: document.getElementById('cfgRange'),
        apply: document.getElementById('cfgApply'),
        reset: document.getElementById('cfgReset')
    };

    const qForm = {
        name: document.getElementById('qName'),
        email: document.getElementById('qEmail'),
        service: document.getElementById('qService'),
        qty: document.getElementById('qQty'),
        garment: document.getElementById('qGarment'),
        message: document.getElementById('qMessage')
    };

    function clamp(n, a, b){ return Math.max(a, Math.min(b, n)); }
    function money(n){ return '$' + Math.round(n).toLocaleString(); }

    const base = {
        print: { setup: 35, per: 6.0 },
        embroidery: { setup: 45, per: 8.5 },
        sublimation: { setup: 30, per: 7.0 },
        manufacturing: { setup: 80, per: 10.0 },
        design: { setup: 60, per: 0.0 },
        other: { setup: 40, per: 7.5 }
    };

    const garmentMult = {
        'T-Shirts': 1.0,
        'Hoodies': 1.65,
        'Tote Bags': 1.15,
        'Hats': 1.35,
        'Mixed / Other': 1.25
    };

    const colorMult = { 1: 1.0, 2: 1.15, 3: 1.30, 4: 1.55 };
    const turnMult = { standard: 1.0, rush: 1.25 };

    function getServiceKey(){
        const opt = cfg.service && cfg.service.selectedOptions && cfg.service.selectedOptions[0];
        const key = opt ? (opt.getAttribute('data-key') || 'other') : 'other';
        return key;
    }

    function estimate(){
        const key = getServiceKey();
        const b = base[key] || base.other;

        const qty = clamp(parseInt(cfg.qty.value || '1', 10) || 1, 1, 9999);
        const garment = cfg.garment.value || 'T-Shirts';
        const gmult = garmentMult[garment] || 1.2;

        const c = parseInt(cfg.colors.value || '1', 10) || 1;
        const cmult = colorMult[c] || 1.0;

        const tmult = turnMult[cfg.turnaround.value || 'standard'] || 1.0;

        let qmult = 1.0;
        if (qty >= 48) qmult = 0.90;
        else if (qty >= 24) qmult = 0.95;
        else if (qty <= 6) qmult = 1.10;

        const unit = (b.per * gmult * cmult * tmult * qmult);
        const subtotal = (b.setup + (unit * qty));

        const low = subtotal * 0.88;
        const high = subtotal * 1.18;

        cfg.range.innerHTML = 'ESTIMATE: <b>' + money(low) + '–' + money(high) + '</b>';
        return { low, high, qty, garment, key, serviceTitle: cfg.service.value || 'Other', colors: c, turnaround: cfg.turnaround.value || 'standard', notes: (cfg.notes.value||'').trim() };
    }

    function buildAutofill(e){
        const t = e.turnaround === 'rush' ? 'Rush (2–4 days)' : 'Standard (5–10 days)';
        const rangeText = (cfg.range.textContent || '').replace('ESTIMATE:', '').trim();

        const lines = [
            'SERVICE: ' + (e.serviceTitle || 'Other'),
            'GARMENT: ' + e.garment,
            'QTY: ' + e.qty,
            'COMPLEXITY: ' + (e.colors >= 4 ? '4+ colors / complex' : (e.colors + ' color(s)')),
            'TURNAROUND: ' + t,
            'ESTIMATE: ' + rangeText,
        ];

        if (e.notes) lines.push('NOTES: ' + e.notes);

        lines.push('');
        lines.push('PROJECT DESCRIPTION:');
        lines.push('- Placement(s):');
        lines.push('- Artwork ready? (yes/no):');
        lines.push('- Size range (S–XL etc):');
        lines.push('- Any special requests:');

        return lines.join('\n');
    }

    function applyToForm(){
        const e = estimate();
        if (qForm.service && cfg.service.value) qForm.service.value = cfg.service.value;
        if (qForm.qty) qForm.qty.value = String(e.qty);
        if (qForm.garment) qForm.garment.value = e.garment;

        const current = (qForm.message && qForm.message.value) ? qForm.message.value.trim() : '';
        const auto = buildAutofill(e);

        if (!current || current.length < 25){
            qForm.message.value = auto;
        } else {
            if (!current.includes('ESTIMATE:')){
                qForm.message.value = auto + '\n\n' + current;
            }
        }

        const form = document.getElementById('quoteForm');
        if (form) form.scrollIntoView({ behavior:'smooth', block:'start' });
        setTimeout(() => { try{ qForm.message.focus(); }catch(_){ } }, 300);
    }

    function resetCfg(){
        cfg.service.value = '';
        cfg.qty.value = '12';
        cfg.garment.value = 'T-Shirts';
        cfg.colors.value = '1';
        cfg.turnaround.value = 'standard';
        cfg.notes.value = '';
        cfg.range.innerHTML = 'ESTIMATE: <b>—</b>';
    }

    ['change','input'].forEach(evt => {
        [cfg.service, cfg.qty, cfg.garment, cfg.colors, cfg.turnaround, cfg.notes].forEach(el => {
            if (!el) return;
            el.addEventListener(evt, () => estimate());
        });
    });
    if (cfg.apply) cfg.apply.addEventListener('click', applyToForm);
    if (cfg.reset) cfg.reset.addEventListener('click', resetCfg);

    estimate();

    /* ---------------------------
       UPLOAD UX
    --------------------------- */
    const attach = document.getElementById('qAttach');
    const fileMeta = document.getElementById('fileMeta');

    function showFileMeta(text, isErr){
        if (!fileMeta) return;
        fileMeta.style.display = 'block';
        fileMeta.textContent = text;
        fileMeta.classList.toggle('err', !!isErr);
    }

    function hideFileMeta(){
        if (!fileMeta) return;
        fileMeta.style.display = 'none';
        fileMeta.textContent = '';
        fileMeta.classList.remove('err');
    }

    if (attach){
        attach.addEventListener('change', () => {
            const f = attach.files && attach.files[0];
            if (!f){ hideFileMeta(); return; }

            const max = 5 * 1024 * 1024;
            const ext = (f.name.split('.').pop() || '').toLowerCase();
            const allowed = ['png','jpg','jpeg','webp','gif','pdf','svg'];

            const sizeOk = f.size <= max;
            const extOk = allowed.includes(ext);

            const sizeMB = (f.size / (1024*1024)).toFixed(2) + 'MB';
            const msg = `Selected: ${f.name} • ${sizeMB}`;

            if (!extOk){
                showFileMeta(msg + ' • Unsupported type', true);
                return;
            }
            if (!sizeOk){
                showFileMeta(msg + ' • Too large (max 5MB)', true);
                return;
            }

            showFileMeta(msg + ' • Ready', false);
        });
    }

    const qf = document.getElementById('quoteForm');
    if (qf){
        qf.addEventListener('submit', (e) => {
            const f = attach && attach.files && attach.files[0];
            if (!f) return;

            const max = 5 * 1024 * 1024;
            const ext = (f.name.split('.').pop() || '').toLowerCase();
            const allowed = ['png','jpg','jpeg','webp','gif','pdf','svg'];

            if (!allowed.includes(ext) || f.size > max){
                e.preventDefault();
                showFileMeta('Attachment blocked. Allowed: PNG/JPG/WEBP/GIF/PDF/SVG • Max 5MB', true);
                try { attach.focus(); } catch(_){}
            }
        });
    }

    /* ---------------------------
       Sticky Drawer
    --------------------------- */
    const drawer = document.getElementById('quoteDrawer');
    const drawerMin = document.getElementById('drawerMin');
    const drawerClose = document.getElementById('drawerClose');
    const drawerOpenBtn = document.getElementById('drawerOpenBtn');
    const drawerOpen = document.getElementById('drawerOpen');

    const dName = document.getElementById('dName');
    const dEmail = document.getElementById('dEmail');
    const dService = document.getElementById('dService');

    function minimizeDrawer(){
        if (!drawer || !drawerMin) return;
        drawer.style.display = 'none';
        drawerMin.style.display = 'block';
        try { localStorage.setItem('dod_services_drawer', 'min'); } catch(_){}
    }

    function restoreDrawer(){
        if (!drawer || !drawerMin) return;
        drawer.style.display = 'block';
        drawerMin.style.display = 'none';
        try { localStorage.setItem('dod_services_drawer', 'open'); } catch(_){}
    }

    if (drawerClose) drawerClose.addEventListener('click', minimizeDrawer);
    if (drawerOpenBtn) drawerOpenBtn.addEventListener('click', restoreDrawer);

    try{
        const st = localStorage.getItem('dod_services_drawer');
        if (st === 'min') minimizeDrawer();
    }catch(_){}

    function openFullForm(){
        if (qForm.name && dName && dName.value) qForm.name.value = dName.value;
        if (qForm.email && dEmail && dEmail.value) qForm.email.value = dEmail.value;
        if (qForm.service && dService && dService.value) qForm.service.value = dService.value;

        const sec = document.getElementById('quoteSection');
        if (sec) sec.scrollIntoView({ behavior:'smooth', block:'start' });

        setTimeout(() => {
            try { qForm.message.focus(); } catch(_){}
        }, 300);
    }

    if (drawerOpen){
        drawerOpen.addEventListener('click', (e) => {
            e.preventDefault();
            openFullForm();
        });
    }

    document.addEventListener('click', (e) => {
        if (e.target.closest('.portfolio-item')) {
            document.querySelectorAll('.portfolio-item video').forEach(v => { try { v.pause(); } catch(_){} });
        }
    });

    /* ---------------------------
       Reveal animations
    --------------------------- */
    const nodes = document.querySelectorAll(
        '.service-card, .equipment li, .portfolio-item, #quoteForm, .section-title, .subtitle, .flash, .trust-strip, .timeline .step, .config, .portfolio-controls'
    );
    nodes.forEach(n => n.classList.add('reveal'));

    const io = new IntersectionObserver((entries) => {
        entries.forEach(e => {
            if (e.isIntersecting) {
                e.target.classList.add('on');
                io.unobserve(e.target);
            }
        });
    }, { threshold: 0.12 });

    nodes.forEach(n => io.observe(n));
})();
</script>

<script src="/js/cookie-consent-global.js" defer></script>
</body>
</html>