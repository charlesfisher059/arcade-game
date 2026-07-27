<?php
declare(strict_types=1);

/**
 * arcade_leaderboard.php
 * Path: /home2/asqrtyte/public_html/arcade_leaderboard.php
 *
 * v3.8: global/weekly/monthly/season leaderboards, character and
 * Classic Paddle filters, personal best, season countdown. See
 * arcade_app/includes/arcade_leaderboard.php for the scoring/scoping
 * design notes.
 */

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/arcade_app/includes/arcade_schema_manager.php';
require_once __DIR__ . '/arcade_app/includes/player_authority.php';
require_once __DIR__ . '/arcade_app/includes/arcade_leaderboard.php';
require_once __DIR__ . '/arcade_app/includes/cloud_identity.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(503);
    exit('Leaderboard is temporarily unavailable.');
}
arcade_schema_ensure($pdo);

function alb_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

$scope = (string)($_GET['scope'] ?? 'alltime');
if (!in_array($scope, ['alltime', 'weekly', 'monthly', 'season'], true)) $scope = 'alltime';

$characterId = trim((string)($_GET['character'] ?? ''));
$paddleOnly = !empty($_GET['paddle']);

$catalog = arcade_authority_character_catalog();
if ($characterId !== '' && !isset($catalog[$characterId])) $characterId = '';

$season = arcade_leaderboard_active_season($pdo);
if ($scope === 'season' && !$season) $scope = 'alltime';

$filters = [];
if ($characterId !== '') $filters['character_id'] = $characterId;
if ($paddleOnly) $filters['character_form'] = 'paddle';

$rows = arcade_leaderboard_rows($pdo, $scope, $season, $filters, 50);

$customerId = arcade_authority_customer_id();
$personalBest = $customerId ? arcade_leaderboard_personal_best($pdo, $customerId, $scope, $season, $filters) : [];

$scopeLabels = [
    'alltime' => 'All-Time',
    'weekly' => 'This Week',
    'monthly' => 'This Month',
    'season' => $season ? alb_h($season['name']) : 'Season',
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Arcade Leaderboard | Diamonds Outta Dirt</title>
<style>
.leaderboard-main{max-width:960px;margin:0 auto;padding:40px 16px 100px;color:#fff;font-family:'Space Mono',monospace}
.leaderboard-account-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}
.leaderboard-account-tabs a{padding:9px 13px;border:1px solid rgba(255,255,255,.14);border-radius:8px;color:#aab5b3;text-decoration:none;font-size:.62rem}
.leaderboard-account-tabs a.active,.leaderboard-account-tabs a:hover{border-color:#00ff9d;color:#00ff9d}
.leaderboard-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
.leaderboard-actions{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0}
.leaderboard-actions a{padding:9px 13px;border:1px solid rgba(0,255,157,.3);border-radius:7px;color:#fff;text-decoration:none;font-size:.65rem}
.leaderboard-actions a:hover{border-color:#00ff9d;color:#00ff9d}
@media(max-width:620px){
  .leaderboard-main{padding-top:24px}
  th,td{padding:9px 7px;white-space:nowrap}
  .leaderboard-tabs a{flex:1 1 auto;text-align:center}
}
.leaderboard-eyebrow{color:#00ff9d;font-size:.62rem;letter-spacing:2px;text-transform:uppercase;margin-bottom:8px}
.leaderboard-main h1{margin:0 0 6px;font-size:1.6rem;letter-spacing:1px}
.leaderboard-countdown{color:#9ed8c8;font-size:.7rem;letter-spacing:1px;margin-bottom:20px}
.leaderboard-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.leaderboard-tabs a{display:inline-block;padding:8px 14px;border:1px solid rgba(0,255,157,.3);border-radius:999px;color:#ccc;text-decoration:none;font-size:.68rem;letter-spacing:1px;text-transform:uppercase}
.leaderboard-tabs a.active,.leaderboard-tabs a:hover{border-color:#00ff9d;color:#00ff9d}
.leaderboard-filters{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:20px;font-size:.68rem}
.leaderboard-filters select{background:#050607;color:#fff;border:1px solid rgba(0,255,157,.3);border-radius:6px;padding:8px;font:inherit}
.leaderboard-filters label{display:inline-flex;align-items:center;gap:6px;color:#ccc}
table{width:100%;border-collapse:collapse;font-size:.72rem}
th,td{padding:10px;border-bottom:1px solid rgba(255,255,255,.08);text-align:left}
th{color:#00f3ff;font-size:.6rem;letter-spacing:1px;text-transform:uppercase}
.tier{display:inline-block;padding:3px 9px;border-radius:999px;font-size:.58rem;letter-spacing:.5px;text-transform:uppercase}
.tier-diamond{background:rgba(0,243,255,.15);color:#7ff2ff;border:1px solid rgba(0,243,255,.4)}
.tier-gold{background:rgba(255,196,84,.15);color:#ffd98a;border:1px solid rgba(255,196,84,.4)}
.tier-silver{background:rgba(220,220,220,.12);color:#e6e6e6;border:1px solid rgba(220,220,220,.35)}
.tier-bronze{background:rgba(205,127,50,.15);color:#e0a56f;border:1px solid rgba(205,127,50,.4)}
.tier-dirt{background:rgba(140,110,80,.12);color:#c2a98a;border:1px solid rgba(140,110,80,.35)}
.leaderboard-you{background:rgba(0,255,157,.06)}
.leaderboard-player-link{color:#fff;text-decoration:none}.leaderboard-player-link:hover{color:#00ff9d;text-decoration:underline}
.leaderboard-personal{margin-top:24px;padding:14px;border:1px solid rgba(0,255,157,.25);border-radius:10px}.leaderboard-source{display:inline-block;margin-left:5px;padding:2px 6px;border:1px solid rgba(255,196,84,.35);border-radius:999px;color:#ffd98a;font-size:.5rem}
.leaderboard-empty{color:#888;padding:20px 0}
</style>
</head>
<body>
<?php
$headerFile = __DIR__ . '/partials/header.php';
if (is_file($headerFile)) require $headerFile;
?>
<main class="leaderboard-main" id="main-content">
    <?php if ($customerId): ?>
    <nav class="leaderboard-account-tabs" aria-label="Account sections">
        <a href="/account">Account Overview</a>
        <a href="/account/arcade.php">Arcade Profile</a>
        <a href="/arcade_leaderboard.php" class="active" aria-current="page">Leaderboard</a>
    </nav>
    <?php endif; ?>

    <div class="leaderboard-eyebrow">Diamonds Outta Dirt Arcade</div>
    <h1>Leaderboard</h1>
    <?php if ($season): ?>
        <div class="leaderboard-countdown" data-season-ends-ms="<?= (int)(strtotime((string)$season['ends_at']) * 1000) ?>" id="seasonCountdown">
            <?= alb_h($season['name']) ?> — ends <?= alb_h($season['ends_at']) ?>
        </div>
    <?php endif; ?>

    <div class="leaderboard-tabs">
        <?php foreach (['alltime' => 'All-Time', 'weekly' => 'This Week', 'monthly' => 'This Month'] as $key => $label): ?>
            <a href="?scope=<?= alb_h($key) ?><?= $characterId !== '' ? '&character=' . alb_h($characterId) : '' ?><?= $paddleOnly ? '&paddle=1' : '' ?>" class="<?= $scope === $key ? 'active' : '' ?>"><?= alb_h($label) ?></a>
        <?php endforeach; ?>
        <?php if ($season): ?>
            <a href="?scope=season<?= $characterId !== '' ? '&character=' . alb_h($characterId) : '' ?><?= $paddleOnly ? '&paddle=1' : '' ?>" class="<?= $scope === 'season' ? 'active' : '' ?>"><?= alb_h($season['name']) ?></a>
        <?php endif; ?>
    </div>

    <form class="leaderboard-filters" method="get">
        <input type="hidden" name="scope" value="<?= alb_h($scope) ?>">
        <select name="character" onchange="this.form.submit()">
            <option value="">All Characters</option>
            <?php foreach ($catalog as $id => $char): ?>
                <option value="<?= alb_h($id) ?>" <?= $characterId === $id ? 'selected' : '' ?>><?= alb_h($char['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <label>
            <input type="checkbox" name="paddle" value="1" <?= $paddleOnly ? 'checked' : '' ?> onchange="this.form.submit()">
            Classic Paddle Only
        </label>
    </form>

    <div class="leaderboard-actions">
        <a href="/arcade">Play Arcade</a>
        <?php if ($customerId): ?><a href="/account/arcade.php">My Arcade Profile</a><?php endif; ?>
    </div>

    <?php if (empty($rows)): ?>
        <div class="leaderboard-empty">No qualifying runs yet for this view.</div>
    <?php else: ?>
    <div class="leaderboard-table-wrap">
    <table>
        <thead><tr><th>#</th><th>Player</th><th>Tier</th><th>Character</th><th>Score</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr class="<?= ($customerId && (int)$row['customer_id'] === $customerId) ? 'leaderboard-you' : '' ?>">
                <td><?= (int)$row['rank'] ?></td>
                <td><a class="leaderboard-player-link" href="/arcade_player.php?name=<?= rawurlencode((string)$row['display_name']) ?>&return=<?= rawurlencode('/arcade_leaderboard.php?'.http_build_query($_GET)) ?>"><?= alb_h($row['display_name']) ?></a><?php if(($row['source']??'verified')==='legacy'): ?><span class="leaderboard-source">LEGACY SCORE</span><?php endif; ?></td>
                <td><span class="tier tier-<?= alb_h(strtolower($row['tier'])) ?>"><?= alb_h($row['tier']) ?></span></td>
                <td><?= alb_h($catalog[$row['character_id']]['name'] ?? $row['character_id']) ?><?= $row['character_form'] === 'paddle' ? ' (Paddle)' : '' ?></td>
                <td><?= number_format((int)$row['score']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php if ($customerId && !empty($personalBest)): ?>
    <div class="leaderboard-personal">
        <strong>Your Best (this view):</strong>
        <?= number_format((int)$personalBest['score']) ?> pts
        with <?= alb_h($catalog[$personalBest['character_id']]['name'] ?? $personalBest['character_id']) ?>
        on <?= alb_h($personalBest['submitted_at']) ?>
    </div>
    <?php elseif ($customerId): ?>
    <div class="leaderboard-personal">No qualifying run yet for this view — play a run to appear here.</div>
    <?php endif; ?>
</main>
<script>
(function(){
  var el = document.getElementById('seasonCountdown');
  if (!el) return;
  var endsAt = Number(el.getAttribute('data-season-ends-ms'));
  if (isNaN(endsAt)) return;
  var seasonLabel = el.textContent.split(' — ')[0];
  function tick(){
    var diff = endsAt - Date.now();
    if (diff <= 0) { el.textContent = seasonLabel + ' — ended'; return; }
    var d = Math.floor(diff / 86400000);
    var h = Math.floor((diff % 86400000) / 3600000);
    var m = Math.floor((diff % 3600000) / 60000);
    el.textContent = seasonLabel + ' — ends in ' + d + 'd ' + h + 'h ' + m + 'm';
  }
  tick();
  setInterval(tick, 60000);
})();
</script>
</body>
</html>
