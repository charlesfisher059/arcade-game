<?php
// create_checkout_session.php — STRIPE CHECKOUT SESSION HANDLER v4.2
declare(strict_types=1);

// Session configuration (matches cart.php v5.1 and checkout.php v4.1)
$https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 604800,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Diagnostics toggle: append ?debug=1 to see status JSON
$debug = (isset($_GET['debug']) && $_GET['debug'] === '1');

// Stripe configuration — robust autoloader
$stripeLoaded = false;
try {
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
        $stripeLoaded = true;
    } elseif (file_exists(__DIR__ . '/autoload.php')) { // fallback if vendor path differs on server
        require_once __DIR__ . '/autoload.php';
        $stripeLoaded = true;
    }
} catch (Throwable $e) {
    error_log('Stripe autoload error: ' . $e->getMessage());
    $stripeLoaded = false;
}

if (!$stripeLoaded || !class_exists('\\Stripe\\Stripe')) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Stripe SDK not installed']);
    exit;
}

// Load Stripe key from env first, then try config file
$stripeSecretKey = getenv('STRIPE_SECRET_KEY') ?: '';

// Fallback to config file if env not set
$configLoaded = false;
if (empty($stripeSecretKey)) {
    if (file_exists('/home2/asqrtyte/config/stripe.php')) {
        try {
            $configContent = file_get_contents('/home2/asqrtyte/config/stripe.php');
            // Extract the key from setApiKey call: \Stripe\Stripe::setApiKey('sk_live_...')
            if (preg_match("/setApiKey\('(sk_[^']+)'\)/", $configContent, $matches)) {
                $stripeSecretKey = $matches[1];
                $configLoaded = true;
                error_log('Stripe key extracted from config file');
            } else {
                // Try old approach - require the file
                require '/home2/asqrtyte/config/stripe.php';
                $configLoaded = true;
                error_log('Stripe config file included');
            }
        } catch (Throwable $e) {
            error_log('Failed to load stripe config: ' . $e->getMessage());
        }
    } else {
        error_log('Stripe config file not found at: /home2/asqrtyte/config/stripe.php');
    }
} else {
    error_log('Stripe key loaded from environment');
}

// Debug diagnostics
if ($debug) {
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'diagnostics' => [
            'vendor_autoload_exists' => file_exists(__DIR__ . '/vendor/autoload.php'),
            'config_stripe_exists' => file_exists(__DIR__ . '/config/stripe.php'),
            'stripe_class_exists' => class_exists('\\Stripe\\Stripe'),
            'key_from_env' => !empty($stripeSecretKey),
            'key_from_config' => $configLoaded,
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'has_email' => !empty($_POST['email'] ?? ''),
            'csrf_present' => !empty($_POST['csrf_token'] ?? ''),
            'session_csrf_present' => !empty($_SESSION['csrf_token'] ?? ''),
            'cart_items_count' => count($_SESSION['cart']['items'] ?? [])
        ]
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// Enforce POST for live requests (debug bypasses)
$method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
if (!$debug && $method !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'error' => 'Method not allowed',
        'received_method' => $method,
        'expected' => 'POST'
    ]);
    exit;
}

// Verify Stripe key is set (either from env or config file)
if (empty($stripeSecretKey) && !$configLoaded) {
    http_response_code(500);
    echo json_encode(['error' => 'Stripe API key not configured or invalid']);
    exit;
}

// Set the key if we loaded it
if (!empty($stripeSecretKey)) {
    \Stripe\Stripe::setApiKey($stripeSecretKey);
}

header('Content-Type: application/json');

require_once __DIR__ . '/db_connect.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['error' => 'Database unavailable']);
    exit;
}

function normalize_country(string $country): string {
    $country = strtoupper(trim($country));
    $country = preg_replace('/[^A-Z]/', '', $country) ?: 'US';
    return substr($country, 0, 2);
}

function normalize_state(string $state): string {
    $state = strtoupper(trim($state));
    $state = preg_replace('/[^A-Z]/', '', $state) ?: '';
    return substr($state, 0, 2);
}

function resolve_tax_rate_percent(string $country, string $state): float {
    $default = (float)(getenv('DOD_TAX_RATE') ?: 0.0);
    if ($country !== 'US') {
        return max(0.0, $default);
    }

    $json = (string)(getenv('DOD_TAX_BY_STATE_JSON') ?: '');
    if ($json !== '') {
        $map = json_decode($json, true);
        if (is_array($map)) {
            $rate = $map[$state] ?? $map[strtolower($state)] ?? null;
            if ($rate !== null && is_numeric($rate)) {
                return max(0.0, (float)$rate);
            }
        }
    }

    return max(0.0, $default);
}

function resolve_shipping_cents(int $subtotalCents, string $country, string $state): int {
    $freeOver = (float)(getenv('DOD_SHIPPING_FREE_OVER') ?: 150.0);
    $flat = (float)(getenv('DOD_SHIPPING_FLAT') ?: 8.95);
    $intl = (float)(getenv('DOD_SHIPPING_INTL') ?: 25.0);
    $byStateJson = (string)(getenv('DOD_SHIPPING_BY_STATE_JSON') ?: '');

    if ($country !== 'US') {
        return max(0, (int)round($intl * 100));
    }
    if ($subtotalCents >= (int)round($freeOver * 100)) {
        return 0;
    }

    if ($byStateJson !== '') {
        $map = json_decode($byStateJson, true);
        if (is_array($map)) {
            $rate = $map[$state] ?? $map[strtolower($state)] ?? null;
            if ($rate !== null && is_numeric($rate)) {
                return max(0, (int)round(((float)$rate) * 100));
            }
        }
    }

    return max(0, (int)round($flat * 100));
}

function parse_client_cart_items(string $raw): array {
    if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return [];

    $items = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) continue;
        $pid = (int)preg_replace('/[^\d]/', '', (string)($item['product_id'] ?? $item['id'] ?? '0'));
        $qty = (int)($item['quantity'] ?? 1);
        $qty = max(1, min(99, $qty));
        $size = trim((string)($item['size'] ?? ''));
        $color = trim((string)($item['color'] ?? ''));
        if ($pid <= 0) continue;
        $items[] = ['product_id' => $pid, 'quantity' => $qty, 'size' => $size, 'color' => $color];
    }
    return $items;
}

// CSRF protection — FAIL CLOSED (fixed 2026-07-17, PROJECT_AUDIT C1).
// Previously this only rejected when BOTH tokens were present, so a
// forged cross-site request that simply omitted csrf_token sailed
// through. The real checkout form (checkout.php) always sends the token
// via FormData, so requiring it here does not break legitimate checkout.
$token = (string)($_POST['csrf_token'] ?? '');
$sessionToken = (string)($_SESSION['csrf_token'] ?? '');
if ($sessionToken === '' || $token === '' || !hash_equals($sessionToken, $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid or missing security token. Refresh the checkout page and try again.']);
    exit;
}

try {
    $customerName = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $city = trim((string)($_POST['city'] ?? ''));
    $state = normalize_state((string)($_POST['state'] ?? ''));
    $zip = strtoupper(trim((string)($_POST['zip'] ?? '')));
    $country = normalize_country((string)($_POST['country'] ?? 'US'));
    $cart_source = trim((string)($_POST['cart_source'] ?? 'session'));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Valid email is required');
    }

    $clientItems = parse_client_cart_items((string)($_POST['cart_data'] ?? ''));
    if (empty($clientItems)) {
        $sessionItems = $_SESSION['cart']['items'] ?? [];
        if (is_array($sessionItems)) {
            foreach ($sessionItems as $si) {
                if (!is_array($si)) continue;
                $pid = (int)($si['product_id'] ?? $si['id'] ?? 0);
                if ($pid <= 0) continue;
                $qty = max(1, min(99, (int)($si['quantity'] ?? 1)));
                $clientItems[] = [
                    'product_id' => $pid,
                    'quantity' => $qty,
                    'size' => trim((string)($si['size'] ?? '')),
                    'color' => trim((string)($si['color'] ?? '')),
                ];
            }
        }
    }
    if (empty($clientItems)) {
        throw new Exception('Cart is empty');
    }

    $qtyByProduct = [];
    $variantByProduct = [];
    foreach ($clientItems as $ci) {
        $pid = (int)$ci['product_id'];
        $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + (int)$ci['quantity'];
        if (!isset($variantByProduct[$pid])) {
            $variantByProduct[$pid] = ['size' => $ci['size'] ?? '', 'color' => $ci['color'] ?? ''];
        }
    }
    foreach ($qtyByProduct as $pid => $qty) {
        $qtyByProduct[$pid] = max(1, min(99, (int)$qty));
    }

    $productIds = array_values(array_unique(array_map('intval', array_keys($qtyByProduct))));
    if (empty($productIds)) {
        throw new Exception('No valid products in cart');
    }

    $ph = implode(',', array_fill(0, count($productIds), '?'));
    $sql = "SELECT id, name, price, stock, image_url FROM products WHERE id IN ($ph)";
    $stmt = $pdo->prepare($sql);
    foreach ($productIds as $i => $pid) {
        $stmt->bindValue($i + 1, $pid, PDO::PARAM_INT);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (empty($rows)) {
        throw new Exception('Products not found');
    }

    $productsById = [];
    foreach ($rows as $r) {
        $productsById[(int)$r['id']] = $r;
    }

    $scheme = $_SERVER['REQUEST_SCHEME'] ?? ($https ? 'https' : 'http');
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $makeAbsolute = function (string $path) use ($scheme, $host): string {
        if ($path === '') return '';
        if (filter_var($path, FILTER_VALIDATE_URL)) return $path;
        $clean = '/' . ltrim($path, '/');
        return $scheme . '://' . $host . $clean;
    };

    $line_items = [];
    $subtotalCents = 0;

    foreach ($qtyByProduct as $pid => $quantity) {
        $product = $productsById[$pid] ?? null;
        if (!is_array($product)) {
            throw new Exception('Product missing: ' . $pid);
        }

        $unitAmountCents = (int)round(((float)($product['price'] ?? 0)) * 100);
        if ($unitAmountCents <= 0) {
            throw new Exception('Invalid price for product ' . $pid);
        }
        $stock = (int)($product['stock'] ?? 0);
        if ($stock > 0 && $quantity > $stock) {
            throw new Exception('Insufficient stock for product ' . $pid);
        }

        $descParts = [];
        $v = $variantByProduct[$pid] ?? ['size' => '', 'color' => ''];
        if (!empty($v['size'])) $descParts[] = 'Size: ' . substr((string)$v['size'], 0, 24);
        if (!empty($v['color'])) $descParts[] = 'Color: ' . substr((string)$v['color'], 0, 24);
        $description = !empty($descParts) ? implode(' | ', $descParts) : null;

        $imageUrl = $makeAbsolute((string)($product['image_url'] ?? ''));
        $line_items[] = [
            'price_data' => [
                'currency' => 'usd',
                'product_data' => [
                    'name' => (string)($product['name'] ?? ('Product #' . $pid)),
                    'description' => $description,
                    'images' => $imageUrl !== '' ? [$imageUrl] : [],
                ],
                'unit_amount' => $unitAmountCents,
            ],
            'quantity' => $quantity,
        ];
        $subtotalCents += ($unitAmountCents * $quantity);
    }

    if ($subtotalCents <= 0 || empty($line_items)) {
        throw new Exception('Cart pricing failed');
    }

    $shippingCents = resolve_shipping_cents($subtotalCents, $country, $state);
    if ($shippingCents > 0) {
        $line_items[] = [
            'price_data' => [
                'currency' => 'usd',
                'product_data' => ['name' => 'Shipping'],
                'unit_amount' => $shippingCents,
            ],
            'quantity' => 1,
        ];
    }

    $taxRate = resolve_tax_rate_percent($country, $state);
    $taxShipping = ((string)getenv('DOD_TAX_SHIPPING') === '1');
    $taxBase = $subtotalCents + ($taxShipping ? $shippingCents : 0);
    $taxCents = ($taxRate > 0) ? (int)round($taxBase * ($taxRate / 100)) : 0;
    if ($taxCents > 0) {
        $line_items[] = [
            'price_data' => [
                'currency' => 'usd',
                'product_data' => ['name' => 'Sales Tax'],
                'unit_amount' => $taxCents,
            ],
            'quantity' => 1,
        ];
    }

    if ($customerName !== '' || $address !== '') {
        $_SESSION['shipping'] = [
            'name' => $customerName,
            'email' => $email,
            'address' => $address,
            'city' => $city,
            'state' => $state,
            'zip' => $zip,
            'country' => $country
        ];
    }

    // ---- Create a PENDING order record BEFORE payment (2026-07-17, AUDIT C2). ----
    // Previously NO order was ever recorded for cart checkout: this file
    // didn't insert one, and stripe_webhook.php keys off metadata.order_id
    // which was never set -- so real customers paid and the store recorded
    // nothing. Now we pre-record a PENDING order and pass its id to Stripe;
    // the webhook (and checkout_success.php as a fallback) flips it to PAID.
    //
    // SAFETY: order recording is best-effort. If it fails we log and STILL
    // create the Stripe session with order_id=0, so a bookkeeping bug can
    // never block a customer from paying (same outcome as before this change).
    $orderId = 0;
    $totalCents = $subtotalCents + $shippingCents + $taxCents;
    $customerId = isset($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : 0;
    $shippingLines = array_filter([
        $customerName,
        $address,
        trim($city . ' ' . $state . ' ' . $zip),
        $country,
    ], static fn($v) => trim((string)$v) !== '');
    $shippingBlock = implode("\n", $shippingLines);

    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO orders
             (customer_id, email, name, order_status, subtotal_cents, shipping_cents, tax_cents, total_cents, shipping_address)
             VALUES (?, ?, ?, 'PENDING', ?, ?, ?, ?, ?)"
        )->execute([
            $customerId ?: null, $email, $customerName,
            $subtotalCents, $shippingCents, $taxCents, $totalCents, $shippingBlock
        ]);
        $orderId = (int)$pdo->lastInsertId();

        $itemStmt = $pdo->prepare(
            "INSERT INTO order_items (order_id, product_id, product_name, price, quantity, size)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        foreach ($qtyByProduct as $pid => $quantity) {
            $product = $productsById[$pid] ?? null;
            if (!is_array($product)) continue;
            $variant = $variantByProduct[$pid] ?? ['size' => '', 'color' => ''];
            $itemStmt->execute([
                $orderId, (int)$pid,
                (string)($product['name'] ?? ('Product #' . $pid)),
                (float)($product['price'] ?? 0),
                (int)$quantity,
                (string)($variant['size'] ?? ''),
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[CREATE_CHECKOUT_ORDER] pre-record failed (payment still proceeds): ' . $e->getMessage());
        $orderId = 0;
    }

    $checkout_session = \Stripe\Checkout\Session::create([
        'payment_method_types' => ['card', 'afterpay_clearpay', 'klarna'],
        'line_items' => $line_items,
        'mode' => 'payment',
        'allow_promotion_codes' => true,
        'success_url' => ($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . $_SERVER['HTTP_HOST'] . '/checkout_success.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => ($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . $_SERVER['HTTP_HOST'] . '/checkout.php',
        'customer_email' => $email,
        'shipping_address_collection' => [
            'allowed_countries' => ['US'],
        ],
        'metadata' => [
            'order_id' => $orderId,
            'customer_name' => $customerName,
            'customer_email' => $email,
            'shipping_address' => $address,
            'shipping_city' => $city,
            'shipping_state' => $state,
            'shipping_zip' => $zip,
            'cart_source' => $cart_source,
            'subtotal_cents' => $subtotalCents,
            'shipping_cents' => $shippingCents,
            'tax_cents' => $taxCents,
            'tax_rate_percent' => $taxRate
        ]
    ]);

    // Link the Stripe session back to the order so checkout_success.php can
    // find it by session_id even if the webhook hasn't fired yet. Best-effort.
    if ($orderId > 0) {
        try {
            $pdo->prepare("UPDATE orders SET stripe_session_id = ? WHERE id = ?")
                ->execute([$checkout_session->id, $orderId]);
        } catch (Throwable $e) {
            error_log('[CREATE_CHECKOUT_ORDER] session-id link failed: ' . $e->getMessage());
        }
    }

    // Return session ID to client
    echo json_encode([
        'id' => $checkout_session->id,
        'url' => $checkout_session->url
    ]);

} catch (\Stripe\Exception\ApiErrorException $e) {
    http_response_code(400);
    echo json_encode(['error' => 'Stripe error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}