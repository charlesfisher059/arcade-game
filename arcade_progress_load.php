<?php
declare(strict_types=1);
require __DIR__ . '/arcade_app/bootstrap.php';
require_once ARCADE_APP_PATH . '/includes/player_authority.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$customerId=arcade_authority_customer_id();
if(!$customerId){echo json_encode(['ok'=>true,'logged_in'=>false]);exit;}
if(!isset($pdo)||!$pdo instanceof PDO){http_response_code(503);echo json_encode(['ok'=>false,'logged_in'=>true,'error'=>'Database unavailable']);exit;}
try{
    $state=arcade_authority_state($pdo,$customerId);
    $stats=$pdo->prepare(
        "SELECT COALESCE(SUM(runs),0) total_runs,COALESCE(SUM(catches),0) total_catches,
                COALESCE(SUM(bosses),0) boss_clears
         FROM arcade_player_characters WHERE customer_id=?"
    );
    $stats->execute([$customerId]); $totals=$stats->fetch(PDO::FETCH_ASSOC) ?: [];
    $best=$pdo->prepare(
        "SELECT COALESCE(MAX(score),0) FROM arcade_player_run_receipts r
         WHERE customer_id=? AND NOT EXISTS(SELECT 1 FROM arcade_score_flags sf WHERE sf.run_id=r.run_id)"
    );
    $best->execute([$customerId]);
    $legacy=$pdo->prepare("SELECT achievements,claimed_rewards FROM arcade_player_progression WHERE customer_id=? LIMIT 1");
    $legacy->execute([$customerId]); $lists=$legacy->fetch(PDO::FETCH_ASSOC) ?: [];
    echo json_encode(['ok'=>true,'logged_in'=>true,'authoritative'=>true,'progress'=>[
        'xp'=>(int)$state['lifetimeShards'],'lifetime_shards'=>(int)$state['lifetimeShards'],
        'total_catches'=>(int)($totals['total_catches']??0),'total_runs'=>(int)($totals['total_runs']??0),
        'boss_clears'=>(int)($totals['boss_clears']??0),'best_score'=>(int)$best->fetchColumn(),
        'achievements'=>json_decode((string)($lists['achievements']??'[]'),true)?:[],
        'claimed_rewards'=>json_decode((string)($lists['claimed_rewards']??'[]'),true)?:[],
    ]],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){error_log('[ARCADE_PROGRESS_LOAD] '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'logged_in'=>true,'error'=>'Load failed']);}
