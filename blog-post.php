<?php
declare(strict_types=1);

/**
 * blog-post.php
 * Path: /home2/asqrtyte/public_html/blog-post.php
 * Served at /blog/{slug} via the .htaccess rewrite rule.
 */

require_once __DIR__ . '/db_connect.php';

if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
}

function bp_render_404(): void {
    http_response_code(404);
    ?>
    <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Post Not Found | DIAMONDS OUTTA DIRT</title>
    <style>body{margin:0;background:#000;color:#eee;font-family:monospace;display:flex;min-height:100vh;align-items:center;justify-content:center;}
    .box{text-align:center;} a{color:#00ff9d;}</style></head><body>
    <div class="box"><h1>Post not found</h1><p><a href="/blog">Back to the Journal</a></p></div>
    </body></html>
    <?php
    exit;
}

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '' || !isset($pdo) || !($pdo instanceof PDO)) {
    bp_render_404();
}

try {
    $stmt = $pdo->prepare("SELECT * FROM blog_posts WHERE slug = ? AND is_published = 1 LIMIT 1");
    $stmt->execute([$slug]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[BLOG_POST] ' . $e->getMessage());
    $post = null;
}

if (!$post) {
    bp_render_404();
}

$host = preg_replace('/[^a-zA-Z0-9\.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'diamondsouttadirt.com'));
$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
$canonical = ($https ? 'https' : 'http') . '://' . $host . '/blog/' . rawurlencode((string)$post['slug']);
$metaDesc = (string)($post['excerpt'] ?? '');
if ($metaDesc === '') {
    $metaDesc = trim(substr(strip_tags((string)$post['content']), 0, 160));
}

$articleSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => (string)$post['title'],
    'datePublished' => date('c', strtotime((string)$post['published_at'])),
    'author' => ['@type' => 'Organization', 'name' => 'Diamonds Outta Dirt'],
    'publisher' => ['@type' => 'Organization', 'name' => 'Diamonds Outta Dirt'],
    'mainEntityOfPage' => $canonical,
];
if (!empty($post['featured_image'])) {
    $articleSchema['image'] = [$post['featured_image']];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h((string)$post['title']) ?> | DIAMONDS OUTTA DIRT</title>
<?php if (is_file(__DIR__ . '/includes/tracking_pixels.php')) { require_once __DIR__ . '/includes/tracking_pixels.php'; } ?>
<meta name="description" content="<?= h($metaDesc) ?>">
<link rel="canonical" href="<?= h($canonical) ?>">
<meta property="og:title" content="<?= h((string)$post['title']) ?>">
<meta property="og:description" content="<?= h($metaDesc) ?>">
<meta property="og:type" content="article">
<meta property="og:url" content="<?= h($canonical) ?>">
<?php if (!empty($post['featured_image'])): ?><meta property="og:image" content="<?= h((string)$post['featured_image']) ?>"><?php endif; ?>
<script type="application/ld+json"><?= json_encode($articleSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<link rel="icon" href="/favicon.ico" sizes="any">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Cormorant+Garamond:wght@500;600;700&family=Syncopate:wght@700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;}
body{margin:0;background:#000;color:#eee;font-family:'Space Mono',monospace;}
.header{padding:20px 5%;border-bottom:1px solid #1a1a1a;display:flex;justify-content:space-between;align-items:center;}
.logo{font-family:'Syncopate',sans-serif;font-size:.72rem;letter-spacing:3px;color:#00ff9d;text-decoration:none;}
.nav a{color:#ccc;text-decoration:none;font-size:.7rem;letter-spacing:1px;margin-left:20px;}
.nav a:hover{color:#00ff9d;}
.wrap{max-width:720px;margin:0 auto;padding:50px 20px 100px;}
.back{font-size:.68rem;color:#00ff9d;text-decoration:none;display:inline-block;margin-bottom:24px;}
.post-date{font-size:.62rem;color:#666;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:14px;}
h1{font-family:'Cormorant Garamond',serif;font-size:2.2rem;color:#fff;margin:0 0 24px;line-height:1.2;}
.featured-img{width:100%;border-radius:10px;margin-bottom:30px;}
.post-content{font-size:.88rem;line-height:1.85;color:#ddd;}
.post-content p{margin:0 0 20px;}
.post-content h2{font-family:'Cormorant Garamond',serif;font-size:1.5rem;color:#fff;margin:32px 0 14px;}
.post-content h3{font-family:'Cormorant Garamond',serif;font-size:1.2rem;color:#fff;margin:26px 0 12px;}
.post-content a{color:#00ff9d;}
.post-content img{max-width:100%;border-radius:8px;margin:20px 0;}
.post-content ul,.post-content ol{margin:0 0 20px;padding-left:24px;}
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
    <a href="/blog" class="back">&larr; Back to the Journal</a>
    <div class="post-date"><?= h(date('F j, Y', strtotime((string)$post['published_at']))) ?></div>
    <h1><?= h((string)$post['title']) ?></h1>
    <?php if (!empty($post['featured_image'])): ?>
        <img class="featured-img" src="<?= h((string)$post['featured_image']) ?>" alt="<?= h((string)$post['title']) ?>">
    <?php endif; ?>
    <div class="post-content"><?= $post['content'] /* admin-authored HTML, intentionally not escaped */ ?></div>
</div>
</body>
</html>