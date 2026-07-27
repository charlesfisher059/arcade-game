<?php
declare(strict_types=1);

require __DIR__ . '/arcade_app/bootstrap.php';
require_once ARCADE_APP_PATH . '/includes/cloud_identity.php';
require_once ARCADE_APP_PATH . '/includes/arcade_schema_manager.php';
require_once ARCADE_APP_PATH . '/includes/player_authority.php';
require_once ARCADE_APP_PATH . '/includes/arcade_progression.php';
require_once ARCADE_APP_PATH . '/includes/arcade_diagnostics.php';
require_once ARCADE_APP_PATH . '/includes/arcade_logger.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function arcade_continue_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    arcade_continue_json(405, ['success'=>false, 'message'=>'POST required.']);
}

$customerId = arcade_authority_customer_id();
if (!$customerId) {
    arcade_continue_json(401, ['success'=>false, 'authenticated'=>false, 'message'=>'Log in again to use Banked Shards.']);
}

$csrf = (string)($_SERVER['HTTP_X_ARCADE_CSRF'] ?? '');
if ($csrf === '' || !hash_equals(arcade_cloud_csrf_token(), $csrf)) {
    arcade_continue_json(403, ['success'=>false, 'message'=>'Your arcade session expired. Refresh the page and try again.']);
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    arcade_continue_json(503, ['success'=>false, 'message'=>'The arcade database is temporarily unavailable.']);
}

if (!arcade_security_guard_session($pdo, $customerId)) {
    arcade_continue_json(401, ['success'=>false, 'authenticated'=>false, 'message'=>'This arcade session was signed out. Log in again.']);
}

if (arcade_maintenance_mode_enabled($pdo)) {
    arcade_continue_json(503, ['success'=>false, 'maintenance'=>true, 'message'=>arcade_maintenance_message($pdo)]);
}

try {
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 8192) {
        throw new InvalidArgumentException('Invalid continue request.');
    }
    $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new InvalidArgumentException('Invalid continue request.');

    $runId = trim((string)($input['runId'] ?? ''));
    $transactionId = trim((string)($input['transactionId'] ?? ''));
    $mode = strtolower(trim((string)($input['mode'] ?? '')));
    $isGauntlet = $mode === 'gauntlet';
    $reviveNumber = $isGauntlet ? (int)($input['reviveNumber'] ?? 0) : 1;

    if (!preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $runId)) {
        throw new InvalidArgumentException('The current run could not be identified. Start a new run and try again.');
    }
    if (!arcade_authority_valid_transaction_id($transactionId)) {
        throw new InvalidArgumentException('Invalid continue transaction.');
    }
    if ($isGauntlet && ($reviveNumber < 1 || $reviveNumber > 100)) {
        throw new InvalidArgumentException('Invalid Gauntlet revive number.');
    }

    arcade_schema_ensure($pdo);
    arcade_authority_ensure_player($pdo, $customerId, 'customer_id:' . $customerId);
    arcade_schema_repair_continue_purchase_schema($pdo);

    $progression = arcade_progression_state($pdo, $customerId);
    $difficulty = (string)($progression['selectedDifficulty'] ?? 'street');
    $profiles = arcade_progression_difficulties($pdo);
    $profile = $profiles[$difficulty] ?? ($profiles['street'] ?? []);
    $baseCost = max(0, min(10000, (int)($profile['continueCost'] ?? 70)));
    $cost = $isGauntlet
        ? max(0, min(10000, $baseCost * $reviveNumber))
        : $baseCost;

    // Gauntlet revives use a compact per-run hash plus the revive number as the
    // receipt key. Other Arcade modes retain the original one-continue-per-run
    // behavior and their existing run-ID idempotency key.
    $gauntletPrefix = $isGauntlet ? ('g:' . substr(hash('sha256', $runId), 0, 40) . ':r:') : '';
    $purchaseRunId = $isGauntlet ? ($gauntletPrefix . $reviveNumber) : $runId;

    $pdo->beginTransaction();
    try {
        $existingRow = null;

        if ($isGauntlet) {
            // Lock all paid Gauntlet revives already recorded for this run and
            // enforce a strict 1,2,3... sequence. This prevents a client from
            // skipping straight to a different receipt key or replaying a lower
            // price after a higher revive has already been purchased.
            $prior = $pdo->prepare(
                'SELECT run_id, shard_cost, difficulty FROM arcade_run_continue_purchases
                 WHERE customer_id = ? AND run_id LIKE ? FOR UPDATE'
            );
            $prior->execute([$customerId, $gauntletPrefix . '%']);
            $maxPaidRevive = 0;
            foreach ($prior->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $savedRunId = (string)($row['run_id'] ?? '');
                if (!str_starts_with($savedRunId, $gauntletPrefix)) continue;
                $savedNumber = (int)substr($savedRunId, strlen($gauntletPrefix));
                if ($savedNumber < 1) continue;
                $maxPaidRevive = max($maxPaidRevive, $savedNumber);
                if ($savedNumber === $reviveNumber) $existingRow = $row;
            }

            // A run that bought its first revive before this update used the
            // plain run ID. Treat that legacy receipt as revive #1 so an active
            // run can continue into the escalating ladder without a second fee.
            $legacy = $pdo->prepare(
                'SELECT run_id, shard_cost, difficulty FROM arcade_run_continue_purchases
                 WHERE customer_id = ? AND run_id = ? LIMIT 1 FOR UPDATE'
            );
            $legacy->execute([$customerId, $runId]);
            $legacyRow = $legacy->fetch(PDO::FETCH_ASSOC);
            if (is_array($legacyRow)) {
                $maxPaidRevive = max($maxPaidRevive, 1);
                if ($reviveNumber === 1 && !is_array($existingRow)) $existingRow = $legacyRow;
            }

            if (is_array($existingRow)) {
                $savedCost = (int)($existingRow['shard_cost'] ?? $cost);
                $pdo->commit();
                arcade_continue_json(200, [
                    'success'=>true,
                    'duplicate'=>true,
                    'mode'=>'gauntlet',
                    'reviveNumber'=>$reviveNumber,
                    'message'=>'Gauntlet revive already verified. Resuming this run.',
                    'baseCost'=>$baseCost,
                    'cost'=>$savedCost,
                    'nextCost'=>max(0, min(10000, $baseCost * ($reviveNumber + 1))),
                    'state'=>arcade_authority_state($pdo, $customerId),
                ]);
            }

            $expectedRevive = $maxPaidRevive + 1;
            if ($reviveNumber !== $expectedRevive) {
                throw new DomainException(
                    $reviveNumber < $expectedRevive
                        ? 'That Gauntlet revive was already used.'
                        : 'The Gauntlet revive sequence changed. Reopen the revive screen and try again.'
                );
            }
        } else {
            // Legacy behavior for Survival and all non-Gauntlet modes.
            $existing = $pdo->prepare(
                'SELECT shard_cost, difficulty FROM arcade_run_continue_purchases
                 WHERE customer_id = ? AND run_id = ? LIMIT 1 FOR UPDATE'
            );
            $existing->execute([$customerId, $runId]);
            $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
            if (is_array($existingRow)) {
                $pdo->commit();
                arcade_continue_json(200, [
                    'success'=>true,
                    'duplicate'=>true,
                    'message'=>'Continue already verified. Resuming this run.',
                    'cost'=>(int)($existingRow['shard_cost'] ?? $cost),
                    'state'=>arcade_authority_state($pdo, $customerId),
                ]);
            }
        }

        $account = $pdo->prepare(
            'SELECT banked_shards FROM arcade_player_accounts WHERE customer_id = ? LIMIT 1 FOR UPDATE'
        );
        $account->execute([$customerId]);
        $bankedRaw = $account->fetchColumn();
        if ($bankedRaw === false) throw new RuntimeException('Arcade wallet could not be loaded.');
        $banked = max(0, (int)$bankedRaw);
        if ($banked < $cost) throw new DomainException('Not enough Banked Shards.');

        if ($cost > 0) {
            $charge = $pdo->prepare(
                'UPDATE arcade_player_accounts
                 SET banked_shards = banked_shards - ?, state_version = state_version + 1
                 WHERE customer_id = ? AND banked_shards >= ?'
            );
            $charge->execute([$cost, $customerId, $cost]);
            if ($charge->rowCount() !== 1) throw new DomainException('Not enough Banked Shards.');
        }

        $receipt = $pdo->prepare(
            'INSERT INTO arcade_run_continue_purchases
             (customer_id, run_id, transaction_uuid, shard_cost, difficulty)
             VALUES (?, ?, ?, ?, ?)'
        );
        $receipt->execute([$customerId, $purchaseRunId, $transactionId, $cost, $difficulty]);

        // The dedicated receipt above is authoritative. Keep the general
        // transaction ledger as a best-effort analytics record so an older live
        // ledger schema cannot prevent a valid revive purchase.
        try {
            $columns = ['customer_id','transaction_uuid','event_type','shard_delta'];
            $values = [$customerId, $transactionId, $isGauntlet ? 'gauntlet_revive_purchase' : 'continue_purchase', -$cost];
            if (arcade_schema_column_exists($pdo, 'arcade_player_transactions', 'run_id')) {
                $columns[] = 'run_id';
                $values[] = $runId;
            }
            if (arcade_schema_column_exists($pdo, 'arcade_player_transactions', 'metadata_json')) {
                $columns[] = 'metadata_json';
                $values[] = json_encode([
                    'difficulty'=>$difficulty,
                    'mode'=>$isGauntlet ? 'gauntlet' : 'arcade',
                    'reviveNumber'=>$isGauntlet ? $reviveNumber : 1,
                    'baseCost'=>$baseCost,
                    'cost'=>$cost,
                    'purchaseRunId'=>$purchaseRunId,
                ], JSON_UNESCAPED_SLASHES);
            }
            $placeholders = implode(',', array_fill(0, count($columns), '?'));
            $ledger = $pdo->prepare(
                'INSERT INTO arcade_player_transactions (' . implode(',', $columns) . ') VALUES (' . $placeholders . ')'
            );
            $ledger->execute($values);
        } catch (Throwable $ledgerError) {
            error_log('[ARCADE_CONTINUE_LEDGER_OPTIONAL] ' . $ledgerError->getMessage());
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((string)$e->getCode() === '23000') {
            // A simultaneous retry may have committed first. Confirm using the
            // exact purchase receipt key and return success without charging.
            $check = $pdo->prepare(
                'SELECT shard_cost FROM arcade_run_continue_purchases WHERE customer_id = ? AND run_id = ? LIMIT 1'
            );
            $check->execute([$customerId, $purchaseRunId]);
            $savedCost = $check->fetchColumn();
            if ($savedCost !== false) {
                arcade_continue_json(200, [
                    'success'=>true,
                    'duplicate'=>true,
                    'mode'=>$isGauntlet ? 'gauntlet' : 'arcade',
                    'reviveNumber'=>$isGauntlet ? $reviveNumber : 1,
                    'message'=>$isGauntlet ? 'Gauntlet revive already verified. Resuming this run.' : 'Continue already verified. Resuming this run.',
                    'baseCost'=>$baseCost,
                    'cost'=>(int)$savedCost,
                    'nextCost'=>$isGauntlet ? max(0, min(10000, $baseCost * ($reviveNumber + 1))) : $baseCost,
                    'state'=>arcade_authority_state($pdo, $customerId),
                ]);
            }
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    arcade_continue_json(200, [
        'success'=>true,
        'mode'=>$isGauntlet ? 'gauntlet' : 'arcade',
        'reviveNumber'=>$isGauntlet ? $reviveNumber : 1,
        'message'=>$isGauntlet ? 'Banked Shards verified. Gauntlet revive accepted.' : 'Banked Shards verified. Resuming the run.',
        'baseCost'=>$baseCost,
        'cost'=>$cost,
        'nextCost'=>$isGauntlet ? max(0, min(10000, $baseCost * ($reviveNumber + 1))) : $baseCost,
        'state'=>arcade_authority_state($pdo, $customerId),
    ]);
} catch (DomainException $e) {
    arcade_continue_json(409, ['success'=>false, 'message'=>$e->getMessage()]);
} catch (InvalidArgumentException $e) {
    arcade_continue_json(422, ['success'=>false, 'message'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('[ARCADE_CONTINUE_RUN] ' . $e->getMessage());
    try { arcade_log_event($pdo ?? null, 'error', 'continue_run_purchase', $e->getMessage(), $customerId ?? null); }
    catch (Throwable $ignored) {}
    arcade_continue_json(500, ['success'=>false, 'message'=>'Continue could not be completed. Refresh the page and try once more.']);
}
