<?php
/**
 * privacy-policy.php
 * Diamonds Outta Dirt — Privacy Policy (v4.6 Cyberpunk/Tactical)
 * SELF-CONTAINED VERSION - No external files needed
 *
 * UPGRADES (v4.6):
 * - Safe HTTPS-aware session cookie params + strict session mode
 * - Security headers (nosniff, frame, referrer, permissions, CSP-lite)
 * - CSRF token for the data request form
 * - Honeypot + timing guard + stronger input validation
 * - Rate limiting hardened (IP+UA key) + safer log path
 * - Safer mail headers (sanitized) + clearer success/failure handling
 * - DB include is optional (future-proof) and never fatals the page
 */

declare(strict_types=1);

// ------------------------------------------------------------
// 0) HTTPS detect
// ------------------------------------------------------------
$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

// ------------------------------------------------------------
// 1) Security headers (best-effort; don't fatal if headers already sent)
// ------------------------------------------------------------
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');

    // Light CSP that won't break inline styles/scripts (this page is self-contained)
    // NOTE: If you later move inline JS/CSS to files, tighten CSP.
    $csp = "default-src 'self'; "
         . "base-uri 'self'; "
         . "form-action 'self'; "
         . "frame-ancestors 'self'; "
         . "img-src 'self' data: https:; "
         . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
         . "font-src 'self' https://fonts.gstatic.com data:; "
         . "script-src 'self' 'unsafe-inline'; "
         . "connect-src 'self';";
    header("Content-Security-Policy: {$csp}");

    // Because this is a policy page, allow indexing (already set in meta), but don't cache POST results
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
}

// ------------------------------------------------------------
// 2) Safe session cookie params + session start
// ------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

if (session_status() === PHP_SESSION_NONE) {
    // Strict mode hardens session fixation a bit
    ini_set('session.use_strict_mode', '1');
    session_start();
}

// ------------------------------------------------------------
// 3) Optional DB include for future integrations (never fatal)
// ------------------------------------------------------------
$pdo = null;
try {
    $dbPath = __DIR__ . '/db_connect.php';
    if (!is_file($dbPath)) {
        $dbPath = __DIR__ . '/includes/db_connect.php';
    }
    if (is_file($dbPath)) {
        require_once $dbPath;
        // If db_connect.php defines $pdo, keep it; otherwise ignore.
        if (isset($pdo) && $pdo instanceof PDO) {
            // ok
        } else {
            $pdo = null;
        }
    }
} catch (Throwable $e) {
    // Don't break page rendering for privacy policy
    error_log('privacy-policy db include error: ' . $e->getMessage());
    $pdo = null;
}

// ------------------------------------------------------------
// 4) Helpers
// ------------------------------------------------------------
if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function csrf_validate(?string $token): bool {
    $sess = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sess) || $sess === '' || !is_string($token) || $token === '') return false;
    return hash_equals($sess, $token);
}

function client_ip(): string {
    // Keep it simple + safe. If you are behind Cloudflare/Proxy, consider trusting specific headers only.
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!is_string($ip) || $ip === '') return 'unknown';
    return preg_replace('/[^0-9a-fA-F:\.\,]/', '', $ip) ?: 'unknown';
}

function client_ua(): string {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (!is_string($ua)) $ua = '';
    // limit length for log key + avoid odd bytes
    $ua = preg_replace('/[^\x20-\x7E]/', '', $ua);
    return substr($ua, 0, 200);
}

// ------------------------------------------------------------
// 5) Handle form submission (self-contained)
// ------------------------------------------------------------
$response = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['requestType'])) {
    $response = handleDataRequest();
}

function handleDataRequest(): array
{
    // Bot traps
    $honeypot = isset($_POST['company']) ? trim((string)$_POST['company']) : '';
    if ($honeypot !== '') {
        return ['success' => false, 'error' => 'Request rejected.'];
    }

    // Timing guard (must take at least ~2 seconds from initial render)
    $formTs = isset($_POST['form_ts']) ? (int)$_POST['form_ts'] : 0;
    if ($formTs > 0 && (time() - $formTs) < 2) {
        return ['success' => false, 'error' => 'Request rejected. Please try again.'];
    }

    // CSRF validation
    $csrf = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    if (!csrf_validate($csrf)) {
        return ['success' => false, 'error' => 'Security check failed. Refresh and try again.'];
    }

    // Rate limiting (IP + UA)
    $ip = client_ip();
    $ua = client_ua();
    $rateKey = 'privacy_request_' . hash('sha256', $ip . '|' . $ua);

    if (!isset($_SESSION[$rateKey]) || !is_array($_SESSION[$rateKey])) {
        $_SESSION[$rateKey] = ['count' => 0, 'time' => time()];
    }

    // Reset counter after 1 hour
    $windowStart = (int)($_SESSION[$rateKey]['time'] ?? 0);
    if (time() - $windowStart > 3600) {
        $_SESSION[$rateKey] = ['count' => 0, 'time' => time()];
    }

    // Allow max 3 requests per hour
    $count = (int)($_SESSION[$rateKey]['count'] ?? 0);
    if ($count >= 3) {
        return ['success' => false, 'error' => 'Too many requests. Please try again in an hour.'];
    }
    $_SESSION[$rateKey]['count'] = $count + 1;

    // Validate inputs
    $requestType = isset($_POST['requestType']) ? trim((string)$_POST['requestType']) : '';
    $emailRaw    = isset($_POST['email']) ? trim((string)$_POST['email']) : '';
    $orderNumber = isset($_POST['orderNumber']) ? trim((string)$_POST['orderNumber']) : '';
    $details     = isset($_POST['details']) ? trim((string)$_POST['details']) : '';

    $email = filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ?: '';

    $validTypes = ['access', 'correction', 'deletion', 'portability', 'restriction', 'objection'];
    if ($requestType === '' || !in_array($requestType, $validTypes, true) || $email === '') {
        return ['success' => false, 'error' => 'Please provide a valid email and request type.'];
    }

    // Sanitize free text (strip tags, trim, and cap length)
    $orderNumber = $orderNumber !== '' ? strip_tags($orderNumber) : '';
    $details     = $details !== '' ? strip_tags($details) : '';

    $orderNumber = substr($orderNumber, 0, 80);
    $details     = substr($details, 0, 2000);

    // Generate request ID
    $requestId = 'DOD-REQ-' . gmdate('Ymd-His') . '-' . substr(strtoupper(bin2hex(random_bytes(4))), 0, 8);

    // Email settings
    $adminEmail = 'privacy@diamondsouttadirt.com'; // Ensure mailbox exists
    $subject    = "Data Request: {$requestId} - " . ucfirst($requestType);

    // Message
    $nowLocal = date('Y-m-d H:i:s T');
    $deadline = date('Y-m-d', strtotime('+30 days'));

    $message  = "NEW DATA REQUEST SUBMITTED\n\n";
    $message .= "Request ID: {$requestId}\n";
    $message .= "Type: {$requestType}\n";
    $message .= "User Email: {$email}\n";
    $message .= "Order #: " . ($orderNumber !== '' ? $orderNumber : 'Not provided') . "\n";
    $message .= "Details: " . ($details !== '' ? $details : 'None provided') . "\n\n";
    $message .= "Submitted: {$nowLocal}\n";
    $message .= "IP Address: {$ip}\n";
    $message .= "User Agent: " . ($ua !== '' ? $ua : 'unknown') . "\n\n";
    $message .= "ACTION REQUIRED: Process within 30 days.\n";
    $message .= "Response deadline: {$deadline}\n";

    // Safe mail headers (avoid header injection)
    $from = 'privacy@diamondsouttadirt.com';
    $fromSafe = preg_replace("/[\r\n]+/", '', $from);
    $headers = "From: {$fromSafe}\r\n"
             . "Reply-To: {$fromSafe}\r\n"
             . "X-Mailer: PHP/" . phpversion();

    $adminSent = @mail($adminEmail, $subject, $message, $headers);

    // Send confirmation to user (do not include IP/UA in user copy)
    $userSubject = "Diamonds Outta Dirt - Data Request Received: {$requestId}";
    $userMessage  = "We received your data request.\n\n";
    $userMessage .= "Request ID: {$requestId}\n";
    $userMessage .= "Type: {$requestType}\n";
    $userMessage .= "Order #: " . ($orderNumber !== '' ? $orderNumber : 'Not provided') . "\n";
    $userMessage .= "Details: " . ($details !== '' ? $details : 'None provided') . "\n\n";
    $userMessage .= "We will process it within 30 days.\n";
    $userMessage .= "If you contact us, reference: {$requestId}\n\n";
    $userMessage .= "Best regards,\nDiamonds Outta Dirt Privacy Team\n";

    $userSent = @mail($email, $userSubject, $userMessage, $headers);

    // Log (best-effort) - place in a non-web path if you can; for now keep here but protect via .htaccess if possible
    $logSafeEmail = preg_replace('/[^A-Za-z0-9@\.\+\-\_]/', '', $email);
    $logSafeOrder = preg_replace('/[^A-Za-z0-9\-\_]/', '', $orderNumber);

    $logEntry = date('Y-m-d H:i:s') . " | {$requestId} | {$requestType} | {$logSafeEmail} | "
              . ($logSafeOrder !== '' ? $logSafeOrder : 'N/A') . " | IP: {$ip}\n";

    // Use LOCK_EX to reduce collisions
    @file_put_contents(__DIR__ . '/privacy-requests.log', $logEntry, FILE_APPEND | LOCK_EX);

    if ($adminSent) {
        return [
            'success' => true,
            'requestId' => $requestId,
            'confirmationEmail' => $email,
            'message' => 'Request submitted successfully. We will process it within 30 days.'
        ];
    }

    // If admin mail fails but user mail sent, still return a useful message
    if ($userSent) {
        return [
            'success' => true,
            'requestId' => $requestId,
            'confirmationEmail' => $email,
            'message' => 'Request received. If you do not hear back, email us directly and reference your Request ID.'
        ];
    }

    return ['success' => false, 'error' => 'Failed to send request. Please email us directly at privacy@diamondsouttadirt.com.'];
}

// Precompute CSRF + timestamp for the form
$csrf = csrf_token();
$formTs = time();
?>
<!DOCTYPE html>
<html lang="en" class="cyberpunk-theme">
<head>
    <meta charset="UTF-8">
    <title>Privacy Policy — DIAMONDS OUTTA DIRT // DATA PROTOCOL_v4.6</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="Diamonds Outta Dirt Privacy Policy - How we protect and handle your data.">
    <meta name="robots" content="index, follow">

    <!-- CSS Variables for v4.6 Cyberpunk/Tactical Theme -->
    <style>
        :root {
            /* v4.6 Data Privacy Theme Colors */
            --neon: #00ffff;
            --neon-glow: rgba(0, 255, 255, 0.25);
            --neon-dark: #008b8b;
            --neon-green: #6bff9f;
            --neon-purple: #9966ff;
            --glass: rgba(20, 25, 45, 0.85);
            --glass-light: rgba(30, 35, 55, 0.7);
            --border: #222233;
            --border-glow: #333344;
            --bg-primary: #0a0a0f;
            --bg-secondary: #11111a;
            --text-primary: #ffffff;
            --text-secondary: #8a8a9e;
            --text-tertiary: #55556a;
            --success: #6bff9f;
            --warning: #ffcc00;
            --danger: #ff3366;

            /* Typography */
            --font-mono: 'Courier Prime', 'Space Mono', monospace;
            --font-sans: 'Syncopate', 'Rajdhani', sans-serif;
            --font-size-xs: 0.65rem;
            --font-size-sm: 0.8rem;
            --font-size-md: 1rem;
            --font-size-lg: 1.6rem;
            --font-size-xl: 2rem;

            /* Spacing */
            --space-xs: 0.5rem;
            --space-sm: 1rem;
            --space-md: 2rem;
            --space-lg: 3rem;
            --space-xl: 4rem;

            /* Borders & Effects */
            --border-radius-sm: 3px;
            --border-radius-md: 6px;
            --transition-speed: 0.3s;
        }

        /* Base Reset */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background: var(--bg-primary);
            background-image:
                radial-gradient(circle at 10% 20%, rgba(0, 100, 100, 0.05) 0%, transparent 20%),
                radial-gradient(circle at 90% 80%, rgba(100, 0, 100, 0.05) 0%, transparent 20%),
                linear-gradient(45deg, #0a0a0f 0%, #11111a 100%);
            color: var(--text-primary);
            font-family: var(--font-mono);
            max-width: 1000px;
            margin: 0 auto;
            line-height: 1.7;
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        /* CRT/Scanline Effect (Subtle) */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            background: linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.25) 50%);
            background-size: 100% 4px;
            z-index: 9999;
            opacity: 0.1;
        }

        /* Main Container */
        .privacy-container { margin: var(--space-xl) auto var(--space-lg); padding: 0 var(--space-md); position: relative; }

        /* Header */
        .privacy-header { margin-bottom: var(--space-lg); padding-bottom: var(--space-md); border-bottom: 1px solid var(--border); }
        .header-row { display: flex; align-items: center; justify-content: space-between; gap: var(--space-sm); }
        .home-link { display: inline-block; background: var(--neon); color: #000; text-decoration: none; padding: 0.4rem 0.8rem; border-radius: var(--border-radius-sm); font-family: var(--font-sans); font-weight: 700; letter-spacing: 0.08em; box-shadow: 0 5px 12px var(--neon-glow); border: none; }
        .home-link:hover { background: var(--neon-green); transform: translateY(-1px); box-shadow: 0 6px 16px var(--neon-glow); }

        h1 {
            font-family: var(--font-sans);
            font-size: var(--font-size-xl);
            letter-spacing: 3px;
            text-transform: uppercase;
            color: var(--neon-green);
            margin-bottom: var(--space-sm);
        }

        .protocol-tag {
            display: inline-block;
            background: rgba(107, 255, 159, 0.1);
            border: 1px solid var(--neon-green);
            padding: 0.3rem 0.8rem;
            font-size: var(--font-size-xs);
            border-radius: var(--border-radius-sm);
            margin-left: var(--space-sm);
            vertical-align: middle;
        }

        /* Content Sections */
        .privacy-section {
            margin-bottom: var(--space-lg);
            padding: var(--space-md);
            background: var(--glass-light);
            border: 1px solid var(--border);
            border-radius: var(--border-radius-md);
        }

        h2 {
            font-family: var(--font-sans);
            font-size: var(--font-size-lg);
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--neon);
            margin-bottom: var(--space-md);
            padding-bottom: var(--space-xs);
            border-bottom: 1px solid rgba(0, 255, 255, 0.2);
        }

        p { font-size: var(--font-size-sm); color: var(--text-secondary); margin-bottom: var(--space-sm); line-height: 1.8; }

        strong { color: var(--text-primary); font-weight: normal; border-bottom: 1px dotted var(--neon-green); }

        /* Lists */
        ul { list-style: none; margin-left: var(--space-md); margin-bottom: var(--space-sm); }
        li {
            font-size: var(--font-size-sm);
            color: var(--text-secondary);
            margin-bottom: var(--space-xs);
            position: relative;
            padding-left: var(--space-md);
        }
        li::before { content: '→'; position: absolute; left: 0; color: var(--neon); font-weight: bold; }

        /* Links */
        a {
            color: var(--success);
            text-decoration: none;
            border-bottom: 1px solid transparent;
            transition: all var(--transition-speed);
            position: relative;
        }
        a:hover { color: var(--neon); border-bottom: 1px solid var(--neon); }
        a::after { content: '[↗]'; font-size: 0.7em; margin-left: 0.3em; opacity: 0.7; }

        /* Data Request Form */
        .data-request-form { background: rgba(20, 40, 40, 0.3); border: 1px solid var(--neon); padding: var(--space-md); border-radius: var(--border-radius-md); margin: var(--space-xl) 0; }

        .form-group { margin-bottom: var(--space-md); }
        .form-group label { display: block; margin-bottom: var(--space-xs); color: var(--neon); font-size: var(--font-size-sm); }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: var(--space-sm); background: rgba(0, 0, 0, 0.5); border: 1px solid var(--border);
            color: var(--text-primary); font-family: var(--font-mono); border-radius: var(--border-radius-sm); transition: border-color var(--transition-speed);
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: var(--neon); box-shadow: 0 0 10px rgba(0, 255, 255, 0.2); }

        .form-submit { background: var(--neon); color: #000; border: none; padding: var(--space-sm) var(--space-md); font-family: var(--font-sans);
            text-transform: uppercase; letter-spacing: 2px; cursor: pointer; border-radius: var(--border-radius-sm); transition: all var(--transition-speed); }
        .form-submit:hover { background: var(--neon-green); transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0, 255, 255, 0.3); }
        .form-submit:disabled { opacity: 0.6; cursor: not-allowed; transform: none; box-shadow: none; }

        /* Notification */
        .notification { padding: var(--space-md); border-radius: var(--border-radius-md); margin-bottom: var(--space-md); border: 1px solid; animation: slideIn 0.3s ease-out; }
        .notification.success { background: rgba(107, 255, 159, 0.1); border-color: var(--success); color: var(--success); }
        .notification.error   { background: rgba(255, 51, 102, 0.1); border-color: var(--danger);  color: var(--danger); }

        /* Accessible hidden */
        .sr-only {
            position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
            overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
        }

        /* Responsive */
        @media (max-width: 768px) {
            :root { --font-size-xl: 1.4rem; --font-size-lg: 1.2rem; --space-md: 1rem; --space-lg: 2rem; }
            .privacy-container { padding: 0 var(--space-sm); }
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
    </style>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Courier+Prime:wght@400;700&family=Syncopate:wght@400;700&family=Space+Mono:wght@400;700&family=Rajdhani:wght@400;600&display=swap" rel="stylesheet">
<?php @include_once __DIR__ . '/includes/bg_styles.php'; ?>
<script src="/js/telemetry.js" defer></script>
</head>
<body>
    <main class="privacy-container">
        <!-- Header Section -->
        <header class="privacy-header">
            <div class="header-row">
                <h1>
                    Privacy Policy
                    <span class="protocol-tag">DATA PROTOCOL_v4.6</span>
                </h1>
                <a href="index.php" class="home-link" aria-label="Return to Home">Return Home</a>
            </div>
            <p style="color: var(--text-secondary); font-size: var(--font-size-sm);">
                Last Updated: <?= h(date('F j, Y')) ?>
            </p>
        </header>

        <!-- Show Notification if form was submitted -->
        <?php if (is_array($response ?? null)): ?>
        <div class="notification <?= !empty($response['success']) ? 'success' : 'error' ?>" role="<?= !empty($response['success']) ? 'status' : 'alert' ?>" aria-live="<?= !empty($response['success']) ? 'polite' : 'assertive' ?>" aria-atomic="true">
            <?= h((string)($response['message'] ?? ($response['error'] ?? ''))) ?>
            <?php if (!empty($response['success'])): ?>
            <div style="margin-top: var(--space-xs); font-size: var(--font-size-xs);">
                Request ID: <strong><?= h((string)($response['requestId'] ?? '')) ?></strong>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Introduction -->
        <section class="privacy-section">
            <h2>DATA PROTECTION PROTOCOL</h2>
            <p>
                <strong>DIAMONDS OUTTA DIRT</strong> ("we", "us", "our", or "the System") operates on a fundamental principle: your data belongs to you. This Privacy Protocol outlines how we collect, use, protect, and when necessary, delete your personal information when you interact with our digital ecosystem.
            </p>
            <p>
                We are committed to transparency and security, implementing strong access controls and privacy-by-design principles across our systems. Your trust is our most valuable asset.
            </p>
        </section>

        <!-- Information We Collect -->
        <section class="privacy-section">
            <h2>DATA WE COLLECT</h2>
            <p>We collect only the information necessary to provide our services and enhance your experience:</p>
            <ul style="color: var(--text-secondary); margin-left: var(--space-md); margin-bottom: var(--space-md);">
                <li><strong>Personal Information:</strong> Name, email, shipping address for order fulfillment</li>
                <li><strong>Payment Information:</strong> Processed securely through Stripe (never stored on our servers)</li>
                <li><strong>Technical Data:</strong> IP address, browser type for security and analytics</li>
                <li><strong>Usage Data:</strong> How you interact with our site to improve user experience</li>
            </ul>
            <p>
                <strong>Critical Note:</strong> Complete payment card numbers, CVV codes, and bank account details are <strong>never stored on our servers</strong>. These are handled exclusively by our PCI-DSS certified payment processors.
            </p>
        </section>

        <!-- Payment Security -->
        <section class="privacy-section">
            <h2>PAYMENT SECURITY</h2>
            <p>All financial transactions are processed through <strong>Stripe, Inc.</strong>, a PCI-DSS Level 1 certified payment processor.</p>
            <p>
                Review Stripe's comprehensive privacy policy at:
                <a href="https://stripe.com/privacy" target="_blank" rel="noopener noreferrer" style="color: var(--neon);">https://stripe.com/privacy</a>
            </p>
        </section>

        <!-- How We Use Your Information -->
        <section class="privacy-section">
            <h2>HOW WE USE YOUR DATA</h2>
            <p>Your information enables us to provide, protect, and improve our services:</p>
            <ul style="color: var(--text-secondary); margin-left: var(--space-md); margin-bottom: var(--space-md);">
                <li>Process orders and transactions</li>
                <li>Prevent fraud and secure accounts</li>
                <li>Send order confirmations and updates</li>
                <li>Improve our website and services</li>
                <li>Comply with legal requirements</li>
            </ul>
            <p>We never sell your personal information. We never use your data for purposes beyond what's described here without explicit, additional consent.</p>
        </section>

        <!-- Your Rights -->
        <section class="privacy-section">
            <h2>YOUR DATA RIGHTS</h2>
            <p>Depending on your jurisdiction, you may have specific rights regarding your personal data:</p>
            <ul style="color: var(--text-secondary); margin-left: var(--space-md); margin-bottom: var(--space-md);">
                <li><strong>Access:</strong> Request a copy of your data</li>
                <li><strong>Correction:</strong> Update inaccurate information</li>
                <li><strong>Deletion:</strong> Request erasure of your data</li>
                <li><strong>Portability:</strong> Get your data in machine-readable format</li>
                <li><strong>Objection:</strong> Object to certain processing</li>
                <li><strong>Restriction:</strong> Limit how we use your data</li>
            </ul>
        </section>

        <!-- Data Request Form -->
        <section class="data-request-form">
            <h2>EXERCISE YOUR RIGHTS</h2>
            <p>Submit a formal data request using the form below:</p>

            <form method="POST" autocomplete="off" novalidate>
                <!-- CSRF -->
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <!-- Timing guard -->
                <input type="hidden" name="form_ts" value="<?= (int)$formTs ?>">
                <!-- Honeypot (bots fill this) -->
                <div class="sr-only" aria-hidden="true">
                    <label for="company">Company</label>
                    <input type="text" id="company" name="company" tabindex="-1" autocomplete="off">
                </div>

                <div class="form-group">
                    <label for="requestType">REQUEST TYPE</label>
                    <select id="requestType" name="requestType" required>
                        <option value="">Select request type</option>
                        <option value="access">Access my data</option>
                        <option value="correction">Correct my data</option>
                        <option value="deletion">Delete my data</option>
                        <option value="portability">Data portability</option>
                        <option value="restriction">Restrict processing</option>
                        <option value="objection">Object to processing</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="email">YOUR EMAIL</label>
                    <input type="email" id="email" name="email" required placeholder="account@example.com" inputmode="email" autocomplete="email">
                </div>

                <div class="form-group">
                    <label for="orderNumber">ORDER NUMBER (OPTIONAL)</label>
                    <input type="text" id="orderNumber" name="orderNumber" placeholder="DOD-XXXX-XXXX" maxlength="80" autocomplete="off">
                </div>

                <div class="form-group">
                    <label for="details">ADDITIONAL DETAILS</label>
                    <textarea id="details" name="details" rows="4" placeholder="Provide specific details about your request..." maxlength="2000"></textarea>
                </div>

                <button type="submit" class="form-submit">SUBMIT DATA REQUEST</button>
            </form>

            <p style="margin-top: var(--space-sm); font-size: var(--font-size-xs); color: var(--text-tertiary);">
                We will respond to all valid requests within 30 days as required by law. You can also email us directly at <strong>privacy@diamondsouttadirt.com</strong>
            </p>
        </section>

        <!-- Contact Information -->
        <section class="privacy-section">
            <h2>CONTACT US</h2>
            <p>For privacy-related inquiries, data requests, or security concerns:</p>
            <ul style="color: var(--text-secondary); margin-left: var(--space-md);">
                <li><strong>Email:</strong> privacy@diamondsouttadirt.com</li>
                <li><strong>Response Time:</strong> 48 hours for general inquiries</li>
                <li><strong>Data Requests:</strong> Processed within 30 days</li>
            </ul>
            <p style="margin-top: var(--space-md);">
                <strong>Last Updated:</strong> <?= h(date('F j, Y')) ?>
            </p>
        </section>
    </main>

    <script>
        // Simple form enhancement
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            if (form) {
                form.addEventListener('submit', function() {
                    const submitBtn = form.querySelector('.form-submit');
                    if (!submitBtn) return;
                    const originalText = submitBtn.textContent;

                    submitBtn.textContent = 'PROCESSING...';
                    submitBtn.disabled = true;

                    // Re-enable after 8 seconds (in case of network/mail delays or errors)
                    setTimeout(() => {
                        submitBtn.textContent = originalText;
                        submitBtn.disabled = false;
                    }, 8000);
                });
            }

            // Auto-hide notifications after 10 seconds
            setTimeout(() => {
                const notifications = document.querySelectorAll('.notification');
                notifications.forEach(notification => {
                    notification.style.opacity = '0';
                    notification.style.transition = 'opacity 0.5s';
                    setTimeout(() => notification.remove(), 500);
                });
            }, 10000);
        });
    </script>
</body>
</html>