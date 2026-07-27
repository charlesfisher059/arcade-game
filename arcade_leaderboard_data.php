<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/arcade_app/includes/arcade_schema_manager.php';
require_once __DIR__ . '/arcade_app/includes/player_authority.php';
require_once __DIR__ . '/arcade_app/includes/arcade_leaderboard.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Leaderboard is temporarily unavailable.',
    ]);
    exit;
}

try {
    arcade_schema_ensure($pdo);

    $scope = strtolower(trim((string)($_GET['scope'] ?? 'alltime')));
    if (!in_array($scope, ['alltime', 'weekly', 'monthly', 'season'], true)) {
        $scope = 'alltime';
    }

    $characterId = trim((string)($_GET['character'] ?? ''));
    $paddleOnly = !empty($_GET['paddle']);
    $limit = max(5, min(25, (int)($_GET['limit'] ?? 10)));

    $catalog = arcade_authority_character_catalog();
    if ($characterId !== '' && !isset($catalog[$characterId])) {
        $characterId = '';
    }

    $season = arcade_leaderboard_active_season($pdo);
    if ($scope === 'season' && !$season) {
        $scope = 'alltime';
    }

    $filters = [];
    if ($characterId !== '') {
        $filters['character_id'] = $characterId;
    }
    if ($paddleOnly) {
        $filters['character_form'] = 'paddle';
    }

    $customerId = arcade_authority_customer_id();
    $rows = arcade_leaderboard_rows(
        $pdo,
        $scope,
        $season,
        $filters,
        $limit
    );

    $resultRows = [];
    foreach ($rows as $row) {
        $characterKey = (string)($row['character_id'] ?? '');
        $resultRows[] = [
            'rank' => (int)($row['rank'] ?? 0),
            'player' => (string)($row['display_name'] ?? 'PLAYER'),
            'profileUrl' => '/arcade_player.php?name=' . rawurlencode((string)($row['display_name'] ?? 'PLAYER')),
            'tier' => (string)($row['tier'] ?? 'Dirt'),
            'characterId' => $characterKey,
            'character' => (string)($catalog[$characterKey]['name'] ?? $characterKey),
            'form' => (string)($row['character_form'] ?? 'illustrated'),
            'score' => (int)($row['score'] ?? 0),
            'submittedAt' => (string)($row['submitted_at'] ?? ''),
            'source' => (string)($row['source'] ?? 'verified'),
            'isCurrentPlayer' => $customerId
                && (int)($row['customer_id'] ?? 0) === $customerId,
        ];
    }

    $personalBest = [];
    $personalRank = null;
    if ($customerId) {
        $personalBest = arcade_leaderboard_personal_best(
            $pdo,
            $customerId,
            $scope,
            $season,
            $filters
        );
        $personalRank = arcade_leaderboard_player_rank(
            $pdo,
            $customerId,
            $scope,
            $season,
            $filters
        );
    }

    $playerState = null;
    if ($customerId) {
        $playerState = arcade_authority_state($pdo, $customerId);
    }

    echo json_encode([
        'success' => true,
        'scope' => $scope,
        'authenticated' => (bool)$customerId,
        'arcadeName' => (string)($playerState['arcadeName'] ?? ''),
        'season' => $season ? [
            'id' => (int)$season['id'],
            'name' => (string)$season['name'],
            'startsAt' => (string)$season['starts_at'],
            'endsAt' => (string)$season['ends_at'],
        ] : null,
        'filters' => [
            'character' => $characterId,
            'paddle' => $paddleOnly,
        ],
        'characters' => array_map(
            static fn(array $row, string $id): array => [
                'id' => $id,
                'name' => (string)$row['name'],
            ],
            $catalog,
            array_keys($catalog)
        ),
        'rows' => $resultRows,
        'personal' => $customerId ? [
            'rank' => $personalRank,
            'score' => (int)($personalBest['score'] ?? 0),
            'characterId' => (string)($personalBest['character_id'] ?? ''),
            'character' => isset($personalBest['character_id'])
                ? (string)($catalog[(string)$personalBest['character_id']]['name']
                    ?? (string)$personalBest['character_id'])
                : '',
            'form' => (string)($personalBest['character_form'] ?? ''),
            'submittedAt' => (string)($personalBest['submitted_at'] ?? ''),
            'hasRun' => !empty($personalBest),
        ] : null,
        'fullLeaderboardUrl' => '/arcade_leaderboard.php',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[ARCADE_LEADERBOARD_DATA] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load the leaderboard right now.',
    ]);
}
