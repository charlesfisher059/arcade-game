<?php
/**
 * terms-of-service.php
 * Diamonds Outta Dirt — Terms of Service (v4.7 Cyberpunk/Tactical HARDENED + RETURNS/REFUNDS)
 * Path: /home2/asqrtyte/public_html/terms-of-service.php
 *
 * Maintains: your self-contained UI/theme + layout
 * Upgrades (v4.7):
 * - Adds RETURNS + REFUNDS policy section (so you actually have one visible)
 * - Adds shipping/damage/incorrect item handling guidance
 * - Adds chargeback/dispute language (basic protection)
 * - Keeps your hardened session + security headers + UI intact
 */

declare(strict_types=1);

// Detect HTTPS (HostGator/Cloudflare friendly)
$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');

// (Optional) consistent timezone for date rendering
// date_default_timezone_set('America/New_York');

// Start session safely (only set cookie params before session_start and before headers sent)
if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        // Use strict session modes where possible
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_httponly', '1');
        if ($https) @ini_set('session.cookie_secure', '1');

        // Set session cookie parameters BEFORE starting session
        // (Domain intentionally blank to default to current host)
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    session_start();
}

// Security headers (best-effort; won’t error if headers already sent)
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// Stable "Last Updated" value (edit when you actually update legal text)
$TERMS_LAST_UPDATED = 'February 13, 2026';
// If you prefer auto-date, set to '' and it will fallback to today's date.
// $TERMS_LAST_UPDATED = '';
$lastUpdated = ($TERMS_LAST_UPDATED !== '') ? $TERMS_LAST_UPDATED : date('F j, Y');

?>
<!DOCTYPE html>
<html lang="en" class="cyberpunk-theme">
<head>
    <meta charset="UTF-8">
    <title>Terms of Service — DIAMONDS OUTTA DIRT // PROTOCOL_v4.7</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="Diamonds Outta Dirt Terms of Service - Terms governing use of our digital ecosystem, including returns and refunds.">
    <meta name="robots" content="index, follow">

    <!-- CSS Variables for v4.7 Cyberpunk/Tactical Theme -->
    <style>
        :root {
            /* v4.7 Terms Theme Colors */
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

        /* Skip Link (a11y) */
        .skip-link{
            position:absolute;
            left:-9999px;
            top: 10px;
            background: var(--neon);
            color:#000;
            padding: 10px 12px;
            border-radius: var(--border-radius-sm);
            font-family: var(--font-sans);
            font-weight: 700;
            letter-spacing: 0.08em;
            z-index: 10000;
        }
        .skip-link:focus{
            left: 12px;
            outline: 2px solid rgba(0,0,0,0.65);
            box-shadow: 0 6px 16px var(--neon-glow);
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
        .terms-container { margin: var(--space-xl) auto var(--space-lg); padding: 0 var(--space-md); position: relative; }

        /* Header */
        .terms-header { margin-bottom: var(--space-lg); padding-bottom: var(--space-md); border-bottom: 1px solid var(--border); }
        .header-row { display: flex; align-items: center; justify-content: space-between; gap: var(--space-sm); }
        .home-link { display: inline-block; background: var(--neon); color: #000; text-decoration: none; padding: 0.4rem 0.8rem; border-radius: var(--border-radius-sm); font-family: var(--font-sans); font-weight: 700; letter-spacing: 0.08em; box-shadow: 0 5px 12px var(--neon-glow); border: none; transition: all 0.15s ease; }
        .home-link:hover { background: var(--neon-green); transform: translateY(-1px); box-shadow: 0 6px 16px var(--neon-glow); }
        .home-link:focus-visible{ outline: 2px solid rgba(0,255,255,0.45); outline-offset: 2px; }

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
        .terms-section {
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

        /* Responsive */
        @media (max-width: 768px) {
            :root { --font-size-xl: 1.4rem; --font-size-lg: 1.2rem; --space-md: 1rem; --space-lg: 2rem; }
            .terms-container { padding: 0 var(--space-sm); }
            .header-row{ align-items:flex-start; }
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
    <a class="skip-link" href="#main">Skip to content</a>

    <main class="terms-container" id="main">
        <!-- Header Section -->
        <header class="terms-header">
            <div class="header-row">
                <h1>
                    Terms of Service
                    <span class="protocol-tag">PROTOCOL_v4.7</span>
                </h1>
                <a href="/" class="home-link" aria-label="Return to Home">Return Home</a>
            </div>
            <p style="color: var(--text-secondary); font-size: var(--font-size-sm);">
                Last Updated: <?= h($lastUpdated) ?>
            </p>
        </header>

        <!-- Introduction -->
        <section class="terms-section">
            <h2>AGREEMENT TO TERMS</h2>
            <p>
                Welcome to <strong>DIAMONDS OUTTA DIRT</strong> ("we", "us", "our", "the Site", or "the Service"). These Terms of Service ("Terms") govern your access to and use of our website, products, and services. By accessing or using the Site, you agree to be bound by these Terms. If you disagree with any part of these Terms, you may not use the Site.
            </p>
        </section>

        <!-- Use License -->
        <section class="terms-section">
            <h2>USE LICENSE</h2>
            <p>Permission is granted to temporarily download one copy of the materials (information or software) on the Site for personal, non-commercial transitory viewing only. This is the grant of a license, not a transfer of title, and under this license you may not:</p>
            <ul style="color: var(--text-secondary); margin-left: var(--space-md); margin-bottom: var(--space-md);">
                <li>Modify or copy the materials</li>
                <li>Use the materials for any commercial purpose or for any public display</li>
                <li>Attempt to decompile or reverse engineer any software contained on the Site</li>
                <li>Remove any copyright or other proprietary notations from the materials</li>
                <li>Transfer the materials to another person or "mirror" the materials on any other server</li>
                <li>Use automated tools or scripts to access, scrape, or extract data from the Site</li>
            </ul>
            <p>This license shall automatically terminate if you violate any of these restrictions and may be terminated by DIAMONDS OUTTA DIRT at any time. Upon termination of your viewing of these materials or upon the termination of this license, you must destroy any downloaded materials in your possession whether in electronic or printed format.</p>
        </section>

        <!-- Disclaimer of Warranties -->
        <section class="terms-section">
            <h2>DISCLAIMER OF WARRANTIES</h2>
            <p>
                The materials on the Site are provided on an 'as is' basis. <strong>DIAMONDS OUTTA DIRT</strong> makes no warranties, expressed or implied, and hereby disclaims and negates all other warranties including, without limitation, implied warranties or conditions of merchantability, fitness for a particular purpose, or non-infringement of intellectual property or other violation of rights.
            </p>
        </section>

        <!-- Limitations of Liability -->
        <section class="terms-section">
            <h2>LIMITATIONS OF LIABILITY</h2>
            <p>
                In no event shall <strong>DIAMONDS OUTTA DIRT</strong> or its suppliers be liable for any damages (including, without limitation, damages for loss of data or profit, or due to business interruption) arising out of the use or inability to use the materials on the Site, even if DIAMONDS OUTTA DIRT or an authorized representative has been notified orally or in writing of the possibility of such damage.
            </p>
        </section>

        <!-- Accuracy of Materials -->
        <section class="terms-section">
            <h2>ACCURACY OF MATERIALS</h2>
            <p>
                The materials appearing on the Site could include technical, typographical, or photographic errors. <strong>DIAMONDS OUTTA DIRT</strong> does not warrant that any of the materials on the Site are accurate, complete, or current. We may make changes to the materials contained on the Site at any time without notice. However, we do not commit to updating said materials.
            </p>
        </section>

        <!-- Links -->
        <section class="terms-section">
            <h2>LINKS</h2>
            <p>
                <strong>DIAMONDS OUTTA DIRT</strong> has not reviewed all of the sites linked to its Site and is not responsible for the contents of any such linked site. The inclusion of any link does not imply endorsement by DIAMONDS OUTTA DIRT of the site. Use of any such linked website is at the user's own risk.
            </p>
        </section>

        <!-- Returns + Refunds -->
        <section class="terms-section">
            <h2>RETURNS + REFUNDS</h2>
            <p>
                We stand on quality. If something arrives wrong, damaged, or incomplete, contact us and we’ll make it right within the rules below.
            </p>

            <ul style="color: var(--text-secondary); margin-left: var(--space-md); margin-bottom: var(--space-md);">
                <li><strong>Return Window:</strong> Requests must be submitted within <strong>14 days</strong> of delivery (based on carrier tracking).</li>
                <li><strong>Condition:</strong> Eligible items must be <strong>unused</strong>, <strong>unworn</strong>, unwashed, and in original condition/packaging where possible.</li>
                <li><strong>Non-Returnable:</strong> <strong>Custom</strong> or <strong>made-to-order</strong> items (including custom embroidery, personalization, or special sizing) are generally <strong>final sale</strong> unless defective or we shipped the wrong item.</li>
                <li><strong>Damaged/Incorrect Items:</strong> Email us within <strong>48 hours</strong> of delivery with photos of the issue and the packaging so we can verify and resolve quickly.</li>
                <li><strong>Refund Method:</strong> Approved refunds are issued back to the original payment method. Timing depends on your bank/provider.</li>
                <li><strong>Shipping Fees:</strong> Shipping costs are typically non-refundable unless the return is due to our error (wrong item/defect).</li>
                <li><strong>Return Shipping:</strong> If the return is not due to our error, the customer may be responsible for return shipping.</li>
            </ul>

            <p>
                To start a return/refund request, email <strong>support@diamondsouttadirt.com</strong> with your order number and a brief explanation.
            </p>

            <p>
                <strong>Chargebacks & Disputes:</strong> If you have an issue with an order, please contact us first. Filing a chargeback without contacting us may delay resolution while we respond through the processor with order and shipment documentation.
            </p>
        </section>

        <!-- Modifications -->
        <section class="terms-section">
            <h2>MODIFICATIONS</h2>
            <p>
                <strong>DIAMONDS OUTTA DIRT</strong> may revise these Terms of Service for the Site at any time without notice. By using this Site, you are agreeing to be bound by the then current version of these Terms of Service.
            </p>
        </section>

        <!-- Governing Law -->
        <section class="terms-section">
            <h2>GOVERNING LAW</h2>
            <p>
                These Terms and Conditions are governed by and construed in accordance with the laws of the jurisdiction where DIAMONDS OUTTA DIRT operates, and you irrevocably submit to the exclusive jurisdiction of the courts located in that location.
            </p>
        </section>

        <!-- Contact Information -->
        <section class="terms-section">
            <h2>CONTACT US</h2>
            <p>If you have any questions about these Terms of Service, please contact us at:</p>
            <ul style="color: var(--text-secondary); margin-left: var(--space-md);">
                <li><strong>Email:</strong> support@diamondsouttadirt.com</li>
                <li><strong>Response Time:</strong> 48 hours for general inquiries</li>
            </ul>
            <p style="margin-top: var(--space-md);">
                <strong>Last Updated:</strong> <?= h($lastUpdated) ?>
            </p>
        </section>
    </main>
</body>
</html>