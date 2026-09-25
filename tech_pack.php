<?php
declare(strict_types=1);

/**
 * tech_pack.php — TEK PAK CREATOR
 * Path: /home2/asqrtyte/public_html/tech_pack.php
 *
 * Factory-ready garment tech pack builder for Diamonds Outta Dirt.
 * Self-contained (no partials/includes). Saves drafts to MySQL + browser.
 * Clean URLs: /tek-pak /tekpak /tech-pack
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
    || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
    || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on');

$domain = (string)($_SERVER['HTTP_HOST'] ?? '');
$domain = preg_replace('/:\d+$/', '', $domain) ?: '';

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    $cookieParams = [
        'lifetime' => 86400 * 30,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
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
        'gc_maxlifetime'  => 86400 * 30,
        'cookie_lifetime' => 86400 * 30,
    ]);
} elseif (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

require_once __DIR__ . '/db_connect.php';

if (!function_exists('h')) {
    function h($s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

function tek_ensure_table(?PDO $pdo): bool
{
    if (!($pdo instanceof PDO)) {
        return false;
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tech_packs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(32) NOT NULL,
                style_code VARCHAR(80) NOT NULL DEFAULT '',
                style_name VARCHAR(200) NOT NULL DEFAULT '',
                season VARCHAR(80) NULL,
                category VARCHAR(80) NULL,
                status ENUM('draft','review','approved','archived') NOT NULL DEFAULT 'draft',
                revision INT UNSIGNED NOT NULL DEFAULT 1,
                pack_json LONGTEXT NOT NULL,
                created_by VARCHAR(150) NULL,
                customer_id INT UNSIGNED NULL,
                ip_hash VARCHAR(64) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_public_id (public_id),
                KEY idx_style_code (style_code),
                KEY idx_status (status),
                KEY idx_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        return true;
    } catch (Throwable $e) {
        error_log('[TEK_PAK_SCHEMA] ' . $e->getMessage());
        return false;
    }
}

function tek_public_id(): string
{
    return bin2hex(random_bytes(12));
}

function tek_json_response(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function tek_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

if (empty($_SESSION['tek_csrf']) || !is_string($_SESSION['tek_csrf'])) {
    $_SESSION['tek_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['tek_csrf'];

$dbOk = isset($pdo) && $pdo instanceof PDO;
$tableOk = $dbOk ? tek_ensure_table($pdo) : false;

$uriPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$usingClean = (strpos($uriPath, '.php') === false);
$endpoint = $usingClean ? '/tek-pak' : '/tech_pack.php';

/* ─── JSON API ─── */
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
if ($action !== '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $body = tek_read_json_body();
    if (!$body) {
        $body = $_POST;
    }

    $token = (string)($body['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!hash_equals($csrf, $token)) {
        tek_json_response(['ok' => false, 'error' => 'Security check failed. Refresh and retry.'], 403);
    }

    if (!$dbOk || !$tableOk) {
        tek_json_response(['ok' => false, 'error' => 'Database unavailable. Drafts still save in your browser.'], 503);
    }

    $customerId = isset($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : null;
    $ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . 'dod_tek_pak');

    try {
        if ($action === 'list') {
            $stmt = $pdo->prepare('SELECT public_id, style_code, style_name, season, category, status, revision, updated_at FROM tech_packs ORDER BY updated_at DESC LIMIT 50');
            $stmt->execute();
            tek_json_response(['ok' => true, 'packs' => $stmt->fetchAll()]);
        }

        if ($action === 'load') {
            $pid = preg_replace('/[^a-f0-9]/', '', strtolower((string)($body['public_id'] ?? '')));
            if ($pid === '') {
                tek_json_response(['ok' => false, 'error' => 'Missing pack id.'], 400);
            }
            $stmt = $pdo->prepare('SELECT * FROM tech_packs WHERE public_id = ? LIMIT 1');
            $stmt->execute([$pid]);
            $row = $stmt->fetch();
            if (!$row) {
                tek_json_response(['ok' => false, 'error' => 'Pack not found.'], 404);
            }
            $pack = json_decode((string)$row['pack_json'], true);
            tek_json_response([
                'ok' => true,
                'meta' => [
                    'public_id' => $row['public_id'],
                    'status' => $row['status'],
                    'revision' => (int)$row['revision'],
                    'updated_at' => $row['updated_at'],
                ],
                'pack' => is_array($pack) ? $pack : new stdClass(),
            ]);
        }

        if ($action === 'save') {
            $pack = $body['pack'] ?? null;
            if (!is_array($pack)) {
                tek_json_response(['ok' => false, 'error' => 'Invalid pack payload.'], 400);
            }
            $encoded = json_encode($pack, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false || strlen($encoded) > 2_500_000) {
                tek_json_response(['ok' => false, 'error' => 'Pack is too large or invalid JSON.'], 400);
            }

            $style = $pack['style'] ?? [];
            $styleCode = mb_substr(trim((string)($style['code'] ?? '')), 0, 80);
            $styleName = mb_substr(trim((string)($style['name'] ?? '')), 0, 200);
            $season = mb_substr(trim((string)($style['season'] ?? '')), 0, 80);
            $category = mb_substr(trim((string)($style['category'] ?? '')), 0, 80);
            $status = (string)($body['status'] ?? $style['status'] ?? 'draft');
            if (!in_array($status, ['draft', 'review', 'approved', 'archived'], true)) {
                $status = 'draft';
            }
            $createdBy = mb_substr(trim((string)($style['designer'] ?? '')), 0, 150);
            $pid = preg_replace('/[^a-f0-9]/', '', strtolower((string)($body['public_id'] ?? '')));
            $bump = !empty($body['bump_revision']);

            if ($pid !== '') {
                $chk = $pdo->prepare('SELECT id, revision FROM tech_packs WHERE public_id = ? LIMIT 1');
                $chk->execute([$pid]);
                $existing = $chk->fetch();
                if ($existing) {
                    $rev = (int)$existing['revision'] + ($bump ? 1 : 0);
                    $upd = $pdo->prepare('UPDATE tech_packs SET style_code=?, style_name=?, season=?, category=?, status=?, revision=?, pack_json=?, created_by=?, customer_id=?, ip_hash=? WHERE public_id=?');
                    $upd->execute([$styleCode, $styleName, $season ?: null, $category ?: null, $status, $rev, $encoded, $createdBy ?: null, $customerId, $ipHash, $pid]);
                    tek_json_response(['ok' => true, 'public_id' => $pid, 'revision' => $rev, 'status' => $status]);
                }
            }

            $pid = tek_public_id();
            $ins = $pdo->prepare('INSERT INTO tech_packs (public_id, style_code, style_name, season, category, status, revision, pack_json, created_by, customer_id, ip_hash) VALUES (?,?,?,?,?,?,1,?,?,?,?)');
            $ins->execute([$pid, $styleCode, $styleName, $season ?: null, $category ?: null, $status, $encoded, $createdBy ?: null, $customerId, $ipHash]);
            tek_json_response(['ok' => true, 'public_id' => $pid, 'revision' => 1, 'status' => $status]);
        }

        if ($action === 'delete') {
            $pid = preg_replace('/[^a-f0-9]/', '', strtolower((string)($body['public_id'] ?? '')));
            if ($pid === '') {
                tek_json_response(['ok' => false, 'error' => 'Missing pack id.'], 400);
            }
            $del = $pdo->prepare('DELETE FROM tech_packs WHERE public_id = ?');
            $del->execute([$pid]);
            tek_json_response(['ok' => true, 'deleted' => $del->rowCount() > 0]);
        }

        tek_json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
    } catch (Throwable $e) {
        error_log('[TEK_PAK_API] ' . $e->getMessage());
        tek_json_response(['ok' => false, 'error' => 'Server error saving pack.'], 500);
    }
}

$loadId = preg_replace('/[^a-f0-9]/', '', strtolower((string)($_GET['id'] ?? '')));
$bootMeta = null;
$bootPack = null;
if ($loadId !== '' && $dbOk && $tableOk) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM tech_packs WHERE public_id = ? LIMIT 1');
        $stmt->execute([$loadId]);
        $row = $stmt->fetch();
        if ($row) {
            $bootMeta = [
                'public_id' => $row['public_id'],
                'status' => $row['status'],
                'revision' => (int)$row['revision'],
                'updated_at' => $row['updated_at'],
            ];
            $decoded = json_decode((string)$row['pack_json'], true);
            $bootPack = is_array($decoded) ? $decoded : null;
        }
    } catch (Throwable $e) {
        error_log('[TEK_PAK_LOAD] ' . $e->getMessage());
    }
}

$boot = [
    'endpoint' => $endpoint,
    'csrf' => $csrf,
    'db' => $dbOk && $tableOk,
    'meta' => $bootMeta,
    'pack' => $bootPack,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1">
<title>TEK PAK CREATOR | DIAMONDS OUTTA DIRT</title>
<meta name="description" content="Build factory-ready garment tech packs — style specs, colorways, graded measurements, BOM, and construction notes.">
<meta name="theme-color" content="#000000">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Tek Pak">
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
<link rel="manifest" href="/manifest-tekpak.json">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="192x192" href="/android-chrome-192x192.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg: #050505;
  --bg2: #0a0a0a;
  --panel: #0d0d0d;
  --line: #1e1e1e;
  --line2: #2a2a2a;
  --text: #f2f2f2;
  --muted: #8a8a8a;
  --accent: #00ff9d;
  --accent-dim: rgba(0,255,157,.12);
  --warn: #ffb020;
  --danger: #ff4d6d;
  --font-ui: "Space Mono", ui-monospace, Menlo, Consolas, monospace;
  --font-display: "Cormorant Garamond", Georgia, serif;
  --headerH: 64px;
  --radius: 4px;
  --safe-top: env(safe-area-inset-top, 0px);
  --safe-bottom: env(safe-area-inset-bottom, 0px);
  --safe-left: env(safe-area-inset-left, 0px);
  --safe-right: env(safe-area-inset-right, 0px);
  --dockH: 0px;
}
*, *::before, *::after { box-sizing: border-box; }
html { scroll-behavior: smooth; -webkit-text-size-adjust: 100%; }
body {
  margin: 0;
  min-height: 100vh;
  min-height: 100dvh;
  color: var(--text);
  font-family: var(--font-ui);
  font-size: 13px;
  line-height: 1.5;
  padding-bottom: var(--dockH);
  background:
    radial-gradient(1200px 600px at 10% -10%, rgba(0,255,157,.08), transparent 55%),
    radial-gradient(900px 500px at 100% 0%, rgba(255,255,255,.04), transparent 50%),
    linear-gradient(180deg, #080808 0%, var(--bg) 40%, #030303 100%);
}
a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }
button, input, select, textarea { font: inherit; color: inherit; }
button { cursor: pointer; -webkit-tap-highlight-color: transparent; touch-action: manipulation; }
input, select, textarea { font-size: 16px; } /* iOS: avoid auto-zoom on focus */
.topbar {
  position: sticky; top: 0; z-index: 40;
  display: flex; align-items: center; justify-content: space-between; gap: 16px;
  min-height: var(--headerH);
  padding: calc(10px + var(--safe-top)) calc(20px + var(--safe-right)) 10px calc(20px + var(--safe-left));
  border-bottom: 1px solid var(--line);
  background: rgba(5,5,5,.92);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
}
.brand {
  display: flex; flex-direction: column; gap: 2px; min-width: 0;
}
.brand-mark {
  font-family: var(--font-display);
  font-size: clamp(1.35rem, 2.4vw, 1.8rem);
  font-weight: 700;
  letter-spacing: .04em;
  line-height: 1;
  color: var(--text);
}
.brand-sub {
  font-size: 10px;
  letter-spacing: .22em;
  text-transform: uppercase;
  color: var(--accent);
}
.top-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: flex-end; }
.btn {
  appearance: none;
  border: 1px solid var(--line2);
  background: var(--panel);
  color: var(--text);
  padding: 8px 12px;
  border-radius: var(--radius);
  letter-spacing: .04em;
  transition: border-color .15s, background .15s, color .15s, transform .15s;
}
.btn:hover { border-color: var(--accent); color: var(--accent); }
.btn:active { transform: translateY(1px); }
.btn-primary {
  background: var(--accent);
  color: #04140e;
  border-color: var(--accent);
  font-weight: 700;
}
.btn-primary:hover { background: #6dffc4; color: #04140e; border-color: #6dffc4; }
.btn-ghost { background: transparent; }
.btn-danger:hover { border-color: var(--danger); color: var(--danger); }
.shell {
  display: grid;
  grid-template-columns: 220px minmax(0, 1fr) 300px;
  gap: 0;
  min-height: calc(100vh - var(--headerH));
}
.rail, .inspector {
  border-right: 1px solid var(--line);
  background: rgba(8,8,8,.7);
  padding: 18px 14px;
}
.inspector {
  border-right: 0;
  border-left: 1px solid var(--line);
}
.rail h2, .inspector h2 {
  margin: 0 0 12px;
  font-size: 10px;
  letter-spacing: .2em;
  color: var(--muted);
  font-weight: 700;
}
.nav-sec { display: flex; flex-direction: column; gap: 4px; margin-bottom: 22px; }
.nav-btn {
  text-align: left;
  border: 1px solid transparent;
  background: transparent;
  padding: 9px 10px;
  border-radius: var(--radius);
  color: var(--muted);
}
.nav-btn:hover { color: var(--text); background: rgba(255,255,255,.03); }
.nav-btn.active {
  color: var(--accent);
  border-color: rgba(0,255,157,.35);
  background: var(--accent-dim);
}
.main {
  padding: 22px 24px 48px;
  min-width: 0;
}
.hero-line {
  display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 10px;
  margin-bottom: 18px;
}
.hero-line h1 {
  margin: 0;
  font-family: var(--font-display);
  font-size: clamp(2rem, 4vw, 2.8rem);
  font-weight: 600;
  letter-spacing: .02em;
}
.meta-chip {
  display: inline-flex; gap: 10px; align-items: center; flex-wrap: wrap;
  color: var(--muted); font-size: 11px; letter-spacing: .08em;
}
.meta-chip strong { color: var(--accent); font-weight: 700; }
.panel {
  border: 1px solid var(--line);
  background: linear-gradient(180deg, rgba(255,255,255,.02), transparent 40%), var(--panel);
  border-radius: 6px;
  padding: 18px;
  margin-bottom: 16px;
  animation: rise .35s ease both;
}
@keyframes rise {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: none; }
}
.panel h3 {
  margin: 0 0 14px;
  font-size: 11px;
  letter-spacing: .18em;
  color: var(--accent);
}
.grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 12px; }
.grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 12px; }
.grid-4 { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 12px; }
.field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.field label {
  font-size: 10px;
  letter-spacing: .14em;
  color: var(--muted);
}
.field input, .field select, .field textarea {
  width: 100%;
  background: #080808;
  border: 1px solid var(--line2);
  border-radius: var(--radius);
  padding: 9px 10px;
  outline: none;
  transition: border-color .15s, box-shadow .15s;
}
.field input:focus, .field select:focus, .field textarea:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 2px rgba(0,255,157,.12);
}
.field textarea { min-height: 88px; resize: vertical; }
.table-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: var(--radius); }
table.data {
  width: 100%;
  border-collapse: collapse;
  min-width: 640px;
}
table.data th, table.data td {
  border-bottom: 1px solid var(--line);
  padding: 8px;
  text-align: left;
  vertical-align: middle;
}
table.data th {
  font-size: 10px;
  letter-spacing: .12em;
  color: var(--muted);
  background: rgba(0,0,0,.35);
  white-space: nowrap;
}
table.data td input, table.data td select {
  width: 100%;
  min-width: 72px;
  background: transparent;
  border: 1px solid transparent;
  padding: 6px 7px;
  border-radius: 3px;
}
table.data td input:focus, table.data td select:focus {
  border-color: var(--accent);
  background: #080808;
  outline: none;
}
.row-actions { display: flex; gap: 6px; }
.tiny {
  border: 1px solid var(--line2);
  background: transparent;
  padding: 4px 8px;
  border-radius: 3px;
  font-size: 11px;
  color: var(--muted);
}
.tiny:hover { color: var(--accent); border-color: var(--accent); }
.section { display: none; }
.section.active { display: block; }
.toast {
  position: fixed; right: 18px; bottom: 18px; z-index: 80;
  max-width: min(360px, calc(100vw - 32px));
  padding: 12px 14px;
  border: 1px solid rgba(0,255,157,.4);
  background: #06140f;
  color: var(--accent);
  border-radius: var(--radius);
  box-shadow: 0 12px 40px rgba(0,0,0,.45);
  opacity: 0; transform: translateY(8px); pointer-events: none;
  transition: opacity .2s, transform .2s;
}
.toast.show { opacity: 1; transform: none; pointer-events: auto; }
.toast.err { border-color: rgba(255,77,109,.5); background: #1a070c; color: #ff8aa0; }
.list {
  display: flex; flex-direction: column; gap: 8px; max-height: 280px; overflow: auto;
}
.list-item {
  border: 1px solid var(--line);
  background: #090909;
  padding: 10px;
  border-radius: var(--radius);
  display: grid; gap: 4px;
}
.list-item button {
  all: unset; cursor: pointer; color: var(--text);
}
.list-item button:hover { color: var(--accent); }
.list-item .sub { color: var(--muted); font-size: 10px; letter-spacing: .06em; }
.help {
  color: var(--muted);
  font-size: 12px;
  margin: 0 0 14px;
  max-width: 62ch;
}
.swatch-row { display: flex; flex-wrap: wrap; gap: 10px; }
.swatch {
  width: 42px; height: 42px; border-radius: 4px;
  border: 1px solid var(--line2);
  box-shadow: inset 0 0 0 1px rgba(255,255,255,.05);
}
.status-dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--accent); display: inline-block;
}
.status-dot.warn { background: var(--warn); }
.status-dot.off { background: #444; }
.ref-drop {
  border: 1px dashed var(--line2);
  border-radius: 6px;
  padding: 18px;
  text-align: center;
  color: var(--muted);
  background: rgba(0,0,0,.25);
  transition: border-color .15s, color .15s;
}
.ref-drop.drag { border-color: var(--accent); color: var(--accent); }
.ref-preview {
  margin-top: 12px;
  max-width: 100%;
  max-height: 280px;
  object-fit: contain;
  border: 1px solid var(--line);
  border-radius: 4px;
  background: #000;
}
.print-only { display: none; }
.iphone-banner {
  display: block;
  margin: 14px 20px 0;
  padding: 14px 16px;
  border: 1px solid rgba(0,255,157,.28);
  background: var(--accent-dim);
  border-radius: 8px;
  color: var(--text);
  font-size: 12px;
  line-height: 1.45;
}
.iphone-banner strong { color: var(--accent); }
.iphone-banner .steps { color: var(--muted); margin-top: 6px; display: grid; gap: 4px; }
body.is-standalone .iphone-banner { display: none !important; }
.desktop-only-gate {
  display: none;
  position: fixed;
  inset: 0;
  z-index: 100;
  background:
    radial-gradient(800px 400px at 50% 0%, rgba(0,255,157,.1), transparent 55%),
    #050505;
  color: var(--text);
  padding: calc(24px + var(--safe-top)) 22px calc(24px + var(--safe-bottom));
  align-items: center;
  justify-content: center;
  text-align: center;
}
.desktop-only-gate .gate-box { max-width: 420px; }
.desktop-only-gate h1 {
  font-family: var(--font-display);
  font-size: 2.4rem;
  margin: 0 0 10px;
  font-weight: 600;
}
.desktop-only-gate p { color: var(--muted); margin: 0 0 18px; line-height: 1.55; }
.desktop-only-gate .gate-brand {
  letter-spacing: .2em;
  font-size: 10px;
  color: var(--accent);
  margin-bottom: 18px;
}
.desktop-only-gate code {
  color: var(--accent);
  font-size: 12px;
}
.mobile-dock { display: none; }
.desk-only { display: inline-flex; }
.mobile-chip-bar { display: none; }

@media (max-width: 1100px) {
  .shell { grid-template-columns: 1fr; }
  .rail, .inspector { border: 0; border-bottom: 1px solid var(--line); }
  .nav-sec { flex-direction: row; flex-wrap: wrap; }
  .grid-2, .grid-3, .grid-4 { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 820px) {
  :root { --dockH: calc(64px + var(--safe-bottom)); --headerH: 56px; }
  body { font-size: 14px; }
  .topbar {
    padding: calc(8px + var(--safe-top)) calc(12px + var(--safe-right)) 8px calc(12px + var(--safe-left));
    gap: 10px;
  }
  .brand-mark { font-size: 1.25rem; }
  .top-actions .desk-only { display: none !important; }
  .top-actions { gap: 6px; }
  .top-actions .btn { padding: 10px 12px; min-height: 44px; }
  .iphone-banner { display: none; } /* desktop-only phase — no phone install CTA */
  body.force-mobile-preview .iphone-banner { display: block; margin: 0 12px 12px; padding: 12px 14px; }
  body.tek-mobile-block .desktop-only-gate { display: flex; }
  body.tek-mobile-block .shell,
  body.tek-mobile-block .topbar,
  body.tek-mobile-block .mobile-dock,
  body.tek-mobile-block .toast { display: none !important; }
  .mobile-chip-bar {
    display: block;
    position: sticky;
    top: calc(var(--headerH) + var(--safe-top) - 8px);
    z-index: 30;
    margin: 0 -12px 14px;
    padding: 8px 12px;
    background: rgba(5,5,5,.94);
    border-bottom: 1px solid var(--line);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
  }
  .chip-scroll {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    padding-bottom: 2px;
  }
  .chip-scroll::-webkit-scrollbar { display: none; }
  .chip {
    flex: 0 0 auto;
    border: 1px solid var(--line2);
    background: #0a0a0a;
    color: var(--muted);
    padding: 10px 14px;
    min-height: 40px;
    border-radius: 999px;
    letter-spacing: .06em;
    font-size: 11px;
  }
  .chip.active {
    color: #04140e;
    background: var(--accent);
    border-color: var(--accent);
    font-weight: 700;
  }
  .rail {
    display: none; /* replaced by chip bar + templates strip in main on phone */
  }
  .mobile-tpl {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    margin: 0 0 14px;
    padding-bottom: 2px;
  }
  .mobile-tpl .btn {
    flex: 0 0 auto;
    min-height: 44px;
    padding: 10px 14px;
  }
  .main {
    padding: 12px calc(12px + var(--safe-right)) calc(24px + var(--dockH)) calc(12px + var(--safe-left));
  }
  .hero-line h1 { font-size: 2rem; }
  .help { font-size: 12px; }
  .grid-2, .grid-3, .grid-4 { grid-template-columns: 1fr; }
  .field input, .field select, .field textarea {
    min-height: 48px;
    padding: 12px 14px;
    border-radius: 8px;
    font-size: 16px;
  }
  .field textarea { min-height: 110px; }
  .btn { min-height: 44px; border-radius: 8px; }
  .panel { padding: 14px; border-radius: 10px; }
  table.data td input, table.data td select {
    min-height: 44px;
    font-size: 16px;
    min-width: 64px;
  }
  .table-wrap {
    margin: 0 -4px;
    border-radius: 8px;
  }
  .inspector {
    padding: 14px calc(12px + var(--safe-right)) calc(20px + var(--safe-bottom)) calc(12px + var(--safe-left));
  }
  .list-item { padding: 12px; min-height: 52px; }
  .list-item button { display: block; width: 100%; padding: 4px 0; font-size: 14px; }
  .mobile-dock {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 6px;
    position: fixed;
    left: 0; right: 0; bottom: 0;
    z-index: 50;
    padding: 8px calc(10px + var(--safe-right)) calc(8px + var(--safe-bottom)) calc(10px + var(--safe-left));
    border-top: 1px solid var(--line);
    background: rgba(5,5,5,.96);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
  }
  .mobile-dock .btn {
    width: 100%;
    padding: 10px 6px;
    font-size: 11px;
    letter-spacing: .04em;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
  }
  .mobile-dock .btn small {
    font-size: 9px;
    letter-spacing: .12em;
    color: inherit;
    opacity: .75;
  }
  .mobile-dock .btn-primary small { opacity: .9; }
  .toast {
    bottom: calc(var(--dockH) + 12px);
    left: 12px;
    right: 12px;
    max-width: none;
  }
  .ref-drop { padding: 22px 14px; min-height: 120px; border-radius: 10px; }
}
@media (min-width: 821px) {
  .mobile-tpl { display: none; }
}
@media print {
  body { background: #fff; color: #000; padding-bottom: 0; }
  .topbar, .rail, .inspector, .toast, .no-print, .mobile-dock, .iphone-banner, .mobile-chip-bar { display: none !important; }
  .shell { display: block; }
  .main { padding: 0; }
  .section { display: block !important; break-inside: avoid; page-break-inside: avoid; }
  .panel { border-color: #ccc; background: #fff; box-shadow: none; animation: none; }
  .panel h3, .brand-sub, .meta-chip strong { color: #000; }
  .hero-line h1 { color: #000; }
  .field input, .field select, .field textarea, table.data td input, table.data td select {
    border: 0 !important; box-shadow: none !important; padding-left: 0; color: #000;
  }
  table.data th { color: #333; background: #f2f2f2; }
  .print-only { display: block; margin-bottom: 16px; }
  .print-only h1 { font-family: var(--font-display); margin: 0 0 4px; }
  a { color: #000; text-decoration: none; }
}
</style>
</head>
<body>
<header class="topbar no-print">
  <div class="brand">
    <div class="brand-mark">DIAMONDS OUTTA DIRT</div>
    <div class="brand-sub">TEK PAK CREATOR</div>
  </div>
  <div class="top-actions">
    <a class="btn btn-ghost desk-only" href="/">HOME</a>
    <a class="btn btn-ghost desk-only" href="/services">SERVICES</a>
    <button type="button" class="btn desk-only" id="btnNew">NEW</button>
    <button type="button" class="btn desk-only" id="btnSave"><span class="hide-sm">SAVE</span> DRAFT</button>
    <button type="button" class="btn desk-only" id="btnBump">+ REV</button>
    <button type="button" class="btn btn-primary desk-only" id="btnInstall" hidden>INSTALL APP</button>
    <button type="button" class="btn btn-primary desk-only" id="btnPrint">EXPORT / PRINT</button>
  </div>
</header>

<div class="iphone-banner no-print" id="iphoneBanner">
  <strong>Desktop app (preview).</strong> Install Tek Pak on your computer first — phone support comes later.
  <div class="steps">
    <span><b>Chrome or Edge:</b> click <b>INSTALL APP</b>, or use the install icon in the address bar.</span>
  </div>
  <div style="margin-top:10px">
    <button type="button" class="btn btn-primary" id="btnInstallBanner" hidden>INSTALL APP</button>
  </div>
</div>

<div class="desktop-only-gate no-print" id="desktopOnlyGate" role="dialog" aria-modal="true" aria-labelledby="gateTitle">
  <div class="gate-box">
    <div class="gate-brand">DIAMONDS OUTTA DIRT</div>
    <h1 id="gateTitle">Desktop only — for now</h1>
    <p>Tek Pak is a desktop app preview. Open this page on your computer to install and try it before it goes on the live site.</p>
    <p><code>/tek-pak</code></p>
    <button type="button" class="btn btn-primary" id="btnGateDismiss" style="margin-top:8px">Continue on this device anyway</button>
  </div>
</div>

<div class="shell">
  <aside class="rail no-print">
    <h2>SECTIONS</h2>
    <nav class="nav-sec" id="secNav">
      <button type="button" class="nav-btn active" data-sec="style">01 · STYLE</button>
      <button type="button" class="nav-btn" data-sec="colorways">02 · COLORWAYS</button>
      <button type="button" class="nav-btn" data-sec="measurements">03 · MEASUREMENTS</button>
      <button type="button" class="nav-btn" data-sec="bom">04 · BOM</button>
      <button type="button" class="nav-btn" data-sec="construction">05 · CONSTRUCTION</button>
      <button type="button" class="nav-btn" data-sec="artwork">06 · ARTWORK</button>
      <button type="button" class="nav-btn" data-sec="labels">07 · LABELS</button>
      <button type="button" class="nav-btn" data-sec="notes">08 · NOTES</button>
    </nav>
    <h2>TEMPLATES</h2>
    <div class="nav-sec" id="tplNav">
      <button type="button" class="btn" data-tpl="tee">TEE</button>
      <button type="button" class="btn" data-tpl="hoodie">HOODIE</button>
      <button type="button" class="btn" data-tpl="pants">PANTS</button>
      <button type="button" class="btn" data-tpl="shorts">SHORTS</button>
      <button type="button" class="btn" data-tpl="cap">CAP</button>
    </div>
  </aside>

  <main class="main">
    <div class="print-only">
      <h1>DIAMONDS OUTTA DIRT — TEK PAK</h1>
      <div id="printHeaderMeta"></div>
    </div>

    <div class="mobile-chip-bar no-print">
      <div class="chip-scroll" id="chipNav">
        <button type="button" class="chip active" data-sec="style">STYLE</button>
        <button type="button" class="chip" data-sec="colorways">COLOR</button>
        <button type="button" class="chip" data-sec="measurements">MEASURE</button>
        <button type="button" class="chip" data-sec="bom">BOM</button>
        <button type="button" class="chip" data-sec="construction">BUILD</button>
        <button type="button" class="chip" data-sec="artwork">ART</button>
        <button type="button" class="chip" data-sec="labels">LABELS</button>
        <button type="button" class="chip" data-sec="notes">NOTES</button>
      </div>
    </div>

    <div class="mobile-tpl no-print" id="mobileTpl">
      <button type="button" class="btn" data-tpl="tee">TEE</button>
      <button type="button" class="btn" data-tpl="hoodie">HOODIE</button>
      <button type="button" class="btn" data-tpl="pants">PANTS</button>
      <button type="button" class="btn" data-tpl="shorts">SHORTS</button>
      <button type="button" class="btn" data-tpl="cap">CAP</button>
    </div>

    <div class="hero-line no-print">
      <h1>Tek Pak</h1>
      <div class="meta-chip">
        <span class="status-dot" id="liveDot"></span>
        <span>REV <strong id="revLabel">1</strong></span>
        <span>STATUS <strong id="statusLabel">DRAFT</strong></span>
        <span id="idLabel">UNSAVED</span>
      </div>
    </div>
    <p class="help no-print">Build a factory-ready garment tech pack: style identity, colorways, graded POM chart, bill of materials, construction callouts, artwork placements, and labels. Autosaves locally; Save Draft pushes to the server when the DB is up.</p>

    <!-- STYLE -->
    <section class="section active" id="sec-style">
      <div class="panel">
        <h3>STYLE IDENTITY</h3>
        <div class="grid-3">
          <div class="field"><label for="styleCode">STYLE CODE</label><input id="styleCode" data-k="style.code" placeholder="DOD-TEE-001"></div>
          <div class="field"><label for="styleName">STYLE NAME</label><input id="styleName" data-k="style.name" placeholder="Pressure Tee"></div>
          <div class="field"><label for="styleSeason">SEASON</label><input id="styleSeason" data-k="style.season" placeholder="SS26"></div>
          <div class="field">
            <label for="styleCategory">CATEGORY</label>
            <select id="styleCategory" data-k="style.category">
              <option value="Tee">Tee</option>
              <option value="Hoodie">Hoodie</option>
              <option value="Crewneck">Crewneck</option>
              <option value="Pants">Pants</option>
              <option value="Shorts">Shorts</option>
              <option value="Jacket">Jacket</option>
              <option value="Cap">Cap</option>
              <option value="Accessory">Accessory</option>
              <option value="Other">Other</option>
            </select>
          </div>
          <div class="field">
            <label for="styleGender">FIT / MARKET</label>
            <select id="styleGender" data-k="style.gender">
              <option value="Unisex">Unisex</option>
              <option value="Mens">Mens</option>
              <option value="Womens">Womens</option>
              <option value="Kids">Kids</option>
            </select>
          </div>
          <div class="field"><label for="styleDesigner">DESIGNER</label><input id="styleDesigner" data-k="style.designer" placeholder="Your name"></div>
          <div class="field"><label for="styleDate">DATE</label><input id="styleDate" type="date" data-k="style.date"></div>
          <div class="field">
            <label for="styleStatus">STATUS</label>
            <select id="styleStatus" data-k="style.status">
              <option value="draft">Draft</option>
              <option value="review">Review</option>
              <option value="approved">Approved</option>
              <option value="archived">Archived</option>
            </select>
          </div>
          <div class="field"><label for="styleFactory">FACTORY / VENDOR</label><input id="styleFactory" data-k="style.factory" placeholder="Vendor name"></div>
        </div>
        <div class="field" style="margin-top:12px">
          <label for="styleDesc">DESCRIPTION</label>
          <textarea id="styleDesc" data-k="style.description" placeholder="Short style story + silhouette notes for sampling."></textarea>
        </div>
      </div>
      <div class="panel">
        <h3>REFERENCE / FLAT</h3>
        <div class="ref-drop" id="refDrop">
          Drop a flat sketch or sample photo here, or
          <label style="color:var(--accent);cursor:pointer;text-decoration:underline"> choose / take photo
            <input type="file" id="refFile" accept="image/*" hidden>
          </label>
          <div id="refMeta" style="margin-top:8px;font-size:11px"></div>
          <img id="refPreview" class="ref-preview" alt="" hidden>
        </div>
      </div>
    </section>

    <!-- COLORWAYS -->
    <section class="section" id="sec-colorways">
      <div class="panel">
        <h3>COLORWAYS</h3>
        <p class="help">Name each colorway and lock main body + accent refs (Pantone / hex / yarn dye).</p>
        <div class="table-wrap">
          <table class="data" id="colorTable">
            <thead>
              <tr><th>NAME</th><th>BODY</th><th>ACCENT</th><th>PANTONE / REF</th><th>HEX</th><th></th></tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
        <div style="margin-top:10px" class="no-print">
          <button type="button" class="btn" id="addColor">+ COLORWAY</button>
        </div>
        <div class="swatch-row" id="swatchRow" style="margin-top:14px"></div>
      </div>
    </section>

    <!-- MEASUREMENTS -->
    <section class="section" id="sec-measurements">
      <div class="panel">
        <h3>GRADED MEASUREMENTS (POM)</h3>
        <div class="grid-3" style="margin-bottom:12px">
          <div class="field"><label for="tolPlus">TOLERANCE +</label><input id="tolPlus" data-k="measurements.tolPlus" placeholder="0.5&quot;"></div>
          <div class="field"><label for="tolMinus">TOLERANCE −</label><input id="tolMinus" data-k="measurements.tolMinus" placeholder="0.5&quot;"></div>
          <div class="field"><label for="sizeRun">SIZE RUN</label><input id="sizeRun" data-k="measurements.sizeRun" placeholder="S,M,L,XL,XXL"></div>
        </div>
        <div class="table-wrap">
          <table class="data" id="pomTable">
            <thead><tr id="pomHead"></tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <div style="margin-top:10px" class="no-print row-actions">
          <button type="button" class="btn" id="addPom">+ POM ROW</button>
          <button type="button" class="btn" id="rebuildSizes">APPLY SIZE RUN</button>
        </div>
      </div>
    </section>

    <!-- BOM -->
    <section class="section" id="sec-bom">
      <div class="panel">
        <h3>BILL OF MATERIALS</h3>
        <div class="table-wrap">
          <table class="data" id="bomTable">
            <thead>
              <tr><th>ITEM</th><th>MATERIAL</th><th>SUPPLIER</th><th>COLOR</th><th>QTY</th><th>UOM</th><th>NOTES</th><th></th></tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
        <div style="margin-top:10px" class="no-print"><button type="button" class="btn" id="addBom">+ BOM LINE</button></div>
      </div>
    </section>

    <!-- CONSTRUCTION -->
    <section class="section" id="sec-construction">
      <div class="panel">
        <h3>CONSTRUCTION DETAILS</h3>
        <div class="table-wrap">
          <table class="data" id="conTable">
            <thead><tr><th>#</th><th>AREA</th><th>DETAIL / CALLOUT</th><th>STITCH / SPEC</th><th></th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <div style="margin-top:10px" class="no-print"><button type="button" class="btn" id="addCon">+ CALLOUT</button></div>
      </div>
    </section>

    <!-- ARTWORK -->
    <section class="section" id="sec-artwork">
      <div class="panel">
        <h3>ARTWORK &amp; PLACEMENT</h3>
        <div class="table-wrap">
          <table class="data" id="artTable">
            <thead><tr><th>ARTWORK</th><th>LOCATION</th><th>W × H</th><th>METHOD</th><th>NOTES</th><th></th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <div style="margin-top:10px" class="no-print"><button type="button" class="btn" id="addArt">+ PLACEMENT</button></div>
      </div>
    </section>

    <!-- LABELS -->
    <section class="section" id="sec-labels">
      <div class="panel">
        <h3>LABELS &amp; PACKAGING</h3>
        <div class="grid-2">
          <div class="field"><label for="mainLabel">MAIN LABEL</label><textarea id="mainLabel" data-k="labels.main" placeholder="Woven neck label — Diamonds Outta Dirt"></textarea></div>
          <div class="field"><label for="careLabel">CARE LABEL</label><textarea id="careLabel" data-k="labels.care" placeholder="Machine wash cold / hang dry"></textarea></div>
          <div class="field"><label for="sizeLabel">SIZE LABEL</label><textarea id="sizeLabel" data-k="labels.size" placeholder="Printed size tab at side seam"></textarea></div>
          <div class="field"><label for="packaging">PACKAGING</label><textarea id="packaging" data-k="labels.packaging" placeholder="Poly bag + branded tissue + hangtag"></textarea></div>
        </div>
      </div>
    </section>

    <!-- NOTES -->
    <section class="section" id="sec-notes">
      <div class="panel">
        <h3>FACTORY NOTES &amp; REVISION LOG</h3>
        <div class="field"><label for="factoryNotes">FACTORY NOTES</label><textarea id="factoryNotes" data-k="notes.factory" placeholder="Special instructions for sampling / bulk."></textarea></div>
        <div class="field" style="margin-top:12px"><label for="qaNotes">QA / FIT NOTES</label><textarea id="qaNotes" data-k="notes.qa" placeholder="Fit comments from sample review."></textarea></div>
        <div class="field" style="margin-top:12px"><label for="revLog">REVISION LOG</label><textarea id="revLog" data-k="notes.revisions" placeholder="Rev 1 — initial pack&#10;Rev 2 — lengthened body +1&quot;"></textarea></div>
      </div>
    </section>
  </main>

  <aside class="inspector no-print">
    <h2>LIBRARY</h2>
    <p class="help" style="margin-top:0">Saved server packs<?php echo ($dbOk && $tableOk) ? '' : ' (DB offline — browser drafts only)'; ?>.</p>
    <div class="list" id="packList"><div class="list-item"><div class="sub">Loading…</div></div></div>
    <div style="margin-top:14px" class="row-actions">
      <button type="button" class="btn" id="btnRefresh">REFRESH</button>
      <button type="button" class="btn btn-danger" id="btnDelete">DELETE</button>
    </div>
    <h2 style="margin-top:28px">SNAPSHOT</h2>
    <div class="meta-chip" style="display:grid;gap:8px">
      <div>COLORWAYS <strong id="statColors">0</strong></div>
      <div>POM ROWS <strong id="statPoms">0</strong></div>
      <div>BOM LINES <strong id="statBom">0</strong></div>
      <div>LOCAL <strong id="statLocal">—</strong></div>
    </div>
  </aside>
</div>

<div class="toast" id="toast" role="status"></div>

<nav class="mobile-dock no-print" aria-label="Tek Pak actions">
  <button type="button" class="btn" id="mBtnNew">NEW<small>PACK</small></button>
  <button type="button" class="btn" id="mBtnSave">SAVE<small>DRAFT</small></button>
  <button type="button" class="btn" id="mBtnBump">+ REV<small>BUMP</small></button>
  <button type="button" class="btn btn-primary" id="mBtnInstall" hidden>APP<small>INSTALL</small></button>
  <button type="button" class="btn btn-primary" id="mBtnPrint">PRINT<small>EXPORT</small></button>
</nav>

<script>
(() => {
  const BOOT = <?= json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  const LS_KEY = 'dod_tek_pak_draft_v1';
  const SIZES_DEFAULT = ['S','M','L','XL','XXL'];

  const TEMPLATES = {
    tee: {
      category: 'Tee',
      poms: [
        { code: 'A', name: 'Chest (1" below armhole)', values: {S:'20',M:'21',L:'22.5',XL:'24',XXL:'25.5'} },
        { code: 'B', name: 'Body Length (HPS)', values: {S:'27',M:'28',L:'29',XL:'30',XXL:'31'} },
        { code: 'C', name: 'Shoulder Width', values: {S:'17',M:'18',L:'19',XL:'20',XXL:'21'} },
        { code: 'D', name: 'Sleeve Length', values: {S:'8',M:'8.25',L:'8.5',XL:'8.75',XXL:'9'} },
        { code: 'E', name: 'Neck Opening', values: {S:'6.5',M:'6.75',L:'7',XL:'7.25',XXL:'7.5'} },
      ],
      bom: [
        { item: 'Body fabric', material: '100% cotton jersey 180gsm', supplier: '', color: 'CW1', qty: '1', uom: 'yd', notes: '' },
        { item: 'Neck rib', material: '1x1 cotton rib', supplier: '', color: 'CW1', qty: '0.1', uom: 'yd', notes: '' },
        { item: 'Main label', material: 'Woven', supplier: '', color: 'Black/White', qty: '1', uom: 'pc', notes: '' },
      ],
      construction: [
        { area: 'Shoulder', detail: 'Shoulder to shoulder seam', stitch: '4-thread overlock' },
        { area: 'Side seam', detail: 'Side seams', stitch: '4-thread overlock' },
        { area: 'Hem', detail: 'Bottom hem 1"', stitch: 'Coverstitch' },
        { area: 'Sleeve', detail: 'Sleeve hem 0.75"', stitch: 'Coverstitch' },
        { area: 'Neck', detail: 'Rib neckband', stitch: 'Coverstitch + twin needle' },
      ],
    },
    hoodie: {
      category: 'Hoodie',
      poms: [
        { code: 'A', name: 'Chest', values: {S:'22',M:'23',L:'24.5',XL:'26',XXL:'27.5'} },
        { code: 'B', name: 'Body Length (HPS)', values: {S:'26',M:'27',L:'28',XL:'29',XXL:'30'} },
        { code: 'C', name: 'Sleeve Length (CB)', values: {S:'33',M:'34',L:'35',XL:'36',XXL:'37'} },
        { code: 'D', name: 'Hood Height', values: {S:'14',M:'14.5',L:'15',XL:'15.5',XXL:'16'} },
        { code: 'E', name: 'Cuff Width', values: {S:'3.5',M:'3.75',L:'4',XL:'4.25',XXL:'4.5'} },
      ],
      bom: [
        { item: 'Fleece body', material: 'Cotton/poly fleece 320gsm', supplier: '', color: 'CW1', qty: '1.8', uom: 'yd', notes: '' },
        { item: 'Rib cuff/hem', material: 'Cotton/poly rib', supplier: '', color: 'CW1', qty: '0.35', uom: 'yd', notes: '' },
        { item: 'Drawcord', material: 'Flat cotton cord', supplier: '', color: 'CW1', qty: '1.2', uom: 'm', notes: '' },
        { item: 'Eyelets', material: 'Metal', supplier: '', color: 'Antique nickel', qty: '2', uom: 'pc', notes: '' },
      ],
      construction: [
        { area: 'Hood', detail: 'Double-layer hood, topstitch edge', stitch: 'Single needle 3mm' },
        { area: 'Pocket', detail: 'Kangaroo pocket', stitch: 'Single needle' },
        { area: 'Cuff', detail: 'Rib cuff set-in', stitch: 'Coverstitch' },
      ],
    },
    pants: {
      category: 'Pants',
      poms: [
        { code: 'A', name: 'Waist Relaxed', values: {S:'14',M:'15',L:'16',XL:'17.5',XXL:'19'} },
        { code: 'B', name: 'Inseam', values: {S:'30',M:'30.5',L:'31',XL:'31.5',XXL:'32'} },
        { code: 'C', name: 'Outseam', values: {S:'40',M:'41',L:'42',XL:'43',XXL:'44'} },
        { code: 'D', name: 'Front Rise', values: {S:'11',M:'11.5',L:'12',XL:'12.5',XXL:'13'} },
        { code: 'E', name: 'Thigh', values: {S:'12',M:'12.5',L:'13',XL:'13.75',XXL:'14.5'} },
        { code: 'F', name: 'Leg Opening', values: {S:'8',M:'8.25',L:'8.5',XL:'8.75',XXL:'9'} },
      ],
      bom: [
        { item: 'Shell', material: 'Cotton twill / fleece', supplier: '', color: 'CW1', qty: '1.6', uom: 'yd', notes: '' },
        { item: 'Waistband elastic', material: '1.5" elastic', supplier: '', color: 'Black', qty: '0.8', uom: 'yd', notes: '' },
        { item: 'Drawcord', material: 'Round cord', supplier: '', color: 'CW1', qty: '1.4', uom: 'm', notes: '' },
      ],
      construction: [
        { area: 'Inseam', detail: 'Inseam join', stitch: '4-thread overlock' },
        { area: 'Outseam', detail: 'Side seam', stitch: '4-thread overlock' },
        { area: 'Waist', detail: 'Elastic waist tunnel', stitch: 'Coverstitch' },
      ],
    },
    shorts: {
      category: 'Shorts',
      poms: [
        { code: 'A', name: 'Waist Relaxed', values: {S:'14',M:'15',L:'16',XL:'17.5',XXL:'19'} },
        { code: 'B', name: 'Outseam', values: {S:'18',M:'18.5',L:'19',XL:'19.5',XXL:'20'} },
        { code: 'C', name: 'Inseam', values: {S:'7',M:'7.25',L:'7.5',XL:'7.75',XXL:'8'} },
        { code: 'D', name: 'Leg Opening', values: {S:'11',M:'11.5',L:'12',XL:'12.5',XXL:'13'} },
      ],
      bom: [
        { item: 'Shell', material: 'French terry / nylon', supplier: '', color: 'CW1', qty: '1.0', uom: 'yd', notes: '' },
        { item: 'Waist elastic', material: '1.25" elastic', supplier: '', color: 'Black', qty: '0.75', uom: 'yd', notes: '' },
      ],
      construction: [
        { area: 'Hem', detail: 'Leg hem 0.5"', stitch: 'Coverstitch' },
        { area: 'Pocket', detail: 'Side seam pockets', stitch: 'Single needle' },
      ],
    },
    cap: {
      category: 'Cap',
      poms: [
        { code: 'A', name: 'Circumference', values: {S:'56',M:'57',L:'58',XL:'59',XXL:'60'} },
        { code: 'B', name: 'Crown Height', values: {S:'12',M:'12.5',L:'13',XL:'13.5',XXL:'14'} },
        { code: 'C', name: 'Brim Length', values: {S:'7',M:'7',L:'7',XL:'7.5',XXL:'7.5'} },
      ],
      bom: [
        { item: 'Crown panels', material: 'Cotton twill', supplier: '', color: 'CW1', qty: '0.35', uom: 'yd', notes: '6-panel' },
        { item: 'Brim board', material: 'Plastic insert', supplier: '', color: '—', qty: '1', uom: 'pc', notes: '' },
        { item: 'Sweatband', material: 'Cotton terry', supplier: '', color: 'CW1', qty: '1', uom: 'pc', notes: '' },
        { item: 'Closure', material: 'Snapback / buckle', supplier: '', color: 'Black', qty: '1', uom: 'pc', notes: '' },
      ],
      construction: [
        { area: 'Panels', detail: '6-panel crown seams', stitch: 'Double needle' },
        { area: 'Brim', detail: 'Topstitch brim edge', stitch: 'Single needle 3mm' },
        { area: 'Eyelets', detail: 'Embroidered eyelets', stitch: 'Embroidery' },
      ],
    },
  };

  const state = {
    publicId: BOOT.meta?.public_id || '',
    revision: BOOT.meta?.revision || 1,
    status: BOOT.meta?.status || 'draft',
    pack: BOOT.pack || emptyPack(),
  };

  function emptyPack() {
    const today = new Date().toISOString().slice(0, 10);
    return {
      style: {
        code: '', name: '', season: '', category: 'Tee', gender: 'Unisex',
        designer: '', date: today, status: 'draft', factory: '', description: '',
        referenceDataUrl: '',
      },
      colorways: [
        { name: 'Colorway 1', body: 'Black', accent: 'Neon Green', pantone: '', hex: '#00ff9d' },
      ],
      measurements: {
        tolPlus: '0.5"',
        tolMinus: '0.5"',
        sizeRun: SIZES_DEFAULT.join(','),
        poms: [],
      },
      bom: [],
      construction: [],
      artwork: [
        { name: 'Front graphic', location: 'Center chest', size: '10" × 10"', method: 'Screen / DTG', notes: '' },
      ],
      labels: { main: '', care: '', size: '', packaging: '' },
      notes: { factory: '', qa: '', revisions: 'Rev 1 — initial tek pak' },
    };
  }

  function deepGet(obj, path) {
    return path.split('.').reduce((a, k) => (a == null ? undefined : a[k]), obj);
  }
  function deepSet(obj, path, value) {
    const parts = path.split('.');
    let cur = obj;
    for (let i = 0; i < parts.length - 1; i++) {
      if (typeof cur[parts[i]] !== 'object' || cur[parts[i]] === null) cur[parts[i]] = {};
      cur = cur[parts[i]];
    }
    cur[parts[parts.length - 1]] = value;
  }

  const toastEl = document.getElementById('toast');
  let toastTimer = null;
  function toast(msg, isErr) {
    toastEl.textContent = msg;
    toastEl.classList.toggle('err', !!isErr);
    toastEl.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toastEl.classList.remove('show'), 2800);
  }

  function sizes() {
    const raw = (state.pack.measurements.sizeRun || '').split(',').map(s => s.trim()).filter(Boolean);
    return raw.length ? raw : [...SIZES_DEFAULT];
  }

  function bindScalarFields() {
    document.querySelectorAll('[data-k]').forEach(el => {
      const path = el.getAttribute('data-k');
      const val = deepGet(state.pack, path);
      if (val != null) el.value = val;
      el.addEventListener('input', () => {
        deepSet(state.pack, path, el.value);
        if (path === 'style.status') {
          state.status = el.value;
          syncMeta();
        }
        scheduleLocalSave();
        updateStats();
      });
    });
  }

  function syncMeta() {
    document.getElementById('revLabel').textContent = String(state.revision || 1);
    document.getElementById('statusLabel').textContent = String(state.status || 'draft').toUpperCase();
    document.getElementById('idLabel').textContent = state.publicId ? ('ID ' + state.publicId.slice(0, 10)) : 'UNSAVED';
    const dot = document.getElementById('liveDot');
    dot.className = 'status-dot' + (BOOT.db ? '' : ' warn');
    document.getElementById('printHeaderMeta').textContent =
      [state.pack.style.code, state.pack.style.name, 'Rev ' + state.revision, (state.status || '').toUpperCase()]
        .filter(Boolean).join('  ·  ');
  }

  function updateStats() {
    document.getElementById('statColors').textContent = String((state.pack.colorways || []).length);
    document.getElementById('statPoms').textContent = String((state.pack.measurements.poms || []).length);
    document.getElementById('statBom').textContent = String((state.pack.bom || []).length);
  }

  function scheduleLocalSave() {
    clearTimeout(scheduleLocalSave._t);
    scheduleLocalSave._t = setTimeout(saveLocal, 400);
  }

  function saveLocal() {
    try {
      const payload = {
        publicId: state.publicId,
        revision: state.revision,
        status: state.status,
        pack: state.pack,
        savedAt: new Date().toISOString(),
      };
      localStorage.setItem(LS_KEY, JSON.stringify(payload));
      document.getElementById('statLocal').textContent = new Date().toLocaleTimeString();
    } catch (e) {
      document.getElementById('statLocal').textContent = 'BLOCKED';
    }
  }

  function loadLocal() {
    try {
      const raw = localStorage.getItem(LS_KEY);
      if (!raw) return null;
      return JSON.parse(raw);
    } catch (_) { return null; }
  }

  /* ─── Colorways ─── */
  function renderColors() {
    const tb = document.querySelector('#colorTable tbody');
    tb.innerHTML = '';
    (state.pack.colorways || []).forEach((cw, idx) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td><input data-i="${idx}" data-f="name" value="${esc(cw.name)}"></td>
        <td><input data-i="${idx}" data-f="body" value="${esc(cw.body)}"></td>
        <td><input data-i="${idx}" data-f="accent" value="${esc(cw.accent)}"></td>
        <td><input data-i="${idx}" data-f="pantone" value="${esc(cw.pantone)}"></td>
        <td><input data-i="${idx}" data-f="hex" value="${esc(cw.hex)}" placeholder="#000000"></td>
        <td><button type="button" class="tiny" data-del="${idx}">✕</button></td>`;
      tb.appendChild(tr);
    });
    tb.querySelectorAll('input').forEach(inp => {
      inp.addEventListener('input', () => {
        const i = +inp.dataset.i, f = inp.dataset.f;
        state.pack.colorways[i][f] = inp.value;
        if (f === 'hex') renderSwatches();
        scheduleLocalSave();
      });
    });
    tb.querySelectorAll('[data-del]').forEach(btn => {
      btn.addEventListener('click', () => {
        state.pack.colorways.splice(+btn.dataset.del, 1);
        renderColors(); scheduleLocalSave(); updateStats();
      });
    });
    renderSwatches();
    updateStats();
  }
  function renderSwatches() {
    const row = document.getElementById('swatchRow');
    row.innerHTML = '';
    (state.pack.colorways || []).forEach(cw => {
      const d = document.createElement('div');
      d.className = 'swatch';
      d.title = cw.name || cw.hex || '';
      d.style.background = cw.hex || '#222';
      row.appendChild(d);
    });
  }

  /* ─── POM ─── */
  function renderPom() {
    const sz = sizes();
    const head = document.getElementById('pomHead');
    head.innerHTML = '<th>CODE</th><th>POINT OF MEASURE</th>' + sz.map(s => `<th>${esc(s)}</th>`).join('') + '<th></th>';
    const tb = document.querySelector('#pomTable tbody');
    tb.innerHTML = '';
    (state.pack.measurements.poms || []).forEach((row, idx) => {
      if (!row.values) row.values = {};
      const tr = document.createElement('tr');
      let cells = `
        <td><input data-i="${idx}" data-f="code" value="${esc(row.code || '')}"></td>
        <td><input data-i="${idx}" data-f="name" value="${esc(row.name || '')}"></td>`;
      sz.forEach(s => {
        cells += `<td><input data-i="${idx}" data-sz="${esc(s)}" value="${esc(row.values[s] || '')}"></td>`;
      });
      cells += `<td><button type="button" class="tiny" data-del="${idx}">✕</button></td>`;
      tr.innerHTML = cells;
      tb.appendChild(tr);
    });
    tb.querySelectorAll('input').forEach(inp => {
      inp.addEventListener('input', () => {
        const i = +inp.dataset.i;
        if (inp.dataset.sz) {
          state.pack.measurements.poms[i].values[inp.dataset.sz] = inp.value;
        } else {
          state.pack.measurements.poms[i][inp.dataset.f] = inp.value;
        }
        scheduleLocalSave();
      });
    });
    tb.querySelectorAll('[data-del]').forEach(btn => {
      btn.addEventListener('click', () => {
        state.pack.measurements.poms.splice(+btn.dataset.del, 1);
        renderPom(); scheduleLocalSave(); updateStats();
      });
    });
    updateStats();
  }

  /* ─── BOM / Construction / Artwork helpers ─── */
  function renderSimpleTable(tableId, key, fields, addDefaults) {
    const tb = document.querySelector(`#${tableId} tbody`);
    tb.innerHTML = '';
    (state.pack[key] || []).forEach((row, idx) => {
      const tr = document.createElement('tr');
      let html = '';
      if (key === 'construction') html += `<td>${idx + 1}</td>`;
      fields.forEach(f => {
        html += `<td><input data-i="${idx}" data-f="${f}" value="${esc(row[f] || '')}"></td>`;
      });
      html += `<td><button type="button" class="tiny" data-del="${idx}">✕</button></td>`;
      tr.innerHTML = html;
      tb.appendChild(tr);
    });
    tb.querySelectorAll('input').forEach(inp => {
      inp.addEventListener('input', () => {
        state.pack[key][+inp.dataset.i][inp.dataset.f] = inp.value;
        scheduleLocalSave();
      });
    });
    tb.querySelectorAll('[data-del]').forEach(btn => {
      btn.addEventListener('click', () => {
        state.pack[key].splice(+btn.dataset.del, 1);
        renderSimpleTable(tableId, key, fields, addDefaults);
        scheduleLocalSave(); updateStats();
      });
    });
    updateStats();
  }

  function renderBom() {
    renderSimpleTable('bomTable', 'bom', ['item','material','supplier','color','qty','uom','notes']);
  }
  function renderCon() {
    renderSimpleTable('conTable', 'construction', ['area','detail','stitch']);
  }
  function renderArt() {
    renderSimpleTable('artTable', 'artwork', ['name','location','size','method','notes']);
  }

  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function applyTemplate(name, quiet) {
    const t = TEMPLATES[name];
    if (!t) return;
    state.pack.style.category = t.category;
    document.getElementById('styleCategory').value = t.category;
    state.pack.measurements.sizeRun = SIZES_DEFAULT.join(',');
    document.getElementById('sizeRun').value = state.pack.measurements.sizeRun;
    state.pack.measurements.poms = JSON.parse(JSON.stringify(t.poms));
    state.pack.bom = JSON.parse(JSON.stringify(t.bom));
    state.pack.construction = JSON.parse(JSON.stringify(t.construction));
    renderAll();
    scheduleLocalSave();
    if (!quiet) toast('Loaded ' + name.toUpperCase() + ' template');
  }

  function renderAll() {
    // re-sync scalars without rebinding listeners every time — set values only
    document.querySelectorAll('[data-k]').forEach(el => {
      const val = deepGet(state.pack, el.getAttribute('data-k'));
      if (val != null) el.value = val;
    });
    renderColors();
    renderPom();
    renderBom();
    renderCon();
    renderArt();
    syncMeta();
    updateStats();
    const img = document.getElementById('refPreview');
    if (state.pack.style.referenceDataUrl) {
      img.src = state.pack.style.referenceDataUrl;
      img.hidden = false;
      document.getElementById('refMeta').textContent = 'Reference attached';
    } else {
      img.hidden = true;
      img.removeAttribute('src');
      document.getElementById('refMeta').textContent = '';
    }
  }

  /* ─── Nav ─── */
  function goSection(sec) {
    document.querySelectorAll('#secNav .nav-btn').forEach(b => b.classList.toggle('active', b.dataset.sec === sec));
    document.querySelectorAll('#chipNav .chip').forEach(b => b.classList.toggle('active', b.dataset.sec === sec));
    document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
    const el = document.getElementById('sec-' + sec);
    if (el) el.classList.add('active');
    const chip = document.querySelector('#chipNav .chip.active');
    if (chip && chip.scrollIntoView) chip.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' });
  }

  document.getElementById('secNav').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-sec]');
    if (!btn) return;
    goSection(btn.dataset.sec);
  });
  document.getElementById('chipNav').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-sec]');
    if (!btn) return;
    goSection(btn.dataset.sec);
  });
  function wireTemplates(root) {
    root.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-tpl]');
      if (!btn) return;
      applyTemplate(btn.dataset.tpl);
    });
  }
  wireTemplates(document.getElementById('tplNav'));
  wireTemplates(document.getElementById('mobileTpl'));

  document.getElementById('addColor').onclick = () => {
    state.pack.colorways.push({ name: 'Colorway ' + (state.pack.colorways.length + 1), body: '', accent: '', pantone: '', hex: '#222222' });
    renderColors(); scheduleLocalSave();
  };
  document.getElementById('addPom').onclick = () => {
    const vals = {};
    sizes().forEach(s => vals[s] = '');
    const n = (state.pack.measurements.poms || []).length;
    state.pack.measurements.poms.push({ code: String.fromCharCode(65 + (n % 26)), name: '', values: vals });
    renderPom(); scheduleLocalSave();
  };
  document.getElementById('rebuildSizes').onclick = () => {
    const sz = sizes();
    (state.pack.measurements.poms || []).forEach(row => {
      const next = {};
      sz.forEach(s => { next[s] = (row.values && row.values[s]) || ''; });
      row.values = next;
    });
    renderPom(); scheduleLocalSave();
    toast('Size run applied');
  };
  document.getElementById('addBom').onclick = () => {
    state.pack.bom.push({ item: '', material: '', supplier: '', color: '', qty: '', uom: 'pc', notes: '' });
    renderBom(); scheduleLocalSave();
  };
  document.getElementById('addCon').onclick = () => {
    state.pack.construction.push({ area: '', detail: '', stitch: '' });
    renderCon(); scheduleLocalSave();
  };
  document.getElementById('addArt').onclick = () => {
    state.pack.artwork.push({ name: '', location: '', size: '', method: '', notes: '' });
    renderArt(); scheduleLocalSave();
  };

  /* ─── Reference image ─── */
  const drop = document.getElementById('refDrop');
  const fileInput = document.getElementById('refFile');
  function ingestFile(file) {
    if (!file || !file.type.startsWith('image/')) return toast('Image files only', true);
    if (file.size > 1_800_000) return toast('Keep reference under ~1.8MB', true);
    const reader = new FileReader();
    reader.onload = () => {
      state.pack.style.referenceDataUrl = reader.result;
      renderAll();
      scheduleLocalSave();
      toast('Reference attached');
    };
    reader.readAsDataURL(file);
  }
  fileInput.addEventListener('change', () => ingestFile(fileInput.files?.[0]));
  ['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('drag'); }));
  ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('drag'); }));
  drop.addEventListener('drop', e => ingestFile(e.dataTransfer.files?.[0]));

  /* ─── API ─── */
  async function api(action, payload = {}) {
    const res = await fetch(BOOT.endpoint + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': BOOT.csrf,
      },
      body: JSON.stringify(Object.assign({ csrf: BOOT.csrf }, payload)),
      credentials: 'same-origin',
    });
    const data = await res.json().catch(() => ({ ok: false, error: 'Bad response' }));
    if (!res.ok || !data.ok) throw new Error(data.error || ('Request failed (' + res.status + ')'));
    return data;
  }

  async function saveServer(bump) {
    saveLocal();
    if (!BOOT.db) {
      toast('Saved in browser (DB offline)');
      return;
    }
    try {
      const data = await api('save', {
        public_id: state.publicId,
        status: state.pack.style.status || state.status || 'draft',
        bump_revision: !!bump,
        pack: state.pack,
      });
      state.publicId = data.public_id;
      state.revision = data.revision;
      state.status = data.status;
      syncMeta();
      saveLocal();
      history.replaceState(null, '', BOOT.endpoint + '?id=' + encodeURIComponent(state.publicId));
      toast(bump ? ('Revision ' + state.revision + ' saved') : 'Draft saved');
      refreshList();
    } catch (err) {
      toast(err.message || 'Save failed', true);
    }
  }

  async function refreshList() {
    const list = document.getElementById('packList');
    if (!BOOT.db) {
      list.innerHTML = '<div class="list-item"><div class="sub">Database offline</div></div>';
      return;
    }
    try {
      const data = await api('list');
      if (!data.packs.length) {
        list.innerHTML = '<div class="list-item"><div class="sub">No saved packs yet</div></div>';
        return;
      }
      list.innerHTML = '';
      data.packs.forEach(p => {
        const el = document.createElement('div');
        el.className = 'list-item';
        el.innerHTML = `<button type="button" data-id="${esc(p.public_id)}">${esc(p.style_code || 'UNTITLED')} — ${esc(p.style_name || 'Tek Pak')}</button>
          <div class="sub">Rev ${p.revision} · ${esc(p.status)} · ${esc(p.updated_at || '')}</div>`;
        el.querySelector('button').onclick = () => loadPack(p.public_id);
        list.appendChild(el);
      });
    } catch (err) {
      list.innerHTML = `<div class="list-item"><div class="sub">${esc(err.message)}</div></div>`;
    }
  }

  async function loadPack(id) {
    try {
      const data = await api('load', { public_id: id });
      state.publicId = data.meta.public_id;
      state.revision = data.meta.revision;
      state.status = data.meta.status;
      state.pack = data.pack;
      renderAll();
      saveLocal();
      history.replaceState(null, '', BOOT.endpoint + '?id=' + encodeURIComponent(id));
      toast('Loaded ' + (state.pack.style.code || id.slice(0, 8)));
    } catch (err) {
      toast(err.message || 'Load failed', true);
    }
  }

  document.getElementById('btnSave').onclick = () => saveServer(false);
  document.getElementById('btnBump').onclick = () => saveServer(true);
  document.getElementById('btnPrint').onclick = () => {
    document.querySelectorAll('.section').forEach(s => s.classList.add('active'));
    syncMeta();
    window.print();
  };
  document.getElementById('mBtnSave').onclick = () => saveServer(false);
  document.getElementById('mBtnBump').onclick = () => saveServer(true);
  document.getElementById('mBtnPrint').onclick = () => document.getElementById('btnPrint').click();
  document.getElementById('mBtnNew').onclick = () => document.getElementById('btnNew').click();
  document.getElementById('btnRefresh').onclick = () => refreshList();
  document.getElementById('btnDelete').onclick = async () => {
    if (!state.publicId) return toast('Nothing to delete', true);
    if (!confirm('Delete this tek pak from the server?')) return;
    try {
      await api('delete', { public_id: state.publicId });
      toast('Deleted');
      state.publicId = '';
      syncMeta();
      history.replaceState(null, '', BOOT.endpoint);
      refreshList();
    } catch (err) {
      toast(err.message, true);
    }
  };
  document.getElementById('btnNew').onclick = () => {
    if (!confirm('Start a new tek pak? Unsaved server changes may be lost (browser draft remains until overwrite).')) return;
    state.publicId = '';
    state.revision = 1;
    state.status = 'draft';
    state.pack = emptyPack();
    renderAll();
    saveLocal();
    history.replaceState(null, '', BOOT.endpoint);
    toast('New tek pak');
  };

  // Desktop-only first: block phone/narrow viewports unless user opts in
  function isPhoneLike() {
    const ua = navigator.userAgent || '';
    const phoneUA = /Android.*Mobile|iPhone|iPod|webOS|BlackBerry|IEMobile|Opera Mini/i.test(ua);
    const narrow = window.matchMedia('(max-width: 820px)').matches;
    const coarse = window.matchMedia('(pointer: coarse)').matches && narrow;
    return phoneUA || coarse;
  }
  function applyDesktopGate() {
    const bypass = sessionStorage.getItem('tek_pak_mobile_ok') === '1';
    if (isPhoneLike() && !bypass) {
      document.body.classList.add('tek-mobile-block');
    } else {
      document.body.classList.remove('tek-mobile-block');
      if (bypass) document.body.classList.add('force-mobile-preview');
    }
  }
  applyDesktopGate();
  const gateBtn = document.getElementById('btnGateDismiss');
  if (gateBtn) {
    gateBtn.onclick = () => {
      sessionStorage.setItem('tek_pak_mobile_ok', '1');
      document.body.classList.remove('tek-mobile-block');
      document.body.classList.add('force-mobile-preview');
      toast('Desktop mode recommended');
    };
  }
  window.addEventListener('resize', () => {
    // Don't re-block if they already opted in this session
    if (sessionStorage.getItem('tek_pak_mobile_ok') === '1') return;
    applyDesktopGate();
  });

  // Hide install tip when already running as home-screen / installed app
  let deferredInstall = null;
  const installBtns = [
    document.getElementById('btnInstall'),
    document.getElementById('btnInstallBanner'),
    document.getElementById('mBtnInstall'),
  ].filter(Boolean);

  function showInstallButtons(show) {
    installBtns.forEach((b) => {
      if (!b) return;
      b.hidden = !show;
    });
    const dock = document.querySelector('.mobile-dock');
    if (dock) dock.style.gridTemplateColumns = show ? 'repeat(5, 1fr)' : 'repeat(4, 1fr)';
  }

  async function triggerInstall() {
    if (deferredInstall) {
      deferredInstall.prompt();
      try {
        const choice = await deferredInstall.userChoice;
        if (choice && choice.outcome === 'accepted') {
          toast('Tek Pak installed on desktop');
          showInstallButtons(false);
        }
      } catch (_) {}
      deferredInstall = null;
      return;
    }
    toast('Chrome/Edge → Install app (address bar icon)');
  }

  installBtns.forEach((b) => { b.onclick = () => triggerInstall(); });

  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredInstall = e;
    showInstallButtons(true);
  });
  window.addEventListener('appinstalled', () => {
    deferredInstall = null;
    showInstallButtons(false);
    toast('Tek Pak desktop app ready');
  });

  // Always offer banner install CTA on desktop
  if (!isPhoneLike()) {
    const ban = document.getElementById('btnInstallBanner');
    if (ban) ban.hidden = false;
  }

  try {
    const standalone = window.navigator.standalone === true
      || window.matchMedia('(display-mode: standalone)').matches
      || window.matchMedia('(display-mode: minimal-ui)').matches;
    if (standalone) {
      document.body.classList.add('is-standalone');
      showInstallButtons(false);
    }
  } catch (_) {}

  // Register Tek Pak service worker (offline shell + installability)
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw-tekpak.js').catch(() => {});
  }

  // PWA shortcut: /tek-pak?new=1
  try {
    const params = new URLSearchParams(window.location.search);
    if (params.get('new') === '1') {
      state.publicId = '';
      state.revision = 1;
      state.status = 'draft';
      state.pack = emptyPack();
      applyTemplate('tee', true);
      history.replaceState(null, '', BOOT.endpoint);
    }
  } catch (_) {}

  // Boot
  if (!BOOT.pack) {
    const local = loadLocal();
    if (local && local.pack && !(new URLSearchParams(window.location.search).get('new') === '1')) {
      state.publicId = local.publicId || '';
      state.revision = local.revision || 1;
      state.status = local.status || 'draft';
      state.pack = local.pack;
    } else if (!(new URLSearchParams(window.location.search).get('new') === '1')) {
      applyTemplate('tee', true);
    }
  }
  bindScalarFields();
  renderAll();
  saveLocal();
  refreshList();
})();
</script>
</body>
</html>
