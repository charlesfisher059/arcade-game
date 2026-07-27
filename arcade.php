<?php
declare(strict_types=1);

require __DIR__ . '/arcade_app/bootstrap.php';
require_once ARCADE_APP_PATH . '/includes/arcade_health.php';
require_once ARCADE_APP_PATH . '/includes/arcade_tutorial_privacy.php';

if (isset($pdo) && $pdo instanceof PDO && !empty($_SESSION['customer_id']) && !arcade_security_guard_session($pdo,(int)$_SESSION['customer_id'])) {
    header('Location: /account/login?next=%2Farcade');
    exit;
}

if (isset($pdo) && $pdo instanceof PDO && arcade_maintenance_mode_enabled($pdo)) {
    http_response_code(503);
    $message = arcade_maintenance_message($pdo);
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Arcade | Diamonds Outta Dirt</title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#000;color:#fff;font-family:'Space Mono',monospace;padding:24px;text-align:center}
.box{max-width:520px}
h1{color:#00ff9d;letter-spacing:2px;font-size:1rem;text-transform:uppercase;margin:0 0 14px}
p{color:#ccc;line-height:1.6}
a{color:#00ff9d}
</style>
</head>
<body>
<div class="box">
<h1>Arcade Temporarily Unavailable</h1>
<p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
<p><a href="/">Return Home</a></p>
</div>
</body>
</html>
<?php
    exit;
}

require ARCADE_APP_PATH . '/views/game.php';
