<?php
declare(strict_types=1);

/**
 * offer_counter.php
 * Path: /home2/asqrtyte/public_html/offer_counter.php
 *
 * Customer-facing page for responding to a counter-offer. Reached via the
 * link emailed from admin/offers.php's "counter_offer" action.
 *
 * Accept -> generates a fresh pay_token, sets status=ACCEPTED with the
 *           countered price, and redirects into the EXISTING offer_pay.php
 *           payment flow (no changes needed there -- it already looks up
 *           by pay_token + status=ACCEPTED).
 * Decline -> marks the offer REJECTED with a note, no payment link created.
 */

$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => $https,
        'cookie_samesite' => 'Lax',
    ]);
}

require_once __DIR__ . '/db_connect.php';

if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
}

function oc_render_message(string $title, string $message, bool $isError = true): void {
    http_response_code($isError ? 400 : 200);
    ?>
    <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($title) ?> | DIAMONDS OUTTA DIRT</title>
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Cormorant+Garamond:wght@500;600&display=swap" rel="stylesheet">
    <style>
        *{box-sizing:border-box;} body{margin:0;background:#000;color:#eee;font-family:'Space Mono',monospace;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
        .box{max-width:440px;text-align:center;} h1{font-family:'Cormorant Garamond',serif;font-size:1.8rem;color:#fff;margin:0 0 12px;}
        p{color:#999;font-size:.82rem;line-height:1.6;} a{color:#00ff9d;text-decoration:none;}
    </style></head><body>
    <div class="box"><h1><?= h($title) ?></h1><p><?= h($message) ?></p><p><a href="/">Return home</a></p></div>
    </body></html>
    <?php
    exit;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    oc_render_message('UNAVAILABLE', 'This page cannot load right now. Please try again shortly.');
}

$token = preg_replace('/[^a-f0-9]/i', '', (string)($_GET['token'] ?? $_POST['token'] ?? ''));
if ($token === '' || strlen($token) < 32) {
    oc_render_message('INVALID LINK', 'This counter-offer link is invalid.');
}

try {
    $stmt = $pdo->prepare("SELECT * FROM offers WHERE counter_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $offer = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[OFFER_COUNTER] ' . $e->getMessage());
    oc_render_message('UNAVAILABLE', 'This page cannot load right now. Please try again shortly.');
    exit;
}

if (!$offer) {
    oc_render_message('INVALID LINK', 'This counter-offer link is invalid or has already been used.');
}

if ($offer['status'] !== 'COUNTERED') {
    if ($offer['status'] === 'ACCEPTED' || $offer['status'] === 'PAID') {
        oc_render_message('ALREADY HANDLED', 'You have already responded to this counter-offer.', false);
    }
    oc_render_message('NOT AVAILABLE', 'This counter-offer is no longer available.');
}

$counterExpiresAt = $offer['counter_expires_at'] ? strtotime((string)$offer['counter_expires_at']) : null;
if ($counterExpiresAt !== null && $counterExpiresAt < time()) {
    oc_render_message('EXPIRED', 'This counter-offer has expired. Feel free to submit a new offer on the Exchange.');
}

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf'];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $decision = (string)($_POST['decision'] ?? '');

        if ($decision === 'accept') {
            try {
                $payToken  = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+3 days'));

                $pdo->prepare("
                    UPDATE offers
                    SET status = 'ACCEPTED', offered_price = counter_price, pay_token = ?,
                        expires_at = ?, counter_responded_at = NOW()
                    WHERE id = ? AND status = 'COUNTERED'
                ")->execute([$payToken, $expiresAt, (int)$offer['id']]);

                header('Location: /offer_pay.php?token=' . urlencode($payToken));
                exit;
            } catch (Throwable $e) {
                error_log('[OFFER_COUNTER] ' . $e->getMessage());
                $error = 'Something went wrong. Please try again.';
            }
        } elseif ($decision === 'decline') {
            try {
                $pdo->prepare("
                    UPDATE offers
                    SET status = 'REJECTED', admin_note = 'Customer declined counter-offer',
                        counter_responded_at = NOW(), reviewed_at = NOW()
                    WHERE id = ? AND status = 'COUNTERED'
                ")->execute([(int)$offer['id']]);

                oc_render_message('DECLINED', 'No problem -- thanks for letting us know. Feel free to browse the Exchange for other items.', false);
            } catch (Throwable $e) {
                error_log('[OFFER_COUNTER] ' . $e->getMessage());
                $error = 'Something went wrong. Please try again.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>COUNTER OFFER | DIAMONDS OUTTA DIRT</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Cormorant+Garamond:wght@500;600&family=Syncopate:wght@700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;}
body{margin:0;background:#000;color:#eee;font-family:'Space Mono',monospace;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
.box{width:100%;max-width:440px;}
.logo{font-family:'Syncopate',sans-serif;font-size:.68rem;letter-spacing:4px;color:#00ff9d;text-decoration:none;display:block;margin-bottom:24px;text-align:center;}
h1{font-family:'Cormorant Garamond',serif;font-size:1.7rem;color:#fff;margin:0 0 8px;text-align:center;}
.sub{color:#888;font-size:.7rem;text-align:center;margin:0 0 24px;letter-spacing:.5px;}
.error{background:rgba(255,77,77,0.08);border:1px solid #ff4d4d;color:#ff4d4d;padding:10px 14px;border-radius:4px;font-size:.72rem;margin-bottom:16px;}
.price-compare{display:flex;justify-content:space-around;align-items:center;background:#0a0a0a;border:1px solid #222;border-radius:8px;padding:22px;margin-bottom:24px;}
.price-block{text-align:center;}
.price-label{font-size:.6rem;color:#888;letter-spacing:1px;margin-bottom:6px;}
.price-value{font-size:1.3rem;font-weight:700;}
.price-value.old{color:#666;text-decoration:line-through;}
.price-value.new{color:#ffaa00;}
.arrow{color:#444;font-size:1.2rem;}
.product-name{text-align:center;color:#fff;font-size:.85rem;margin-bottom:24px;letter-spacing:.5px;}
.actions{display:flex;gap:12px;}
.btn{flex:1;padding:14px;border-radius:4px;font-family:inherit;font-weight:700;font-size:.72rem;letter-spacing:1px;cursor:pointer;text-transform:uppercase;border:none;}
.btn-accept{background:#00ff9d;color:#000;}
.btn-accept:hover{filter:brightness(1.08);}
.btn-decline{background:none;border:1px solid #333;color:#999;}
.btn-decline:hover{border-color:#ff4d4d;color:#ff4d4d;}
.expiry-note{text-align:center;color:#666;font-size:.62rem;margin-top:18px;letter-spacing:.3px;}
</style>
</head>
<body>
<div class="box">
    <a href="/" class="logo">DIAMONDS OUTTA DIRT</a>
    <h1>We Countered Your Offer</h1>
    <p class="sub">REVIEW AND RESPOND BELOW</p>

    <?php if ($error !== ''): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

    <div class="product-name"><?= h((string)$offer['product_name']) ?></div>

    <div class="price-compare">
        <div class="price-block">
            <div class="price-label">YOUR OFFER</div>
            <div class="price-value old">$<?= number_format((float)$offer['offered_price'], 2) ?></div>
        </div>
        <div class="arrow">&rarr;</div>
        <div class="price-block">
            <div class="price-label">OUR COUNTER</div>
            <div class="price-value new">$<?= number_format((float)$offer['counter_price'], 2) ?></div>
        </div>
    </div>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="token" value="<?= h($token) ?>">
        <div class="actions">
            <button type="submit" name="decision" value="decline" class="btn btn-decline">DECLINE</button>
            <button type="submit" name="decision" value="accept" class="btn btn-accept">ACCEPT &amp; PAY</button>
        </div>
    </form>

    <p class="expiry-note">This counter-offer expires <?= h(date('M j, Y g:ia', $counterExpiresAt)) ?></p>
</div>
</body>
</html>