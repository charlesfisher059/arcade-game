<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cookie Policy | Diamonds Outta Dirt</title>
    <style>
        :root { --bg:#060606; --panel:#101010; --border:#252525; --text:#f4f4f4; --muted:#9b9b9b; --accent:#6bff9f; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); color:var(--text); font:13px/1.6 "Space Mono", Menlo, Consolas, monospace; text-transform:uppercase; }
        .wrap { max-width:900px; margin:34px auto; padding:0 16px; }
        .card { border:1px solid var(--border); background:var(--panel); padding:20px; }
        h1 { margin:0 0 12px; color:var(--accent); letter-spacing:2px; font-size:19px; }
        h2 { margin:20px 0 8px; color:var(--accent); letter-spacing:1.3px; font-size:13px; }
        p { margin:0 0 10px; color:var(--muted); letter-spacing:0.9px; }
        ul { margin:0 0 10px 18px; color:var(--muted); }
        a { color:var(--accent); text-decoration:none; }
        a:hover { color:#fff; }
        .links { margin-top:18px; display:flex; gap:10px; flex-wrap:wrap; }
        .btn { border:1px solid var(--border); padding:8px 10px; font-size:11px; letter-spacing:1px; }
    </style>
<?php @include_once __DIR__ . '/includes/bg_styles.php'; ?>
<script src="/js/telemetry.js" defer></script>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>COOKIE POLICY</h1>
            <p>Last updated: February 13, 2026</p>

            <h2>What We Use</h2>
            <ul>
                <li>Essential cookies: required for cart, security, and core page behavior.</li>
                <li>Analytics cookies: optional, used for site usage measurement and performance insights.</li>
                <li>Marketing cookies: optional, used for ad and campaign attribution.</li>
            </ul>

            <h2>Your Controls</h2>
            <p>You can accept or reject optional cookies from the consent banner. You can reopen settings from the homepage footer using the “Cookie Settings” button.</p>

            <h2>Data Sources</h2>
            <p>If enabled, analytics and marketing data may be processed through providers such as Google Analytics and Meta Pixel, based on your consent choice.</p>

            <h2>Contact</h2>
            <p>For privacy and data questions, contact <a href="mailto:support@diamondsouttadirt.com">support@diamondsouttadirt.com</a>.</p>

            <div class="links">
                <a class="btn" href="/">Home</a>
                <a class="btn" href="/privacy-policy">Privacy Policy</a>
                <a class="btn" href="/terms-of-service">Terms</a>
            </div>
        </div>
    </div>
</body>
</html>