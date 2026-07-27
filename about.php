<?php
/**
 * OUR STORY — DIAMONDS OUTTA DIRT
 * Path: /home2/asqrtyte/public_html/about.php
 *
 * PATCH v2 (SITE-WIDE CONSISTENCY):
 * - Added EXCHANGE link to nav (was missing -- every other page has it)
 * - Added SIGN IN / ACCOUNT link to nav, matching account/auth.php session pattern
 * - Added /js/telemetry.js so page views + engagement here show up in analytics
 * - Added /js/search.js so the site search widget is available here too
 * - Added includes/bg_styles.php so this page respects admin-controlled
 *   backgrounds and gets the VIEW BG toggle like the rest of the site
 * - Kept existing layout, founder cards, slideshow logic, and styling intact
 */

declare(strict_types=1);

// ------------------------------------------------------------
// Detect HTTPS (robust)
// ------------------------------------------------------------
$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');

// ------------------------------------------------------------
// Security headers (safe, lightweight)
// ------------------------------------------------------------
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if ($https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// ------------------------------------------------------------
// Session bootstrap (safe + consistent)
// ------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $https, true);
    }
    session_start();
}

if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('site_base_url')) {
    function site_base_url(bool $https): string {
        $scheme = $https ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = preg_replace('/[^A-Za-z0-9\.\-:]/', '', $host);
        return $scheme . '://' . $host;
    }
}

// Optional: cart count badge (non-invasive, won't break if cart shape differs)
$cartCount = 0;
if (isset($_SESSION['cart']['items']) && is_array($_SESSION['cart']['items'])) {
    foreach ($_SESSION['cart']['items'] as $item) {
        $cartCount += max(0, (int)($item['quantity'] ?? 0));
    }
} elseif (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        if (is_array($item)) $cartCount += max(0, (int)($item['quantity'] ?? 0));
        elseif (is_numeric($item)) $cartCount += (int)$item;
    }
}

// DB connection -- needed for bg_styles.php (admin-controlled backgrounds)
// and for the logged-in customer nav state. Non-fatal if unavailable.
if (!isset($pdo) || !($pdo instanceof PDO)) {
    @include_once __DIR__ . '/db_connect.php';
}
$isCustomerLoggedIn = !empty($_SESSION['customer_id']);

$baseUrl = site_base_url($https);
$canonicalUrl = $baseUrl . '/about';
$metaTitle = 'OUR STORY // DIAMONDS OUTTA DIRT';
$metaDescription = 'Diamonds Outta Dirt is a Columbus, Ohio streetwear label founded in 2017, blending fashion design, photography, and runway storytelling built from pressure and growth.';
$ogImage = $baseUrl . '/images/og-default.jpg';

// Founding creators (replace placeholder names + video URLs with your real data)
$defaultFoundingCreators = [
    [
        'name' => 'Founder One',
        'role' => 'Creative Director',
        'quote' => 'Every piece starts as pressure -- an idea that won\'t leave me alone until it\'s real.',
        'bio' => 'Leads visual direction and collection concepts from sketch to final execution.',
        'feats' => [
            'Directed multiple runway-ready capsule drops',
            'Led campaign concept development + styling',
            'Built the signature DOD visual language',
        ],
        'video_url' => '/assets/videos/creators/founder-one.mp4',
        'video_title' => 'Founder One: Creative Direction Highlights',
        'image_url' => '/images/placeholder.jpg',
        'social_links' => [
            'instagram' => '',
            'tiktok' => '',
            'x' => '',
            'youtube' => '',
            'website' => '',
        ],
        'slideshow' => [],
        'is_active' => true,
        'position' => 0,
    ],
    [
        'name' => 'Founder Two',
        'role' => 'Design & Production Lead',
        'quote' => 'I don\'t sign off on anything until I\'d wear it myself, out the door, no second thoughts.',
        'bio' => 'Owns garment development, fit testing, and production workflow.',
        'feats' => [
            'Delivered small-batch product runs across categories',
            'Built repeatable quality-control standards',
            'Shipped custom pieces for show and retail release',
        ],
        'video_url' => '/assets/videos/creators/founder-two.mp4',
        'video_title' => 'Founder Two: Design and Production Feats',
        'image_url' => '/images/placeholder.jpg',
        'social_links' => [
            'instagram' => '',
            'tiktok' => '',
            'x' => '',
            'youtube' => '',
            'website' => '',
        ],
        'slideshow' => [],
        'is_active' => true,
        'position' => 1,
    ],
    [
        'name' => 'Founder Three',
        'role' => 'Photography & Media Director',
        'quote' => 'A good photo doesn\'t just show the clothes -- it shows why someone would want to be seen in them.',
        'bio' => 'Captures brand stories through campaign photography and visual media.',
        'feats' => [
            'Produced lookbook and campaign photo sets',
            'Shot backstage and runway documentation',
            'Built media assets for digital + event rollouts',
        ],
        'video_url' => '/assets/videos/creators/founder-three.mp4',
        'video_title' => 'Founder Three: Media and Photography Reel',
        'image_url' => '/images/placeholder.jpg',
        'social_links' => [
            'instagram' => '',
            'tiktok' => '',
            'x' => '',
            'youtube' => '',
            'website' => '',
        ],
        'slideshow' => [],
        'is_active' => true,
        'position' => 2,
    ],
    [
        'name' => 'Founder Four',
        'role' => 'Community & Operations Lead',
        'quote' => 'The brand isn\'t the clothes on a rack -- it\'s everyone who shows up when we call.',
        'bio' => 'Coordinates events, partnerships, and customer-facing execution.',
        'feats' => [
            'Helped execute 20+ fashion show activations',
            'Managed community-facing launch operations',
            'Built repeat customer and partner workflows',
        ],
        'video_url' => '/assets/videos/creators/founder-four.mp4',
        'video_title' => 'Founder Four: Operations and Event Feats',
        'image_url' => '/images/placeholder.jpg',
        'social_links' => [
            'instagram' => '',
            'tiktok' => '',
            'x' => '',
            'youtube' => '',
            'website' => '',
        ],
        'slideshow' => [],
        'is_active' => true,
        'position' => 3,
    ],
];

$aboutCreatorsFile = __DIR__ . '/admin/_data/content/about_creators.json';
$foundingCreators = $defaultFoundingCreators;

if (is_file($aboutCreatorsFile) && is_readable($aboutCreatorsFile)) {
    $rawCreators = @file_get_contents($aboutCreatorsFile);
    if (is_string($rawCreators) && $rawCreators !== '') {
        $parsedCreators = json_decode($rawCreators, true);
        if (is_array($parsedCreators)) {
            $normalized = [];
            foreach ($parsedCreators as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $name = trim((string)($entry['name'] ?? ''));
                $role = trim((string)($entry['role'] ?? ''));
                $bio = trim((string)($entry['bio'] ?? ''));
                $quote = trim((string)($entry['quote'] ?? ''));
                $videoUrl = trim((string)($entry['video_url'] ?? ''));
                $videoTitle = trim((string)($entry['video_title'] ?? ''));
                $imageUrl = trim((string)($entry['image_url'] ?? '/images/placeholder.jpg'));
                $isActive = !isset($entry['is_active']) || (bool)$entry['is_active'];
                $position = isset($entry['position']) ? (int)$entry['position'] : count($normalized);
                $socialLinks = isset($entry['social_links']) && is_array($entry['social_links']) ? $entry['social_links'] : [];
                $slideshow = isset($entry['slideshow']) && is_array($entry['slideshow']) ? $entry['slideshow'] : [];
                $feats = [];
                if (isset($entry['feats']) && is_array($entry['feats'])) {
                    foreach ($entry['feats'] as $feat) {
                        $featText = trim((string)$feat);
                        if ($featText !== '') {
                            $feats[] = $featText;
                        }
                    }
                }

                $normalized[] = [
                    'name' => $name !== '' ? $name : 'Founder',
                    'role' => $role !== '' ? $role : 'Founding Member',
                    'bio' => $bio,
                    'quote' => $quote,
                    'feats' => $feats,
                    'video_url' => $videoUrl,
                    'video_title' => $videoTitle !== '' ? $videoTitle : 'Creator video',
                    'image_url' => $imageUrl !== '' ? $imageUrl : '/images/placeholder.jpg',
                    'social_links' => [
                        'instagram' => trim((string)($socialLinks['instagram'] ?? '')),
                        'tiktok' => trim((string)($socialLinks['tiktok'] ?? '')),
                        'x' => trim((string)($socialLinks['x'] ?? '')),
                        'youtube' => trim((string)($socialLinks['youtube'] ?? '')),
                        'website' => trim((string)($socialLinks['website'] ?? '')),
                    ],
                    'slideshow' => array_values(array_filter(array_map(static function ($v): string {
                        return trim((string)$v);
                    }, $slideshow), static function (string $v): bool {
                        return $v !== '';
                    })),
                    'is_active' => $isActive,
                    'position' => $position,
                ];
            }

            if (count($normalized) > 0) {
                usort($normalized, static function (array $a, array $b): int {
                    return ((int)($a['position'] ?? 0)) <=> ((int)($b['position'] ?? 0));
                });
                $active = array_values(array_filter($normalized, static function (array $c): bool {
                    return !isset($c['is_active']) || (bool)$c['is_active'];
                }));
                $source = count($active) > 0 ? $active : $normalized;
                $foundingCreators = array_slice($source, 0, 4);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($metaTitle) ?></title>
<?php if (is_file(__DIR__ . '/includes/tracking_pixels.php')) { require_once __DIR__ . '/includes/tracking_pixels.php'; } ?>
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#000000">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="DOD">
<script src="/js/pwa-init.js" defer></script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="<?= h($metaDescription) ?>">
<link rel="canonical" href="<?= h($canonicalUrl) ?>">
<meta property="og:title" content="<?= h($metaTitle) ?>">
<meta property="og:description" content="<?= h($metaDescription) ?>">
<meta property="og:type" content="website">
<meta property="og:url" content="<?= h($canonicalUrl) ?>">
<meta property="og:image" content="<?= h($ogImage) ?>">
<meta property="og:site_name" content="DIAMONDS OUTTA DIRT">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= h($metaTitle) ?>">
<meta name="twitter:description" content="<?= h($metaDescription) ?>">
<meta name="twitter:image" content="<?= h($ogImage) ?>">
<link rel="stylesheet" href="/css/master.css">
<script src="/js/telemetry.js" defer></script>
<script src="/js/search.js" defer></script>
<style>
.founders-section {
  margin-top: 72px;
}
.hook-line {
  font-family: 'Syncopate', sans-serif;
  font-size: clamp(1.3rem, 3vw, 2rem);
  letter-spacing: 2px;
  line-height: 1.3;
  color: #fff;
  margin: 0 0 40px;
  max-width: 18ch;
}
.hook-line em {
  font-style: normal;
  color: var(--neon);
}
.proof-strip {
  margin-top: 50px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: #0b0b0b;
  padding: 26px;
}
.proof-strip-kicker {
  color: var(--neon);
  font-size: 0.68rem;
  letter-spacing: 2px;
  margin-bottom: 6px;
}
.proof-strip-title {
  font-size: 1.1rem;
  letter-spacing: 1px;
  margin: 0 0 18px;
  color: #fff;
}
.proof-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 14px;
}
.proof-card {
  border: 1px solid var(--border);
  border-radius: 8px;
  aspect-ratio: 4 / 5;
  background: #050505;
  background-size: cover;
  background-position: center;
  position: relative;
  overflow: hidden;
}
.proof-card-label {
  position: absolute;
  left: 0; right: 0; bottom: 0;
  padding: 10px;
  background: linear-gradient(to top, rgba(0,0,0,0.88), transparent);
  font-size: 0.62rem;
  letter-spacing: 1px;
  color: #fff;
}
.proof-note {
  margin-top: 14px;
  font-size: 0.68rem;
  color: #666;
  letter-spacing: 0.5px;
}
.mid-cta {
  margin: 60px 0;
  padding: 32px;
  border: 1px solid var(--neon);
  border-radius: 10px;
  background: linear-gradient(135deg, rgba(0,255,157,0.08), rgba(0,0,0,0.4));
  text-align: center;
}
.mid-cta p {
  margin: 0 0 16px;
  color: #ddd;
  font-size: 0.95rem;
  letter-spacing: 0.5px;
}
.mid-cta a {
  display: inline-block;
  padding: 13px 28px;
  background: var(--neon);
  color: #000;
  text-decoration: none;
  font-weight: 700;
  letter-spacing: 2px;
  font-size: 0.75rem;
  border-radius: 4px;
  text-transform: uppercase;
}
.mid-cta a:hover {
  filter: brightness(1.08);
}
.founder-quote {
  margin: 0 0 12px;
  padding: 10px 14px;
  border-left: 2px solid var(--neon);
  background: rgba(0,255,157,0.04);
  font-size: 0.82rem;
  font-style: italic;
  color: #cfcfcf;
  line-height: 1.6;
}
.founders-kicker {
  color: var(--neon);
  letter-spacing: 2px;
  font-size: 0.7rem;
  margin-bottom: 10px;
}
.founders-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 20px;
}
.founder-card {
  background: #0b0b0b;
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 18px;
}
.founder-head {
  margin-bottom: 10px;
}
.founder-image {
  width: 100%;
  aspect-ratio: 1 / 1;
  object-fit: cover;
  border: 1px solid var(--border);
  border-radius: 8px;
  margin-bottom: 12px;
  background: #050505;
}
.founder-name {
  margin: 0;
  font-size: 1rem;
  letter-spacing: 1px;
}
.founder-role {
  margin: 6px 0 0;
  color: var(--neon);
  font-size: 0.75rem;
  letter-spacing: 2px;
  text-transform: uppercase;
}
.founder-bio {
  margin: 0 0 12px;
  color: #bcbcbc;
  line-height: 1.6;
  font-size: 0.88rem;
}
.founder-feats-title {
  margin: 0 0 8px;
  font-size: 0.75rem;
  letter-spacing: 2px;
  color: var(--neon);
}
.founder-feats {
  margin: 0 0 14px;
  padding-left: 18px;
  color: #cfcfcf;
  line-height: 1.6;
}
.founder-feats li {
  margin-bottom: 6px;
}
.founder-socials {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: 8px 0 14px;
}
.founder-social-link {
  border: 1px solid var(--border);
  border-radius: 4px;
  padding: 5px 8px;
  font-size: 0.6rem;
  letter-spacing: 1px;
  text-decoration: none;
  color: #ddd;
}
.founder-social-link:hover {
  border-color: var(--neon);
  color: var(--neon);
}
.founder-video {
  border: 1px solid var(--border);
  border-radius: 8px;
  overflow: hidden;
  background: #000;
}
.founder-video video,
.founder-video iframe {
  width: 100%;
  aspect-ratio: 16 / 9;
  display: block;
  border: 0;
}
.founder-slideshow {
  border: 1px solid var(--border);
  border-radius: 8px;
  overflow: hidden;
  background: #000;
  margin-bottom: 14px;
}
.founder-slide {
  display: none;
}
.founder-slide.active {
  display: block;
}
.founder-slide img,
.founder-slide video,
.founder-slide iframe {
  width: 100%;
  aspect-ratio: 16 / 9;
  object-fit: cover;
  display: block;
  border: 0;
}
.founder-slideshow-controls {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  padding: 8px 10px;
  border-top: 1px solid var(--border);
  background: #060606;
}
.founder-slide-btn {
  border: 1px solid var(--border);
  border-radius: 4px;
  background: transparent;
  color: #ddd;
  padding: 4px 8px;
  cursor: pointer;
  font-size: 0.65rem;
}
.founder-slide-btn:hover {
  border-color: var(--neon);
  color: var(--neon);
}
.founder-slide-pos {
  font-size: 0.62rem;
  color: #999;
}
@media (max-width: 860px) {
  .founders-grid {
    grid-template-columns: 1fr;
  }
}
.brand-link {
  display: flex;
  align-items: center;
  gap: 8px;
  text-decoration: none;
}
.brand-logo-img {
  height: 30px;
  width: auto;
  display: block;
  flex-shrink: 0;
}
.brand-text {
  color: inherit;
}
@media (max-width: 480px) {
  .brand-text { display: none; }
}
</style>
<?php @include_once __DIR__ . '/includes/bg_styles.php'; ?>
</head>

<body class="tech-archive about-page">

<a href="#main-content" class="skip-link">SKIP TO MAIN CONTENT</a>

<header class="about-nav" role="banner">
  <div class="bar">
    <div class="brand">
      <a href="/" class="brand-link">
        <img src="/images/logo-header.png" alt="Diamonds Outta Dirt" class="brand-logo-img">
        <span class="status-dot" aria-hidden="true"></span>
        <span class="brand-text"><?= h('DIAMONDS OUTTA DIRT') ?></span>
      </a>
    </div>
    <button class="about-nav-toggle" type="button" aria-expanded="false" aria-controls="aboutNavLinks" aria-label="Toggle navigation">MENU</button>
    <nav class="links" id="aboutNavLinks" aria-label="Site navigation">
      <a href="/" class="">HOME</a>
      <a href="/shop" class="">SHOP</a>
      <a href="/exchange" class="">EXCHANGE</a>
      <a href="/casting" class="">CASTING</a>
      <a href="/arcade" class="">ARCADE</a>
      <a href="/about" class="active" aria-current="page">ABOUT</a>
      <?php if ($isCustomerLoggedIn): ?>
        <a href="/account" class="">ACCOUNT</a>
      <?php else: ?>
        <a href="/account/login" class="">SIGN IN</a>
      <?php endif; ?>
      <a href="/cart" class="">BAG [<?= (int)$cartCount ?>]</a>
    </nav>
  </div>
</header>

<main class="about-wrapper" id="main-content" tabindex="-1">

  <p class="hook-line"><em>Pressure creates purpose.</em> This is what happens when you dig deeper.</p>

  <h1>OUR STORY</h1>
  <div class="sub">BUILT FROM THE GROUND UP</div>

  <div class="story-block">
    Diamonds Outta Dirt was founded in <strong>2017</strong> — not as a brand, but as an idea.
    A concept rooted in growth, pressure, and transformation.
    What started as a logo turned into a T-shirt, and that T-shirt became the foundation of something much bigger.
  </div>

  <div class="story-block">
    From the beginning, Diamonds Outta Dirt was never meant to stay confined to one lane.
    As the vision expanded, so did the craft — evolving into fashion design, photography,
    and graphic design, all blended into custom pieces that exist beyond trends.
  </div>

  <div class="story-block">
    Every garment carries intention. Every collection tells a chapter.
    Our work reflects the environments we came from and the spaces we've grown into.
  </div>

  <blockquote class="quote">
    "Diamonds Outta Dirt isn't just clothing — it's the reminder that pressure creates purpose,
    and growth only happens when you're willing to dig deeper."
  </blockquote>

  <div class="story-block">
    Over the years, Diamonds Outta Dirt has been featured in <strong>20+ fashion shows across Columbus, Ohio</strong>,
    presenting runway pieces that merge streetwear with conceptual design.
    Our collections are made for <strong>men, women, and kids</strong> — because growth has no age limit.
  </div>

  <div class="proof-strip" aria-label="Runway and show history">
    <p class="proof-strip-kicker">ON THE RUNWAY</p>
    <h2 class="proof-strip-title">20+ Shows. One Vision.</h2>
    <div class="proof-grid">
      <div class="proof-card" style="background-image:url('/images/about/runway-1.jpg')"><span class="proof-card-label">RUNWAY 2023</span></div>
      <div class="proof-card" style="background-image:url('/images/about/backstage-1.jpg')"><span class="proof-card-label">BACKSTAGE</span></div>
      <div class="proof-card" style="background-image:url('/images/about/capsule-1.jpg')"><span class="proof-card-label">CAPSULE DROP</span></div>
      <div class="proof-card" style="background-image:url('/images/about/collection-1.jpg')"><span class="proof-card-label">FULL COLLECTION</span></div>
    </div>
  </div>

  <h2 style="margin-top:60px;letter-spacing:3px;font-size:1rem;">MILESTONES</h2>
  <div class="milestones" aria-label="Brand milestones">
    <div class="milestone">
      <span>2017</span>
      <h3>THE CONCEPT</h3>
      <p>A logo. A vision. The beginning of Diamonds Outta Dirt.</p>
    </div>

    <div class="milestone">
      <span>EVOLUTION</span>
      <h3>FROM TEES TO DESIGN</h3>
      <p>Expansion into fashion design, photography, and graphic storytelling.</p>
    </div>

    <div class="milestone">
      <span>RUNWAY</span>
      <h3>20+ SHOWS</h3>
      <p>Featured in fashion shows across Ohio, presenting custom and conceptual pieces.</p>
    </div>

    <div class="milestone">
      <span>MISSION</span>
      <h3>DIG DEEPER</h3>
      <p>
        Diamonds Outta Dirt represents growth from pressure —
        becoming more than potential and reaching for what's beyond it.
      </p>
    </div>
  </div>

  <div class="mid-cta">
    <p>Like what you're seeing? The full collection is one click away.</p>
    <a href="/shop">Shop The Story</a>
  </div>

  <section class="founders-section" aria-label="Founding creators">
    <h2 style="margin:0 0 8px;letter-spacing:3px;font-size:1rem;">FOUNDING CREATORS</h2>
    <p class="founders-kicker">FOUR FOUNDERS // BUILT FROM VISION, PRESSURE, AND EXECUTION</p>

    <div class="founders-grid">
      <?php foreach ($foundingCreators as $creator): ?>
        <?php
          $videoUrl = trim((string)($creator['video_url'] ?? ''));
          $isYoutube = (bool)preg_match('#(youtube\.com|youtu\.be)#i', $videoUrl);
          $youtubeEmbedUrl = $videoUrl;
          if ($isYoutube) {
              if (preg_match('#youtu\.be/([A-Za-z0-9_-]{6,})#i', $videoUrl, $m)) {
                  $youtubeEmbedUrl = 'https://www.youtube.com/embed/' . $m[1];
              } elseif (preg_match('#[?&]v=([A-Za-z0-9_-]{6,})#i', $videoUrl, $m)) {
                  $youtubeEmbedUrl = 'https://www.youtube.com/embed/' . $m[1];
              }
          }
        ?>
        <article class="founder-card">
          <img class="founder-image"
               src="<?= h((string)($creator['image_url'] ?? '/images/placeholder.jpg')) ?>"
               alt="<?= h((string)$creator['name']) ?>"
               loading="lazy"
               onerror="this.onerror=null;this.src='/images/placeholder.jpg';">
          <header class="founder-head">
            <h3 class="founder-name"><?= h((string)$creator['name']) ?></h3>
            <p class="founder-role"><?= h((string)$creator['role']) ?></p>
          </header>

          <?php $creatorQuote = trim((string)($creator['quote'] ?? '')); ?>
          <?php if ($creatorQuote !== ''): ?>
            <p class="founder-quote">&ldquo;<?= h($creatorQuote) ?>&rdquo;</p>
          <?php endif; ?>
          <p class="founder-bio"><?= h((string)$creator['bio']) ?></p>

          <p class="founder-feats-title">FEATURED FEATS</p>
          <ul class="founder-feats">
            <?php foreach (($creator['feats'] ?? []) as $feat): ?>
              <li><?= h((string)$feat) ?></li>
            <?php endforeach; ?>
          </ul>

          <?php
            $socialLinks = isset($creator['social_links']) && is_array($creator['social_links']) ? $creator['social_links'] : [];
            $socialMap = [
              'instagram' => 'INSTAGRAM',
              'tiktok' => 'TIKTOK',
              'x' => 'X',
              'youtube' => 'YOUTUBE',
              'website' => 'SITE',
            ];
            $socialOut = [];
            foreach ($socialMap as $key => $label) {
              $url = trim((string)($socialLinks[$key] ?? ''));
              if ($url !== '') {
                $socialOut[] = ['label' => $label, 'url' => $url];
              }
            }
            $slideshowItems = isset($creator['slideshow']) && is_array($creator['slideshow']) ? $creator['slideshow'] : [];
          ?>

          <?php if (!empty($socialOut)): ?>
            <div class="founder-socials" aria-label="Creator social links">
              <?php foreach ($socialOut as $social): ?>
                <a class="founder-social-link" href="<?= h((string)$social['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h((string)$social['label']) ?></a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if (!empty($slideshowItems)): ?>
            <div class="founder-slideshow" data-slideshow>
              <?php foreach ($slideshowItems as $slideIndex => $slide): ?>
                <?php
                  $slideUrl = trim((string)$slide);
                  $slideIsYoutube = (bool)preg_match('#(youtube\.com|youtu\.be)#i', $slideUrl);
                  $slideEmbed = $slideUrl;
                  if ($slideIsYoutube) {
                      if (preg_match('#youtu\.be/([A-Za-z0-9_-]{6,})#i', $slideUrl, $m)) {
                          $slideEmbed = 'https://www.youtube.com/embed/' . $m[1];
                      } elseif (preg_match('#[?&]v=([A-Za-z0-9_-]{6,})#i', $slideUrl, $m)) {
                          $slideEmbed = 'https://www.youtube.com/embed/' . $m[1];
                      }
                  }
                  $slideIsVideoFile = !$slideIsYoutube && (bool)preg_match('#\.(mp4|webm|mov|m4v)(\?.*)?$#i', $slideUrl);
                ?>
                <div class="founder-slide<?= $slideIndex === 0 ? ' active' : '' ?>" data-slide>
                  <?php if ($slideIsYoutube): ?>
                    <iframe src="<?= h($slideEmbed) ?>" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>
                  <?php elseif ($slideIsVideoFile): ?>
                    <video controls preload="metadata" playsinline>
                      <source src="<?= h($slideUrl) ?>" type="video/mp4">
                    </video>
                  <?php else: ?>
                    <img src="<?= h($slideUrl) ?>" loading="lazy" alt="<?= h((string)$creator['name']) ?> slideshow media" onerror="this.onerror=null;this.src='/images/placeholder.jpg';">
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
              <div class="founder-slideshow-controls">
                <button type="button" class="founder-slide-btn" data-slide-prev>PREV</button>
                <span class="founder-slide-pos" data-slide-pos>1 / <?= count($slideshowItems) ?></span>
                <button type="button" class="founder-slide-btn" data-slide-next>NEXT</button>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($videoUrl !== ''): ?>
            <div class="founder-video">
              <?php if ($isYoutube): ?>
                <iframe
                  src="<?= h($youtubeEmbedUrl) ?>"
                  title="<?= h((string)($creator['video_title'] ?? 'Creator video')) ?>"
                  loading="lazy"
                  allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                  allowfullscreen></iframe>
              <?php else: ?>
                <video controls preload="metadata" playsinline aria-label="<?= h((string)($creator['video_title'] ?? 'Creator video')) ?>">
                  <source src="<?= h($videoUrl) ?>" type="video/mp4">
                </video>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="about-cta" aria-label="Next steps">
    <a class="primary" href="/shop">Shop Collection</a>
    <a href="/lookbook">View Lookbook</a>
  </section>

</main>

<script>
(() => {
  const toggle = document.querySelector('.about-nav-toggle');
  const nav = document.getElementById('aboutNavLinks');
  if (!toggle || !nav) return;

  const mobileQuery = window.matchMedia('(max-width: 768px)');

  const closeNav = () => {
    nav.classList.remove('open');
    toggle.setAttribute('aria-expanded', 'false');
  };

  toggle.addEventListener('click', () => {
    const willOpen = !nav.classList.contains('open');
    nav.classList.toggle('open', willOpen);
    toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
  });

  nav.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => {
      if (mobileQuery.matches) closeNav();
    });
  });

  document.addEventListener('click', (e) => {
    if (!mobileQuery.matches || !nav.classList.contains('open')) return;
    if (nav.contains(e.target) || toggle.contains(e.target)) return;
    closeNav();
  });

  window.addEventListener('resize', () => {
    if (!mobileQuery.matches) closeNav();
  }, { passive: true });
})();

(() => {
  const slideshows = document.querySelectorAll('[data-slideshow]');
  slideshows.forEach((slideshow) => {
    const slides = Array.from(slideshow.querySelectorAll('[data-slide]'));
    if (slides.length <= 1) {
      return;
    }
    let index = 0;
    const pos = slideshow.querySelector('[data-slide-pos]');
    const prev = slideshow.querySelector('[data-slide-prev]');
    const next = slideshow.querySelector('[data-slide-next]');

    const render = () => {
      slides.forEach((slide, i) => slide.classList.toggle('active', i === index));
      if (pos) pos.textContent = `${index + 1} / ${slides.length}`;
    };

    if (prev) {
      prev.addEventListener('click', () => {
        index = (index - 1 + slides.length) % slides.length;
        render();
      });
    }
    if (next) {
      next.addEventListener('click', () => {
        index = (index + 1) % slides.length;
        render();
      });
    }

    setInterval(() => {
      index = (index + 1) % slides.length;
      render();
    }, 5500);
  });
})();
</script>

<?php
// Keep footer include if you want consistent footer across pages.
// If footer is also wrong, we can localize it the same way.
@include __DIR__ . '/partials/footer.php';
?>

    <script src="/js/cookie-consent-global.js" defer></script>
</body>
</html>