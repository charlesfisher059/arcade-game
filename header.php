<?php
/**
 * /partials/header.php
 * Diamonds Outta Dirt — Tactical Header/Nav (v4.9.1 ACCOUNT MENU FIX)
 *
 * IMPORTANT:
 * - This is a TRUE PARTIAL. It does NOT output: <!doctype>, <html>, <head>, <body>, or <main>.
 * - Safe to include from inside an existing page template.
 * - Does NOT depend on DiamondCart\CartSession (prevents "Class not found" crashes).
 * - Cart count reads from $_SESSION + syncs from /cart.php?action=get_cart_state&ajax=1
 */

declare(strict_types=1);

// Shared persistent session configuration.
$sessionBootstrap = __DIR__ . '/../includes/session_bootstrap.php';
if (is_file($sessionBootstrap)) {
    require_once $sessionBootstrap;
} elseif (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

// --- Cart totals from session (fallback/base) ---------------------------------
$cartCount = 0;
$cartTotal = 0.00;

// v5.x cart structure with 'items'
if (isset($_SESSION['cart']['items']) && is_array($_SESSION['cart']['items'])) {
    foreach ($_SESSION['cart']['items'] as $item) {
        if (!is_array($item)) continue;

        $q = max(0, (int)($item['quantity'] ?? 0));
        $cartCount += $q;

        if (isset($item['price_cents'])) {
            $p = max(0.0, ((float)$item['price_cents'] / 100.0));
            $cartTotal += $q * $p;
        } elseif (isset($item['price'])) {
            $p = max(0.0, (float)$item['price']);
            $cartTotal += $q * $p;
        }
    }
}
// Legacy flat cart
elseif (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        if (!is_array($item)) {
            if (is_numeric($item)) $cartCount += (int)$item;
            continue;
        }

        $q = max(0, (int)($item['quantity'] ?? 0));
        $cartCount += $q;

        if (isset($item['price'])) {
            $p = max(0.0, (float)$item['price']);
            $cartTotal += $q * $p;
        }
    }
}

/* --- Active nav state (CLEAN URL AWARE) ------------------------------------ */
$currentPage = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$reqPath = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($reqPath === '') $reqPath = '/';

// Normalize trailing slash except root
if ($reqPath !== '/' && substr($reqPath, -1) === '/') {
    $reqPath = rtrim($reqPath, '/');
}

$pathIs = static function (string $path) use ($reqPath): bool {
    return $reqPath === $path;
};
$pathStarts = static function (string $prefix) use ($reqPath): bool {
    return $prefix !== '' && strpos($reqPath, $prefix) === 0;
};

// HOME when "/" OR direct index.php hit (canonical rewrite still prefers "/")
$isHomePage     = $pathIs('/') || $currentPage === 'index.php';

// ARCHIVE active on /shop and /product/*
$isArchivePage  = $pathIs('/shop') || $pathStarts('/shop/') || $pathStarts('/product');

// Other pages
$isLookbookPage = $pathIs('/lookbook') || $currentPage === 'lookbook.php';
$isAboutPage    = $pathIs('/about') || $pathIs('/about.php') || $currentPage === 'about.php';
$isServicesPage = $pathIs('/services') || $pathStarts('/services/') || $currentPage === 'services.php';
$isArcadePage   = $pathIs('/arcade')
    || ($currentPage === 'arcade.php' && !$pathStarts('/account'));
$isEventsPage   = $pathIs('/events') || $currentPage === 'events.php';
$isCastingPage  = $pathIs('/casting') || $currentPage === 'casting.php';

$isWishlistPage = $pathIs('/wishlist') || $pathIs('/wishlist.php') || $currentPage === 'wishlist.php';

$isCustomerLoggedIn = !empty($_SESSION['customer_id']);
$isAccountPage = $pathStarts('/account') || $currentPage === 'account.php';
$isArcadeProfilePage = $pathIs('/account/arcade')
    || $pathIs('/account/arcade.php');

// Only show wishlist link if file exists (prevents 404)
$wishlistExists = @is_file(__DIR__ . '/../wishlist.php');
?>

<script>
/**
 * DOD: minimal cart-related storage hygiene ONLY.
 * Do NOT delete unrelated keys (could break other features/tools).
 */
(function dodStorageHygiene(){
  try {
    const legacyKeys = ['DOD_CART', 'cart', 'bagCount'];
    legacyKeys.forEach(k => {
      if (k !== 'dod_cart' && k !== 'cart_version' && k !== 'bagCountJS') {
        const v = localStorage.getItem(k);
        if (v && v.length < 20000) {
          try { localStorage.removeItem(k); } catch(e) {}
        }
      }
    });
  } catch(e) {}
})();
</script>

<style>
:root{
  --neon:#00ff9d;
  --cyber-blue:#00f3ff;
  --border:#333;
  --glass:rgba(5,5,10,.95);
  --glass2:rgba(0,0,0,.92);
  --ink:#fff;
  --muted:#888;
  --dark:#000;
  --hdrH:92px;
}

.tactical-nav{
  width:100%;
  padding:15px 5%;
  background:var(--glass);
  border-bottom:1px solid var(--border);
  position:fixed; top:0; left:0;
  z-index:1000;
  backdrop-filter:blur(10px);
  -webkit-backdrop-filter:blur(10px);
  box-shadow:0 5px 30px rgba(0,0,0,.8);
  overflow:hidden;
}
.tactical-nav::before{
  content:""; position:absolute; inset:0; pointer-events:none;
  background-image:
    linear-gradient(to right, rgba(0,255,157,.06) 1px, transparent 1px),
    linear-gradient(to bottom, rgba(255,0,255,.04) 1px, transparent 1px);
  background-size:40px 40px;
  opacity:.25;
  mix-blend-mode:screen;
}
.tactical-nav::after{
  content:""; position:absolute; inset:0; pointer-events:none;
  background:repeating-linear-gradient(
    to bottom,
    rgba(0,255,157,0.02),
    rgba(0,255,157,0.02) 1px,
    rgba(0,0,0,0.00) 2px,
    rgba(0,0,0,0.00) 4px
  );
  opacity:.15;
  mix-blend-mode:overlay;
}

.top-bar{
  display:flex; justify-content:space-between; align-items:center;
  gap:14px; position:relative; z-index:2;
}

.logo{
  font-family:'Syncopate',sans-serif;
  letter-spacing:8px;
  font-size:.90rem;
  text-decoration:none;
  color:var(--neon);
  text-transform:uppercase;
  line-height:1.1;
  text-shadow:0 0 10px rgba(0,255,157,.3);
  animation:syncopatePulse 3s steps(14,end) infinite;
  font-weight:900;
  position:relative;
  -webkit-tap-highlight-color:transparent;
}
.logo::after{
  content:""; position:absolute; bottom:-5px; left:0;
  width:100%; height:2px;
  background:linear-gradient(90deg,var(--neon),transparent);
}
@keyframes syncopatePulse{
  0%{letter-spacing:8px;filter:brightness(1)}
  38%{letter-spacing:12px;filter:brightness(1.15)}
  55%{letter-spacing:6px;filter:brightness(.95)}
  100%{letter-spacing:8px;filter:brightness(1)}
}

.system-status{
  font-size:.6rem;
  letter-spacing:2px;
  color:var(--muted);
  font-family:'Space Mono',monospace;
  display:flex; gap:10px; align-items:center; flex-wrap:wrap;
  position:relative; z-index:2;
}
.system-status .dot{
  width:8px;height:8px;border-radius:50%;
  background:var(--neon);
  box-shadow:0 0 10px rgba(0,255,157,.5);
  display:inline-block;
  animation:dotPulse 2s infinite;
}
@keyframes dotPulse{0%,100%{opacity:1}50%{opacity:.7}}
.system-status .time{color:var(--neon);text-shadow:0 0 8px rgba(0,255,157,.3)}

.sub-nav{
  display:flex;
  justify-content:space-between;
  align-items:center;
  margin-top:16px;
  padding-top:14px;
  border-top:1px solid var(--border);
  position:relative; z-index:2;
  gap:18px;
}
.nav-group,.nav-links{
  display:flex; align-items:center; gap:18px; flex-wrap:wrap;
}
.nav-tab{
  font-size:.72rem;
  letter-spacing:2px;
  color:var(--ink);
  text-decoration:none;
  font-family:'Space Mono',monospace;
  padding:10px 16px;
  border-radius:6px;
  border:1px solid var(--border);
  background:transparent;
  transition:all .25s ease;
  position:relative;
  overflow:hidden;
  touch-action:manipulation;
  -webkit-tap-highlight-color:transparent;
}
.nav-tab:hover,.nav-tab.active{
  color:var(--neon);
  border-color:var(--neon);
  background:rgba(0,255,157,.05);
  transform:translateY(-2px);
  box-shadow:0 5px 15px rgba(0,255,157,.1);
}
.nav-tab::after{
  content:""; position:absolute; bottom:-5px; left:0;
  width:0; height:1px; background:var(--neon);
  transition:width .25s ease;
}
.nav-tab:hover::after,.nav-tab.active::after{width:100%}
.nav-tab:focus-visible{outline:2px solid var(--neon); outline-offset:2px}

.nav-tab.bag{
  color:var(--neon);
  border-color:var(--neon);
  background:rgba(0,255,157,.1);
  font-weight:900;
}
.nav-tab.bag:hover{background:var(--neon); color:#000}

.nav-dropdown{
  position:relative;
  display:inline-flex;
}
.nav-dropdown-trigger{
  font-family:'Space Mono',monospace;
  cursor:pointer;
  display:inline-flex;
  align-items:center;
  gap:6px;
}
.nav-dropdown-chevron{
  font-size:.6rem;
  transition:transform .2s ease;
}
.nav-dropdown.open .nav-dropdown-chevron{transform:rotate(180deg)}
.nav-dropdown-menu{
  display:none;
  position:absolute;
  top:calc(100% + 8px);
  left:0;
  min-width:180px;
  flex-direction:column;
  gap:4px;
  padding:8px;
  background:var(--glass2, rgba(10,10,10,.96));
  border:1px solid var(--border);
  border-radius:8px;
  box-shadow:0 18px 40px rgba(0,0,0,.6);
  backdrop-filter:blur(12px);
  -webkit-backdrop-filter:blur(12px);
  z-index:20;
}
.nav-dropdown.open .nav-dropdown-menu{display:flex}
.nav-dropdown-link{
  font-size:.72rem;
  letter-spacing:1.5px;
  color:var(--ink);
  text-decoration:none;
  font-family:'Space Mono',monospace;
  padding:9px 12px;
  border-radius:6px;
  transition:all .2s ease;
  white-space:nowrap;
}
.nav-dropdown-link:hover,.nav-dropdown-link.active{
  color:var(--neon);
  background:rgba(0,255,157,.08);
}
.nav-links .nav-dropdown-menu{
  left:auto;
  right:0;
}
.nav-dropdown-menu{
  z-index:1006;
}

@media (min-width: 769px){
  .nav-dropdown:hover .nav-dropdown-menu{display:flex}
}

.bag-count{
  background:var(--neon);
  color:#000;
  font-size:.7rem;
  font-weight:900;
  min-width:20px;height:20px;
  border-radius:50%;
  display:inline-flex; align-items:center; justify-content:center;
  padding:0 5px;
  margin-left:6px;
  box-shadow:0 0 10px var(--neon);
}

.menu-toggle{
  display:none;
  font-size:.65rem;
  letter-spacing:2px;
  cursor:pointer;
  color:var(--neon);
  font-family:'Space Mono',monospace;
  border:1px solid var(--neon);
  background:rgba(0,255,157,.1);
  padding:12px 14px;
  border-radius:8px;
  user-select:none;
  transition:all .25s ease;
  touch-action:manipulation;
  -webkit-tap-highlight-color:transparent;
}
.menu-toggle:hover{background:var(--neon); color:#000}
.menu-toggle:focus-visible{outline:2px solid var(--neon); outline-offset:2px}

@media (max-width: 992px){
  .tactical-nav{padding:12px 5%}
  .logo{letter-spacing:6px; font-size:.86rem}
  .system-status{font-size:.56rem; gap:6px}
}
@media (max-width: 768px){
  .tactical-nav{overflow:visible}
  .tactical-nav{padding:10px 4%}
  .top-bar{gap:10px}
  .logo{letter-spacing:5px; font-size:.82rem}
  .system-status{display:none}
  .menu-toggle{display:inline-flex; align-items:center; gap:8px; margin-left:auto}

  .sub-nav{
    display:none;
    position:fixed;
    left:0; right:0;
    top:var(--hdrH);
    z-index:1002;
    flex-direction:column;
    gap:12px;
    margin-top:0;
    padding:16px;
    padding-top:calc(16px + env(safe-area-inset-top));
    background:var(--glass2);
    border-top:1px solid var(--border);
    border-bottom:1px solid var(--border);
    backdrop-filter:blur(12px);
    -webkit-backdrop-filter:blur(12px);
    box-shadow:0 18px 60px rgba(0,0,0,.7);
    max-height:calc(100vh - var(--hdrH));
    overflow-y:auto;
    -webkit-overflow-scrolling:touch;
    overscroll-behavior:contain;
    padding-bottom:calc(16px + env(safe-area-inset-bottom));
  }
  .sub-nav.active{
    display:flex;
    z-index:1004;
    pointer-events:auto;
  }

  .nav-group,.nav-links{
    flex-direction:column;
    align-items:stretch;
    gap:10px;
    width:100%;
  }
  .nav-links{
    border-top:1px solid var(--border);
    padding-top:12px;
    margin-top:6px;
  }
  .nav-tab{
    width:100%;
    padding:14px 14px;
    border-radius:12px;
    text-align:center;
    font-size:.78rem;
    letter-spacing:2px;
    background:rgba(0,0,0,.28);
  }
  .nav-tab:hover,.nav-tab.active{transform:translateY(-1px)}

  .nav-overlay{
    position:fixed; inset:0;
    pointer-events:auto;
    background:rgba(0,0,0,.55);
    backdrop-filter:blur(2px);
    -webkit-backdrop-filter:blur(2px);
    z-index:999;
  }

  .nav-dropdown{
    display:flex;
    flex-direction:column;
    width:100%;
  }
  .nav-dropdown-trigger{
    width:100%;
    justify-content:center;
  }
  .nav-dropdown-menu,
  .nav-links .nav-dropdown-menu{
    position:static;
    left:auto;
    right:auto;
    display:none;
    width:100%;
    box-shadow:none;
    background:rgba(0,0,0,.2);
    margin-top:6px;
  }
  .nav-dropdown.open .nav-dropdown-menu{display:flex}
  .nav-dropdown-link{
    text-align:center;
    white-space:normal;
  }
}
@media (max-width: 480px){
  .tactical-nav{padding:8px 3%}
  .logo{letter-spacing:4px; font-size:.78rem}
  .menu-toggle{padding:12px 12px; font-size:.62rem}
}
@media (prefers-reduced-motion: reduce){
  .logo{animation:none}
  .nav-tab{transition:none}
  .system-status .dot{animation:none}
  .nav-tab:hover,.nav-tab.active{transform:none}
}

.skip-link{
  position:absolute;
  top:-40px; left:10px;
  background:var(--neon);
  color:#000;
  padding:12px 20px;
  z-index:10000;
  text-decoration:none;
  font-family:'Space Mono',monospace;
  font-size:.8rem;
  font-weight:900;
  border:1px solid var(--dark);
  transition:top .25s ease;
}
.skip-link:focus{top:10px; outline:2px solid var(--neon); outline-offset:2px}
</style>

<a href="#main-content" class="skip-link" tabindex="1">SKIP TO MAIN CONTENT</a>

<header class="tactical-nav" role="banner" id="dodHeader">
  <div class="top-bar">
    <a href="/" class="logo" aria-label="Diamonds Outta Dirt home">DIAMONDS OUTTA DIRT</a>

    <div class="system-status" aria-live="polite">
      <span class="dot" aria-hidden="true"></span>
      <span>STATUS: ONLINE // ARCHIVE</span>
      <span class="time" id="headerTime">SYNC: --:--:--</span>
    </div>

    <button
      class="menu-toggle"
      id="menuToggle"
      type="button"
      aria-controls="mainNav"
      aria-expanded="false"
      aria-label="Toggle navigation menu"
    >
      MENU <span id="menuChevron" aria-hidden="true">▸</span>
    </button>
  </div>

  <nav class="sub-nav" id="mainNav" aria-label="Main navigation">
    <div class="nav-group">
      <a href="/" class="nav-tab <?= $isHomePage ? 'active' : '' ?>">HOME</a>

      <div class="nav-dropdown <?= ($isArchivePage || $isLookbookPage || $isWishlistPage) ? 'active-group' : '' ?>">
        <button type="button" class="nav-tab nav-dropdown-trigger <?= ($isArchivePage || $isLookbookPage || $isWishlistPage) ? 'active' : '' ?>" aria-expanded="false" aria-haspopup="true">
          SHOP <span class="nav-dropdown-chevron" aria-hidden="true">▾</span>
        </button>
        <div class="nav-dropdown-menu" role="menu">
          <a href="/shop" class="nav-dropdown-link <?= $isArchivePage ? 'active' : '' ?>" role="menuitem">ARCHIVE</a>
          <a href="/lookbook" class="nav-dropdown-link <?= $isLookbookPage ? 'active' : '' ?>" role="menuitem">LOOKBOOK</a>
          <?php if ($wishlistExists): ?>
            <a href="/wishlist" class="nav-dropdown-link <?= $isWishlistPage ? 'active' : '' ?>" role="menuitem">WISHLIST</a>
          <?php endif; ?>
        </div>
      </div>

      <a href="/about"    class="nav-tab <?= $isAboutPage ? 'active' : '' ?>">OUR STORY</a>
      <a href="/services" class="nav-tab <?= $isServicesPage ? 'active' : '' ?>">SERVICES</a>
      <a href="/casting"  class="nav-tab <?= $isCastingPage ? 'active' : '' ?>">CASTING</a>
      <a href="/arcade"   class="nav-tab <?= $isArcadePage ? 'active' : '' ?>">ARCADE</a>
      <a href="/events"   class="nav-tab <?= $isEventsPage ? 'active' : '' ?>">EVENTS</a>
    </div>

    <div class="nav-links">
      <?php if ($isCustomerLoggedIn): ?>
        <div class="nav-dropdown account-nav-dropdown <?= $isAccountPage ? 'active-group' : '' ?>">
          <button
            type="button"
            class="nav-tab nav-dropdown-trigger <?= $isAccountPage ? 'active' : '' ?>"
            aria-expanded="false"
            aria-haspopup="true"
          >
            ACCOUNT <span class="nav-dropdown-chevron" aria-hidden="true">▾</span>
          </button>
          <div class="nav-dropdown-menu account-nav-menu" role="menu" aria-label="Account navigation">
            <a
              href="/account"
              class="nav-dropdown-link <?= ($isAccountPage && !$isArcadeProfilePage) ? 'active' : '' ?>"
              role="menuitem"
            >ACCOUNT OVERVIEW</a>
            <a
              href="/account/arcade.php"
              class="nav-dropdown-link <?= $isArcadeProfilePage ? 'active' : '' ?>"
              role="menuitem"
            >ARCADE PROFILE</a>
          </div>
        </div>
      <?php else: ?>
        <a href="/account/login" class="nav-tab <?= $isAccountPage ? 'active' : '' ?>">LOG IN</a>
      <?php endif; ?>
      <a href="/cart" class="nav-tab bag" id="bagLink" aria-label="View bag">
        BAG
        <span id="bagCountWrap">
          <?php if ($cartCount > 0): ?>
            <span class="bag-count" id="bagCountPHP"><?= (int)$cartCount ?></span>
          <?php endif; ?>
        </span>
      </a>
    </div>
  </nav>
</header>

<script>
(function(){
  const header = document.getElementById('dodHeader');
  const nav = document.getElementById('mainNav');
  const btn = document.getElementById('menuToggle');
  const chev = document.getElementById('menuChevron');
  const timeEl = document.getElementById('headerTime');
  const bagWrap = document.getElementById('bagCountWrap');

  // Shop dropdown: click to toggle (works for touch, keyboard, and as a
  // fallback alongside the CSS hover-open on desktop). Only one open at
  // a time, closes on outside click or Escape.
  const dropdowns = Array.prototype.slice.call(document.querySelectorAll('.nav-dropdown'));
  dropdowns.forEach(function (dropdown) {
    const trigger = dropdown.querySelector('.nav-dropdown-trigger');
    if (!trigger) return;
    trigger.addEventListener('click', function (e) {
      e.preventDefault();
      const isOpen = dropdown.classList.contains('open');
      dropdowns.forEach(function (d) {
        d.classList.remove('open');
        const t = d.querySelector('.nav-dropdown-trigger');
        if (t) t.setAttribute('aria-expanded', 'false');
      });
      if (!isOpen) {
        dropdown.classList.add('open');
        trigger.setAttribute('aria-expanded', 'true');
      }
    });
  });
  document.addEventListener('click', function (e) {
    dropdowns.forEach(function (d) {
      if (!d.contains(e.target)) {
        d.classList.remove('open');
        const t = d.querySelector('.nav-dropdown-trigger');
        if (t) t.setAttribute('aria-expanded', 'false');
      }
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      dropdowns.forEach(function (d) {
        d.classList.remove('open');
        const t = d.querySelector('.nav-dropdown-trigger');
        if (t) t.setAttribute('aria-expanded', 'false');
      });
    }
  });

  // Measure header height so drawer sits under it
  function setHeaderHeightVar(){
    if (!header) return;
    const h = header.getBoundingClientRect().height;
    document.documentElement.style.setProperty('--hdrH', Math.max(56, Math.round(h)) + 'px');
  }
  setHeaderHeightVar();
  window.addEventListener('resize', setHeaderHeightVar);

  // Live time
  function pad(n){ return String(n).padStart(2,'0'); }
  function tick(){
    const d = new Date();
    if (timeEl) timeEl.textContent = `SYNC: ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
  }
  tick();
  setInterval(tick, 1000);

  // Drawer overlay + scroll lock (iOS-safe)
  let overlay = null;
  let scrollY = 0;

  function lockBody(){
    scrollY = window.scrollY || document.documentElement.scrollTop || 0;
    document.body.style.position = 'fixed';
    document.body.style.top = `-${scrollY}px`;
    document.body.style.left = '0';
    document.body.style.right = '0';
    document.body.style.width = '100%';
  }
  function unlockBody(){
    document.body.style.position = '';
    document.body.style.top = '';
    document.body.style.left = '';
    document.body.style.right = '';
    document.body.style.width = '';
    window.scrollTo(0, scrollY);
  }

  function ensureOverlay(){
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.className = 'nav-overlay';
    overlay.addEventListener('click', closeMenu);
    return overlay;
  }

  function openMenu(){
    if (!nav) return;
    nav.classList.add('active');
    if (btn) btn.setAttribute('aria-expanded','true');
    if (chev) chev.textContent = '▾';

    if (window.matchMedia('(max-width: 768px)').matches) {
      const o = ensureOverlay();
      if (!o.isConnected) document.body.appendChild(o);
      lockBody();
      fetchCartState(); // “live” refresh on open
    }
  }

  function closeMenu(){
    if (!nav) return;
    nav.classList.remove('active');
    if (btn) btn.setAttribute('aria-expanded','false');
    if (chev) chev.textContent = '▸';

    if (overlay && overlay.isConnected) overlay.remove();
    if (window.matchMedia('(max-width: 768px)').matches) unlockBody();
  }

  function toggleMenu(){
    (nav && nav.classList.contains('active')) ? closeMenu() : openMenu();
  }

  if (btn) btn.addEventListener('click', toggleMenu);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' || e.key === 'Esc') closeMenu();
  });

  if (nav) {
    nav.addEventListener('click', (e) => {
      const a = e.target.closest('a');
      if (!a) return;
      if (window.matchMedia('(max-width: 768px)').matches) {
        setTimeout(closeMenu, 120);
      }
    });
  }

  window.addEventListener('resize', () => {
    if (window.innerWidth > 768 && nav && nav.classList.contains('active')) closeMenu();
  });

  // Focus trap
  document.addEventListener('keydown', (e) => {
    if (!nav || !nav.classList.contains('active')) return;
    if (e.key !== 'Tab') return;

    const focusables = nav.querySelectorAll('a, button, [href], [tabindex]:not([tabindex="-1"])');
    if (!focusables.length) return;
    const first = focusables[0];
    const last  = focusables[focusables.length - 1];

    if (e.shiftKey) {
      if (document.activeElement === first) { e.preventDefault(); last.focus(); }
    } else {
      if (document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });

  // Cart count sync (event-first)
  function readCartFromLocalStorage(){
    try {
      const raw = localStorage.getItem('dod_cart');
      if (!raw) return { count: null };
      const data = JSON.parse(raw);
      let count = 0;

      if (data && typeof data === 'object' && Array.isArray(data.items)) {
        for (const it of data.items) count += Math.max(0, parseInt(it.quantity || 0, 10));
      } else if (Array.isArray(data)) {
        for (const it of data) count += Math.max(0, parseInt(it.quantity || 0, 10));
      }
      return { count };
    } catch(e) {
      return { count: null };
    }
  }

  function renderBagCount(count){
    if (!bagWrap) return;

    if (!count || count <= 0) {
      bagWrap.innerHTML = '';
      return;
    }

    let badge = bagWrap.querySelector('.bag-count');
    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'bag-count';
      badge.id = 'bagCountJS';
      bagWrap.appendChild(badge);
    }
    badge.textContent = count;
  }

  function syncBag(){
    const { count } = readCartFromLocalStorage();
    if (typeof count === 'number' && count >= 0) renderBagCount(count);
  }

  async function fetchCartState(){
    try {
      const res = await fetch('/cart.php?action=get_cart_state&ajax=1', { credentials: 'same-origin' });
      const data = await res.json();
      if (data && data.ok && data.totals && typeof data.totals.total_qty === 'number') {
        renderBagCount(data.totals.total_qty);
      }
    } catch(e) {}
  }

  document.addEventListener('DOMContentLoaded', function() {
    setTimeout(syncBag, 100);
    fetchCartState();
  });

  window.addEventListener('storage', (e) => {
    if (e.key === 'dod_cart') setTimeout(syncBag, 10);
  });

  window.addEventListener('dod:cart:updated', syncBag);
  window.addEventListener('cartUpdated', function(e) {
    if (e.detail && typeof e.detail.count === 'number') renderBagCount(e.detail.count);
    else syncBag();
  });

  // Minimal polling (60s) + visibility/focus based
  let pollTimer = null;
  const POLL_MS = 60000;

  function startPolling(){
    if (pollTimer) return;
    pollTimer = setInterval(() => {
      if (document.visibilityState === 'visible') fetchCartState();
    }, POLL_MS);
  }
  function stopPolling(){
    if (!pollTimer) return;
    clearInterval(pollTimer);
    pollTimer = null;
  }

  if (document.visibilityState === 'visible') startPolling();

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      fetchCartState();
      startPolling();
    } else {
      stopPolling();
    }
  });

  window.addEventListener('focus', () => {
    if (document.visibilityState === 'visible') fetchCartState();
  });

  window.__DOD_HEADER_CART_REFRESH__ = fetchCartState;
})();
</script>
