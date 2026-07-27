<?php
declare(strict_types=1);

/**
 * track_recommendation_click.php
 * Path: /home2/asqrtyte/public_html/track_recommendation_click.php
 *
 * Every "you might also like" link routes through here first: log the
 * click, then redirect straight to the real product page. Keeps CTR
 * tracking invisible to the shopper -- a bit slower than a direct link
 * is not, this is a single indexed INSERT before a redirect.
 */

require_once __DIR__ . '/db_connect.php';

$src = (int)($_GET['src'] ?? 0);
$rec = (int)($_GET['rec'] ?? 0);
$to = (string)($_GET['to'] ?? '');

if ($src > 0 && $rec > 0) {
    try {
        $pdo->prepare("
            INSERT INTO product_recommendation_events (source_product_id, recommended_product_id, event_type)
            VALUES (?, ?, 'click')
        ")->execute([$src, $rec]);
    } catch (Throwable $e) {
        error_log('[TRACK_RECOMMENDATION_CLICK] ' . $e->getMessage());
    }
}

$destination = $to !== '' ? '/product/' . $to : '/shop';
header('Location: ' . $destination, true, 302);
exit;