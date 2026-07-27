<?php
/**
 * cosmetics.php — COSMETIC UNLOCK AUTHORITY v3.1
 * Path: /home2/asqrtyte/public_html/cosmetics.php
 *
 * SOURCES:
 * 1) Admin unlock rules (unlock_rules table)
 * 2) Merch purchases (orders + order_items)
 * 3) Arcade performance (arcade_scores)
 *
 * PATCH v3.1: Uses $_SESSION['customer_id'] (the real account session key
 * set by account/login.php) instead of the nonexistent 'user_email' key,
 * which meant this endpoint never recognized logged-in customers before.
 *
 * RETURNS:
 * { ok: true, unlockedSkinIds: [] }
 */
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* -------------------------------------------------
   BASE UNLOCKS
--------------------------------------------------*/
$unlocked_skins = ['default'];

/* -------------------------------------------------
   DATABASE
--------------------------------------------------*/
require_once __DIR__ . '/db_connect.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo json_encode(['ok' => true, 'unlockedSkinIds' => $unlocked_skins]);
    exit;
}

/* -------------------------------------------------
   USER IDENTIFICATION (real account session key)
--------------------------------------------------*/
$user_email = '';
$customerId = !empty($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : 0;

if ($customerId > 0) {
    try {
        $custStmt = $pdo->prepare("SELECT email FROM customers WHERE id = ? LIMIT 1");
        $custStmt->execute([$customerId]);
        $custRow = $custStmt->fetch(PDO::FETCH_ASSOC);
        if ($custRow) {
            $user_email = (string)$custRow['email'];
        }
    } catch (Throwable $e) {
        error_log('[COSMETICS][CUSTOMER_LOOKUP] ' . $e->getMessage());
    }
}

if ($user_email === '') {
    echo json_encode(['ok' => true, 'unlockedSkinIds' => $unlocked_skins]);
    exit;
}

/* -------------------------------------------------
   1) MERCH -> SKIN UNLOCKS (ADMIN RULES)
--------------------------------------------------*/
try {
    $ruleStmt = $pdo->query("
        SELECT keyword, skin_id
        FROM unlock_rules
        WHERE is_active = 1
    ");
    $rules = $ruleStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($rules)) {
        $orderStmt = $pdo->prepare("
            SELECT oi.product_name
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE o.email = ?
              AND o.order_status IN ('PAID','COMPLETED','SHIPPED','DELIVERED')
        ");
        $orderStmt->execute([$user_email]);
        while ($row = $orderStmt->fetch(PDO::FETCH_ASSOC)) {
            $product_name = strtoupper((string)$row['product_name']);
            foreach ($rules as $rule) {
                if (strpos($product_name, strtoupper((string)$rule['keyword'])) !== false) {
                    $unlocked_skins[] = (string)$rule['skin_id'];
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('[COSMETICS][RULES] ' . $e->getMessage());
}

/* -------------------------------------------------
   2) ARCADE PERFORMANCE UNLOCKS
--------------------------------------------------*/
try {
    $bestStmt = $pdo->prepare("
        SELECT score, level_reached
        FROM arcade_scores
        WHERE player_email = ? OR customer_id = ?
        ORDER BY score DESC, level_reached DESC
        LIMIT 1
    ");
    $bestStmt->execute([$user_email, $customerId]);
    $best = $bestStmt->fetch(PDO::FETCH_ASSOC);

    if ($best) {
        $score = (int)$best['score'];
        $level = (int)$best['level_reached'];

        if ($score >= 5000)  $unlocked_skins[] = 'neoncore';
        if ($score >= 10000) $unlocked_skins[] = 'goldline';
        if ($score >= 25000) $unlocked_skins[] = 'overlord';

        if ($level >= 10) $unlocked_skins[] = 'deepvoid';
        if ($level >= 20) $unlocked_skins[] = 'apex';
    }

    $rankStmt = $pdo->query("
        SELECT player_email, customer_id
        FROM arcade_scores
        ORDER BY score DESC, level_reached DESC
        LIMIT 5
    ");
    $rank = 1;
    while ($row = $rankStmt->fetch(PDO::FETCH_ASSOC)) {
        $isMatch = ((string)$row['player_email'] === $user_email)
            || ($customerId > 0 && (int)($row['customer_id'] ?? 0) === $customerId);
        if ($isMatch) {
            if ($rank <= 3) $unlocked_skins[] = 'elite';
            if ($rank === 1) $unlocked_skins[] = 'crowned';
            break;
        }
        $rank++;
    }
} catch (Throwable $e) {
    error_log('[COSMETICS][ARCADE] ' . $e->getMessage());
}

/* -------------------------------------------------
   FINAL RESPONSE
--------------------------------------------------*/
echo json_encode([
    'ok' => true,
    'unlockedSkinIds' => array_values(array_unique($unlocked_skins))
]);