<?php
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/db_connect.php';

// Load Stripe key from env first, then config file fallback
$stripeSecret = getenv('STRIPE_SECRET_KEY') ?: '';
if (empty($stripeSecret) && file_exists('/home2/asqrtyte/config/stripe.php')) {
    try {
        ob_start();
        require '/home2/asqrtyte/config/stripe.php';
        ob_end_clean();
    } catch (Throwable $e) {
        error_log('Failed to load stripe config: ' . $e->getMessage());
    }
}

if (!empty($stripeSecret)) {
    \Stripe\Stripe::setApiKey($stripeSecret);
}

$sessionId = $_GET['session_id'] ?? '';
if (!$sessionId) {
    header("Location: index.php");
    exit;
}

$session = \Stripe\Checkout\Session::retrieve($sessionId);
$orderId = $session->metadata->order_id ?? null;

if ($orderId) {
    $stmt = $pdo->prepare("UPDATE orders SET order_status='PAID' WHERE id=?");
    $stmt->execute([(int)$orderId]);
}
?>
<!DOCTYPE html>
<html>
<head>
<title>PAYMENT CONFIRMED</title>
</head>
<body>
<h1>PAYMENT SUCCESSFUL</h1>
<p>Your order is confirmed.</p>
<a href="index.php">Return Home</a>
<script>
localStorage.removeItem('dod_cart');
</script>
</body>
</html>
