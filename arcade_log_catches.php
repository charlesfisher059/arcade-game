<?php
declare(strict_types=1);

/**
 * arcade_log_catches.php
 * Path: /home2/asqrtyte/public_html/arcade_log_catches.php
 *
 * Logs which products were caught during a run, batched as one request
 * at the end of the run rather than firing on every single catch.
 * Powers the "most collected products" admin analytics.
 */

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/db_connect.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
    exit;
}

$rawBody = file_get_contents('php://input');
$input = json_decode((string)$rawBody, true);
$items = is_array($input['items'] ?? null) ? $input['items'] : [];

if (empty($items) || count($items) > 50) {
    // Reject empty or suspiciously large payloads; this should only
    // ever be a handful of distinct products from one run.
    echo json_encode(['ok' => true, 'logged' => 0]);
    exit;
}

$customerId = !empty($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : null;
$logged = 0;

try {
    $stmt = $pdo->prepare("
        INSERT INTO arcade_product_catches (product_id, product_name, catch_count, customer_id)
        VALUES (?, ?, ?, ?)
    ");
    foreach ($items as $item) {
        $name = trim((string)($item['name'] ?? ''));
        if ($name === '') continue;
        $productId = isset($item['id']) && (int)$item['id'] > 0 ? (int)$item['id'] : null;
        $count = max(1, min(9999, (int)($item['count'] ?? 1)));
        $stmt->execute([$productId, substr($name, 0, 255), $count, $customerId]);
        $logged++;
    }
    echo json_encode(['ok' => true, 'logged' => $logged]);
} catch (Throwable $e) {
    error_log('[ARCADE_LOG_CATCHES] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
}