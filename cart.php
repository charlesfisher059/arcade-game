<?php
// cart.php — SHOPPING CART v5.2.0 (UI REBUILD + NAV FIX + CLEAN/PHP ROUTE AUTO-DETECT + DUAL-STORAGE)
// DIAMONDS OUTTA DIRT — "TRANSFORMING PRESSURE INTO CLARITY"
//
// WHAT CHANGED (v5.2.0):
// ✅ UI cleaned + simplified (less “boxy”, better spacing/typography, consistent buttons)
// ✅ NAV FIX: auto-detects whether you're visiting /cart (clean) or /cart.php (php) and builds links accordingly
// ✅ Endpoint fix: all forms + fetch() hit the correct cart endpoint (clean or php) automatically
// ✅ Fixed CSS typos (qty-wrap / qty-btn selectors were missing dots, causing broken layout/styles)
// ✅ Added missing actions: apply_discount, clear_discount, set_shipping (so the sidebar actually works)
// ✅ Totals are real now (shipping + discount + tax + grand total)
//
// NOTE:
// - If your .htaccess rewrite is active, you'll likely use /cart and /shop, etc.
// - If rewrite is NOT active, links will fall back to /cart.php /shop.php automatically.

declare(strict_types=1);

/* ============================================================================
   0) SECURITY, HEADERS, ERROR HANDLING - SESSION CONFIG
   ============================================================================ */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/cart_errors.log');

$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
    || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
    || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on');

$domain = $_SERVER['HTTP_HOST'] ?? '';
$domain = preg_replace('/:\d+$/', '', $domain);

$cookieParams = [
    'lifetime' => 86400 * 7,
    'path'     => '/',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax'
];
if ($domain !== '') {
    $cookieParams['domain'] = $domain;
}
session_set_cookie_params($cookieParams);

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_secure'   => $https,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
        'gc_maxlifetime'  => 86400 * 7,
        'cookie_lifetime' => 86400 * 7
    ]);
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    if ($https) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

/* ============================================================================
   0.1) ROUTING AUTO-DETECT (clean URLs vs direct .php)
   ============================================================================ */
$uriPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$usingCleanUrls = (strpos($uriPath, '.php') === false); // if you're on /cart, /shop etc.
$cartEndpoint   = $usingCleanUrls ? '/cart' : '/cart.php';

function route(string $clean, string $phpFallback, bool $usingCleanUrls): string {
    return $usingCleanUrls ? $clean : $phpFallback;
}

/* ============================================================================
   1) CONFIG & DB
   ============================================================================ */
const SITE_NAME  = 'DIAMONDS OUTTA DIRT';
const MOTTO      = 'TRANSFORMING PRESSURE INTO CLARITY';
const VERSION    = 'SYSTEM_v5.2.0';
const CURRENCY   = 'USD';

const TAX_RATE               = 0.0875;        // 8.75%
const SHIPPING_METHODS       = ['GROUND','EXPRESS','INTL'];
const SHIPPING_BASE = [
    'GROUND'  => 6_99,   // cents
    'EXPRESS' => 19_99,
    'INTL'    => 29_99
];
const SHIPPING_PER_KG = [
    'GROUND'  => 2_50,   // cents per kg
    'EXPRESS' => 6_00,
    'INTL'    => 10_00
];
const DEFAULT_ITEM_WEIGHT_KG = 0.5;

// Discount codes (basic demo)
$DISCOUNT_CODES = [
    'WELCOME10' => ['type' => 'percent', 'value' => 10,   'min_cents' => 5_000,  'expires' => strtotime('+1 year')],
    'FREESHIP'  => ['type' => 'freeship','value' => 0,    'min_cents' => 7_500,  'expires' => strtotime('+6 months')],
    'TAKE15'    => ['type' => 'fixed',   'value' => 1500, 'min_cents' => 10_000, 'expires' => strtotime('+3 months')],
];

$pdo = null;
$dbConnected = false;
try {
    $dbFile = __DIR__ . '/db_connect.php';
    if (file_exists($dbFile)) {
        require_once $dbFile;
        if (isset($pdo) && $pdo instanceof PDO) {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $dbConnected = true;
        }
    }
} catch (Throwable $e) {
    error_log('DB CONNECT FAIL: ' . $e->getMessage());
    $dbConnected = false;
}

/* ============================================================================
   2) HELPERS
   ============================================================================ */
function h(string $s, string $context = 'html'): string {
    if ($context === 'attr') return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($context === 'js') {
        return str_replace(
            ["\\", "'", "\"", "\n", "\r", "</", "<", ">", "&"],
            ["\\\\", "\\'", "\\\"", "\\n", "\\r", "<\\/", "\\x3c", "\\x3e", "\\x26"],
            $s
        );
    }
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(?string $t): bool {
    return is_string($t) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

function json_reply(array $data, int $code = 200): never {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function cents_to_str(int $cents): string {
    return number_format($cents / 100, 2, '.', ',');
}

function price_to_cents(float $price): int {
    return (int)round($price * 100);
}

function get_item_key(int $pid, ?string $size, ?string $color): string {
    $s = strtoupper(trim($size ?? ''));
    $c = strtoupper(trim($color ?? ''));
    return $pid . '_' . ($s !== '' ? $s : 'OS') . '_' . ($c !== '' ? $c : 'NOCOLOR');
}

function ensure_cart(): void {
    if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [
            'items' => [],
            'last_sync' => time(),
            'cart_id' => bin2hex(random_bytes(16)),
            'checkout_token' => null,
            'shipping_method' => 'GROUND',
            'discount_code' => null
        ];
    }
    if (!isset($_SESSION['cart']['items']) || !is_array($_SESSION['cart']['items'])) {
        $_SESSION['cart']['items'] = [];
    }
    if (empty($_SESSION['cart']['cart_id'])) {
        $_SESSION['cart']['cart_id'] = bin2hex(random_bytes(16));
    }
    if (empty($_SESSION['cart']['shipping_method']) || !is_string($_SESSION['cart']['shipping_method'])) {
        $_SESSION['cart']['shipping_method'] = 'GROUND';
    }
    if (!array_key_exists('discount_code', $_SESSION['cart'])) {
        $_SESSION['cart']['discount_code'] = null;
    }
}

/**
 * Snapshots a logged-in customer's cart into cart_abandonment whenever it
 * changes, so a scheduled check (admin/abandoned_cart_check.php) can find
 * carts that have gone stale and send a recovery email. Silently no-ops
 * for guests (no email to snapshot against) or if the DB is unavailable --
 * this must never block or break the actual cart action it's attached to.
 */
function snapshot_cart_for_abandonment_recovery(?PDO $pdo, bool $dbConnected): void {
    if (!$dbConnected || !$pdo) return;
    if (empty($_SESSION['customer_id'])) return;

    try {
        $items = cart_items();

        if (empty($items)) {
            // Cart emptied out -- nothing to remind them about anymore
            $pdo->prepare("DELETE FROM cart_abandonment WHERE customer_id = ?")
                ->execute([(int)$_SESSION['customer_id']]);
            return;
        }

        $custStmt = $pdo->prepare("SELECT email, name FROM customers WHERE id = ? LIMIT 1");
        $custStmt->execute([(int)$_SESSION['customer_id']]);
        $customer = $custStmt->fetch(PDO::FETCH_ASSOC);
        if (!$customer || empty($customer['email'])) return;

        $subtotalCents = compute_subtotal_cents($items);
        $itemCount = 0;
        foreach ($items as $it) $itemCount += max(1, (int)($it['quantity'] ?? 1));

        $snapshot = json_encode(array_map(static function ($it) {
            return [
                'name' => (string)($it['name'] ?? ''),
                'image' => (string)($it['image'] ?? ''),
                'size' => (string)($it['size'] ?? 'OS'),
                'quantity' => (int)($it['quantity'] ?? 1),
                'price' => (float)($it['price'] ?? 0),
            ];
        }, $items), JSON_UNESCAPED_SLASHES);

        $pdo->prepare("
            INSERT INTO cart_abandonment (customer_id, email, name, cart_snapshot, subtotal, item_count, first_reminder_sent_at, second_reminder_sent_at, recovered_at)
            VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, NULL)
            ON DUPLICATE KEY UPDATE
                email = VALUES(email),
                name = VALUES(name),
                cart_snapshot = VALUES(cart_snapshot),
                subtotal = VALUES(subtotal),
                item_count = VALUES(item_count),
                first_reminder_sent_at = NULL,
                second_reminder_sent_at = NULL,
                recovered_at = NULL,
                updated_at = NOW()
        ")->execute([
            (int)$_SESSION['customer_id'],
            (string)$customer['email'],
            (string)($customer['name'] ?? ''),
            $snapshot,
            $subtotalCents / 100,
            $itemCount,
        ]);
    } catch (Throwable $e) {
        error_log('[CART_ABANDONMENT_SNAPSHOT] ' . $e->getMessage());
        // Never let this break the actual cart action
    }
}

function cart_items(): array {
    ensure_cart();
    return $_SESSION['cart']['items'];
}

function set_cart_items(array $items): void {
    ensure_cart();
    $_SESSION['cart']['items'] = $items;
    $_SESSION['cart']['last_sync'] = time();
}

function cart_count_qty(): int {
    $items = cart_items();
    $total = 0;
    foreach ($items as $item) {
        $q = isset($item['quantity']) ? (int)$item['quantity'] : 1;
        if ($q > 0) $total += $q;
    }
    return $total;
}

function normalize_cart_item(array $item): array {
    $pid = (int)($item['id'] ?? $item['product_id'] ?? 0);
    $name = trim((string)($item['name'] ?? ''));
    $price = (float)($item['price'] ?? 0);
    $size = strtoupper(trim((string)($item['size'] ?? 'OS')));
    $color = strtoupper(trim((string)($item['color'] ?? 'NOCOLOR')));
    $quantity = max(1, (int)($item['quantity'] ?? 1));
    $image = trim((string)($item['image'] ?? $item['image_url'] ?? ''));

    return [
        'id' => $pid,
        'product_id' => $pid,
        'name' => $name,
        'price' => $price,
        'price_cents' => price_to_cents($price),
        'image' => $image,
        'size' => $size,
        'color' => $color,
        'quantity' => $quantity,
        'added_at' => $item['added_at'] ?? time(),
        'key' => get_item_key($pid, $size, $color),
        'weight_kg' => isset($item['weight_kg']) ? (float)$item['weight_kg'] : DEFAULT_ITEM_WEIGHT_KG
    ];
}

function compute_shipping_cents(array $items, string $method): int {
    $m = strtoupper($method);
    if (!in_array($m, SHIPPING_METHODS, true)) $m = 'GROUND';

    $base = (int)(SHIPPING_BASE[$m] ?? 0);
    $perKg = (int)(SHIPPING_PER_KG[$m] ?? 0);

    $totalKg = 0.0;
    foreach ($items as $it) {
        $q = max(1, (int)($it['quantity'] ?? 1));
        $w = (float)($it['weight_kg'] ?? DEFAULT_ITEM_WEIGHT_KG);
        $totalKg += ($w * $q);
    }
    $weightCost = (int)round($totalKg * $perKg);
    return max(0, $base + $weightCost);
}

function compute_subtotal_cents(array $items): int {
    $subtotal = 0;
    foreach ($items as $it) {
        $unit = isset($it['price_cents']) ? (int)$it['price_cents'] : price_to_cents((float)($it['price'] ?? 0));
        $qty  = max(1, (int)($it['quantity'] ?? 1));
        $subtotal += ($unit * $qty);
    }
    return max(0, $subtotal);
}

function validate_discount(?string $code, array $DISCOUNT_CODES, int $subtotal): array {
    $code = strtoupper(trim((string)$code));
    if ($code === '') return ['ok'=>false, 'code'=>'EMPTY_CODE', 'message'=>'Enter a code'];

    if (!isset($DISCOUNT_CODES[$code])) {
        return ['ok'=>false, 'code'=>'INVALID_CODE', 'message'=>'Invalid code'];
    }
    $d = $DISCOUNT_CODES[$code];
    $expires = (int)($d['expires'] ?? 0);
    if ($expires > 0 && time() > $expires) {
        return ['ok'=>false, 'code'=>'EXPIRED', 'message'=>'Code expired'];
    }
    $min = (int)($d['min_cents'] ?? 0);
    if ($subtotal < $min) {
        return ['ok'=>false, 'code'=>'MIN_NOT_MET', 'message'=>'Subtotal too low for this code'];
    }
    return ['ok'=>true, 'code'=>'VALID', 'discount'=>$d, 'normalized'=>$code];
}

function compute_discount_cents(array $discountDef, int $subtotal, int $shipping): int {
    $type = (string)($discountDef['type'] ?? '');
    $value = (int)($discountDef['value'] ?? 0);

    if ($type === 'percent') {
        $pct = max(0, min(90, $value));
        return (int)floor($subtotal * ($pct / 100));
    }
    if ($type === 'fixed') {
        return max(0, min($subtotal, $value));
    }
    if ($type === 'freeship') {
        return max(0, $shipping);
    }
    return 0;
}

function compute_totals(array $items, string $shippingMethod, ?string $discountCode, array $DISCOUNT_CODES): array {
    $subtotal = compute_subtotal_cents($items);
    $shipping = $subtotal > 0 ? compute_shipping_cents($items, $shippingMethod) : 0;

    $discountCents = 0;
    $appliedCode = null;

    if (is_string($discountCode) && trim($discountCode) !== '') {
        $v = validate_discount($discountCode, $DISCOUNT_CODES, $subtotal);
        if (!empty($v['ok'])) {
            $appliedCode = $v['normalized'];
            $discountCents = compute_discount_cents((array)$v['discount'], $subtotal, $shipping);
        }
    }

    $taxable = max(0, $subtotal - $discountCents);
    $tax = (int)round($taxable * TAX_RATE);

    $grand = max(0, $subtotal + $shipping + $tax - $discountCents);

    return [
        'subtotal' => $subtotal,
        'shipping' => $shipping,
        'tax' => $tax,
        'discount' => $discountCents,
        'grand_total' => $grand,
        'item_count' => count($items),
        'total_qty' => cart_count_qty(),
        'discount_code' => $appliedCode
    ];
}

/* ============================================================================
   3) CART ACTIONS
   ============================================================================ */
$action = $_POST['action'] ?? $_GET['action'] ?? null;
$isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'])) === 'xmlhttprequest')
          || (isset($_GET['ajax']) && $_GET['ajax'] === '1');

if ($action === 'import_local') {
    if (!csrf_check($_POST['csrf'] ?? '')) json_reply(['ok'=>false,'code'=>'CSRF_FAIL'], 400);

    $localItems = json_decode($_POST['items'] ?? '[]', true) ?? [];
    if (!is_array($localItems)) $localItems = [];

    ensure_cart();
    $sessionItems = cart_items();

    $merged = $sessionItems;
    $importedCount = 0;
    $updatedCount = 0;

    foreach ($localItems as $localItem) {
        if (!is_array($localItem)) continue;

        $normalized = normalize_cart_item($localItem);
        $key = $normalized['key'];

        $found = false;
        foreach ($merged as $index => $sessionItem) {
            $sessionKey = get_item_key(
                (int)($sessionItem['product_id'] ?? $sessionItem['id'] ?? 0),
                (string)($sessionItem['size'] ?? 'OS'),
                (string)($sessionItem['color'] ?? 'NOCOLOR')
            );

            if ($sessionKey === $key) {
                // Idempotent merge: prevent quantity inflation when cart page re-syncs localStorage on each visit.
                $merged[$index]['quantity'] = max(
                    max(1, (int)($sessionItem['quantity'] ?? 1)),
                    max(1, (int)($normalized['quantity'] ?? 1))
                );
                $updatedCount++;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $merged[] = $normalized;
            $importedCount++;
        }
    }

    set_cart_items($merged);

    $shippingMethod = (string)($_SESSION['cart']['shipping_method'] ?? 'GROUND');
    $discountCode = $_SESSION['cart']['discount_code'] ?? null;

    $totals = compute_totals($merged, $shippingMethod, is_string($discountCode)?$discountCode:null, $DISCOUNT_CODES);
    $totals['cart_id'] = $_SESSION['cart']['cart_id'] ?? null;

    snapshot_cart_for_abandonment_recovery($pdo, $dbConnected);

    json_reply([
        'ok' => true,
        'code' => 'LOCAL_STORAGE_IMPORTED',
        'imported' => $importedCount,
        'updated' => $updatedCount,
        'item_count' => count($merged),
        'total_qty' => cart_count_qty(),
        'totals' => $totals,
        'items' => $merged,
        'cart_id' => $_SESSION['cart']['cart_id'] ?? null
    ]);
}

if ($action === 'add_to_cart') {
    if (!csrf_check($_POST['csrf'] ?? '')) json_reply(['ok'=>false,'code'=>'CSRF_FAIL'], 400);

    $product_id = (int)($_POST['product_id'] ?? 0);
    $quantity = max(1, (int)($_POST['quantity'] ?? 1));
    $size = trim((string)($_POST['size'] ?? 'OS'));
    $color = trim((string)($_POST['color'] ?? 'NOCOLOR'));
    $name = trim((string)($_POST['name'] ?? ''));
    $price = (float)($_POST['price'] ?? 0);
    $image = trim((string)($_POST['image'] ?? ''));
    $weight = isset($_POST['weight_kg']) ? (float)$_POST['weight_kg'] : DEFAULT_ITEM_WEIGHT_KG;

    if ($product_id <= 0) json_reply(['ok'=>false,'code'=>'INVALID_PRODUCT'], 400);

    ensure_cart();
    $items = cart_items();
    $key = get_item_key($product_id, $size, $color);

    $found = false;
    foreach ($items as $index => $item) {
        if (get_item_key(
            (int)($item['product_id'] ?? $item['id'] ?? 0),
            (string)($item['size'] ?? 'OS'),
            (string)($item['color'] ?? 'NOCOLOR')
        ) === $key) {
            $items[$index]['quantity'] = max(1, (int)($item['quantity'] ?? 1)) + $quantity;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $items[] = normalize_cart_item([
            'id' => $product_id,
            'product_id' => $product_id,
            'name' => $name,
            'price' => $price,
            'image' => $image,
            'size' => $size,
            'color' => $color,
            'quantity' => $quantity,
            'weight_kg' => $weight
        ]);
    }

    set_cart_items($items);

    $shippingMethod = (string)($_SESSION['cart']['shipping_method'] ?? 'GROUND');
    $discountCode = $_SESSION['cart']['discount_code'] ?? null;

    $totals = compute_totals($items, $shippingMethod, is_string($discountCode)?$discountCode:null, $DISCOUNT_CODES);
    $totals['cart_id'] = $_SESSION['cart']['cart_id'] ?? null;

    snapshot_cart_for_abandonment_recovery($pdo, $dbConnected);

    json_reply([
        'ok' => true,
        'code' => 'ITEM_ADDED',
        'item_count' => count($items),
        'total_qty' => cart_count_qty(),
        'totals' => $totals,
        'items' => $items,
        'cart_id' => $_SESSION['cart']['cart_id'] ?? null
    ]);
}

if ($action === 'get_cart_state') {
    ensure_cart();
    $items = cart_items();

    $shippingMethod = (string)($_SESSION['cart']['shipping_method'] ?? 'GROUND');
    $discountCode = $_SESSION['cart']['discount_code'] ?? null;

    $totals = compute_totals($items, $shippingMethod, is_string($discountCode)?$discountCode:null, $DISCOUNT_CODES);

    json_reply([
        'ok' => true,
        'items' => $items,
        'totals' => $totals,
        'cart_id' => $_SESSION['cart']['cart_id'] ?? null,
        'last_sync' => $_SESSION['cart']['last_sync'] ?? time(),
        'checkout_token' => $_SESSION['cart']['checkout_token'] ?? null,
        'shipping_method' => $shippingMethod,
        'discount_code' => $totals['discount_code'] ?? null
    ]);
}

if ($action === 'set_shipping') {
    if (!csrf_check($_POST['csrf'] ?? '')) json_reply(['ok'=>false,'code'=>'CSRF_FAIL'], 400);

    ensure_cart();
    $method = strtoupper(trim((string)($_POST['method'] ?? 'GROUND')));
    if (!in_array($method, SHIPPING_METHODS, true)) $method = 'GROUND';
    $_SESSION['cart']['shipping_method'] = $method;

    $items = cart_items();
    $discountCode = $_SESSION['cart']['discount_code'] ?? null;
    $totals = compute_totals($items, $method, is_string($discountCode)?$discountCode:null, $DISCOUNT_CODES);

    json_reply([
        'ok' => true,
        'code' => 'SHIPPING_SET',
        'shipping_method' => $method,
        'totals' => $totals,
        'items' => $items
    ]);
}

if ($action === 'apply_discount') {
    if (!csrf_check($_POST['csrf'] ?? '')) json_reply(['ok'=>false,'code'=>'CSRF_FAIL'], 400);

    ensure_cart();
    $items = cart_items();
    $subtotal = compute_subtotal_cents($items);

    $code = strtoupper(trim((string)($_POST['code'] ?? '')));
    $v = validate_discount($code, $DISCOUNT_CODES, $subtotal);
    if (empty($v['ok'])) {
        json_reply(['ok'=>false,'code'=>$v['code'] ?? 'INVALID', 'message'=>$v['message'] ?? 'Invalid'], 400);
    }

    $_SESSION['cart']['discount_code'] = $v['normalized'];

    $shippingMethod = (string)($_SESSION['cart']['shipping_method'] ?? 'GROUND');
    $totals = compute_totals($items, $shippingMethod, $v['normalized'], $DISCOUNT_CODES);

    json_reply([
        'ok' => true,
        'code' => 'DISCOUNT_APPLIED',
        'discount_code' => $totals['discount_code'],
        'totals' => $totals,
        'items' => $items
    ]);
}

if ($action === 'clear_discount') {
    if (!csrf_check($_POST['csrf'] ?? '')) json_reply(['ok'=>false,'code'=>'CSRF_FAIL'], 400);

    ensure_cart();
    $_SESSION['cart']['discount_code'] = null;

    $items = cart_items();
    $shippingMethod = (string)($_SESSION['cart']['shipping_method'] ?? 'GROUND');
    $totals = compute_totals($items, $shippingMethod, null, $DISCOUNT_CODES);

    json_reply([
        'ok' => true,
        'code' => 'DISCOUNT_CLEARED',
        'totals' => $totals,
        'items' => $items
    ]);
}

if ($action === 'prepare_checkout') {
    if (!csrf_check($_POST['csrf'] ?? '')) json_reply(['ok'=>false,'code'=>'CSRF_FAIL'], 400);

    ensure_cart();
    $items = cart_items();
    if (empty($items)) json_reply(['ok'=>false,'code'=>'CART_EMPTY','message'=>'Cannot checkout with empty cart'], 400);

    $checkoutToken = bin2hex(random_bytes(16));
    $_SESSION['cart']['checkout_token'] = $checkoutToken;
    $_SESSION['cart']['checkout_prepared_at'] = time();

    $shippingMethod = (string)($_SESSION['cart']['shipping_method'] ?? 'GROUND');
    $discountCode = $_SESSION['cart']['discount_code'] ?? null;
    $totals = compute_totals($items, $shippingMethod, is_string($discountCode)?$discountCode:null, $DISCOUNT_CODES);

    json_reply([
        'ok' => true,
        'code' => 'CHECKOUT_PREPARED',
        'items' => $items,
        'totals' => $totals,
        'cart_id' => $_SESSION['cart']['cart_id'],
        'checkout_token' => $checkoutToken,
        'checkout_url' => route('/checkout?token=' . $checkoutToken, '/checkout.php?token=' . $checkoutToken, $usingCleanUrls),
        'timestamp' => time()
    ]);
}

// Remaining actions (qty/remove/clear)
try {
    ensure_cart();

    if ($action === 'update_qty') {
        if (!csrf_check($_POST['csrf'] ?? '')) json_reply(['ok'=>false,'code'=>'CSRF_FAIL'], 400);

        $pid   = (int)($_POST['product_id'] ?? 0);
        $size  = strtoupper(trim((string)($_POST['size'] ?? 'OS')));
        $color = strtoupper(trim((string)($_POST['color'] ?? 'NOCOLOR')));
        $qty   = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT, ['options' => ['min_range'=>1,'max_range'=>99]]) ?? 1;

        $key   = get_item_key($pid, $size, $color);
        $items = cart_items();

        $updated = false;
        foreach ($items as $index => $item) {
            $item_key = get_item_key(
                (int)($item['product_id'] ?? $item['id'] ?? 0),
                (string)($item['size'] ?? 'OS'),
                (string)($item['color'] ?? 'NOCOLOR')
            );
            if ($item_key === $key) {
                $items[$index]['quantity'] = (int)$qty;
                $updated = true;
                break;
            }
        }

        if ($updated) set_cart_items($items);

        $shippingMethod = (string)($_SESSION['cart']['shipping_method'] ?? 'GROUND');
        $discountCode = $_SESSION['cart']['discount_code'] ?? null;
        $totals = compute_totals($items, $shippingMethod, is_string($discountCode)?$discountCode:null, $DISCOUNT_CODES);
        $totals['cart_id'] = $_SESSION['cart']['cart_id'] ?? null;

        snapshot_cart_for_abandonment_recovery($pdo, $dbConnected);

        if ($isAjax) json_reply(['ok'=>true,'code'=>'CART_UPDATED_SUCCESSFULLY','totals'=>$totals,'items'=>$items]);
    }

    if ($action === 'remove_item') {
        if (!csrf_check($_POST['csrf'] ?? '')) json_reply(['ok'=>false,'code'=>'CSRF_FAIL'], 400);

        $pid   = (int)($_POST['product_id'] ?? 0);
        $size  = strtoupper(trim((string)($_POST['size'] ?? 'OS')));
        $color = strtoupper(trim((string)($_POST['color'] ?? 'NOCOLOR')));
        $key   = get_item_key($pid, $size, $color);

        $items = cart_items();
        $new_items = [];

        foreach ($items as $item) {
            $item_key = get_item_key(
                (int)($item['product_id'] ?? $item['id'] ?? 0),
                (string)($item['size'] ?? 'OS'),
                (string)($item['color'] ?? 'NOCOLOR')
            );
            if ($item_key !== $key) $new_items[] = $item;
        }

        set_cart_items($new_items);

        $shippingMethod = (string)($_SESSION['cart']['shipping_method'] ?? 'GROUND');
        $discountCode = $_SESSION['cart']['discount_code'] ?? null;
        $totals = compute_totals($new_items, $shippingMethod, is_string($discountCode)?$discountCode:null, $DISCOUNT_CODES);
        $totals['cart_id'] = $_SESSION['cart']['cart_id'] ?? null;

        snapshot_cart_for_abandonment_recovery($pdo, $dbConnected);

        if ($isAjax) json_reply(['ok'=>true,'code'=>'ITEM_REMOVED','totals'=>$totals,'items'=>$new_items]);
    }

    if ($action === 'clear_cart') {
        if (!csrf_check($_POST['csrf'] ?? '')) json_reply(['ok'=>false,'code'=>'CSRF_FAIL'], 400);

        set_cart_items([]);
        $_SESSION['cart']['checkout_token'] = null;
        $_SESSION['cart']['discount_code'] = null;

        $totals = [
            'subtotal' => 0,
            'shipping' => 0,
            'tax' => 0,
            'discount' => 0,
            'grand_total' => 0,
            'item_count' => 0,
            'total_qty' => 0,
            'cart_id' => $_SESSION['cart']['cart_id'] ?? null,
            'discount_code' => null
        ];

        snapshot_cart_for_abandonment_recovery($pdo, $dbConnected);

        if ($isAjax) json_reply(['ok'=>true,'code'=>'CART_CLEARED','totals'=>$totals,'items'=>[]]);
    }

} catch (Throwable $e) {
    error_log('CART_ACTION_FAIL: ' . $e->getMessage());
    if ($isAjax) json_reply(['ok'=>false,'code'=>'INTERNAL_ERROR'], 500);
}

/* ============================================================================
   4) VIEW DATA PREP
   ============================================================================ */
ensure_cart();
$items = cart_items();

$itemCount = count($items);
$totalQty = cart_count_qty();
$cartId = $_SESSION['cart']['cart_id'] ?? null;
$checkoutToken = $_SESSION['cart']['checkout_token'] ?? null;

$shippingMethod = (string)($_SESSION['cart']['shipping_method'] ?? 'GROUND');
$discountCode = $_SESSION['cart']['discount_code'] ?? null;

$totals = compute_totals($items, $shippingMethod, is_string($discountCode)?$discountCode:null, $DISCOUNT_CODES);
$csrf = csrf_token();

// Suggested products
$suggested = [];
if ($dbConnected) {
    try {
        $sql = "SELECT id, name, price, image_url FROM products WHERE stock > 0 ORDER BY COALESCE(featured,0) DESC, created_at DESC LIMIT 6";
        $st  = $pdo->query($sql);
        $suggested = $st ? ($st->fetchAll() ?: []) : [];
    } catch (Throwable $e) {
        error_log('suggested_fail: ' . $e->getMessage());
    }
}
if (!$dbConnected || empty($suggested)) {
    $suggested = [
        ['id'=>1, 'name'=>'NEON REFLECTIVE HOODIE', 'price'=>89.99, 'image_url'=>'/images/products/hoodie-neon.jpg'],
        ['id'=>3, 'name'=>'CRYSTAL MESH TOP',       'price'=>64.99, 'image_url'=>'/images/products/mesh-top.jpg'],
        ['id'=>9, 'name'=>'TECHNO JACKET',          'price'=>129.99,'image_url'=>'/images/products/techno-jacket.jpg'],
        ['id'=>5, 'name'=>'LIMITED PRINT #001',     'price'=>149.99,'image_url'=>'/images/products/print-001.jpg'],
        ['id'=>7, 'name'=>'FORGE PATCH',            'price'=>19.99, 'image_url'=>'/images/products/patch.jpg'],
        ['id'=>8, 'name'=>'TACTICAL CAP',           'price'=>29.99, 'image_url'=>'/images/products/cap.jpg'],
    ];
}

// Routes (auto clean/php)
$homeUrl     = route('/', '/index.php', $usingCleanUrls);
$shopUrl     = route('/shop', '/shop.php', $usingCleanUrls);
$lookbookUrl = route('/lookbook', '/lookbook.php', $usingCleanUrls);
$servicesUrl = route('/services', '/services.php', $usingCleanUrls);
$cartUrl     = route('/cart', '/cart.php', $usingCleanUrls);

$productBase = $usingCleanUrls ? '/product/' : '/product.php?id=';

/* ============================================================================
   5) HTML OUTPUT — UI REBUILD
   ============================================================================ */
?>
<!DOCTYPE html>
<html lang="en" class="tech-archive" data-version="<?=h(VERSION)?>" data-cart-id="<?=h($cartId ?? '')?>" data-checkout-token="<?=h($checkoutToken ?? '')?>" data-cart-endpoint="<?=h($cartEndpoint,'attr')?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>BAG (<?=$totalQty?>) | <?=h(SITE_NAME)?></title>

<meta name="description" content="<?=h(MOTTO)?> — Secure Cart and Checkout">
<meta property="og:title" content="BAG | <?=h(SITE_NAME)?>">
<meta property="og:description" content="<?=h(MOTTO)?>">
<meta property="og:type" content="website">
<meta property="og:image" content="/images/social-preview.jpg">
<meta name="twitter:card" content="summary_large_image">
<meta name="csrf-token" content="<?=h($csrf)?>">

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;700;900&family=Space+Mono:wght@400;700&family=Syncopate:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/master.css">

<style>
:root{
  --neon:#00ff9d; --cyber-blue:#00f3ff; --cyber-pink:#ff00ff;
  --tactical-dark:#000; --border:rgba(255,255,255,.12); --muted:#9aa0a6;
  --danger:#ff3b69; --ok:#00ff9d; --grid:rgba(0,255,157,.08);
  --card:rgba(8,8,10,.82);
  --shadow:0 18px 40px rgba(0,0,0,.55);
  --radius:14px;
}
*{box-sizing:border-box}
body{
  background:#000;color:#fff;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Space Mono',monospace;
  min-height:100vh;margin:0;
}
.grid-bg::before{
  content:""; position:fixed; inset:0; pointer-events:none; z-index:-2;
  background-image:linear-gradient(var(--grid) 1px,transparent 1px),linear-gradient(90deg,var(--grid) 1px,transparent 1px);
  background-size:56px 56px; opacity:.40;
}
.scanlines::after{
  content:""; position:fixed; inset:0; pointer-events:none; z-index:-3;
  background:linear-gradient(transparent 50%, rgba(0,255,157,.035) 50%);
  background-size:100% 4px; mix-blend:screen;
}
.glow{
  position:fixed; inset:-20%; z-index:-4; pointer-events:none;
  background:
    radial-gradient(60% 40% at 25% 25%, rgba(0,243,255,.10), transparent 60%),
    radial-gradient(50% 40% at 75% 30%, rgba(255,0,255,.08), transparent 60%),
    radial-gradient(60% 55% at 55% 75%, rgba(0,255,157,.10), transparent 65%);
  filter:blur(14px);
}

/* NAV */
header.nav{
  position:sticky; top:0; z-index:20;
  background:rgba(0,0,0,.72);
  backdrop-filter: blur(10px);
  border-bottom:1px solid var(--border);
}
.nav-inner{
  max-width:1200px;margin:0 auto;padding:14px 18px;
  display:flex;align-items:center;justify-content:space-between;gap:14px;
}
.brand{
  display:flex;flex-direction:column;gap:2px;
}
.brand a{
  color:var(--neon); text-decoration:none;
  letter-spacing:2px; font-family:Syncopate; font-weight:700; font-size:.95rem;
}
.brand small{
  color:var(--muted); font-family:'Space Mono'; letter-spacing:1.4px; font-size:.68rem;
}
.nav-links{
  display:flex;align-items:center;gap:10px; flex-wrap:wrap; justify-content:flex-end;
}
.nav-links a{
  color:#fff; text-decoration:none;
  font-family:'Space Mono';
  font-size:.72rem; letter-spacing:1.6px;
  padding:8px 10px;
  border:1px solid rgba(255,255,255,.10);
  border-radius:999px;
  background:rgba(10,10,12,.35);
  transition:all .25s ease;
}
.nav-links a:hover{border-color:rgba(0,255,157,.45); box-shadow:0 0 18px rgba(0,255,157,.12); transform:translateY(-1px)}
.nav-bag{
  border-color:rgba(0,255,157,.55)!important;
  color:var(--neon)!important;
}

/* LAYOUT */
.wrap{
  max-width:1200px;margin:22px auto 60px auto;padding:0 18px;
  display:grid;grid-template-columns: 1.6fr .9fr;gap:18px;
}
@media (max-width: 980px){ .wrap{grid-template-columns:1fr} }

.card{
  background:var(--card);
  border:1px solid var(--border);
  border-radius:var(--radius);
  box-shadow:var(--shadow);
}
.card-h{
  padding:16px 16px 10px 16px;
  border-bottom:1px solid rgba(255,255,255,.08);
  display:flex;align-items:flex-end;justify-content:space-between;gap:10px;
}
.h-title{
  margin:0; font-size:1.0rem; letter-spacing:2px;
  font-family:'Space Mono'; color:var(--neon);
}
.h-sub{
  margin:0; font-size:.72rem; color:var(--muted); font-family:'Space Mono';
}

/* ITEMS */
.items{
  padding:6px 10px 10px 10px;
}
.item{
  display:grid;
  grid-template-columns: 92px 1fr auto;
  gap:14px;
  padding:12px 10px;
  border-bottom:1px solid rgba(255,255,255,.07);
}
.item:last-child{border-bottom:none}
.thumb{
  width:92px;height:92px;border-radius:12px;
  object-fit:cover;background:#0b0b0b;border:1px solid rgba(255,255,255,.10);
}
.title{
  font-weight:900; letter-spacing:.4px; font-size:.92rem; margin:0;
}
.meta{
  margin-top:6px; font-family:'Space Mono'; font-size:.68rem; color:var(--muted); letter-spacing:1px;
  display:flex; flex-wrap:wrap; gap:6px;
}
.pill{
  display:inline-flex; align-items:center; gap:6px;
  padding:4px 8px; border-radius:999px;
  border:1px solid rgba(255,255,255,.10);
  background:rgba(0,0,0,.35);
}
.pill b{color:#fff; font-weight:700}
.unit{
  margin-top:8px; font-family:'Space Mono'; font-size:.7rem; color:rgba(255,255,255,.85);
}
.right{
  text-align:right; display:flex; flex-direction:column; align-items:flex-end; justify-content:center; gap:10px;
}
.line{
  font-family:'Space Mono'; font-weight:700; letter-spacing:.5px;
}
.mini{
  color:var(--muted); font-family:'Space Mono'; font-size:.65rem; letter-spacing:1px;
}
.controls{
  display:flex; align-items:center; gap:10px;
}
.qty-wrap{
  display:inline-flex; align-items:center;
  border:1px solid rgba(255,255,255,.12);
  border-radius:999px; overflow:hidden;
  background:rgba(0,0,0,.45);
}
.qty-btn{
  background:transparent; border:none; color:#fff;
  width:34px; height:32px; cursor:pointer; font-weight:900; font-size:1rem;
}
.qty-input{
  width:44px; text-align:center;
  background:transparent; border:none; color:#fff; height:32px;
  font-family:'Space Mono';
}
.qty-btn:focus-visible,.qty-input:focus-visible{outline:2px solid var(--neon);outline-offset:2px}
.remove{
  background:transparent;border:none;color:var(--danger);cursor:pointer;
  font-family:'Space Mono'; font-size:.68rem; letter-spacing:1.2px;
}
.remove:hover{color:#fff;text-shadow:0 0 8px rgba(255,59,105,.55)}

/* SUMMARY */
.summary{
  padding:14px 14px 16px 14px;
}
.row{
  display:flex;align-items:center;justify-content:space-between;
  padding:10px 0;border-bottom:1px dashed rgba(255,255,255,.12);
  font-family:'Space Mono'; letter-spacing:1px; font-size:.72rem;
}
.row:last-child{border-bottom:none}
.big{
  font-size:1.1rem; font-weight:900; letter-spacing:1px;
}
.select, .input{
  width:100%;
  padding:11px 12px;
  border-radius:12px;
  border:1px solid rgba(255,255,255,.14);
  background:rgba(0,0,0,.42);
  color:#fff;
  font-family:'Space Mono';
  outline:none;
}
.select:focus, .input:focus{border-color:rgba(0,255,157,.55); box-shadow:0 0 0 3px rgba(0,255,157,.12)}
.inline{
  display:flex; gap:8px; margin-top:10px;
}
.btn{
  display:inline-flex;align-items:center;justify-content:center;
  padding:12px 14px;border-radius:12px;
  border:1px solid rgba(0,255,157,.55);
  background:transparent;color:var(--neon);
  font-family:'Space Mono'; font-weight:700; letter-spacing:1.6px;
  cursor:pointer; text-decoration:none;
  transition:all .25s ease;
  min-height:44px;
}
.btn:hover{
  background:linear-gradient(135deg,var(--neon),var(--cyber-blue));
  color:#000; box-shadow:0 0 18px rgba(0,255,157,.22); transform:translateY(-1px)
}
.btn.secondary{
  border-color:rgba(255,255,255,.14); color:#fff;
}
.btn.secondary:hover{
  background:rgba(255,255,255,.10); color:#fff; transform:translateY(-1px)
}
.btn.danger{
  border-color:rgba(255,59,105,.55); color:var(--danger);
}
.btn.danger:hover{
  background:linear-gradient(135deg,#ff5c8a,#ffe0e9);
  color:#000;
}
.btn.checkout{
  background:var(--neon); color:#000; border:none; font-weight:900;
}
.btn.checkout:hover{background:linear-gradient(135deg,var(--neon),var(--cyber-blue))}
.btn.checkout.loading{background:rgba(0,0,0,.45); color:var(--neon); cursor:not-allowed; opacity:.9}
.hint{
  margin-top:10px; color:var(--muted); font-family:'Space Mono'; font-size:.68rem; letter-spacing:1px;
}

/* EMPTY */
.empty{
  padding:22px 16px; text-align:center;
}
.empty h3{
  margin:0; font-family:'Space Mono'; letter-spacing:2px;
  color:var(--neon); font-size:1rem;
}
.empty p{margin:10px 0 0 0; color:var(--muted); font-family:'Space Mono'; font-size:.72rem}
.suggest{
  margin-top:16px; display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px;
}
.sugg{
  border:1px solid rgba(255,255,255,.10); border-radius:14px; overflow:hidden;
  background:rgba(0,0,0,.35); text-decoration:none; color:#fff;
  transition:all .25s ease;
}
.sugg:hover{transform:translateY(-2px); border-color:rgba(0,255,157,.35); box-shadow:0 0 22px rgba(0,255,157,.10)}
.sugg img{width:100%; height:120px; object-fit:cover; background:#0b0b0b}
.sugg .pad{padding:10px}
.sugg .n{font-size:.78rem; font-weight:800; letter-spacing:.4px}
.sugg .p{margin-top:6px; font-family:'Space Mono'; color:var(--neon); font-size:.74rem}

/* Toast & sync */
.sync-status {
  position: fixed; top: 76px; right: 16px; z-index: 1000;
  background: rgba(0, 0, 0, 0.9);
  border: 1px solid rgba(255,255,255,.14);
  padding: 8px 12px; border-radius: 999px;
  font-family: 'Space Mono'; font-size: 0.7rem;
  display: none;
}
.sync-status.syncing { border-color: rgba(0,243,255,.55); color: var(--cyber-blue); }
.sync-status.synced  { border-color: rgba(0,255,157,.55); color: var(--neon); }
.sync-status.error   { border-color: rgba(255,59,105,.55); color: var(--danger); }

.toast{
  position:fixed; right:16px; bottom:16px; max-width:340px; z-index:9999;
  background: rgba(10,10,12,.92);
  border:1px solid rgba(255,255,255,.12);
  padding: 12px 14px; border-radius: 14px;
  font-family:'Space Mono'; font-size:.74rem; letter-spacing:.6px;
  box-shadow: 0 18px 40px rgba(0,0,0,.55);
  display:none;
}
.toast.ok{border-color:rgba(0,255,157,.55)}
.toast.err{border-color:rgba(255,59,105,.55)}

@media (prefers-reduced-motion: reduce){
  *{animation:none !important;transition:none !important}
}
@media print{
  header, .btn, .controls, .sync-status, .toast {display:none !important}
  .wrap{grid-template-columns:1fr !important}
}
</style>
</head>

<body class="grid-bg scanlines tech-archive">
<div class="glow" aria-hidden="true"></div>

<header class="nav" role="banner">
  <div class="nav-inner">
    <div class="brand">
      <a href="<?=$homeUrl?>"><?=h(SITE_NAME)?></a>
      <small><?=h(VERSION)?> // CHECKOUT_READY</small>
    </div>

    <nav class="nav-links" aria-label="Primary">
      <a href="<?=$homeUrl?>">HOME</a>
      <a href="<?=$shopUrl?>">SHOP</a>
      <a href="<?=$lookbookUrl?>">LOOKBOOK</a>
      <a href="<?=$servicesUrl?>">SERVICES</a>
      <a class="nav-bag" href="<?=$cartUrl?>" id="cart-link">BAG [<span id="cart-count"><?=$totalQty?></span>]</a>
    </nav>
  </div>
</header>

<div id="sync-status" class="sync-status">SYNCING...</div>

<main class="wrap" id="main-content" role="main" tabindex="-1" aria-live="polite" aria-busy="false">

  <section class="card" aria-label="Cart items">
    <div class="card-h">
      <div>
        <p class="h-title">YOUR BAG</p>
        <p class="h-sub">DUAL_STORAGE_SYNC // <?= $totalQty ?> ITEM(S)</p>
      </div>
      <?php if (!empty($items)): ?>
        <form class="nojs-clear" method="post" action="<?=h($cartEndpoint,'attr')?>" style="margin:0">
          <input type="hidden" name="action" value="clear_cart">
          <input type="hidden" name="csrf" value="<?=h($csrf)?>">
          <button class="btn danger" type="submit" style="min-height:40px;padding:10px 12px">CLEAR</button>
        </form>
      <?php endif; ?>
    </div>

    <?php if (empty($items)): ?>
      <div class="empty">
        <h3>INVENTORY_EMPTY</h3>
        <p>NO_ITEMS_IN_TRANSPORT // INITIATE_SHOPPING_PROTOCOL</p>
        <div style="margin-top:14px">
          <a class="btn" href="<?=$shopUrl?>">CONTINUE BROWSING</a>
        </div>

        <div class="suggest" aria-label="Suggested items">
          <?php foreach ($suggested as $s):
            $img = '/' . ltrim((string)($s['image_url'] ?? '/images/placeholder.jpg'), '/');
            $pid = (int)$s['id'];
            $name = strtoupper((string)$s['name']);
            $p = (float)$s['price'];
            $href = $usingCleanUrls ? ($productBase . $pid) : ($productBase . $pid);
          ?>
          <a class="sugg" href="<?=h($href,'attr')?>">
            <img loading="lazy" decoding="async" src="<?=h($img)?>" alt="<?=h($name)?>" onerror="this.src='/images/placeholder.jpg'">
            <div class="pad">
              <div class="n"><?=h($name)?></div>
              <div class="p">$<?=h(number_format($p,2,'.',','))?></div>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php else: ?>
      <div class="items">
        <?php foreach ($items as $it):
          $pid   = (int)($it['product_id'] ?? $it['id'] ?? 0);
          $qty   = max(1, (int)($it['quantity'] ?? 1));
          $size  = (string)($it['size'] ?? 'OS');
          $color = (string)($it['color'] ?? 'NOCOLOR');
          $name  = (string)($it['name'] ?? ('PRODUCT_'.$pid));
          $img   = '/' . ltrim((string)($it['image'] ?? '/images/placeholder.jpg'), '/');
          $unitC = isset($it['price_cents']) ? (int)$it['price_cents'] : price_to_cents((float)($it['price'] ?? 0));
          $lineC = $unitC * $qty;
          $key   = get_item_key($pid, $size, $color);

          $productHref = $usingCleanUrls ? ($productBase . $pid) : ($productBase . $pid);
        ?>
        <article class="item cart-item" data-key="<?=h($key,'attr')?>" data-pid="<?=$pid?>" data-size="<?=h($size,'attr')?>" data-color="<?=h($color,'attr')?>">
          <a href="<?=h($productHref,'attr')?>" style="display:block">
            <img class="thumb cart-thumb" src="<?=h($img)?>" alt="<?=h($name)?>" loading="lazy" decoding="async" onerror="this.src='/images/placeholder.jpg'">
          </a>

          <div>
            <p class="title cart-title"><?=h(strtoupper($name))?></p>
            <div class="meta">
              <span class="pill">SIZE <b><?=h($size)?></b></span>
              <span class="pill">COLOR <b><?=h($color)?></b></span>
            </div>
            <div class="unit">UNIT: $<?=h(cents_to_str($unitC))?></div>

            <form class="nojs-remove" method="post" action="<?=h($cartEndpoint,'attr')?>" style="margin-top:10px">
              <input type="hidden" name="action" value="remove_item">
              <input type="hidden" name="csrf" value="<?=h($csrf)?>">
              <input type="hidden" name="product_id" value="<?=$pid?>">
              <input type="hidden" name="size" value="<?=h($size,'attr')?>">
              <input type="hidden" name="color" value="<?=h($color,'attr')?>">
              <button class="remove" type="submit" aria-label="Remove <?=h($name)?>">REMOVE</button>
            </form>
          </div>

          <div class="right">
            <div class="controls">
              <div class="qty-wrap" role="group" aria-label="Quantity for <?=h($name)?>">
                <button class="qty-btn btn-minus" type="button" aria-label="Decrease quantity">−</button>
                <input class="qty-input" type="number" min="1" max="99" inputmode="numeric" value="<?=$qty?>" aria-label="Quantity">
                <button class="qty-btn btn-plus" type="button" aria-label="Increase quantity">+</button>
              </div>
            </div>
            <div class="line">$<span class="line-total"><?=h(cents_to_str($lineC))?></span></div>
            <div class="mini">ID: <?=h((string)$pid)?></div>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <aside class="card" aria-label="Order summary">
    <div class="card-h">
      <div>
        <p class="h-title">SUMMARY</p>
        <p class="h-sub">TAX + SHIPPING + DISCOUNT</p>
      </div>
    </div>

    <div class="summary">
      <div class="row"><div>Sub-Total</div><div>$<span id="subtotal"><?=h(cents_to_str((int)$totals['subtotal']))?></span></div></div>
      <div class="row"><div>Shipping</div><div>$<span id="shipping"><?=h(cents_to_str((int)$totals['shipping']))?></span></div></div>
      <div class="row"><div>Discount</div><div>− $<span id="discount"><?=h(cents_to_str((int)$totals['discount']))?></span></div></div>
      <div class="row"><div>Tax</div><div>$<span id="tax"><?=h(cents_to_str((int)$totals['tax']))?></span></div></div>
      <div class="row big"><div>Grand Total</div><div>$<span id="grand"><?=h(cents_to_str((int)$totals['grand_total']))?></span></div></div>

      <div style="margin-top:12px">
        <label for="ship" class="hint" style="display:block;margin-bottom:6px">SHIPPING METHOD</label>
        <select id="ship" class="select">
          <?php foreach (SHIPPING_METHODS as $m): ?>
            <option value="<?=h($m,'attr')?>" <?= strtoupper($shippingMethod)===$m ? 'selected' : '' ?>><?=h($m)?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="margin-top:12px">
        <label for="discount-code" class="hint" style="display:block;margin-bottom:6px">DISCOUNT CODE</label>
        <div class="inline">
          <input class="input" id="discount-code" placeholder="e.g. WELCOME10" value="<?=h((string)($totals['discount_code'] ?? ''))?>">
          <button class="btn" id="apply-discount" type="button">APPLY</button>
        </div>
        <button class="btn secondary" id="clear-discount" type="button" style="margin-top:8px;width:100%">CLEAR DISCOUNT</button>
      </div>

      <div style="margin-top:14px; display:flex; gap:10px">
        <a class="btn secondary" style="flex:1" href="<?=$shopUrl?>">BACK TO SHOP</a>
        <?php if (!empty($items)): ?>
          <button class="btn checkout" style="flex:1" id="checkout-btn" type="button">CHECKOUT</button>
        <?php else: ?>
          <button class="btn secondary" style="flex:1" type="button" disabled>EMPTY</button>
        <?php endif; ?>
      </div>

      <div class="hint">Cart sync: session + localStorage (restores after refresh)</div>
    </div>
  </aside>
</main>

<div id="toast" class="toast"></div>

<script>
/* ============================================================================
   CART MANAGER v5.2.0 (ROUTE-AWARE + REAL TOTALS + UI FIX)
   ============================================================================ */
(function(){
  'use strict';

  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const cartId = document.documentElement.dataset.cartId || '';
  const checkoutToken = document.documentElement.dataset.checkoutToken || '';
  const cartEndpoint = document.documentElement.dataset.cartEndpoint || '/cart.php';

  const state = {
    updating: false,
    syncing: false,
    processingCheckout: false
  };

  const $  = (sel, root=document) => root.querySelector(sel);
  const $$ = (sel, root=document) => Array.from(root.querySelectorAll(sel));

  function showToast(msg, ok=true){
    const t = $('#toast');
    if(!t) return;
    t.className = 'toast ' + (ok ? 'ok' : 'err');
    t.textContent = msg;
    t.style.display = 'block';
    setTimeout(()=>{ t.style.display = 'none'; }, 2400);
  }

  function showSyncStatus(msg, type='syncing'){
    const s = $('#sync-status');
    if(!s) return;
    s.className = 'sync-status ' + type;
    s.textContent = msg;
    s.style.display = 'block';
    if(type === 'synced') setTimeout(() => { s.style.display = 'none'; }, 1800);
  }

  function moneyFromCents(c){ return (Number(c||0)/100).toFixed(2); }

  function applyTotals(totals){
    if(!totals) return;
    $('#subtotal').textContent = moneyFromCents(totals.subtotal);
    $('#shipping').textContent = moneyFromCents(totals.shipping);
    $('#discount').textContent = moneyFromCents(totals.discount);
    $('#tax').textContent      = moneyFromCents(totals.tax);
    $('#grand').textContent    = moneyFromCents(totals.grand_total);
    const cartCount = $('#cart-count');
    if(cartCount && typeof totals.total_qty !== 'undefined') cartCount.textContent = String(totals.total_qty);
  }

  function saveToLocalStorage(items) {
    try {
      const cartData = {
        items: items,
        timestamp: Date.now(),
        cart_id: cartId,
        checkout_token: checkoutToken,
        version: 'v5.2.0'
      };
      localStorage.setItem('dod_cart', JSON.stringify(cartData));
      localStorage.setItem('cart_version', 'v5.2.0');
      return true;
    } catch(e) {
      console.error('LocalStorage save failed:', e);
      return false;
    }
  }

  function loadFromLocalStorage() {
    try {
      const saved = localStorage.getItem('dod_cart');
      if(!saved) return null;
      const data = JSON.parse(saved);
      return data.items || [];
    } catch(e) {
      console.error('LocalStorage load failed:', e);
      return null;
    }
  }

  function getCartItemsFromPage() {
    const items = [];
    $$('.cart-item').forEach(article => {
      const pid = article.dataset.pid;
      const size = article.dataset.size;
      const color = article.dataset.color;
      const qty = parseInt($('.qty-input', article)?.value || 1, 10);
      const name = $('.cart-title', article)?.textContent || '';
      const unitPriceText = $('.unit', article)?.textContent || '';
      const unitPriceMatch = unitPriceText.match(/\$([\d,.]+)/);
      const price = unitPriceMatch ? parseFloat(unitPriceMatch[1].replace(/,/g, '')) : 0;
      const img = $('.cart-thumb', article)?.getAttribute('src') || '';

      items.push({
        id: pid,
        product_id: pid,
        name: name,
        price: price,
        image: img,
        size: size,
        color: color,
        quantity: qty
      });
    });
    return items;
  }

  async function safeJson(res){
    try{
      const text = await res.text();
      try { return JSON.parse(text); }
      catch(e){
        console.error('Non-JSON response:', text.slice(0, 400));
        return { ok:false, code:'NON_JSON', message:'Non-JSON response from server', raw:text };
      }
    }catch(e){
      return { ok:false, code:'NO_BODY', message:'Failed reading response body' };
    }
  }

  async function postAction(action, extraFields = {}) {
    const form = new FormData();
    form.append('action', action);
    form.append('csrf', csrfToken);
    Object.keys(extraFields).forEach(k => form.append(k, String(extraFields[k])));

    const res = await fetch(cartEndpoint + '?ajax=1', {
      method: 'POST',
      body: form,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    return await safeJson(res);
  }

  async function syncWithServer() {
    if(state.syncing) return;
    state.syncing = true;

    try {
      showSyncStatus('SYNCING...', 'syncing');

      const localItems = loadFromLocalStorage();
      if(!localItems || localItems.length === 0) {
        showSyncStatus('SYNCED', 'synced');
        state.syncing = false;
        return;
      }

      const data = await postAction('import_local', { items: JSON.stringify(localItems) });

      if(data && data.ok) {
        saveToLocalStorage(data.items);
        showSyncStatus('SYNCED', 'synced');
        if (data.totals) applyTotals(data.totals);
      } else {
        showSyncStatus('SYNC FAILED', 'error');
      }
    } catch(e) {
      console.error('Sync failed:', e);
      showSyncStatus('SYNC ERROR', 'error');
    } finally {
      state.syncing = false;
    }
  }

  async function prepareAndNavigateToCheckout(e) {
    e.preventDefault();
    e.stopPropagation();

    if (state.processingCheckout) return;
    state.processingCheckout = true;

    const checkoutBtn = $('#checkout-btn');
    if (checkoutBtn) {
      checkoutBtn.classList.add('loading');
      checkoutBtn.textContent = 'PREPARING...';
      checkoutBtn.disabled = true;
    }

    showSyncStatus('PREPARING CHECKOUT...', 'syncing');

    try {
      const currentItems = getCartItemsFromPage();
      if (currentItems.length === 0) throw new Error('Cart is empty');

      if (!saveToLocalStorage(currentItems)) {
        throw new Error('Failed to save cart locally');
      }

      const data = await postAction('prepare_checkout');

      if (data && data.ok && data.checkout_url) {
        showSyncStatus('READY', 'synced');
        showToast('Redirecting…');

        const updatedCartData = {
          items: currentItems,
          timestamp: Date.now(),
          cart_id: data.cart_id,
          checkout_token: data.checkout_token,
          version: 'v5.2.0'
        };
        localStorage.setItem('dod_cart', JSON.stringify(updatedCartData));

        setTimeout(() => { window.location.href = data.checkout_url; }, 450);
      } else {
        throw new Error(data?.message || 'Checkout preparation failed');
      }
    } catch (error) {
      console.error('Checkout error:', error);
      showToast('Checkout error: ' + error.message, false);
      showSyncStatus('CHECKOUT FAILED', 'error');

      if (checkoutBtn) {
        checkoutBtn.classList.remove('loading');
        checkoutBtn.textContent = 'CHECKOUT';
        checkoutBtn.disabled = false;
      }
    } finally {
      state.processingCheckout = false;
    }
  }

  async function updateQty(article, qty){
    if (state.updating) return;
    state.updating = true;
    $('main')?.setAttribute('aria-busy','true');

    try{
      const pid = article.dataset.pid;
      const size = article.dataset.size;
      const color = article.dataset.color;

      const data = await postAction('update_qty', {
        product_id: pid,
        size: size,
        color: color,
        quantity: String(qty)
      });

      if (data && data.ok){
        $('.qty-input', article).value = String(qty);

        const unitText = $('.unit', article).textContent || '';
        const m = unitText.match(/\$([\d,.]+)/);
        const unitPrice = m ? parseFloat(m[1].replace(/,/g,'')) : 0;

        const lineTotal = unitPrice * qty;
        $('.line-total', article).textContent = lineTotal.toFixed(2);

        if (data.totals) applyTotals(data.totals);
        saveToLocalStorage(data.items || getCartItemsFromPage());
        showToast('Quantity updated');
      } else {
        showToast(data?.message || 'Update failed', false);
      }
    }catch(e){
      console.error(e);
      showToast('Network error', false);
    }finally{
      state.updating = false;
      $('main')?.setAttribute('aria-busy','false');
    }
  }

  async function removeItem(article){
    if (state.updating) return;
    state.updating = true;

    try{
      const pid = article.dataset.pid;
      const size = article.dataset.size;
      const color = article.dataset.color;

      const data = await postAction('remove_item', {
        product_id: pid,
        size: size,
        color: color
      });

      if (data && data.ok){
        article.remove();
        if (data.totals) applyTotals(data.totals);

        saveToLocalStorage(data.items || getCartItemsFromPage());
        showToast('Item removed');

        if (document.querySelectorAll('.cart-item').length === 0) {
          setTimeout(() => location.reload(), 650);
        }
      } else {
        showToast(data?.message || 'Remove failed', false);
      }
    }catch(e){
      console.error(e);
      showToast('Network error', false);
    }finally{
      state.updating=false;
    }
  }

  function bind(){
    $$('.cart-item').forEach(article => {
      const minus = $('.btn-minus', article);
      const plus  = $('.btn-plus', article);
      const input = $('.qty-input', article);

      minus?.addEventListener('click', () => {
        const q = Math.max(1, Math.min(99, Number(input.value||1) - 1));
        updateQty(article, q);
      });

      plus?.addEventListener('click', () => {
        const q = Math.max(1, Math.min(99, Number(input.value||1) + 1));
        updateQty(article, q);
      });

      input?.addEventListener('change', () => {
        const q = Math.max(1, Math.min(99, Number(input.value||1)));
        updateQty(article, q);
      });

      const removeForm = $('.nojs-remove', article);
      if (removeForm) {
        removeForm.addEventListener('submit', (e) => {
          e.preventDefault();
          removeItem(article);
        });
      }
    });

    const clearForm = $('.nojs-clear');
    if (clearForm) {
      clearForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!confirm('Clear entire cart?')) return;

        const data = await postAction('clear_cart');
        if (data?.ok) {
          localStorage.removeItem('dod_cart');
          localStorage.removeItem('cart_version');
          location.reload();
        } else {
          showToast(data?.message || 'Clear failed', false);
        }
      });
    }

    const checkoutBtn = $('#checkout-btn');
    if (checkoutBtn) checkoutBtn.addEventListener('click', prepareAndNavigateToCheckout);

    // Shipping
    const ship = $('#ship');
    ship?.addEventListener('change', async () => {
      showSyncStatus('UPDATING SHIPPING...', 'syncing');
      const method = ship.value;
      const data = await postAction('set_shipping', { method });
      if (data?.ok) {
        applyTotals(data.totals);
        showSyncStatus('SHIPPING SET', 'synced');
        showToast('Shipping updated');
      } else {
        showSyncStatus('SHIPPING ERROR', 'error');
        showToast(data?.message || 'Shipping update failed', false);
      }
    });

    // Discount
    $('#apply-discount')?.addEventListener('click', async () => {
      const code = ($('#discount-code')?.value || '').trim();
      if (!code) return showToast('Enter a code', false);

      showSyncStatus('APPLYING...', 'syncing');
      const data = await postAction('apply_discount', { code });
      if (data?.ok) {
        applyTotals(data.totals);
        $('#discount-code').value = data.totals?.discount_code || code.toUpperCase();
        showSyncStatus('DISCOUNT OK', 'synced');
        showToast('Discount applied');
      } else {
        showSyncStatus('DISCOUNT FAIL', 'error');
        showToast(data?.message || 'Discount failed', false);
      }
    });

    $('#clear-discount')?.addEventListener('click', async () => {
      showSyncStatus('CLEARING...', 'syncing');
      const data = await postAction('clear_discount');
      if (data?.ok) {
        applyTotals(data.totals);
        $('#discount-code').value = '';
        showSyncStatus('CLEARED', 'synced');
        showToast('Discount cleared');
      } else {
        showSyncStatus('ERROR', 'error');
        showToast(data?.message || 'Clear failed', false);
      }
    });
  }

  async function initialSync() {
    const localItems = loadFromLocalStorage();
    if(localItems && localItems.length > 0) {
      await syncWithServer();
    } else {
      // still refresh totals from server state in case discount/shipping were set earlier
      const data = await postAction('get_cart_state');
      if (data?.ok && data.totals) applyTotals(data.totals);
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    bind();
    initialSync();
  });
})();
</script>
</body>
</html>
<?php
// END cart.php v5.2.0
?>