<?php
declare(strict_types=1);
/**
 * MASTER DATABASE CONNECTION — CLEAN + STABLE (v5.0.1 HARDENED)
 * Diamonds Outta Dirt
 *
 * STRUCTURE PRESERVED — PATCHED FOR STABILITY
 *
 * FIXES ADDED (NON-BREAKING):
 * - ✅ Guard function declarations (prevents "Cannot redeclare ..." fatals -> HTTP 500)
 * - ✅ Guard define() for DOD_DB_CONNECTED
 * - ✅ Keeps your throttled schema auto-repair
 * - ✅ Env loader remains HostGator-safe
 */

/* ======================================================
    0. ERROR HANDLING (PRODUCTION SAFE)
====================================================== */
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

@ini_set('error_log', '/home2/asqrtyte/php_errors.log');

/* ======================================================
   1. ENV LOADER (HOSTGATOR SAFE)
====================================================== */
$envPath = '/home2/asqrtyte/.env';

if (is_readable($envPath)) {
    $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $line = (string)$line;
            if ($line === '' || $line[0] === '#') continue;
            if (strpos($line, '=') === false) continue;

            [$key, $value] = explode('=', $line, 2);
            $key = trim((string)$key);
            $value = trim((string)$value, " \t\n\r\0\x0B\"'");

            if ($key === '') continue;

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

/* ======================================================
   2. DATABASE CONFIG
====================================================== */
$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_NAME = getenv('DB_NAME') ?: 'asqrtyte_dod_db';
$DB_USER = getenv('DB_USER') ?: 'asqrtyte_dod_user';
$DB_PASS = getenv('DB_PASS') ?: '';
$CHARSET = 'utf8mb4';

$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$CHARSET}";

/* ======================================================
   3. PDO OPTIONS (NO EMULATION, NO SILENT BREAKAGE)
====================================================== */
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_TIMEOUT            => 5,
];

if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
    $options[PDO::MYSQL_ATTR_INIT_COMMAND] =
        "SET NAMES {$CHARSET} COLLATE utf8mb4_unicode_ci";
}

/* ======================================================
   4. CONNECT
====================================================== */
$pdo = null;

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (Throwable $e) {
    error_log('[DB_CONNECT_FAIL] ' . $e->getMessage());
    $pdo = null;
}

/* ======================================================
   5. CORE TABLES + SCHEMA REPAIR (THROTTLED)
====================================================== */
if (!function_exists('dod_schema_should_run')) {
    function dod_schema_should_run(int $seconds = 21600): bool
    {
        $seconds = max(600, $seconds);

        $stampFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'dod_schema_stamp.txt';

        if (!is_file($stampFile)) {
            @file_put_contents($stampFile, (string)time());
            return true;
        }

        $mtime = @filemtime($stampFile);
        if (!is_int($mtime)) {
            @file_put_contents($stampFile, (string)time());
            return true;
        }

        if ((time() - $mtime) >= $seconds) {
            @touch($stampFile);
            return true;
        }

        return false;
    }
}

if ($pdo instanceof PDO) {
    try {
        /* ---------------- PRODUCTS ---------------- */
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS products (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                stock INT NOT NULL DEFAULT 0,
                category VARCHAR(50) NOT NULL DEFAULT 'MENS',
                image_url VARCHAR(500) NULL,
                description TEXT NULL,

                bg_url VARCHAR(500) NULL,
                bg_type VARCHAR(50) NULL,

                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                KEY idx_category (category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- REGISTRATION ATTEMPTS (rate limiting) ---------------- */
        // One row per registration attempt, keyed by hashed IP. Used to
        // cap how many accounts a single address can create per hour --
        // bot protection, not tied to any specific account.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS registration_attempts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ip_hash VARCHAR(64) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_ip_created (ip_hash, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- ARCADE MUSIC TRACKS ---------------- */
        // Gameplay music catalog, separate from the per-product music on
        // product pages. One random active track plays for the whole run.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS arcade_music_tracks (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(200) NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- EMAIL SEND LOG ---------------- */
        // Persistent, queryable record of transactional email attempts
        // (starting with signup verification emails). PHP's mail()
        // fails silently far too often on shared hosting to trust
        // without a real record -- this makes failures visible instead
        // of scrolling away in a server log nobody checks.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS email_send_log (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email_type VARCHAR(50) NOT NULL,
                recipient VARCHAR(255) NOT NULL,
                success TINYINT(1) NOT NULL DEFAULT 0,
                error_detail VARCHAR(500) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_type (email_type),
                KEY idx_success (success),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- ARCADE PROGRESS ANOMALIES ---------------- */
        // Flags implausibly large jumps in synced lifetime_shards (the
        // field that drives real Stripe coupon codes). Never blocks a
        // save -- just logs it for admin review, since a false positive
        // shouldn't cost a legitimate player their progress.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS arcade_progress_anomalies (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id INT UNSIGNED NOT NULL,
                field_name VARCHAR(60) NOT NULL,
                previous_value INT UNSIGNED NOT NULL,
                incoming_value INT UNSIGNED NOT NULL,
                delta INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_customer (customer_id),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");


        /* ---------------- ARCADE SERVER-AUTHORITATIVE PLAYER STATE ---------------- */
        // v3.7: the database is the authority for character ownership,
        // equipped character, Banked/Lifetime Shards, mastery, and inventory.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS arcade_player_accounts (
                customer_id INT UNSIGNED PRIMARY KEY,
                banked_shards INT UNSIGNED NOT NULL DEFAULT 0,
                lifetime_shards INT UNSIGNED NOT NULL DEFAULT 0,
                equipped_character VARCHAR(64) NOT NULL DEFAULT 'fire_muse',
                migration_version TINYINT UNSIGNED NOT NULL DEFAULT 0,
                state_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
                last_run_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS arcade_player_characters (
                customer_id INT UNSIGNED NOT NULL,
                character_id VARCHAR(64) NOT NULL,
                purchased_cost INT UNSIGNED NOT NULL DEFAULT 0,
                purchased_at TIMESTAMP NULL,
                mastery_xp INT UNSIGNED NOT NULL DEFAULT 0,
                runs INT UNSIGNED NOT NULL DEFAULT 0,
                bosses INT UNSIGNED NOT NULL DEFAULT 0,
                outfits INT UNSIGNED NOT NULL DEFAULT 0,
                catches INT UNSIGNED NOT NULL DEFAULT 0,
                perfect_catches INT UNSIGNED NOT NULL DEFAULT 0,
                max_combo INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (customer_id, character_id),
                KEY idx_character (character_id),
                KEY idx_mastery (mastery_xp)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS arcade_player_inventory (
                customer_id INT UNSIGNED NOT NULL,
                item_id VARCHAR(80) NOT NULL,
                quantity INT UNSIGNED NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (customer_id, item_id),
                KEY idx_item (item_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS arcade_player_reward_claims (
                customer_id INT UNSIGNED NOT NULL,
                character_id VARCHAR(64) NOT NULL,
                mastery_level TINYINT UNSIGNED NOT NULL,
                transaction_uuid VARCHAR(96) NOT NULL,
                claimed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (customer_id, character_id, mastery_level),
                UNIQUE KEY uq_reward_transaction (transaction_uuid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS arcade_player_run_receipts (
                customer_id INT UNSIGNED NOT NULL,
                run_id VARCHAR(100) NOT NULL,
                character_id VARCHAR(64) NOT NULL,
                score INT UNSIGNED NOT NULL DEFAULT 0,
                duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
                banked_reward INT UNSIGNED NOT NULL DEFAULT 0,
                lifetime_reward INT UNSIGNED NOT NULL DEFAULT 0,
                mastery_xp_reward INT UNSIGNED NOT NULL DEFAULT 0,
                submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (customer_id, run_id),
                KEY idx_customer_submitted (customer_id, submitted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS arcade_player_transactions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id INT UNSIGNED NOT NULL,
                transaction_uuid VARCHAR(96) NOT NULL,
                event_type VARCHAR(50) NOT NULL,
                character_id VARCHAR(64) NULL,
                shard_delta INT NOT NULL DEFAULT 0,
                lifetime_delta INT NOT NULL DEFAULT 0,
                item_id VARCHAR(80) NULL,
                item_quantity INT NOT NULL DEFAULT 0,
                run_id VARCHAR(100) NULL,
                metadata_json LONGTEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_player_transaction (customer_id, transaction_uuid),
                KEY idx_customer_created (customer_id, created_at),
                KEY idx_event (event_type),
                KEY idx_run (run_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- ARCADE EVENT LOG (v3.7.4) ---------------- */
        // Every rejected purchase/run/reward-claim in arcade_player_action.php
        // used to just return a JSON error to the browser and vanish -- no
        // server-side record for an admin to ever see. This makes that
        // visible, mirroring email_send_log's "make failures queryable"
        // pattern.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS arcade_event_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id INT UNSIGNED NULL,
                severity ENUM('info','warning','error') NOT NULL DEFAULT 'error',
                context VARCHAR(60) NOT NULL,
                message VARCHAR(500) NOT NULL,
                detail_json LONGTEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_customer (customer_id),
                KEY idx_context (context),
                KEY idx_severity (severity),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- ARCADE PLAYER SNAPSHOTS (v3.7.4) ---------------- */
        // Point-in-time backups of a player's server-authoritative state,
        // so a bad migration or admin mistake can be undone instead of
        // being permanent.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS arcade_player_snapshots (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id INT UNSIGNED NOT NULL,
                reason VARCHAR(100) NOT NULL DEFAULT 'manual',
                snapshot_json LONGTEXT NOT NULL,
                created_by VARCHAR(100) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_customer_created (customer_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- ARCADE SYSTEM SETTINGS (v3.7.4) ---------------- */
        // Small key/value store for arcade-wide switches -- starts with
        // maintenance mode, but shaped to hold future settings too instead
        // of adding a new column/table per switch.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS arcade_system_settings (
                setting_key VARCHAR(60) PRIMARY KEY,
                setting_value VARCHAR(500) NULL,
                updated_by VARCHAR(100) NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- ARCADE SEASONS (v3.8) ---------------- */
        // Deliberate scoping decision: leaderboards are computed on the
        // fly from arcade_player_run_receipts (already has customer_id,
        // character_id, score, submitted_at) filtered by this season's
        // date range, rather than maintaining a separate denormalized
        // leaderboard-entries table that would need to be kept in sync.
        // Simpler, and the roadmap's own instruction was "use the
        // existing server run receipts instead of trusting localStorage"
        // -- this satisfies that directly. Weekly/monthly/global scopes
        // are just different date-range filters over the same receipts,
        // not separate tables either.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS arcade_seasons (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                starts_at TIMESTAMP NOT NULL,
                ends_at TIMESTAMP NOT NULL,
                status ENUM('upcoming','active','ended','archived') NOT NULL DEFAULT 'upcoming',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_status (status),
                KEY idx_dates (starts_at, ends_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- ARCADE SCORE FLAGS (v3.8) ---------------- */
        // Admin leaderboard moderation: exclude a run from leaderboard
        // calculations without deleting arcade_player_run_receipts (that
        // row still counts toward the player's own Shards/mastery, which
        // were already paid out at run-submit time -- flagging only
        // hides it from public rankings, it never claws back rewards).
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS arcade_score_flags (
                run_id VARCHAR(100) PRIMARY KEY,
                customer_id INT UNSIGNED NOT NULL,
                flagged_by VARCHAR(100) NULL,
                reason VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_customer (customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- ARCADE SEASON REWARDS (v3.8) ---------------- */
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS arcade_season_rewards (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                season_id INT UNSIGNED NOT NULL,
                rank_start INT UNSIGNED NOT NULL,
                rank_end INT UNSIGNED NOT NULL,
                reward_label VARCHAR(200) NOT NULL,
                item_id VARCHAR(80) NULL,
                item_quantity INT UNSIGNED NOT NULL DEFAULT 0,
                banked_shards INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_season (season_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS arcade_season_reward_claims (
                customer_id INT UNSIGNED NOT NULL,
                season_id INT UNSIGNED NOT NULL,
                reward_id INT UNSIGNED NOT NULL,
                transaction_uuid VARCHAR(96) NOT NULL,
                claimed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (customer_id, season_id),
                UNIQUE KEY uq_season_reward_transaction (transaction_uuid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- PRODUCT RECOMMENDATION EVENTS ---------------- */
        // Powers the "measure click-through rate on recommendations"
        // success criteria from the roadmap.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS product_recommendation_events (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source_product_id INT UNSIGNED NOT NULL,
                recommended_product_id INT UNSIGNED NOT NULL,
                event_type ENUM('impression','click') NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_source (source_product_id),
                KEY idx_type (event_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- AI LISTING DRAFTS ---------------- */
        // Shared by two entry points: sellers uploading items to the
        // marketplace, and admin adding to the DOD catalog directly.
        // customer_id is set for seller-created drafts; NULL means the
        // draft was started from the admin side. Nothing here becomes a
        // real product/listing until a human reviews and publishes it.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ai_listing_drafts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source_type ENUM('seller','admin') NOT NULL DEFAULT 'admin',
                customer_id INT UNSIGNED NULL,
                image_path VARCHAR(500) NOT NULL,
                ai_brand VARCHAR(120) NULL,
                ai_type VARCHAR(120) NULL,
                ai_color VARCHAR(60) NULL,
                ai_material VARCHAR(120) NULL,
                ai_title VARCHAR(255) NULL,
                ai_description TEXT NULL,
                ai_confidence DECIMAL(4,3) NULL,
                ai_raw_response TEXT NULL,
                asking_price DECIMAL(10,2) NULL,
                edited_title VARCHAR(255) NULL,
                edited_description TEXT NULL,
                status ENUM('draft','pending_review','published','rejected') NOT NULL DEFAULT 'draft',
                published_product_id INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_status (status),
                KEY idx_customer (customer_id),
                KEY idx_source (source_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- PRODUCT IMAGES (GALLERY) ---------------- */
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS product_images (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_id INT UNSIGNED NOT NULL,
                image_url VARCHAR(500) NOT NULL,
                assignment VARCHAR(50) NULL,
                position INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                KEY idx_product (product_id),
                KEY idx_assignment (assignment),
                KEY idx_position (position),

                CONSTRAINT fk_pi_product
                    FOREIGN KEY (product_id)
                    REFERENCES products(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- ADMIN USERS ---------------- */
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS admin_users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(100) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- CUSTOMERS ---------------- */
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS customers (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                first_name VARCHAR(100) NULL,
                last_name VARCHAR(100) NULL,
                reset_token VARCHAR(64) NULL,
                reset_expires TIMESTAMP NULL,
                referral_code VARCHAR(20) NULL,
                email_verified TINYINT(1) NOT NULL DEFAULT 0,
                verify_token VARCHAR(64) NULL,
                verify_token_expires TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_login_at TIMESTAMP NULL,
                UNIQUE KEY uq_email (email),
                UNIQUE KEY uq_referral_code (referral_code),
                KEY idx_reset (reset_token),
                KEY idx_verify_token (verify_token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- CUSTOMER ADDRESSES ---------------- */
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS customer_addresses (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id INT UNSIGNED NOT NULL,
                full_name VARCHAR(150) NULL,
                line1 VARCHAR(255) NOT NULL,
                line2 VARCHAR(255) NULL,
                city VARCHAR(120) NOT NULL,
                state VARCHAR(60) NULL,
                zip VARCHAR(20) NOT NULL,
                country VARCHAR(60) NOT NULL DEFAULT 'US',
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_customer (customer_id),
                CONSTRAINT fk_addr_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- ORDERS ---------------- */
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS orders (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id INT UNSIGNED NULL,
                email VARCHAR(255) NULL,
                name VARCHAR(255) NULL,
                order_status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
                stripe_session_id VARCHAR(255) NULL,
                subtotal_cents INT UNSIGNED NOT NULL DEFAULT 0,
                shipping_cents INT UNSIGNED NOT NULL DEFAULT 0,
                tax_cents INT UNSIGNED NOT NULL DEFAULT 0,
                total_cents INT UNSIGNED NOT NULL DEFAULT 0,
                shipping_address TEXT NULL,
                tracking_number VARCHAR(120) NULL,
                admin_note TEXT NULL,
                refund_note TEXT NULL,
                post_purchase_email_sent_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_orders_customer (customer_id),
                KEY idx_orders_email (email),
                KEY idx_orders_status (order_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- ORDER STATUS HISTORY (admin audit trail) ---------------- */
        // Referenced by admin/order_view.php + order_update.php since they
        // were first scaffolded, but the table was never actually created,
        // so every status-change log write silently failed. Statuses are
        // stored UPPERCASE to match orders.order_status and the Stripe/cron
        // vocabulary (PENDING/PAID/PROCESSING/SHIPPED/DELIVERED/COMPLETED/
        // REFUNDED/CANCELLED) -- NOT the lowercase set the old broken admin
        // scaffold assumed.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS order_status_history (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_id INT UNSIGNED NOT NULL,
                status VARCHAR(30) NOT NULL,
                changed_by VARCHAR(100) NULL,
                changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_osh_order (order_id),
                KEY idx_osh_changed (changed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- ORDER ITEMS ---------------- */
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS order_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NULL,
                product_name VARCHAR(255) NOT NULL,
                price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                quantity INT UNSIGNED NOT NULL DEFAULT 1,
                size VARCHAR(30) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_order (order_id),
                KEY idx_product (product_id),
                CONSTRAINT fk_oi_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- WISHLISTS ---------------- */
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS wishlists (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_customer_product (customer_id, product_id),
                KEY idx_wishlist_customer (customer_id),
                KEY idx_wishlist_product (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- PRODUCT EMBEDDINGS (VISUAL SIMILARITY) ---------------- */
        // Weekend 4: one embedding vector per product image, generated via
        // Voyage AI's multimodal embedding model. Similarity is computed
        // in PHP (cosine similarity) rather than a dedicated vector DB --
        // reasonable for a catalog this size, and avoids adding
        // infrastructure shared hosting can't run.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS product_embeddings (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_id INT UNSIGNED NOT NULL,
                embedding LONGTEXT NOT NULL,
                model VARCHAR(60) NOT NULL DEFAULT 'voyage-multimodal-3',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_product (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- RECOMMENDATION CLICKS ---------------- */
        // Powers the Weekend 4 success metric: click-through rate on
        // "visually similar" recommendations, not price accuracy.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS recommendation_clicks (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source_product_id INT UNSIGNED NOT NULL,
                clicked_product_id INT UNSIGNED NOT NULL,
                customer_id INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_source (source_product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- RECOMMENDATION IMPRESSIONS ---------------- */
        // The other half of a CTR calculation -- how many times each
        // source product's recommendation set was actually shown.
        // Without this, "clicks" alone can't produce a real rate.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS recommendation_impressions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source_product_id INT UNSIGNED NOT NULL,
                shown_count INT UNSIGNED NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_source (source_product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- PRODUCT REVIEWS ---------------- */
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS product_reviews (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_id INT UNSIGNED NOT NULL,
                user_name VARCHAR(80) NOT NULL,
                rating TINYINT UNSIGNED NOT NULL,
                review TEXT NOT NULL,
                approved TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_product (product_id),
                KEY idx_approved (approved)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- NEWSLETTER SUBSCRIBERS ---------------- */
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS newsletter_subscribers (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'unsubscribed',
                wants_drops TINYINT(1) NOT NULL DEFAULT 0,
                wants_events TINYINT(1) NOT NULL DEFAULT 0,
                consent_marketing TINYINT(1) NOT NULL DEFAULT 0,
                consent_source VARCHAR(50) NULL,
                source VARCHAR(50) NULL,
                confirm_token VARCHAR(64) NULL,
                confirmed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_email (email),
                KEY idx_status (status),
                KEY idx_confirm_token (confirm_token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- PUSH SUBSCRIPTIONS ---------------- */
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS push_subscriptions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                endpoint VARCHAR(500) NOT NULL,
                customer_id INT UNSIGNED NULL,
                p256dh VARCHAR(255) NOT NULL,
                auth VARCHAR(255) NOT NULL,
                user_agent VARCHAR(255) NULL,
                last_used_at TIMESTAMP NULL,
                last_error VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_endpoint (endpoint(255)),
                KEY idx_customer (customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- CART ABANDONMENT RECOVERY ---------------- */
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS cart_abandonment (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id INT UNSIGNED NOT NULL,
                email VARCHAR(255) NOT NULL,
                name VARCHAR(255) NULL,
                cart_snapshot TEXT NOT NULL,
                subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
                item_count INT UNSIGNED NOT NULL DEFAULT 0,
                first_reminder_sent_at TIMESTAMP NULL,
                second_reminder_sent_at TIMESTAMP NULL,
                recovered_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_customer (customer_id),
                KEY idx_updated_at (updated_at),
                KEY idx_recovered (recovered_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- REFERRAL PROGRAM ---------------- */
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS referrals (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                referrer_customer_id INT UNSIGNED NOT NULL,
                referred_customer_id INT UNSIGNED NULL,
                referred_email VARCHAR(255) NULL,
                status ENUM('pending','completed','rewarded') NOT NULL DEFAULT 'pending',
                reward_code VARCHAR(40) NULL,
                reward_issued_at TIMESTAMP NULL,
                first_order_id INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_referrer (referrer_customer_id),
                KEY idx_referred_customer (referred_customer_id),
                KEY idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- MODEL / CASTING SUBMISSIONS ---------------- */
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS model_submissions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                full_name VARCHAR(150) NOT NULL,
                email VARCHAR(255) NOT NULL,
                phone VARCHAR(30) NULL,
                instagram_handle VARCHAR(100) NULL,
                portfolio_url VARCHAR(500) NULL,
                interest_type VARCHAR(50) NOT NULL DEFAULT 'photoshoot',
                height VARCHAR(20) NULL,
                sizes VARCHAR(100) NULL,
                location VARCHAR(150) NULL,
                message TEXT NULL,
                photo_1 VARCHAR(500) NULL,
                photo_2 VARCHAR(500) NULL,
                photo_3 VARCHAR(500) NULL,
                age_confirmed TINYINT(1) NOT NULL DEFAULT 0,
                is_shortlisted TINYINT(1) NOT NULL DEFAULT 0,
                status ENUM('new','reviewed','contacted','booked','declined') NOT NULL DEFAULT 'new',
                admin_note TEXT NULL,
                ip_hash VARCHAR(64) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_status (status),
                KEY idx_created (created_at),
                KEY idx_email (email),
                KEY idx_shortlisted (is_shortlisted)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- MODEL OUTFIT ASSIGNMENTS ---------------- */
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS model_outfit_assignments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                model_submission_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                is_listed TINYINT(1) NOT NULL DEFAULT 0,
                notes VARCHAR(500) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_model (model_submission_id),
                KEY idx_product (product_id),
                KEY idx_listed (is_listed)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- MODEL SHOOT SCHEDULE ---------------- */
        // Assigns a model submission to a specific date for a shoot/booking.
        // Separate table from model_submissions since one model could be
        // booked for multiple shoots over time.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS model_shoot_schedule (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                model_submission_id INT UNSIGNED NOT NULL,
                shoot_date DATE NOT NULL,
                shoot_time VARCHAR(20) NULL,
                location VARCHAR(200) NULL,
                shoot_type VARCHAR(80) NULL,
                notes VARCHAR(500) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_model (model_submission_id),
                KEY idx_date (shoot_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- CUSTOMER PHOTOS (SOCIAL PROOF) ---------------- */
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS customer_photos (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                image_url VARCHAR(500) NOT NULL,
                customer_handle VARCHAR(100) NULL,
                caption VARCHAR(255) NULL,
                product_id INT UNSIGNED NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_active (is_active),
                KEY idx_sort (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- ARCADE PLAYER PROGRESSION ---------------- */
        // Account-tied, permanent progression -- separate from the
        // device-local arcade_scores/localStorage tracking, so progress
        // survives across devices for logged-in customers ("no progress
        // is ever lost").
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS arcade_player_progression (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id INT UNSIGNED NOT NULL,
                xp INT UNSIGNED NOT NULL DEFAULT 0,
                lifetime_shards INT UNSIGNED NOT NULL DEFAULT 0,
                total_catches INT UNSIGNED NOT NULL DEFAULT 0,
                total_runs INT UNSIGNED NOT NULL DEFAULT 0,
                boss_clears INT UNSIGNED NOT NULL DEFAULT 0,
                best_score INT UNSIGNED NOT NULL DEFAULT 0,
                achievements TEXT NULL,
                claimed_rewards TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_customer (customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- ARCADE SCORES ---------------- */
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS arcade_scores (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                player_name VARCHAR(20) NOT NULL,
                score INT UNSIGNED NOT NULL,
                level_reached INT UNSIGNED NOT NULL DEFAULT 1,
                game VARCHAR(50) NOT NULL DEFAULT 'dod_arcade',
                player_email VARCHAR(255) NULL,
                customer_id INT UNSIGNED NULL,
                playtime_seconds INT UNSIGNED NOT NULL DEFAULT 0,
                ip_hash VARCHAR(64) NULL,
                user_agent VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_game_score (game, score),
                KEY idx_created (created_at),
                KEY idx_ip_hash (ip_hash),
                KEY idx_customer (customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- ARCADE PRODUCT CATCHES ---------------- */
        // Powers the admin "most collected products" analytics -- logged
        // in a small batch once per run (not per catch) to avoid
        // hammering the server with a request on every single diamond.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS arcade_product_catches (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_id INT UNSIGNED NULL,
                product_name VARCHAR(255) NOT NULL,
                catch_count INT UNSIGNED NOT NULL DEFAULT 1,
                customer_id INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_product (product_id),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ---------------- BLOG ---------------- */
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS blog_posts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL,
                excerpt VARCHAR(500) NULL,
                content MEDIUMTEXT NOT NULL,
                featured_image VARCHAR(500) NULL,
                is_published TINYINT(1) NOT NULL DEFAULT 0,
                published_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_slug (slug),
                KEY idx_published (is_published, published_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        /* ======================================================
           5B. SCHEMA AUTO-REPAIR (SAFE ALTER TABLE, THROTTLED)
           - Runs at most once per 6 hours
           - Disable: env DOD_SCHEMA_AUTO_REPAIR=0
        ======================================================= */
        $autoRepair = getenv('DOD_SCHEMA_AUTO_REPAIR');
        if ($autoRepair === false || $autoRepair === '' || $autoRepair === '1' || strtolower((string)$autoRepair) === 'true') {

            if (dod_schema_should_run(21600)) {
                $column_exists = function (string $table, string $column) use ($pdo, $DB_NAME): bool {
                    try {
                        $stmt = $pdo->prepare("
                            SELECT 1
                            FROM INFORMATION_SCHEMA.COLUMNS
                            WHERE TABLE_SCHEMA = :db
                              AND TABLE_NAME = :t
                              AND COLUMN_NAME = :c
                            LIMIT 1
                        ");
                        $stmt->execute([':db' => (string)$DB_NAME, ':t' => $table, ':c' => $column]);
                        return (bool)$stmt->fetchColumn();
                    } catch (Throwable $e) {
                        error_log('[DB_SCHEMA_CHECK_FAIL] ' . $e->getMessage());
                        return false;
                    }
                };

                // ---- products: slug + meta + sizes + audio ----
                if (!$column_exists('products', 'slug')) {
                    $pdo->exec("ALTER TABLE products ADD COLUMN slug VARCHAR(255) NULL AFTER name");
                    try { $pdo->exec("CREATE INDEX idx_slug ON products (slug)"); } catch (Throwable $e) {}
                }
                if (!$column_exists('products', 'meta_title')) {
                    $pdo->exec("ALTER TABLE products ADD COLUMN meta_title VARCHAR(255) NULL AFTER description");
                }
                if (!$column_exists('products', 'meta_description')) {
                    $pdo->exec("ALTER TABLE products ADD COLUMN meta_description VARCHAR(500) NULL AFTER meta_title");
                }
                if (!$column_exists('products', 'meta_keywords')) {
                    $pdo->exec("ALTER TABLE products ADD COLUMN meta_keywords VARCHAR(500) NULL AFTER meta_description");
                }
                if (!$column_exists('products', 'available_sizes')) {
                    $pdo->exec("ALTER TABLE products ADD COLUMN available_sizes VARCHAR(255) NULL AFTER meta_keywords");
                }
                if (!$column_exists('products', 'audio_url')) {
                    $pdo->exec("ALTER TABLE products ADD COLUMN audio_url VARCHAR(500) NULL AFTER available_sizes");
                }

                // ---- product_images: file_type ----
                if (!$column_exists('product_images', 'file_type')) {
                    $pdo->exec("ALTER TABLE product_images ADD COLUMN file_type VARCHAR(50) NULL AFTER image_url");
                    try { $pdo->exec("CREATE INDEX idx_file_type ON product_images (file_type)"); } catch (Throwable $e) {}
                }

                // ---- customers: verification + referral columns (safe if
                // the table above was just created fresh -- these will
                // simply already exist and get skipped) ----
                if (!$column_exists('customers', 'referral_code')) {
                    $pdo->exec("ALTER TABLE customers ADD COLUMN referral_code VARCHAR(20) NULL AFTER last_login_at");
                    try { $pdo->exec("CREATE UNIQUE INDEX idx_referral_code ON customers (referral_code)"); } catch (Throwable $e) {}
                }
                if (!$column_exists('customers', 'email_verified')) {
                    $pdo->exec("ALTER TABLE customers ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0");
                }
                if (!$column_exists('customers', 'verify_token')) {
                    $pdo->exec("ALTER TABLE customers ADD COLUMN verify_token VARCHAR(64) NULL");
                    try { $pdo->exec("CREATE INDEX idx_verify_token ON customers (verify_token)"); } catch (Throwable $e) {}
                }
                if (!$column_exists('customers', 'verify_token_expires')) {
                    $pdo->exec("ALTER TABLE customers ADD COLUMN verify_token_expires TIMESTAMP NULL");
                }

                // ---- orders: post-purchase email tracking ----
                if ($column_exists('orders', 'id') && !$column_exists('orders', 'post_purchase_email_sent_at')) {
                    $pdo->exec("ALTER TABLE orders ADD COLUMN post_purchase_email_sent_at TIMESTAMP NULL");
                }

                // ---- model_submissions: shortlist flag ----
                if ($column_exists('model_submissions', 'id') && !$column_exists('model_submissions', 'is_shortlisted')) {
                    $pdo->exec("ALTER TABLE model_submissions ADD COLUMN is_shortlisted TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
                    try { $pdo->exec("CREATE INDEX idx_shortlisted ON model_submissions (is_shortlisted)"); } catch (Throwable $e) {}
                }

                // ---- ai_listing_drafts: add pending_review status if an earlier version was deployed without it ----
                if ($column_exists('ai_listing_drafts', 'id')) {
                    try { $pdo->exec("ALTER TABLE ai_listing_drafts MODIFY COLUMN status ENUM('draft','pending_review','published','rejected') NOT NULL DEFAULT 'draft'"); } catch (Throwable $e) {}
                }
                if ($column_exists('ai_listing_drafts', 'id') && !$column_exists('ai_listing_drafts', 'asking_price')) {
                    $pdo->exec("ALTER TABLE ai_listing_drafts ADD COLUMN asking_price DECIMAL(10,2) NULL");
                }

                // ---- products: attribute columns for similarity matching ----
                if ($column_exists('products', 'id') && !$column_exists('products', 'item_brand')) {
                    $pdo->exec("ALTER TABLE products ADD COLUMN item_brand VARCHAR(120) NULL");
                }
                if ($column_exists('products', 'id') && !$column_exists('products', 'item_type')) {
                    $pdo->exec("ALTER TABLE products ADD COLUMN item_type VARCHAR(120) NULL");
                    try { $pdo->exec("CREATE INDEX idx_item_type ON products (item_type)"); } catch (Throwable $e) {}
                }
                if ($column_exists('products', 'id') && !$column_exists('products', 'item_color')) {
                    $pdo->exec("ALTER TABLE products ADD COLUMN item_color VARCHAR(60) NULL");
                }
                if ($column_exists('products', 'id') && !$column_exists('products', 'item_material')) {
                    $pdo->exec("ALTER TABLE products ADD COLUMN item_material VARCHAR(120) NULL");
                }

                // ---- arcade_scores: playtime + customer link (for admin analytics) ----
                if ($column_exists('arcade_scores', 'id') && !$column_exists('arcade_scores', 'playtime_seconds')) {
                    $pdo->exec("ALTER TABLE arcade_scores ADD COLUMN playtime_seconds INT UNSIGNED NOT NULL DEFAULT 0");
                }
                if ($column_exists('arcade_scores', 'id') && !$column_exists('arcade_scores', 'customer_id')) {
                    $pdo->exec("ALTER TABLE arcade_scores ADD COLUMN customer_id INT UNSIGNED NULL");
                    try { $pdo->exec("CREATE INDEX idx_arcade_scores_customer ON arcade_scores (customer_id)"); } catch (Throwable $e) {}
                }

                // ---- arcade_player_run_receipts: which form was played (v3.8 --
                // needed so a "Classic Paddle leaderboard" can actually be
                // filtered; this wasn't recorded per-run before) ----
                if ($column_exists('arcade_player_run_receipts', 'customer_id') && !$column_exists('arcade_player_run_receipts', 'character_form')) {
                    $pdo->exec("ALTER TABLE arcade_player_run_receipts ADD COLUMN character_form VARCHAR(20) NOT NULL DEFAULT 'illustrated'");
                    try { $pdo->exec("CREATE INDEX idx_run_receipts_form ON arcade_player_run_receipts (character_form)"); } catch (Throwable $e) {}
                }

                // ---- orders: admin fulfillment/refund/notes columns (2026-07-17)
                // The admin order pages read/write these but they never
                // existed on the live table, so every admin order action
                // silently failed. Add them to the existing live table here. ----
                if ($column_exists('orders', 'id') && !$column_exists('orders', 'tracking_number')) {
                    $pdo->exec("ALTER TABLE orders ADD COLUMN tracking_number VARCHAR(120) NULL");
                }
                if ($column_exists('orders', 'id') && !$column_exists('orders', 'admin_note')) {
                    $pdo->exec("ALTER TABLE orders ADD COLUMN admin_note TEXT NULL");
                }
                if ($column_exists('orders', 'id') && !$column_exists('orders', 'refund_note')) {
                    $pdo->exec("ALTER TABLE orders ADD COLUMN refund_note TEXT NULL");
                }
            }
        }

    } catch (Throwable $e) {
        error_log('[DB_SCHEMA_WARN] ' . $e->getMessage());
    }
}

/* ======================================================
   6. GLOBAL FLAGS
====================================================== */
if (!defined('DOD_DB_CONNECTED')) {
    define('DOD_DB_CONNECTED', $pdo instanceof PDO);
}

/* ======================================================
   7. SAFE QUERY HELPER
====================================================== */
if (!function_exists('dod_query')) {
    function dod_query(string $sql, array $params = []): ?PDOStatement
    {
        global $pdo;
        if (!($pdo instanceof PDO)) return null;

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (Throwable $e) {
            error_log('[DB_QUERY_FAIL] ' . $e->getMessage());
            return null;
        }
    }
}