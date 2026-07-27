<?php
declare(strict_types=1);

/**
 * offer_pay_success.php -- Path: /home2/asqrtyte/public_html/offer_pay_success.php
 *
 * Stripe redirects here after a successful accepted-offer payment.
 * Verifies the session server-side (never trusts the redirect alone),
 * marks the offer PAID, decrements stock, and inserts a real
 * orders/order_items row so the sale shows up in your dashboard and
 * counts toward the Exchange's real guide-price history.
 */

session_start();

require_once __DIR__ . '/db_connect.php';

function ops_render(string $title, string $message, bool $isError = true): void {
    header('Content-Type: text/html; charset=UTF-8');
    $color = $isError ? '#ff4d4d' : '#00ff9d';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' | DIAMONDS OUTTA DIRT</title>';
    echo '<style>body{margin:0;background:#000;color:#fff;font-family:ui-monospace,Menlo,Consolas,monospace;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px} .box{max-width:560px;border:1px solid #333;background:#050505;padding:28px;border-radius:10px;text-align:center} .h{color:' . $color . ';letter-spacing:2px;font-weight:800;margin:0 0 12px;font-size:1.1rem} .p{color:#bbb;line-height:1.6;margin:0 0 10px} a{color:#00ff9d}</style></head><body><div class="box"><div class="h">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div><p class="p">' . $message . '</p><p class="p"><a href="/">Return Home</a></p></div></body></html>';
    exit;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    ops_render('UNAVAILABLE', 'This page cannot load right now. Please contact us with your payment confirmation email.');
}

$sessionId = (string)($_GET['session_id'] ?? '');
$token = (string)($_GET['token'] ?? '');

if ($sessionId === '' || $token === '') {
    ops_render('INVALID REQUEST', 'This confirmation link is missing required information.');
}

try {
    $stmt = $pdo->prepare("SELECT * FROM offers WHERE pay_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $offer = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[OFFER_PAY_SUCCESS] ' . $e->getMessage());
    ops_render('UNAVAILABLE', 'This page cannot load right now. Please contact us with your payment confirmation email.');
    exit;
}

if (!$offer) {
    ops_render('NOT FOUND', 'We could not find this offer. Please contact us with your payment confirmation email.');
}

if ($offer['status'] === 'PAID') {
    ops_render('PAYMENT CONFIRMED', 'Your payment was already confirmed. Thank you for your purchase!', false);
}

/* ---- Stripe SDK + key load (same pattern as offer_pay.php) ---- */
$stripeLoaded = false;
try {
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
        $stripeLoaded = class_exists('Stripe\\Stripe');
    }
} catch (Throwable $e) {
    error_log('Stripe autoload error: ' . $e->getMessage());
}

if (!$stripeLoaded) {
    ops_render('UNAVAILABLE', 'We could not verify your payment automatically. Please contact us with your payment confirmation email.');
}

$stripeSecretKey = getenv('STRIPE_SECRET_KEY') ?: '';
if (empty($stripeSecretKey) && file_exists('/home2/asqrtyte/config/stripe.php')) {
    try {
        $configContent = file_get_contents('/home2/asqrtyte/config/stripe.php');
        if ($configContent !== false) {
            $needle = "setApiKey(";
            $pos = strpos($configContent, $needle);
            if ($pos !== false) {
                $start = $pos + strlen($needle);
                $quoteChar = $configContent[$start] ?? '';
                if ($quoteChar === "'" || $quoteChar === '"') {
                    $end = strpos($configContent, $quoteChar, $start + 1);
                    if ($end !== false) {
                        $stripeSecretKey = substr($configContent, $start + 1, $end - $start - 1);
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Failed to load stripe config: ' . $e->getMessage());
    }
}

if (empty($stripeSecretKey)) {
    ops_render('UNAVAILABLE', 'We could not verify your payment automatically. Please contact us with your payment confirmation email.');
}

Stripe\Stripe::setApiKey($stripeSecretKey);

try {
    $session = Stripe\Checkout\Session::retrieve($sessionId);
} catch (Throwable $e) {
    error_log('[OFFER_PAY_SUCCESS] Stripe retrieve failed: ' . $e->getMessage());
    ops_render('VERIFICATION FAILED', 'We could not verify your payment. Please contact us with your payment confirmation email.');
    exit;
}

$metaOfferId = (string)($session->metadata->offer_id ?? '');
$metaToken = (string)($session->metadata->offer_token ?? '');
$paymentStatus = (string)($session->payment_status ?? '');

if ($metaOfferId === '' || (int)$metaOfferId !== (int)$offer['id'] || $metaToken !== $token) {
    error_log('[OFFER_PAY_SUCCESS] Metadata mismatch for session ' . $sessionId);
    ops_render('VERIFICATION FAILED', 'We could not verify this payment. Please contact us with your payment confirmation email.');
}

if ($paymentStatus !== 'paid') {
    ops_render('PAYMENT NOT COMPLETE', 'Your payment has not completed yet. If you completed checkout, please wait a moment and refresh, or contact us.');
}

/* ---- Payment verified. Record everything. ---- */
try {
    $pdo->beginTransaction();

    // Re-lock + re-check stock inside the transaction to avoid overselling.
    $lockStmt = $pdo->prepare("SELECT id, name, stock FROM products WHERE id = ? FOR UPDATE");
    $lockStmt->execute([(int)$offer['product_id']]);
    $product = $lockStmt->fetch(PDO::FETCH_ASSOC);

    $quantity = (int)$offer['quantity'];

    if ($product && (int)$product['stock'] >= $quantity) {
        $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?")->execute([$quantity, (int)$offer['product_id']]);
    } else {
        error_log('[OFFER_PAY_SUCCESS] Stock insufficient at payment time for offer #' . $offer['id'] . ' -- payment still honored, stock not decremented below zero.');
    }

    $ordersTableOk = false;
    try {
        $chk = $pdo->query("SHOW TABLES LIKE 'orders'");
        $ordersTableOk = (bool)$chk->fetch();
    } catch (Throwable $e) { /* ignore */ }

    if ($ordersTableOk) {
        $orderStmt = $pdo->prepare("INSERT INTO orders (email, order_status, created_at) VALUES (?, 'PAID', NOW())");
        $orderStmt->execute([(string)$offer['customer_email']]);
        $orderId = (int)$pdo->lastInsertId();

        try {
            $itemStmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, product_name, quantity, size, price) VALUES (?, ?, ?, ?, '', ?)");
            $itemStmt->execute([
                $orderId,
                (int)$offer['product_id'],
                (string)$offer['product_name'],
                $quantity,
                (float)$offer['offered_price']
            ]);
        } catch (Throwable $e) {
            error_log('[OFFER_PAY_SUCCESS] order_items insert failed (orders row still created): ' . $e->getMessage());
        }
    }

    $pdo->prepare("UPDATE offers SET status = 'PAID', paid_at = NOW(), stripe_session_id = ? WHERE id = ?")
        ->execute([$sessionId, (int)$offer['id']]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[OFFER_PAY_SUCCESS] Failed to finalize paid offer #' . $offer['id'] . ': ' . $e->getMessage());
    ops_render('PAYMENT RECEIVED', 'Your payment went through, but we had trouble finalizing the order automatically. We have been notified and will follow up shortly.', false);
}

ops_render(
    'PAYMENT CONFIRMED',
    'Thank you! Your accepted offer for <strong>' . htmlspecialchars((string)$offer['product_name'], ENT_QUOTES, 'UTF-8') . '</strong> has been paid in full. A confirmation has been sent to your email.',
    false
);