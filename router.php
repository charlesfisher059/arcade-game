<?php
/**
 * router.php — Development Server Router
 * Handles URL rewriting for clean slugs (removes .php extensions)
 * Use: php -S localhost:8000 router.php
 *
 * Path: /home2/asqrtyte/public_html/router.php
 *
 * HARDENING PASS (structure preserved):
 * - Normalize URI safely (strip query, decode, remove traversal)
 * - Serve real files/dirs directly (including listed public dirs)
 * - Mirror .htaccess routing:
 *     /                 -> /index.php
 *     /shop             -> /shop.php
 *     /shop/{category}  -> /shop.php?category=...
 *     /services         -> /services.php (plus aliases)
 *     /product/{id}     -> /product.php?id=...
 *     /product/{slug}   -> /product.php?slug=...
 *     /{page}           -> /{page}.php (if exists)
 * - Keep admin/api/vendor/etc served directly (dev convenience)
 */

declare(strict_types=1);

// ------------------------------------------------------------
// 0) Read + normalize request path
// ------------------------------------------------------------
$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$rawPath = is_string($rawPath) ? $rawPath : '/';

// Decode, trim, and normalize
$decoded = rawurldecode($rawPath);
$decoded = str_replace("\0", '', $decoded);

// Remove repeated slashes and trim
$decoded = preg_replace('#/+#', '/', $decoded);
$uri = trim($decoded, '/');

// Prevent traversal attempts
if ($uri !== '' && (str_contains($uri, '../') || str_contains($uri, '..\\') || str_starts_with($uri, '../') || str_starts_with($uri, '..\\'))) {
    http_response_code(400);
    echo '<h1>400 Bad Request</h1>';
    echo '<p>Invalid path.</p>';
    return true;
}

// ------------------------------------------------------------
// 1) Public files/dirs allowed to be served directly
// ------------------------------------------------------------
$publicFiles = [
    'favicon.ico',
    'robots.txt',
    'sitemap.xml',
];

$publicDirs = [
    'images',
    'css',
    'js',
    'audio',
    'assets',
    'uploads',
    'vendor',
    'logs',
    '_data',
    'admin',
    'api',
    'fallback',
    'archive',
    'config',
    'data',
    'includes',
    'partials',
    '.well-known',
];

// If requesting a known public file, let built-in server handle it
if (in_array($uri, $publicFiles, true)) {
    return false;
}

// If request is within a public directory, let built-in server handle it
foreach ($publicDirs as $dir) {
    if ($uri === $dir || str_starts_with($uri, $dir . '/')) {
        return false;
    }
}

// ------------------------------------------------------------
// 2) If it's a real file/dir in docroot, serve it
// ------------------------------------------------------------
$path = __DIR__ . ($uri === '' ? '' : '/' . $uri);

// If it's a real file, serve it directly
if ($uri !== '' && is_file($path)) {
    return false;
}

// If it's a real directory, serve index.php if present, else default handling
if ($uri !== '' && is_dir($path)) {
    $index = rtrim($path, '/\\') . '/index.php';
    if (is_file($index)) {
        $_SERVER['REQUEST_URI'] = '/' . $uri . '/index.php';
        include $index;
        return true;
    }
    return false;
}

// ------------------------------------------------------------
// 3) Routing (mirror .htaccess intent)
// ------------------------------------------------------------
$segments = $uri === '' ? [] : array_values(array_filter(explode('/', $uri), static fn($s) => $s !== ''));

if (empty($segments)) {
    // Root request
    $_SERVER['REQUEST_URI'] = '/index.php';
    include __DIR__ . '/index.php';
    return true;
}

$first = $segments[0];
$second = $segments[1] ?? null;

// 3a) PRODUCT: /product/{id} OR /product/{slug}
if ($first === 'product' && $second !== null) {
    $param = (string)$second;

    // numeric id route
    if (ctype_digit($param)) {
        $_GET['id'] = $param;
        $_SERVER['REQUEST_URI'] = '/product.php?id=' . urlencode($param);
        include __DIR__ . '/product.php';
        return true;
    }

    // slug route
    $_GET['slug'] = $param;
    $_SERVER['REQUEST_URI'] = '/product.php?slug=' . urlencode($param);
    include __DIR__ . '/product.php';
    return true;
}

// 3b) SHOP: /shop OR /shop/{category}
if ($first === 'shop') {
    if ($second !== null) {
        $_GET['category'] = (string)$second;
        $_SERVER['REQUEST_URI'] = '/shop.php?category=' . urlencode((string)$second);
        include __DIR__ . '/shop.php';
        return true;
    }

    $_SERVER['REQUEST_URI'] = '/shop.php';
    include __DIR__ . '/shop.php';
    return true;
}

// 3c) SERVICES: /services plus aliases
if ($first === 'services' || $first === 'service-shop' || $first === 'service_shop') {
    $_SERVER['REQUEST_URI'] = '/services.php';
    include __DIR__ . '/services.php';
    return true;
}

// 3d) Clean URL -> PHP file: /about -> /about.php (only if file exists)
$phpFile = __DIR__ . '/' . $first . '.php';
if (is_file($phpFile)) {
    $_SERVER['REQUEST_URI'] = '/' . $first . '.php';

    // Optional: expose extra segments via $_GET['path'] if needed (non-breaking)
    if (count($segments) > 1) {
        // Keep simple, avoid turning segments into arbitrary query params
        $_GET['path'] = implode('/', array_slice($segments, 1));
        $_SERVER['REQUEST_URI'] .= '?path=' . urlencode($_GET['path']);
    }

    include $phpFile;
    return true;
}

// ------------------------------------------------------------
// 4) 404 - Not Found
// ------------------------------------------------------------
http_response_code(404);
if (is_file(__DIR__ . '/404.php')) {
    include __DIR__ . '/404.php';
} else {
    echo '<h1>404 Not Found</h1>';
    echo '<p>The requested resource "' . htmlspecialchars($uri, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" was not found.</p>';
}
return true;
