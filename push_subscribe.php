<?php
declare(strict_types=1);
/**
 * push_subscribe.php
 * Path: /home2/asqrtyte/public_html/push_subscribe.php
 *
 * Saves a browser's push subscription. Works for both logged-in
 * customers (customer_id set, so notifications can target a specific
 * person) and anonymous visitors (customer_id NULL, for general
 * marketing/announcement pushes only -- do not send anything
 * account-specific to a NULL-customer_id subscription).
 */
header('Content-Type: application/json; charset=UTF-8');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Service unavailable']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input) || empty($input['endpoint']) || empty($input['keys']['p256dh']) || empty($input['keys']['auth'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid subscription payload']);
    exit;
}

$endpoint = substr((string)$input['endpoint'], 0, 500);
$p256dh = substr((string)$input['keys']['p256dh'], 0, 255);
$auth = substr((string)$input['keys']['auth'], 0, 255);
$customerId = !empty($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : null;
$userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

try {
    $stmt = $pdo->prepare("
        INSERT INTO push_subscriptions (endpoint, customer_id, p256dh, auth, user_agent, last_used_at)
        VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
            customer_id = VALUES(customer_id),
            p256dh = VALUES(p256dh),
            auth = VALUES(auth),
            user_agent = VALUES(user_agent),
            last_used_at = UTC_TIMESTAMP(),
            last_error = NULL
    ");
    $stmt->execute([$endpoint, $customerId, $p256dh, $auth, $userAgent]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('[PUSH_SUBSCRIBE] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not save subscription']);
}