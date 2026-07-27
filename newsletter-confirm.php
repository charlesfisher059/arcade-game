<?php
declare(strict_types=1);

/**
 * newsletter-confirm.php
 * Path: /home2/asqrtyte/public_html/newsletter-confirm.php
 *
 * Handles the double opt-in confirmation link sent by
 * api/newsletter_subscribe.php. This file was referenced by that email
 * (/newsletter-confirm.php?t=...) but never existed -- meaning every
 * newsletter signup has been permanently stuck at status='unsubscribed'
 * since nobody's confirmation link ever went anywhere.
 *
 * Also reveals the first-order discount code as a thank-you for
 * confirming, tying into the welcome-offer signup prompt.
 */

require_once __DIR__ . '/db_connect.php';

if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
}

// The actual discount code -- must be created in your Stripe Dashboard
// (Product Catalog -> Coupons -> Promotion Codes) as a real promotion
// code, with "Limit to 1 redemption per customer" enabled so it
// genuinely only works once. This page just displays it; Stripe enforces
// the actual restriction at checkout via allow_promotion_codes.
const WELCOME_DISCOUNT_CODE = 'WELCOME10';

function render_page(string $title, string $message, bool $showCode = false): void {
    ?>
    <!doctype html>
    <html lang="en">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($title) ?> | DIAMONDS OUTTA DIRT</title>
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Cormorant+Garamond:wght@500;600&family=Syncopate:wght@700&display=swap" rel="stylesheet">
    <style>
        *{box-sizing:border-box;}
        body{margin:0;background:#000;color:#eee;font-family:'Space Mono',monospace;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
        .box{max-width:440px;text-align:center;}
        .logo{font-family:'Syncopate',sans-serif;font-size:.68rem;letter-spacing:4px;color:#00ff9d;margin-bottom:24px;}
        h1{font-family:'Cormorant Garamond',serif;font-size:1.8rem;color:#fff;margin:0 0 12px;}
        p{color:#999;font-size:.82rem;line-height:1.6;}
        a{color:#00ff9d;text-decoration:none;}
        .code-box{
            margin:22px 0;padding:18px;border:2px dashed #00ff9d;border-radius:8px;
            background:rgba(0,255,157,0.06);
        }
        .code-label{font-size:.62rem;color:#888;letter-spacing:2px;margin-bottom:8px;}
        .code-value{font-size:1.6rem;font-weight:700;color:#00ff9d;letter-spacing:3px;}
        .btn{display:inline-block;margin-top:20px;background:#00ff9d;color:#000;padding:13px 28px;border-radius:4px;font-weight:700;font-size:.72rem;letter-spacing:1.5px;text-transform:uppercase;text-decoration:none;}
    </style></head><body>
    <div class="box">
        <a href="/" class="logo">DIAMONDS OUTTA DIRT</a>
        <h1><?= h($title) ?></h1>
        <p><?= h($message) ?></p>
        <?php if ($showCode): ?>
        <div class="code-box">
            <div class="code-label">YOUR 10% OFF CODE</div>
            <div class="code-value"><?= h(WELCOME_DISCOUNT_CODE) ?></div>
        </div>
        <p>Enter this code at checkout on your first order.</p>
        <?php endif; ?>
        <a href="/shop" class="btn">Start Shopping</a>
    </div>
    </body></html>
    <?php
    exit;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    render_page('UNAVAILABLE', 'This page cannot load right now. Please try again shortly.');
}

$token = preg_replace('/[^a-f0-9]/i', '', (string)($_GET['t'] ?? ''));
if ($token === '' || strlen($token) < 32) {
    render_page('INVALID LINK', 'This confirmation link is invalid.');
}

try {
    $stmt = $pdo->prepare("SELECT id, email, status, confirmed_at FROM newsletter_subscribers WHERE confirm_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sub) {
        // Token not found -- either already used (cleared after first
        // confirm) or never existed. Since we clear the token on success,
        // treat "not found" as "probably already confirmed" rather than
        // a hard error, which is the more likely and friendlier case.
        render_page('ALREADY CONFIRMED', 'This subscription is already confirmed -- you\'re all set!', true);
    }

    if ($sub['status'] === 'active' && $sub['confirmed_at']) {
        render_page('ALREADY CONFIRMED', 'This subscription is already confirmed -- you\'re all set!', true);
    }

    $pdo->prepare("
        UPDATE newsletter_subscribers
        SET status = 'active', confirmed_at = NOW(), confirm_token = NULL
        WHERE id = ?
    ")->execute([(int)$sub['id']]);

    render_page('CONFIRMED', 'You\'re in! Thanks for confirming your subscription.', true);

} catch (Throwable $e) {
    error_log('[NEWSLETTER_CONFIRM] ' . $e->getMessage());
    render_page('UNAVAILABLE', 'This page cannot load right now. Please try again shortly.');
}