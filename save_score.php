<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status'=>'error','message'=>'POST required.']);
    exit;
}
// v3.8.1: this legacy route no longer writes a second, client-trusted score
// table. Signed-in runs are recorded only by arcade_player_action.php, where
// duplicate IDs, rate limits, bounds, account ownership, and audit logging are
// enforced. Returning 200 keeps old cached clients quiet while doing no harm.
echo json_encode([
    'status'=>'deprecated','authoritative'=>true,
    'message'=>'Score submission is handled by the secure run endpoint.'
], JSON_UNESCAPED_SLASHES);
