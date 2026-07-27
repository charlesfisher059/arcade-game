<?php
declare(strict_types=1);

/**
 * related_products.php
 * Path: /home2/asqrtyte/public_html/related_products.php
 *
 * Weekend 4 of the AI listing roadmap, with one scoping decision worth
 * being upfront about: this is ATTRIBUTE similarity (type, color,
 * brand, material, category -- all things the AI classifier already
 * extracts), not true visual/pixel embedding similarity. A real
 * embedding-based approach needs a vector store and an embedding
 * model call per product, which is a real infrastructure decision
 * (cost + a data source to pick) rather than something to bolt on
 * quietly inside a shared-hosting PHP stack. This gets you working,
 * genuinely relevant recommendations today; true visual embeddings
 * are a clearly separate upgrade if you want to invest in it later.
 *
 * Usage: include this file after defining $relatedForProductId (int).
 * It queries, renders its own markup, and logs an impression event
 * per recommendation shown. Drop the include into your product page
 * template wherever the "you might also like" section belongs.
 */

if (!isset($relatedForProductId) || !is_int($relatedForProductId) || $relatedForProductId <= 0) {
    return;
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once __DIR__ . '/db_connect.php';
}

function relprod_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

$related = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
    $stmt->execute([$relatedForProductId]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($source) {
        // Score candidates by how many attributes match. Products created
        // before the AI classifier existed will have NULL attributes and
        // fall back to category-only matching -- less precise, but not broken.
        $sql = "
            SELECT *,
                (CASE WHEN item_type IS NOT NULL AND item_type = ? THEN 3 ELSE 0 END)
              + (CASE WHEN item_color IS NOT NULL AND item_color = ? THEN 2 ELSE 0 END)
              + (CASE WHEN item_brand IS NOT NULL AND ? IS NOT NULL AND item_brand = ? THEN 2 ELSE 0 END)
              + (CASE WHEN item_material IS NOT NULL AND item_material = ? THEN 1 ELSE 0 END)
              + (CASE WHEN category = ? THEN 1 ELSE 0 END)
              AS similarity_score
            FROM products
            WHERE id != ? AND stock > 0
            HAVING similarity_score > 0
            ORDER BY similarity_score DESC, RAND()
            LIMIT 6
        ";
        $stmt2 = $pdo->prepare($sql);
        $stmt2->execute([
            $source['item_type'], $source['item_color'],
            $source['item_brand'], $source['item_brand'],
            $source['item_material'], $source['category'],
            $relatedForProductId,
        ]);
        $related = $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!empty($related)) {
            $impStmt = $pdo->prepare("
                INSERT INTO product_recommendation_events (source_product_id, recommended_product_id, event_type)
                VALUES (?, ?, 'impression')
            ");
            foreach ($related as $r) {
                $impStmt->execute([$relatedForProductId, (int)$r['id']]);
            }
        }
    }
} catch (Throwable $e) {
    error_log('[RELATED_PRODUCTS] ' . $e->getMessage());
    $related = [];
}

if (empty($related)) return;
?>
<style>
  .related-products-section { margin-top: 40px; }
  .related-products-title {
    font-family: 'Space Mono', monospace;
    font-size: 0.7rem;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: #00ff9d;
    margin-bottom: 14px;
  }
  .related-products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 14px;
  }
  .related-product-card {
    display: block;
    text-decoration: none;
    color: inherit;
  }
  .related-product-card img {
    width: 100%;
    aspect-ratio: 1;
    object-fit: cover;
    border-radius: 8px;
    background: #111;
  }
  .related-product-name {
    font-family: 'Space Mono', monospace;
    font-size: 0.62rem;
    color: #ccc;
    margin-top: 6px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .related-product-price {
    font-family: 'Space Mono', monospace;
    font-size: 0.66rem;
    color: #00ff9d;
    margin-top: 2px;
  }
</style>
<div class="related-products-section">
  <div class="related-products-title">You Might Also Like</div>
  <div class="related-products-grid">
    <?php foreach ($related as $r):
        $imgUrl = (string)$r['image_url'];
        if (!preg_match('#^(https?:)?//#i', $imgUrl)) $imgUrl = '/' . ltrim($imgUrl, '/');
        $slug = trim((string)($r['slug'] ?? ''));
        $target = $slug !== '' ? rawurlencode($slug) : (string)(int)$r['id'];
        $trackLink = '/track_recommendation_click.php?src=' . (int)$relatedForProductId . '&rec=' . (int)$r['id'] . '&to=' . rawurlencode($target);
    ?>
    <a class="related-product-card" href="<?= relprod_h($trackLink) ?>">
        <img src="<?= relprod_h($imgUrl) ?>" alt="<?= relprod_h($r['name']) ?>" loading="lazy">
        <div class="related-product-name"><?= relprod_h($r['name']) ?></div>
        <div class="related-product-price">$<?= relprod_h(number_format((float)$r['price'], 2)) ?></div>
    </a>
    <?php endforeach; ?>
  </div>
</div>