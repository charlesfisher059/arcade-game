<?php
declare(strict_types=1);
require __DIR__ . '/arcade_app/bootstrap.php';
require_once ARCADE_APP_PATH . '/includes/arcade_leaderboard.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!isset($pdo) || !$pdo instanceof PDO) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'system_status'=>'DATABASE_SYNC_FAILED','count'=>0,'ranks'=>[]]);
    exit;
}
$rows=arcade_leaderboard_rows($pdo,'alltime',null,[],5);
$ranks=[];
foreach($rows as $row){
    $ranks[]=[
        'subject'=>(string)$row['display_name'],
        'score'=>(int)$row['score'],
        'level'=>0,
        'character'=>(string)$row['character_id'],
        'form'=>(string)$row['character_form'],
        'source'=>(string)($row['source']??'verified'),
    ];
}
echo json_encode([
    'ok'=>true,'authoritative'=>true,'system_status'=>'DATABASE_SYNC_COMPLETE',
    'count'=>count($ranks),'ranks'=>$ranks,
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
