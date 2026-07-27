<?php
// order_confirmation.php — ORDER CONFIRMATION HUD (v1.4 HARDENED + CLEAN URL SAFE)
// Path: /home2/asqrtyte/public_html/order_confirmation.php

declare(strict_types=1);

// -----------------------------
// SECURITY HEADERS (best-effort)
// -----------------------------
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
    // Keep CSP permissive enough for inline <style>/<script> in this self-contained page.
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; script-src 'self' 'unsafe-inline'; base-uri 'self'; form-action 'self'; frame-ancestors 'self';");
}

// -----------------------------
// HELPERS
// -----------------------------
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// -----------------------------
// INPUT SANITIZATION
// -----------------------------
$order_id = null;

// Accept ?order_id=123 or ?order_id=#123 (just in case)
if (isset($_GET['order_id'])) {
    $raw = trim((string)$_GET['order_id']);
    $raw = ltrim($raw, "# \t\n\r\0\x0B");

    if ($raw !== '' && preg_match('/^\d{1,10}$/', $raw)) {
        $order_id = (int)$raw;
    }
}

// Optional message (future-proof). Only allow a small whitelist.
$status = isset($_GET['status']) ? strtolower(trim((string)$_GET['status'])) : 'ok';
$allowedStatus = ['ok', 'success', 'paid'];
if (!in_array($status, $allowedStatus, true)) $status = 'ok';

// Prefer clean URL return (/shop) but don't break legacy shop.php
$shopReturnHref = '/shop';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ORDER SECURED | DOD_ARCHIVE</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Global Styles -->
    <link rel="stylesheet" href="css/master.css">

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --accent: #6bff9f;
            --glitch-red: #ff3e3e;
        }

        body {
            background: #000;
            color: #fff;
            font-family: 'Space Mono', monospace;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            overflow: hidden;
        }

        .confirmation-box {
            max-width: 520px;
            padding: 60px 40px;
            border: 1px solid #222;
            background: rgba(5,5,5,0.85);
            backdrop-filter: blur(12px);
            box-shadow: 0 0 30px rgba(107,255,159,0.12);
        }

        h1 {
            font-family: 'Inter', sans-serif;
            font-size: 2.2rem;
            letter-spacing: 4px;
            color: var(--accent);
            margin-bottom: 25px;
        }

        .order-line {
            font-size: 0.85rem;
            color: #bbb;
            letter-spacing: 1px;
            line-height: 1.7;
            margin-bottom: 35px;
        }

        .order-id {
            color: var(--accent);
            font-weight: 700;
        }

        .btn-cta {
            display: inline-block;
            padding: 16px 36px;
            border: 1px solid var(--accent);
            color: var(--accent);
            text-decoration: none;
            font-size: 0.7rem;
            letter-spacing: 3px;
            text-transform: uppercase;
            transition: all 0.3s ease;
        }

        .btn-cta:hover,
        .btn-cta:focus-visible {
            background: var(--accent);
            color: #000;
            box-shadow: 0 0 25px rgba(107,255,159,0.5);
            outline: none;
        }

        @media (max-width: 600px) {
            .confirmation-box {
                padding: 45px 22px;
                margin: 20px;
            }

            h1 {
                font-size: 1.8rem;
            }
        }
    </style>
<script src="/js/telemetry.js" defer></script>
</head>
<body>
<?php if ($order_id !== null): ?>
<script>
window.addEventListener('DOMContentLoaded', function () {
    // Wait for telemetry.js to define dodTrack (deferred load)
    var tries = 0;
    var iv = setInterval(function () {
        tries++;
        if (typeof window.dodTrack === 'function') {
            window.dodTrack('purchase', { id: <?= (int)$order_id ?> });
            clearInterval(iv);
        } else if (tries > 20) {
            clearInterval(iv);
        }
    }, 100);
});
</script>
<?php endif; ?>

<div class="confirmation-box">
    <div class="status-label" style="margin-bottom: 20px; color: var(--accent); font-size: 0.65rem; letter-spacing: 3px;">
        [ TRANSMISSION_CONFIRMED ]
    </div>

    <h1>ORDER SECURED</h1>

    <p class="order-line">
        Your order
        <span class="order-id">
            <?= $order_id !== null ? '#' . h((string)$order_id) : 'HAS BEEN RECEIVED' ?>
        </span>
        has been successfully logged in the archive.<br>
        Confirmation will arrive through your communication channel shortly.
    </p>

    <a href="<?= h($shopReturnHref) ?>" class="btn-cta">RETURN TO BASE</a>
</div>

<script>
    // PURGE CLIENT-SIDE BAG CACHE AFTER CONFIRMATION
    try {
        localStorage.removeItem('dod_cart');
    } catch (e) {}

    // Optional: purge any old cart keys you might have used historically
    try {
        localStorage.removeItem('cart');
        localStorage.removeItem('cart_items');
    } catch (e) {}
</script>

</body>
</html>