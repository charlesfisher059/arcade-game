<?php
declare(strict_types=1);
require __DIR__.'/arcade_app/bootstrap.php';
require_once ARCADE_APP_PATH.'/includes/cloud_identity.php';
require_once ARCADE_APP_PATH.'/includes/player_authority.php';
require_once ARCADE_APP_PATH.'/includes/arcade_diagnostics.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$customerId=arcade_authority_customer_id();if(!$customerId){http_response_code(401);echo json_encode(['success'=>false,'authenticated'=>false]);exit;}
if(!isset($pdo)||!($pdo instanceof PDO)){http_response_code(503);echo json_encode(['success'=>false,'message'=>'Recovery unavailable.']);exit;}
try{
 if($_SERVER['REQUEST_METHOD']==='GET'){echo json_encode(['success'=>true,'snapshot'=>arcade_diagnostics_load_recovery($pdo,$customerId),'version'=>arcade_version_string()],JSON_UNESCAPED_SLASHES);exit;}
 if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['success'=>false]);exit;}
 $csrf=(string)($_SERVER['HTTP_X_ARCADE_CSRF']??'');if($csrf===''||!hash_equals(arcade_cloud_csrf_token(),$csrf)){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Invalid security token.']);exit;}
 $raw=file_get_contents('php://input');if($raw===false||strlen($raw)>20000)throw new InvalidArgumentException('Invalid recovery request.');
 $input=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$action=(string)($input['action']??'');
 if($action==='save'){if(!is_array($input['snapshot']??null))throw new InvalidArgumentException('Snapshot required.');arcade_diagnostics_save_recovery($pdo,$customerId,$input['snapshot']);echo json_encode(['success'=>true]);exit;}
 if($action==='clear'){arcade_diagnostics_clear_recovery($pdo,$customerId);echo json_encode(['success'=>true]);exit;}
 throw new InvalidArgumentException('Unknown recovery action.');
}catch(Throwable $e){error_log('[ARCADE_RUN_RECOVERY] '.$e->getMessage());http_response_code(400);echo json_encode(['success'=>false,'message'=>'Unable to process recovery.']);}
