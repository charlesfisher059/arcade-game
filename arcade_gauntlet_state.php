<?php
declare(strict_types=1);

/** Diamonds Outta Dirt Arcade v4.6.27.9 — Gauntlet career state endpoint. */

require __DIR__ . '/arcade_app/bootstrap.php';
require_once ARCADE_APP_PATH . '/includes/player_authority.php';
require_once ARCADE_APP_PATH . '/includes/arcade_health.php';
require_once ARCADE_APP_PATH . '/includes/arcade_tutorial_privacy.php';
require_once ARCADE_APP_PATH . '/includes/arcade_gauntlet.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$customerId = arcade_authority_customer_id();
if (!$customerId) {
    echo json_encode([
        'success'=>true,
        'authenticated'=>false,
        'gauntlet'=>null,
        'version'=>arcade_version_string(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    http_response_code(503);
    echo json_encode(['success'=>false,'authenticated'=>true,'message'=>'Database connection unavailable.']);
    exit;
}

if (!arcade_security_guard_session($pdo,$customerId)) {
    http_response_code(401);
    echo json_encode(['success'=>false,'authenticated'=>false,'message'=>'This arcade session was signed out. Log in again.']);
    exit;
}

if (arcade_maintenance_mode_enabled($pdo)) {
    http_response_code(503);
    echo json_encode(['success'=>false,'maintenance'=>true,'message'=>arcade_maintenance_message($pdo)]);
    exit;
}

try {
    echo json_encode([
        'success'=>true,
        'authenticated'=>true,
        'gauntlet'=>arcade_gauntlet_state($pdo,$customerId),
        'version'=>arcade_version_string(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[ARCADE_GAUNTLET_STATE] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success'=>false,
        'authenticated'=>true,
        'message'=>'Unable to load Gauntlet career progress.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
