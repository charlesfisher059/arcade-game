<?php
declare(strict_types=1);

require __DIR__ . '/arcade_app/bootstrap.php';
require_once ARCADE_APP_PATH . '/includes/cloud_identity.php';
require_once ARCADE_APP_PATH . '/includes/player_authority.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'POST required.']);
    exit;
}

$identity = arcade_cloud_identity(isset($pdo) && $pdo instanceof PDO ? $pdo : null);
if (!$identity['authenticated']) {
    http_response_code(401);
    echo json_encode(['success'=>false,'authenticated'=>false,'message'=>'Log in to use cloud save.']);
    exit;
}

$csrf = (string)($_SERVER['HTTP_X_ARCADE_CSRF'] ?? '');
if ($csrf === '' || !hash_equals(arcade_cloud_csrf_token(), $csrf)) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Invalid security token. Refresh the arcade and try again.']);
    exit;
}
if (!isset($pdo) || !$pdo instanceof PDO) {
    http_response_code(503);
    echo json_encode(['success'=>false,'message'=>'Database connection unavailable.']);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 524288) {
    http_response_code(413);
    echo json_encode(['success'=>false,'message'=>'Save payload is too large.']);
    exit;
}

try {
    arcade_cloud_ensure($pdo);
    $input = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $saveData = $input['data'] ?? null;
    if (!is_array($saveData)) throw new InvalidArgumentException('Save data must be an object.');

    $warnings = [];
    $authorityCustomerId = arcade_authority_customer_id();
    if ($authorityCustomerId) {
        try {
            arcade_authority_ensure_player($pdo, $authorityCustomerId, (string)$identity['account_key'], $saveData);
        } catch (Throwable $authorityError) {
            $warnings[] = 'Cloud snapshot saved; the server profile will retry synchronization.';
            error_log('[ARCADE_CLOUD_AUTHORITY_SAVE] ' . $authorityError->getMessage());
        }
        // Economy, character ownership, inventory, and premium data remain
        // server-authoritative even when another profile panel has a problem.
        $saveData = arcade_authority_strip_snapshot($saveData);
    }

    foreach (array_keys($saveData) as $key) {
        if (!is_string($key) || !str_starts_with($key, 'dod_')) unset($saveData[$key]);
    }

    $encoded = json_encode($saveData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (strlen($encoded) > 400000) throw new LengthException('Save data exceeds the maximum size.');

    $deviceLabel = substr(trim((string)($input['device_label'] ?? '')), 0, 120);
    $stmt = $pdo->prepare(
        'INSERT INTO arcade_cloud_saves (account_key,save_version,save_data,device_label)
         VALUES (:account_key,1,:save_data,:device_label)
         ON DUPLICATE KEY UPDATE save_version=VALUES(save_version),save_data=VALUES(save_data),device_label=VALUES(device_label),updated_at=CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        'account_key'=>$identity['account_key'],
        'save_data'=>$encoded,
        'device_label'=>$deviceLabel !== '' ? $deviceLabel : null,
    ]);

    // Once canonical customer_id storage succeeds, remove duplicate legacy-key
    // rows for the same signed-in account.
    foreach (($identity['account_keys'] ?? []) as $legacyKey) {
        if ((string)$legacyKey === (string)$identity['account_key']) continue;
        try { $pdo->prepare('DELETE FROM arcade_cloud_saves WHERE account_key=?')->execute([(string)$legacyKey]); }
        catch (Throwable $cleanupError) { error_log('[ARCADE_CLOUD_LEGACY_CLEANUP] ' . $cleanupError->getMessage()); }
    }

    echo json_encode([
        'success'=>true,
        'message'=>'Cloud save updated.',
        'saved_at'=>gmdate('c'),
        'display_name'=>$identity['display_name'],
        'warnings'=>$warnings,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (JsonException $e) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'The browser sent invalid save data. Refresh and try again.','error_code'=>'invalid_json']);
} catch (Throwable $e) {
    error_log('[ARCADE_CLOUD_SAVE] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Unable to save cloud progress. The server logged the exact error for repair.','error_code'=>'cloud_save_failed']);
}
