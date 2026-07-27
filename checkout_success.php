<?php
// create_checkout_session.php — STRIPE CHECKOUT SESSION HANDLER v4.1
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
    if (file_exists('/home2/asqrtyte/public_html/config/stripe.php')) {
        try {
            $configContent = file_get_contents('/home2/asqrtyte/public_html/config/stripe.php');
            // Extract the key from setApiKey call: \Stripe\Stripe::setApiKey('sk_live_...')
            if (preg_match("/setApiKey\('(sk_[^']+)'\)/", $configContent, $matches)) {
                $stripeSecretKey = $matches[1];
                $configLoaded = true;
                error_log('Stripe key extracted from config file');
            } else {
                // Try old approach - require the file
                require '/home2/asqrtyte/public_html/config/stripe.php';
                $configLoaded = true;
                error_log('Stripe config file included');
            }
        } catch (Throwable $e) {
            error_log('Failed to load stripe config: ' . $e->getMessage());
        }
    } else {
        error_log('Stripe config file not found at: /home2/asqrtyte/public_html/config/stripe.php');
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

// CSRF protection (optional for testing)
$token = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';
// Allow requests without CSRF for API testing; enforce if both present
if (!empty($token) && !empty($sessionToken) && !hash_equals($sessionToken, $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

try {
    // Get optional shipping info from POST (Stripe will collect if not provided)
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $zip = trim($_POST['zip'] ?? '');
    $country = trim($_POST['country'] ?? 'US');
    $cart_source = $_POST['cart_source'] ?? 'session';

    // Email is required for Stripe session
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Valid email is required');
    }

    // Get cart items - try POST cart_data first, then fall back to SESSION
    $items = [];
    
    // Try to parse cart_data from POST (sent by checkout.php)
    $cartDataPost = $_POST['cart_data'] ?? '';
    if (!empty($cartDataPost)) {
        $decoded = json_decode($cartDataPost, true);
        if (is_array($decoded)) {
            $items = $decoded;
        }
    }
    
    // Fall back to SESSION cart if POST cart is empty
    if (empty($items)) {
        $cartData = $_SESSION['cart'] ?? ['items' => []];
        $items = $cartData['items'] ?? [];
    }

    if (empty($items)) {
        throw new Exception('Cart is empty');
    }

    // Tax/shipping configuration (defaults to zero if env not set)
    $taxRate = (float)(getenv('DOD_TAX_RATE') ?: 0); // percent
    $shippingCents = (int)round(((float)(getenv('DOD_SHIPPING_FLAT') ?: 0)) * 100);

    // Build Stripe line items
    $line_items = [];
    $subtotalCents = 0;

    $scheme = $_SERVER['REQUEST_SCHEME'] ?? ($https ? 'https' : 'http');
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $makeAbsolute = function (string $path) use ($scheme, $host): string {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }
        $clean = '/' . ltrim($path, '/');
        return $scheme . '://' . $host . $clean;
    };

    foreach ($items as $item) {
        $name = $item['name'] ?? 'Unknown Product';
        $quantity = (int)($item['quantity'] ?? 1);
        
        // Handle both price (dollars) and price_cents (cents)
        $price_cents = (int)($item['price_cents'] ?? 0);
        if ($price_cents === 0 && isset($item['price'])) {
            $price_cents = (int)round((float)($item['price']) * 100);
        }
        
        // Skip items with invalid pricing
        if ($price_cents <= 0 || $quantity <= 0) {
            continue;
        }

        $description_parts = [];
        if (!empty($item['size'])) {
            $description_parts[] = 'Size: ' . $item['size'];
        }
        if (!empty($item['color'])) {
            $description_parts[] = 'Color: ' . $item['color'];
        }
        $description = !empty($description_parts) ? implode(' | ', $description_parts) : null;

        $imageUrl = '';
        if (!empty($item['image'])) {
            $imageUrl = $makeAbsolute((string)$item['image']);
        }

        $line_items[] = [
            'price_data' => [
                'currency' => 'usd',
                'product_data' => [
                    'name' => $name,
                    'description' => $description,
                    'images' => $imageUrl ? [$imageUrl] : []
                ],
                'unit_amount' => $price_cents,
            ],
            'quantity' => $quantity,
        ];

        $subtotalCents += $price_cents * $quantity;
    }

    if (empty($line_items)) {
        throw new Exception('No valid items in cart');
    }

    // Optional flat shipping line item
    if ($shippingCents > 0) {
        $line_items[] = [
            'price_data' => [
                'currency' => 'usd',
                'product_data' => [
                    'name' => 'Shipping',
                ],
                'unit_amount' => $shippingCents,
            ],
            'quantity' => 1,
        ];
    }

    // Optional tax line item (simple rate on subtotal + shipping)
    if ($taxRate > 0) {
        $taxCents = (int)round(($subtotalCents + $shippingCents) * ($taxRate / 100));
        if ($taxCents > 0) {
            $line_items[] = [
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [ 'name' => 'Sales Tax' ],
                    'unit_amount' => $taxCents,
                ],
                'quantity' => 1,
            ];
        }
    }

    // Store shipping info in session for success page (if provided)
    if (!empty($name) || !empty($address)) {
        $_SESSION['shipping'] = [
            'name' => $name,
            'email' => $email,
            'address' => $address,
            'city' => $city,
            'state' => $state,
            'zip' => $zip,
            'country' => $country
        ];
    }

    // Create Stripe checkout session
    $checkout_session = \Stripe\Checkout\Session::create([
        'payment_method_types' => ['card', 'afterpay_clearpay', 'klarna'],
        'line_items' => $line_items,
        'mode' => 'payment',
        'success_url' => ($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . $_SERVER['HTTP_HOST'] . '/checkout_success.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => ($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . $_SERVER['HTTP_HOST'] . '/checkout.php',
        'customer_email' => $email,
        'shipping_address_collection' => [
            'allowed_countries' => ['US'],
        ],
        'metadata' => [
            'customer_name' => $name,
            'customer_email' => $email,
            'shipping_address' => $address,
            'shipping_city' => $city,
            'shipping_state' => $state,
            'shipping_zip' => $zip,
            'cart_source' => $cart_source,
            'subtotal_cents' => $subtotalCents,
            'shipping_cents' => $shippingCents,
            'tax_rate_percent' => $taxRate
        ]
    ]);

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
