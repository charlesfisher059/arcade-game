<?php
declare(strict_types=1);

/**
 * offer_pay.php -- Path: /home2/asqrtyte/public_html/offer_pay.php
 *
 * Landing page for the secure link emailed to a customer once you accept
 * their offer. Validates the token, then creates a one-off Stripe Checkout
 * session for exactly the accepted price x quantity and redirects to it.
 *
 * Mirrors the Stripe integration pattern already used in
 * create_checkout_session.php (same SDK load path, same key resolution).
 */

session_start();

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

require_once __DIR__ . '/db_connect.php';

function op_render_message(string $title, string $message, bool $isError = true): void {
    header('Content-Type: text/html; charset=UTF-8');
    $color = $isError ? '#ff4d4d' : '#00ff9d';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' | DIAMONDS OUTTA DIRT</title>';
    echo '<style>body{margin:0;background:#000;color:#fff;font-family:ui-monospace,Menlo,Consolas,monospace;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px} .box{max-width:560px;border:1px solid #333;background:#050505;padding:28px;border-radius:10px;text-align:center} .h{color:' . $color . ';letter-spacing:2px;font-weight:800;margin:0 0 12px;font-size:1.1rem} .p{color:#bbb;line-height:1.6;margin:0 0 10px} a{color:#00ff9d}</style></head><body><div class="box"><div class="h">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div><p class="p">' . $message . '</p><p class="p"><a href="/exchange">Return to the Exchange</a></p></div></body></html>';
    exit;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    op_render_message('UNAVAILABLE', 'This page cannot load right now. Please try again shortly.');
}

$token = (string)($_GET['token'] ?? '');
$tokenOk = ($token !== '') && (strlen($token) >= 32) && (strlen($token) <= 64) && ctype_alnum($token);
if (!$tokenOk) {
    op_render_message('INVALID LINK', 'This payment link is invalid.');
}

try {
    $stmt = $pdo->prepare("SELECT * FROM offers WHERE pay_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $offer = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[OFFER_PAY] ' . $e->getMessage());
    op_render_message('UNAVAILABLE', 'This page cannot load right now. Please try again shortly.');
    exit;
}

if (!$offer) {
    op_render_message('INVALID LINK', 'This payment link is invalid or has already been used.');
}

if ($offer['status'] === 'PAID') {
    op_render_message('ALREADY PAID', 'This offer has already been paid for. Thank you.', false);
}

if ($offer['status'] !== 'ACCEPTED') {
    op_render_message('NOT AVAILABLE', 'This offer is no longer available for payment.');
}

$expiresAt = $offer['expires_at'] ? strtotime((string)$offer['expires_at']) : null;
if ($expiresAt !== null && $expiresAt < time()) {
    try {
        $pdo->prepare("UPDATE offers SET status = 'EXPIRED' WHERE id = ?")->execute([(int)$offer['id']]);
    } catch (Throwable $e) { /* best effort */ }
    op_render_message('OFFER EXPIRED', 'This accepted offer has expired. Please reach out if you would still like to purchase the item.');
}

/* ---- Re-check stock at the moment of payment (could have sold out via normal checkout since acceptance) ---- */
try {
    $pStmt = $pdo->prepare("SELECT id, name, price, stock, image_url FROM products WHERE id = ? LIMIT 1");
    $pStmt->execute([(int)$offer['product_id']]);
    $product = $pStmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $product = null;
}

if (!$product || (int)$product['stock'] < (int)$offer['quantity']) {
    op_render_message('SOLD OUT', 'Sorry, this item no longer has enough stock to fulfill your accepted offer. Please contact us.');
}

/* ---- Stripe SDK load (same pattern as create_checkout_session.php) ---- */
$stripeLoaded = false;
try {
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
        $stripeLoaded = class_exists('Stripe\\Stripe');
    }
} catch (Throwable $e) {
    error_log('Stripe autoload error: ' . $e->getMessage());
}

if (!$stripeLoaded || !class_exists('Stripe\\Stripe')) {
    op_render_message('UNAVAILABLE', 'Payment processing is temporarily unavailable. Please try again shortly or contact us.');
}

$stripeSecretKey = getenv('STRIPE_SECRET_KEY') ?: '';
if (empty($stripeSecretKey) && file_exists('/home2/asqrtyte/config/stripe.php')) {
    try {
        $configContent = file_get_contents('/home2/asqrtyte/config/stripe.php');
        if ($configContent !== false) {
            $found = ex_extract_stripe_key($configContent);
            if ($found !== '') {
                $stripeSecretKey = $found;
            }
        }
    } catch (Throwable $e) {
        error_log('Failed to load stripe config: ' . $e->getMessage());
    }
}

if (empty($stripeSecretKey)) {
    op_render_message('UNAVAILABLE', 'Payment processing is temporarily unavailable. Please try again shortly or contact us.');
}

Stripe\Stripe::setApiKey($stripeSecretKey);

try {
    $unitAmountCents = (int)round((float)$offer['offered_price'] * 100);
    $quantity = (int)$offer['quantity'];

    if ($unitAmountCents <= 0 || $quantity <= 0) {
        throw new Exception('Invalid offer amount');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'diamondsouttadirt.com');

    $imageUrl = '';
    $rawImage = (string)($product['image_url'] ?? '');
    $isAbsolute = (strpos($rawImage, 'http://') === 0) || (strpos($rawImage, 'https://') === 0);
    if ($rawImage !== '' && $isAbsolute) {
        $imageUrl = $rawImage;
    } elseif ($rawImage !== '') {
        $imageUrl = $scheme . '://' . $host . '/' . ltrim($rawImage, '/');
    }

    $checkout_session = Stripe\Checkout\Session::create([
        'payment_method_types' => ['card'],
        'line_items' => [[
            'price_data' => [
                'currency' => 'usd',
                'product_data' => [
                    'name' => (string)$offer['product_name'] . ' (Accepted Offer)',
                    'images' => $imageUrl !== '' ? [$imageUrl] : [],
                ],
                'unit_amount' => $unitAmountCents,
            ],
            'quantity' => $quantity,
        ]],
        'mode' => 'payment',
        'success_url' => $scheme . '://' . $host . '/offer_pay_success.php?session_id={CHECKOUT_SESSION_ID}&token=' . urlencode($token),
        'cancel_url' => $scheme . '://' . $host . '/offer_pay.php?token=' . urlencode($token),
        'customer_email' => (string)$offer['customer_email'],
        'shipping_address_collection' => [
            'allowed_countries' => ['US'],
        ],
        'metadata' => [
            'offer_id' => (string)$offer['id'],
            'offer_token' => $token,
        ]
    ]);

    try {
        $pdo->prepare("UPDATE offers SET stripe_session_id = ? WHERE id = ?")->execute([$checkout_session->id, (int)$offer['id']]);
    } catch (Throwable $e) { /* non-fatal */ }

    header('Location: ' . $checkout_session->url);
    exit;
} catch (Throwable $e) {
    error_log('[OFFER_PAY] Stripe session failed: ' . $e->getMessage());
    op_render_message('UNAVAILABLE', 'We could not start checkout for this offer. Please try again shortly or contact us.');
}

function ex_extract_stripe_key(string $configContent): string {
    $needle = "setApiKey(";
    $pos = strpos($configContent, $needle);
    if ($pos === false) return '';
    $start = $pos + strlen($needle);
    $quoteChar = $configContent[$start] ?? '';
    if ($quoteChar !== "'" && $quoteChar !== '"') return '';
    $end = strpos($configContent, $quoteChar, $start + 1);
    if ($end === false) return '';
    return substr($configContent, $start + 1, $end - $start - 1);
}