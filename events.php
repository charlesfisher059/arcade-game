<?php
/**
 * events.php
 * DIAMONDS OUTTA DIRT — EVENTS GRID (PUBLIC) + ADMIN PIN CONTROLS
 * PATCH v4.5:
 * - Adds full customer nav: Shop, Exchange, Lookbook, Services, Bag, Account/Sign In
 * - Adds optional visitor tracking include
 * - Adds cookie consent global script + footer partial support
 * - Adds safe telemetry hooks for event media open/copy/share
 * Path: /home2/asqrtyte/public_html/events.php
 *
 * Critical Fix:
 * - PHP Fatal: preg_replace() does NOT accept Closure replacement on many hosts.
 *   Use preg_replace_callback() instead (prevents crash after uploading flyer).
 *
 * Behavior:
 * - Public sees: Events media grid + search + filter + lightbox + share/copy.
 * - Admin sees: Pin / Unpin / Pin Top controls (only when admin_logged_in=true).
 * - No uploader/admin links shown to public.
 */

declare(strict_types=1);

/* ─────────────────────────────
   0. ENV + ERROR HANDLING (PROD-SAFE)
───────────────────────────── */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

/* ─────────────────────────────
   1. SESSION + SECURITY HEADERS
───────────────────────────── */

// Best-effort HTTPS detection (HostGator/Cloudflare friendly)
$HTTPS = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
      || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
      || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

@ini_set('session.use_strict_mode', '1');
@ini_set('session.use_only_cookies', '1');
@ini_set('session.cookie_httponly', '1');
if ($HTTPS) @ini_set('session.cookie_secure', '1');

if (session_status() === PHP_SESSION_NONE) {
    // Avoid 500s on hosts that choke on cookie_samesite options
    $opts = [
        'cookie_httponly' => true,
        'cookie_secure'   => $HTTPS,
    ];
    if (defined('PHP_VERSION_ID') && PHP_VERSION_ID >= 70300) {
        $opts['cookie_samesite'] = 'Strict';
    }
    session_start($opts);
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$messages = [];

/* ─────────────────────────────
   1.1 ADMIN FLAG
───────────────────────────── */
$isAdmin = (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true);
$customerLoggedIn = !empty($_SESSION['customer_id'] ?? null);

// Optional visitor tracking. This page is file-based, so tracking must never break events.
$visitTracker = __DIR__ . '/includes/track_visit.php';
if (is_file($visitTracker)) {
    try {
        require_once $visitTracker;
    } catch (Throwable $e) {
        error_log('events.php visitor tracking failed: ' . $e->getMessage());
    }
}

/* ─────────────────────────────
   1.2 CSRF TOKEN
───────────────────────────── */
if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || strlen($_SESSION['csrf_token']) < 32) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'];

/* ─────────────────────────────
   1.3 LIGHT RATE LIMIT (SESSION)
───────────────────────────── */
function rateLimit(string $bucket, int $maxHits, int $windowSeconds): bool {
    if (!isset($_SESSION['rl']) || !is_array($_SESSION['rl'])) $_SESSION['rl'] = [];
    if (!isset($_SESSION['rl'][$bucket]) || !is_array($_SESSION['rl'][$bucket])) $_SESSION['rl'][$bucket] = [];

    $now = time();
    $_SESSION['rl'][$bucket] = array_values(array_filter($_SESSION['rl'][$bucket], function ($t) use ($now, $windowSeconds) {
        return is_int($t) && $t >= ($now - $windowSeconds);
    }));

    if (count($_SESSION['rl'][$bucket]) >= $maxHits) return false;

    $_SESSION['rl'][$bucket][] = $now;
    return true;
}

/* ─────────────────────────────
   2. CONFIG
───────────────────────────── */
$config = [
    'brand' => 'Diamonds Outta Dirt',
    'events' => [
        'dir' => __DIR__ . '/images/events/',
        'url' => '/images/events/',
        'allowed' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm'],
        'max_list_scan' => 3000,
    ],
    'thumbs' => [
        'dir' => __DIR__ . '/images/thumbs/',
        'url' => '/images/thumbs/',
        'thumb_w' => 300,
        'thumb_h' => 300,
    ],
    'pins' => [
        'file' => __DIR__ . '/data/events_pins.json',
        'max'  => 3,
    ],
];

/* ─────────────────────────────
   3. HELPERS
───────────────────────────── */

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function jsStr(string $s): string {
    return json_encode($s, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '""';
}

function startsWith(string $haystack, string $needle): bool {
    if ($needle === '') return true;
    if (function_exists('str_starts_with')) return str_starts_with($haystack, $needle);
    return strncmp($haystack, $needle, strlen($needle)) === 0;
}

function ensureDir(string $dir, int $mode = 0755): bool {
    if (is_dir($dir)) return true;
    return @mkdir($dir, $mode, true);
}

function isWithinBase(string $path, string $baseDir): bool {
    $rp = realpath($path);
    $rb = realpath($baseDir);
    if ($rp === false || $rb === false) return false;
    $rb = rtrim($rb, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return startsWith($rp, $rb);
}

function isVideoExt(string $ext): bool {
    $ext = strtolower($ext);
    return in_array($ext, ['mp4', 'webm', 'mov'], true);
}

function formatBytes(int $b): string {
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $val = (float)$b;
    while ($val >= 1024 && $i < count($u) - 1) { $val /= 1024; $i++; }
    return ($i === 0) ? ((string)$b . ' B') : (rtrim(rtrim(number_format($val, 1, '.', ''), '0'), '.') . ' ' . $u[$i]);
}

function monthLabel(int $ts): string {
    if ($ts <= 0) return 'Unknown';
    return date('M Y', $ts);
}

/**
 * Clean title from filename safely (NO preg_replace closure!)
 */
function prettyTitleFromFilename(string $filename): string {
    $base = (string)pathinfo($filename, PATHINFO_FILENAME);
    $s = $base;

    $s = preg_replace('/\[(.*?)\]|\((.*?)\)/u', ' ', $s) ?? $s;
    $s = preg_replace('/([_-])\d{10}(?:\D|$)/u', ' ', $s) ?? $s;
    $s = preg_replace('/([_-])\d{4}-\d{2}-\d{2}(?:\D|$)/u', ' ', $s) ?? $s;
    $s = preg_replace('/([_-])\d{8}(?:\D|$)/u', ' ', $s) ?? $s;
    $s = preg_replace('/([_-])(final|edit|export|draft|v\d+|ver\d+|version\d+|copy)\b/iu', ' ', $s) ?? $s;

    $s = str_replace(['_', '-', '.'], ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    $s = trim($s);

    if ($s === '') $s = 'Untitled';

    if (function_exists('mb_convert_case')) {
        $s = mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
    } else {
        $s = ucwords(strtolower($s));
    }

    // ✅ FIX: use preg_replace_callback (closure allowed here)
    $s = preg_replace_callback('/\b(Ig|Fb|Diy|Rsvp|Usa|Dj|Vip)\b/u', function ($m) {
        $word = (string)($m[0] ?? '');
        if ($word === '') return '';
        if (function_exists('mb_strtoupper')) return mb_strtoupper($word, 'UTF-8');
        return strtoupper($word);
    }, $s) ?? $s;

    return $s;
}

/* ─────────────────────────────
   4. FILE LISTING
───────────────────────────── */
function getEventFiles(array $cfg, array $thumbCfg, array $config): array {
    $dir     = (string)($cfg['dir'] ?? '');
    $urlBase = (string)($cfg['url'] ?? '');
    $allowed = (array)($cfg['allowed'] ?? []);
    $maxScan = (int)($cfg['max_list_scan'] ?? 3000);

    $out = [];
    if (!is_dir($dir)) return $out;

    $dh = @opendir($dir);
    if ($dh === false) return $out;

    $count = 0;
    while (($f = readdir($dh)) !== false) {
        if ($f === '.' || $f === '..') continue;
        if ($f[0] === '.') continue;

        $path = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $f;
        if (!is_file($path)) continue;
        if (!isWithinBase($path, $dir)) continue;

        $ext = strtolower((string)pathinfo($f, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowed, true)) continue;

        $isVideo  = isVideoExt($ext);
        $size     = (int)@filesize($path);
        $modified = (int)@filemtime($path);

        // thumbs:
        // - images: thumbs/<filename> if exists, else original
        // - videos: thumbs/<filename>.jpg poster if exists
        $thumbDir = rtrim((string)$thumbCfg['dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $thumbUrl = rtrim((string)$thumbCfg['url'], '/') . '/';
        $thumbSrc = '';

        if ($isVideo) {
            $posterFs = $thumbDir . $f . '.jpg';
            if (is_file($posterFs) && isWithinBase($posterFs, $thumbDir)) {
                $thumbSrc = $thumbUrl . rawurlencode($f) . '.jpg';
            }
        } else {
            $thumbFs = $thumbDir . $f;
            if (is_file($thumbFs) && isWithinBase($thumbFs, $thumbDir)) {
                $thumbSrc = $thumbUrl . rawurlencode($f);
            }
        }

        $url = rtrim($urlBase, '/') . '/' . rawurlencode($f);

        $out[] = [
            'name'     => $f,
            'url'      => $url,
            'ext'      => $ext,
            'is_video' => $isVideo,
            'thumb'    => $thumbSrc,
            'size'     => $size,
            'modified' => $modified,
            'month'    => monthLabel($modified),
            'title'    => prettyTitleFromFilename($f),
        ];

        $count++;
        if ($count >= $maxScan) break;
    }
    closedir($dh);

    usort($out, fn($a, $b) => ($b['modified'] ?? 0) <=> ($a['modified'] ?? 0));
    return $out;
}

/* ─────────────────────────────
   5. PIN STORAGE (MAX 3)
───────────────────────────── */
function loadPins(string $file): array {
    if (!is_file($file)) return [];
    $raw = @file_get_contents($file);
    if (!is_string($raw) || trim($raw) === '') return [];
    $json = json_decode($raw, true);
    if (!is_array($json)) return [];

    $pins = [];
    foreach ($json as $p) {
        if (is_string($p) && $p !== '') $pins[] = $p;
    }
    return array_values(array_unique($pins));
}

function savePins(string $file, array $pins): bool {
    $dir = dirname($file);
    if (!ensureDir($dir, 0755)) return false;

    $tmp = $file . '.tmp_' . bin2hex(random_bytes(4));
    $data = json_encode(array_values($pins), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($data)) return false;

    if (@file_put_contents($tmp, $data, LOCK_EX) === false) return false;
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/* ─────────────────────────────
   6. PIN/UNPIN ACTIONS (ADMIN ONLY)
───────────────────────────── */
$action     = (string)(filter_input(INPUT_POST, 'action', FILTER_UNSAFE_RAW) ?? '');
$actionFile = (string)(filter_input(INPUT_POST, 'file', FILTER_UNSAFE_RAW) ?? '');
$actionCsrf = (string)(filter_input(INPUT_POST, 'csrf_token', FILTER_UNSAFE_RAW) ?? '');

if ($action !== '' && $actionFile !== '' && $actionCsrf !== '') {
    try {
        if (!$isAdmin) {
            $messages[] = ['type' => 'error', 'text' => 'Not authorized.'];
        } elseif (!rateLimit('events_pin', 30, 60)) {
            $messages[] = ['type' => 'error', 'text' => 'Rate limit exceeded. Please slow down.'];
        } elseif (!hash_equals($CSRF, $actionCsrf)) {
            $messages[] = ['type' => 'error', 'text' => 'Security token invalid.'];
        } else {
            $file = basename($actionFile);
            $file = trim($file);

            $eventsDir = rtrim((string)$config['events']['dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            $candidatePath = $eventsDir . $file;
            $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
            $allowed = (array)$config['events']['allowed'];

            if ($file === '' || $ext === '' || !in_array($ext, $allowed, true) || !is_file($candidatePath) || !isWithinBase($candidatePath, $eventsDir)) {
                $messages[] = ['type' => 'error', 'text' => 'Invalid file target.'];
            } else {
                $pinsFile = (string)$config['pins']['file'];
                $maxPins  = (int)$config['pins']['max'];
                $pins     = loadPins($pinsFile);

                if ($action === 'pin') {
                    $pins = array_values(array_filter($pins, fn($p) => $p !== $file));
                    array_unshift($pins, $file);
                    $pins = array_slice($pins, 0, max(1, $maxPins));

                    $messages[] = savePins($pinsFile, $pins)
                        ? ['type' => 'success', 'text' => "Pinned: {$file}"]
                        : ['type' => 'error', 'text' => 'Failed to save pins (check /data permissions).'];

                } elseif ($action === 'unpin') {
                    $pins = array_values(array_filter($pins, fn($p) => $p !== $file));

                    $messages[] = savePins($pinsFile, $pins)
                        ? ['type' => 'success', 'text' => "Unpinned: {$file}"]
                        : ['type' => 'error', 'text' => 'Failed to save pins (check /data permissions).'];

                } elseif ($action === 'pin_top') {
                    $pins = array_values(array_filter($pins, fn($p) => $p !== $file));
                    array_unshift($pins, $file);
                    $pins = array_slice($pins, 0, max(1, $maxPins));

                    $messages[] = savePins($pinsFile, $pins)
                        ? ['type' => 'success', 'text' => "Pinned (Top): {$file}"]
                        : ['type' => 'error', 'text' => 'Failed to save pins (check /data permissions).'];
                }
            }
        }
    } catch (Throwable $e) {
        error_log("events.php pin error: " . $e->getMessage());
        $messages[] = ['type' => 'error', 'text' => 'System error while pinning. Check server logs.'];
    }
}

/* ─────────────────────────────
   7. BUILD DATA MODEL (PINS + GROUPS)
───────────────────────────── */
$items = getEventFiles($config['events'], $config['thumbs'], $config);

$pinsFile = (string)$config['pins']['file'];
$pinnedNames = loadPins($pinsFile);
$pinnedMap = array_fill_keys($pinnedNames, true);

$byName = [];
foreach ($items as $it) $byName[(string)$it['name']] = $it;

$pinnedItems = [];
foreach ($pinnedNames as $pn) {
    if (isset($byName[$pn])) $pinnedItems[] = $byName[$pn];
}

$remaining = [];
foreach ($items as $it) {
    $n = (string)$it['name'];
    if (!isset($pinnedMap[$n])) $remaining[] = $it;
}

$groups = [];
foreach ($remaining as $it) {
    $k = (string)($it['month'] ?? 'Unknown');
    if (!isset($groups[$k])) $groups[$k] = [];
    $groups[$k][] = $it;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Events | <?= h($config['brand']) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=5,user-scalable=yes">
<style>
:root{
    --neon: var(--neon, #00ff9d);
    --pink: #ff2bd6;
    --cyan: #00d9ff;

    --error: #ff0055;
    --dark: #000;
    --darker: #050505;
    --medium: #111;
    --light: #222;
    --text: #fff;
    --text-dim: #888;
    --border: #333;
}
*{ box-sizing:border-box; margin:0; padding:0; }

body{
    background: radial-gradient(1200px 800px at 10% 10%, rgba(0,255,157,0.06), transparent 60%),
                radial-gradient(900px 600px at 90% 20%, rgba(0,217,255,0.06), transparent 55%),
                radial-gradient(900px 600px at 70% 90%, rgba(255,43,214,0.05), transparent 55%),
                var(--dark);
    color: var(--text);
    font-family: 'Courier New', monospace;
    padding: 14px;
    min-height: 100vh;
}

.container{
    max-width: 1180px;
    margin: 0 auto;
    border: 1px solid rgba(255,255,255,0.10);
    padding: 18px;
    background: rgba(0,0,0,0.62);
    box-shadow: 0 0 30px rgba(0, 255, 157, 0.06);
    border-radius: 12px;
    position: relative;
    overflow: hidden;
}
.container::before{
    content:"";
    position:absolute; inset:0;
    background:
      linear-gradient(transparent 0, rgba(255,255,255,0.02) 1px, transparent 2px) 0 0/100% 24px,
      linear-gradient(90deg, transparent 0, rgba(255,255,255,0.02) 1px, transparent 2px) 0 0/24px 100%;
    pointer-events:none;
    opacity:0.35;
}

.header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap: 10px;
    flex-wrap: wrap;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    padding-bottom: 14px;
    margin-bottom: 14px;
}
h1{
    color: var(--neon);
    font-size: 1.18rem;
    letter-spacing: 2px;
    text-transform: uppercase;
}
.subline{
    margin-top: 6px;
    font-size: 0.82rem;
    color: var(--text-dim);
}

.actions{
    display:flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items:center;
    justify-content:flex-end;
}
@media (max-width: 768px){
    .actions{ width:100%; }
}
.btn-link{
    color: var(--text-dim);
    text-decoration:none;
    font-size: 0.82rem;
    border: 1px solid rgba(255,255,255,0.14);
    padding: 10px 12px;
    border-radius: 10px;
    min-height: 44px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap: 8px;
    background: rgba(0,0,0,0.25);
    transition: all 0.15s ease;
}
.btn-link:hover{
    color: var(--text);
    border-color: rgba(255,255,255,0.35);
    transform: translateY(-1px);
}

.notice{
    margin: 12px 0;
    padding: 12px;
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.12);
    background: rgba(255,255,255,0.03);
    font-size: 0.92rem;
}
.notice.success{ border-color: rgba(0,255,157,0.35); color: var(--neon); }
.notice.error{ border-color: rgba(255,0,85,0.45); color: #ff6b9a; }

.toolbar{
    display:flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items:center;
    margin: 14px 0 10px;
}
.search{
    flex: 1;
    min-width: 220px;
    padding: 12px 14px;
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.12);
    background: rgba(0,0,0,0.35);
    color: var(--text);
    font-family: inherit;
    font-size: 0.95rem;
    min-height: 48px;
}
.search:focus{
    outline: 1px solid rgba(0,255,157,0.45);
    border-color: rgba(0,255,157,0.45);
}
.filter{
    min-width: 170px;
    padding: 12px 14px;
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.12);
    background: rgba(0,0,0,0.35);
    color: var(--text);
    font-family: inherit;
    font-size: 0.95rem;
    min-height: 48px;
}

.group-title{
    margin: 18px 0 10px;
    padding-top: 10px;
    border-top: 1px solid rgba(255,255,255,0.08);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap: 10px;
    flex-wrap: wrap;
}
.group-title h2{
    font-size: 0.95rem;
    color: var(--text-dim);
    letter-spacing: 1px;
    text-transform: uppercase;
}
.group-count{
    font-size: 0.8rem;
    color: rgba(0,255,157,0.9);
    font-weight: 700;
}

.grid{
    display:grid;
    grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
    gap: 14px;
    margin-bottom: 12px;
}

.card{
    position: relative;
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid rgba(255,255,255,0.12);
    background: rgba(10,10,10,0.6);
    transition: transform 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
    display:flex;
    flex-direction: column;
}
.card:hover{
    transform: translateY(-2px);
    border-color: rgba(0,255,157,0.35);
    box-shadow: 0 12px 24px rgba(0,0,0,0.35);
}

.media{
    width: 100%;
    height: 150px;
    background: rgba(0,0,0,0.55);
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    position: relative;
}
.media img, .media video{
    width: 100%;
    height: 100%;
    object-fit: cover;
    display:block;
}
.play{
    position:absolute;
    inset:auto 10px 10px auto;
    background: rgba(0,0,0,0.75);
    border: 1px solid rgba(255,255,255,0.15);
    color: #fff;
    padding: 6px 10px;
    border-radius: 999px;
    font-size: 0.75rem;
    display:none;
}
.card[data-is-video="1"] .play{ display:inline-flex; align-items:center; gap:6px; }

.pinned{
    position:absolute;
    top: 10px; left: 10px;
    background: rgba(0,0,0,0.75);
    border: 1px solid rgba(255,255,255,0.18);
    color: var(--neon);
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 0.72rem;
    letter-spacing: 1px;
}

.info{
    padding: 12px 12px 10px;
    display:flex;
    flex-direction: column;
    gap: 8px;
    flex: 1;
}
.card-title{
    font-size: 0.88rem;
    line-height: 1.25;
    color: #fff;
    word-break: break-word;
}
.meta{
    display:flex;
    justify-content: space-between;
    gap: 10px;
    font-size: 0.75rem;
    color: rgba(255,255,255,0.55);
}
.meta b{ color: rgba(0,255,157,0.95); }

.row{
    display:flex;
    gap: 8px;
    flex-wrap: wrap;
    padding: 0 12px 12px;
}

.btn{
    flex: 1;
    min-width: 120px;
    padding: 10px 12px;
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.12);
    background: rgba(0,0,0,0.35);
    color: var(--text-dim);
    font-family: inherit;
    font-size: 0.78rem;
    cursor: pointer;
    min-height: 44px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap: 8px;
    text-decoration:none;
    transition: all 0.12s ease;
}
.btn:hover{
    color: var(--text);
    border-color: rgba(255,255,255,0.35);
}
.btn.copy:hover{ border-color: rgba(0,255,157,0.45); color: var(--neon); }
.btn.share:hover{ border-color: rgba(0,217,255,0.45); color: var(--cyan); }
.btn.admin:hover{ border-color: rgba(255,43,214,0.45); color: var(--pink); }

.empty{
    padding: 18px;
    border-radius: 12px;
    border: 1px dashed rgba(255,255,255,0.18);
    color: rgba(255,255,255,0.55);
    background: rgba(255,255,255,0.03);
    text-align: center;
}

.lightbox{
    display:none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.94);
    z-index: 999;
    padding: 14px;
    align-items:center;
    justify-content:center;
}
.lb-inner{
    width: min(980px, 94vw);
    max-height: 92vh;
    position: relative;
}
.lb-close{
    position:absolute;
    top: -52px;
    right: 0;
    min-width: 44px;
    min-height: 44px;
    border-radius: 999px;
    background: rgba(255,0,85,0.9);
    border: 1px solid rgba(255,255,255,0.18);
    color: #fff;
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size: 1.1rem;
}
.lb-media{
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.14);
    overflow:hidden;
    background: rgba(0,0,0,0.5);
}
.lb-media img, .lb-media video{
    width: 100%;
    max-height: 86vh;
    display:block;
}

@media (max-width: 768px){
    body{ padding: 10px; }
    .container{ padding: 14px; }
    h1{ font-size: 1.05rem; }
    .btn-link{ flex: 1; }
    .grid{ grid-template-columns: repeat(auto-fill, minmax(155px, 1fr)); }
    .row{ flex-direction: column; }
    .btn{ width: 100%; }
}
</style>
<?php @include_once __DIR__ . '/includes/bg_styles.php'; ?>
<script src="/js/telemetry.js" defer></script>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1>Events</h1>
            <div class="subline">Flyers + videos from <b style="color:var(--neon);"><?= h($config['brand']) ?></b></div>
        </div>
        <div class="actions" aria-label="Site navigation">
            <a class="btn-link" href="/">🏠 Home</a>
            <a class="btn-link" href="/shop">🛍️ Shop</a>
            <a class="btn-link" href="/exchange">💎 Exchange</a>
            <a class="btn-link" href="/lookbook">📸 Lookbook</a>
            <a class="btn-link" href="/services">🧵 Services</a>
            <a class="btn-link" href="/cart">🛒 Bag</a>
            <?php if ($customerLoggedIn): ?>
                <a class="btn-link" href="/account">👤 Account</a>
            <?php else: ?>
                <a class="btn-link" href="/account/login">🔐 Sign In</a>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
                <a class="btn-link" href="/admin/dashboard.php">⚙️ Admin</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($messages)): ?>
        <?php foreach ($messages as $m): ?>
            <?php $t = (($m['type'] ?? '') === 'error') ? 'error' : 'success'; ?>
            <div class="notice <?= h($t) ?>"><?= h((string)($m['text'] ?? '')) ?></div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="toolbar">
        <input id="search" class="search" type="text" placeholder="Search events..." autocomplete="off" inputmode="search">
        <select id="filter" class="filter">
            <option value="all">All media</option>
            <option value="images">Images / Flyers</option>
            <option value="videos">Videos</option>
        </select>
    </div>

    <?php if (empty($items)): ?>
        <div class="empty">No event media found in <span style="color:var(--neon);">/images/events/</span></div>
    <?php else: ?>

        <?php if (!empty($pinnedItems)): ?>
            <div class="group-title" data-group="Pinned">
                <h2>Pinned</h2>
                <div class="group-count"><?= h((string)count($pinnedItems)) ?> / <?= h((string)$config['pins']['max']) ?></div>
            </div>

            <div class="grid" data-grid="Pinned">
                <?php foreach ($pinnedItems as $it): ?>
                    <?php
                        $name = (string)$it['name'];
                        $url  = (string)$it['url'];
                        $title = (string)$it['title'];
                        $ext = (string)($it['ext'] ?? '');
                        $isVideo = !empty($it['is_video']);
                        $thumb = (string)($it['thumb'] ?? '');
                        $sizeStr = formatBytes((int)($it['size'] ?? 0));
                        $dateStr = !empty($it['modified']) ? date('Y-m-d', (int)$it['modified']) : '—';

                        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
                        $scheme = $HTTPS ? 'https://' : 'http://';
                        $absUrl = ($host !== '') ? ($scheme . $host . $url) : $url;
                    ?>
                    <div class="card"
                         data-name="<?= h(strtolower($name . ' ' . $title)) ?>"
                         data-type="<?= $isVideo ? 'video' : 'image' ?>"
                         data-is-video="<?= $isVideo ? '1' : '0' ?>"
                         data-url="<?= h($url) ?>"
                         data-title="<?= h($title) ?>"
                         data-absurl="<?= h($absUrl) ?>">

                        <div class="pinned">★ PINNED</div>

                        <div class="media" role="button" tabindex="0"
                             onclick="openLightbox(<?= jsStr($url) ?>, <?= jsStr($ext) ?>)"
                             onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openLightbox(<?= jsStr($url) ?>, <?= jsStr($ext) ?>);}">
                            <?php if ($isVideo): ?>
                                <video muted playsinline preload="metadata" <?= $thumb !== '' ? 'poster="'.h($thumb).'"' : '' ?>></video>
                                <span class="play">▶ Video</span>
                            <?php else: ?>
                                <img src="<?= h($thumb !== '' ? $thumb : $url) ?>" loading="lazy" decoding="async" alt="<?= h($title) ?>">
                            <?php endif; ?>
                        </div>

                        <div class="info">
                            <div class="card-title"><?= h($title) ?></div>
                            <div class="meta">
                                <span><?= h($dateStr) ?></span>
                                <span><b><?= h($sizeStr) ?></b></span>
                            </div>
                        </div>

                        <div class="row">
                            <button class="btn copy" type="button" onclick="copyLink(this)">📋 Copy</button>
                            <button class="btn share" type="button" onclick="shareItem(this)">🔗 Share</button>

                            <?php if ($isAdmin): ?>
                                <form method="post" style="flex:1; min-width:120px;">
                                    <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                                    <input type="hidden" name="file" value="<?= h($name) ?>">
                                    <input type="hidden" name="action" value="unpin">
                                    <button class="btn admin" type="submit">🧷 Unpin</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php foreach ($groups as $month => $list): ?>
            <div class="group-title" data-group="<?= h($month) ?>">
                <h2><?= h($month) ?></h2>
                <div class="group-count"><?= h((string)count($list)) ?></div>
            </div>

            <div class="grid" data-grid="<?= h($month) ?>">
                <?php foreach ($list as $it): ?>
                    <?php
                        $name = (string)$it['name'];
                        $url  = (string)$it['url'];
                        $title = (string)$it['title'];
                        $ext = (string)($it['ext'] ?? '');
                        $isVideo = !empty($it['is_video']);
                        $thumb = (string)($it['thumb'] ?? '');
                        $sizeStr = formatBytes((int)($it['size'] ?? 0));
                        $dateStr = !empty($it['modified']) ? date('Y-m-d', (int)$it['modified']) : '—';

                        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
                        $scheme = $HTTPS ? 'https://' : 'http://';
                        $absUrl = ($host !== '') ? ($scheme . $host . $url) : $url;

                        $isPinned = isset($pinnedMap[$name]);
                    ?>
                    <div class="card"
                         data-name="<?= h(strtolower($name . ' ' . $title)) ?>"
                         data-type="<?= $isVideo ? 'video' : 'image' ?>"
                         data-is-video="<?= $isVideo ? '1' : '0' ?>"
                         data-url="<?= h($url) ?>"
                         data-title="<?= h($title) ?>"
                         data-absurl="<?= h($absUrl) ?>">

                        <?php if ($isPinned): ?>
                            <div class="pinned">★ PINNED</div>
                        <?php endif; ?>

                        <div class="media" role="button" tabindex="0"
                             onclick="openLightbox(<?= jsStr($url) ?>, <?= jsStr($ext) ?>)"
                             onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openLightbox(<?= jsStr($url) ?>, <?= jsStr($ext) ?>);}">
                            <?php if ($isVideo): ?>
                                <video muted playsinline preload="metadata" <?= $thumb !== '' ? 'poster="'.h($thumb).'"' : '' ?>></video>
                                <span class="play">▶ Video</span>
                            <?php else: ?>
                                <img src="<?= h($thumb !== '' ? $thumb : $url) ?>" loading="lazy" decoding="async" alt="<?= h($title) ?>">
                            <?php endif; ?>
                        </div>

                        <div class="info">
                            <div class="card-title"><?= h($title) ?></div>
                            <div class="meta">
                                <span><?= h($dateStr) ?></span>
                                <span><b><?= h($sizeStr) ?></b></span>
                            </div>
                        </div>

                        <div class="row">
                            <button class="btn copy" type="button" onclick="copyLink(this)">📋 Copy</button>
                            <button class="btn share" type="button" onclick="shareItem(this)">🔗 Share</button>

                            <?php if ($isAdmin): ?>
                                <?php if (!$isPinned): ?>
                                    <form method="post" style="flex:1; min-width:120px;">
                                        <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                                        <input type="hidden" name="file" value="<?= h($name) ?>">
                                        <input type="hidden" name="action" value="pin">
                                        <button class="btn admin" type="submit">🧷 Pin</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" style="flex:1; min-width:120px;">
                                        <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                                        <input type="hidden" name="file" value="<?= h($name) ?>">
                                        <input type="hidden" name="action" value="pin_top">
                                        <button class="btn admin" type="submit">🧷 Pin Top</button>
                                    </form>
                                    <form method="post" style="flex:1; min-width:120px;">
                                        <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                                        <input type="hidden" name="file" value="<?= h($name) ?>">
                                        <input type="hidden" name="action" value="unpin">
                                        <button class="btn admin" type="submit">🧷 Unpin</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

    <?php endif; ?>
</div>

<div class="lightbox" id="lightbox" aria-hidden="true">
    <div class="lb-inner">
        <button class="lb-close" id="lbClose" aria-label="Close">×</button>
        <div class="lb-media">
            <img id="lbImg" alt="" style="display:none;">
            <video id="lbVid" controls playsinline preload="metadata" style="display:none;"></video>
        </div>
    </div>
</div>

<script>
(() => {
    function dodTrack(eventName, payload) {
        try {
            if (typeof window.dodTrack === 'function') {
                window.dodTrack(eventName, payload || {});
            }
        } catch (_) {}
    }

    const search = document.getElementById('search');
    const filter = document.getElementById('filter');

    function applyFilters(){
        const term = (search.value || '').trim().toLowerCase();
        const f = filter.value;

        document.querySelectorAll('.card').forEach(card => {
            const name = (card.getAttribute('data-name') || '');
            const type = (card.getAttribute('data-type') || 'all');
            const matchTerm = (term === '' || name.includes(term));
            const matchType = (f === 'all' || (f === 'images' && type === 'image') || (f === 'videos' && type === 'video'));
            card.style.display = (matchTerm && matchType) ? 'flex' : 'none';
        });

        document.querySelectorAll('[data-grid]').forEach(grid => {
            const visible = Array.from(grid.querySelectorAll('.card')).some(c => c.style.display !== 'none');
            const group = grid.getAttribute('data-grid');
            const title = document.querySelector('.group-title[data-group="' + CSS.escape(group) + '"]');
            if (title) title.style.display = visible ? 'flex' : 'none';
            grid.style.display = visible ? 'grid' : 'none';
        });
    }

    search.addEventListener('input', applyFilters);
    filter.addEventListener('change', applyFilters);

    const lb = document.getElementById('lightbox');
    const lbClose = document.getElementById('lbClose');
    const lbImg = document.getElementById('lbImg');
    const lbVid = document.getElementById('lbVid');

    function isVideoExt(ext){
        ext = (ext || '').toLowerCase();
        return ['mp4','webm','mov'].includes(ext);
    }

    window.openLightbox = (src, ext) => {
        const video = isVideoExt(ext);
        dodTrack('event_media_open', {
            page: 'events',
            media_type: video ? 'video' : 'image',
            file_ext: ext || '',
            url: src || ''
        });

        if (video) {
            lbImg.style.display = 'none';
            lbImg.removeAttribute('src');

            lbVid.style.display = 'block';
            lbVid.src = src;
            lbVid.playsInline = true;

            const p = lbVid.play();
            if (p && typeof p.catch === 'function') p.catch(() => {});
        } else {
            lbVid.pause();
            lbVid.removeAttribute('src');
            lbVid.load();
            lbVid.style.display = 'none';

            lbImg.style.display = 'block';
            lbImg.src = src;
        }

        lb.style.display = 'flex';
        lb.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    };

    function closeLightbox(){
        lb.style.display = 'none';
        lb.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = 'auto';

        lbVid.pause();
        lbVid.removeAttribute('src');
        lbVid.load();

        lbImg.removeAttribute('src');
    }

    lbClose.addEventListener('click', closeLightbox);
    lb.addEventListener('click', (e) => { if (e.target === lb) closeLightbox(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeLightbox(); });

    function getCard(btn){
        const card = btn.closest('.card');
        return card || null;
    }

    window.copyLink = (btn) => {
        const card = getCard(btn);
        if (!card) return;
        const abs = card.getAttribute('data-absurl') || card.getAttribute('data-url') || '';
        if (!abs) return;

        const done = () => {
            const orig = btn.textContent;
            btn.textContent = '✅ Copied';
            dodTrack('event_link_copy', {
                page: 'events',
                url: abs,
                title: (card.getAttribute('data-title') || 'Event')
            });
            setTimeout(() => btn.textContent = orig, 1200);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(abs).then(done).catch(() => {
                const ta = document.createElement('textarea');
                ta.value = abs;
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); done(); } catch(e){}
                document.body.removeChild(ta);
            });
        } else {
            const ta = document.createElement('textarea');
            ta.value = abs;
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); done(); } catch(e){}
            document.body.removeChild(ta);
        }
    };

    window.shareItem = (btn) => {
        const card = getCard(btn);
        if (!card) return;
        const abs = card.getAttribute('data-absurl') || card.getAttribute('data-url') || '';
        const title = card.getAttribute('data-title') || 'Event';

        if (navigator.share) {
            dodTrack('event_share', {
                page: 'events',
                url: abs,
                title: title
            });
            navigator.share({ title, text: title, url: abs }).catch(() => {});
            return;
        }
        window.copyLink(btn);
        alert('Link copied. Paste into Instagram / Facebook / anywhere.');
    };

    applyFilters();
})();
</script>
<?php
$footerFile = __DIR__ . '/partials/footer.php';
if (is_file($footerFile)) {
    include $footerFile;
}
?>
<script src="/js/cookie-consent-global.js" defer></script>
</body>
</html>