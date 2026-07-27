<?php
declare(strict_types=1);

/**
 * wishlist.php — Diamonds Outta Dirt
 * Path: /home2/asqrtyte/public_html/wishlist.php
 *
 * PATCH v2 (full rewrite):
 * - Uses the real customer account system (account/auth.php, customer_id)
 *   instead of the old nonexistent user_id session key
 * - Reads products.image_url (correct column name) instead of products.image
 * - Redirects unauthenticated visitors to /account/login instead of a
 *   dead /login.php route
 * - CSRF-protected remove action
 * - Brand-matched styling (Space Mono, neon green) instead of the
 *   unrelated magenta placeholder styling
 */

require_once __DIR__ . '/account/auth.php';
account_require_login('/account/login');

if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
}

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf'];
$cid = (int)$_SESSION['customer_id'];

$flash = '';

// Handle remove action (also supports POST from the wishlist_toggle API redirect)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove') {
    if (hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $productId = (int)($_POST['product_id'] ?? 0);
        if ($productId > 0 && isset($pdo) && $pdo instanceof PDO) {
            try {
                $pdo->prepare("DELETE FROM wishlists WHERE customer_id = ? AND product_id = ?")
                    ->execute([$cid, $productId]);
                $flash = 'Removed from wishlist.';
            } catch (Throwable $e) {
                error_log('[WISHLIST] ' . $e->getMessage());
            }
        }
    }
}

$items = [];
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $stmt = $pdo->prepare("
            SELECT w.product_id, p.name, p.price, p.image_url, p.stock, p.slug
            FROM wishlists w
            JOIN products p ON p.id = w.product_id
            WHERE w.customer_id = ?
            ORDER BY w.created_at DESC
        ");
        $stmt->execute([$cid]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[WISHLIST] ' . $e->getMessage());
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>WISHLIST | DIAMONDS OUTTA DIRT</title>
<link rel="stylesheet" href="/css/master.css">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Cormorant+Garamond:wght@500;600&family=Syncopate:wght@700;900&display=swap" rel="stylesheet">
<script src="/js/telemetry.js" defer></script>
<style>
*{ box-sizing:border-box; }
body{ margin:0; background:#000; color:#eee; font-family:'Space Mono',monospace; min-height:100vh; }
.wl-wrap{ max-width:1080px; margin:0 auto; padding:32px 20px 100px; }
.wl-topbar{ display:flex; justify-content:space-between; align-items:center; margin-bottom:28px; flex-wrap:wrap; gap:12px; }
.wl-logo{ font-family:'Syncopate',sans-serif; font-size:.72rem; letter-spacing:4px; color:#00ff9d; text-decoration:none; }
.wl-toplinks{ display:flex; gap:16px; font-size:.65rem; }
.wl-toplinks a{ color:#888; text-decoration:none; }
.wl-toplinks a:hover{ color:#00ff9d; }
.wl-title{ font-family:'Cormorant Garamond',serif; font-size:2rem; color:#fff; margin:0 0 4px; }
.wl-sub{ font-size:.68rem; color:#888; margin:0 0 24px; }
.flash{ padding:11px 14px; border-radius:4px; margin-bottom:18px; font-size:.74rem; background:rgba(0,255,157,0.08); border:1px solid #00ff9d; color:#00ff9d; }
.empty-note{ color:#666; font-size:.8rem; padding:60px 0; text-align:center; }
.empty-note a{ color:#00ff9d; text-decoration:none; }
.wl-grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:18px; }
.wl-card{ background:#0a0a0a; border:1px solid #222; border-radius:6px; overflow:hidden; transition:border-color .2s ease; }
.wl-card:hover{ border-color:#00ff9d; }
.wl-card-link{ text-decoration:none; color:inherit; display:block; }
.wl-card img{ width:100%; height:200px; object-fit:cover; display:block; background:#050505; }
.wl-card-info{ padding:12px; }
.wl-card-name{ font-size:.72rem; color:#fff; letter-spacing:.5px; margin-bottom:6px; line-height:1.4; text-transform:uppercase; }
.wl-card-price{ font-size:.8rem; color:#00ff9d; font-weight:700; }
.wl-card-stock{ font-size:.6rem; margin-top:4px; letter-spacing:.5px; }
.wl-card-stock.out{ color:#ff4d4d; }
.wl-card-stock.low{ color:#ffaa00; }
.wl-remove-form{ padding:0 12px 12px; }
.wl-remove-btn{ width:100%; background:none; border:1px solid #2a2a2a; color:#888; padding:8px; border-radius:4px; font-family:inherit; font-size:.62rem; letter-spacing:1px; cursor:pointer; text-transform:uppercase; }
.wl-remove-btn:hover{ border-color:#ff4d4d; color:#ff4d4d; }
</style>
</head>
<body>
<div class="wl-wrap">
    <div class="wl-topbar">
        <a href="/" class="wl-logo">DIAMONDS OUTTA DIRT</a>
        <div class="wl-toplinks">
            <a href="/shop">Shop</a>
            <a href="/exchange">Exchange</a>
            <a href="/account">My Account</a>
            <a href="/cart">Bag</a>
        </div>
    </div>

    <h1 class="wl-title">Your Wishlist</h1>
    <p class="wl-sub"><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?> saved</p>

    <?php if ($flash !== ''): ?>
        <div class="flash"><?= h($flash) ?></div>
    <?php endif; ?>

    <?php if (empty($items)): ?>
        <div class="empty-note">
            Your wishlist is empty.<br><br>
            <a href="/shop">Browse the archive</a> to start saving favorites.
        </div>
    <?php else: ?>
        <div class="wl-grid">
            <?php foreach ($items as $item):
                $pid = (int)$item['product_id'];
                $slug = trim((string)($item['slug'] ?? ''));
                $href = $slug !== '' ? '/product/' . rawurlencode($slug) : '/product/' . $pid;
                $stock = (int)($item['stock'] ?? 0);
                $img = (string)($item['image_url'] ?? '');
                if ($img === '') $img = '/images/placeholder.jpg';
                elseif (!preg_match('#^https?://#i', $img)) $img = '/' . ltrim($img, '/');
            ?>
            <div class="wl-card">
                <a href="<?= h($href) ?>" class="wl-card-link">
                    <img src="<?= h($img) ?>" alt="<?= h((string)$item['name']) ?>" loading="lazy" onerror="this.src='/images/placeholder.jpg'">
                    <div class="wl-card-info">
                        <div class="wl-card-name"><?= h((string)$item['name']) ?></div>
                        <div class="wl-card-price">$<?= number_format((float)$item['price'], 2) ?></div>
                        <?php if ($stock <= 0): ?>
                            <div class="wl-card-stock out">OUT OF STOCK</div>
                        <?php elseif ($stock <= 5): ?>
                            <div class="wl-card-stock low"><?= $stock ?> LEFT</div>
                        <?php endif; ?>
                    </div>
                </a>
                <form method="post" class="wl-remove-form">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="product_id" value="<?= $pid ?>">
                    <button type="submit" class="wl-remove-btn">REMOVE</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>