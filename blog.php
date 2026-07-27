<?php
declare(strict_types=1);

/**
 * blog.php
 * Path: /home2/asqrtyte/public_html/blog.php
 */

require_once __DIR__ . '/db_connect.php';

if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
}

$posts = [];
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $posts = $pdo->query("
            SELECT title, slug, excerpt, featured_image, published_at
            FROM blog_posts
            WHERE is_published = 1
            ORDER BY published_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[BLOG] ' . $e->getMessage());
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>JOURNAL | DIAMONDS OUTTA DIRT</title>
<?php if (is_file(__DIR__ . '/includes/tracking_pixels.php')) { require_once __DIR__ . '/includes/tracking_pixels.php'; } ?>
<meta name="description" content="Drops, stories, and behind-the-scenes from Diamonds Outta Dirt.">
<link rel="icon" href="/favicon.ico" sizes="any">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Cormorant+Garamond:wght@500;600;700&family=Syncopate:wght@700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;}
body{margin:0;background:#000;color:#eee;font-family:'Space Mono',monospace;}
.header{padding:20px 5%;border-bottom:1px solid #1a1a1a;display:flex;justify-content:space-between;align-items:center;}
.logo{font-family:'Syncopate',sans-serif;font-size:.72rem;letter-spacing:3px;color:#00ff9d;text-decoration:none;}
.nav a{color:#ccc;text-decoration:none;font-size:.7rem;letter-spacing:1px;margin-left:20px;}
.nav a:hover{color:#00ff9d;}
.wrap{max-width:840px;margin:0 auto;padding:50px 20px 100px;}
h1{font-family:'Cormorant Garamond',serif;font-size:2.4rem;color:#fff;margin:0 0 8px;text-align:center;}
.sub{text-align:center;color:#888;font-size:.72rem;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:50px;}
.post-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:28px;}
.post-card{text-decoration:none;color:inherit;border:1px solid #1a1a1a;border-radius:10px;overflow:hidden;background:#0a0a0a;transition:border-color .2s ease,transform .2s ease;}
.post-card:hover{border-color:#00ff9d;transform:translateY(-3px);}
.post-card img{width:100%;height:180px;object-fit:cover;display:block;background:#111;}
.post-card-body{padding:18px;}
.post-card-title{font-family:'Cormorant Garamond',serif;font-size:1.3rem;color:#fff;margin:0 0 8px;}
.post-card-excerpt{font-size:.72rem;color:#999;line-height:1.6;margin:0 0 10px;}
.post-card-date{font-size:.6rem;color:#666;letter-spacing:1px;text-transform:uppercase;}
.empty-note{text-align:center;color:#666;padding:60px 0;font-size:.8rem;}
</style>
</head>
<body>
<div class="header">
    <a href="/" class="logo">DIAMONDS OUTTA DIRT</a>
    <nav class="nav">
        <a href="/shop">Shop</a>
        <a href="/exchange">Exchange</a>
        <a href="/casting">Casting</a>
        <a href="/blog">Journal</a>
    </nav>
</div>

<div class="wrap">
    <h1>The Journal</h1>
    <p class="sub">Drops, stories, and behind the scenes</p>

    <?php if (empty($posts)): ?>
        <div class="empty-note">Nothing here yet -- check back soon.</div>
    <?php else: ?>
    <div class="post-grid">
        <?php foreach ($posts as $p): ?>
            <a class="post-card" href="/blog/<?= h(rawurlencode((string)$p['slug'])) ?>">
                <?php if (!empty($p['featured_image'])): ?>
                    <img src="<?= h((string)$p['featured_image']) ?>" alt="<?= h((string)$p['title']) ?>" loading="lazy">
                <?php endif; ?>
                <div class="post-card-body">
                    <div class="post-card-title"><?= h((string)$p['title']) ?></div>
                    <?php if (!empty($p['excerpt'])): ?>
                        <div class="post-card-excerpt"><?= h((string)$p['excerpt']) ?></div>
                    <?php endif; ?>
                    <div class="post-card-date"><?= h(date('M j, Y', strtotime((string)$p['published_at']))) ?></div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
</body>
</html>