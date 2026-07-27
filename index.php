<?php
declare(strict_types=1);

// -----------------------------------------------------------------------------
// index.php — Diamonds Outta Dirt (ARCHIVE / STOREFRONT v4.4)
// NOTE: Full file output as requested. UI preserved.
// Fixes applied (no UI changes):
// - Define $https safely before any header() usage and before $scheme usage
// - Only send security headers when headers are not already sent
// - HSTS only when HTTPS is actually on
// - Remove accidental duplicate category query block
// - Fix category filtering correctness (use UPPER(category) to match normalized category inputs)
// -----------------------------------------------------------------------------

require_once __DIR__ . '/includes/cache.php';

// Helper: get WebP version of an image if it exists
function getWebpIfExists(string $imagePath): string {
    $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));

    // Only attempt WebP variant for common raster formats
    if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        return $imagePath;
    }

    $webpPath = preg_replace('/\.(jpe?g|png)$/i', '.webp', $imagePath);
    if (!$webpPath) {
        return $imagePath;
    }

    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $publicPath = '/' . ltrim($webpPath, '/');

    if ($docRoot !== '' && is_file($docRoot . $publicPath)) {
        return $publicPath;
    }

    return $imagePath;
}

// -----------------------------------------------------------------------------
// HTTPS DETECTION (MUST EXIST BEFORE headers + $scheme usage)
// -----------------------------------------------------------------------------
$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');

// Ensure session exists for fingerprint/CSRF
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

// Session fingerprint (helps detect stolen sessions)
if (empty($_SESSION['session_fingerprint'])) {
    $_SESSION['session_fingerprint'] = hash(
        'sha256',
        (string)($_SERVER['HTTP_USER_AGENT'] ?? '') .
        (string)($_SERVER['REMOTE_ADDR'] ?? '') .
        session_id()
    );
    $_SESSION['session_created'] = time();
}

// CSRF token (NOTE: use for POST forms, not in product links)
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Enhanced security headers (only if headers are not already sent)
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; frame-ancestors 'self'; object-src 'none'; script-src 'self' 'unsafe-inline' https://www.googletagmanager.com https://connect.facebook.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: https:; media-src 'self' https:; connect-src 'self' https://www.google-analytics.com https://region1.google-analytics.com https://www.facebook.com");
    if ($https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/* ============================================================================
   1. CONFIGURATION & DATABASE
   ============================================================================ */

define('PRODUCTS_PER_PAGE', 8);
define('CURRENCY', 'USD');
define('FIELD_REPORTS_LIMIT', 25);
define('SITE_NAME', 'DIAMONDS OUTTA DIRT');
define('SITE_TAGLINE', 'TRANSFORMING PRESSURE INTO CLARITY');
define('VERSION', 'v4.4');
define('CATEGORY_META_CACHE_TTL', 3600);     // 1h
define('FIELD_REPORTS_CACHE_TTL', 1800);     // 30m
define('SCHEMA_META_CACHE_TTL', 86400);      // 24h
define('DB_INDEX_CHECK_TTL', 86400);         // 24h
define('CACHE_HOOK_DIR', __DIR__ . '/_data/cache-hooks');
define('CATALOG_INVALIDATION_FILE', CACHE_HOOK_DIR . '/catalog.bump');
define('FIELD_REPORTS_INVALIDATION_FILE', CACHE_HOOK_DIR . '/field_reports.bump');
define('SCHEMA_INVALIDATION_FILE', CACHE_HOOK_DIR . '/schema.bump');

function cache_hook_token(string $file): int {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_file($file)) {
        @file_put_contents($file, (string)time());
    }
    $mtime = @filemtime($file);
    return $mtime === false ? 0 : (int)$mtime;
}

function cache_hook_bump(string $file): void {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_file($file)) {
        @file_put_contents($file, (string)time());
    }
    @touch($file);
}

function get_schema_capabilities(PDO $pdo, int $schemaToken): array {
    $cacheKey = 'index_schema_caps_v2_' . $schemaToken;
    $cached = cache_get($cacheKey, SCHEMA_META_CACHE_TTL);
    if (is_array($cached)) {
        return $cached;
    }

    $caps = [
        'products_has_featured' => false,
        'field_reports_table_exists' => false,
        'field_reports_has_is_hidden' => false,
    ];

    try {
        $productsFeaturedSql = "
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'products'
              AND COLUMN_NAME = 'featured'
            LIMIT 1
        ";
        $caps['products_has_featured'] = (bool)$pdo->query($productsFeaturedSql)->fetchColumn();

        $fieldReportsTableSql = "
            SELECT 1
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'field_reports'
            LIMIT 1
        ";
        $caps['field_reports_table_exists'] = (bool)$pdo->query($fieldReportsTableSql)->fetchColumn();

        if ($caps['field_reports_table_exists']) {
            $fieldReportsHiddenSql = "
                SELECT 1
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'field_reports'
                  AND COLUMN_NAME = 'is_hidden'
                LIMIT 1
            ";
            $caps['field_reports_has_is_hidden'] = (bool)$pdo->query($fieldReportsHiddenSql)->fetchColumn();
        }
    } catch (Throwable $e) {
        error_log('Schema capability check failed: ' . $e->getMessage());
    }

    cache_set($cacheKey, $caps);
    return $caps;
}

function ensure_products_indexes(PDO $pdo, int $schemaToken): void {
    $guardKey = 'index_products_index_check_v1_' . $schemaToken;
    if (cache_get($guardKey, DB_INDEX_CHECK_TTL) !== false) {
        return;
    }

    try {
        $sql = "
            SELECT INDEX_NAME
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'products'
        ";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $indexes = array_fill_keys(array_map('strval', $rows), true);

        $ddl = [];
        if (!isset($indexes['idx_products_stock_created_at'])) {
            $ddl[] = "CREATE INDEX idx_products_stock_created_at ON products (stock, created_at)";
        }
        if (!isset($indexes['idx_products_category'])) {
            $ddl[] = "CREATE INDEX idx_products_category ON products (category)";
        }
        if (!isset($indexes['idx_products_slug'])) {
            $ddl[] = "CREATE INDEX idx_products_slug ON products (slug)";
        }

        foreach ($ddl as $statement) {
            try {
                $pdo->exec($statement);
            } catch (Throwable $e) {
                error_log('Index create failed: ' . $e->getMessage());
            }
        }

        if (!empty($ddl)) {
            cache_hook_bump(SCHEMA_INVALIDATION_FILE);
        }
    } catch (Throwable $e) {
        error_log('Index verification failed: ' . $e->getMessage());
    }

    cache_set($guardKey, ['checked_at' => time()]);
}

// Database connection with enhanced error handling
$pdo = null;
$dbConnected = false;
$dbError = null;

try {
    // Check if db_connect.php exists
    if (file_exists(__DIR__ . '/db_connect.php')) {
        require_once __DIR__ . '/db_connect.php';

        if (isset($pdo) && $pdo instanceof PDO) {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $dbConnected = true;
        }
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
    error_log('Database connection error: ' . $dbError);
    // Continue with fallback mode - don't die, just use fallback data
}

$catalogToken = cache_hook_token(CATALOG_INVALIDATION_FILE);
$fieldReportsToken = cache_hook_token(FIELD_REPORTS_INVALIDATION_FILE);
$schemaToken = cache_hook_token(SCHEMA_INVALIDATION_FILE);

// Invalidation hook for admin sessions: /?invalidate_cache=catalog|field_reports|schema|all
$canInvalidateCache = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
if ($canInvalidateCache && isset($_GET['invalidate_cache'])) {
    $target = strtolower(trim((string)$_GET['invalidate_cache']));
    if ($target === 'catalog' || $target === 'all') {
        cache_hook_bump(CATALOG_INVALIDATION_FILE);
        $catalogToken = cache_hook_token(CATALOG_INVALIDATION_FILE);
    }
    if ($target === 'field_reports' || $target === 'all') {
        cache_hook_bump(FIELD_REPORTS_INVALIDATION_FILE);
        $fieldReportsToken = cache_hook_token(FIELD_REPORTS_INVALIDATION_FILE);
    }
    if ($target === 'schema' || $target === 'all') {
        cache_hook_bump(SCHEMA_INVALIDATION_FILE);
        $schemaToken = cache_hook_token(SCHEMA_INVALIDATION_FILE);
    }
}

$schemaCaps = [
    'products_has_featured' => false,
    'field_reports_table_exists' => false,
    'field_reports_has_is_hidden' => false,
];
if ($dbConnected && $pdo instanceof PDO) {
    ensure_products_indexes($pdo, $schemaToken);
    $schemaCaps = get_schema_capabilities($pdo, $schemaToken);
}

/* ============================================================================
   2. INPUT VALIDATION & PROCESSING
   ============================================================================ */

// Page number validation
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'default' => 1]
]);
$page = max(1, (int)$page);

$limit  = PRODUCTS_PER_PAGE;
$offset = ($page - 1) * $limit;

// Category validation - DYNAMIC BASED ON DATABASE
$validCategories = []; // Start empty, will populate from database or fallback
$selectedCategory = 'all';

$categoryMetaCacheKey = 'index_category_meta_v1_' . $catalogToken;
$cachedCategoryMeta = cache_get($categoryMetaCacheKey, CATEGORY_META_CACHE_TTL);
if (is_array($cachedCategoryMeta) && isset($cachedCategoryMeta['valid_categories']) && is_array($cachedCategoryMeta['valid_categories'])) {
    $validCategories = $cachedCategoryMeta['valid_categories'];
} elseif ($dbConnected && $pdo instanceof PDO) {
    try {
        $catStmt = $pdo->query("
            SELECT DISTINCT UPPER(category) AS cat
            FROM products
            WHERE category IS NOT NULL AND category != ''
            ORDER BY cat
        ");
        $dbCategories = $catStmt ? ($catStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];

        if (!empty($dbCategories)) {
            $validCategories = $dbCategories;
        } else {
            $validCategories = ['DRESSES', 'TOPS', 'CROP_TOPS', 'TANK_TOPS', 'BOTTOMS',
                               'DENIM', 'OUTERWEAR', 'ACTIVEWEAR', 'ACCESSORIES',
                               'SHOES', 'VINTAGE'];
        }
        cache_set($categoryMetaCacheKey, ['valid_categories' => $validCategories, 'cached_at' => time()]);
    } catch (Throwable $e) {
        error_log('Category fetch error: ' . $e->getMessage());
        $validCategories = ['DRESSES', 'TOPS', 'CROP_TOPS', 'TANK_TOPS', 'BOTTOMS',
                           'DENIM', 'OUTERWEAR', 'ACTIVEWEAR', 'ACCESSORIES',
                           'SHOES', 'VINTAGE'];
    }
} else {
    $validCategories = ['DRESSES', 'TOPS', 'CROP_TOPS', 'TANK_TOPS', 'BOTTOMS',
                       'DENIM', 'OUTERWEAR', 'ACTIVEWEAR', 'ACCESSORIES',
                       'SHOES', 'VINTAGE'];
}

// Homepage is preview-only: category filtering lives on /shop.
$selectedCategory = 'all';

/* ============================================================================
   3. DATABASE QUERIES WITH COMPLETE FALLBACKS
   ============================================================================ */

// Caching for product/category listings
$cacheKey = 'products_' . $selectedCategory . '_page_' . $page . '_catalog_' . $catalogToken . '_schema_' . $schemaToken;
$cached = cache_get($cacheKey, 180); // 3 min TTL
if ($cached !== false) {
    [$products, $totalProductsAll, $totalProductsFiltered, $totalPages, $categoryCounts] = $cached;
} else {
    $products = [];
    $totalProductsAll = 0;
    $totalProductsFiltered = 0;
    $totalPages = 1;
    $categoryCounts = [];

    if ($dbConnected && $pdo instanceof PDO) {
        try {
            $categoryCountsCacheKey = 'index_category_counts_v1_' . $catalogToken;
            $cachedCategoryCounts = cache_get($categoryCountsCacheKey, CATEGORY_META_CACHE_TTL);
            if (is_array($cachedCategoryCounts)) {
                $categoryCounts = $cachedCategoryCounts;
            } else {
                $categoryStmt = $pdo->query("
                    SELECT UPPER(category) AS category, COUNT(*) AS count
                    FROM products
                    WHERE stock > 0 AND category IS NOT NULL
                    GROUP BY UPPER(category)
                ");
                if ($categoryStmt) {
                    $pairs = $categoryStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
                    foreach ($pairs as $k => $v) {
                        $k = strtoupper((string)$k);
                        $categoryCounts[$k] = (int)$v;
                        if (!in_array($k, $validCategories, true)) {
                            $validCategories[] = $k;
                        }
                    }
                }
                cache_set($categoryCountsCacheKey, $categoryCounts);
            }

            $countAllStmt = $pdo->query('SELECT COUNT(*) AS total FROM products WHERE stock > 0');
            if ($countAllStmt) {
                $rowAll = $countAllStmt->fetch();
                $totalProductsAll = (int)($rowAll['total'] ?? 0);
            }

            $where = ['stock > 0'];
            $params = [];
            if ($selectedCategory !== 'all') {
                // FIX: normalize compare to match uppercase input categories
                $where[] = 'UPPER(category) = :category';
                $params[':category'] = $selectedCategory;
            }
            $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

            $countSql = "SELECT COUNT(*) AS total FROM products $whereSql";
            $countStmt = $pdo->prepare($countSql);
            foreach ($params as $k => $v) {
                $countStmt->bindValue($k, $v);
            }
            $countStmt->execute();
            $rowFiltered = $countStmt->fetch();
            $totalProductsFiltered = (int)($rowFiltered['total'] ?? 0);

            $totalPages = max(1, (int)ceil($totalProductsFiltered / $limit));
            if ($page > $totalPages) {
                $page = $totalPages;
                $offset = ($page - 1) * $limit;
            }

            $featuredColumnExists = (bool)($schemaCaps['products_has_featured'] ?? false);

            // Inline LIMIT/OFFSET for native prepares (MySQL doesn't allow bound params here)
            $limitInt = (int)$limit;
            $offsetInt = (int)$offset;

            // NOTE: include slug so we can build /product/{slug} clean URLs
            if ($featuredColumnExists) {
                $sql = "
                    SELECT id, slug, name, price, stock, category, image_url, description,
                           created_at, COALESCE(featured, 0) as featured
                    FROM products
                    $whereSql
                    ORDER BY COALESCE(featured, 0) DESC, created_at DESC
                    LIMIT $limitInt OFFSET $offsetInt
                ";
            } else {
                $sql = "
                    SELECT id, slug, name, price, stock, category, image_url, description,
                           created_at, 0 as featured
                    FROM products
                    $whereSql
                    ORDER BY created_at DESC
                    LIMIT $limitInt OFFSET $offsetInt
                ";
            }

            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->execute();
            $products = $stmt->fetchAll() ?: [];
        } catch (Throwable $e) {
            error_log('Product query error: ' . $e->getMessage());
            $dbConnected = false;
        }
    }

    if (!$dbConnected || empty($products)) {
        $totalProductsAll = 12;
        $totalProductsFiltered = $selectedCategory === 'all' ? 12 : 4;
        $categoryCounts = ['DRESSES' => 4, 'TOPS' => 4, 'ACCESSORIES' => 4];

        $fallbackProducts = [
            [
                'id' => 1,
                'slug' => 'cyberpunk-hoodie',
                'name' => 'CYBERPUNK HOODIE',
                'price' => 89.99,
                'stock' => 10,
                'category' => 'OUTERWEAR',
                'image_url' => '/images/placeholder.jpg',
                'description' => 'Tactical cyberpunk hoodie with neon accents',
                'featured' => 1
            ],
            [
                'id' => 2,
                'slug' => 'neon-crop-top',
                'name' => 'NEON CROP TOP',
                'price' => 49.99,
                'stock' => 3,
                'category' => 'CROP_TOPS',
                'image_url' => '/images/placeholder.jpg',
                'description' => 'Glow-in-the-dark crop top',
                'featured' => 0
            ],
            [
                'id' => 3,
                'slug' => 'tactical-vest',
                'name' => 'TACTICAL VEST',
                'price' => 129.99,
                'stock' => 8,
                'category' => 'OUTERWEAR',
                'image_url' => '/images/placeholder.jpg',
                'description' => 'Modular tactical vest system',
                'featured' => 1
            ],
            [
                'id' => 4,
                'slug' => 'grid-leggings',
                'name' => 'GRID LEGGINGS',
                'price' => 69.99,
                'stock' => 15,
                'category' => 'ACTIVEWEAR',
                'image_url' => '/images/placeholder.jpg',
                'description' => 'Digital grid pattern leggings',
                'featured' => 0
            ],
            [
                'id' => 5,
                'slug' => 'data-mask',
                'name' => 'DATA MASK',
                'price' => 29.99,
                'stock' => 2,
                'category' => 'ACCESSORIES',
                'image_url' => '/images/placeholder.jpg',
                'description' => 'LED face mask with scrolling text',
                'featured' => 1
            ],
            [
                'id' => 6,
                'slug' => 'hyperdrive-jacket',
                'name' => 'HYPERDRIVE JACKET',
                'price' => 149.99,
                'stock' => 5,
                'category' => 'OUTERWEAR',
                'image_url' => '/images/placeholder.jpg',
                'description' => 'Reflective windbreaker with kinetic lines',
                'featured' => 0
            ],
            [
                'id' => 7,
                'slug' => 'neural-link-beanie',
                'name' => 'NEURAL LINK BEANIE',
                'price' => 39.99,
                'stock' => 12,
                'category' => 'ACCESSORIES',
                'image_url' => '/images/placeholder.jpg',
                'description' => 'Smart fabric beanie with LED display',
                'featured' => 0
            ],
            [
                'id' => 8,
                'slug' => 'synthetic-dress',
                'name' => 'SYNTHETIC DRESS',
                'price' => 99.99,
                'stock' => 7,
                'category' => 'DRESSES',
                'image_url' => '/images/placeholder.jpg',
                'description' => 'Liquid metal effect cocktail dress',
                'featured' => 1
            ],
            [
                'id' => 9,
                'slug' => 'quantum-pants',
                'name' => 'QUANTUM PANTS',
                'price' => 89.99,
                'stock' => 9,
                'category' => 'BOTTOMS',
                'image_url' => '/images/placeholder.jpg',
                'description' => 'Holographic print cargo pants',
                'featured' => 0
            ],
            [
                'id' => 10,
                'slug' => 'signal-gloves',
                'name' => 'SIGNAL GLOVES',
                'price' => 44.99,
                'stock' => 20,
                'category' => 'ACCESSORIES',
                'image_url' => '/images/placeholder.jpg',
                'description' => 'Touchscreen gloves with LED fingertips',
                'featured' => 0
            ],
            [
                'id' => 11,
                'slug' => 'drone-helmet',
                'name' => 'DRONE HELMET',
                'price' => 199.99,
                'stock' => 3,
                'category' => 'ACCESSORIES',
                'image_url' => '/images/placeholder.jpg',
                'description' => 'Aerodynamic helmet with HUD visor',
                'featured' => 1
            ],
            [
                'id' => 12,
                'slug' => 'grid-tank',
                'name' => 'GRID TANK',
                'price' => 34.99,
                'stock' => 18,
                'category' => 'TANK_TOPS',
                'image_url' => '/images/placeholder.jpg',
                'description' => 'Mesh tank top with circuit board print',
                'featured' => 0
            ],
        ];

        if ($selectedCategory !== 'all') {
            $products = array_filter($fallbackProducts, function ($product) use ($selectedCategory) {
                return strtoupper((string)($product['category'] ?? '')) === $selectedCategory;
            });
            $totalProductsFiltered = count($products);
            $products = array_values($products);
        } else {
            $products = $fallbackProducts;
        }

        $products = array_slice($products, $offset, $limit);
        $totalPages = max(1, (int)ceil($totalProductsFiltered / $limit));
    }

    cache_set($cacheKey, [$products, $totalProductsAll, $totalProductsFiltered, $totalPages, $categoryCounts]);
}

/* ============================================================================
   4. FIELD REPORTS - PHP 8.5 COMPATIBLE VERSION
   ============================================================================ */

$fieldReports = [];
$schemaVersion = 'v4.4';

// Function to check if image file exists
function validateImageFile(string $imagePath): bool {
    if (empty($imagePath)) {
        return false;
    }

    $fullPath = $_SERVER['DOCUMENT_ROOT'] . $imagePath;

    // Check if file exists and is readable
    if (!file_exists($fullPath) || !is_readable($fullPath)) {
        return false;
    }

    // Check file size
    if (filesize($fullPath) === 0) {
        return false;
    }

    return true;
}

// Placeholder generation should be handled offline (admin/cron), not during page request.
function createCyberpunkPlaceholder(string $filename, string $caption = ''): string {
    return '/images/placeholder.jpg';
}

// Try cache first for field reports (longer TTL + invalidation hook token)
$fieldReportsCacheKey = 'index_field_reports_v2_' . $fieldReportsToken;
$cachedFieldReports = cache_get($fieldReportsCacheKey, FIELD_REPORTS_CACHE_TTL);
if (is_array($cachedFieldReports)) {
    $fieldReports = $cachedFieldReports;
} else {
    // Try to fetch field reports from database
    if ($dbConnected && $pdo instanceof PDO && !empty($schemaCaps['field_reports_table_exists'])) {
        try {
            $hasIsHidden = !empty($schemaCaps['field_reports_has_is_hidden']);

            if ($hasIsHidden) {
                $sql = 'SELECT media_url, caption, type, created_at FROM field_reports WHERE is_hidden = 0 ORDER BY sort_order ASC, created_at DESC LIMIT :lim';
            } else {
                $sql = 'SELECT media_url, caption, type, created_at FROM field_reports ORDER BY created_at DESC LIMIT :lim';
            }

            $repStmt = $pdo->prepare($sql);
            $repStmt->bindValue(':lim', (int)FIELD_REPORTS_LIMIT, PDO::PARAM_INT);
            $repStmt->execute();

            $rawReports = $repStmt->fetchAll() ?: [];

            foreach ($rawReports as $report) {
                $mediaUrl = trim((string)($report['media_url'] ?? ''));
                $caption = trim((string)($report['caption'] ?? ''));
                $type = trim((string)($report['type'] ?? 'image'));
                $createdAt = (string)($report['created_at'] ?? date('Y-m-d H:i:s'));

                if ($mediaUrl === '') {
                    continue;
                }

                if (!preg_match('#^(https?:|/)#', $mediaUrl)) {
                    $mediaUrl = '/' . ltrim($mediaUrl, '/');
                }

                if ($type === '' || $type === 'image') {
                    $ext = strtolower(pathinfo($mediaUrl, PATHINFO_EXTENSION));
                    $type = in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'm4v'], true) ? 'video' : 'image';
                }

                if ($type === 'image' && !validateImageFile($mediaUrl)) {
                    $mediaUrl = '/images/placeholder.jpg';
                }

                $fieldReports[] = [
                    'src' => $mediaUrl,
                    'caption' => $caption ?: 'SYSTEM_DATA // UNTITLED',
                    'type' => strtolower($type),
                    'created_at' => $createdAt,
                    'is_recent' => (time() - strtotime($createdAt)) < (7 * 24 * 3600),
                    'is_valid' => true,
                ];
            }
        } catch (Throwable $e) {
            error_log('Field reports query failed: ' . $e->getMessage());
        }
    }

    // Generate fallback field reports if none found
    if (empty($fieldReports)) {
        $captions = [
            'LOC: SECTOR_7 // SUBJ: HOODIE',
            'LOC: UNDERGROUND // SUBJ: MASK',
        'SYS_OVERLOAD // MOTION_BLUR',
        'ARCHIVE_DATA // RAW_FILM',
        'LOC: REFLECTION // SUBJ: NEON',
        'PROTOCOL_ACTIVE // SCANNING',
        'SIGNAL_LOST // STATIC',
        'DATA_STREAM // ENCRYPTED',
        'FREQUENCY_OPEN // TRANSMITTING',
        'SYSTEM_UPDATE // v4.4',
        'NEURAL_LINK // ESTABLISHED',
        'GRID_ACCESS // GRANTED',
        'SURVEILLANCE_FEED // LIVE',
            'ENCRYPTION_BROKEN // DATA_LEAK',
            'HACKER_VISION // ACTIVATED',
        ];

        for ($i = 1; $i <= 15; $i++) {
            $fieldReports[] = [
                'src' => '/images/placeholder.jpg',
                'caption' => $captions[$i - 1] ?? "FIELD_REPORT_{$i} // ARCHIVE_DATA",
                'type' => 'image',
                'created_at' => date('Y-m-d H:i:s', strtotime("-$i days")),
                'is_recent' => $i <= 7,
                'is_valid' => true,
            ];
        }
    }

    $fieldReports = array_slice($fieldReports, 0, FIELD_REPORTS_LIMIT);
    cache_set($fieldReportsCacheKey, $fieldReports);
}

// Limit field reports for performance
$fieldReports = array_slice($fieldReports, 0, FIELD_REPORTS_LIMIT);

// Newsletter event post (latest active card shown in footer)
$newsletterEventPost = null;
if ($dbConnected && $pdo instanceof PDO) {
    try {
        $postStmt = $pdo->query("
            SELECT title, excerpt, image_url, target_url
            FROM newsletter_posts
            WHERE is_active = 1
            ORDER BY updated_at DESC, id DESC
            LIMIT 1
        ");
        $postRow = $postStmt ? ($postStmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;
        if (is_array($postRow)) {
            $title = trim((string)($postRow['title'] ?? ''));
            $excerpt = trim((string)($postRow['excerpt'] ?? ''));
            $imageUrl = trim((string)($postRow['image_url'] ?? ''));
            $targetUrl = trim((string)($postRow['target_url'] ?? '/events'));

            if ($title !== '' && $imageUrl !== '') {
                if (!preg_match('#^(https?://|/)#i', $imageUrl)) {
                    $imageUrl = '/' . ltrim($imageUrl, '/');
                }
                if (!preg_match('#^(https?://|/)#i', $targetUrl)) {
                    $targetUrl = '/events';
                }
                if ($targetUrl === '') {
                    $targetUrl = '/events';
                }
                $newsletterEventPost = [
                    'title' => $title,
                    'excerpt' => $excerpt,
                    'image_url' => $imageUrl,
                    'target_url' => $targetUrl,
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('Newsletter event post fetch failed: ' . $e->getMessage());
    }
}

/* ============================================================================
   5. CART & SESSION DATA
   ============================================================================ */

$totalItems = 0;
$cartTotal = 0.00;
$cartItems = [];

$sessionCart = $_SESSION['cart'] ?? [];
$itemsSource = [];
if (is_array($sessionCart) && isset($sessionCart['items']) && is_array($sessionCart['items'])) {
    $itemsSource = $sessionCart['items']; // current cart schema
} elseif (is_array($sessionCart)) {
    $itemsSource = $sessionCart; // legacy fallback
}

foreach ($itemsSource as $id => $item) {
    if (!is_array($item)) {
        continue;
    }

    $quantity = max(0, (int)($item['quantity'] ?? 1));
    $price = max(0.00, (float)($item['price'] ?? 0));

    if ($quantity > 0 && $price > 0) {
        $totalItems += $quantity;
        $cartTotal += ($price * $quantity);
        $cartItems[$id] = $item;
    }
}

/* ============================================================================
   6. HELPER FUNCTIONS
   ============================================================================ */

function h2(string $s, string $context = 'html'): string {
    // Back-compat wrapper if you ever had both; keep single canonical h() below.
    return h($s, $context);
}

function h(string $s, string $context = 'html'): string {
    if ($context === 'attr') {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
    }
    if ($context === 'js') {
        return str_replace(
            ["\\", "'", '"', "\n", "\r", '</', '<', '>', '&'],
            ["\\\\", "\\'", '\\"', '\\n', '\\r', '<\\/', '\\x3c', '\\x3e', '\\x26'],
            $s
        );
    }
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function getProductImage(string $imageUrl): string {
    if (empty($imageUrl)) {
        return '/images/placeholder.jpg';
    }

    $clean = '/' . ltrim($imageUrl, '/');
    $fullPath = $_SERVER['DOCUMENT_ROOT'] . $clean;

    // Try original path
    if (file_exists($fullPath) && is_readable($fullPath)) {
        return $clean;
    }

    // Try common fallback folders
    $try_paths = [
        '/images/' . ltrim($imageUrl, '/'),
        '/uploads/' . ltrim($imageUrl, '/'),
        '/assets/' . ltrim($imageUrl, '/'),
        '/images/products/' . basename($imageUrl),
        '/images/webp/' . ltrim($imageUrl, '/'),
        '/webp/' . ltrim($imageUrl, '/'),
    ];
    foreach ($try_paths as $try) {
        $tryFull = $_SERVER['DOCUMENT_ROOT'] . $try;
        if (file_exists($tryFull) && is_readable($tryFull)) {
            return $try;
        }
    }

    return '/images/placeholder.jpg';
}

// Lighter preview image helper for index page: prefer thumbs/medium variants when available.
function getPreviewProductImage(string $imageUrl): string {
    if ($imageUrl === '') {
        return getProductImage($imageUrl);
    }

    $base = basename($imageUrl);
    if ($base === '' || $base === '.' || $base === '..') {
        return getProductImage($imageUrl);
    }

    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    if ($docRoot === '') {
        return getProductImage($imageUrl);
    }

    $candidates = [
        '/images/thumbs/' . $base,
        '/images/medium/' . $base,
    ];

    foreach ($candidates as $rel) {
        $full = $docRoot . $rel;
        if (is_file($full) && is_readable($full)) {
            return $rel;
        }
    }

    return getProductImage($imageUrl);
}

function getPageUrl(int $pageNum, string $category = 'all'): string {
    $pageNum = max(1, $pageNum);
    $params = ['page' => $pageNum];

    if ($category !== '' && $category !== 'all') {
        $params['category'] = $category;
    }

    return '?' . http_build_query($params);
}

function formatPrice(float $price): string {
    return number_format($price, 2, '.', ',');
}

/**
 * Build a clean product URL:
 * - Prefer /product/{slug} when available
 * - Fallback to /product/{id}
 */
function getProductUrl(int $id, ?string $slug = null): string {
    $id = max(0, $id);
    $slug = is_string($slug) ? trim($slug) : '';
    if ($slug !== '') {
        return '/product/' . rawurlencode($slug);
    }
    return '/product/' . $id;
}

/* ============================================================================
   7. TEMPLATE RENDERING
   ============================================================================ */

// Current URL for meta tags
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scheme = $https ? 'https' : 'http';
$currentUrl = $scheme . '://' . $host . ($_SERVER['REQUEST_URI'] ?? '/');

// Site statistics
$visibleProducts = count($products);
$systemStatus = $dbConnected ? 'DATABASE_CONNECTED' : 'FALLBACK_MODE';
$fieldReportsCount = count($fieldReports);

// Dynamic meta/OG tags
$metaTitle = SITE_NAME . ' // ' . VERSION . ' ARCHIVE';
if ($selectedCategory !== 'all') {
    $metaTitle = SITE_NAME . ' // ' . strtoupper($selectedCategory) . ' // ' . VERSION . ' ARCHIVE';
}

$metaDescription = SITE_TAGLINE . ' | Cyberpunk apparel and art archive.';
if ($selectedCategory !== 'all') {
    $metaDescription = 'Shop ' . ucfirst(strtolower($selectedCategory)) . ' sector. ' . SITE_TAGLINE . ' | Cyberpunk apparel and art archive.';
}
$metaDescription .= ' Displaying ' . $visibleProducts . ' of ' . $totalProductsFiltered . ' items.';

$metaImage = $scheme . '://' . $host . '/images/social-preview.jpg';
if ($selectedCategory !== 'all') {
    // Use a category-specific image if available
    $catImg = '/images/social-preview-' . strtolower($selectedCategory) . '.jpg';
    $catImgPath = $_SERVER['DOCUMENT_ROOT'] . $catImg;
    if (file_exists($catImgPath)) {
        $metaImage = $scheme . '://' . $host . $catImg;
    }
}

?>
<!DOCTYPE html>
<html lang="en" class="tech-archive" data-version="<?= h(VERSION) ?>" data-status="<?= h($systemStatus) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">

    <title><?= h($metaTitle) ?></title>

    <?php if (is_file(__DIR__ . '/includes/tracking_pixels.php')) { require_once __DIR__ . '/includes/tracking_pixels.php'; } ?>

    <!-- Meta Tags -->
    <meta name="description" content="<?= h($metaDescription) ?>">
    <meta name="keywords" content="cyberpunk, streetwear, fashion, art, apparel, design, cyberwear, futuristic">
    <meta name="author" content="<?= h(SITE_NAME) ?>">
    <meta name="robots" content="index, follow">
    <meta name="version" content="<?= h(VERSION) ?>">

    <!-- Open Graph -->
    <meta property="og:title" content="<?= h($metaTitle) ?>">
    <meta property="og:description" content="<?= h($metaDescription) ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= h($currentUrl) ?>">
    <meta property="og:image" content="<?= h($metaImage) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:site_name" content="<?= h(SITE_NAME) ?>">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= h($metaTitle) ?>">
    <meta name="twitter:description" content="<?= h($metaDescription) ?>">
    <meta name="twitter:image" content="<?= h($metaImage) ?>">

    <!-- Favicons -->
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/android-chrome-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/android-chrome-512x512.png">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#000000">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="DOD">
    <script src="/js/pwa-init.js" defer></script>
    <script src="/js/welcome-offer.js" defer></script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Syncopate:wght@400;700;900&family=Inter:wght@300;400;700;900&family=Orbitron:wght@400;700;900&display=swap" rel="stylesheet">

    <!-- CSS -->
    <style>
        /* ============================================================================
           CYBERPUNK DESIGN SYSTEM
           ============================================================================ */
        :root {
            --neon: #00ff9d;
            --neon-glow: 0 0 10px rgba(0, 255, 157, 0.5);
            --neon-glow-strong: 0 0 20px rgba(0, 255, 157, 0.7);
            --cyber-blue: #00f3ff;
            --cyber-pink: #ff00ff;
            --dark: #000;
            --darker: #050505;
            --medium: #111;
            --light: #222;
            --text: #fff;
            --text-dim: #888;
            --border: #333;
            --border-glow: rgba(0, 255, 157, 0.3);
            --error: #ff0055;
            --success: #00ff9d;
            --warning: #ffaa00;
            --grid-color: rgba(0, 255, 157, 0.1);
            --scanline: rgba(0, 255, 157, 0.03);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body.tech-archive {
            background: var(--dark);
            color: var(--text);
            font-family: 'Inter', sans-serif;
            overflow-x: hidden;
            min-height: 100vh;
            position: relative;
            line-height: 1.6;
        }

        /* BASE BACKGROUND (only if no data-bg is set)
           FIX: prevents double-overlay when data-bg="grid" is used */
        body.tech-archive:not([data-bg])::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image:
                linear-gradient(var(--grid-color) 1px, transparent 1px),
                linear-gradient(90deg, var(--grid-color) 1px, transparent 1px);
            background-size: 50px 50px;
            z-index: -3;
            pointer-events: none;
            opacity: 0.3;
        }

        body.tech-archive:not([data-bg])::after {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(
                to bottom,
                transparent 50%,
                var(--scanline) 50%
            );
            background-size: 100% 4px;
            z-index: -2;
            pointer-events: none;
            animation: scanlines 8s linear infinite;
            opacity: 0.5;
        }

        /* Background Themes */
       /* ============================================================
          BACKGROUND SYSTEM — FINAL FIX
          ============================================================ */

        /* GRID MODE ONLY */
        body.tech-archive[data-bg="grid"]::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(var(--grid-color) 1px, transparent 1px),
                linear-gradient(90deg, var(--grid-color) 1px, transparent 1px);
            background-size: 50px 50px;
            z-index: -3;
            pointer-events: none;
            opacity: 0.3;
        }

        body.tech-archive[data-bg="grid"]::after {
            content: '';
            position: fixed;
            inset: 0;
            background: linear-gradient(
                to bottom,
                transparent 50%,
                var(--scanline) 50%
            );
            background-size: 100% 4px;
            z-index: -2;
            pointer-events: none;
            animation: scanlines 8s linear infinite;
            opacity: 0.5;
        }

        /* DARK MODE */
        body.tech-archive[data-bg="dark"] {
            background: var(--dark);
        }

        /* GRADIENT MODE */
        body.tech-archive[data-bg="gradient"] {
            background:
                radial-gradient(circle at 20% 20%, rgba(0, 255, 157, 0.12), transparent 50%),
                radial-gradient(circle at 80% 30%, rgba(255, 0, 157, 0.12), transparent 45%),
                radial-gradient(circle at 50% 80%, rgba(0, 243, 255, 0.12), transparent 55%),
                var(--dark);
        }

        /* FIXED: removed stray brace that broke CSS parsing */

        body.tech-archive[data-bg="dark"]::before,
        body.tech-archive[data-bg="dark"]::after {
           content: none;
        }

        body.tech-archive[data-bg="gradient"]::before {
            opacity: 0;
        }

        body.tech-archive[data-bg="gradient"]::after {
            opacity: 0;
        }

        body.tech-archive[data-bg="gradient"] {
            background: radial-gradient(circle at 20% 20%, rgba(0, 255, 157, 0.12), transparent 50%),
                        radial-gradient(circle at 80% 30%, rgba(255, 0, 157, 0.12), transparent 45%),
                        radial-gradient(circle at 50% 80%, rgba(0, 243, 255, 0.12), transparent 55%),
                        var(--dark);
        }

        @keyframes scanlines {
            0% { transform: translateY(0); }
            100% { transform: translateY(4px); }
        }

        /* Glitch Effect */
        @keyframes glitch {
            0% { transform: translate(0); }
            20% { transform: translate(-2px, 2px); }
            40% { transform: translate(-2px, -2px); }
            60% { transform: translate(2px, 2px); }
            80% { transform: translate(2px, -2px); }
            100% { transform: translate(0); }
        }

        /* Content stacking fix: ensures all real page content (hero, gallery,
           newsletter, product grid, footer) always paints above the
           admin-controlled background layer injected via includes/bg_styles.php */
        main#main-content,
        footer {
            position: relative;
            z-index: 1;
        }

        /* Skip Link */
        .skip-link {
            position: absolute;
            top: -40px;
            left: 10px;
            background: var(--neon);
            color: #000;
            padding: 12px 20px;
            z-index: 10000;
            text-decoration: none;
            font-family: 'Space Mono', monospace;
            font-size: 0.8rem;
            font-weight: bold;
            border: 1px solid var(--dark);
            transition: top 0.3s ease;
        }

        .skip-link:focus {
            top: 10px;
            outline: 2px solid var(--neon);
            outline-offset: 2px;
        }

        /* Header */
        header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            padding: 20px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 1000;
            background: rgba(0, 0, 0, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            position: relative;
        }

        .logo-img {
            height: 40px;
            width: auto;
            display: block;
            flex-shrink: 0;
        }

        .logo-text {
            font-family: 'Syncopate', sans-serif;
            font-size: 1.2rem;
            color: var(--neon);
            letter-spacing: 3px;
            text-transform: uppercase;
            font-weight: 900;
            white-space: nowrap;
        }

        .logo::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 100%;
            height: 2px;
            background: linear-gradient(90deg, var(--neon), transparent);
        }

        .nav-right {
            display: flex;
            gap: 30px;
            align-items: center;
        }

        .nav-toggle {
            display: none;
            border: 1px solid var(--border);
            background: transparent;
            color: var(--text);
            font-family: 'Space Mono', monospace;
            font-size: 0.72rem;
            letter-spacing: 2px;
            padding: 10px 14px;
            cursor: pointer;
            border-radius: 3px;
            min-height: 44px;
            align-items: center;
            justify-content: center;
        }

        .nav-toggle:hover,
        .nav-toggle:focus-visible {
            color: var(--neon);
            border-color: var(--neon);
            outline: none;
        }

        .theme-toggle {
            border: 1px solid var(--border);
            background: transparent;
            color: var(--text);
            font-family: 'Space Mono', monospace;
            font-size: 0.7rem;
            letter-spacing: 2px;
            padding: 10px 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            border-radius: 3px;
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .theme-toggle:hover,
        .theme-toggle:focus-visible {
            color: var(--neon);
            border-color: var(--neon);
            outline: none;
        }

        .nav-link {
            color: var(--text);
            text-decoration: none;
            font-family: 'Space Mono', monospace;
            font-size: 0.8rem;
            letter-spacing: 2px;
            transition: color 0.3s ease;
            position: relative;
            min-height: 44px;
            display: inline-flex;
            align-items: center;
        }

        .nav-link:hover {
            color: var(--neon);
        }

        .nav-link::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 0;
            height: 1px;
            background: var(--neon);
            transition: width 0.3s ease;
        }

        .nav-link:hover::after {
            width: 100%;
        }

        .cart-icon {
            position: relative;
        }

        .cart-count {
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--neon);
            color: #000;
            font-size: 0.6rem;
            padding: 2px 6px;
            border-radius: 50%;
            font-weight: bold;
            min-width: 18px;
            text-align: center;
        }

        /* Hero Section */
        .hero-section {
            position: relative;
            width: 100%;
            height: 100vh;
            min-height: 700px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 10%;
            margin-top: 80px;
            overflow: hidden;
            isolation: isolate;
        }

        .hero-bg-video {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            z-index: -1;
            opacity: 0.4;
            filter: grayscale(100%) contrast(120%) brightness(0.7);
        }

        .video-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background:
                linear-gradient(90deg, rgba(0, 0, 0, 0.9) 0%, transparent 30%, transparent 70%, rgba(0, 0, 0, 0.9) 100%),
                radial-gradient(circle at 30% 50%, rgba(0, 255, 157, 0.1) 0%, transparent 70%),
                radial-gradient(circle at 70% 50%, rgba(255, 0, 157, 0.1) 0%, transparent 70%);
            z-index: -1;
            pointer-events: none;
        }

        .hero-content {
            text-align: center;
            max-width: 900px;
            position: relative;
            z-index: 1;
            animation: fadeInUp 1s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .hero-title {
            font-family: 'Orbitron', sans-serif;
            font-size: clamp(4rem, 10vw, 7rem);
            color: var(--neon);
            margin-bottom: 30px;
            text-transform: uppercase;
            letter-spacing: 10px;
            line-height: 0.9;
            text-shadow:
                0 0 10px rgba(0, 255, 157, 0.5),
                0 0 20px rgba(0, 255, 157, 0.3),
                0 0 30px rgba(0, 255, 157, 0.1);
            position: relative;
            display: inline-block;
        }

        .hero-title::before {
            content: '';
            position: absolute;
            top: -10px;
            left: -20px;
            right: -20px;
            bottom: -10px;
            border: 1px solid rgba(0, 255, 157, 0.3);
            pointer-events: none;
            animation: glitch 3s infinite;
        }

        .hero-subtext {
            max-width: 600px;
            margin: 0 auto 40px;
            font-size: 0.9rem;
            letter-spacing: 3px;
            color: var(--text-dim);
            font-family: 'Space Mono', monospace;
            line-height: 1.8;
        }

        .system-text {
            color: var(--neon);
            margin-top: 20px;
            display: block;
            font-weight: bold;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        .access-btn {
            display: inline-block;
            padding: 18px 45px;
            background: transparent;
            border: 2px solid var(--neon);
            color: var(--neon);
            font-family: 'Space Mono', monospace;
            font-size: 0.8rem;
            letter-spacing: 3px;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            text-decoration: none;
            font-weight: bold;
            margin-top: 20px;
        }

        .access-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.6s;
        }

        .access-btn:hover {
            background: linear-gradient(135deg, var(--neon), var(--cyber-blue));
            color: #000;
            box-shadow:
                0 0 30px rgba(0, 255, 157, 0.4),
                0 0 60px rgba(0, 255, 157, 0.2);
            transform: translateY(-5px);
        }

        .access-btn:hover::before {
            left: 100%;
        }

        .access-btn:focus-visible {
            outline: 2px solid var(--neon);
            outline-offset: 4px;
        }

        .hero-display-stats {
            margin-top: 40px;
            font-size: 0.7rem;
            color: var(--text-dim);
            letter-spacing: 2px;
            font-family: 'Space Mono', monospace;
            background: rgba(0, 0, 0, 0.5);
            padding: 15px 25px;
            border-radius: 5px;
            border: 1px solid rgba(0, 255, 157, 0.1);
            display: inline-block;
        }

        /* Transmission Strip */
        .transmission-strip {
            width: 100%;
            overflow-x: auto;
            white-space: nowrap;
            background: linear-gradient(135deg, rgba(5, 5, 5, 0.95), rgba(17, 17, 17, 0.95));
            padding: 60px 0;
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
            scrollbar-width: none;
            -ms-overflow-style: none;
            position: relative;
            backdrop-filter: blur(10px);
        }

        .transmission-strip::-webkit-scrollbar {
            display: none;
        }

        .transmission-header {
            text-align: center;
            font-size: 0.9rem;
            color: var(--neon);
            letter-spacing: 4px;
            margin-bottom: 50px;
            font-family: 'Orbitron', sans-serif;
            text-transform: uppercase;
            position: relative;
            display: inline-block;
            left: 50%;
            transform: translateX(-50%);
        }

        .transmission-header::before,
        .transmission-header::after {
            content: '⚡';
            margin: 0 20px;
            opacity: 0.5;
            animation: pulse 1.5s infinite alternate;
        }

        .transmission-reel {
            display: inline-flex;
            gap: 30px;
            padding: 0 10%;
            animation: reel-scroll 80s linear infinite;
            will-change: transform;
        }

        @keyframes reel-scroll {
            0% { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }

        @keyframes reel-scroll-mobile {
            0% { transform: translateX(0); }
            100% { transform: translateX(-35%); }
        }

        /* Desktop/GPU performance profile */
        body.tech-archive.perf-lite::after,
        body.tech-archive.perf-lite[data-bg="grid"]::after {
            animation: none;
            opacity: 0.2;
        }

        body.tech-archive.perf-lite .hero-bg-video {
            opacity: 0.24;
            filter: grayscale(100%) contrast(105%) brightness(0.62);
        }

        body.tech-archive.perf-lite .video-overlay {
            background:
                linear-gradient(90deg, rgba(0, 0, 0, 0.92) 0%, transparent 35%, transparent 65%, rgba(0, 0, 0, 0.92) 100%),
                radial-gradient(circle at 50% 50%, rgba(0, 255, 157, 0.06) 0%, transparent 78%);
        }

        body.tech-archive.perf-lite .transmission-strip {
            backdrop-filter: none;
            background: linear-gradient(135deg, rgba(5, 5, 5, 0.97), rgba(17, 17, 17, 0.97));
        }

        body.tech-archive.perf-lite .transmission-reel {
            animation-duration: 135s;
            will-change: auto;
        }

        body.tech-archive.perf-lite .transmission-frame img,
        body.tech-archive.perf-lite .transmission-frame video {
            filter: grayscale(70%) contrast(108%) brightness(0.86);
        }

        @media (min-width: 1600px) {
            body.tech-archive:not(.perf-lite)::after,
            body.tech-archive:not(.perf-lite)[data-bg="grid"]::after {
                animation-duration: 12s;
                opacity: 0.38;
            }

            body.tech-archive:not(.perf-lite) .hero-bg-video {
                opacity: 0.32;
                filter: grayscale(100%) contrast(110%) brightness(0.66);
            }

            body.tech-archive:not(.perf-lite) .video-overlay {
                background:
                    linear-gradient(90deg, rgba(0, 0, 0, 0.92) 0%, transparent 33%, transparent 67%, rgba(0, 0, 0, 0.92) 100%),
                    radial-gradient(circle at 30% 50%, rgba(0, 255, 157, 0.08) 0%, transparent 76%),
                    radial-gradient(circle at 70% 50%, rgba(255, 0, 157, 0.08) 0%, transparent 76%);
            }

            body.tech-archive:not(.perf-lite) .transmission-strip {
                backdrop-filter: blur(6px);
            }

            body.tech-archive:not(.perf-lite) .transmission-reel {
                animation-duration: 96s;
            }
        }

        .transmission-strip:hover .transmission-reel {
            animation-play-state: paused;
        }

        .transmission-frame {
            width: 320px;
            height: 450px;
            position: relative;
            flex-shrink: 0;
            overflow: hidden;
            border: 1px solid var(--border);
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            background: var(--dark);
        }

        .transmission-frame:hover {
            border-color: var(--neon);
            transform: translateY(-10px) scale(1.03);
            box-shadow:
                0 20px 40px rgba(0, 255, 157, 0.15),
                0 0 0 1px rgba(0, 255, 157, 0.1);
        }

        .transmission-frame img,
        .transmission-frame video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            filter: grayscale(80%) contrast(120%) brightness(0.8);
            transition: all 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .transmission-frame:hover img,
        .transmission-frame:hover video {
            filter: grayscale(0%) contrast(100%) brightness(1.1);
            transform: scale(1.1);
        }

        .frame-caption {
            position: absolute;
            bottom: 20px;
            left: 20px;
            right: 20px;
            background: rgba(0, 0, 0, 0.9);
            color: var(--neon);
            font-family: 'Space Mono', monospace;
            font-size: 0.7rem;
            padding: 12px 15px;
            pointer-events: none;
            letter-spacing: 1.5px;
            border: 1px solid rgba(0, 255, 157, 0.3);
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .transmission-frame:hover .frame-caption {
            background: rgba(0, 0, 0, 0.95);
            border-color: var(--neon);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }

        .view-logs-frame {
            display: flex !important;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            background: linear-gradient(135deg, var(--darker) 0%, var(--medium) 100%);
            border: 1px solid var(--border);
            transition: all 0.5s ease;
            position: relative;
            overflow: hidden;
        }

        .view-logs-frame::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(
                45deg,
                transparent 30%,
                rgba(0, 255, 157, 0.1) 50%,
                transparent 70%
            );
            transform: rotate(45deg);
            animation: shine 3s infinite linear;
        }

        @keyframes shine {
            0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
        }

        .view-logs-frame:hover {
            background: linear-gradient(135deg, var(--dark) 0%, var(--darker) 100%);
            border-color: var(--neon);
        }

        .view-logs-frame span {
            color: var(--text);
            font-family: 'Syncopate', sans-serif;
            font-size: 1.5rem;
            text-align: center;
            line-height: 1.3;
            letter-spacing: 4px;
            font-weight: 700;
            position: relative;
            z-index: 1;
            transition: color 0.3s ease;
        }

        .view-logs-frame:hover span {
            color: var(--neon);
        }

        .view-logs-frame .arrow {
            color: var(--neon);
            font-size: 1.8rem;
            margin-top: 20px;
            display: block;
            animation: arrow-pulse 2s infinite;
            position: relative;
            z-index: 1;
        }

        @keyframes arrow-pulse {
            0%, 100% { opacity: 0.7; transform: translateX(0); }
            50% { opacity: 1; transform: translateX(8px); }
        }

        /* Product Grid Section */
        .lookbook-section {
            padding: 100px 10% 120px;
        }

        .section-tag {
            font-size: 0.7rem;
            color: var(--neon);
            margin-bottom: 60px;
            letter-spacing: 4px;
            text-transform: uppercase;
            font-family: 'Space Mono', monospace;
            position: relative;
            display: inline-block;
            padding-left: 20px;
            border-left: 2px solid var(--neon);
        }

        .collection-showcase {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin: -18px 0 46px;
        }

        .collection-tile {
            display: block;
            text-decoration: none;
            border: 1px solid var(--border);
            border-radius: 3px;
            padding: 24px 22px;
            min-height: 150px;
            background:
                linear-gradient(140deg, rgba(255, 0, 102, 0.09) 0%, rgba(5, 5, 5, 0.96) 40%),
                linear-gradient(0deg, rgba(0, 255, 157, 0.08), rgba(0, 255, 157, 0.08));
            transition: transform 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .collection-tile::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, transparent, rgba(0, 255, 157, 0.12), transparent);
            transform: translateX(-120%);
            transition: transform 0.45s ease;
        }

        .collection-tile:hover,
        .collection-tile:focus-visible {
            border-color: var(--neon);
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0, 255, 157, 0.15);
            outline: none;
        }

        .collection-tile:hover::after,
        .collection-tile:focus-visible::after {
            transform: translateX(120%);
        }

        .collection-label {
            font-family: 'Space Mono', monospace;
            color: var(--text-dim);
            letter-spacing: 2px;
            font-size: 0.58rem;
            margin-bottom: 10px;
            display: block;
            text-transform: uppercase;
        }

        .collection-title {
            display: block;
            font-family: 'Syncopate', sans-serif;
            color: var(--text);
            font-size: 1rem;
            letter-spacing: 2px;
            margin-bottom: 10px;
            text-transform: uppercase;
        }

        .collection-copy {
            display: block;
            font-family: 'Space Mono', monospace;
            color: var(--text-dim);
            font-size: 0.68rem;
            line-height: 1.5;
            letter-spacing: 1px;
            max-width: 32ch;
        }

        .collection-arrow {
            display: inline-block;
            margin-top: 13px;
            font-family: 'Space Mono', monospace;
            color: var(--neon);
            font-size: 0.72rem;
            letter-spacing: 2px;
        }

        .preview-controls {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 220px;
            gap: 12px;
            align-items: center;
            margin: -12px 0 28px;
        }

        .preview-search,
        .preview-sort {
            width: 100%;
            min-height: 44px;
            border: 1px solid var(--border);
            border-radius: 3px;
            background: rgba(0, 0, 0, 0.52);
            color: var(--text);
            font-family: 'Space Mono', monospace;
            font-size: 0.72rem;
            letter-spacing: 1px;
            padding: 10px 12px;
        }

        .preview-search:focus-visible,
        .preview-sort:focus-visible {
            outline: 2px solid var(--neon);
            outline-offset: 2px;
            border-color: var(--neon);
        }

        .preview-empty {
            grid-column: 1 / -1;
            border: 1px solid var(--border);
            background: rgba(0, 0, 0, 0.4);
            border-radius: 3px;
            padding: 18px;
            font-family: 'Space Mono', monospace;
            font-size: 0.68rem;
            letter-spacing: 1.5px;
            color: var(--text-dim);
            text-transform: uppercase;
        }

        /* Category Navigation */
        .nav-group {
            display: flex;
            gap: 15px;
            margin-bottom: 50px;
            flex-wrap: wrap;
            align-items: center;
        }

        .nav-group .label {
            font-size: 0.6rem;
            color: var(--neon);
            margin-right: 20px;
            letter-spacing: 2px;
            font-family: 'Space Mono', monospace;
        }

        .nav-tab {
            padding: 12px 25px;
            border: 1px solid var(--border);
            color: var(--text-dim);
            font-family: 'Space Mono', monospace;
            font-size: 0.7rem;
            letter-spacing: 2px;
            cursor: pointer;
            transition: all 0.3s ease;
            user-select: none;
            background: transparent;
            border-radius: 3px;
            position: relative;
            overflow: hidden;
        }

        .nav-tab:hover,
        .nav-tab.active {
            border-color: var(--neon);
            color: var(--neon);
            background: rgba(0, 255, 157, 0.05);
            transform: translateY(-2px);
        }

        .nav-tab.active {
            background: rgba(0, 255, 157, 0.1);
            box-shadow: 0 5px 15px rgba(0, 255, 157, 0.1);
        }

        .nav-tab .count {
            color: var(--neon);
            font-weight: bold;
            margin-left: 5px;
        }

        /* Product Grid */
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 40px 30px;
            margin-bottom: 80px;
        }

        .reveal-item {
            transition: opacity 0.6s ease, transform 0.6s ease;
            opacity: 0;
            transform: translateY(20px);
        }

        .reveal-item.revealed {
            opacity: 1;
            transform: translateY(0);
        }

        .product-card {
            display: block;
            text-decoration: none;
            position: relative;
            transition: all 0.3s ease;
            height: 100%;
        }

        .product-card:hover {
            transform: translateY(-10px);
        }

        .product-card:focus-visible {
            outline: 2px solid var(--neon);
            outline-offset: 6px;
        }

        .card-image {
            position: relative;
            overflow: hidden;
            background: var(--darker);
            border: 1px solid var(--border);
            margin-bottom: 20px;
            height: 350px;
            transition: border-color 0.3s ease;
        }

        .product-card:hover .card-image {
            border-color: var(--neon);
        }

        .card-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .product-card:hover .card-image img {
            transform: scale(1.08);
        }

        .stock-low {
            position: absolute;
            top: 15px;
            right: 15px;
            background: linear-gradient(135deg, var(--warning), #ffdd00);
            color: #000;
            padding: 6px 12px;
            font-size: 0.55rem;
            z-index: 2;
            font-family: 'Space Mono', monospace;
            font-weight: bold;
            border-radius: 3px;
            border: 1px solid rgba(0, 0, 0, 0.3);
            animation: pulse 1.5s infinite;
        }

        .featured-badge {
            position: absolute;
            top: 15px;
            left: 15px;
            background: var(--cyber-pink);
            color: #000;
            padding: 6px 12px;
            font-size: 0.55rem;
            z-index: 2;
            font-family: 'Space Mono', monospace;
            font-weight: bold;
            border-radius: 3px;
            border: 1px solid rgba(0, 0, 0, 0.3);
        }

        .card-info {
            padding: 0 5px;
        }

        .card-title {
            font-size: 0.85rem;
            letter-spacing: 2px;
            color: var(--text);
            font-family: 'Space Mono', monospace;
            font-weight: bold;
            margin-bottom: 8px;
            transition: color 0.3s ease;
            line-height: 1.4;
        }

        .product-card:hover .card-title {
            color: var(--neon);
        }

        .card-price {
            color: var(--neon);
            font-size: 0.9rem;
            font-weight: bold;
            font-family: 'Syncopate', sans-serif;
        }

        /* Homepage Shop CTA (replaces pagination controls) */
        .shop-cta-wrap {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
            flex-wrap: wrap;
        }

        .shop-cta-btn {
            padding: 12px 24px;
            border: 1px solid var(--neon);
            text-decoration: none;
            color: var(--neon);
            font-family: 'Space Mono', monospace;
            font-size: 0.8rem;
            transition: all 0.3s ease;
            min-height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 3px;
            font-weight: bold;
            letter-spacing: 2px;
            background: rgba(0, 255, 157, 0.08);
        }

        .shop-cta-btn:hover,
        .shop-cta-btn:focus-visible {
            background: var(--neon);
            color: #000;
            border-color: var(--neon);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 255, 157, 0.2);
            outline: none;
        }

        .shipping-note {
            width: 100%;
            text-align: center;
            color: var(--text-dim);
            font-family: 'Space Mono', monospace;
            font-size: 0.62rem;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        .customer-photos-section {
            margin: 60px 0 40px;
            padding: 0 20px;
        }
        .customer-photos-title {
            text-align: center;
            font-family: 'Space Mono', monospace;
            font-size: 0.78rem;
            letter-spacing: 3px;
            color: var(--accent-2, #28e0b9);
            margin-bottom: 22px;
            text-transform: uppercase;
        }
        .customer-photos-strip {
            display: flex;
            gap: 14px;
            overflow-x: auto;
            padding-bottom: 10px;
            -webkit-overflow-scrolling: touch;
            scroll-snap-type: x proximity;
        }
        .customer-photo-item {
            flex-shrink: 0;
            width: 180px;
            scroll-snap-align: start;
        }
        .customer-photo-item img {
            width: 180px;
            height: 220px;
            object-fit: cover;
            border-radius: 10px;
            display: block;
            border: 1px solid rgba(148, 163, 184, 0.2);
        }
        .customer-photo-handle {
            margin-top: 6px;
            font-family: 'Space Mono', monospace;
            font-size: 0.66rem;
            color: var(--text-dim);
            text-align: center;
        }
        @media (max-width: 640px) {
            .customer-photo-item, .customer-photo-item img { width: 140px; }
            .customer-photo-item img { height: 175px; }
        }

        .seo-copy-block {
            margin-top: 28px;
            border-top: 1px solid var(--border);
            padding-top: 20px;
        }

        .seo-copy-block p {
            max-width: 72ch;
            margin: 0 auto;
            color: var(--text-dim);
            font-family: 'Space Mono', monospace;
            font-size: 0.66rem;
            letter-spacing: 1.2px;
            line-height: 1.8;
            text-transform: uppercase;
        }

        /* Loading Spinner */
        .loading-spinner {
            grid-column: 1 / -1;
            text-align: center;
            padding: 80px;
            font-family: 'Space Mono', monospace;
            color: var(--neon);
            letter-spacing: 3px;
            position: relative;
        }

        .loading-spinner::after {
            content: '';
            display: block;
            width: 40px;
            height: 40px;
            margin: 20px auto;
            border: 3px solid rgba(0, 255, 157, 0.1);
            border-top-color: var(--neon);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Footer */
        footer {
            padding: 60px 10% 40px;
            border-top: 1px solid var(--border);
            font-family: 'Space Mono', monospace;
            font-size: 0.7rem;
            color: var(--text-dim);
            letter-spacing: 2px;
            background: rgba(5, 5, 5, 0.9);
        }

        .footer-logo-wrap {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
        }
        .footer-logo-img {
            height: 90px;
            width: auto;
            opacity: 0.92;
        }

        .footer-links {
            display: flex;
            justify-content: center;
            gap: 40px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        .footer-links a {
            color: var(--text-dim);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer-links a:hover {
            color: var(--neon);
        }

        .footer-info {
            text-align: center;
            margin-top: 30px;
            padding-top: 30px;
            border-top: 1px solid var(--border);
        }

        .trust-social {
            margin-top: 20px;
            padding: 24px 0;
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }

        .trust-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
        }

        .trust-item {
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 3px;
            background: rgba(0, 255, 157, 0.03);
            font-size: 0.62rem;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            line-height: 1.5;
            color: var(--text-dim);
        }

        .trust-item strong {
            color: var(--neon);
            display: block;
            font-size: 0.7rem;
            letter-spacing: 2px;
            margin-bottom: 6px;
        }

        .social-line {
            margin-top: 18px;
            text-align: center;
            font-size: 0.62rem;
            letter-spacing: 2px;
        }

        .social-line a {
            color: var(--neon);
            text-decoration: none;
            margin: 0 8px;
        }

        .social-line a:hover {
            color: var(--text);
        }

        .newsletter-wrap {
            margin-top: 18px;
            padding: 16px 14px;
            border: 1px solid var(--border);
            border-radius: 3px;
            background: rgba(0, 255, 157, 0.03);
        }

        .newsletter-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 6px;
        }

        .newsletter-privacy-btn {
            min-height: 34px;
            border: 1px solid var(--border);
            background: rgba(0, 0, 0, 0.4);
            color: var(--text-dim);
            border-radius: 3px;
            font-family: 'Space Mono', monospace;
            font-size: 0.58rem;
            letter-spacing: 1.2px;
            padding: 0 10px;
            cursor: pointer;
            text-transform: uppercase;
        }

        .newsletter-privacy-btn:hover,
        .newsletter-privacy-btn:focus-visible {
            color: var(--neon);
            border-color: var(--neon);
            outline: none;
        }

        .newsletter-data-hidden {
            display: none;
        }

        .newsletter-event-card {
            display: block;
            text-decoration: none;
            border: 1px solid var(--border);
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 12px;
            background: rgba(0, 0, 0, 0.45);
            transition: border-color 0.25s ease, transform 0.25s ease;
        }

        .newsletter-event-card:hover,
        .newsletter-event-card:focus-visible {
            border-color: var(--neon);
            transform: translateY(-2px);
            outline: none;
        }

        .newsletter-event-card img {
            width: 100%;
            height: 170px;
            object-fit: cover;
            display: block;
        }

        .newsletter-event-meta {
            padding: 10px;
            border-top: 1px solid var(--border);
        }

        .newsletter-event-title {
            display: block;
            color: var(--neon);
            font-size: 0.62rem;
            letter-spacing: 1.6px;
            margin-bottom: 5px;
        }

        .newsletter-event-copy {
            display: block;
            color: var(--text-dim);
            font-size: 0.58rem;
            letter-spacing: 1.1px;
            text-transform: uppercase;
            line-height: 1.5;
        }

        .newsletter-title {
            display: block;
            color: var(--neon);
            font-family: 'Space Mono', monospace;
            font-size: 0.66rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .newsletter-copy {
            font-size: 0.62rem;
            letter-spacing: 1.2px;
            color: var(--text-dim);
            margin-bottom: 12px;
            line-height: 1.5;
        }

        .newsletter-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 10px;
            align-items: center;
        }

        .newsletter-email {
            width: 100%;
            min-height: 42px;
            border: 1px solid var(--border);
            border-radius: 3px;
            background: rgba(0, 0, 0, 0.52);
            color: var(--text);
            font-family: 'Space Mono', monospace;
            font-size: 0.72rem;
            letter-spacing: 1px;
            padding: 10px 12px;
        }

        .newsletter-email:focus-visible {
            outline: 2px solid var(--neon);
            outline-offset: 2px;
            border-color: var(--neon);
        }

        .newsletter-btn {
            min-height: 42px;
            border: 1px solid var(--neon);
            background: rgba(0, 255, 157, 0.08);
            color: var(--neon);
            border-radius: 3px;
            font-family: 'Space Mono', monospace;
            font-size: 0.7rem;
            letter-spacing: 1.8px;
            padding: 0 14px;
            cursor: pointer;
            transition: all 0.25s ease;
        }

        .newsletter-btn:hover,
        .newsletter-btn:focus-visible {
            background: var(--neon);
            color: #000;
            outline: none;
        }

        .newsletter-btn[disabled] {
            opacity: 0.6;
            cursor: wait;
        }

        .newsletter-options {
            margin-top: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 0.58rem;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: var(--text-dim);
        }

        .newsletter-options label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
        }

        .newsletter-options input[type="checkbox"] {
            accent-color: var(--neon);
        }

        .newsletter-consent {
            margin-top: 10px;
            font-size: 0.58rem;
            letter-spacing: 1.1px;
            text-transform: uppercase;
            color: var(--text-dim);
            line-height: 1.5;
        }

        .newsletter-consent label {
            display: inline-flex;
            align-items: flex-start;
            gap: 8px;
            cursor: pointer;
        }

        .newsletter-consent a {
            color: var(--neon);
            text-decoration: none;
        }

        .newsletter-consent a:hover,
        .newsletter-consent a:focus-visible {
            color: var(--text);
            outline: none;
        }

        .newsletter-subscribed-card {
            margin-top: 8px;
            border: 1px solid var(--neon);
            border-radius: 3px;
            background: rgba(0, 255, 157, 0.08);
            padding: 12px;
        }

        .newsletter-subscribed-title {
            color: var(--neon);
            font-family: 'Space Mono', monospace;
            font-size: 0.68rem;
            letter-spacing: 1.8px;
            margin-bottom: 6px;
            display: block;
        }

        .newsletter-subscribed-copy {
            color: var(--text-dim);
            font-size: 0.6rem;
            letter-spacing: 1.1px;
            text-transform: uppercase;
            line-height: 1.5;
        }

        .newsletter-manage-link {
            display: inline-block;
            margin-top: 10px;
            color: var(--neon);
            text-decoration: none;
            border: 1px solid var(--border);
            border-radius: 3px;
            padding: 6px 10px;
            font-size: 0.58rem;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        .newsletter-manage-link:hover,
        .newsletter-manage-link:focus-visible {
            border-color: var(--neon);
            color: #000;
            background: var(--neon);
            outline: none;
        }

        .newsletter-msg {
            margin-top: 10px;
            min-height: 16px;
            font-size: 0.58rem;
            letter-spacing: 1.2px;
            color: var(--text-dim);
            text-transform: uppercase;
        }

        .newsletter-msg.ok {
            color: var(--success);
        }

        .newsletter-msg.err {
            color: var(--error);
        }

        .system-status {
            color: var(--neon);
            font-weight: bold;
        }

        .cookie-settings-btn {
            margin-top: 12px;
            min-height: 36px;
            border: 1px solid var(--border);
            background: rgba(0, 0, 0, 0.45);
            color: var(--text-dim);
            border-radius: 3px;
            font-family: 'Space Mono', monospace;
            font-size: 0.58rem;
            letter-spacing: 1.2px;
            padding: 0 12px;
            text-transform: uppercase;
            cursor: pointer;
        }

        .cookie-settings-btn:hover,
        .cookie-settings-btn:focus-visible {
            color: var(--neon);
            border-color: var(--neon);
            outline: none;
        }

        .cookie-consent {
            position: fixed;
            left: 16px;
            right: 16px;
            bottom: 16px;
            z-index: 1200;
            border: 1px solid var(--border);
            border-left: 3px solid var(--neon);
            background: rgba(5, 5, 5, 0.96);
            backdrop-filter: blur(6px);
            padding: 14px;
            max-width: 960px;
            margin: 0 auto;
            display: none;
        }

        .cookie-consent.open {
            display: block;
        }

        .cookie-consent-title {
            color: var(--neon);
            font-size: 0.68rem;
            letter-spacing: 2px;
            margin-bottom: 8px;
            font-family: 'Space Mono', monospace;
            display: block;
            text-transform: uppercase;
        }

        .cookie-consent-copy {
            color: var(--text-dim);
            font-size: 0.62rem;
            letter-spacing: 1.2px;
            line-height: 1.6;
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        .cookie-consent-copy a {
            color: var(--neon);
            text-decoration: none;
        }

        .cookie-consent-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .cookie-btn {
            min-height: 36px;
            border: 1px solid var(--border);
            background: rgba(0, 0, 0, 0.45);
            color: var(--text);
            border-radius: 3px;
            padding: 0 12px;
            font-family: 'Space Mono', monospace;
            font-size: 0.58rem;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            cursor: pointer;
        }

        .cookie-btn.primary {
            border-color: var(--neon);
            color: var(--neon);
        }

        .cookie-btn:hover,
        .cookie-btn:focus-visible {
            border-color: var(--neon);
            color: var(--neon);
            outline: none;
        }

        .cookie-prefs {
            margin-top: 12px;
            border-top: 1px solid var(--border);
            padding-top: 10px;
            display: none;
        }

        .cookie-prefs.open {
            display: block;
        }

        .cookie-prefs label {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            color: var(--text-dim);
            font-size: 0.58rem;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 7px;
        }

        .cookie-prefs input[type="checkbox"] {
            accent-color: var(--neon);
            margin-top: 2px;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .hero-section,
            .lookbook-section,
            footer {
                padding-left: 5%;
                padding-right: 5%;
            }

            .transmission-reel {
                padding: 0 5%;
                gap: 25px;
            }

            .transmission-frame {
                width: 280px;
                height: 400px;
            }
        }

        @media (max-width: 992px) {
            .hero-section {
                height: 80vh;
                min-height: 600px;
            }

            .hero-title {
                font-size: clamp(3rem, 8vw, 5rem);
                letter-spacing: 5px;
            }

            .product-grid {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
                gap: 30px;
            }

            .transmission-frame {
                width: 240px;
                height: 340px;
            }

            header {
                flex-wrap: wrap;
                gap: 10px;
            }

            .nav-toggle {
                display: inline-flex;
                margin-left: auto;
            }

            .nav-right {
                display: none;
                width: 100%;
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
                border-top: 1px solid var(--border);
                padding-top: 12px;
                max-height: calc(100vh - 140px);
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
                overscroll-behavior: contain;
                padding-bottom: 12px;
            }

            .nav-right.open {
                display: flex;
            }

            .nav-link,
            .theme-toggle {
                width: 100%;
                padding: 12px 14px;
                border: 1px solid var(--border);
                border-radius: 3px;
                justify-content: flex-start;
            }

            .nav-link::after {
                display: none;
            }

            .cart-count {
                position: static;
                margin-left: 8px;
                top: auto;
                right: auto;
            }
        }

        @media (max-width: 768px) {
            header {
                padding: 15px 20px;
            }

            .nav-right {
                gap: 20px;
            }

            .hero-section {
                height: 70vh;
                min-height: 430px;
                margin-top: 70px;
            }

            .hero-bg-video {
                opacity: 0.16;
                filter: grayscale(100%) contrast(100%) brightness(0.55);
            }

            .hero-title {
                font-size: clamp(2.5rem, 7vw, 4rem);
                letter-spacing: 3px;
            }

            .access-btn {
                padding: 15px 35px;
                font-size: 0.75rem;
            }

            .transmission-reel {
                gap: 14px;
                animation: reel-scroll-mobile 95s linear infinite;
                padding: 0 14px;
            }

            .transmission-frame {
                width: min(62vw, 210px);
                height: min(84vw, 280px);
            }

            .transmission-strip {
                padding: 34px 0;
            }

            .transmission-header {
                margin-bottom: 24px;
                font-size: 0.72rem;
                letter-spacing: 2px;
            }

            .transmission-header::before,
            .transmission-header::after {
                display: none;
            }

            .frame-caption {
                font-size: 0.65rem;
                padding: 10px;
                bottom: 15px;
                left: 15px;
                right: 15px;
            }

            .lookbook-section {
                padding: 60px 5% 80px;
            }

            .product-grid {
                grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
                gap: 25px;
            }

            .collection-showcase {
                grid-template-columns: 1fr;
                gap: 14px;
                margin-top: -8px;
                margin-bottom: 34px;
            }

            .preview-controls {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .card-image {
                height: 300px;
            }

            .trust-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .newsletter-form {
                grid-template-columns: 1fr;
            }

            .footer-links {
                gap: 20px;
            }
        }

        @media (max-width: 576px) {
            .hero-section {
                height: 60vh;
                min-height: 390px;
                padding: 0 20px;
            }

            .hero-bg-video {
                display: none;
            }

            .hero-title {
                font-size: clamp(2rem, 6vw, 3rem);
            }

            .hero-subtext {
                font-size: 0.8rem;
                letter-spacing: 2px;
            }

            .transmission-header {
                font-size: 0.8rem;
                letter-spacing: 3px;
            }

            .transmission-frame {
                width: 180px;
                height: 260px;
            }

            .nav-group {
                justify-content: center;
            }

            .nav-tab {
                padding: 10px 20px;
                font-size: 0.65rem;
            }

            .product-grid {
                grid-template-columns: 1fr;
                gap: 30px;
            }

            .shop-cta-wrap {
                gap: 10px;
            }

            .shop-cta-btn {
                padding: 10px 16px;
                font-size: 0.75rem;
                min-height: 44px;
            }

            footer {
                padding: 40px 20px 30px;
            }
            .footer-logo-img {
                height: 64px;
            }

            .collection-tile {
                min-height: 128px;
                padding: 18px 16px;
            }

            .collection-title {
                font-size: 0.86rem;
            }
        }

        @media (max-width: 480px) {
            .hero-title {
                font-size: clamp(1.8rem, 5vw, 2.5rem);
            }

            .view-logs-frame span {
                font-size: 1.2rem;
                letter-spacing: 3px;
            }

            .footer-links {
                flex-direction: column;
                gap: 15px;
                align-items: center;
            }
        }

        /* Reduced Motion */
        @media (prefers-reduced-motion: reduce) {
            .transmission-reel,
            .access-btn,
            .product-card,
            .reveal-item,
            .stock-low,
            .hero-bg-video,
            .view-logs-frame::before,
            body.tech-archive::after,
            .hero-title::before {
                animation: none !important;
                transition: none !important;
            }

            .reveal-item {
                opacity: 1;
                transform: none;
            }
        }

        /* High Contrast Mode */
        @media (prefers-contrast: high) {
            :root {
                --neon: #00ff00;
                --text: #fff;
                --border: #fff;
                --grid-color: rgba(0, 255, 0, 0.3);
            }

            .transmission-frame,
            .product-card .card-image,
            .page-link,
            .nav-tab {
                border-width: 2px;
            }
        }

    </style>
<script src="/js/telemetry.js" defer></script>
<script src="/js/search.js" defer></script>
</head>

<body class="tech-archive" data-bg="grid">
<?php @include_once __DIR__ . '/includes/bg_styles.php'; ?>
    <!-- Skip to Main Content -->
    <a href="#main-content" class="skip-link" tabindex="1">
        SKIP TO MAIN CONTENT
    </a>

    <!-- Header - FIXED NAVIGATION -->
    <header>
        <a href="/" class="logo">
            <img src="/images/logo-header.png" alt="Diamonds Outta Dirt" class="logo-img">
            <span class="logo-text">DIAMONDS OUTTA DIRT</span>
        </a>
        <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="primaryNav" aria-label="Toggle navigation">
            MENU
        </button>
        <div class="nav-right" id="primaryNav">
            <button class="theme-toggle" type="button" data-bg-toggle aria-label="Cycle background style">
                BG: GRID
            </button>
            <a href="/about" class="nav-link">ABOUT</a>
            <a href="/services" class="nav-link">SERVICES</a>
            <a href="/lookbook" class="nav-link">LOOKBOOK</a>
            <!-- Link to 3D shop experience -->
            <a href="/shop3d" class="nav-link">SHOP_3D</a>
            <a href="/arcade" class="nav-link">ARCADE</a>
            <!-- Link to shop section on current page -->
           <a href="/shop" class="nav-link">SHOP</a>
            <a href="/exchange" class="nav-link">EXCHANGE</a>
            <?php if (!empty($_SESSION['customer_id'])): ?>
                <a href="/account" class="nav-link">ACCOUNT</a>
            <?php else: ?>
                <a href="/account/login" class="nav-link">SIGN IN</a>
            <?php endif; ?>
            <!-- Link to terms page (exists) -->
            <a href="/terms-of-service" class="nav-link">TERMS</a>
            <!-- Link to privacy page (exists) -->
            <a href="/privacy-policy" class="nav-link">PRIVACY</a>
            <!-- Link to cart page -->
            <a href="/cart" class="nav-link cart-icon">
                CART <span class="cart-count"><?= $totalItems ?></span>
            </a>
        </div>
    </header>

    <main id="main-content" role="main" tabindex="-1">
        <!-- Hero Section -->
        <section class="hero-section" aria-label="Hero banner with video background">
            <video autoplay muted loop playsinline class="hero-bg-video"
                   preload="metadata"
                   poster="/images/video-poster.jpg"
                   aria-hidden="true">
                <source src="/assets/videos/hero-visual.mp4" type="video/mp4">
                Your browser does not support the video tag.
            </video>

            <div class="video-overlay" aria-hidden="true"></div>

            <div class="hero-content">
                <h1 class="hero-title">RAW<br>REFINED</h1>

                <div class="hero-subtext">
                    <p>TRANSFORMING PRESSURE INTO CLARITY.</p>
                    <p class="system-text">
                        [SYSTEM_UPDATE_<?= h(VERSION) ?>]<br>
                        FIELD_REPORTS_LOADED: <?= $fieldReportsCount ?> // SCHEMA: <?= strtoupper($schemaVersion) ?>
                    </p>
                </div>

             <a href="/shop" class="access-btn" aria-label="Access product archive">
    ACCESS_ARCHIVE
</a>


                <p class="hero-display-stats">
                    DISPLAYING <?= $visibleProducts ?> OF <?= $totalProductsFiltered ?> ITEMS
                    // STATUS: <?= $systemStatus ?>
                </p>
            </div>
        </section>

        <!-- Transmission Strip -->
        <div class="transmission-strip" role="region" aria-label="Live field reports gallery">
            <div class="transmission-header">
                FIELD_REPORTS // LIVE_FEED_<?= h(VERSION) ?>
            </div>

            <div class="transmission-reel">
                <?php foreach ($fieldReports as $index => $report): ?>
                    <div class="transmission-frame"
                         data-index="<?= $index ?>"
                         data-type="<?= h($report['type']) ?>"
                         data-recent="<?= $report['is_recent'] ? 'true' : 'false' ?>">
                        <?php if ($report['type'] === 'video'): ?>
                            <video autoplay muted loop playsinline
                                   aria-label="<?= h($report['caption']) ?>"
                                   role="img">
                                <source src="<?= h($report['src']) ?>" type="video/mp4">
                            </video>
                        <?php else: ?>
                            <?php $webp = getWebpIfExists($report['src']); ?>
                            <picture>
                                <?php if ($webp): ?>
                                    <source srcset="<?= h($webp) ?>" type="image/webp">
                                <?php endif; ?>
                                <img src="<?= h($report['src']) ?>"
                                     alt="<?= h($report['caption']) ?>"
                                     loading="lazy"
                                     decoding="async"
                                     width="320"
                                     height="450"
                                     onerror="this.onerror=null; this.src='/images/placeholder.jpg'">
                            </picture>
                        <?php endif; ?>

                        <div class="frame-caption"><?= h($report['caption']) ?></div>

                        <?php if ($report['is_recent']): ?>
                            <div style="position: absolute; top: 15px; left: 15px; background: var(--cyber-pink); color: #000; padding: 4px 8px; font-size: 0.5rem; font-family: 'Space Mono'; font-weight: bold; border-radius: 3px; z-index: 2;">
                                NEW
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <!-- View Logs Link -->
                <a href="/shop" class="transmission-frame view-logs-frame"
   aria-label="View full shop archive">

                    <span>VIEW<br>FULL<br>SHOP<span class="arrow" aria-hidden="true">>></span></span>
                </a>
            </div>
        </div>

        <!-- Product Grid Section -->
        <section id="shop" class="lookbook-section" aria-label="Product inventory">
            <div class="section-tag">
                [[ INVENTORY // LIVE_DATABASE_<?= h(VERSION) ?> ]]
            </div>

            <div class="collection-showcase" aria-label="Curated collections">
                <a class="collection-tile" href="/shop?category=outerwear" aria-label="Shop outerwear collection">
                    <span class="collection-label">CURATED SET 01</span>
                    <span class="collection-title">OUTERWEAR</span>
                    <span class="collection-copy">Structured layers for weather shifts, long nights, and high-contrast silhouettes.</span>
                    <span class="collection-arrow">ENTER NODE_01 -></span>
                </a>
                <a class="collection-tile" href="/shop?category=accessories" aria-label="Shop accessories collection">
                    <span class="collection-label">CURATED SET 02</span>
                    <span class="collection-title">ACCESSORIES</span>
                    <span class="collection-copy">Utility-first details, hardware accents, and finishing pieces for any fit.</span>
                    <span class="collection-arrow">ENTER NODE_02 -></span>
                </a>
                <a class="collection-tile" href="/shop?sort=newest" aria-label="Shop new drops">
                    <span class="collection-label">CURATED SET 03</span>
                    <span class="collection-title">NEW DROPS</span>
                    <span class="collection-copy">Fresh releases from the latest cycle. Updated frequently as inventory lands.</span>
                    <span class="collection-arrow">ENTER NODE_03 -></span>
                </a>
            </div>

            <div class="preview-controls" aria-label="Filter and sort preview products">
                <input
                    type="search"
                    id="previewSearch"
                    class="preview-search"
                    placeholder="SEARCH PREVIEW PRODUCTS"
                    aria-label="Search preview products">
                <select id="previewSort" class="preview-sort" aria-label="Sort preview products">
                    <option value="newest">Sort: Newest</option>
                    <option value="price_asc">Sort: Price Low-High</option>
                    <option value="featured">Sort: Featured First</option>
                </select>
            </div>

            <div class="newsletter-wrap" aria-label="Newsletter signup">
                <div class="newsletter-head">
                    <strong class="newsletter-title">DROP ALERTS // EVENT SIGNAL</strong>
                    <button type="button" class="newsletter-privacy-btn" id="newsletterDataToggle" aria-expanded="true" aria-controls="newsletterDataPanel">
                        HIDE DATA
                    </button>
                </div>
                <div id="newsletterDataPanel">
                    <p class="newsletter-copy">Get notified when new drops land and when events go live. Get first access to drops + event codes.</p>
                    <?php if (is_array($newsletterEventPost)): ?>
                        <a class="newsletter-event-card" href="<?= h((string)$newsletterEventPost['target_url']) ?>" aria-label="Open event update">
                            <img
                                src="<?= h((string)$newsletterEventPost['image_url']) ?>"
                                alt="<?= h((string)$newsletterEventPost['title']) ?>"
                                loading="lazy"
                                decoding="async">
                            <span class="newsletter-event-meta">
                                <strong class="newsletter-event-title"><?= h((string)$newsletterEventPost['title']) ?></strong>
                                <span class="newsletter-event-copy"><?= h((string)($newsletterEventPost['excerpt'] !== '' ? $newsletterEventPost['excerpt'] : 'Tap to open event details.')) ?></span>
                            </span>
                        </a>
                    <?php endif; ?>
                    <form class="newsletter-form" id="newsletterForm" novalidate>
                        <input
                            type="email"
                            class="newsletter-email"
                            id="newsletterEmail"
                            name="email"
                            autocomplete="email"
                            inputmode="email"
                            required
                            maxlength="190"
                            placeholder="ENTER YOUR EMAIL"
                            aria-label="Email address">
                        <button type="submit" class="newsletter-btn" id="newsletterSubmit">SUBSCRIBE</button>
                        <div class="newsletter-options">
                            <label><input type="checkbox" id="newsletterDrops" checked> New Drops</label>
                            <label><input type="checkbox" id="newsletterEvents" checked> Events</label>
                        </div>
                        <div class="newsletter-consent">
                            <label>
                                <input type="checkbox" id="newsletterConsent" required>
                                <span>I agree to receive marketing emails and accept the <a href="/privacy-policy" target="_blank" rel="noopener noreferrer">privacy policy</a>.</span>
                            </label>
                        </div>
                        <div class="newsletter-msg" id="newsletterMsg" aria-live="polite"></div>
                    </form>
                </div>
            </div>

            <!-- Product Grid -->
            <div class="product-grid" id="main-grid" role="region" aria-label="Product listings">
                <?php if (empty($products)): ?>
                    <div class="loading-spinner">
                        NO_PRODUCTS_FOUND // DATABASE_EMPTY
                        <br>
                        <span style="font-size:0.6rem;color:var(--text-dim);">
                            Try a different category or check back later.
                        </span>
                    </div>
                <?php else: ?>
                    <?php foreach ($products as $product):
                        $id = (int)($product['id'] ?? 0);
                        $slugRaw = isset($product['slug']) ? (string)$product['slug'] : '';
                        $productUrl = getProductUrl($id, $slugRaw);

                        $imgSrc = getPreviewProductImage((string)($product['image_url'] ?? ''));
                        $price = formatPrice((float)($product['price'] ?? 0));
                        $name = strtoupper(h((string)($product['name'] ?? '')));
                        $category = strtoupper(h((string)($product['category'] ?? '')));
                        $stock = (int)($product['stock'] ?? 0);
                        $isLowStock = ($stock > 0 && $stock <= 5);
                        $isFeatured = !empty($product['featured']) && $product['featured'] == 1;
                        $description = h((string)($product['description'] ?? ''));
                    ?>
                    <div class="reveal-item"
                         data-item-category="<?= $category ?>"
                         data-item-id="<?= $id ?>"
                         data-item-stock="<?= $stock ?>"
                         data-item-featured="<?= $isFeatured ? 'true' : 'false' ?>"
                         data-item-name="<?= h(strtolower((string)($product['name'] ?? '')), 'attr') ?>"
                         data-item-price="<?= (float)($product['price'] ?? 0) ?>"
                         data-item-created="<?= h((string)($product['created_at'] ?? ''), 'attr') ?>">
                        <a href="<?= h($productUrl) ?>" class="product-card"
                           aria-label="<?= $name ?>, Price $<?= $price ?>, <?= $stock ?> in stock, <?= $description ?>">
                            <div class="card-image">
                                <?php if ($isLowStock): ?>
                                    <div class="stock-low" aria-hidden="true">
                                        LOW_STOCK (<?= $stock ?>)
                                    </div>
                                <?php endif; ?>

                                <?php if ($isFeatured): ?>
                                    <div class="featured-badge" aria-hidden="true">
                                        FEATURED
                                    </div>
                                <?php endif; ?>

                                <?php $webp = getWebpIfExists($imgSrc); ?>
                                <picture>
                                    <?php if ($webp): ?>
                                        <source srcset="<?= h($webp) ?>" type="image/webp">
                                    <?php endif; ?>
                                    <img src="<?= h($imgSrc) ?>"
                                         alt="<?= $name ?>"
                                         loading="lazy"
                                         decoding="async"
                                         width="280"
                                         height="350"
                                         onerror="this.onerror=null; this.src='/images/placeholder.jpg'">
                                </picture>
                            </div>

                            <div class="card-info">
                                <div class="card-title">
                                    <?= $name ?>
                                </div>
                                <div class="card-price">
                                    $<?= $price ?>
                                </div>
                            </div>
                        </a>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Shop CTA -->
            <div class="shop-cta-wrap" aria-label="View full shop">
                <a href="/shop" class="shop-cta-btn" aria-label="Go to full shop page">
                    SHOP NOW
                </a>
                <p class="shipping-note">Ships in 3-5 business days // All sales final // myshineisnow@diamondsouttadirt.com</p>
            </div>

            <?php
                $customerPhotos = [];
                if (isset($pdo) && $pdo instanceof PDO) {
                    try {
                        $customerPhotos = $pdo->query("SELECT * FROM customer_photos WHERE is_active = 1 ORDER BY sort_order ASC, id DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    } catch (Throwable $e) { /* table may not exist yet, skip gracefully */ }
                }
            ?>
            <?php if (!empty($customerPhotos)): ?>
            <section class="customer-photos-section" aria-label="Customer photos">
                <h2 class="customer-photos-title">AS WORN BY YOU</h2>
                <div class="customer-photos-strip">
                    <?php foreach ($customerPhotos as $cp): ?>
                        <div class="customer-photo-item">
                            <img src="<?= h((string)$cp['image_url']) ?>" alt="<?= h((string)($cp['caption'] ?? 'Customer photo')) ?>" loading="lazy">
                            <?php if (!empty($cp['customer_handle'])): ?>
                                <div class="customer-photo-handle"><?= h((string)$cp['customer_handle']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <div class="seo-copy-block" aria-label="Brand and category overview">
                <p>
                    Diamonds Outta Dirt curates cyberpunk streetwear and wearable art that blends utility, texture, and statement silhouettes.
                    Our live archive highlights limited drops across outerwear, accessories, dresses, tops, tank tops, bottoms, and activewear so you can track what is in stock in real time.
                    Every release is built for movement, night scenes, and layered styling, with featured picks surfaced first for faster discovery.
                    We update this storefront frequently with new arrivals, event-linked collections, and community-tested essentials, so returning visitors can find fresh pieces and classic staples in one place.
                    Explore the preview, then enter the full shop archive for complete product details, availability, and checkout.
                </p>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer>
        <div class="footer-logo-wrap">
            <img src="/images/logo-footer.png" alt="Diamonds Outta Dirt" class="footer-logo-img">
        </div>

        <div class="footer-links">
            <a href="/">HOME</a>
            <a href="/about">ABOUT</a>
            <a href="/services">SERVICES</a>
            <a href="/lookbook">LOOKBOOK</a>
            <a href="/shop3d">SHOP_3D</a>
            <a href="/arcade">ARCADE</a>
            <a href="/shop">SHOP</a>
            <a href="/exchange">EXCHANGE</a>
            <a href="/terms-of-service">TERMS</a>
            <a href="/privacy-policy">PRIVACY</a>
            <a href="/cookie-policy">COOKIES</a>
            <a href="/cart">CART</a>
        </div>

        <div class="trust-social" aria-label="Trust and community">
            <div class="trust-grid">
                <div class="trust-item">
                    <strong>SECURE CHECKOUT</strong>
                    encrypted checkout flow with verified payment processing.
                </div>
                <div class="trust-item">
                    <strong>FAST FULFILLMENT</strong>
                    tracking sent on dispatch and responsive support for every order.
                </div>
                <div class="trust-item">
                    <strong>FIELD-TESTED</strong>
                    community-backed drops with repeat buyers and real-world wear.
                </div>
            </div>
            <div class="social-line">
                SOCIAL SIGNAL:
                <a href="https://instagram.com" target="_blank" rel="noopener noreferrer">INSTAGRAM</a> //
                <a href="https://tiktok.com" target="_blank" rel="noopener noreferrer">TIKTOK</a> //
                <a href="https://x.com" target="_blank" rel="noopener noreferrer">X</a>
            </div>
        </div>

        <div class="footer-info">
            <p>© <?= date('Y') ?> DIAMONDS OUTTA DIRT // ARCHIVE <?= h(VERSION) ?></p>
            <p style="margin-top: 15px; font-size: 0.6rem; color: #666;">
                SYSTEM STATUS: <span class="system-status"><?= $systemStatus ?></span> //
                PRODUCTS LOADED: <?= $visibleProducts ?> //
                FIELD REPORTS: <?= $fieldReportsCount ?>
                <?php if (!$dbConnected && $dbError): ?>
                    <br><span style="color: var(--error);">DATABASE ERROR: <?= h(substr($dbError, 0, 100)) ?></span>
                <?php endif; ?>
            </p>
            <button type="button" id="cookieSettingsBtn" class="cookie-settings-btn">COOKIE SETTINGS</button>
        </div>
    </footer>

    <div class="cookie-consent" id="cookieConsent" role="dialog" aria-live="polite" aria-label="Cookie consent">
        <strong class="cookie-consent-title">COOKIE NOTICE</strong>
        <div class="cookie-consent-copy">
            We use essential cookies for site operation. Analytics and marketing cookies are optional.
            See our <a href="/cookie-policy">cookie policy</a>.
        </div>
        <div class="cookie-consent-actions">
            <button type="button" class="cookie-btn primary" id="cookieAcceptAll">ACCEPT ALL</button>
            <button type="button" class="cookie-btn" id="cookieReject">REJECT OPTIONAL</button>
            <button type="button" class="cookie-btn" id="cookieCustomize">CUSTOMIZE</button>
            <button type="button" class="cookie-btn" id="cookieSavePrefs" style="display:none;">SAVE PREFERENCES</button>
        </div>
        <div class="cookie-prefs" id="cookiePrefsPanel">
            <label>
                <input type="checkbox" checked disabled>
                <span>Essential cookies (always on)</span>
            </label>
            <label>
                <input type="checkbox" id="cookieAnalytics">
                <span>Analytics cookies (traffic + usage insights)</span>
            </label>
            <label>
                <input type="checkbox" id="cookieMarketing">
                <span>Marketing cookies (ad/retargeting pixels)</span>
            </label>
        </div>
    </div>

    <!-- Enhanced JavaScript -->
    <script>
    /* ============================================================================
       ENHANCED STOREFRONT LOGIC - v4.4
       ============================================================================ */

    (function() {
        'use strict';

        // --- CONFIGURATION ---
        const config = {
            version: '<?= h(VERSION) ?>',
            totalProducts: <?= (int)$totalProductsFiltered ?>,
            currentCategory: '<?= h($selectedCategory, 'js') ?>',
            currentPage: <?= (int)$page ?>,
            systemStatus: '<?= h($systemStatus, 'js') ?>',
            backgroundOptions: ['grid', 'gradient', 'dark'],
            analytics: {
                ga4MeasurementId: '',
                metaPixelId: '',
                endpoint: '/api/track_event.php',
            },
            consentStorageKey: 'dod-cookie-consent-v1',
        };

        // --- STATE MANAGEMENT ---
        const state = {
            isDragging: false,
            startX: 0,
            scrollLeft: 0,
            loadedImages: new Set(),
            intersectionObservers: new Map(),
            backgroundIndex: 0,
            consent: {
                decided: false,
                essential: true,
                analytics: false,
                marketing: false,
                ts: '',
                source: '',
            },
        };

        // --- UTILITY FUNCTIONS ---
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        function nowIso() {
            return new Date().toISOString();
        }

        function defaultConsent() {
            return {
                decided: false,
                essential: true,
                analytics: false,
                marketing: false,
                ts: '',
                source: '',
            };
        }

        function readConsent() {
            try {
                const raw = localStorage.getItem(config.consentStorageKey);
                if (!raw) return defaultConsent();
                const parsed = JSON.parse(raw);
                if (!parsed || typeof parsed !== 'object') return defaultConsent();
                return {
                    decided: !!parsed.decided,
                    essential: true,
                    analytics: !!parsed.analytics,
                    marketing: !!parsed.marketing,
                    ts: String(parsed.ts || ''),
                    source: String(parsed.source || ''),
                };
            } catch (_) {
                return defaultConsent();
            }
        }

        function persistConsent(nextConsent) {
            state.consent = {
                decided: !!nextConsent.decided,
                essential: true,
                analytics: !!nextConsent.analytics,
                marketing: !!nextConsent.marketing,
                ts: String(nextConsent.ts || nowIso()),
                source: String(nextConsent.source || 'banner'),
            };
            try {
                localStorage.setItem(config.consentStorageKey, JSON.stringify(state.consent));
            } catch (_) {
                // ignore
            }
        }

        function canTrackAnalytics() {
            return !!(state.consent && state.consent.analytics);
        }

        function canTrackMarketing() {
            return !!(state.consent && state.consent.marketing);
        }

        function openConsentBanner() {
            const banner = document.getElementById('cookieConsent');
            if (banner) banner.classList.add('open');
        }

        function closeConsentBanner() {
            const banner = document.getElementById('cookieConsent');
            if (banner) banner.classList.remove('open');
        }

        function getAnalyticsSessionId() {
            try {
                const key = 'dod_analytics_sid';
                let sid = sessionStorage.getItem(key);
                if (!sid) {
                    sid = 'sid_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
                    sessionStorage.setItem(key, sid);
                }
                return sid;
            } catch (_) {
                return '';
            }
        }

        function getUtmParams() {
            try {
                const p = new URLSearchParams(window.location.search || '');
                return {
                    utm_source: p.get('utm_source') || '',
                    utm_medium: p.get('utm_medium') || '',
                    utm_campaign: p.get('utm_campaign') || '',
                };
            } catch (_) {
                return { utm_source: '', utm_medium: '', utm_campaign: '' };
            }
        }

        function detectDeviceType() {
            const w = window.innerWidth || 0;
            if (w > 0 && w <= 768) return 'mobile';
            if (w > 768 && w <= 1024) return 'tablet';
            return 'desktop';
        }

        function trackEvent(eventName, payload = {}) {
            try {
                if (!canTrackAnalytics()) {
                    return;
                }
                const utm = getUtmParams();
                const commonContext = {
                    session_id: getAnalyticsSessionId(),
                    referrer: document.referrer || '',
                    device_type: detectDeviceType(),
                    viewport: `${window.innerWidth || 0}x${window.innerHeight || 0}`,
                    utm_source: utm.utm_source,
                    utm_medium: utm.utm_medium,
                    utm_campaign: utm.utm_campaign,
                };

                const eventPayload = {
                    event: eventName,
                    payload: Object.assign({}, commonContext, payload),
                    page: window.location.pathname,
                    ts: new Date().toISOString(),
                };

                if (Array.isArray(window.dataLayer)) {
                    window.dataLayer.push(eventPayload);
                }

                if (typeof window.gtag === 'function') {
                    const gtagPayload = Object.assign({}, eventPayload);
                    delete gtagPayload.event;
                    window.gtag('event', eventName, gtagPayload);
                }

                if (typeof window.fbq === 'function') {
                    if (canTrackMarketing()) {
                        window.fbq('trackCustom', eventName, eventPayload);
                        fireMetaStandardEvent(eventName, eventPayload);
                    }
                }

                if (navigator.sendBeacon) {
                    const body = JSON.stringify(eventPayload);
                    navigator.sendBeacon(config.analytics.endpoint, new Blob([body], { type: 'application/json' }));
                }
            } catch (_) {
                // no-op
            }
        }

        function fireMetaStandardEvent(eventName, payload) {
            if (typeof window.fbq !== 'function' || !canTrackMarketing()) {
                return;
            }
            if (eventName === 'add_to_cart_click') {
                window.fbq('track', 'AddToCart', payload);
                return;
            }
            if (eventName === 'newsletter_submit') {
                window.fbq('track', 'Lead', payload);
                return;
            }
            if (eventName === 'product_click') {
                window.fbq('track', 'ViewContent', payload);
            }
        }

        function setupAnalyticsProviders() {
            if (!canTrackAnalytics()) {
                return;
            }
            const gaId = String(config.analytics.ga4MeasurementId || '').trim();
            const metaPixelId = String(config.analytics.metaPixelId || '').trim();

            if (gaId !== '') {
                window.dataLayer = window.dataLayer || [];
                window.gtag = window.gtag || function gtag(){ window.dataLayer.push(arguments); };

                if (!window.__dodGaLoaded) {
                    const gaScript = document.createElement('script');
                    gaScript.async = true;
                    gaScript.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(gaId);
                    document.head.appendChild(gaScript);
                    window.gtag('js', new Date());
                    window.gtag('config', gaId, { anonymize_ip: true });
                    window.__dodGaLoaded = true;
                }
            }

            if (metaPixelId !== '' && canTrackMarketing()) {
                if (!window.fbq) {
                    (function(f,b,e,v,n,t,s){
                        if(f.fbq) return;
                        n = f.fbq = function(){ n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments); };
                        if(!f._fbq) f._fbq = n;
                        n.push = n;
                        n.loaded = true;
                        n.version = '2.0';
                        n.queue = [];
                        t = b.createElement(e);
                        t.async = true;
                        t.src = v;
                        s = b.getElementsByTagName(e)[0];
                        s.parentNode.insertBefore(t, s);
                    })(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
                }
                if (!window.__dodMetaPixelInit) {
                    window.fbq('init', metaPixelId);
                    window.fbq('track', 'PageView');
                    window.__dodMetaPixelInit = true;
                }
            }
        }

        function setupCookieConsent() {
            state.consent = readConsent();

            const banner = document.getElementById('cookieConsent');
            const openBtn = document.getElementById('cookieSettingsBtn');
            const acceptBtn = document.getElementById('cookieAcceptAll');
            const rejectBtn = document.getElementById('cookieReject');
            const customizeBtn = document.getElementById('cookieCustomize');
            const savePrefsBtn = document.getElementById('cookieSavePrefs');
            const prefsPanel = document.getElementById('cookiePrefsPanel');
            const analyticsInput = document.getElementById('cookieAnalytics');
            const marketingInput = document.getElementById('cookieMarketing');

            if (!banner || !acceptBtn || !rejectBtn || !customizeBtn || !savePrefsBtn || !prefsPanel || !analyticsInput || !marketingInput) {
                return;
            }

            const syncInputs = () => {
                analyticsInput.checked = !!state.consent.analytics;
                marketingInput.checked = !!state.consent.marketing;
            };
            syncInputs();

            if (!state.consent.decided) {
                openConsentBanner();
            } else {
                closeConsentBanner();
                setupAnalyticsProviders();
            }

            if (openBtn) {
                openBtn.addEventListener('click', () => {
                    syncInputs();
                    prefsPanel.classList.add('open');
                    savePrefsBtn.style.display = '';
                    openConsentBanner();
                });
            }

            acceptBtn.addEventListener('click', () => {
                persistConsent({
                    decided: true,
                    analytics: true,
                    marketing: true,
                    ts: nowIso(),
                    source: 'accept_all',
                });
                closeConsentBanner();
                setupAnalyticsProviders();
                trackEvent('cookie_consent_update', { analytics: 1, marketing: 1, source: 'accept_all' });
            });

            rejectBtn.addEventListener('click', () => {
                persistConsent({
                    decided: true,
                    analytics: false,
                    marketing: false,
                    ts: nowIso(),
                    source: 'reject_optional',
                });
                closeConsentBanner();
            });

            customizeBtn.addEventListener('click', () => {
                prefsPanel.classList.add('open');
                savePrefsBtn.style.display = '';
            });

            savePrefsBtn.addEventListener('click', () => {
                persistConsent({
                    decided: true,
                    analytics: !!analyticsInput.checked,
                    marketing: !!marketingInput.checked && !!analyticsInput.checked,
                    ts: nowIso(),
                    source: 'customize',
                });
                closeConsentBanner();
                setupAnalyticsProviders();
                trackEvent('cookie_consent_update', {
                    analytics: state.consent.analytics ? 1 : 0,
                    marketing: state.consent.marketing ? 1 : 0,
                    source: 'customize',
                });
            });
        }

        // --- INITIALIZATION ---
        function init() {
            console.log(`%c⚙️ ${'<?= h(SITE_NAME, 'js') ?>'} Storefront ${config.version}`,
                       'color: #00ff9d; font-size: 14px; font-weight: bold;');
            console.log(`%cStatus: ${config.systemStatus} | Products: ${config.totalProducts} | Page: ${config.currentPage}`,
                       'color: #888;');

            // Initialize cookie consent + tracking permissions.
            setupCookieConsent();

            // Select a lighter visual profile on weaker large-desktop hardware.
            setupVisualPerformanceProfile();

            // Set up mobile navigation
            setupMobileNavigation();

            // Set up category navigation
            setupCategoryNavigation();

            // Set up reveal animations
            setupRevealAnimations();

            // Set up transmission strip interaction
            setupTransmissionStrip();

            // Set up lazy loading
            setupLazyLoading();

            // Set up performance optimizations
            setupPerformanceOptimizations();

            // Set up error handling
            setupErrorHandling();

            // Set up background toggle
            setupBackgroundToggle();

            // Set up newsletter form
            setupNewsletterDataToggle();
            setupNewsletterForm();

            // Set up preview controls (search + sort)
            setupProductPreviewControls();

            // Set up engagement tracking
            setupEngagementTracking();

            // Record page view only after analytics consent.
            trackEvent('page_view', { page_type: 'homepage_preview' });

        }

        // --- MOBILE NAVIGATION ---
        function setupMobileNavigation() {
            const navToggle = document.querySelector('.nav-toggle');
            const nav = document.getElementById('primaryNav');
            if (!navToggle || !nav) {
                return;
            }

            const closeNav = () => {
                nav.classList.remove('open');
                navToggle.setAttribute('aria-expanded', 'false');
            };

            navToggle.addEventListener('click', () => {
                const willOpen = !nav.classList.contains('open');
                nav.classList.toggle('open', willOpen);
                navToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });

            nav.querySelectorAll('a').forEach((link) => {
                link.addEventListener('click', () => {
                    if (window.matchMedia('(max-width: 992px)').matches) {
                        closeNav();
                    }
                });
            });

            document.addEventListener('click', (e) => {
                if (!window.matchMedia('(max-width: 992px)').matches) {
                    return;
                }
                if (!nav.classList.contains('open')) {
                    return;
                }
                if (nav.contains(e.target) || navToggle.contains(e.target)) {
                    return;
                }
                closeNav();
            });

            window.addEventListener('resize', () => {
                if (!window.matchMedia('(max-width: 992px)').matches) {
                    closeNav();
                }
            }, { passive: true });
        }

        // --- CATEGORY NAVIGATION ---
        function setupCategoryNavigation() {
            const tabs = document.querySelectorAll('.nav-tab[href*="category="]');

            tabs.forEach((tab) => {
                // Keyboard navigation
                tab.addEventListener('keydown', function(e) {
                    // Arrow key navigation between tabs
                    if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
                        e.preventDefault();
                        const currentIndex = Array.from(tabs).indexOf(this);
                        let nextIndex;

                        if (e.key === 'ArrowRight') {
                            nextIndex = (currentIndex + 1) % tabs.length;
                        } else {
                            nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;
                        }

                        tabs[nextIndex].focus();
                    }
                });
            });
        }

        // --- REVEAL ANIMATIONS ---
        function setupRevealAnimations() {
            const items = document.querySelectorAll('.reveal-item');

            if (!('IntersectionObserver' in window)) {
                // Fallback: reveal all immediately
                items.forEach((item) => item.classList.add('revealed'));
                return;
            }

            const observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('revealed');
                        observer.unobserve(entry.target);
                    }
                });
            }, {
                threshold: 0.1,
                rootMargin: '50px',
            });

            items.forEach((item) => observer.observe(item));
        }

        // --- TRANSMISSION STRIP ---
        function setupTransmissionStrip() {
            const strip = document.querySelector('.transmission-strip');
            const reel = document.querySelector('.transmission-reel');

            if (!strip || !reel) {
                return;
            }

            // Pause animation on hover
            reel.addEventListener('mouseenter', () => {
                reel.style.animationPlayState = 'paused';
            });

            reel.addEventListener('mouseleave', () => {
                reel.style.animationPlayState = 'running';
            });

            // Touch/pointer drag support
            let isDown = false;
            let startX = 0;
            let scrollLeft = 0;
            let dragged = false;

            strip.addEventListener('pointerdown', (e) => {
                isDown = true;
                dragged = false;
                strip.style.cursor = 'grabbing';
                startX = e.pageX - strip.offsetLeft;
                scrollLeft = strip.scrollLeft;

                // Pause animation during drag
                reel.style.animationPlayState = 'paused';
            });

            strip.addEventListener('pointerleave', () => {
                isDown = false;
                strip.style.cursor = 'grab';
            });

            strip.addEventListener('pointerup', () => {
                isDown = false;
                strip.style.cursor = 'grab';

                // Resume animation after delay
                setTimeout(() => {
                    reel.style.animationPlayState = 'running';
                }, 1000);
            });

            strip.addEventListener('pointermove', (e) => {
                if (!isDown) {
                    return;
                }
                e.preventDefault();
                dragged = true;
                const x = e.pageX - strip.offsetLeft;
                const walk = (x - startX) * 2;
                strip.scrollLeft = scrollLeft - walk;
            });

            // Prevent accidental clicks after drag (FIXED: only block if we actually dragged)
            strip.addEventListener('click', (e) => {
                if (dragged) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                dragged = false;
            }, true);
        }

        // --- LAZY LOADING ---
        function setupLazyLoading() {
            const images = document.querySelectorAll('img[loading="lazy"]');

            if (!('IntersectionObserver' in window) || images.length === 0) {
                return;
            }

            const observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        observer.unobserve(img);

                        // Preload image
                        if (img.dataset.src) {
                            img.src = img.dataset.src;
                        }

                        state.loadedImages.add(img.src);
                    }
                });
            }, {
                threshold: 0.1,
                rootMargin: '100px',
            });

            images.forEach((img) => observer.observe(img));

            // Store observer for cleanup
            state.intersectionObservers.set('images', observer);
        }

        function setupVisualPerformanceProfile() {
            const body = document.body;
            if (!body) {
                return;
            }

            const hasMatchMedia = typeof window.matchMedia === 'function';
            const prefersReducedMotion = hasMatchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const isMobile = hasMatchMedia && window.matchMedia('(max-width: 768px)').matches;
            const isLargeDesktop = hasMatchMedia
                && window.matchMedia('(min-width: 1600px)').matches
                && window.matchMedia('(pointer: fine)').matches;
            const lowCpuBudget = typeof navigator.hardwareConcurrency === 'number' && navigator.hardwareConcurrency <= 4;
            const lowMemoryBudget = typeof navigator.deviceMemory === 'number' && navigator.deviceMemory <= 4;
            const saveData = !!(navigator.connection && navigator.connection.saveData);

            // On mobile or low-resource environments, enable perf-lite profile.
            const usePerfLite = prefersReducedMotion || saveData || isMobile || (isLargeDesktop && (lowCpuBudget || lowMemoryBudget));

            body.classList.toggle('perf-lite', usePerfLite);

            const heroVideo = document.querySelector('.hero-bg-video');
            if (heroVideo) {
                if (usePerfLite || isMobile) {
                    // Fully disable background video on mobile/perf-lite to avoid heavy network/CPU cost.
                    heroVideo.removeAttribute('src');
                    const sources = heroVideo.querySelectorAll('source');
                    sources.forEach((s) => s.remove());
                    try {
                        heroVideo.load();
                    } catch (_) {
                        // ignore
                    }
                } else {
                    heroVideo.preload = 'metadata';
                }
            }
        }

        // --- PERFORMANCE OPTIMIZATIONS ---
        function setupPerformanceOptimizations() {
            // Debounced resize handler
            const handleResize = debounce(() => {
                setupVisualPerformanceProfile();

                // Recalculate any layout-dependent elements
                const revealItems = document.querySelectorAll('.reveal-item:not(.revealed)');
                if (revealItems.length > 0) {
                    setupRevealAnimations();
                }
            }, 250);

            window.addEventListener('resize', handleResize, { passive: true });

            // Save scroll position for back navigation
            window.addEventListener('beforeunload', () => {
                sessionStorage.setItem('storefrontScrollPos', String(window.scrollY || 0));
            });

            // Restore scroll position if coming back (FIXED: safe modern detection)
            try {
                const navEntries = (performance && performance.getEntriesByType)
                    ? performance.getEntriesByType('navigation')
                    : [];
                const navType = navEntries && navEntries[0] ? navEntries[0].type : '';
                const isBackForward = navType === 'back_forward';

                if (isBackForward) {
                    const savedPos = sessionStorage.getItem('storefrontScrollPos');
                    if (savedPos) {
                        requestAnimationFrame(() => {
                            window.scrollTo(0, parseInt(savedPos, 10) || 0);
                            sessionStorage.removeItem('storefrontScrollPos');
                        });
                    }
                }
            } catch (e) {
                // ignore
            }

            // Optimize video loading
            const heroVideo = document.querySelector('.hero-bg-video');
            if (heroVideo) {
                heroVideo.addEventListener('error', function() {
                    console.warn('Hero video failed to load, using fallback');
                    const poster = this.getAttribute('poster');
                    if (poster) {
                        const container = this.parentElement;
                        if (container) {
                            const fallback = document.createElement('div');
                            fallback.style.cssText = `
                                position: absolute;
                                inset: 0;
                                background: linear-gradient(45deg, #000 0%, #111 100%);
                                z-index: 0;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                color: var(--neon);
                                font-family: 'Space Mono';
                                font-size: 0.8rem;
                                text-align: center;
                            `;
                            fallback.textContent = 'SYSTEM_VISUAL_UNAVAILABLE';
                            container.appendChild(fallback);
                        }
                    }
                }, { once: true });
            }
        }

        // --- ERROR HANDLING ---
        function setupErrorHandling() {
            // Global error handler
            window.addEventListener('error', (e) => {
                console.error('Storefront error:', e.error || e.message);

                // Log to console with styling
                console.log('%c⚠️ Unhandled error detected',
                           'color: #ff0055; font-weight: bold;');

                // Could send to error tracking service here
            });

            // Unhandled promise rejection
            window.addEventListener('unhandledrejection', (e) => {
                console.error('Unhandled promise rejection:', e.reason);
            });

            // Image error handling
            document.addEventListener('error', (e) => {
                if (e.target && e.target.tagName === 'IMG') {
                    const img = e.target;
                    if (!img.dataset.retry) {
                        img.dataset.retry = 'true';
                        img.src = '/images/placeholder.jpg';
                    }
                }
            }, true);
        }

        // --- BACKGROUND TOGGLE ---
        function setupBackgroundToggle() {
            const toggle = document.querySelector('[data-bg-toggle]');
            const body = document.body;
            if (!toggle || !body) {
                return;
            }

            // IMPROVEMENT: Per-page key so backgrounds don't bleed across pages
            const pageKey = 'dod-bg:' + window.location.pathname;

            const saved = localStorage.getItem(pageKey);
            if (saved && config.backgroundOptions.includes(saved)) {
                state.backgroundIndex = config.backgroundOptions.indexOf(saved);
            } else {
                // fallback to current HTML attribute
                const attr = body.getAttribute('data-bg');
                if (attr && config.backgroundOptions.includes(attr)) {
                    state.backgroundIndex = config.backgroundOptions.indexOf(attr);
                }
            }

            applyBackground();

            toggle.addEventListener('click', () => {
                state.backgroundIndex = (state.backgroundIndex + 1) % config.backgroundOptions.length;
                applyBackground();
            });

            function applyBackground() {
                const current = config.backgroundOptions[state.backgroundIndex];
                body.setAttribute('data-bg', current);
                localStorage.setItem(pageKey, current);
                toggle.textContent = `BG: ${current.toUpperCase()}`;
            }
        }

        function setupNewsletterForm() {
            const form = document.getElementById('newsletterForm');
            const emailInput = document.getElementById('newsletterEmail');
            const dropsInput = document.getElementById('newsletterDrops');
            const eventsInput = document.getElementById('newsletterEvents');
            const consentInput = document.getElementById('newsletterConsent');
            const submitBtn = document.getElementById('newsletterSubmit');
            const msg = document.getElementById('newsletterMsg');
            if (!form || !emailInput || !submitBtn || !msg) {
                return;
            }

            const setMsg = (text, kind) => {
                msg.textContent = text;
                msg.classList.remove('ok', 'err');
                if (kind) msg.classList.add(kind);
            };

            form.addEventListener('submit', async (e) => {
                e.preventDefault();

                const email = (emailInput.value || '').trim().toLowerCase();
                const wantsDrops = !!(dropsInput && dropsInput.checked);
                const wantsEvents = !!(eventsInput && eventsInput.checked);
                const validEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);

                if (!validEmail) {
                    setMsg('ENTER A VALID EMAIL', 'err');
                    return;
                }
                if (!wantsDrops && !wantsEvents) {
                    setMsg('SELECT AT LEAST ONE ALERT TYPE', 'err');
                    return;
                }
                if (!consentInput || !consentInput.checked) {
                    setMsg('CONSENT IS REQUIRED', 'err');
                    return;
                }

                submitBtn.disabled = true;
                setMsg('SUBMITTING...', '');

                try {
                    const response = await fetch('/api/newsletter_subscribe.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            email: email,
                            wants_drops: wantsDrops ? 1 : 0,
                            wants_events: wantsEvents ? 1 : 0,
                            consent_marketing: 1,
                            consent_source: 'index_newsletter_block',
                            source: 'index_newsletter_block'
                        })
                    });
                    const data = await response.json().catch(() => ({}));
                    if (!response.ok || !data.ok) {
                        setMsg(String(data.error || 'SUBSCRIBE FAILED'), 'err');
                        return;
                    }
                    setMsg(String(data.message || 'SUBSCRIBED'), 'ok');
                    trackEvent('newsletter_submit', {
                        source: 'index_newsletter_block',
                        wants_drops: wantsDrops ? 1 : 0,
                        wants_events: wantsEvents ? 1 : 0,
                    });
                    const manageUrl = String(data.manage_url || '/newsletter-manage.php');
                    const safeManageUrl = manageUrl.startsWith('/') ? manageUrl : '/newsletter-manage.php';
                    const safeMsg = escapeHtml(String(data.message || 'SUBSCRIBED'));
                    form.outerHTML = `
                        <div class="newsletter-subscribed-card" aria-live="polite">
                            <strong class="newsletter-subscribed-title">SUBSCRIBED</strong>
                            <div class="newsletter-subscribed-copy">${safeMsg}</div>
                            <a class="newsletter-manage-link" href="${escapeHtml(safeManageUrl)}">MANAGE PREFERENCES</a>
                        </div>
                    `;
                    form.reset();
                    if (dropsInput) dropsInput.checked = true;
                    if (eventsInput) eventsInput.checked = true;
                } catch (_) {
                    setMsg('NETWORK ERROR. TRY AGAIN.', 'err');
                } finally {
                    submitBtn.disabled = false;
                }
            });
        }

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function setupNewsletterDataToggle() {
            const toggleBtn = document.getElementById('newsletterDataToggle');
            const panel = document.getElementById('newsletterDataPanel');
            if (!toggleBtn || !panel) {
                return;
            }

            const storageKey = 'dod-newsletter-data-hidden:' + window.location.pathname;
            const saved = localStorage.getItem(storageKey) === '1';

            const apply = (hidden) => {
                panel.classList.toggle('newsletter-data-hidden', hidden);
                toggleBtn.textContent = hidden ? 'SHOW DATA' : 'HIDE DATA';
                toggleBtn.setAttribute('aria-expanded', hidden ? 'false' : 'true');
            };

            apply(saved);

            toggleBtn.addEventListener('click', () => {
                const hidden = !panel.classList.contains('newsletter-data-hidden');
                apply(hidden);
                localStorage.setItem(storageKey, hidden ? '1' : '0');
            });
        }

        function setupProductPreviewControls() {
            const grid = document.getElementById('main-grid');
            const searchInput = document.getElementById('previewSearch');
            const sortInput = document.getElementById('previewSort');
            if (!grid || !searchInput || !sortInput) {
                return;
            }

            const cards = Array.from(grid.querySelectorAll('.reveal-item'));
            const originalOrder = new Map(cards.map((el, idx) => [el, idx]));
            let emptyNode = null;

            const parseCreated = (value) => {
                const t = Date.parse(String(value || ''));
                return Number.isFinite(t) ? t : 0;
            };

            const apply = () => {
                const term = String(searchInput.value || '').trim().toLowerCase();
                const sortMode = String(sortInput.value || 'newest');

                const filtered = cards.filter((card) => {
                    const name = String(card.dataset.itemName || '').toLowerCase();
                    const category = String(card.dataset.itemCategory || '').toLowerCase();
                    return term === '' || name.includes(term) || category.includes(term);
                });

                filtered.sort((a, b) => {
                    if (sortMode === 'price_asc') {
                        const pa = parseFloat(String(a.dataset.itemPrice || '0')) || 0;
                        const pb = parseFloat(String(b.dataset.itemPrice || '0')) || 0;
                        if (pa !== pb) return pa - pb;
                    } else if (sortMode === 'featured') {
                        const fa = a.dataset.itemFeatured === 'true' ? 1 : 0;
                        const fb = b.dataset.itemFeatured === 'true' ? 1 : 0;
                        if (fa !== fb) return fb - fa;
                    } else {
                        const da = parseCreated(a.dataset.itemCreated || '');
                        const db = parseCreated(b.dataset.itemCreated || '');
                        if (da !== db) return db - da;
                    }
                    return (originalOrder.get(a) || 0) - (originalOrder.get(b) || 0);
                });

                cards.forEach((card) => {
                    card.style.display = 'none';
                });
                filtered.forEach((card) => {
                    card.style.display = '';
                    grid.appendChild(card);
                });

                if (!emptyNode) {
                    emptyNode = document.createElement('div');
                    emptyNode.className = 'preview-empty';
                    emptyNode.textContent = 'NO MATCHES IN PREVIEW. TRY ANOTHER SEARCH TERM OR OPEN FULL SHOP.';
                }

                if (filtered.length === 0) {
                    if (!grid.contains(emptyNode)) {
                        grid.appendChild(emptyNode);
                    }
                } else if (grid.contains(emptyNode)) {
                    emptyNode.remove();
                }
            };

            searchInput.addEventListener('input', apply);
            sortInput.addEventListener('change', apply);
            apply();
        }

        function setupEngagementTracking() {
            document.querySelectorAll('.product-card').forEach((card) => {
                card.addEventListener('click', () => {
                    const item = card.closest('.reveal-item');
                    trackEvent('product_click', {
                        id: item ? (item.dataset.itemId || '') : '',
                        category: item ? (item.dataset.itemCategory || '') : '',
                    });
                });
            });

            document.querySelectorAll('.newsletter-event-card').forEach((card) => {
                card.addEventListener('click', () => {
                    trackEvent('event_card_click', {
                        href: card.getAttribute('href') || '',
                    });
                });
            });

            document.addEventListener('click', (e) => {
                const target = e.target instanceof Element ? e.target.closest('[data-add-to-cart], .add-to-cart-btn, button[name="add_to_cart"]') : null;
                if (!target) {
                    return;
                }
                trackEvent('add_to_cart_click', {
                    id: target.getAttribute('data-product-id') || '',
                });
            });
        }

        // --- CLEANUP ---
        function cleanup() {
            // Clean up intersection observers
            state.intersectionObservers.forEach((observer) => {
                observer.disconnect();
            });
            state.intersectionObservers.clear();
        }

        // --- STARTUP ---
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }

        // Cleanup on page hide/unload
        window.addEventListener('pagehide', cleanup);
        window.addEventListener('beforeunload', cleanup);

        // Expose debug tools in development
        if (window.location.search.includes('debug=1')) {
            window.__storefrontDebug = {
                config,
                state,
                utils: {
                    debounce,
                },
                refresh: () => {
                    cleanup();
                    init();
                },
            };
            console.log('Storefront debug tools available at window.__storefrontDebug');
        }

    })();
    </script>
</body>
</html>