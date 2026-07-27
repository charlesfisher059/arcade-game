<?php
declare(strict_types=1);

/**
 * sitemap.php
 * Path: /home2/asqrtyte/public_html/sitemap.php
 * Served at /sitemap.xml via the .htaccess rewrite added alongside this file.
 *
 * Dynamically generated so it's never stale -- every product currently in
 * the database is included automatically, with no manual maintenance.
 */

header('Content-Type: application/xml; charset=UTF-8');

require_once __DIR__ . '/db_connect.php';

$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
$host = preg_replace('/[^a-zA-Z0-9\.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'diamondsouttadirt.com'));
$base = ($https ? 'https' : 'http') . '://' . $host;

function sm_url(string $loc, string $changefreq, string $priority, ?string $lastmod = null): string {
    $xml = "  <url>\n";
    $xml .= "    <loc>" . htmlspecialchars($loc, ENT_QUOTES | ENT_XML1, 'UTF-8') . "</loc>\n";
    if ($lastmod !== null) {
        $xml .= "    <lastmod>" . htmlspecialchars($lastmod) . "</lastmod>\n";
    }
    $xml .= "    <changefreq>{$changefreq}</changefreq>\n";
    $xml .= "    <priority>{$priority}</priority>\n";
    $xml .= "  </url>\n";
    return $xml;
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

// ---- Static, high-value pages ----
echo sm_url($base . '/', 'daily', '1.0');
echo sm_url($base . '/shop', 'daily', '0.9');
echo sm_url($base . '/exchange', 'hourly', '0.9');
echo sm_url($base . '/lookbook', 'weekly', '0.6');
echo sm_url($base . '/about', 'monthly', '0.5');
echo sm_url($base . '/services', 'monthly', '0.5');

// ---- Products (dynamic, always current) ----
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $slugExists = false;
        try {
            $chk = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'slug' LIMIT 1");
            $slugExists = (bool)$chk->fetch();
        } catch (Throwable $e) { /* ignore, fall back to id-based URLs */ }

        $slugSelect = $slugExists ? 'slug' : 'NULL AS slug';
        $stmt = $pdo->query("SELECT id, $slugSelect, updated_at, created_at FROM products ORDER BY id DESC");
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($products as $p) {
            $pid = (int)$p['id'];
            $slug = trim((string)($p['slug'] ?? ''));
            $loc = $slug !== '' ? ($base . '/product/' . rawurlencode($slug)) : ($base . '/product/' . $pid);

            $lastmodRaw = $p['updated_at'] ?? $p['created_at'] ?? null;
            $lastmod = null;
            if ($lastmodRaw) {
                $ts = strtotime((string)$lastmodRaw);
                if ($ts !== false) $lastmod = date('Y-m-d', $ts);
            }

            echo sm_url($loc, 'weekly', '0.7', $lastmod);
        }
    } catch (Throwable $e) {
        error_log('[SITEMAP] ' . $e->getMessage());
        // Fail gracefully -- still output a valid sitemap with just the static pages above
    }

    // ---- Blog posts (dynamic) ----
    try {
        $blogStmt = $pdo->query("SELECT slug, published_at FROM blog_posts WHERE is_published = 1 ORDER BY published_at DESC");
        $blogPosts = $blogStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($blogPosts as $bp) {
            $bpSlug = trim((string)($bp['slug'] ?? ''));
            if ($bpSlug === '') continue;
            $bpLastmod = null;
            $bpTs = strtotime((string)($bp['published_at'] ?? ''));
            if ($bpTs !== false) $bpLastmod = date('Y-m-d', $bpTs);
            echo sm_url($base . '/blog/' . rawurlencode($bpSlug), 'monthly', '0.5', $bpLastmod);
        }
        if (!empty($blogPosts)) {
            echo sm_url($base . '/blog', 'weekly', '0.6');
        }
    } catch (Throwable $e) {
        error_log('[SITEMAP_BLOG] ' . $e->getMessage());
    }
}

echo '</urlset>';