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
require_once ARCADE_APP_PATH . '/includes/arcade_balance.php';
require_once ARCADE_APP_PATH . '/includes/arcade_missions_events.php';
require_once ARCADE_APP_PATH . '/includes/arcade_progression.php';
require_once ARCADE_APP_PATH . '/includes/arcade_feature_access.php';
require_once ARCADE_APP_PATH . '/includes/arcade_mastery_fashion.php';
require_once ARCADE_APP_PATH . '/includes/arcade_diagnostics.php';
require_once ARCADE_APP_PATH . '/includes/arcade_effects.php';
require_once ARCADE_APP_PATH . '/includes/arcade_runway.php';
require_once ARCADE_APP_PATH . '/includes/arcade_gauntlet.php';
require_once ARCADE_APP_PATH . '/includes/arcade_story_quests.php';
require_once ARCADE_APP_PATH . '/includes/arcade_workshop.php';
require_once ARCADE_APP_PATH . '/includes/arcade_social.php';
require_once ARCADE_APP_PATH . '/includes/arcade_community.php';
require_once ARCADE_APP_PATH . '/includes/arcade_trust_safety.php';
require_once ARCADE_APP_PATH . '/includes/arcade_market_chat.php';
require_once ARCADE_APP_PATH . '/includes/arcade_tutorial_privacy.php';
require_once ARCADE_APP_PATH . '/includes/arcade_growth.php';
require_once ARCADE_APP_PATH . '/includes/arcade_bestiary.php';
require_once ARCADE_APP_PATH . '/includes/arcade_character_identity.php';
require_once ARCADE_APP_PATH . '/includes/arcade_upgrade_lab.php';
require_once ARCADE_APP_PATH . '/includes/arcade_gauntlet_collection.php';
require_once __DIR__ . '/arcade_app/includes/arcade_bosses.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'POST required.']);
    exit;
}

$customerId = arcade_authority_customer_id();
if (!$customerId) {
    http_response_code(401);
    echo json_encode(['success'=>false,'authenticated'=>false,'message'=>'Log in to change arcade progress.']);
    exit;
}

$csrf = (string)($_SERVER['HTTP_X_ARCADE_CSRF'] ?? '');
if ($csrf === '' || !hash_equals(arcade_cloud_csrf_token(), $csrf)) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Invalid security token. Refresh and try again.']);
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

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 65536) {
    http_response_code(413);
    echo json_encode(['success'=>false,'message'=>'Request payload is too large.']);
    exit;
}

try {
    $input = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new InvalidArgumentException('Invalid request.');
    $action = trim((string)($input['action'] ?? ''));
    $transactionId = trim((string)($input['transactionId'] ?? ''));
    if (!arcade_authority_valid_transaction_id($transactionId)) {
        throw new InvalidArgumentException('Invalid transaction ID.');
    }

    arcade_schema_ensure($pdo);
    arcade_authority_ensure_player($pdo, $customerId, 'customer_id:' . $customerId);
    arcade_trust_assert_action_allowed($pdo, $customerId, $action, $input);

    // v4.6.20.3: Player Hub and event access is controlled from the
    // server-backed Feature Unlock Manager. The separate Runtime Stability
    // switches remain emergency kill switches and take precedence when off.
    $accessFeature = null;
    $runtimeFeature = null;

    if (in_array($action, ['purchase_wearable','purchase_wearable_bundle'], true)) {
        $accessFeature = 'wearables';
        $runtimeFeature = 'wearableShop';
    } elseif (in_array($action, ['purchase_effect','equip_effect'], true)) {
        $accessFeature = 'effects';
    } elseif ($action === 'claim_story_quest') {
        $accessFeature = 'quests';
    } elseif (in_array($action, ['craft_workshop_item','equip_workshop_item','unequip_workshop_slot'], true)) {
        $accessFeature = 'crafting';
    } elseif (in_array($action, ['claim_mission','claim_mission_bonus'], true)) {
        $accessFeature = 'missions';
    } elseif (str_starts_with($action, 'community_guild_')) {
        $accessFeature = 'community';
        $runtimeFeature = 'guilds';
    } elseif ($action === 'community_world_boss_claim') {
        $accessFeature = 'worldboss';
        $runtimeFeature = 'worldBoss';
    } elseif (str_starts_with($action, 'chat_')) {
        $accessFeature = 'chat';
        $runtimeFeature = 'chat';
    } elseif (str_starts_with($action, 'market_listing_')) {
        $accessFeature = 'marketplace';
        $runtimeFeature = 'marketplace';
    } elseif ($action === 'claim_bestiary_hunt') {
        $accessFeature = 'bestiary';
    } elseif ($action === 'submit_run' && (string)($input['mode'] ?? '') === 'boss-rush') {
        $accessFeature = 'bossrush';
        $runtimeFeature = 'bossRush';
    } elseif (in_array($action, ['set_powerup_loadout','upgrade_powerup'], true)) {
        $accessFeature = 'upgrades';
        $runtimeFeature = 'powerups';
    } elseif ($action === 'upgrade_lab_purchase') {
        $accessFeature = 'upgrades';
    }

    if ($accessFeature !== null) {
        arcade_feature_access_assert($pdo, $customerId, $accessFeature);
    }

    $runtimeFeatures = arcade_diagnostics_settings($pdo)['features'] ?? [];
    if ($runtimeFeature !== null && empty($runtimeFeatures[$runtimeFeature])) {
        throw new DomainException('That arcade feature is temporarily disabled for maintenance.');
    }


    $duplicateState = static function () use ($pdo, $customerId): array {
        return arcade_authority_state($pdo, $customerId);
    };

    if ($action === 'update_arcade_name') {
        $requestedName = (string)($input['arcadeName'] ?? '');

        $pdo->beginTransaction();
        try {
            $dup = $pdo->prepare(
                "SELECT id FROM arcade_player_transactions
                 WHERE customer_id = ? AND transaction_uuid = ? LIMIT 1"
            );
            $dup->execute([$customerId, $transactionId]);
            if ($dup->fetchColumn()) {
                $pdo->commit();
                echo json_encode([
                    'success'=>true,
                    'duplicate'=>true,
                    'state'=>$duplicateState(),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                exit;
            }

            $oldStmt = $pdo->prepare(
                "SELECT arcade_name FROM arcade_player_accounts
                 WHERE customer_id = ? FOR UPDATE"
            );
            $oldStmt->execute([$customerId]);
            $oldName = (string)($oldStmt->fetchColumn() ?: '');

            $newName = arcade_authority_update_arcade_name(
                $pdo,
                $customerId,
                $requestedName
            );

            $pdo->prepare(
                "INSERT INTO arcade_player_transactions
                 (customer_id,transaction_uuid,event_type,metadata_json)
                 VALUES (?,?,'arcade_name_update',?)"
            )->execute([
                $customerId,
                $transactionId,
                json_encode(
                    ['oldName'=>$oldName,'newName'=>$newName],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                )
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        echo json_encode([
            'success'=>true,
            'message'=>'Arcade Name updated.',
            'state'=>$duplicateState(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'purchase_character') {
        $characterId = trim((string)($input['characterId'] ?? ''));
        $catalog = arcade_authority_character_catalog();
        if (!isset($catalog[$characterId])) throw new InvalidArgumentException('Unknown character.');
        $character = $catalog[$characterId];
        $diamondCost = max(0, (int)($character['diamondCost'] ?? 0));
        $shardCost = max(0, (int)($character['cost'] ?? 0));
        $defaultOutfit = $characterId . '_default';

        $pdo->beginTransaction();
        try {
            $dup = $pdo->prepare(
                "SELECT id FROM arcade_player_transactions
                 WHERE customer_id = ? AND transaction_uuid = ? LIMIT 1"
            );
            $dup->execute([$customerId, $transactionId]);
            if ($dup->fetchColumn()) {
                $pdo->commit();
                echo json_encode(['success'=>true,'duplicate'=>true,'state'=>$duplicateState()]);
                exit;
            }

            $accountStmt = $pdo->prepare(
                "SELECT banked_shards, lifetime_shards, diamonds
                 FROM arcade_player_accounts WHERE customer_id = ? FOR UPDATE"
            );
            $accountStmt->execute([$customerId]);
            $account = $accountStmt->fetch(PDO::FETCH_ASSOC);
            if (!$account) throw new RuntimeException('Player account unavailable.');

            $ownedStmt = $pdo->prepare(
                "SELECT 1 FROM arcade_player_characters
                 WHERE customer_id = ? AND character_id = ? LIMIT 1"
            );
            $ownedStmt->execute([$customerId, $characterId]);
            if ($ownedStmt->fetchColumn()) {
                $loadout = $pdo->prepare(
                    "SELECT outfit_id FROM arcade_player_character_loadouts
                     WHERE customer_id=? AND character_id=? LIMIT 1"
                );
                $loadout->execute([$customerId,$characterId]);
                $equippedOutfit = (string)($loadout->fetchColumn() ?: $defaultOutfit);
                $pdo->prepare(
                    "UPDATE arcade_player_accounts
                     SET equipped_character = ?, equipped_outfit = ?, equipped_form = 'illustrated', state_version = state_version + 1
                     WHERE customer_id = ?"
                )->execute([$characterId,$equippedOutfit,$customerId]);
                $pdo->prepare(
                    "INSERT INTO arcade_player_transactions
                     (customer_id, transaction_uuid, event_type, character_id, outfit_id, metadata_json)
                     VALUES (?, ?, 'equip_existing', ?, ?, ?)"
                )->execute([$customerId,$transactionId,$characterId,$equippedOutfit,json_encode(['alreadyOwned'=>true])]);
                $pdo->commit();
                echo json_encode(['success'=>true,'alreadyOwned'=>true,'state'=>$duplicateState()]);
                exit;
            }

            if ($diamondCost > 0) {
                if ((int)$account['diamonds'] < $diamondCost) {
                    throw new DomainException('Not enough Diamonds.');
                }
                $pdo->prepare(
                    "UPDATE arcade_player_accounts
                     SET diamonds = diamonds - ?, equipped_character = ?, equipped_outfit = ?,
                         equipped_form = 'illustrated', state_version = state_version + 1
                     WHERE customer_id = ?"
                )->execute([$diamondCost,$characterId,$defaultOutfit,$customerId]);
                $pdo->prepare(
                    "INSERT INTO arcade_player_characters
                     (customer_id, character_id, purchased_cost, purchased_diamonds, purchased_at)
                     VALUES (?, ?, 0, ?, CURRENT_TIMESTAMP)"
                )->execute([$customerId,$characterId,$diamondCost]);
                $pdo->prepare(
                    "INSERT INTO arcade_player_premium_items
                     (customer_id,item_type,item_key,character_id,purchased_diamonds,source,transaction_uuid)
                     VALUES (?, 'character', ?, ?, ?, 'diamond_purchase', ?)"
                )->execute([$customerId,$characterId,$characterId,$diamondCost,$transactionId]);
                $pdo->prepare(
                    "INSERT INTO arcade_player_transactions
                     (customer_id,transaction_uuid,event_type,character_id,diamond_delta,metadata_json)
                     VALUES (?,?,'premium_character_purchase',?,?,?)"
                )->execute([
                    $customerId,$transactionId,$characterId,-$diamondCost,
                    json_encode(['name'=>$character['name'],'currency'=>'diamonds'],JSON_UNESCAPED_SLASHES)
                ]);
            } else {
                if ((int)$account['lifetime_shards'] < (int)($character['unlock'] ?? 0)) {
                    throw new DomainException('Lifetime Shard requirement has not been reached.');
                }
                if ((int)$account['banked_shards'] < $shardCost) {
                    throw new DomainException('Not enough Banked Shards.');
                }
                $pdo->prepare(
                    "UPDATE arcade_player_accounts
                     SET banked_shards = banked_shards - ?, equipped_character = ?, equipped_outfit = ?,
                         equipped_form = 'illustrated', state_version = state_version + 1
                     WHERE customer_id = ?"
                )->execute([$shardCost,$characterId,$defaultOutfit,$customerId]);
                $pdo->prepare(
                    "INSERT INTO arcade_player_characters
                     (customer_id, character_id, purchased_cost, purchased_diamonds, purchased_at)
                     VALUES (?, ?, ?, 0, CURRENT_TIMESTAMP)"
                )->execute([$customerId,$characterId,$shardCost]);
                $pdo->prepare(
                    "INSERT INTO arcade_player_transactions
                     (customer_id, transaction_uuid, event_type, character_id, shard_delta, metadata_json)
                     VALUES (?, ?, 'character_purchase', ?, ?, ?)"
                )->execute([
                    $customerId,$transactionId,$characterId,-$shardCost,
                    json_encode(['name'=>$character['name'],'unlock'=>(int)($character['unlock'] ?? 0)],JSON_UNESCAPED_SLASHES)
                ]);
            }

            $pdo->prepare(
                "INSERT INTO arcade_player_character_loadouts (customer_id,character_id,outfit_id)
                 VALUES (?,?,?) ON DUPLICATE KEY UPDATE outfit_id=VALUES(outfit_id)"
            )->execute([$customerId,$characterId,$defaultOutfit]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        try {
            arcade_social_record_purchase_notice(
                $pdo,$customerId,$transactionId,
                'Character Added: '.(string)$character['name'],
                (string)$character['name'].' was purchased for '.number_format($diamondCost>0?$diamondCost:$shardCost).' '.($diamondCost>0?'Diamonds':'Shards').' and equipped to your Arcade account.',
                '/arcade.php#hub=character'
            );
        } catch (Throwable $noticeError) { error_log('[ARCADE_PURCHASE_INBOX] '.$noticeError->getMessage()); }
        echo json_encode([
            'success'=>true,
            'message'=>$diamondCost > 0 ? 'Premium character purchased and equipped.' : 'Character purchased and equipped.',
            'state'=>$duplicateState(),
            'achievements'=>arcade_achievements_sync($pdo,$customerId),
            'dailyLogin'=>arcade_daily_login_status($pdo,$customerId),
            'masteryFashion'=>arcade_mastery_fashion_player_state($pdo,$customerId),
        ],JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'purchase_outfit' || $action === 'equip_outfit') {
        $outfitId = arcade_premium_safe_key((string)($input['outfitId'] ?? ''),80);
        $premiumCatalog = arcade_premium_catalog($pdo);
        $outfit = null;
        foreach ($premiumCatalog['outfits'] as $candidate) {
            if ((string)$candidate['itemKey'] === $outfitId) { $outfit = $candidate; break; }
        }
        if (!$outfit) throw new InvalidArgumentException('Unknown outfit.');
        $characterId = (string)$outfit['characterId'];
        if ($characterId === '') throw new InvalidArgumentException('Outfit is missing its character.');
        $price = max(0,(int)$outfit['diamondPrice']);

        $pdo->beginTransaction();
        try {
            $dup=$pdo->prepare("SELECT id FROM arcade_player_transactions WHERE customer_id=? AND transaction_uuid=? LIMIT 1");
            $dup->execute([$customerId,$transactionId]);
            if($dup->fetchColumn()){
                $pdo->commit();
                echo json_encode(['success'=>true,'duplicate'=>true,'state'=>$duplicateState()]);
                exit;
            }
            $ownedCharacter=$pdo->prepare("SELECT 1 FROM arcade_player_characters WHERE customer_id=? AND character_id=? LIMIT 1");
            $ownedCharacter->execute([$customerId,$characterId]);
            if(!$ownedCharacter->fetchColumn()) throw new DomainException('Own the character before buying its outfit.');

            $ownedOutfit = !empty($outfit['isDefault']);
            if (!$ownedOutfit) {
                $own=$pdo->prepare("SELECT 1 FROM arcade_player_premium_items WHERE customer_id=? AND item_type='outfit' AND item_key=? LIMIT 1");
                $own->execute([$customerId,$outfitId]);
                $ownedOutfit=(bool)$own->fetchColumn();
            }

            $diamondDelta=0;
            if ($action === 'purchase_outfit' && !$ownedOutfit) {
                $account=$pdo->prepare("SELECT diamonds FROM arcade_player_accounts WHERE customer_id=? FOR UPDATE");
                $account->execute([$customerId]);
                $diamonds=(int)($account->fetchColumn() ?: 0);
                if ($diamonds < $price) throw new DomainException('Not enough Diamonds.');
                $diamondDelta=-$price;
                $pdo->prepare("UPDATE arcade_player_accounts SET diamonds=diamonds-?,state_version=state_version+1 WHERE customer_id=?")
                    ->execute([$price,$customerId]);
                $pdo->prepare(
                    "INSERT INTO arcade_player_premium_items
                     (customer_id,item_type,item_key,character_id,purchased_diamonds,source,transaction_uuid)
                     VALUES (?, 'outfit', ?, ?, ?, 'diamond_purchase', ?)"
                )->execute([$customerId,$outfitId,$characterId,$price,$transactionId]);
                $ownedOutfit=true;
            }
            if (!$ownedOutfit) throw new DomainException('That outfit is locked.');

            $pdo->prepare(
                "INSERT INTO arcade_player_character_loadouts (customer_id,character_id,outfit_id)
                 VALUES (?,?,?) ON DUPLICATE KEY UPDATE outfit_id=VALUES(outfit_id)"
            )->execute([$customerId,$characterId,$outfitId]);
            $pdo->prepare(
                "UPDATE arcade_player_accounts
                 SET equipped_outfit=CASE WHEN equipped_character=? THEN ? ELSE equipped_outfit END,
                     state_version=state_version+1 WHERE customer_id=?"
            )->execute([$characterId,$outfitId,$customerId]);
            $pdo->prepare(
                "INSERT INTO arcade_player_transactions
                 (customer_id,transaction_uuid,event_type,character_id,outfit_id,diamond_delta,metadata_json)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([
                $customerId,$transactionId,$action==='purchase_outfit'?'outfit_purchase':'outfit_equip',
                $characterId,$outfitId,$diamondDelta,
                json_encode(['name'=>$outfit['name'],'visualStyle'=>$outfit['visualStyle']],JSON_UNESCAPED_SLASHES)
            ]);
            $pdo->commit();
        } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
        if($action==='purchase_outfit'){
            try { arcade_social_record_purchase_notice($pdo,$customerId,$transactionId,'Outfit Added: '.(string)$outfit['name'],(string)$outfit['name'].' was purchased for '.number_format($price).' Diamonds and equipped.','/arcade.php#hub=wardrobe'); }
            catch(Throwable $noticeError){ error_log('[ARCADE_PURCHASE_INBOX] '.$noticeError->getMessage()); }
        }
        echo json_encode([
            'success'=>true,
            'message'=>$action==='purchase_outfit'?'Outfit purchased and equipped.':'Outfit equipped.',
            'state'=>$duplicateState(),
        ],JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'equip_character') {
        $characterId = trim((string)($input['characterId'] ?? ''));
        if (!isset(arcade_authority_character_catalog()[$characterId])) {
            throw new InvalidArgumentException('Unknown character.');
        }

        $pdo->beginTransaction();
        try {
            $dup = $pdo->prepare(
                "SELECT id FROM arcade_player_transactions
                 WHERE customer_id = ? AND transaction_uuid = ? LIMIT 1"
            );
            $dup->execute([$customerId, $transactionId]);
            if ($dup->fetchColumn()) {
                $pdo->commit();
                echo json_encode(['success'=>true,'duplicate'=>true,'state'=>$duplicateState()]);
                exit;
            }

            $owned = $pdo->prepare(
                "SELECT 1 FROM arcade_player_characters
                 WHERE customer_id = ? AND character_id = ? LIMIT 1"
            );
            $owned->execute([$customerId, $characterId]);
            if (!$owned->fetchColumn()) throw new DomainException('That character is not owned.');

            $loadout=$pdo->prepare(
                "SELECT outfit_id FROM arcade_player_character_loadouts WHERE customer_id=? AND character_id=? LIMIT 1"
            );
            $loadout->execute([$customerId,$characterId]);
            $equippedOutfit=(string)($loadout->fetchColumn() ?: ($characterId . '_default'));
            $pdo->prepare(
                "UPDATE arcade_player_accounts
                 SET equipped_character = ?, equipped_outfit = ?, equipped_form = 'illustrated', state_version = state_version + 1
                 WHERE customer_id = ?"
            )->execute([$characterId,$equippedOutfit,$customerId]);

            $pdo->prepare(
                "INSERT INTO arcade_player_transactions
                 (customer_id, transaction_uuid, event_type, character_id, outfit_id)
                 VALUES (?, ?, 'character_equip', ?, ?)"
            )->execute([$customerId, $transactionId, $characterId, $equippedOutfit]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        echo json_encode(['success'=>true,'message'=>'Character equipped.','state'=>$duplicateState()]);
        exit;
    }

    if ($action === 'equip_form') {
        $form = trim((string)($input['form'] ?? ''));
        if (!in_array($form, arcade_authority_forms(), true)) {
            throw new InvalidArgumentException('Unknown character form.');
        }
        $pdo->beginTransaction();
        try {
            $dup=$pdo->prepare("SELECT id FROM arcade_player_transactions WHERE customer_id=? AND transaction_uuid=? LIMIT 1");
            $dup->execute([$customerId,$transactionId]);
            if($dup->fetchColumn()){
                $pdo->commit();
                echo json_encode(['success'=>true,'duplicate'=>true,'state'=>$duplicateState()]);
                exit;
            }
            $pdo->prepare(
                "UPDATE arcade_player_accounts SET equipped_form=?, state_version=state_version+1 WHERE customer_id=?"
            )->execute([$form,$customerId]);
            $pdo->prepare(
                "INSERT INTO arcade_player_transactions
                 (customer_id,transaction_uuid,event_type,metadata_json)
                 VALUES (?,?,'character_form_equip',?)"
            )->execute([$customerId,$transactionId,json_encode(['form'=>$form],JSON_UNESCAPED_SLASHES)]);
            $pdo->commit();
        } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
        echo json_encode(['success'=>true,'message'=>'Character form equipped.','state'=>$duplicateState()]);
        exit;
    }

    if ($action === 'claim_mastery_reward') {
        $characterId = trim((string)($input['characterId'] ?? ''));
        $level = (int)($input['level'] ?? 0);
        $reward = arcade_authority_mastery_rewards()[$level] ?? null;
        if (!isset(arcade_authority_character_catalog()[$characterId]) || !$reward) {
            throw new InvalidArgumentException('Unknown Mastery reward.');
        }

        $pdo->beginTransaction();
        try {
            $dup = $pdo->prepare(
                "SELECT id FROM arcade_player_transactions
                 WHERE customer_id = ? AND transaction_uuid = ? LIMIT 1"
            );
            $dup->execute([$customerId, $transactionId]);
            if ($dup->fetchColumn()) {
                $pdo->commit();
                echo json_encode(['success'=>true,'duplicate'=>true,'state'=>$duplicateState()]);
                exit;
            }

            $characterStmt = $pdo->prepare(
                "SELECT mastery_xp FROM arcade_player_characters
                 WHERE customer_id = ? AND character_id = ? FOR UPDATE"
            );
            $characterStmt->execute([$customerId, $characterId]);
            $xp = $characterStmt->fetchColumn();
            if ($xp === false) throw new DomainException('That character is not owned.');
            if (arcade_authority_level((int)$xp) < $level) {
                throw new DomainException('That Mastery level has not been reached.');
            }

            $claim = $pdo->prepare(
                "INSERT IGNORE INTO arcade_player_reward_claims
                 (customer_id, character_id, mastery_level, transaction_uuid)
                 VALUES (?, ?, ?, ?)"
            );
            $claim->execute([$customerId, $characterId, $level, $transactionId]);
            if ($claim->rowCount() !== 1) {
                throw new DomainException('That Mastery reward was already claimed.');
            }

            $banked = (int)($reward['banked'] ?? 0);
            if ($banked > 0) {
                $pdo->prepare(
                    "UPDATE arcade_player_accounts
                     SET banked_shards = banked_shards + ?,
                         state_version = state_version + 1
                     WHERE customer_id = ?"
                )->execute([$banked, $customerId]);
            } else {
                $pdo->prepare(
                    "UPDATE arcade_player_accounts SET state_version = state_version + 1
                     WHERE customer_id = ?"
                )->execute([$customerId]);
            }

            $itemId = (string)($reward['item'] ?? '');
            $itemQty = (int)($reward['quantity'] ?? 0);
            if ($itemId !== '' && $itemQty > 0) {
                arcade_authority_add_item($pdo, $customerId, $itemId, $itemQty);
            }

            $pdo->prepare(
                "INSERT INTO arcade_player_transactions
                 (customer_id, transaction_uuid, event_type, character_id,
                  shard_delta, item_id, item_quantity, metadata_json)
                 VALUES (?, ?, 'mastery_reward', ?, ?, ?, ?, ?)"
            )->execute([
                $customerId, $transactionId, $characterId, $banked,
                $itemId !== '' ? $itemId : null, $itemQty,
                json_encode(['level'=>$level,'label'=>$reward['label']], JSON_UNESCAPED_SLASHES)
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        echo json_encode([
            'success'=>true,
            'message'=>'Mastery reward claimed.',
            'state'=>$duplicateState(),
            'achievements'=>arcade_achievements_sync($pdo,$customerId),
            'dailyLogin'=>arcade_daily_login_status($pdo,$customerId),
            'masteryFashion'=>arcade_mastery_fashion_player_state($pdo,$customerId),
        ],JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }


    if ($action === 'set_progression_difficulty') {
        $difficulty=trim((string)($input['difficulty']??'street'));
        $progression=arcade_progression_set_difficulty($pdo,$customerId,$difficulty,$transactionId);
        echo json_encode(['success'=>true,'message'=>'Difficulty updated.','progression'=>$progression,'state'=>$duplicateState()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'claim_level_reward') {
        $rewardLevel=max(0,(int)($input['level']??0));
        $progression=arcade_progression_claim_level_reward($pdo,$customerId,$rewardLevel,$transactionId);
        echo json_encode(['success'=>true,'message'=>'Level reward claimed.','progression'=>$progression,'state'=>$duplicateState()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'prestige_player') {
        $masteryFashion=arcade_mastery_prestige($pdo,$customerId,$transactionId);
        echo json_encode(['success'=>true,'message'=>'Diamond Prestige complete.','masteryFashion'=>$masteryFashion,'progression'=>arcade_progression_state($pdo,$customerId),'state'=>$duplicateState()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'set_powerup_loadout') {
        $slot=max(0,(int)($input['slot']??0));
        $powerupKey=strtolower(trim((string)($input['powerupKey']??'')));
        $masteryFashion=arcade_mastery_set_powerup_loadout($pdo,$customerId,$slot,$powerupKey,$transactionId);
        echo json_encode(['success'=>true,'message'=>'Power-Up loadout updated.','masteryFashion'=>$masteryFashion,'state'=>$duplicateState()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'upgrade_powerup') {
        $powerupKey=strtolower(trim((string)($input['powerupKey']??'')));
        $masteryFashion=arcade_mastery_upgrade_powerup($pdo,$customerId,$powerupKey,$transactionId);
        echo json_encode(['success'=>true,'message'=>'Power-Up upgraded.','masteryFashion'=>$masteryFashion,'state'=>$duplicateState()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'purchase_wearable') {
        $itemKey=strtolower(trim((string)($input['itemKey']??'')));
        $characterId=strtolower(trim((string)($input['characterId']??'')));
        $masteryFashion=arcade_mastery_purchase_wearable($pdo,$customerId,$itemKey,$characterId,$transactionId);
        try { arcade_social_record_purchase_notice($pdo,$customerId,$transactionId,'Wearable Added: '.ucwords(str_replace('-',' ',$itemKey)),'Your wearable purchase was verified and saved permanently to your account.','/arcade.php#hub=wearables'); } catch(Throwable $noticeError){ error_log('[ARCADE_PURCHASE_INBOX] '.$noticeError->getMessage()); }
        echo json_encode(['success'=>true,'message'=>'Wearable purchased and equipped.','masteryFashion'=>$masteryFashion,'state'=>$duplicateState()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'equip_wearable') {
        $itemKey=strtolower(trim((string)($input['itemKey']??'')));
        $characterId=strtolower(trim((string)($input['characterId']??'')));
        $masteryFashion=arcade_mastery_equip_wearable($pdo,$customerId,$itemKey,$characterId,$transactionId);
        echo json_encode(['success'=>true,'message'=>'Wearable equipped.','masteryFashion'=>$masteryFashion,'state'=>$duplicateState()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'unequip_wearable') {
        $slot=strtolower(trim((string)($input['slot']??'')));
        $characterId=strtolower(trim((string)($input['characterId']??'')));
        $masteryFashion=arcade_mastery_unequip_wearable($pdo,$customerId,$slot,$characterId,$transactionId);
        echo json_encode(['success'=>true,'message'=>'Wearable removed.','masteryFashion'=>$masteryFashion,'state'=>$duplicateState()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'purchase_wearable_bundle') {
        $bundleKey=strtolower(trim((string)($input['bundleKey']??'')));$characterId=strtolower(trim((string)($input['characterId']??'')));
        $masteryFashion=arcade_mastery_purchase_wearable_bundle($pdo,$customerId,$bundleKey,$characterId,$transactionId);
        try { arcade_social_record_purchase_notice($pdo,$customerId,$transactionId,'Wearable Bundle Added: '.ucwords(str_replace('-',' ',$bundleKey)),'Your wearable bundle purchase was verified and saved permanently to your account.','/arcade.php#hub=wearables'); } catch(Throwable $noticeError){ error_log('[ARCADE_PURCHASE_INBOX] '.$noticeError->getMessage()); }
        echo json_encode(['success'=>true,'message'=>'Wearable bundle purchased and equipped.','masteryFashion'=>$masteryFashion,'state'=>$duplicateState()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }

    if ($action === 'save_wearable_look') {
        $slot=(int)($input['slot']??0);$name=(string)($input['name']??'');$characterId=strtolower(trim((string)($input['characterId']??'')));
        $masteryFashion=arcade_mastery_save_look($pdo,$customerId,$slot,$name,$characterId,$transactionId);
        echo json_encode(['success'=>true,'message'=>'Saved Look stored.','masteryFashion'=>$masteryFashion,'state'=>$duplicateState()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }

    if ($action === 'apply_wearable_look') {
        $slot=(int)($input['slot']??0);$masteryFashion=arcade_mastery_apply_look($pdo,$customerId,$slot,$transactionId);
        echo json_encode(['success'=>true,'message'=>'Saved Look applied.','masteryFashion'=>$masteryFashion,'state'=>$duplicateState()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }

    if ($action === 'delete_wearable_look') {
        $slot=(int)($input['slot']??0);$masteryFashion=arcade_mastery_delete_look($pdo,$customerId,$slot,$transactionId);
        echo json_encode(['success'=>true,'message'=>'Saved Look deleted.','masteryFashion'=>$masteryFashion,'state'=>$duplicateState()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }

    if ($action === 'purchase_effect') {
        $effectKey=(string)($input['effectKey'] ?? '');
        $effects=arcade_effects_purchase($pdo,$customerId,$effectKey,$transactionId);
        try { arcade_social_record_purchase_notice($pdo,$customerId,$transactionId,'Ability Effect Added: '.ucwords(str_replace(['-','_'],' ',$effectKey)),'Your Ability Effect purchase was verified and saved to your account.','/arcade.php#hub=effects'); } catch(Throwable $noticeError){ error_log('[ARCADE_PURCHASE_INBOX] '.$noticeError->getMessage()); }
        echo json_encode(['success'=>true,'message'=>'Effect purchased.','effects'=>$effects],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'equip_effect') {
        $characterId=(string)($input['characterId'] ?? '');
        $effectKey=(string)($input['effectKey'] ?? '');
        $effects=arcade_effects_equip($pdo,$customerId,$characterId,$effectKey);
        echo json_encode(['success'=>true,'message'=>'Effect equipped.','effects'=>$effects],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }


    if ($action === 'tutorial_progress') {
        $stepKey=trim((string)($input['stepKey']??''));
        $completed=is_array($input['completedSteps']??null)?$input['completedSteps']:[];
        $status=trim((string)($input['status']??'in_progress'));
        arcade_tutorial_save_progress($pdo,$customerId,$stepKey,$completed,$status);
        echo json_encode(['success'=>true,'message'=>'Tutorial progress saved.','tutorialPrivacy'=>arcade_tutorial_privacy_state($pdo,$customerId)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'tutorial_skip') {
        $stepKey=trim((string)($input['stepKey']??''));
        $completed=is_array($input['completedSteps']??null)?$input['completedSteps']:[];
        arcade_tutorial_save_progress($pdo,$customerId,$stepKey,$completed,'skipped');
        echo json_encode(['success'=>true,'message'=>'Tutorial skipped. You can restart it from Help & Training.','tutorialPrivacy'=>arcade_tutorial_privacy_state($pdo,$customerId)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'tutorial_complete') {
        $result=arcade_tutorial_complete_and_reward($pdo,$customerId,$transactionId);
        echo json_encode(['success'=>true,'message'=>$result['duplicate']?'Tutorial was already completed.':'Tutorial complete. Training reward saved.','reward'=>$result['reward'],'tutorialPrivacy'=>arcade_tutorial_privacy_state($pdo,$customerId),'state'=>$duplicateState()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'privacy_request_create') {
        $request=arcade_privacy_create_request($pdo,$customerId,trim((string)($input['requestType']??'')),(string)($input['message']??''));
        echo json_encode(['success'=>true,'message'=>'Privacy request submitted.','request'=>$request,'tutorialPrivacy'=>arcade_tutorial_privacy_state($pdo,$customerId)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'privacy_signout_others') {
        $epoch=arcade_security_rotate_other_sessions($pdo,$customerId);
        echo json_encode(['success'=>true,'message'=>'Other arcade sessions will be signed out when they next connect.','sessionEpoch'=>$epoch,'tutorialPrivacy'=>arcade_tutorial_privacy_state($pdo,$customerId)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'continue_run_purchase') {
        $runId=trim((string)($input['runId'] ?? ''));
        if(!preg_match('/^[A-Za-z0-9._:-]{8,100}$/',$runId)) throw new InvalidArgumentException('Invalid run ID.');
        $progression=arcade_progression_state($pdo,$customerId);
        $difficulty=(string)($progression['selectedDifficulty'] ?? 'street');
        $profiles=arcade_progression_difficulties($pdo);
        $profile=$profiles[$difficulty] ?? $profiles['street'];
        $cost=max(0,min(10000,(int)($profile['continueCost'] ?? 100)));

        // Older live databases could report the current schema version while
        // still missing the run_id ledger column. The dedicated receipt table
        // makes this purchase path work and remain idempotent on every version.
        arcade_schema_repair_continue_purchase_schema($pdo);

        $pdo->beginTransaction();
        try {
            $dup=$pdo->prepare("SELECT transaction_uuid FROM arcade_player_transactions WHERE customer_id=? AND transaction_uuid=? LIMIT 1");
            $dup->execute([$customerId,$transactionId]);
            if($dup->fetchColumn()!==false){
                $pdo->commit();
                echo json_encode(['success'=>true,'duplicate'=>true,'cost'=>$cost,'state'=>$duplicateState()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
                exit;
            }

            $already=$pdo->prepare("SELECT shard_cost FROM arcade_run_continue_purchases WHERE customer_id=? AND run_id=? LIMIT 1 FOR UPDATE");
            $already->execute([$customerId,$runId]);
            $alreadyCost=$already->fetchColumn();
            if($alreadyCost!==false){
                $pdo->commit();
                echo json_encode([
                    'success'=>true,'duplicate'=>true,
                    'message'=>'Continue already verified. Resuming this run.',
                    'cost'=>(int)$alreadyCost,'state'=>$duplicateState()
                ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
                exit;
            }

            $account=$pdo->prepare("SELECT banked_shards FROM arcade_player_accounts WHERE customer_id=? FOR UPDATE");
            $account->execute([$customerId]);
            $banked=(int)($account->fetchColumn() ?: 0);
            if($banked<$cost) throw new DomainException('Not enough Banked Shards.');

            if($cost>0){
                $charge=$pdo->prepare("UPDATE arcade_player_accounts SET banked_shards=banked_shards-?,state_version=state_version+1 WHERE customer_id=? AND banked_shards>=?");
                $charge->execute([$cost,$customerId,$cost]);
                if($charge->rowCount()!==1) throw new DomainException('Not enough Banked Shards.');
            }

            $pdo->prepare("INSERT INTO arcade_run_continue_purchases(customer_id,run_id,transaction_uuid,shard_cost,difficulty) VALUES (?,?,?,?,?)")
                ->execute([$customerId,$runId,$transactionId,$cost,$difficulty]);

            // The dedicated continue receipt is authoritative. Keep the general
            // ledger best-effort so a legacy transaction-table difference can
            // never strand a player after a valid Shard deduction.
            try {
                $columns=['customer_id','transaction_uuid','event_type','shard_delta'];
                $values=[$customerId,$transactionId,'continue_purchase',-$cost];
                if(arcade_schema_column_exists($pdo,'arcade_player_transactions','run_id')){
                    $columns[]='run_id';$values[]=$runId;
                }
                if(arcade_schema_column_exists($pdo,'arcade_player_transactions','metadata_json')){
                    $columns[]='metadata_json';
                    $values[]=json_encode(['difficulty'=>$difficulty,'cost'=>$cost],JSON_UNESCAPED_SLASHES);
                }
                $placeholders=implode(',',array_fill(0,count($columns),'?'));
                $pdo->prepare('INSERT INTO arcade_player_transactions('.implode(',',$columns).') VALUES ('.$placeholders.')')->execute($values);
            } catch(Throwable $ledgerError) {
                error_log('[ARCADE_CONTINUE_LEDGER_OPTIONAL] '.$ledgerError->getMessage());
            }
            $pdo->commit();
        } catch(PDOException $e){
            if($pdo->inTransaction())$pdo->rollBack();
            if((string)$e->getCode()==='23000'){
                $check=$pdo->prepare("SELECT shard_cost FROM arcade_run_continue_purchases WHERE customer_id=? AND run_id=? LIMIT 1");
                $check->execute([$customerId,$runId]);
                $savedCost=$check->fetchColumn();
                if($savedCost!==false){
                    echo json_encode([
                        'success'=>true,'duplicate'=>true,
                        'message'=>'Continue already verified. Resuming this run.',
                        'cost'=>(int)$savedCost,'state'=>$duplicateState()
                    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }
            throw $e;
        } catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
        echo json_encode(['success'=>true,'message'=>'Run continue purchased.','cost'=>$cost,'state'=>$duplicateState()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'claim_growth_reward') {
        $rewardType=trim((string)($input['rewardType'] ?? ''));
        $rewardKey=trim((string)($input['rewardKey'] ?? ''));
        $claim=arcade_growth_claim($pdo,$customerId,$rewardType,$rewardKey,$transactionId);
        echo json_encode([
            'success'=>true,'message'=>$claim['message'],'reward'=>$claim,
            'state'=>$duplicateState(),'growth'=>arcade_growth_state($pdo,$customerId)
        ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'record_growth_event') {
        $eventKey=strtolower(trim((string)($input['eventKey'] ?? '')));
        $allowed=['referral_copy','challenge_copy','challenge_share','growth_tab_open','daily_view','weekly_view'];
        if(!in_array($eventKey,$allowed,true)) throw new InvalidArgumentException('Unknown growth event.');
        $source=substr(preg_replace('/[^a-z0-9_-]+/i','',(string)($input['source'] ?? '')) ?? '',0,80);
        arcade_growth_log($pdo,$customerId,$eventKey,$source);
        echo json_encode(['success'=>true,'message'=>'Growth event recorded.']);
        exit;
    }

    if ($action === 'sync_gauntlet_collection') {
        $clientArtifacts = is_array($input['artifacts'] ?? null) ? $input['artifacts'] : [];
        $clientRelics = is_array($input['relics'] ?? null) ? $input['relics'] : [];
        $clientEvolutions = is_array($input['weaponEvolutions'] ?? null) ? $input['weaponEvolutions'] : [];
        if (count($clientArtifacts) > 60 || count($clientRelics) > 30 || count($clientEvolutions) > 20) {
            throw new InvalidArgumentException('Gauntlet collection payload is too large.');
        }
        $collection = arcade_gauntlet_collection_merge($pdo, $customerId, $clientArtifacts, $clientRelics, $clientEvolutions);
        echo json_encode(['success'=>true,'message'=>'Gauntlet collection synced.','gauntletCollection'=>$collection],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'claim_bestiary_hunt') {
        $huntId=arcade_authority_int($input['huntId']??0,2147483647);
        if($huntId<1)throw new InvalidArgumentException('Featured Hunt ID is required.');
        $claim=arcade_bestiary_claim_hunt($pdo,$customerId,$huntId,$transactionId);
        echo json_encode(['success'=>true,'message'=>$claim['message'],'reward'=>$claim['reward'],'state'=>$duplicateState(),'bestiary'=>$claim['state']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'submit_run') {
        $runId = trim((string)($input['runId'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $runId)) {
            throw new InvalidArgumentException('Invalid run ID.');
        }

        $duration = arcade_authority_int($input['duration'] ?? 0, 7200);
        $score = arcade_authority_int($input['score'] ?? 0, 10000000);
        $catches = arcade_authority_int($input['catches'] ?? 0, 100000);
        $perfect = arcade_authority_int($input['perfect'] ?? 0, 100000);
        $bossSettings = arcade_boss_settings($pdo);
        $mode = strtolower(trim((string)($input['mode'] ?? 'survival')));
        if (!in_array($mode,['survival','runway','boss-rush','gauntlet'],true)) $mode='survival';
        if ($mode==='boss-rush' && empty($bossSettings['bossRushEnabled'])) $mode='survival';
        $bossLimit = $mode==='boss-rush' ? (int)$bossSettings['bossRushTarget'] : 2;
        $bosses = arcade_authority_int($input['bossClears'] ?? 0, $bossLimit);
        $rawBossResults = $input['bossResults'] ?? [];
        $hasBossDetails = is_array($rawBossResults) && count($rawBossResults) > 0;
        $bossResults = arcade_boss_normalize_results($rawBossResults,$bosses,$bossSettings);
        $eliteBossClears = count(array_filter($bossResults,static fn(array $row):bool=>!empty($row['elite'])));
        $declaredElite = arcade_authority_int($input['eliteBossClears'] ?? 0,$bossLimit);
        if (($hasBossDetails || $declaredElite > 0) && $declaredElite !== $eliteBossClears) throw new DomainException('Elite Boss result data did not match the run summary.');
        $bossRushComplete = $mode==='boss-rush' && !empty($input['bossRushComplete']) && $bosses >= (int)$bossSettings['bossRushTarget'];
        if ($mode==='boss-rush' && !empty($input['bossRushComplete']) && !$bossRushComplete) throw new DomainException('Boss Rush completion was not valid for this run.');
        if (($mode==='boss-rush' || $hasBossDetails) && count($bossResults) !== $bosses && $bosses > 0) throw new DomainException('Boss result details were incomplete.');
        if ($mode==='boss-rush' && $bosses > 0) {
            $catalogBossIds=array_keys(arcade_boss_catalog());
            $expectedBossIds=[];
            for($bossOrderIndex=0;$bossOrderIndex<$bosses;$bossOrderIndex++){
                $expectedBossIds[]=$catalogBossIds[$bossOrderIndex % count($catalogBossIds)];
            }
            $submittedBossIds=array_map(static fn(array $row):string=>(string)$row['id'],$bossResults);
            if ($submittedBossIds !== $expectedBossIds) throw new DomainException('Boss Rush encounter order was invalid.');
            if (!empty($bossSettings['eliteEnabled']) && $bossRushComplete && empty($bossResults[$bosses-1]['elite'])) {
                throw new DomainException('The Elite Boss Rush finale was missing.');
            }
        }
        if (empty($bossSettings['eliteEnabled']) && $eliteBossClears > 0) throw new DomainException('Elite Bosses are currently disabled.');
        $bossRewardPlan = arcade_boss_reward_plan($bossResults,$bossRushComplete,$bossSettings);
        $outfits = arcade_authority_int($input['outfits'] ?? 0, 100);
        $progressionStateForRunway=arcade_progression_state($pdo,$customerId);
        $runwayDifficulty=(string)($progressionStateForRunway['selectedDifficulty']??'street');
        $runwaySettings=arcade_runway_settings($pdo);
        $runwaySummary=arcade_runway_normalize_summary($input['runway']??[],$runwayDifficulty,$outfits,$catches);
        $runwayRewardPlan=$mode==='runway'?arcade_runway_reward_plan($runwaySummary,$runwaySettings):[
            'grade'=>'—','gradeKey'=>'','accuracy'=>0,'mistakes'=>0,'difficulty'=>$runwayDifficulty,'items'=>[],
            'completedOutfits'=>0,'goodCatches'=>0,'wrongCatches'=>0,'damagedCatches'=>0,'decoyCatches'=>0,'missedMaterials'=>0,'stagesCompleted'=>0,
        ];
        $gauntletSummary=$mode==='gauntlet'
            ? arcade_gauntlet_normalize_summary($input['gauntlet']??[],$duration,$score)
            : ['level'=>0,'completedLevels'=>0,'victory'=>false,'quit'=>false,'kills'=>0,'eliteKills'=>0,'enemyEncounters'=>[],'enemyDefeats'=>[],'duration'=>$duration,'score'=>$score];
        $gauntletRewardPlan=$mode==='gauntlet'
            ? arcade_gauntlet_reward_plan($gauntletSummary)
            : ['bankedShards'=>0,'mobShards'=>0,'levelShards'=>0,'clearBonus'=>0,'items'=>[],'level'=>0,'completedLevels'=>0,'kills'=>0,'eliteKills'=>0,'victory'=>false];
        $maxCombo = arcade_authority_int($input['maxCombo'] ?? 0, 100000);
        $atkKills = arcade_authority_int($input['atkKills'] ?? 0, 100000);
        $miniBossClears = arcade_authority_int($input['miniBossClears'] ?? 0, 2);
        $enemyKills = arcade_authority_int($input['enemyKills'] ?? 0, 100000);
        $hasEnemyBreakdown=is_array($input['enemyDefeats']??null)&&count($input['enemyDefeats'])>0;
        $enemyEncounters=arcade_bestiary_normalize_count_map($input['enemyEncounters']??[],['drifter','armored','leech'],100000);
        $enemyDefeats=arcade_bestiary_normalize_count_map($input['enemyDefeats']??[],['drifter','armored','leech'],100000);
        if(!$hasEnemyBreakdown&&$enemyKills>0){$enemyDefeats['drifter']=$enemyKills;$enemyEncounters['drifter']=max($enemyEncounters['drifter'],$enemyKills);}
        if($hasEnemyBreakdown&&array_sum($enemyDefeats)!==$enemyKills)throw new DomainException('Enemy Bestiary totals did not match the run summary.');
        foreach($enemyDefeats as $enemyKey=>$enemyCount){if($enemyCount>$enemyEncounters[$enemyKey])throw new DomainException('Enemy defeat totals exceeded encounters.');}
        $materialDropWhitelist=['diamond-dust'=>8,'fabric-roll'=>8,'button-set'=>8,'sewing-pin'=>8,'gold-thread'=>2];
        $materialDrops=[];
        if(is_array($input['materialDrops']??null)){
            foreach($input['materialDrops'] as $materialId=>$materialQty){
                $materialId=strtolower(trim((string)$materialId));
                if(!isset($materialDropWhitelist[$materialId]))continue;
                $materialQty=max(0,min((int)$materialDropWhitelist[$materialId],(int)$materialQty));
                if($materialQty>0)$materialDrops[$materialId]=$materialQty;
            }
        }
        $miniBossEncounters=arcade_authority_int($input['miniBossEncounters']??$miniBossClears,2);
        $miniBossDefeats=arcade_authority_int($input['miniBossDefeats']??$miniBossClears,2);
        if($miniBossDefeats!==$miniBossClears||$miniBossDefeats>$miniBossEncounters)throw new DomainException('Mini-Boss Bestiary totals did not match the run summary.');
        $bossEncounters=arcade_bestiary_normalize_boss_encounters($input['bossEncounters']??[],16);
        if(!$bossEncounters&&$bossResults){foreach($bossResults as $row)$bossEncounters[]=['id'=>$row['id'],'elite'=>!empty($row['elite']),'highestPhase'=>4,'defeated'=>true,'clearMs'=>0];}
        $defeatedBossEncounters=array_values(array_filter($bossEncounters,static fn(array $row):bool=>!empty($row['defeated'])));
        if(count($defeatedBossEncounters)!==$bosses)throw new DomainException('Boss Bestiary clear totals did not match the run summary.');
        if(count($bossEncounters)>$bosses+1)throw new DomainException('Too many boss encounters were reported.');
        $defeatedEncounterIds=array_map(static fn(array $row):string=>(string)$row['id'],$defeatedBossEncounters);
        $rewardBossIds=array_map(static fn(array $row):string=>(string)$row['id'],$bossResults);
        if($defeatedEncounterIds!==$rewardBossIds)throw new DomainException('Boss Bestiary encounter order was invalid.');
        $mutator = strtolower(trim((string)($input['mutator'] ?? 'none')));
        $allowedMutators=['none','low_gravity','double_powerups','no_shields','double_shards','fast_enemies','stronger_bosses'];
        if (!in_array($mutator,$allowedMutators,true)) $mutator='none';
        $sessionLength = strtolower(trim((string)($input['sessionLength'] ?? 'extended')));
        if (!in_array($sessionLength,['standard','extended','marathon'],true)) {
            $sessionLength='extended';
        }

        $allowedRunUpgrades = [
            'speed'=>4,'health'=>4,'catch'=>4,'charge'=>4,'dash'=>3,'shield'=>3,
            'combo'=>4,'special'=>4,'perfect'=>4,'recovery'=>3,'armor'=>4,
            'boss'=>4,'magnet'=>3,'overclock'=>3,'shard'=>3,'runway'=>3,
            'extended-dash'=>3,'opening-charge'=>3,'signal-reserve'=>3,
            'powerup-radar'=>3,'runway-clock'=>3,'momentum-engine'=>3,
            'diamond-focus'=>3,'core-plating'=>3,
        ];
        $runUpgrades = [];
        $postedRunUpgrades = is_array($input['runUpgrades'] ?? null)
            ? $input['runUpgrades']
            : [];
        foreach ($postedRunUpgrades as $upgradeId=>$upgradeLevel) {
            $upgradeId = strtolower(trim((string)$upgradeId));
            if (!isset($allowedRunUpgrades[$upgradeId])) continue;
            $level = max(0,min(
                (int)$allowedRunUpgrades[$upgradeId],
                (int)$upgradeLevel
            ));
            if ($level > 0) $runUpgrades[$upgradeId] = $level;
        }
        $calculatedRunUpgradePicks = array_sum($runUpgrades);
        $runUpgradePicks = arcade_authority_int(
            $input['runUpgradePicks'] ?? $calculatedRunUpgradePicks,
            7
        );
        if ($calculatedRunUpgradePicks > 7 || $runUpgradePicks !== $calculatedRunUpgradePicks) {
            throw new DomainException('Temporary run-build data is outside accepted limits.');
        }

        $characterSpecial = strtolower(trim((string)($input['characterSpecial'] ?? '')));
        $characterSpecial = preg_replace('/[^a-z0-9_-]+/','',$characterSpecial) ?? '';
        $characterSpecial = substr($characterSpecial,0,40);
        if ($duration < 5) throw new DomainException('Run was too short to submit.');
        if($mode==='gauntlet' && (int)$gauntletSummary['kills']<1 && empty($gauntletSummary['quit'])) throw new DomainException('Gauntlet run did not contain a valid encounter.');
        $maxCatches = max(80, $duration * 12);
        $maxAttackKills = max(100, $duration * 20);
        if (
            $catches > $maxCatches
            || $perfect > $catches
            || $atkKills > $maxAttackKills
            || $maxCombo > ($catches + $outfits * 10 + 100)
        ) {
            throw new DomainException('Run statistics are outside accepted limits.');
        }
        $runMetrics = [
            'score'=>$score,'duration'=>$duration,'catches'=>$catches,'perfect'=>$perfect,
            'bosses'=>$bosses,'outfits'=>$outfits,'maxCombo'=>$maxCombo,'atkKills'=>$atkKills,
            'miniBosses'=>$miniBossClears,'enemyKills'=>$enemyKills,'mutator'=>$mutator,
            'mode'=>$mode,'sessionLength'=>$sessionLength,'eliteBosses'=>$eliteBossClears,'bossRushComplete'=>$bossRushComplete,
            'gauntletKills'=>(int)$gauntletSummary['kills'],'gauntletLevel'=>(int)$gauntletSummary['level'],'gauntletVictory'=>!empty($gauntletSummary['victory']),
        ];
        $runRisk = arcade_trust_score_run($runMetrics);
        $runReview = null;
        $growthRun = null;
        $bestiaryRun = null;

        if ($mode === 'gauntlet') {
            // v4.6.27.9: repair new career columns before the reward transaction.
            // MySQL/MariaDB DDL can implicitly commit, so this must stay outside.
            arcade_gauntlet_ensure($pdo);
        }

        $pdo->beginTransaction();
        try {
            $rate = $pdo->prepare(
                "SELECT COUNT(*) FROM arcade_player_run_receipts
                 WHERE customer_id = ? AND submitted_at >= (CURRENT_TIMESTAMP - INTERVAL 5 MINUTE)"
            );
            $rate->execute([$customerId]);
            if ((int)$rate->fetchColumn() >= 12) {
                throw new DomainException('Too many runs were submitted. Wait a few minutes.');
            }

            $receipt = $pdo->prepare(
                "SELECT banked_reward, lifetime_reward, mastery_xp_reward
                 FROM arcade_player_run_receipts
                 WHERE customer_id = ? AND run_id = ? LIMIT 1"
            );
            $receipt->execute([$customerId, $runId]);
            $existing = $receipt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $pdo->commit();
                $reviewStmt=$pdo->prepare(
                    "SELECT status FROM arcade_run_reviews WHERE customer_id=? AND run_id=? LIMIT 1"
                );
                $reviewStmt->execute([$customerId,$runId]);
                $reviewStatus=$reviewStmt->fetchColumn();
                echo json_encode([
                    'success'=>true,'duplicate'=>true,
                    'rewards'=>array_merge($existing,['gauntlet'=>$mode==='gauntlet'?$gauntletRewardPlan:null]),
                    'state'=>$duplicateState(),
                    'gauntlet'=>$mode==='gauntlet'?arcade_gauntlet_state($pdo,$customerId):null,
                    'runReview'=>$reviewStatus?[
                        'queued'=>$reviewStatus==='queued',
                        'message'=>'This run was already saved and its review status is unchanged.'
                    ]:null,
                    'trust'=>arcade_trust_player_state($pdo,$customerId)
                ]);
                exit;
            }

            $accountStmt = $pdo->prepare(
                "SELECT equipped_character, equipped_form FROM arcade_player_accounts
                 WHERE customer_id = ? FOR UPDATE"
            );
            $accountStmt->execute([$customerId]);
            $accountSelection = $accountStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $characterId = (string)($accountSelection['equipped_character'] ?? 'fire_muse');
            $characterForm = (string)($accountSelection['equipped_form'] ?? 'illustrated');
            if (!in_array($characterForm, arcade_authority_forms(), true)) $characterForm = 'illustrated';
            if (!isset(arcade_authority_character_catalog()[$characterId])) $characterId = 'fire_muse';

            $characterStmt = $pdo->prepare(
                "SELECT mastery_xp FROM arcade_player_characters
                 WHERE customer_id = ? AND character_id = ? FOR UPDATE"
            );
            $characterStmt->execute([$customerId, $characterId]);
            if ($characterStmt->fetchColumn() === false) {
                $pdo->prepare(
                    "INSERT IGNORE INTO arcade_player_characters
                     (customer_id, character_id, purchased_cost, purchased_at)
                     VALUES (?, 'fire_muse', 0, CURRENT_TIMESTAMP)"
                )->execute([$customerId]);
                $characterId = 'fire_muse';
            }

            $balanceState = arcade_balance_state($pdo);
            $rewardBalance = $balanceState['config']['rewards'] ?? arcade_balance_defaults()['rewards'];
            $survivalBalance = $balanceState['config']['survival'] ?? arcade_balance_defaults()['survival'];
            if ($mode!=='survival') {
                $miniBossClears=0;$miniBossEncounters=0;$miniBossDefeats=0;$enemyKills=0;
                $enemyEncounters=['drifter'=>0,'armored'=>0,'leech'=>0];$enemyDefeats=['drifter'=>0,'armored'=>0,'leech'=>0];$mutator='none';
            }
            $maxMiniBossByScore=0;
            if ($mode==='survival' && $score >= (int)$survivalBalance['miniBossScore1']) $maxMiniBossByScore=1;
            if ($mode==='survival' && $score >= (int)$survivalBalance['miniBossScore2']) $maxMiniBossByScore=2;
            $miniBossClears=min($miniBossClears,$maxMiniBossByScore);
            // Keep Bestiary totals aligned with the server-validated Mini-Boss clear count.
            $miniBossDefeats=$miniBossClears;
            $miniBossEncounters=min(2,max($miniBossDefeats,$miniBossEncounters));
            $timeBonus=$mode==='survival'
                ? intdiv($duration,max(10,(int)$survivalBalance['survivalTimeBonusEvery']))*(int)$survivalBalance['survivalTimeBonusShards'] : 0;
            $comboBonus=$mode==='survival'
                ? intdiv($maxCombo,max(5,(int)$survivalBalance['comboBonusEvery']))*(int)$survivalBalance['comboBonusShards'] : 0;
            $miniBossBonus=$mode==='survival' ? $miniBossClears*(int)$survivalBalance['miniBossShardReward'] : 0;
            $plausibleRunShardEvents=max(0,$catches+$perfect+$atkKills+$enemyKills);
            $milestoneBonus=$mode==='survival'
                ? intdiv($plausibleRunShardEvents,max(5,(int)$survivalBalance['shardMilestoneStep']))*(int)$survivalBalance['shardMilestoneBonus'] : 0;
            $survivalBonusTotal=$timeBonus+$comboBonus+$miniBossBonus+$milestoneBonus;
            $rewardBreakdown=['timeBonus'=>$timeBonus,'comboBonus'=>$comboBonus,'miniBossBonus'=>$miniBossBonus,'milestoneBonus'=>$milestoneBonus,'survivalBonusTotal'=>$survivalBonusTotal];
            $multiplier = $characterId === 'dirt_utility' ? 1.20 : 1.0;
            if($mode==='gauntlet'){
                $bankedReward=(int)$gauntletRewardPlan['bankedShards'];
            }else{
                $bankedReward = min(
                    (int)$rewardBalance['bankedMax'],
                    max((int)$rewardBalance['bankedMin'], (int)round(
                        (
                            floor($score / max(1,(int)$rewardBalance['scoreDivisor']))
                            + floor($catches / max(1,(int)$rewardBalance['catchDivisor']))
                            + $bosses * (int)$rewardBalance['bossBanked']
                            + $outfits * (int)$rewardBalance['outfitBanked']
                            + $survivalBonusTotal
                        ) * $multiplier
                    ))
                );
            }
            if ((int)($bossRewardPlan['bankedBonus'] ?? 0) > 0) {
                $bankedReward += (int)$bossRewardPlan['bankedBonus'];
            }
            $progressionBefore=arcade_progression_state($pdo,$customerId);
            $selectedDifficulty=(string)($progressionBefore['selectedDifficulty']??'street');
            $difficultyDefinitions=arcade_progression_difficulties($pdo);
            $difficultyRewardMultiplier=(float)($difficultyDefinitions[$selectedDifficulty]['rewardMultiplier']??1.0);
            $activeEvent = arcade_event_featured($pdo);
            $eventMultiplier = $activeEvent
                ? max(1.0,min(5.0,(float)$activeEvent['shard_multiplier']))
                : 1.0;
            $workshopShardMultiplier = arcade_workshop_server_shard_multiplier(
                $pdo,$customerId
            );
            $modeRewardMultiplier = $mode==='survival'
                ? max(0.5,min(5.0,((float)($rewardBalance['survivalRewardPercent'] ?? 135)) / 100.0))
                : 1.0;
            $combinedShardMultiplier = max(
                1.0,
                min(8.0,$eventMultiplier * $workshopShardMultiplier * $difficultyRewardMultiplier * $modeRewardMultiplier)
            );
            if ($combinedShardMultiplier > 1.0) {
                $bankedReward = min(
                    (int)round((int)$rewardBalance['bankedMax'] * $combinedShardMultiplier),
                    max(
                        (int)$rewardBalance['bankedMin'],
                        (int)round($bankedReward * $combinedShardMultiplier)
                    )
                );
            }

            if($mode==='gauntlet'){
                $lifetimeReward=min((int)$rewardBalance['lifetimeMax'],max(1,(int)$gauntletSummary['kills']+(int)$gauntletSummary['completedLevels']*10+(!empty($gauntletSummary['victory'])?50:0)));
                $masteryXp=max((int)$rewardBalance['masteryMin'],min((int)$rewardBalance['masteryMax'],10+intdiv((int)$gauntletSummary['kills'],2)+(int)$gauntletSummary['completedLevels']*10+(!empty($gauntletSummary['victory'])?50:0)));
            }else{
                $lifetimeReward = min(
                    (int)$rewardBalance['lifetimeMax'],
                    max(
                        1,
                        $catches
                        + $bosses * (int)$rewardBalance['bossLifetime']
                        + $outfits * (int)$rewardBalance['outfitLifetime']
                    )
                );
                $masteryXp = max(
                    (int)$rewardBalance['masteryMin'],
                    min(
                        (int)$rewardBalance['masteryMax'],
                        (int)$rewardBalance['masteryBase']
                        + $bosses * (int)$rewardBalance['masteryBoss']
                        + $outfits * (int)$rewardBalance['masteryOutfit']
                        + intdiv($catches,max(1,(int)$rewardBalance['masteryCatchDivisor']))
                        + intdiv($perfect,max(1,(int)$rewardBalance['masteryPerfectDivisor']))
                    )
                );
            }

            $pdo->prepare(
                "UPDATE arcade_player_accounts
                 SET banked_shards = banked_shards + ?,
                     lifetime_shards = lifetime_shards + ?,
                     state_version = state_version + 1,
                     last_run_at = CURRENT_TIMESTAMP
                 WHERE customer_id = ?"
            )->execute([$bankedReward, $lifetimeReward, $customerId]);

            $pdo->prepare(
                "UPDATE arcade_player_characters
                 SET mastery_xp = mastery_xp + ?,
                     runs = runs + 1,
                     bosses = bosses + ?,
                     outfits = outfits + ?,
                     catches = catches + ?,
                     perfect_catches = perfect_catches + ?,
                     max_combo = GREATEST(max_combo, ?)
                 WHERE customer_id = ? AND character_id = ?"
            )->execute([
                $masteryXp, $bosses, $outfits, $catches, $perfect, $maxCombo,
                $customerId, $characterId
            ]);

            // Deterministic run inventory rewards controlled by Game Balance Admin.
            if($mode==='gauntlet'){
                foreach(($gauntletRewardPlan['items']??[]) as $gauntletItemId=>$gauntletItemQty){
                    $gauntletItemQty=max(0,(int)$gauntletItemQty);
                    if($gauntletItemQty>0)arcade_authority_add_item($pdo,$customerId,(string)$gauntletItemId,$gauntletItemQty);
                }
            }else{
                arcade_authority_add_item(
                    $pdo,
                    $customerId,
                    'diamond-dust',
                    max(1,intdiv($score,max(1,(int)$rewardBalance['diamondDustScoreDivisor'])))
                );
                if ($mode==='runway') {
                    foreach (($runwayRewardPlan['items'] ?? []) as $runwayItemId=>$runwayItemQty) {
                        $runwayItemQty=max(0,(int)$runwayItemQty);
                        if($runwayItemQty>0) arcade_authority_add_item($pdo,$customerId,(string)$runwayItemId,$runwayItemQty);
                    }
                } else {
                    if ($catches >= (int)$rewardBalance['fabricCatchThreshold']) arcade_authority_add_item($pdo,$customerId,'fabric-roll',1);
                    if ($perfect >= (int)$rewardBalance['goldThreadPerfectThreshold']) arcade_authority_add_item($pdo,$customerId,'gold-thread',1);
                    if ($outfits >= 1) {
                        arcade_authority_add_item($pdo,$customerId,'runway-ticket',max(1,$outfits));
                        arcade_authority_add_item($pdo,$customerId,'button-set',max(1,$outfits));
                    }
                }
                if ($bosses >= 1) arcade_authority_add_item($pdo,$customerId,'boss-core',$bosses);
                foreach (($bossRewardPlan['items'] ?? []) as $bossItemId=>$bossItemQty) {
                    $bossItemQty=max(0,(int)$bossItemQty);
                    if($bossItemQty>0) arcade_authority_add_item($pdo,$customerId,(string)$bossItemId,$bossItemQty);
                }
                if ($score >= (int)$rewardBalance['dodPatchScoreThreshold']) arcade_authority_add_item($pdo,$customerId,'dod-patch',1);
            }

            // v4.6.27.4 survival catchable material drops: authenticated
            // inventory is awarded here so the next server snapshot cannot
            // overwrite a client-only DOD_INVENTORY.add().
            $awardedMaterialDrops=[];
            if($mode==='survival'&&$materialDrops){
                $dropCap=max(0,(int)($rewardBalance['survivalMaterialDropCap']??8));
                $remaining=min($dropCap,max(0,$enemyKills+$miniBossClears));
                foreach($materialDrops as $materialId=>$materialQty){
                    if($remaining<=0)break;
                    $award=min($remaining,(int)$materialQty,(int)$materialDropWhitelist[$materialId]);
                    if($award<=0)continue;
                    arcade_authority_add_item($pdo,$customerId,$materialId,$award);
                    $awardedMaterialDrops[$materialId]=$award;
                    $remaining-=$award;
                }
            }

            $gauntletState=$mode==='gauntlet'?arcade_gauntlet_apply_run($pdo,$customerId,$gauntletSummary,$bankedReward):null;

            $pdo->prepare(
                "INSERT INTO arcade_player_run_receipts
                 (customer_id, run_id, character_id, score, duration_seconds,
                  banked_reward, lifetime_reward, mastery_xp_reward, character_form)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $customerId, $runId, $characterId, $score, $duration,
                $bankedReward, $lifetimeReward, $masteryXp, $characterForm
            ]);
            $runReview = arcade_trust_record_run_review(
                $pdo,$customerId,$runId,$runMetrics,$runRisk
            );
            if ($runReview) {
                arcade_log_event(
                    $pdo,'warning','run_risk_review',
                    'Run added to trust and safety review queue.',
                    $customerId,
                    ['runId'=>$runId,'riskScore'=>$runRisk['score'],'riskLevel'=>$runRisk['level'],'reasons'=>$runRisk['reasons']]
                );
            }

            $worldBossRun=null;
            arcade_mission_apply_run($pdo,$customerId,[
                'score'=>$score,
                'duration'=>$duration,
                'catches'=>$catches,
                'perfect'=>$perfect,
                'bosses'=>$bosses,
                'outfits'=>$outfits,
                'atkKills'=>$atkKills,
                'maxCombo'=>$maxCombo,
                'mode'=>$mode,
                'eliteBosses'=>$eliteBossClears,
                'bossRushComplete'=>$bossRushComplete,
                'characterId'=>$characterId,
                'characterForm'=>$characterForm,
                'runwayGrade'=>$runwayRewardPlan['grade']??null,
                'runwayAccuracy'=>$runwayRewardPlan['accuracy']??0,
                'runwayStages'=>$runwayRewardPlan['stagesCompleted']??0,
            ]);
            arcade_character_apply_run($pdo,$customerId,[
                'characterId'=>$characterId,'maxCombo'=>$maxCombo,'bosses'=>$bosses,
                'runwayGrade'=>$runwayRewardPlan['grade']??null,
            ]);

            $progressionRun=arcade_progression_run_xp($pdo,$customerId,[
                'score'=>$score,'bosses'=>$bosses,'outfits'=>$outfits,'perfect'=>$perfect,
                'bossRushComplete'=>$bossRushComplete
            ]);
            $worldBossAccess=arcade_feature_access_status($pdo,$customerId,'worldboss');
            if(!empty($worldBossAccess['unlocked']) && !empty($runtimeFeatures['worldBoss'])) {
                $worldBossRun=arcade_community_apply_run($pdo,$customerId,$runId,[
                    'score'=>$score,'catches'=>$catches,'bosses'=>$bosses,'outfits'=>$outfits
                ]);
            }

            $growthRun=arcade_growth_apply_run($pdo,$customerId,[
                'runId'=>$runId,'score'=>$score,'duration'=>$duration,'catches'=>$catches,
                'bosses'=>$bosses,'maxCombo'=>$maxCombo,'miniBosses'=>$miniBossClears,
                'mode'=>$mode,'characterId'=>$characterId
            ]);
            $bestiaryRun=arcade_bestiary_apply_run($pdo,$customerId,[
                'runId'=>$runId,'score'=>$score,'duration'=>$duration,'maxCombo'=>$maxCombo,'mode'=>$mode,
                'enemyEncounters'=>$enemyEncounters,'enemyDefeats'=>$enemyDefeats,
                'miniBossEncounters'=>$miniBossEncounters,'miniBossDefeats'=>$miniBossDefeats,
                'bossEncounters'=>$bossEncounters,'bossRushComplete'=>$bossRushComplete,
            ]);

            $pdo->prepare(
                "INSERT INTO arcade_player_transactions
                 (customer_id, transaction_uuid, event_type, character_id,
                  shard_delta, lifetime_delta, run_id, metadata_json)
                 VALUES (?, ?, 'run_reward', ?, ?, ?, ?, ?)"
            )->execute([
                $customerId, $transactionId, $characterId,
                $bankedReward, $lifetimeReward, $runId,
                json_encode([
                    'score'=>$score,'duration'=>$duration,'catches'=>$catches,
                    'perfect'=>$perfect,'bosses'=>$bosses,'outfits'=>$outfits,
                    'atkKills'=>$atkKills,'maxCombo'=>$maxCombo,'masteryXp'=>$masteryXp,
                    'miniBossClears'=>$miniBossClears,'miniBossEncounters'=>$miniBossEncounters,'enemyKills'=>$enemyKills,'mutator'=>$mutator,
                    'enemyEncounters'=>$enemyEncounters,'enemyDefeats'=>$enemyDefeats,'bossEncounters'=>$bossEncounters,
                    'survivalRankPoints'=>(int)($bestiaryRun['pointsEarned']??0),'survivalRankUp'=>$bestiaryRun['rankUp']['key']??null,
                    'survivalRewardBreakdown'=>$rewardBreakdown,
                    'form'=>$characterForm,'mode'=>$mode,
                    'runway'=>$mode==='runway'?$runwayRewardPlan:null,
                    'gauntlet'=>$mode==='gauntlet'?$gauntletSummary:null,
                    'gauntletReward'=>$mode==='gauntlet'?$gauntletRewardPlan:null,
                    'materialDrops'=>$mode==='survival'?$awardedMaterialDrops:[],
                    'eliteBossClears'=>$eliteBossClears,'bossRushComplete'=>$bossRushComplete,'bossResults'=>$bossResults,
                    'bossRushTarget'=>$mode==='boss-rush'?(int)$bossSettings['bossRushTarget']:null,
                    'bossRushBankedBonus'=>(int)($bossRewardPlan['bankedBonus']??0),
                    'bossRushTokens'=>(int)($bossRewardPlan['rushTokens']??0),
                    'sessionLength'=>$sessionLength,
                    'characterSpecial'=>$characterSpecial,
                    'runUpgradePicks'=>$runUpgradePicks,
                    'runUpgrades'=>$runUpgrades,
                    'playerXp'=>(int)($progressionRun['xp']??0),
                    'playerLevel'=>(int)($progressionRun['levelAfter']??1),
                    'difficulty'=>(string)($progressionRun['difficulty']??'street'),
                    'eventId'=>$activeEvent ? (int)$activeEvent['id'] : null,
                    'eventMultiplier'=>$eventMultiplier,
                    'workshopShardMultiplier'=>$workshopShardMultiplier,
                    'difficultyRewardMultiplier'=>$difficultyRewardMultiplier,
                    'modeRewardMultiplier'=>$modeRewardMultiplier,
                    'combinedShardMultiplier'=>$combinedShardMultiplier,
                    'balanceRevision'=>(int)($balanceState['revision'] ?? 0),
                    'riskScore'=>(int)$runRisk['score'],
                    'riskLevel'=>(string)$runRisk['level'],
                    'riskReasons'=>$runRisk['reasons']
                ], JSON_UNESCAPED_SLASHES)
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }


        // Deliver referral notices only after the authoritative run transaction
        // commits. The Inbox schema helper can issue DDL on an older install.
        if (!empty($growthRun['referral'])) {
            try {
                $referral=$growthRun['referral'];
                arcade_social_create_system_message(
                    $pdo,(int)$referral['referrerId'],'Referral Activated',
                    'A player completed training and the required runs. '.number_format((int)$referral['referrerReward']).' Banked Shards were added to your account.',
                    'reward','/arcade.php#hub=growth','referral-'.(int)$referral['referralId'].'-owner'
                );
                arcade_social_create_system_message(
                    $pdo,$customerId,'Welcome Through a Friend',
                    'Your referral activation is complete. '.number_format((int)$referral['friendReward']).' Banked Shards were added to your account.',
                    'reward','/arcade.php#hub=growth','referral-'.(int)$referral['referralId'].'-friend'
                );
            } catch (Throwable $noticeError) {
                error_log('[ARCADE_REFERRAL_INBOX] '.$noticeError->getMessage());
            }
        }
        if (!empty($bestiaryRun['rankUp']) || !empty($bestiaryRun['rewards'])) {
            try {
                $rewardTotal=array_sum(array_map(static fn(array $row):int=>(int)($row['shards']??0),$bestiaryRun['rewards']??[]));
                $title=!empty($bestiaryRun['rankUp'])?'Survival Rank Advanced':'Bestiary Reward Unlocked';
                $body=!empty($bestiaryRun['rankUp'])
                    ? 'You reached '.(string)$bestiaryRun['rankUp']['name'].' rank.'.($rewardTotal>0?' '.number_format($rewardTotal).' Banked Shards were added from rank and collection rewards.':'')
                    : number_format($rewardTotal).' Banked Shards were added from new Bestiary discoveries.';
                arcade_social_create_system_message($pdo,$customerId,$title,$body,'reward','/arcade.php#hub=bestiary','bestiary-'.$runId);
            } catch (Throwable $noticeError) { error_log('[ARCADE_BESTIARY_INBOX] '.$noticeError->getMessage()); }
        }

        echo json_encode([
            'success'=>true,
            'message'=>'Run rewards saved.',
            'rewards'=>[
                'bankedShards'=>$bankedReward,
                'lifetimeShards'=>$lifetimeReward,
                'masteryXp'=>$masteryXp,
                'bossItems'=>$bossRewardPlan['items'] ?? [],
                'playerXp'=>(int)($progressionRun['xp']??0),
                'playerLevel'=>(int)($progressionRun['levelAfter']??1),
                'difficulty'=>(string)($progressionRun['difficulty']??'street'),
                'breakdown'=>$rewardBreakdown,
                'runway'=>$mode==='runway'?$runwayRewardPlan:null,
                'gauntlet'=>$mode==='gauntlet'?array_merge($gauntletRewardPlan,['bankedShards'=>$bankedReward]):null,
                'materialDrops'=>$mode==='survival'?$awardedMaterialDrops:[],
            ],
            'state'=>$duplicateState(),
            'runId'=>$runId,
            'gauntlet'=>$mode==='gauntlet'?$gauntletState:null,
            'achievements'=>arcade_achievements_sync($pdo,$customerId),
            'dailyLogin'=>arcade_daily_login_status($pdo,$customerId),
            'missions'=>arcade_mission_player_status($pdo,$customerId),
            'progression'=>$progressionRun['state']??arcade_progression_state($pdo,$customerId),
            'masteryFashion'=>arcade_mastery_fashion_player_state($pdo,$customerId),
            'event'=>isset($activeEvent) && $activeEvent ? arcade_event_public($activeEvent) : null,
            'worldBossRun'=>$worldBossRun,
            'runReview'=>$runReview,
            'shareChallenge'=>$growthRun['challenge'] ?? null,
            'challengeBeat'=>$growthRun['challengeBeat'] ?? null,
            'referralActivation'=>$growthRun['referral'] ?? null,
            'growth'=>arcade_growth_state($pdo,$customerId),
            'bestiary'=>$bestiaryRun['state']??arcade_bestiary_state($pdo,$customerId),
            'characterIdentity'=>arcade_character_identity_state($pdo,$customerId),
            'bestiaryRewards'=>$bestiaryRun['rewards']??[],
            'survivalRankUp'=>$bestiaryRun['rankUp']??null,
            'survivalRankPoints'=>(int)($bestiaryRun['pointsEarned']??0),
            'community'=>arcade_community_state($pdo,$customerId),
            'trust'=>arcade_trust_player_state($pdo,$customerId),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'claim_story_quest') {
        $questKey = trim((string)($input['questKey'] ?? ''));
        if (!preg_match('/^[a-z0-9_-]{3,80}$/',$questKey)) {
            throw new InvalidArgumentException('Invalid story quest.');
        }
        $story = arcade_story_claim(
            $pdo,$customerId,$questKey,$transactionId
        );
        echo json_encode([
            'success'=>true,
            'message'=>'Story quest reward claimed.',
            'state'=>$duplicateState(),
            'storyQuests'=>$story,
            'workshop'=>arcade_workshop_state($pdo,$customerId),
            'achievements'=>arcade_achievements_sync($pdo,$customerId),
        ],JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'craft_workshop_item') {
        $recipeId = trim((string)($input['recipeId'] ?? ''));
        $workshop = arcade_workshop_craft(
            $pdo,$customerId,$recipeId,$transactionId
        );
        echo json_encode([
            'success'=>true,
            'message'=>'Workshop item crafted.',
            'state'=>$duplicateState(),
            'workshop'=>$workshop,
            'storyQuests'=>arcade_story_state($pdo,$customerId),
            'masteryFashion'=>arcade_mastery_fashion_player_state($pdo,$customerId),
        ],JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'equip_workshop_item') {
        $recipeId = trim((string)($input['recipeId'] ?? ''));
        $workshop = arcade_workshop_equip(
            $pdo,$customerId,$recipeId,$transactionId
        );
        echo json_encode([
            'success'=>true,
            'message'=>'Workshop item equipped.',
            'state'=>$duplicateState(),
            'workshop'=>$workshop,
            'storyQuests'=>arcade_story_state($pdo,$customerId),
        ],JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'unequip_workshop_slot') {
        $slot = trim((string)($input['slot'] ?? ''));
        $workshop = arcade_workshop_unequip(
            $pdo,$customerId,$slot,$transactionId
        );
        echo json_encode([
            'success'=>true,
            'message'=>'Workshop slot cleared.',
            'state'=>$duplicateState(),
            'workshop'=>$workshop,
            'storyQuests'=>arcade_story_state($pdo,$customerId),
        ],JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'claim_mission') {
        $missionId = (int)($input['missionId'] ?? 0);
        $periodKey = trim((string)($input['periodKey'] ?? ''));
        if ($missionId <= 0 || !preg_match('/^(daily|weekly|event):[A-Za-z0-9._:-]{1,50}$/',$periodKey)) {
            throw new InvalidArgumentException('Invalid mission reward.');
        }
        $missions = arcade_mission_claim(
            $pdo,$customerId,$missionId,$periodKey,$transactionId
        );
        echo json_encode([
            'success'=>true,
            'message'=>'Mission reward claimed.',
            'state'=>$duplicateState(),
            'missions'=>$missions,
            'progression'=>arcade_progression_state($pdo,$customerId),
            'achievements'=>arcade_achievements_sync($pdo,$customerId),
        ],JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'claim_mission_bonus') {
        $periodType = strtolower(trim((string)($input['periodType'] ?? '')));
        $periodKey = trim((string)($input['periodKey'] ?? ''));
        $missions = arcade_mission_claim_bonus(
            $pdo,$customerId,$periodType,$periodKey,$transactionId
        );
        echo json_encode([
            'success'=>true,
            'message'=>ucfirst($periodType).' mission bonus claimed.',
            'state'=>$duplicateState(),
            'missions'=>$missions,
            'progression'=>arcade_progression_state($pdo,$customerId),
            'achievements'=>arcade_achievements_sync($pdo,$customerId),
        ],JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'claim_daily_login') {
        $dailyLogin = arcade_daily_login_claim($pdo,$customerId,$transactionId);
        echo json_encode([
            'success'=>true,
            'message'=>'Daily login reward claimed.',
            'state'=>$duplicateState(),
            'dailyLogin'=>$dailyLogin,
            'achievements'=>arcade_achievements_sync($pdo,$customerId),
        ],JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'claim_achievement') {
        $achievementKey = trim((string)($input['achievementKey'] ?? ''));
        if (!preg_match('/^[a-z0-9_:-]{3,80}$/',$achievementKey)) {
            throw new InvalidArgumentException('Invalid achievement.');
        }
        $achievements = arcade_achievement_claim(
            $pdo,$customerId,$achievementKey,$transactionId
        );
        echo json_encode([
            'success'=>true,
            'message'=>'Achievement reward claimed.',
            'state'=>$duplicateState(),
            'achievements'=>$achievements,
            'dailyLogin'=>arcade_daily_login_status($pdo,$customerId),
        ],JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'claim_season_reward') {
        $seasonId = (int)($input['seasonId'] ?? 0);
        if ($seasonId <= 0) throw new InvalidArgumentException('Missing season.');
        arcade_leaderboard_active_season($pdo); // lazily finalize an expired active season

        $seasonStmt = $pdo->prepare(
            "SELECT id, name, starts_at, ends_at, status FROM arcade_seasons WHERE id = ? LIMIT 1"
        );
        $seasonStmt->execute([$seasonId]);
        $season = $seasonStmt->fetch(PDO::FETCH_ASSOC);
        if (!$season) throw new InvalidArgumentException('Season not found.');
        if (!in_array($season['status'], ['ended', 'archived'], true)) {
            throw new DomainException('This season has not ended yet.');
        }

        $pdo->beginTransaction();
        try {
            $dup = $pdo->prepare(
                "SELECT id FROM arcade_player_transactions
                 WHERE customer_id = ? AND transaction_uuid = ? LIMIT 1"
            );
            $dup->execute([$customerId, $transactionId]);
            if ($dup->fetchColumn()) {
                $pdo->commit();
                echo json_encode(['success'=>true,'duplicate'=>true,'state'=>$duplicateState()]);
                exit;
            }

            $alreadyClaimed = $pdo->prepare(
                "SELECT 1 FROM arcade_season_reward_claims WHERE customer_id = ? AND season_id = ? LIMIT 1"
            );
            $alreadyClaimed->execute([$customerId, $seasonId]);
            if ($alreadyClaimed->fetchColumn()) {
                throw new DomainException('You already claimed a reward for this season.');
            }

            $myRank = arcade_leaderboard_player_rank($pdo, $customerId, 'season', $season, []);
            if ($myRank === null) throw new DomainException('You did not place on this season\'s leaderboard.');

            $rewardStmt = $pdo->prepare(
                "SELECT id, reward_label, item_id, item_quantity, banked_shards
                 FROM arcade_season_rewards
                 WHERE season_id = ? AND rank_start <= ? AND rank_end >= ?
                 ORDER BY rank_start ASC LIMIT 1"
            );
            $rewardStmt->execute([$seasonId, $myRank, $myRank]);
            $reward = $rewardStmt->fetch(PDO::FETCH_ASSOC);
            if (!$reward) throw new DomainException('No reward tier for your final rank.');

            $pdo->prepare(
                "INSERT INTO arcade_season_reward_claims (customer_id, season_id, reward_id, transaction_uuid)
                 VALUES (?, ?, ?, ?)"
            )->execute([$customerId, $seasonId, $reward['id'], $transactionId]);

            $banked = (int)$reward['banked_shards'];
            if ($banked > 0) {
                $pdo->prepare(
                    "UPDATE arcade_player_accounts
                     SET banked_shards = banked_shards + ?, state_version = state_version + 1
                     WHERE customer_id = ?"
                )->execute([$banked, $customerId]);
            }

            $itemId = (string)($reward['item_id'] ?? '');
            $itemQty = (int)($reward['item_quantity'] ?? 0);
            if ($itemId !== '' && $itemQty > 0) {
                arcade_authority_add_item($pdo, $customerId, $itemId, $itemQty);
            }

            $pdo->prepare(
                "INSERT INTO arcade_player_transactions
                 (customer_id, transaction_uuid, event_type, shard_delta, item_id, item_quantity, metadata_json)
                 VALUES (?, ?, 'season_reward', ?, ?, ?, ?)"
            )->execute([
                $customerId, $transactionId, $banked,
                $itemId !== '' ? $itemId : null, $itemQty,
                json_encode(['seasonId'=>$seasonId,'rank'=>$myRank,'label'=>$reward['reward_label']], JSON_UNESCAPED_SLASHES)
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        echo json_encode([
            'success'=>true,
            'message'=>'Season reward claimed.',
            'state'=>$duplicateState(),
            'seasonRewards'=>arcade_leaderboard_player_season_rewards($pdo,$customerId),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'claim_inbox_message') {
        $messageId=(int)($input['messageId'] ?? 0);
        if ($messageId<=0) throw new InvalidArgumentException('Invalid inbox message.');
        $inbox=arcade_social_claim_inbox($pdo,$customerId,$messageId,$transactionId);
        echo json_encode(['success'=>true,'message'=>'Inbox reward claimed.','state'=>$duplicateState(),'progression'=>arcade_progression_state($pdo,$customerId),'social'=>['inbox'=>$inbox,'profile'=>arcade_social_profile_state($pdo,$customerId)]],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit;
    }
    if ($action === 'read_inbox_message' || $action === 'dismiss_inbox_message') {
        $messageId=(int)($input['messageId'] ?? 0);
        if ($messageId<=0) throw new InvalidArgumentException('Invalid inbox message.');
        $inbox=arcade_social_mark_inbox($pdo,$customerId,$messageId,$action==='dismiss_inbox_message'?'dismiss':'read');
        echo json_encode(['success'=>true,'message'=>$action==='dismiss_inbox_message'?'Inbox message dismissed.':'Inbox message marked as read.','social'=>['inbox'=>$inbox,'profile'=>arcade_social_profile_state($pdo,$customerId)]],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit;
    }
    if ($action === 'mark_all_inbox_read') {
        $inbox=arcade_social_mark_all_inbox_read($pdo,$customerId);
        echo json_encode(['success'=>true,'message'=>'All active inbox messages marked as read.','social'=>['inbox'=>$inbox,'profile'=>arcade_social_profile_state($pdo,$customerId)]],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit;
    }
    if ($action === 'save_arcade_profile') {
        $profileInput=is_array($input['profile'] ?? null)?$input['profile']:[];
        $profile=arcade_social_save_profile($pdo,$customerId,$profileInput,$transactionId);
        echo json_encode(['success'=>true,'message'=>'Arcade profile updated.','social'=>['inbox'=>arcade_social_inbox_state($pdo,$customerId),'profile'=>$profile]],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit;
    }

    if ($action === 'submit_player_report') {
        $trust=arcade_trust_submit_report(
            $pdo,$customerId,is_array($input['report']??null)?$input['report']:[],$transactionId
        );
        echo json_encode(['success'=>true,'message'=>'Report submitted for administrator review.','trust'=>$trust],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit;
    }

    if ($action === 'community_friend_request') {
        $community=arcade_community_friend_request($pdo,$customerId,(string)($input['arcadeName']??''),$transactionId);
        echo json_encode(['success'=>true,'message'=>'Friend request updated.','community'=>array_merge(arcade_community_state($pdo,$customerId),['friends'=>$community])],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'community_friend_respond') {
        $community=arcade_community_friend_respond($pdo,$customerId,(int)($input['friendshipId']??0),(string)($input['decision']??''),$transactionId);
        echo json_encode(['success'=>true,'message'=>'Friend request updated.','community'=>array_merge(arcade_community_state($pdo,$customerId),['friends'=>$community])],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'community_friend_remove') {
        $community=arcade_community_friend_remove($pdo,$customerId,(int)($input['friendshipId']??0),$transactionId);
        echo json_encode(['success'=>true,'message'=>'Friend connection removed.','community'=>array_merge(arcade_community_state($pdo,$customerId),['friends'=>$community])],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'community_guild_create') {
        $guild=arcade_community_guild_create($pdo,$customerId,is_array($input['guild']??null)?$input['guild']:[],$transactionId);
        echo json_encode(['success'=>true,'message'=>'Guild created.','state'=>$duplicateState(),'community'=>array_merge(arcade_community_state($pdo,$customerId),['guild'=>$guild])],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'community_guild_join') {
        $guild=arcade_community_guild_join($pdo,$customerId,(int)($input['guildId']??0),$transactionId);
        echo json_encode(['success'=>true,'message'=>'Guild joined.','community'=>array_merge(arcade_community_state($pdo,$customerId),['guild'=>$guild])],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'community_guild_invite') {
        $guild=arcade_community_guild_invite($pdo,$customerId,(string)($input['arcadeName']??''),$transactionId);
        echo json_encode(['success'=>true,'message'=>'Guild invitation sent.','community'=>array_merge(arcade_community_state($pdo,$customerId),['guild'=>$guild])],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'community_guild_accept_invite') {
        $guild=arcade_community_guild_accept_invite($pdo,$customerId,(int)($input['inviteId']??0),$transactionId);
        echo json_encode(['success'=>true,'message'=>'Guild invitation accepted.','community'=>array_merge(arcade_community_state($pdo,$customerId),['guild'=>$guild])],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'community_guild_contribute') {
        $guild=arcade_community_guild_contribute($pdo,$customerId,(int)($input['amount']??0),$transactionId);
        echo json_encode(['success'=>true,'message'=>'Guild contribution recorded.','state'=>$duplicateState(),'community'=>array_merge(arcade_community_state($pdo,$customerId),['guild'=>$guild])],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'community_guild_leave') {
        $guild=arcade_community_guild_leave($pdo,$customerId,$transactionId);
        echo json_encode(['success'=>true,'message'=>'Guild membership updated.','community'=>array_merge(arcade_community_state($pdo,$customerId),['guild'=>$guild])],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'community_trade_create') {
        $trades=arcade_community_trade_create($pdo,$customerId,is_array($input['trade']??null)?$input['trade']:[],$transactionId);
        echo json_encode(['success'=>true,'message'=>'Trade offer placed in server escrow.','state'=>$duplicateState(),'community'=>array_merge(arcade_community_state($pdo,$customerId),['trades'=>$trades])],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'community_trade_accept') {
        $trades=arcade_community_trade_accept($pdo,$customerId,(int)($input['tradeId']??0),$transactionId);
        echo json_encode(['success'=>true,'message'=>'Trade completed.','state'=>$duplicateState(),'community'=>array_merge(arcade_community_state($pdo,$customerId),['trades'=>$trades])],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'community_trade_close') {
        $trades=arcade_community_trade_close($pdo,$customerId,(int)($input['tradeId']??0),(string)($input['decision']??''),$transactionId);
        echo json_encode(['success'=>true,'message'=>'Trade offer closed and escrow returned.','state'=>$duplicateState(),'community'=>array_merge(arcade_community_state($pdo,$customerId),['trades'=>$trades])],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'community_world_boss_claim') {
        $worldBoss=arcade_community_world_boss_claim($pdo,$customerId,(int)($input['bossId']??0),$transactionId);
        echo json_encode(['success'=>true,'message'=>'World Boss reward claimed.','state'=>$duplicateState(),'community'=>array_merge(arcade_community_state($pdo,$customerId),['worldBoss'=>$worldBoss])],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }

    if ($action === 'chat_send') {
        $marketChat=['chat'=>arcade_market_chat_send($pdo,$customerId,is_array($input['message']??null)?$input['message']:[],$transactionId),'marketplace'=>arcade_marketplace_state($pdo,$customerId)];
        echo json_encode(['success'=>true,'message'=>'Message sent.','marketChat'=>$marketChat],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'chat_mute') {
        $chat=arcade_market_chat_mute($pdo,$customerId,(string)($input['arcadeName']??''),(int)($input['hours']??168),$transactionId);
        echo json_encode(['success'=>true,'message'=>'Player muted.','marketChat'=>['chat'=>$chat,'marketplace'=>arcade_marketplace_state($pdo,$customerId)]],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'chat_unmute') {
        $chat=arcade_market_chat_unmute($pdo,$customerId,(int)($input['customerId']??0),$transactionId);
        echo json_encode(['success'=>true,'message'=>'Player unmuted.','marketChat'=>['chat'=>$chat,'marketplace'=>arcade_marketplace_state($pdo,$customerId)]],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'chat_delete_message') {
        $chat=arcade_market_chat_delete_message($pdo,$customerId,(int)($input['messageId']??0),$transactionId);
        echo json_encode(['success'=>true,'message'=>'Message removed.','marketChat'=>['chat'=>$chat,'marketplace'=>arcade_marketplace_state($pdo,$customerId)]],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'chat_mark_read') {
        $chat=arcade_market_chat_mark_read($pdo,$customerId,(string)($input['channelKey']??''),(int)($input['messageId']??0));
        echo json_encode(['success'=>true,'message'=>'Chat marked read.','marketChat'=>['chat'=>$chat,'marketplace'=>arcade_marketplace_state($pdo,$customerId)]],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'market_listing_create') {
        $market=arcade_marketplace_create($pdo,$customerId,is_array($input['listing']??null)?$input['listing']:[],$transactionId);
        echo json_encode(['success'=>true,'message'=>'Marketplace listing created. Items are now in server escrow.','state'=>$duplicateState(),'marketChat'=>['chat'=>arcade_market_chat_state($pdo,$customerId),'marketplace'=>$market]],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'market_listing_purchase') {
        $listingId=(int)($input['listingId']??0);
        $market=arcade_marketplace_purchase($pdo,$customerId,$listingId,$transactionId);
        try { arcade_social_record_purchase_notice($pdo,$customerId,$transactionId,'Marketplace Purchase Complete','Marketplace listing #'.number_format($listingId).' was purchased and delivered to your server inventory.','/arcade.php#hub=marketplace'); } catch(Throwable $noticeError){ error_log('[ARCADE_PURCHASE_INBOX] '.$noticeError->getMessage()); }
        echo json_encode(['success'=>true,'message'=>'Marketplace purchase completed.','state'=>$duplicateState(),'social'=>arcade_social_state($pdo,$customerId),'marketChat'=>['chat'=>arcade_market_chat_state($pdo,$customerId),'marketplace'=>$market]],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'market_listing_cancel') {
        $market=arcade_marketplace_cancel($pdo,$customerId,(int)($input['listingId']??0),$transactionId);
        echo json_encode(['success'=>true,'message'=>'Listing cancelled and escrowed items returned.','state'=>$duplicateState(),'marketChat'=>['chat'=>arcade_market_chat_state($pdo,$customerId),'marketplace'=>$market]],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }


    if ($action === 'upgrade_lab_sync') {
        $legacy=is_array($input['legacyState'] ?? null) ? $input['legacyState'] : null;
        $upgradeLab=arcade_upgrade_lab_state($pdo,$customerId,$legacy);
        echo json_encode([
            'success'=>true,
            'message'=>'Upgrade Lab synchronized with the server wallet.',
            'upgradeLab'=>$upgradeLab,
            'state'=>$duplicateState(),
        ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'upgrade_lab_purchase') {
        $upgradeId=trim((string)($input['upgradeId'] ?? ''));
        $legacy=is_array($input['legacyState'] ?? null) ? $input['legacyState'] : null;
        $purchase=arcade_upgrade_lab_purchase($pdo,$customerId,$upgradeId,$transactionId,$legacy);
        echo json_encode([
            'success'=>true,
            'duplicate'=>(bool)($purchase['duplicate'] ?? false),
            'message'=>!empty($purchase['duplicate']) ? 'Upgrade purchase already verified.' : 'Upgrade purchased.',
            'purchase'=>$purchase,
            'upgradeLab'=>$purchase['state'] ?? arcade_upgrade_lab_state($pdo,$customerId),
            'state'=>$duplicateState(),
        ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'character_build_save') {
        $identity=arcade_character_save_build($pdo,$customerId,$input,$transactionId);
        echo json_encode(['success'=>true,'message'=>'Character build saved and equipped.','state'=>$duplicateState(),'characterIdentity'=>$identity],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'character_mission_claim') {
        $identity=arcade_character_claim_mission($pdo,$customerId,(string)($input['missionKey']??''),$transactionId);
        echo json_encode(['success'=>true,'message'=>'Character mission reward claimed.','state'=>$duplicateState(),'characterIdentity'=>$identity],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'ghost_save') {
        $saved=arcade_ghost_save($pdo,$customerId,$input,$transactionId);
        echo json_encode(['success'=>true,'message'=>'Verified Ghost saved.','characterIdentity'=>$saved['state'],'ghostId'=>$saved['ghostId']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'ghost_load') {
        $ghost=arcade_ghost_load($pdo,$customerId,(int)($input['ghostId']??0));
        echo json_encode(['success'=>true,'message'=>'Ghost loaded.','ghost'=>$ghost],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'ghost_result') {
        $result=arcade_ghost_result($pdo,$customerId,(string)($input['runId']??''),(int)($input['ghostId']??0),$transactionId);
        echo json_encode(['success'=>true,'message'=>$result['won']?'Ghost defeated.':'Ghost result verified.','won'=>$result['won'],'characterIdentity'=>$result['state']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'push_subscribe') {
        $push=arcade_push_subscribe($pdo,$customerId,is_array($input['subscription']??null)?$input['subscription']:[]);
        echo json_encode(['success'=>true,'message'=>'Notifications enabled on this device.','push'=>$push,'characterIdentity'=>arcade_character_identity_state($pdo,$customerId)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'push_unsubscribe') {
        $push=arcade_push_unsubscribe($pdo,$customerId,(string)($input['endpoint']??''));
        echo json_encode(['success'=>true,'message'=>'Notifications disabled on this device.','push'=>$push,'characterIdentity'=>arcade_character_identity_state($pdo,$customerId)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'push_preferences_save') {
        $push=arcade_push_preferences_save($pdo,$customerId,is_array($input['preferences']??null)?$input['preferences']:[]);
        echo json_encode(['success'=>true,'message'=>'Notification preferences saved.','push'=>$push,'characterIdentity'=>arcade_character_identity_state($pdo,$customerId)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'push_test') {
        $helper=ARCADE_SITE_ROOT.'/includes/arcade_push_notify.php';if(is_file($helper))require_once $helper;
        if(!function_exists('arcade_push_notify_customer'))throw new DomainException('Push sender is unavailable.');
        arcade_push_notify_customer($pdo,$customerId,'Diamonds Outta Dirt Arcade','Test notification delivered. Open Player Hub to review your settings.','/arcade.php#hub=notifications','arcade-test',true);
        echo json_encode(['success'=>true,'message'=>'Test notification requested.','push'=>arcade_push_state($pdo,$customerId)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }

    throw new InvalidArgumentException('Unknown arcade action.');
} catch (DomainException $e) {
    arcade_log_rejection($pdo ?? null, $action ?? 'unknown_action', $e, $customerId ?? null, ['characterId' => $input['characterId'] ?? null]);
    http_response_code(409);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
} catch (InvalidArgumentException $e) {
    arcade_log_rejection($pdo ?? null, $action ?? 'unknown_action', $e, $customerId ?? null, ['characterId' => $input['characterId'] ?? null]);
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('[ARCADE_PLAYER_ACTION] ' . $e->getMessage());
    arcade_log_event($pdo ?? null, 'error', $action ?? 'unknown_action', $e->getMessage(), $customerId ?? null);
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Unable to complete that arcade action.']);
}
