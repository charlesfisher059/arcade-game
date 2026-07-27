<?php
// checkout.php — STRIPE CHECKOUT ENTRY (v3.4 HARDENED + v5.x CART COMPAT)
// Path: /home2/asqrtyte/public_html/checkout.php
declare(strict_types=1);

/* =============================================================================
   0) HTTPS + SESSION (COOKIE PARAMS BEFORE session_start)
============================================================================= */
$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
    || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
    || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on');

$domain = $_SERVER['HTTP_HOST'] ?? '';
$domain = preg_replace('/:\d+$/', '', (string)$domain);

if (session_status() === PHP_SESSION_NONE) {
    $cookieParams = [
        'lifetime' => 604800,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax'
    ];
    if ($domain !== '') {
        $cookieParams['domain'] = $domain;
    }
    session_set_cookie_params($cookieParams);
    session_start();
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if ($https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/* ---------------------------------
   CSRF TOKEN
----------------------------------*/
if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
$googlePlacesKey = (string)(getenv('GOOGLE_PLACES_API_KEY') ?: '');
$mapboxPublicToken = (string)(getenv('MAPBOX_PUBLIC_TOKEN') ?: '');
$shippingFreeOver = (float)(getenv('DOD_SHIPPING_FREE_OVER') ?: 150.0);
$shippingFlat = (float)(getenv('DOD_SHIPPING_FLAT') ?: 8.95);
$shippingIntl = (float)(getenv('DOD_SHIPPING_INTL') ?: 25.0);
$shippingByStateJson = (string)(getenv('DOD_SHIPPING_BY_STATE_JSON') ?: '');
$taxDefault = (float)(getenv('DOD_TAX_RATE') ?: 0.0);
$taxByStateJson = (string)(getenv('DOD_TAX_BY_STATE_JSON') ?: '');
$taxShippingEnabled = ((string)getenv('DOD_TAX_SHIPPING') === '1');

/* ---------------------------------
   OPTIONAL STATUS / ERROR BANNER
----------------------------------*/
$msg = isset($_GET['msg']) ? strtoupper(trim((string)$_GET['msg'])) : '';
$banner = '';
if ($msg === 'CHECKOUT_FAILED') {
    $banner = 'CHECKOUT_FAILED — PLEASE TRY AGAIN.';
} elseif ($msg !== '') {
    $banner = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>CHECKOUT | DIAMONDS OUTTA DIRT</title>
    <?php if (is_file(__DIR__ . '/includes/tracking_pixels.php')) { require_once __DIR__ . '/includes/tracking_pixels.php'; } ?>
    <link rel="stylesheet" href="/css/master.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@700;900&family=Space+Mono&family=Syncopate:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --checkout-panel: rgba(6, 10, 18, 0.86);
            --checkout-border: rgba(255,255,255,0.14);
            --checkout-soft: rgba(255,255,255,0.08);
        }
        html,
        body,
        body.tech-archive {
            margin: 0;
            background:
                radial-gradient(circle at 18% 16%, rgba(0, 255, 157, 0.12), transparent 52%),
                radial-gradient(circle at 82% 0%, rgba(0, 243, 255, 0.10), transparent 48%),
                #030508 !important;
            color: #fff !important;
        }
        .checkout-container {
            max-width: 1080px;
            margin: 138px auto 50px;
            padding: 0 20px;
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 28px;
            align-items: start;
        }
        @media (max-width: 768px) {
            .checkout-container {
                grid-template-columns: 1fr;
                margin-top: 110px;
            }
        }
        .checkout-panel {
            background: var(--checkout-panel);
            border: 1px solid var(--checkout-border);
            padding: 30px;
            backdrop-filter: blur(8px);
        }
        .checkout-kicker {
            color: #9be9ff;
            font-family: 'Space Mono';
            font-size: 0.62rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 12px;
        }
        h2 {
            border-bottom: 1px solid var(--border);
            padding-bottom: 15px;
            margin-bottom: 25px;
            font-family: 'Syncopate';
            font-size: 0.8rem;
            letter-spacing: 3px;
            color: var(--neon);
            text-transform: uppercase;
        }
        .form-group { margin-bottom: 25px; }
        label {
            display: block;
            font-family: 'Space Mono';
            font-size: 0.6rem;
            margin-bottom: 8px;
            color: var(--neon);
            letter-spacing: 2px;
        }
        .dod-field {
            width: 100%;
            padding: 15px;
            background: rgba(10,10,10,0.8);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
            font-family: 'Space Mono';
            border-radius: 2px;
            outline: none;
            transition: 0.3s;
        }
        .dod-field:focus {
            border-color: var(--neon);
            box-shadow: 0 0 15px rgba(107,255,159,0.1);
        }
        .checkout-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 640px) {
            .checkout-grid-2 {
                grid-template-columns: 1fr;
                gap: 0;
            }
        }
        .order-summary {
            position: sticky;
            top: 112px;
            background: var(--checkout-panel);
            padding: 30px;
            border: 1px solid var(--checkout-border);
            height: fit-content;
            backdrop-filter: blur(10px);
        }
        @media (max-width: 768px) {
            .order-summary {
                position: static;
                top: auto;
            }
            .checkout-panel,
            .order-summary {
                padding: 22px;
            }
        }
        .summary-row {
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            margin-bottom:14px;
            font-family:'Space Mono';
            font-size:0.72rem;
            letter-spacing:1px;
            gap: 14px;
        }
        .summary-main {
            min-width: 0;
            flex: 1;
        }
        .summary-name {
            overflow-wrap: anywhere;
        }
        .summary-amount {
            font-weight: 700;
            white-space: nowrap;
        }
        .item-spec {
            display:inline-block;
            color:var(--neon);
            font-size:0.55rem;
            background:#000;
            padding:2px 6px;
            margin-top:5px;
            border-left:1px solid var(--neon);
        }
        .summary-meta {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px dashed var(--checkout-soft);
            color: #9aa0ad;
            font-family: 'Space Mono';
            font-size: 0.58rem;
            letter-spacing: 1.2px;
        }
        .summary-total {
            border-top:1px solid var(--border);
            padding-top:20px;
            margin-top:20px;
            font-size:1.4rem;
            font-family:'Inter';
            font-weight:900;
            color:var(--neon);
        }
        .btn-submit {
            width:100%;
            background:var(--neon);
            color:black;
            border:none;
            padding:22px;
            font-family:'Space Mono';
            font-weight:900;
            cursor:pointer;
            margin-top:20px;
            font-size:0.9rem;
            letter-spacing:2px;
            transition: transform 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease;
        }
        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 30px rgba(107,255,159,0.25);
        }
        .btn-submit:disabled {
            opacity:0.45;
            cursor:not-allowed;
        }
        .banner {
            max-width: 1000px;
            margin: 120px auto -90px;
            padding: 14px 18px;
            border: 1px solid rgba(255,255,255,0.12);
            background: rgba(0,0,0,0.7);
            color: var(--neon);
            font-family: 'Space Mono';
            font-size: 0.7rem;
            letter-spacing: 2px;
            text-transform: uppercase;
        }
        .banner.error { color: #ff3e3e; border-color: rgba(255,62,62,0.4); }
        .mini {
            margin-top: 10px;
            color: #888;
            font-size: 0.55rem;
            letter-spacing: 1px;
            text-transform:none;
        }
        .inline-error{
            display:none;
            margin-top:12px;
            padding:12px 14px;
            border:1px solid rgba(255,62,62,0.35);
            background: rgba(255,62,62,0.08);
            color:#ff3e3e;
            font-family:'Space Mono';
            font-size:0.7rem;
            letter-spacing:1px;
        }
        .inline-ok{
            display:none;
            margin-top:12px;
            padding:12px 14px;
            border:1px solid rgba(107,255,159,0.25);
            background: rgba(107,255,159,0.08);
            color: var(--neon);
            font-family:'Space Mono';
            font-size:0.65rem;
            letter-spacing:1px;
        }
        .checkout-back {
            display:inline-flex;
            align-items:center;
            gap:8px;
            margin-bottom:18px;
            text-decoration:none;
            color: #e6ebf5;
            border: 1px solid var(--checkout-border);
            background: linear-gradient(135deg, rgba(24, 32, 54, 0.98), rgba(12, 19, 34, 0.98));
            padding:10px 12px;
            font-family:'Space Mono';
            font-size:0.7rem;
            letter-spacing:1.2px;
            text-transform:uppercase;
            min-height: 44px;
            transition: background 0.2s ease, color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }
        .checkout-back:visited {
            color: #e6ebf5;
        }
        .checkout-back:hover {
            color: #8fd7ff;
            border-color: rgba(143, 215, 255, 0.62);
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(2, 7, 16, 0.9);
        }
        .checkout-back:focus-visible {
            outline: 2px solid var(--neon);
            outline-offset: 2px;
        }
        .trust-row {
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            margin: 0 0 14px;
        }
        .trust-chip {
            border:1px solid var(--checkout-soft);
            background: rgba(255,255,255,0.02);
            padding:8px 10px;
            font-family:'Space Mono';
            font-size:0.56rem;
            color:#b7c2d1;
            letter-spacing:1px;
            text-transform:uppercase;
        }
        .final-sale-notice {
            margin: 0 0 18px;
            padding: 10px 12px;
            border: 1px solid rgba(255, 183, 77, 0.35);
            background: rgba(255, 183, 77, 0.06);
            color: #ffc978;
            font-family: 'Space Mono';
            font-size: 0.62rem;
            letter-spacing: 0.5px;
            line-height: 1.6;
            text-transform: none;
            border-radius: 2px;
        }
        .video-bg-container {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; overflow: hidden;
            background: #030508;
        }
        .bg-video {
            width: 100%; height: 100%; object-fit: cover; opacity: 0.22; filter: saturate(0.9) contrast(1.05);
        }
    </style>
</head>
<body class="tech-archive">
<div class="video-bg-container">
    <video autoplay muted loop playsinline class="bg-video">
        <source src="/images/brand-visuals.mp4" type="video/mp4">
    </video>
</div>

<header class="tactical-nav">
    <div class="top-bar">
        <a href="/" class="logo">DIAMONDS OUTTA DIRT</a>
        <div class="system-status">STATUS: STRIPE_CHECKOUT</div>
    </div>
</header>

<?php if ($banner !== ''): ?>
    <div class="banner error">
        <?= htmlspecialchars($banner, ENT_QUOTES, 'UTF-8') ?>
        <div class="mini">If this keeps happening, your Stripe session creator is failing (create_checkout_session.php).</div>
    </div>
<?php endif; ?>

<div class="checkout-container">
    <div class="shipping-section">
        <a href="/cart" class="checkout-back">← RETURN_TO_BAG</a>
        <div class="checkout-panel">
        <div class="checkout-kicker">SECURE CHECKOUT PIPELINE</div>
        <h2>SHIPPING_LOGISTICS</h2>
        <div class="trust-row">
            <span class="trust-chip">TLS_ENCRYPTED</span>
            <span class="trust-chip">STRIPE_REDIRECT</span>
            <span class="trust-chip">NO_CARD_DATA_STORED</span>
            <span class="trust-chip">SHIPS IN 3-5 BUSINESS DAYS</span>
        </div>
        <div class="final-sale-notice">ALL SALES ARE FINAL -- NO RETURNS OR EXCHANGES. Please review sizing and details carefully before completing your purchase.</div>

        <form action="/create_checkout_session.php" method="POST" id="checkout-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="cart_data" id="hidden-cart-data" value="">
            <input type="hidden" name="cart_source" value="localStorage:dod_cart">

            <div class="form-group">
                <label>RECIPIENT_NAME</label>
                <input type="text" name="name" class="dod-field" required minlength="2" autocomplete="name">
            </div>

            <div class="form-group">
                <label>COMMUNICATION_CHANNEL (EMAIL)</label>
                <input type="email" name="email" class="dod-field" required autocomplete="email">
            </div>

            <div class="form-group">
                <label>DESTINATION_STREET</label>
                <input type="text" name="address" class="dod-field" required autocomplete="street-address">
            </div>

            <div class="checkout-grid-2">
                <div class="form-group">
                    <label>CITY_SECTOR</label>
                    <input type="text" name="city" class="dod-field" required autocomplete="address-level2">
                </div>
                <div class="form-group">
                    <label>STATE_REGION</label>
                    <input type="text" name="state" class="dod-field" required maxlength="2" autocomplete="address-level1" value="OH" placeholder="OH">
                </div>
            </div>

            <div class="checkout-grid-2">
                <div class="form-group">
                    <label>POSTAL_CODE</label>
                    <input type="text" name="zip" class="dod-field" required autocomplete="postal-code">
                </div>
                <div class="form-group">
                    <label>COUNTRY_CODE</label>
                    <input type="text" name="country" class="dod-field" required maxlength="2" value="US" autocomplete="country">
                </div>
            </div>

            <div id="inlineError" class="inline-error" role="alert" aria-live="assertive"></div>
            <div id="inlineOk" class="inline-ok" role="status" aria-live="polite"></div>

            <button type="submit" class="btn-submit" id="submitBtn">
                PROCEED_TO_PAYMENT
            </button>
            <datalist id="addressSuggestions"></datalist>
        </form>
        </div>
    </div>

    <div class="order-summary">
        <div class="checkout-kicker">CURRENT SESSION</div>
        <h2>ARCHIVE_SUMMARY</h2>
        <div class="summary-row">
            <span>ITEM_COUNT</span>
            <span id="checkout-count">0</span>
        </div>
        <div id="checkout-items"></div>
        <div class="summary-row">
            <span>SUBTOTAL</span>
            <span id="checkout-subtotal">$0.00</span>
        </div>
        <div class="summary-row">
            <span>SHIPPING_ESTIMATE</span>
            <span id="checkout-shipping">$0.00</span>
        </div>
        <div class="summary-row">
            <span>TAX_ESTIMATE</span>
            <span id="checkout-tax">$0.00</span>
        </div>
        <div class="summary-total summary-row">
            <span>EST_TOTAL_VALUE</span>
            <span id="checkout-total">$0.00</span>
        </div>
        <div class="summary-meta">
            ESTIMATES UPDATE FROM STATE/COUNTRY. FINAL TOTAL IS AUTHORITATIVE IN STRIPE CHECKOUT.
        </div>
    </div>
</div>

<script>
(function(){
    const inlineError = document.getElementById('inlineError');
    const inlineOk = document.getElementById('inlineOk');
    const items = document.getElementById('checkout-items');
    const totalEl = document.getElementById('checkout-total');
    const subtotalEl = document.getElementById('checkout-subtotal');
    const shippingEl = document.getElementById('checkout-shipping');
    const taxEl = document.getElementById('checkout-tax');
    const countEl = document.getElementById('checkout-count');
    const hiddenCart = document.getElementById('hidden-cart-data');
    const form = document.getElementById('checkout-form');
    const btn = document.getElementById('submitBtn');
    const addressInput = form.querySelector('input[name="address"]');
    const cityInput = form.querySelector('input[name="city"]');
    const stateInput = form.querySelector('input[name="state"]');
    const zipInput = form.querySelector('input[name="zip"]');
    const countryInput = form.querySelector('input[name="country"]');
    const addressSuggestions = document.getElementById('addressSuggestions');
    const config = {
        googlePlacesKey: <?= json_encode($googlePlacesKey, JSON_UNESCAPED_SLASHES) ?>,
        mapboxPublicToken: <?= json_encode($mapboxPublicToken, JSON_UNESCAPED_SLASHES) ?>,
        shippingFreeOver: <?= json_encode($shippingFreeOver) ?>,
        shippingFlat: <?= json_encode($shippingFlat) ?>,
        shippingIntl: <?= json_encode($shippingIntl) ?>,
        shippingByState: <?= json_encode(json_decode($shippingByStateJson, true) ?: new stdClass(), JSON_UNESCAPED_SLASHES) ?>,
        taxDefault: <?= json_encode($taxDefault) ?>,
        taxByState: <?= json_encode(json_decode($taxByStateJson, true) ?: new stdClass(), JSON_UNESCAPED_SLASHES) ?>,
        taxShippingEnabled: <?= $taxShippingEnabled ? 'true' : 'false' ?>
    };

    function showError(msg){
        if (!inlineError) return;
        inlineError.textContent = msg;
        inlineError.style.display = 'block';
        if (inlineOk) inlineOk.style.display = 'none';
    }
    function showOk(msg){
        if (!inlineOk) return;
        inlineOk.textContent = msg;
        inlineOk.style.display = 'block';
        if (inlineError) inlineError.style.display = 'none';
    }
    function money(n){
        const x = Number(n);
        return (Number.isFinite(x) ? x : 0).toFixed(2);
    }
    function safeText(s){
        return String(s ?? '').replace(/[<>]/g, '');
    }
    function safeCode(v, len){
        return String(v || '').toUpperCase().replace(/[^A-Z]/g, '').slice(0, len);
    }
    function safeZip(v){
        return String(v || '').toUpperCase().replace(/[^0-9A-Z-]/g, '').slice(0, 12);
    }
    function normalizeCountry(v){
        const out = String(v || '').toUpperCase().replace(/[^A-Z]/g, '').slice(0, 2);
        return out || 'US';
    }
    function normalizeState(v){
        return String(v || '').toUpperCase().replace(/[^A-Z]/g, '').slice(0, 2);
    }
    function resolveShipping(subtotal, country, state){
        if (country !== 'US') {
            return Number(config.shippingIntl) || 0;
        }
        if (subtotal >= (Number(config.shippingFreeOver) || 0)) {
            return 0;
        }
        const s = normalizeState(state);
        if (config.shippingByState && typeof config.shippingByState === 'object') {
            const raw = config.shippingByState[s] ?? config.shippingByState[String(s).toLowerCase()] ?? null;
            if (raw !== null && raw !== undefined && raw !== '' && !Number.isNaN(Number(raw))) {
                return Math.max(0, Number(raw));
            }
        }
        return Number(config.shippingFlat) || 0;
    }
    function resolveTaxRate(country, state){
        if (country !== 'US') {
            return Math.max(0, Number(config.taxDefault) || 0);
        }
        const s = normalizeState(state);
        if (config.taxByState && typeof config.taxByState === 'object') {
            const raw = config.taxByState[s] ?? config.taxByState[String(s).toLowerCase()] ?? null;
            if (raw !== null && raw !== undefined && raw !== '' && !Number.isNaN(Number(raw))) {
                return Math.max(0, Number(raw));
            }
        }
        return Math.max(0, Number(config.taxDefault) || 0);
    }
    function recomputeSummary(subtotal){
        const country = normalizeCountry(countryInput ? countryInput.value : 'US');
        const state = normalizeState(stateInput ? stateInput.value : '');
        if (countryInput) countryInput.value = country;
        if (stateInput) stateInput.value = state;

        const shipping = resolveShipping(subtotal, country, state);
        const taxRate = resolveTaxRate(country, state);
        const taxableBase = subtotal + (config.taxShippingEnabled ? shipping : 0);
        const tax = taxableBase * (taxRate / 100);
        const grand = subtotal + shipping + tax;

        if (subtotalEl) subtotalEl.textContent = `$${money(subtotal)}`;
        if (shippingEl) shippingEl.textContent = `$${money(shipping)}`;
        if (taxEl) taxEl.textContent = `$${money(tax)}`;
        totalEl.textContent = `$${money(grand)}`;
    }
    function setAddressParts(parts){
        if (!parts || typeof parts !== 'object') return;
        if (parts.address && addressInput) addressInput.value = safeText(parts.address);
        if (parts.city && cityInput) cityInput.value = safeText(parts.city);
        if (parts.state && stateInput) stateInput.value = safeCode(parts.state, 2);
        if (parts.zip && zipInput) zipInput.value = safeZip(parts.zip);
        if (parts.country && countryInput) countryInput.value = safeCode(parts.country, 2) || 'US';
        recomputeSummary(window.__dodCheckoutSubtotal || 0);
    }

    function setupGooglePlacesAutocomplete(){
        if (!config.googlePlacesKey || !addressInput) return false;
        const callbackName = '__dodInitGooglePlacesCheckout';
        window[callbackName] = function(){
            if (!(window.google && window.google.maps && window.google.maps.places)) return;
            const autocomplete = new window.google.maps.places.Autocomplete(addressInput, {
                types: ['address'],
                componentRestrictions: { country: ['us'] },
                fields: ['address_components', 'formatted_address']
            });
            autocomplete.addListener('place_changed', () => {
                const place = autocomplete.getPlace();
                const parts = { address: place.formatted_address || addressInput.value };
                const comps = Array.isArray(place.address_components) ? place.address_components : [];
                comps.forEach((c) => {
                    const types = c.types || [];
                    if (types.includes('locality')) parts.city = c.long_name;
                    if (types.includes('administrative_area_level_1')) parts.state = c.short_name;
                    if (types.includes('postal_code')) parts.zip = c.long_name;
                    if (types.includes('country')) parts.country = c.short_name;
                });
                setAddressParts(parts);
            });
        };

        const s = document.createElement('script');
        s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(config.googlePlacesKey) + '&libraries=places&callback=' + callbackName;
        s.async = true;
        s.defer = true;
        s.onerror = () => { setupMapboxAutocomplete(); };
        document.head.appendChild(s);
        return true;
    }

    function setupMapboxAutocomplete(){
        if (!config.mapboxPublicToken || !addressInput || !addressSuggestions) return false;
        addressInput.setAttribute('list', 'addressSuggestions');
        const featuresByLabel = new Map();
        let timer = null;

        const parseMapboxFeature = (feature) => {
            const parts = { address: feature.place_name || addressInput.value };
            const ctx = Array.isArray(feature.context) ? feature.context : [];
            const all = [feature].concat(ctx);
            all.forEach((c) => {
                const id = String(c.id || '');
                if (id.startsWith('place.')) parts.city = c.text || parts.city;
                if (id.startsWith('region.')) parts.state = c.short_code ? String(c.short_code).split('-').pop() : c.text;
                if (id.startsWith('postcode.')) parts.zip = c.text || parts.zip;
                if (id.startsWith('country.')) parts.country = c.short_code || c.text;
            });
            return parts;
        };

        addressInput.addEventListener('input', () => {
            const q = addressInput.value.trim();
            if (q.length < 4) return;
            if (timer) window.clearTimeout(timer);
            timer = window.setTimeout(async () => {
                try {
                    const url = 'https://api.mapbox.com/geocoding/v5/mapbox.places/' +
                        encodeURIComponent(q) +
                        '.json?autocomplete=true&types=address&country=us&limit=5&access_token=' +
                        encodeURIComponent(config.mapboxPublicToken);
                    const res = await fetch(url);
                    if (!res.ok) return;
                    const data = await res.json();
                    const features = Array.isArray(data.features) ? data.features : [];
                    featuresByLabel.clear();
                    addressSuggestions.innerHTML = '';
                    features.forEach((f) => {
                        const label = safeText(f.place_name || '');
                        if (!label) return;
                        featuresByLabel.set(label, f);
                        const o = document.createElement('option');
                        o.value = label;
                        addressSuggestions.appendChild(o);
                    });
                } catch (_) {}
            }, 180);
        });

        addressInput.addEventListener('change', () => {
            const key = addressInput.value.trim();
            const feature = featuresByLabel.get(key);
            if (!feature) return;
            setAddressParts(parseMapboxFeature(feature));
        });
        return true;
    }

    function normalizeCheckoutCart(rawValue){
        // Supports:
        // - v5.x object: { items: [...], cart_id, checkout_token, version, ... }
        // - legacy array: [ ...items ]
        // - fallback: sessionStorage backup from prior step
        let parsed = null;

        try {
            parsed = JSON.parse(rawValue || 'null');
        } catch (e) {
            parsed = null;
        }

        let arr = [];
        if (Array.isArray(parsed)) {
            arr = parsed;
        } else if (parsed && typeof parsed === 'object' && Array.isArray(parsed.items)) {
            arr = parsed.items;
        }

        // If nothing, try sessionStorage backup
        if (!Array.isArray(arr) || arr.length === 0) {
            try {
                const backup = sessionStorage.getItem('dod_checkout_cart_backup');
                if (backup) {
                    const b = JSON.parse(backup);
                    if (Array.isArray(b)) arr = b;
                    else if (b && typeof b === 'object' && Array.isArray(b.items)) arr = b.items;
                }
            } catch (e) {}
        }

        // Normalize each item to safe shape
        const clean = [];
        (arr || []).forEach(it => {
            if (!it || typeof it !== 'object') return;

            const pid = String(it.product_id ?? it.id ?? '').replace(/[^\d]/g, '');
            const price = Number(it.price) || 0;
            const qty = Math.max(1, Math.min(99, Math.floor(Number(it.quantity) || 1)));

            clean.push({
                product_id: pid ? Number(pid) : 0,
                id: pid ? Number(pid) : 0,
                name: safeText(it.name || 'ITEM'),
                price: price,
                quantity: qty,
                size: safeText(it.size || 'OS'),
                color: safeText(it.color || 'NOCOLOR'),
                image: safeText(it.image || it.image_url || '')
            });
        });

        return clean.filter(x => (Number(x.product_id) || 0) > 0 && Number.isFinite(Number(x.price)));
    }

    // Load cart from localStorage (supports v5.x object)
    setupGooglePlacesAutocomplete() || setupMapboxAutocomplete();
    const raw = localStorage.getItem('dod_cart');
    const cart = normalizeCheckoutCart(raw);

    if (!Array.isArray(cart) || cart.length === 0) {
        // If cart isn't here, redirect back to bag
        window.location.href = '/cart?msg=EMPTY_CART';
        return;
    }

    // Render summary + compute total
    let total = 0;
    items.innerHTML = '';
    cart.forEach(it => {
        const price = Number(it.price) || 0;
        const qty = Math.max(1, Math.min(99, Math.floor(Number(it.quantity) || 1)));
        const line = price * qty;
        total += line;

        const name = safeText(it.name || 'ITEM');
        const size = safeText(it.size || 'OS');

        items.innerHTML += `
            <div class="summary-row">
                <span class="summary-main">
                    <span class="summary-name">${name}</span>
                    <span class="item-spec">${size} × ${qty}</span>
                </span>
                <span class="summary-amount">$${money(line)}</span>
            </div>
        `;
    });

    if (countEl) countEl.textContent = String(cart.length);
    window.__dodCheckoutSubtotal = total;
    recomputeSummary(total);

    if (typeof window.dodTrackInitiateCheckout === 'function') {
        window.dodTrackInitiateCheckout(
            cart.map(it => ({ id: it.id, name: it.name, price: it.price, quantity: it.quantity })),
            total
        );
    }

    if (countryInput) {
        countryInput.addEventListener('input', () => recomputeSummary(window.__dodCheckoutSubtotal || 0));
        countryInput.addEventListener('change', () => recomputeSummary(window.__dodCheckoutSubtotal || 0));
    }
    if (stateInput) {
        stateInput.addEventListener('input', () => recomputeSummary(window.__dodCheckoutSubtotal || 0));
        stateInput.addEventListener('change', () => recomputeSummary(window.__dodCheckoutSubtotal || 0));
    }

    // Critical: set hidden cart_data BEFORE submit
    try {
        hiddenCart.value = JSON.stringify(cart);
        sessionStorage.setItem('dod_checkout_cart_backup', hiddenCart.value);
        showOk('CART_LOCKED — READY_FOR_STRIPE_REDIRECT.');
    } catch (e) {
        showError('CART_SERIALIZATION_FAILED — PLEASE TRY AGAIN.');
        return;
    }

    // Harden submit with fetch (POST explicitly)
    let submitLock = false;
    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        if (submitLock) return;

        if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
            showError('FORM_INVALID — CHECK FIELDS AND TRY AGAIN.');
            return;
        }

        if (!hiddenCart.value || hiddenCart.value.length < 2) {
            showError('CART_DATA_MISSING — RETURN_TO_BAG_AND_RETRY.');
            return;
        }

        submitLock = true;
        btn.disabled = true;
        btn.textContent = 'REDIRECTING_TO_STRIPE...';

        try {
            const formData = new FormData(form);
            const res = await fetch('/create_checkout_session.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            let data = null;
            const contentType = (res.headers.get('content-type') || '').toLowerCase();
            if (contentType.includes('application/json')) {
                data = await res.json();
            } else {
                const txt = await res.text();
                try { data = JSON.parse(txt); } catch(e){ data = { error: 'NON_JSON_RESPONSE' }; }
            }

            if (!res.ok) {
                const errMsg = (data && data.error) ? data.error : ('HTTP_' + res.status);
                showError('STRIPE_ERROR: ' + errMsg);
                submitLock = false;
                btn.disabled = false;
                btn.textContent = 'PROCEED_TO_PAYMENT';
                return;
            }

            if (data && data.error) {
                showError('STRIPE_ERROR: ' + data.error);
                submitLock = false;
                btn.disabled = false;
                btn.textContent = 'PROCEED_TO_PAYMENT';
                return;
            }

            if (data && data.url) {
                window.location.href = data.url;
            } else if (data && data.id) {
                // Fallback: construct Stripe URL from session ID (rarely needed; Stripe usually returns url)
                window.location.href = 'https://checkout.stripe.com/pay/' + data.id;
            } else {
                showError('CHECKOUT_SESSION_FAILED — NO_URL_RETURNED.');
                submitLock = false;
                btn.disabled = false;
                btn.textContent = 'PROCEED_TO_PAYMENT';
            }
        } catch (err) {
            console.error(err);
            showError('NETWORK_ERROR — ' + (err && err.message ? err.message : 'UNKNOWN'));
            submitLock = false;
            btn.disabled = false;
            btn.textContent = 'PROCEED_TO_PAYMENT';
        }
    });
})();
</script>
</body>
</html>
<?php
// END checkout.php v3.4 (HARDENED + v5.x CART COMPAT)
?>