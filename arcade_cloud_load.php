<?php
declare(strict_types=1);

require __DIR__ . '/arcade_app/bootstrap.php';
require_once ARCADE_APP_PATH . '/includes/cloud_identity.php';
require_once ARCADE_APP_PATH . '/includes/player_authority.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$identity = arcade_cloud_identity(isset($pdo) && $pdo instanceof PDO ? $pdo : null);
if (!$identity['authenticated']) {
    http_response_code(401);
    echo json_encode(['success'=>false,'authenticated'=>false,'message'=>'Log in to use cloud save.']);
    exit;
}
if (!isset($pdo) || !$pdo instanceof PDO) {
    http_response_code(503);
    echo json_encode(['success'=>false,'message'=>'Database connection unavailable.']);
    exit;
}

try {
    arcade_cloud_ensure($pdo);
    $row = arcade_cloud_find_row($pdo, $identity);
    $saveData = [];
    $warnings = [];

    if ($row && trim((string)$row['save_data']) !== '') {
        $decoded = json_decode((string)$row['save_data'], true);
        if (is_array($decoded)) {
            $saveData = $decoded;
        } else {
            // Do not lock the player out because one older save row is malformed.
            // The next successful Save to Cloud replaces it with valid JSON.
            $warnings[] = 'The previous cloud snapshot was unreadable. Device progress can be saved over it safely.';
            error_log('[ARCADE_CLOUD_LOAD_INVALID_JSON] account=' . (string)$identity['account_key'] . ' error=' . json_last_error_msg());
        }
    }

    $authorityState = null;
    $authorityCustomerId = arcade_authority_customer_id();
    if ($authorityCustomerId) {
        try {
            arcade_authority_ensure_player($pdo, $authorityCustomerId, (string)$identity['account_key'], $saveData);
            $authorityState = arcade_authority_state($pdo, $authorityCustomerId);
            $saveData = arcade_authority_apply_snapshot($saveData, $authorityState);
        } catch (Throwable $authorityError) {
            // Cloud recovery and the account label should still load while a
            // secondary Player Hub module is being repaired. Protected economy
            // fields are never trusted from the browser/cloud fallback.
            $saveData = arcade_authority_strip_snapshot($saveData);
            $warnings[] = 'Your account is signed in, but part of the server profile is still synchronizing.';
            error_log('[ARCADE_CLOUD_AUTHORITY_LOAD] ' . $authorityError->getMessage());
        }
    }

    echo json_encode([
        'success'=>true,
        'authenticated'=>true,
        'display_name'=>$identity['display_name'],
        'account'=>[
            'customer_id'=>$identity['customer_id'],
            'account_key'=>$identity['account_key'],
            'display_name'=>$identity['display_name'],
            'email'=>$identity['email'],
        ],
        'authority'=>$authorityState,
        'warnings'=>$warnings,
        'save'=>$row ? [
            'version'=>(int)($row['save_version'] ?? 1),
            'data'=>$saveData,
            'device_label'=>(string)($row['device_label'] ?? ''),
            'updated_at'=>(string)($row['updated_at'] ?? ''),
        ] : null,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[ARCADE_CLOUD_LOAD] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success'=>false,
        'authenticated'=>true,
        'message'=>'Unable to load cloud save. The server logged the exact error for repair.',
        'error_code'=>'cloud_load_failed',
    ]);
}
