---
name: dod-site
description: >
  Use this skill whenever working on the Diamonds Outta Dirt (DOD) website
  codebase — diamondsouttadirt.com, a PHP/MySQL streetwear e-commerce site
  on HostGator shared hosting. Trigger on any task touching its PHP files,
  database, Stripe checkout, Exchange marketplace, admin panel, cart,
  emails, arcade game, casting/model submissions, or deployment. This
  skill encodes hard-won conventions and gotchas; skipping it causes
  recurring, already-solved bugs.
---

# Diamonds Outta Dirt — Site Skill

Context and conventions for working on diamondsouttadirt.com. Read this
before making changes — several of these are things that caused real
bugs when missed.

## Stack & hosting

- PHP/MySQL (PDO), HostGator shared hosting, no framework
- Web root: `/home2/asqrtyte/public_html/`
- **PDO emulation is OFF.** Always use `INFORMATION_SCHEMA` queries for
  checking table/column existence. `SHOW TABLES/COLUMNS LIKE :param`
  fails silently on this host — don't use it. This is not theoretical:
  found live 2026-07-17 in `admin/includes/field_reports_db.php` and
  `admin/dashboard.php`, both using this exact broken pattern. Traced
  impact: `columnExists()` always returned `false`, so the schema-repair
  function kept re-running `ALTER TABLE ADD COLUMN` on columns that
  already existed, hit a duplicate-column error, and silently broke
  the field-reports upload feature after its first-ever use. Fixed by
  switching to `INFORMATION_SCHEMA` (see `exchange.php`'s
  `ex_table_exists`/`ex_column_exists` or `product.php`'s
  `db_table_exists`/`db_column_exists` for the correct pattern already
  used elsewhere). If you ever see a feature that "worked once and then
  broke," check for this pattern first.
- Brand aesthetic: dark background, neon green accent (`#00ff9d`),
  Space Mono (UI/code feel) + Cormorant Garamond (headers)

## Auth & sessions

- Customers: `$_SESSION['customer_id']`
- Admin: `$_SESSION['admin_logged_in'] === true`
- **Real shared session bootstrap**: `includes/session_bootstrap.php` —
  returns early if a session is already active, otherwise sets
  30-day lifetime (`60*60*24*30`) with matching `session.gc_maxlifetime`,
  rolling expiration on each active request, and deliberately no forced
  cookie domain (avoids splitting sessions between the apex domain and
  `www`). Required by `partials/header.php`, `account/arcade.php`,
  `arcade_app/bootstrap.php`, and `includes/referral_helper.php`.
  Because of the early-return guard, **whichever file's session-start
  code runs first on a given request wins** — matters if you add a new
  entry point that doesn't go through `header.php`.
- **`includes/session_start.php` is dead code** — do not confuse it with
  `includes/session_bootstrap.php` above (dangerously similar name).
  Nothing requires it anymore; it was a stale, partial duplicate of an
  older `header.php` that referenced a `DiamondCart\CartSession` class
  which was never actually defined anywhere in the codebase (would have
  caused a fatal "Class not found" error). `header.php` was rewritten
  (now v4.9, "PARTIAL-SAFE") specifically to stop depending on it. Safe
  to delete from the live host if you're cleaning up, but don't
  resurrect a require to it.
- Admin uses its own separate bootstrap, `admin/_session_bootstrap.php`
  (custom session name `DODADMINSESSID`, cookie scoped to `/admin` —
  both deliberate, don't remove). It currently sets `'lifetime' => 0`
  (expires when the browser closes) — this differs from the 30-day
  customer standard above. Not confirmed whether that's an intentional
  security choice for admin or a regression of the old "logged out
  every time" gotcha; check with the user before changing it either way.
- `account/auth.php` also has its own inline `session_start()`
  (`httponly`/`secure`/`samesite`, no explicit lifetime) guarded by
  `session_status() === PHP_SESSION_NONE` — only takes effect if
  nothing has started a session yet that request. On pages that load
  `header.php` first, this is a no-op; on any page that includes
  `auth.php` without `header.php`, it'll fall back to a browser-session
  cookie (no persistent lifetime).
- `partials/header.php` is now a **true partial** (v4.9) — it does NOT
  output `<!doctype>/<html>/<head>/<body>/<main>`, so it's safe to
  include inside an existing page template. It computes cart
  count/total directly from `$_SESSION['cart']['items']` inline rather
  than through a `CartSession` class.

## Email

- All system email sends from `myshineisnow@diamondsouttadirt.com`
- **Never use `@mail(...)` with error suppression.** Always capture the
  return value and log to the `email_send_log` table (recipient, type,
  success, error_detail). PHP's `mail()` can also return `true` while
  the message never actually reaches an inbox — that's a real,
  unresolved deliverability question on this host, not just a code bug.
  If verification emails are reported as "sent but never arrived,"
  check spam first, then consider this may need a real transactional
  provider (Postmark/SendGrid/Mailgun) instead of PHP's `mail()`.

## Known recurring bug pattern: files saved under the wrong name/path

Found repeatedly (2026-07-17): a file's own docblock says where it
belongs, but it's actually sitting somewhere else -- `backfill_embeddings.ph`
(typo'd extension), `push_subscribe.php` and `push_unsubscribe.php`
(both in `includes/` instead of `api/`), and worst so far:
`api/exchange_price_history.php` actually *contained*
`api/exchange_live_status.php`'s code (moved to fix), which meant the
*real* price-history endpoint had never existed anywhere and
`exchange.php`'s guide-price chart was silently broken. **When
something "was never wired up" or "doesn't work," check whether the
file sitting at the expected path actually contains what its own
docblock says it should**, not just whether a file exists there at all.
A quick systematic check: grep every file's `Path:` docblock comment
and compare it to the file's actual location.

## Known recurring bug pattern: file drift

Several admin files (`admin_nav.php`, `db_connect.php`) have been found
with meaningfully different content between what Claude had cached and
what was actually live. **Always treat a local copy as possibly stale.**
When in doubt, ask for the current live file rather than assuming.

## The arcade — two codebases exist, know which one is live

1. **A single-file `arcade.php`** — built from scratch in earlier Claude
   sessions. Catch-em-up mechanics, 2 bosses, in-run shop (Shards
   currency), XP/achievements, coupon rewards tied to real Stripe codes
   (`ARCADE5`, `ARCADEFREESHIP`, `ARCADE10` — **these must be created
   manually in the Stripe dashboard**, they don't exist just because the
   code references them).
2. **A modular RPG version** (`arcade_app/` folder structure) — built by
   a different tool/process, based on snapshots of the single-file
   version at various points, then extended with character
   customization, cosmetics, crafting, quests, missions, a permanent
   Upgrade Lab, mid-run upgrade drafts, group events, and cloud saves.
   **This is the version that was chosen to deploy going forward.**

If working on the arcade, confirm which one is actually live before
editing — they are not the same file, and the RPG version has had
several point releases (v2.2, v2.3, v3.2.1, v3.3, v3.4, v3.4.1, v3.7.3,
v3.7.4) each built independently, meaning fixes made to one release
don't automatically carry into the next one shared from elsewhere.
The current baseline (per the user's own roadmap, 2026-07-17) is v3.7.3
"Classic Paddle + Character Hover Restore" -- server-authoritative
Banked/Lifetime Shards, character ownership/mastery/inventory, cloud
migration, run receipts, admin player management, ~30-day sessions.

### v3.7.4 — Stability, Recovery, and Admin Controls (built 2026-07-17)

Added on top of the v3.7.3 baseline, per the user's roadmap:
- **`arcade_health.php`** (root, public JSON) + `arcade_app/includes/arcade_health.php`
  (shared helper) -- health/status check. Deliberately doesn't expose
  *which* tables are missing publicly; that detail is admin-only.
- **`arcade_app/includes/arcade_logger.php`** -- `arcade_log_event()` /
  `arcade_log_rejection()`, writing to the new `arcade_event_log` table.
  Wired into `arcade_player_action.php`'s and `arcade_player_state.php`'s
  catch blocks, so rejected purchases/runs/reward-claims and hard errors
  are now queryable instead of vanishing into a server log nobody checks.
- **Maintenance mode**: `arcade_system_settings` table (key/value),
  toggled from `admin/arcade_system.php`. Enforced in
  `arcade_player_action.php`, `arcade_player_state.php` (both return a
  503 with the message instead of processing), and `arcade.php` itself
  (shows a real maintenance page instead of loading the game).
- **`admin/arcade_system.php`** -- DB table verification (via the
  existing `INFORMATION_SCHEMA` pattern, not `SHOW ... LIKE`), maintenance
  mode toggle, and the event log viewer with severity/context filters.
- **`admin/arcade_player_recovery.php`** -- per-player tools: create a
  backup snapshot, restore from one (always auto-snapshots the
  *current* state first, so a restore is itself undoable), repair
  (re-validates equipped character is actually owned), and retry the
  cloud-to-server migration (safe to re-run -- shard/stat merges use
  `GREATEST()`, never decrease existing progress).
- **Snapshot capture/restore** lives in `player_authority.php` as
  `arcade_authority_capture_snapshot()` / `_save_snapshot()` /
  `_restore_snapshot()` / `_repair_player()`. Snapshots store the *raw*
  rows from `arcade_player_accounts`/`_characters`/`_inventory`/
  `_reward_claims` (not the compiled client-facing state shape), so a
  restore is an exact delete-and-reinsert, not a reverse-engineering
  problem.
- **Version footer**: `arcade_version_string()` (currently `'v3.7.4'`,
  a single hardcoded return -- bump it by hand each release) rendered
  in `game_shell.php` and included in the `__dodArcadeAuthority` JS
  config.
- **Cache-busting was already fully implemented** before this build --
  `arcade_asset_url()` in `bootstrap.php` appends `?v=<filemtime>` to
  every CSS/JS include in `views/game.php` and `views/partials/runtime.php`.
  Nothing needed fixing there.
- **Loading indicator**: `server-authority.js` now toggles
  `body.dod-server-busy` for the duration of any in-flight request
  (CSS animated bar in `controls.css`), and fires a
  `dod:server-authority-loading` event for feature-specific spinners.
- New tables: `arcade_event_log`, `arcade_player_snapshots`,
  `arcade_system_settings` (all in `db_connect.php`, grouped with the
  other `arcade_player_*` tables).

### v3.8 — Seasons and Leaderboards (built 2026-07-17)

**Deliberate scoping decision**: leaderboards are computed on the fly
from `arcade_player_run_receipts` (already has customer_id,
character_id, score, submitted_at), NOT from a separate synced
`arcade_leaderboard_entries`/`arcade_player_season_progress` table pair
as the roadmap suggested -- avoids a whole class of sync bugs for a
boutique-scale site. Global/weekly/monthly are just date-range filters
over the same receipts; character-specific/Classic Paddle are a
`character_id`/`character_form` filter. "Seasonal XP" is the sum of
`mastery_xp_reward` from runs within the season's date range -- no
separate progress table needed either.

- **New column**: `arcade_player_run_receipts.character_form` (added
  via the throttled schema-repair block in `db_connect.php`, same
  pattern as the existing `arcade_scores` column additions) --
  needed because a "Classic Paddle leaderboard" requires knowing which
  form was played per run, which wasn't tracked before. Populated by
  `arcade_player_action.php`'s `submit_run` from a new `form` field the
  client now sends (`server-authority.js` reads it from
  `localStorage.dod_arcade_character_v2.form`).
- **`arcade_app/includes/arcade_leaderboard.php`** -- the shared query
  layer: `arcade_leaderboard_active_season()` (lazily flips an expired
  'active' season to 'ended' on read, no cron needed), 
  `arcade_leaderboard_rows()` (one best run per player, excludes
  flagged runs via `LEFT JOIN arcade_score_flags ... IS NULL`),
  `arcade_leaderboard_rank_tier()` (percentile-based: top 5%=Diamond,
  20%=Gold, 50%=Silver, 80%=Bronze, rest=Dirt -- fair across seasons
  with different score distributions, not a fixed score threshold),
  `arcade_leaderboard_personal_best()`, `arcade_leaderboard_season_xp()`.
- **`arcade_leaderboard.php`** (public page) -- scope tabs, character/
  Classic-Paddle filters, personal best, live season countdown. Uses
  `partials/header.php` like `account/arcade.php` does (own `<head>`,
  no footer), not the arcade's own dark-monospace admin styling.
- **`admin/arcade_seasons.php`** -- create/activate/archive seasons
  (only one can be 'active' at a time -- activating one auto-ends any
  other), configure rank-range reward tiers (`arcade_season_rewards`).
- **`admin/arcade_leaderboards.php`** -- flag/unflag runs
  (`arcade_score_flags`). Flagging hides a run from every public scope
  but never deletes the receipt or claws back Shards/mastery already
  paid out at run-submit time -- moderation, not punishment.
- **Reward claiming**: `claim_season_reward` action in
  `arcade_player_action.php` -- only claimable once a season's status
  is `ended`/`archived`, computes the player's final rank via
  `arcade_leaderboard_rows()`, matches it to a reward tier, pays out
  once (`arcade_season_reward_claims` has a composite PK on
  customer_id+season_id).
- **Season countdown inside the arcade**: `runtime_data.php` passes
  the active season's name/end-time into `__dodArcadeAuthority`;
  `server-authority.js` populates `#arcadeSeasonBanner` (added in
  `game_shell.php`) and links to the leaderboard page.

### Recurring bug across RPG releases: lookbook backgrounds

The lookbook photo rotation system only activated in Runway mode, not
Survival mode, across at least 4 separate releases before it got fixed
upstream (confirmed fixed as of v3.4/v3.4.1). If a new release regresses
this, the fix is: call `pickRunwayBackground()` unconditionally on run
start (not just inside the `gameMode === 'runway'` block), and check
`runwayBgImage && runwayBgImage.loaded` at the draw() call site instead
of checking `gameMode === 'runway'`.

### Music system

Gameplay music is a separate system from per-product music on product
pages. Tracks are managed at `admin/arcade_music.php`, stored in the
`arcade_music_tracks` table, fetched via `arcade_fetch_music_tracks()`
in `catalog_repository.php`, exposed to the client as
`window.__dodArcadeMusicTracks`. One random active track plays per run.

Audio in `arcade.js` runs through TWO paths: an HTML `<audio>` element
(`arcadeMusicEl`) for the uploaded music tracks, and Web Audio
(`musicGain`/`sfxGain`) for synth ambience + sound effects. Volumes are
`settings.musicVolume` / `settings.sfxVolume` (0-100, sliders in the
Settings dialog). There is **no** mute toggle in the code today (an
earlier `settings.musicMuted` note in this file was aspirational — it
never existed).

**⚠️ Attempted mute button + start-menu login button on 2026-07-17 were
REVERTED** after the user reported the change "crashed my arcade." The
edits (a `#arcadeMuteBtn` + `settings.muted` in `arcade.js`/`game_shell.php`/
`controls.css`, and a conditional `#arcadeOverlayLogin`) all passed
`node --check` and were individually null-guarded, so the crash cause
was never reproduced/confirmed here (no PHP/browser runtime available).
**Strong suspicion: the local `arcade.js` is DRIFTED from the live one**
(this project's recurring file-drift pattern) — uploading the local base
may have clobbered a newer live `arcade.js`. Before re-attempting arcade
UI changes, get the user's CURRENT live `arcade.js` and diff it, and ask
for the actual browser console error.

## Casting / model submissions system

`admin/model_submissions.php` — review casting applications. Has grown
significantly:
- Shoot scheduling (`model_shoot_schedule` — one model, one date)
- Group events (`model_shoot_events` + `model_event_assignments` — many
  models linked to one show/event, with per-model email notification
  tracking)
- Two-way messaging (`model_messages`) — models see and reply to
  messages through their **regular customer account**, not a separate
  login. The link is by email match: if a logged-in customer's email
  matches a `model_submissions.email` (case-insensitive), their account
  shows an extra "CASTING" tab. This pattern (link by email match,
  no separate model auth system) is deliberate and should be reused if
  extending this further, rather than building a parallel login system.

Models have no notification channel other than email — no push, no app,
no login-based alerts beyond what shows when they next open their
account.

## AI listing tool

`ai_listing.php` (upload + review + publish), backed by
`ai_listing_upload.php` (calls Claude API, model `claude-haiku-4-5-20251001`,
creates a draft — never publishes automatically) and
`ai_listing_publish.php` (admin publishes immediately; seller
submissions go to `pending_review`, requiring approval at
`admin/ai_listing_review.php`). Pricing suggestions
(`ai_pricing_estimate.php`) use only this site's own historical sales —
no external/public resale-price data source is wired in, and doing so
would need a deliberate choice of paid API or licensed data, not a
scraper.

Known gaps versus a more sophisticated spec the user has referenced:
only one overall confidence score (not per-field), no condition/pattern
classification, one photo per listing, no separate pricing logic for
one-of-one custom/handmade pieces.

**Correction (2026-07-17):** true visual/image-embedding similarity is
not actually a gap -- it exists at `similar_products.php` (cosine
similarity over Voyage embeddings stored via `embedding_helper.php`)
and is wired into `product.php`'s "Visually Similar" section, alongside
the separate attribute-based section from `related_products.php`. Click
tracking for it goes through `recommendation_track_click.php` (renamed
from a typo'd `ecommendation_track_click.php` that nothing was calling).

## Outstanding, unresolved items

- **`checkout_success.php` / `stripe_success.php` are both broken**
  (found 2026-07-17, still unresolved): `checkout_success.php`'s entire
  body is a near-duplicate of `create_checkout_session.php` -- it
  creates a NEW Stripe session instead of verifying the one from
  Stripe's redirect, never reads `$_GET['session_id']`, and requires
  POST (Stripe's redirect is GET), so a real paying customer hits a
  raw JSON 405 error instead of a confirmation page. `stripe_success.php`
  does read `session_id` and call `Session::retrieve()`, but never
  checks `payment_status === 'paid'` before marking an order PAID, and
  expects `metadata->order_id`, which `create_checkout_session.php`
  never sets. The correct reference pattern already exists at
  `offer_pay_success.php` (verifies payment_status, then records the
  order). Fixing this is blocked on seeing `stripe_webhook.php` (a real,
  intended file per `.htaccess`'s protected-endpoints rule, not yet
  shared) to know whether the webhook already creates/verifies orders
  server-side, since writing the success pages wrong risks duplicate
  order rows.
- **Email deliverability**: a real user's verification email logged as
  "sent" but never arrived. Spam-folder check was the first step;
  migrating off PHP's `mail()` to a real transactional provider is the
  likely real fix if that's ruled out.
- **AI listing tool gap, decided 2026-07-17**: `ai_listing.php` and
  `ai_listing_upload.php` aren't in this local folder, only
  `ai_listing_publish.php` and `admin/ai_listing_review.php` are.
  Decision: do NOT rebuild these from scratch. The `ai_listing_drafts`
  table schema (`source_type`, `image_path`, `ai_brand/type/color/
  material/title/description/confidence/raw_response`, `asking_price`,
  `edited_title/description`, `status`) is complete and precise, and
  `ai_listing_publish.php` already reads/writes every column correctly
  -- strong evidence the upload half was actually built and just isn't
  synced to this local folder (same file-drift pattern as elsewhere in
  this project), not that it's genuinely missing. Rebuilding blind risks
  a second, conflicting implementation (wrong Claude prompt shape, wrong
  field mapping) instead of recovering the real one. Get the live file
  from the user before writing anything here.
- **Exchange live-status polling** (`exchange.php`'s toast/sound/haptic
  alert for new pending offers) targets `/api/exchange_live_status.php`,
  which doesn't exist. Fails silently (caught, no crash) -- the feature
  just never fires.
- Two customer-account features requested but not yet built: changing
  email after signup, and re-verifying an existing email is correct.
- **Six referenced-but-never-built pages/endpoints** (found 2026-07-17
  via a full site-wide link scan, not typos -- no similarly-named file
  exists anywhere): `customer_photos.php` (admin page for the
  `customer_photos` social-proof table), `cleanup-carts.php`,
  `add_product.php`, `settings.php` (all from `admin/dashboard.php`),
  `export.php` (from `admin/import.php`), and `admin/post_purchase_email_check.php`
  (a cron script -- the other three cron scripts, `low_stock_check.php`/
  `abandoned_cart_check.php`/`referral_reward_check.php`, all exist; if
  this one is registered as a live cPanel cron job, it's been failing
  silently on every run). **The dead nav links/hrefs pointing to the
  first five have been removed** so the admin UI no longer shows
  buttons that 404 -- but the underlying pages/features themselves were
  NOT built, since each needs real product decisions (what fields, what
  settings, what export format). Revisit if any of these are wanted.

**Resolved this session (2026-07-17)**: `related_products.php` is now
wired into `product.php` (replaced the old same-category-only block);
`similar_products.php` (visual-embedding similarity) is also wired in
as a second "Visually Similar" section, with its click-tracker renamed
from a typo'd `ecommendation_track_click.php`. Also fixed: 3 silently-
broken `SHOW ... LIKE :param` schema-check functions (see "Database"
section), a dead footer include, 2 dead duplicate files, a misplaced
`push_subscribe.php`, a typo'd `.ph` extension on `backfill_embeddings`,
a redundant/conflicting arcade mobile-controls CSS layout, and 5 dead
admin nav links (removed, pages still don't exist -- see above).
