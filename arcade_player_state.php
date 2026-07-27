<?php
declare(strict_types=1);

require __DIR__ . '/arcade_app/bootstrap.php';
require_once ARCADE_APP_PATH . '/includes/cloud_identity.php';
require_once ARCADE_APP_PATH . '/includes/arcade_schema_manager.php';
require_once ARCADE_APP_PATH . '/includes/player_authority.php';
require_once ARCADE_APP_PATH . '/includes/arcade_health.php';
require_once ARCADE_APP_PATH . '/includes/arcade_logger.php';
require_once ARCADE_APP_PATH . '/includes/arcade_leaderboard.php';
require_once ARCADE_APP_PATH . '/includes/arcade_achievements.php';
require_once ARCADE_APP_PATH . '/includes/arcade_missions_events.php';
require_once ARCADE_APP_PATH . '/includes/arcade_progression.php';
require_once ARCADE_APP_PATH . '/includes/arcade_feature_access.php';
require_once ARCADE_APP_PATH . '/includes/arcade_mastery_fashion.php';
require_once ARCADE_APP_PATH . '/includes/arcade_story_quests.php';
require_once ARCADE_APP_PATH . '/includes/arcade_workshop.php';
require_once ARCADE_APP_PATH . '/includes/arcade_donations.php';
require_once ARCADE_APP_PATH . '/includes/arcade_social.php';
require_once ARCADE_APP_PATH . '/includes/arcade_community.php';
require_once ARCADE_APP_PATH . '/includes/arcade_trust_safety.php';
require_once ARCADE_APP_PATH . '/includes/arcade_market_chat.php';
require_once ARCADE_APP_PATH . '/includes/arcade_tutorial_privacy.php';
require_once ARCADE_APP_PATH . '/includes/arcade_growth.php';
require_once ARCADE_APP_PATH . '/includes/arcade_bestiary.php';
require_once ARCADE_APP_PATH . '/includes/arcade_character_identity.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$customerId = arcade_authority_customer_id();
if (!$customerId) {
    http_response_code(401);
    echo json_encode(['success'=>false,'authenticated'=>false,'message'=>'Log in to load your arcade account.']);
    exit;
}
if (!isset($pdo) || !$pdo instanceof PDO) {
    http_response_code(503);
    echo json_encode(['success'=>false,'message'=>'Database connection unavailable.']);
    exit;
}
if (!arcade_security_guard_session($pdo,$customerId)) {
    http_response_code(401);
    echo json_encode(['success'=>false,'authenticated'=>false,'message'=>'This arcade session was signed out. Log in again.']);
    exit;
}
if (arcade_maintenance_mode_enabled($pdo)) {
    http_response_code(503);
    echo json_encode(['success'=>false,'maintenance'=>true,'message'=>arcade_maintenance_message($pdo)]);
    exit;
}

$warnings = [];
$safe = static function(string $label, callable $callback, $fallback = null) use (&$warnings) {
    try { return $callback(); }
    catch (Throwable $e) {
        $warnings[] = $label . ' is temporarily unavailable.';
        error_log('[ARCADE_PLAYER_STATE_' . strtoupper(preg_replace('/[^A-Z0-9]+/i','_',$label)) . '] ' . $e->getMessage());
        return $fallback;
    }
};

try {
    arcade_schema_ensure($pdo);
    // The core account state is required. Optional Player Hub modules below are
    // isolated so one broken panel cannot make the signed-in account look like
    // a guest or prevent Cloud Save from registering.
    $state = arcade_authority_state($pdo, $customerId);

    echo json_encode([
        'success'=>true,
        'authenticated'=>true,
        'account'=>arcade_cloud_identity($pdo),
        'state'=>$state,
        'seasonRewards'=>$safe('Season rewards', fn()=>arcade_leaderboard_player_season_rewards($pdo,$customerId), []),
        'achievements'=>$safe('Achievements', fn()=>arcade_achievements_sync($pdo,$customerId), []),
        'dailyLogin'=>$safe('Daily login', fn()=>arcade_daily_login_status($pdo,$customerId), null),
        'missions'=>$safe('Missions', fn()=>arcade_mission_player_status($pdo,$customerId), []),
        'progression'=>$safe('Progression', fn()=>arcade_progression_state($pdo,$customerId), null),
        'featureAccess'=>$safe('Feature access', fn()=>arcade_feature_access_public_config($pdo,$customerId), null),
        'masteryFashion'=>$safe('Mastery and fashion', fn()=>arcade_mastery_fashion_player_state($pdo,$customerId), null),
        'storyQuests'=>$safe('Story quests', fn()=>arcade_story_state($pdo,$customerId), null),
        'workshop'=>$safe('Workshop', fn()=>arcade_workshop_state($pdo,$customerId), null),
        'donations'=>$safe('Donations', fn()=>arcade_donation_settings($pdo), null),
        'social'=>$safe('Social profile', fn()=>arcade_social_state($pdo,$customerId), null),
        'community'=>$safe('Community', fn()=>arcade_community_state($pdo,$customerId), null),
        'trust'=>$safe('Trust and safety', fn()=>arcade_trust_player_state($pdo,$customerId), null),
        'marketChat'=>$safe('Marketplace and chat', fn()=>arcade_market_chat_full_state($pdo,$customerId), null),
        'tutorialPrivacy'=>$safe('Tutorial and privacy', fn()=>arcade_tutorial_privacy_state($pdo,$customerId), null),
        'growth'=>$safe('Growth and return loop', fn()=>arcade_growth_state($pdo,$customerId), null),
        'bestiary'=>$safe('Bestiary and Survival rank', fn()=>arcade_bestiary_state($pdo,$customerId), null),
        'characterIdentity'=>$safe('Character identity and Ghost Rivals', fn()=>arcade_character_identity_state($pdo,$customerId), null),
        'warnings'=>$warnings,
        'csrf'=>arcade_cloud_csrf_token(),
        'version'=>arcade_version_string(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[ARCADE_PLAYER_STATE_CORE] ' . $e->getMessage());
    arcade_log_event($pdo, 'error', 'load_state', $e->getMessage(), $customerId);
    http_response_code(500);
    echo json_encode([
        'success'=>false,
        'authenticated'=>true,
        'message'=>'Unable to initialize the core arcade profile. The server logged the exact error for repair.',
        'error_code'=>'core_profile_failed',
    ]);
}
