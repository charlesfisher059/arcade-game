<?php
declare(strict_types=1);
require __DIR__.'/arcade_app/bootstrap.php';
require_once ARCADE_APP_PATH.'/includes/cloud_identity.php';
require_once ARCADE_APP_PATH.'/includes/player_authority.php';
require_once ARCADE_APP_PATH.'/includes/arcade_diagnostics.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['success'=>false,'message'=>'POST required.']);exit;}
$csrf=(string)($_SERVER['HTTP_X_ARCADE_CSRF']??'');
if($csrf===''||!hash_equals(arcade_cloud_csrf_token(),$csrf)){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Invalid security token.']);exit;}
if(!isset($pdo)||!($pdo instanceof PDO)){http_response_code(503);echo json_encode(['success'=>false,'message'=>'Diagnostics unavailable.']);exit;}
$settings=arcade_diagnostics_settings($pdo);if(empty($settings['diagnosticReporting'])){echo json_encode(['success'=>true,'disabled'=>true]);exit;}
$now=time();$bucket=is_array($_SESSION['arcade_diag_rate']??null)?$_SESSION['arcade_diag_rate']:[];$bucket=array_values(array_filter($bucket,static fn($ts)=>(int)$ts>$now-60));
if(count($bucket)>=20){http_response_code(429);echo json_encode(['success'=>false,'message'=>'Report limit reached.']);exit;}
$raw=file_get_contents('php://input');if($raw===false||strlen($raw)>12288){http_response_code(413);echo json_encode(['success'=>false,'message'=>'Report too large.']);exit;}
try{$event=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($event))throw new InvalidArgumentException('Invalid report.');
$customerId=arcade_authority_customer_id();$saved=arcade_diagnostics_record($pdo,$event,$customerId);$bucket[]=$now;$_SESSION['arcade_diag_rate']=$bucket;echo json_encode(['success'=>$saved]);
}catch(Throwable $e){error_log('[ARCADE_DIAGNOSTICS_REPORT] '.$e->getMessage());http_response_code(400);echo json_encode(['success'=>false,'message'=>'Unable to record report.']);}
