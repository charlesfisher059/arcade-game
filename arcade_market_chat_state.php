<?php
declare(strict_types=1);

require __DIR__ . '/arcade_app/bootstrap.php';
require_once ARCADE_APP_PATH . '/includes/cloud_identity.php';
require_once ARCADE_APP_PATH . '/includes/arcade_schema_manager.php';
require_once ARCADE_APP_PATH . '/includes/player_authority.php';
require_once ARCADE_APP_PATH . '/includes/arcade_health.php';
require_once ARCADE_APP_PATH . '/includes/arcade_community.php';
require_once ARCADE_APP_PATH . '/includes/arcade_trust_safety.php';
require_once ARCADE_APP_PATH . '/includes/arcade_market_chat.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$customerId=arcade_authority_customer_id();
if(!$customerId){http_response_code(401);echo json_encode(['success'=>false,'authenticated'=>false,'message'=>'Log in to load Chat and Marketplace.']);exit;}
if(!isset($pdo)||!($pdo instanceof PDO)){http_response_code(503);echo json_encode(['success'=>false,'message'=>'Database connection unavailable.']);exit;}
if(arcade_maintenance_mode_enabled($pdo)){http_response_code(503);echo json_encode(['success'=>false,'maintenance'=>true,'message'=>arcade_maintenance_message($pdo)]);exit;}

try{
    arcade_schema_ensure($pdo);
    arcade_authority_ensure_player($pdo,$customerId,'customer_id:'.$customerId);
    echo json_encode([
        'success'=>true,'authenticated'=>true,
        'marketChat'=>arcade_market_chat_full_state($pdo,$customerId),
        'trust'=>arcade_trust_player_state($pdo,$customerId),
        'csrf'=>arcade_cloud_csrf_token(),'version'=>arcade_version_string(),
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    error_log('[ARCADE_MARKET_CHAT_STATE] '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Unable to load Chat and Marketplace.']);
}
