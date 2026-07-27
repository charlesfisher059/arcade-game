<?php
// stripe_webhook.php — STRIPE WEBHOOK HANDLER
// Path: /home2/asqrtyte/public_html/stripe_webhook.php
declare(strict_types=1);

/*
 * Corrected 2026-07-17 (PROJECT_AUDIT C3). Two bugs fixed vs. the
 * previously-live copy:
 *   1. It wrote `orders.updated_at = NOW()`, but that column does not
 *      exist on the orders table — so every UPDATE here threw "Unknown
 *      column 'updated_at'" and the handler 500'd. Removed.
 *   2. has_column() used `SHOW COLUMNS FROM t LIKE ?` with a bound
 *      param, which fails on this host (PDO emulation off — see the
 *      db rule in SKILL.md). Switched to INFORMATION_SCHEMA.
 * Also added a best-effort order_status_history insert on the PAID
 * transition so the admin order view's audit trail is complete.
 *
 * Requires the order to already exist (created PENDING by
 * create_checkout_session.php, which now passes its id as
 * metadata.order_id). This webhook is the authoritative PAID confirmation.
 */

/* =================================
   BOOTSTRAP
================================= */
require_once __DIR__ . '/db_connect.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    error_log('[STRIPE_WEBHOOK] DB connection missing');
    exit('DB error');
}

/* Composer Autoload */
$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    error_log('[STRIPE_WEBHOOK] Composer autoload missing');
    exit('Autoload missing');
}
require_once $autoload;

/* =================================
   STRIPE CONFIG
================================= */
$stripeSecret  = getenv('STRIPE_SECRET_KEY') ?: '';
$webhookSecret = getenv('STRIPE_WEBHOOK_SECRET') ?: '';

if (empty($stripeSecret) && file_exists('/home2/asqrtyte/config/stripe.php')) {
    try {
        ob_start();
        require '/home2/asqrtyte/config/stripe.php';
        ob_end_clean();
    } catch (Throwable $e) {
        error_log('[STRIPE_WEBHOOK] Failed to load config: ' . $e->getMessage());
    }
}

if (!$stripeSecret || !$webhookSecret) {
    http_response_code(500);
    error_log('[STRIPE_WEBHOOK] Stripe config missing (secret=' . strlen($stripeSecret) . ', webhook=' . strlen($webhookSecret) . ')');
    exit('Stripe config missing');
}

\Stripe\Stripe::setApiKey($stripeSecret);
\Stripe\Stripe::setApiVersion('2023-10-16');

/* =================================
   READ RAW REQUEST
================================= */
$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (!$payload || !$sigHeader) {
    http_response_code(400);
    exit('Invalid request');
}

/* =================================
   VERIFY SIGNATURE
================================= */
try {
    $event = \Stripe\Webhook::constructEvent(
        $payload,
        $sigHeader,
        $webhookSecret
    );
} catch (Throwable $e) {
    http_response_code(400);
    exit('Invalid signature');
}

/* =================================
   HELPERS
================================= */
// INFORMATION_SCHEMA, not SHOW COLUMNS LIKE :param (which fails silently
// on this host with PDO emulation off).
function has_column(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
        );
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/* =================================
   EVENT HANDLING
================================= */
try {
    switch ($event->type) {

        /* =============================
           CHECKOUT PAID
        ============================== */
        case 'checkout.session.completed': {
            $session = $event->data->object;
            if (($session->payment_status ?? '') !== 'paid') break;

            $orderId = (int)($session->metadata->order_id ?? 0);
            if ($orderId <= 0) break;

            $pdo->beginTransaction();

            $check = $pdo->prepare("SELECT order_status FROM orders WHERE id = ? FOR UPDATE");
            $check->execute([$orderId]);
            $current = $check->fetchColumn();

            if ($current !== false && $current !== 'PAID') {
                $pdo->prepare("
                    UPDATE orders
                    SET order_status = 'PAID', stripe_session_id = ?
                    WHERE id = ?
                ")->execute([$session->id, $orderId]);

                // Best-effort audit trail (matches admin order flow).
                try {
                    $pdo->prepare("INSERT INTO order_status_history (order_id, status, changed_by) VALUES (?, 'PAID', 'stripe_webhook')")
                        ->execute([$orderId]);
                } catch (Throwable $e) {
                    error_log('[STRIPE_WEBHOOK] history insert: ' . $e->getMessage());
                }
            }

            $pdo->commit();
            break;
        }

        /* =============================
           REFUND CREATED / UPDATED
        ============================== */
        case 'refund.created':
        case 'refund.updated':
        case 'charge.refunded': {

            $charge = $event->data->object;
            $paymentIntentId = $charge->payment_intent ?? null;
            if (!$paymentIntentId) break;

            $sessions = \Stripe\Checkout\Session::all([
                'payment_intent' => $paymentIntentId,
                'limit' => 1
            ]);

            if (empty($sessions->data)) break;

            $session = $sessions->data[0];
            $orderId = (int)($session->metadata->order_id ?? 0);
            if ($orderId <= 0) break;

            $amountRefunded = ($charge->amount_refunded ?? 0) / 100;
            $amountTotal    = ($charge->amount ?? 0) / 100;

            $status = ($amountRefunded >= $amountTotal)
                ? 'REFUNDED'
                : 'PARTIALLY_REFUNDED';

            $pdo->beginTransaction();

            $sql = "UPDATE orders SET order_status = ?";
            $params = [$status];

            if (has_column($pdo, 'orders', 'refund_amount')) {
                $sql .= ", refund_amount = ?";
                $params[] = $amountRefunded;
            }

            $sql .= " WHERE id = ?";
            $params[] = $orderId;

            $pdo->prepare($sql)->execute($params);

            try {
                $pdo->prepare("INSERT INTO order_status_history (order_id, status, changed_by) VALUES (?, ?, 'stripe_webhook')")
                    ->execute([$orderId, $status]);
            } catch (Throwable $e) {
                error_log('[STRIPE_WEBHOOK] history insert: ' . $e->getMessage());
            }

            $pdo->commit();
            break;
        }

        default:
            // Ignore unneeded events
            break;
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[STRIPE_WEBHOOK_ERROR] ' . $e->getMessage());
    http_response_code(500);
    exit('Webhook error');
}

/* =================================
   ACK STRIPE
================================= */
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'ok']);