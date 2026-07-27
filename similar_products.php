<?php
declare(strict_types=1);

/**
 * similar_products.php
 * Path: /home2/asqrtyte/public_html/similar_products.php
 *
 * GET ?product_id=123&limit=6
 *
 * Returns the N most visually similar OTHER products, computed by
 * cosine similarity over stored Voyage embeddings. With a catalog this
 * size, comparing against every other embedding in PHP is fast enough
 * -- no vector database needed. Logs an impression each time this is
 * called, which is the denominator half of the click-through-rate
 * metric (see recommendation_track_click.php for the numerator half).
 */

header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/db_connect.php';

$productId = (int)($_GET['product_id'] ?? 0);
$limit = max(1, min(12, (int)($_GET['limit'] ?? 6)));

if ($productId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing product_id']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT embedding FROM product_embeddings WHERE product_id = ? LIMIT 1");
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['ok' => true, 'has_embedding' => false, 'items' => []]);
        exit;
    }
    $sourceVector = json_decode((string)$row['embedding'], true);
    if (!is_array($sourceVector)) {
        echo json_encode(['ok' => true, 'has_embedding' => false, 'items' => []]);
        exit;
    }

    require_once __DIR__ . '/embedding_helper.php';

    // Pull all other embeddings. Fine for a boutique-size catalog; if
    // this ever grows into the tens of thousands of SKUs, this is the
    // point where a real vector index would replace the PHP loop below.
    $allStmt = $pdo->prepare("
        SELECT pe.product_id, pe.embedding, p.name, p.price, p.image_url
        FROM product_embeddings pe
        JOIN products p ON p.id = pe.product_id
        WHERE pe.product_id != ? AND p.stock > 0
    ");
    $allStmt->execute([$productId]);
    $rows = $allStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $scored = [];
    foreach ($rows as $r) {
        $vec = json_decode((string)$r['embedding'], true);
        if (!is_array($vec)) continue;
        $sim = cosineSimilarity($sourceVector, $vec);
        $scored[] = [
            'product_id' => (int)$r['product_id'],
            'name' => $r['name'],
            'price' => (float)$r['price'],
            'image_url' => $r['image_url'],
            'similarity' => round($sim, 4),
        ];
    }

    usort($scored, function ($a, $b) { return $b['similarity'] <=> $a['similarity']; });
    $top = array_slice($scored, 0, $limit);

    if (!empty($top)) {
        $pdo->prepare("INSERT INTO recommendation_impressions (source_product_id, shown_count) VALUES (?, 1)")
            ->execute([$productId]);
    }

    echo json_encode(['ok' => true, 'has_embedding' => true, 'items' => $top]);
} catch (Throwable $e) {
    error_log('[SIMILAR_PRODUCTS] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Lookup failed']);
}