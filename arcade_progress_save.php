<?php
declare(strict_types=1);
require __DIR__ . '/arcade_app/bootstrap.php';
require_once ARCADE_APP_PATH . '/includes/cloud_identity.php';
require_once ARCADE_APP_PATH . '/includes/player_authority.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'POST required']);exit;}
$customerId=arcade_authority_customer_id();
if(!$customerId){echo json_encode(['ok'=>true,'logged_in'=>false]);exit;}
$csrf=(string)($_SERVER['HTTP_X_ARCADE_CSRF']??'');
if($csrf===''||!hash_equals(arcade_cloud_csrf_token(),$csrf)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Invalid security token']);exit;}
if(!isset($pdo)||!$pdo instanceof PDO){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Database unavailable']);exit;}
$raw=(string)file_get_contents('php://input');
if(strlen($raw)>32768){http_response_code(413);echo json_encode(['ok'=>false,'error'=>'Payload too large']);exit;}
$input=json_decode($raw,true);
if(!is_array($input)){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Invalid payload']);exit;}
$cleanList=static function($value):array{
    if(!is_array($value))return [];
    $out=[];foreach($value as $item){if(!is_scalar($item))continue;$v=substr(trim((string)$item),0,80);if($v!=='')$out[$v]=true;}
    return array_slice(array_keys($out),0,200);
};
$incomingAchievements=$cleanList($input['achievements']??[]);
$incomingRewards=$cleanList($input['claimed_rewards']??[]);
try{
    $pdo->beginTransaction();
    $stmt=$pdo->prepare("SELECT achievements,claimed_rewards FROM arcade_player_progression WHERE customer_id=? FOR UPDATE");
    $stmt->execute([$customerId]);$existing=$stmt->fetch(PDO::FETCH_ASSOC)?:[];
    $ach=array_values(array_unique(array_merge(json_decode((string)($existing['achievements']??'[]'),true)?:[],$incomingAchievements)));
    $rew=array_values(array_unique(array_merge(json_decode((string)($existing['claimed_rewards']??'[]'),true)?:[],$incomingRewards)));
    $state=arcade_authority_state($pdo,$customerId);
    $stats=$pdo->prepare("SELECT COALESCE(SUM(runs),0),COALESCE(SUM(catches),0),COALESCE(SUM(bosses),0) FROM arcade_player_characters WHERE customer_id=?");
    $stats->execute([$customerId]);$totals=$stats->fetch(PDO::FETCH_NUM)?:[0,0,0];
    $best=$pdo->prepare("SELECT COALESCE(MAX(score),0) FROM arcade_player_run_receipts WHERE customer_id=?");$best->execute([$customerId]);
    $pdo->prepare(
        "INSERT INTO arcade_player_progression
         (customer_id,xp,lifetime_shards,total_catches,total_runs,boss_clears,best_score,achievements,claimed_rewards)
         VALUES (?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE xp=VALUES(xp),lifetime_shards=VALUES(lifetime_shards),
           total_catches=VALUES(total_catches),total_runs=VALUES(total_runs),boss_clears=VALUES(boss_clears),
           best_score=VALUES(best_score),achievements=VALUES(achievements),claimed_rewards=VALUES(claimed_rewards)"
    )->execute([$customerId,(int)$state['lifetimeShards'],(int)$state['lifetimeShards'],(int)$totals[1],(int)$totals[0],(int)$totals[2],(int)$best->fetchColumn(),json_encode($ach),json_encode($rew)]);
    $pdo->commit();echo json_encode(['ok'=>true,'logged_in'=>true,'authoritative'=>true]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('[ARCADE_PROGRESS_SAVE] '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Save failed']);}
