<?php
declare(strict_types=1);

/**
 * ai_listing_publish.php
 * Path: /home2/asqrtyte/public_html/ai_listing_publish.php
 *
 * Takes a draft (with whatever edits the human made) and moves it
 * forward. What "forward" means depends on who's publishing:
 *   - Admin drafts become a real row in `products` immediately.
 *   - Seller drafts move to 'pending_review' -- they do NOT go live on
 *     their own. A marketplace where anyone's upload becomes a live
 *     listing with no review is a moderation problem waiting to
 *     happen, so seller submissions wait for an admin to approve them
 *     (that approval screen isn't built yet -- see the note at the end
 *     of this response).
 */

header('Content-Type: application/json; charset=UTF-8');

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$isAdmin = !empty($_SESSION['admin_logged_in']);
$customerId = !empty($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : null;

if (!$isAdmin && !$customerId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Log in to publish a listing']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

$draftId = (int)($input['draft_id'] ?? 0);
if ($draftId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing draft_id']);
    exit;
}

$editedTitle = trim((string)($input['title'] ?? ''));
$editedDescription = trim((string)($input['description'] ?? ''));
$price = isset($input['price']) ? round((float)$input['price'], 2) : null;
$category = trim((string)($input['category'] ?? 'MENS'));
$stock = isset($input['stock']) ? max(0, (int)$input['stock']) : 1;

if ($editedTitle === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Title is required']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM ai_listing_drafts WHERE id = ? LIMIT 1");
    $stmt->execute([$draftId]);
    $draft = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$draft) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Draft not found']);
        exit;
    }
    // Ownership check: admins can publish any draft; sellers only their own.
    if (!$isAdmin && (int)$draft['customer_id'] !== $customerId) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Not your draft']);
        exit;
    }
    if ($draft['status'] !== 'draft') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'This draft was already ' . $draft['status']]);
        exit;
    }

    $pdo->beginTransaction();

    $pdo->prepare("UPDATE ai_listing_drafts SET edited_title = ?, edited_description = ? WHERE id = ?")
        ->execute([$editedTitle, $editedDescription, $draftId]);

    if ($isAdmin) {
        if ($price === null) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Price is required to publish to the catalog']);
            exit;
        }
        $prodStmt = $pdo->prepare("
            INSERT INTO products (name, price, stock, category, image_url, description, item_brand, item_type, item_color, item_material)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $prodStmt->execute([
            $editedTitle, $price, $stock, substr($category, 0, 50),
            $draft['image_path'], $editedDescription,
            $draft['ai_brand'], $draft['ai_type'], $draft['ai_color'], $draft['ai_material'],
        ]);
        $productId = (int)$pdo->lastInsertId();

        $pdo->prepare("UPDATE ai_listing_drafts SET status = 'published', published_product_id = ? WHERE id = ?")
            ->execute([$productId, $draftId]);

        $pdo->commit();

        require_once __DIR__ . '/embedding_helper.php';
        generateProductEmbedding($pdo, $productId, $draft['image_path']);

        echo json_encode(['ok' => true, 'status' => 'published', 'product_id' => $productId]);
    } else {
        $askingPrice = isset($input['asking_price']) && $input['asking_price'] !== '' ? round((float)$input['asking_price'], 2) : null;
        $pdo->prepare("UPDATE ai_listing_drafts SET status = 'pending_review', asking_price = ? WHERE id = ?")
            ->execute([$askingPrice, $draftId]);

        $pdo->commit();
        echo json_encode(['ok' => true, 'status' => 'pending_review']);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[AI_LISTING_PUBLISH] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Publish failed']);
}