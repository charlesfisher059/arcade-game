<?php
declare(strict_types=1);

/**
 * embedding_helper.php
 * Path: /home2/asqrtyte/public_html/embedding_helper.php
 *
 * Shared by the publish flow, the admin backfill tool, and anywhere
 * else that needs to (re)generate a product's visual embedding.
 * Requires db_connect.php to already be loaded (uses $pdo).
 */

/**
 * Generate and store an embedding for one product's image.
 * Returns true on success, false on failure (never throws -- a
 * failed embedding should not break whatever called it, like a
 * publish action).
 */
function generateProductEmbedding(PDO $pdo, int $productId, string $imagePath): bool {
    $apiKey = getenv('VOYAGE_API_KEY') ?: '';
    if ($apiKey === '') {
        error_log('[EMBEDDING_HELPER] VOYAGE_API_KEY not set — skipping embedding generation');
        return false;
    }

    $localPath = __DIR__ . '/' . ltrim($imagePath, '/');
    if (!is_file($localPath)) {
        error_log('[EMBEDDING_HELPER] Image file not found: ' . $localPath);
        return false;
    }

    $mime = (string)(finfo_file(finfo_open(FILEINFO_MIME_TYPE), $localPath) ?: 'image/jpeg');
    $base64 = base64_encode((string)file_get_contents($localPath));

    $payload = [
        'inputs' => [[
            'content' => [
                ['type' => 'image_base64', 'image_base64' => 'data:' . $mime . ';base64,' . $base64],
            ],
        ]],
        'model' => 'voyage-multimodal-3',
        'input_type' => 'document',
    ];

    $ch = curl_init('https://api.voyageai.com/v1/multimodalembeddings');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        error_log('[EMBEDDING_HELPER] Voyage API call failed for product ' . $productId . ', http=' . $httpCode);
        return false;
    }

    $decoded = json_decode($response, true);
    $vector = $decoded['data'][0]['embedding'] ?? null;
    if (!is_array($vector) || empty($vector)) {
        error_log('[EMBEDDING_HELPER] No embedding vector in response for product ' . $productId);
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO product_embeddings (product_id, embedding, model)
            VALUES (?, ?, 'voyage-multimodal-3')
            ON DUPLICATE KEY UPDATE embedding = VALUES(embedding), created_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$productId, json_encode($vector)]);
        return true;
    } catch (Throwable $e) {
        error_log('[EMBEDDING_HELPER] DB save failed for product ' . $productId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Cosine similarity between two equal-length vectors.
 * Voyage embeddings are already normalized to length 1, so this is
 * equivalent to a plain dot product -- but computed properly here in
 * case that ever changes upstream.
 */
function cosineSimilarity(array $a, array $b): float {
    $dot = 0.0; $magA = 0.0; $magB = 0.0;
    $len = min(count($a), count($b));
    for ($i = 0; $i < $len; $i++) {
        $dot += $a[$i] * $b[$i];
        $magA += $a[$i] * $a[$i];
        $magB += $b[$i] * $b[$i];
    }
    if ($magA <= 0 || $magB <= 0) return 0.0;
    return $dot / (sqrt($magA) * sqrt($magB));
}