<?php
declare(strict_types=1);

/**
 * recommendation_track_click.php
 * Path: /home2/asqrtyte/public_html/recommendation_track_click.php
 *
 * POST { source_product_id, clicked_product_id }
 * Call this when someone actually clicks a "visually similar" item.
 * Paired with the impression logged in similar_products.php, this is
 * what makes a real click-through rate possible: clicks / impressions,
 * per your own refined KPI -- not a price-accuracy guess.
 */

header('Content-Type: application/json; charset=UTF-8');

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
$sourceId = (int)($input['source_product_id'] ?? 0);
$clickedId = (int)($input['clicked_product_id'] ?? 0);
$customerId = !empty($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : null;

if ($sourceId <= 0 || $clickedId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing product ids']);
    exit;
}

try {
    $pdo->prepare("
        INSERT INTO recommendation_clicks (source_product_id, clicked_product_id, customer_id)
        VALUES (?, ?, ?)
    ")->execute([$sourceId, $clickedId, $customerId]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('[RECOMMENDATION_TRACK_CLICK] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
}