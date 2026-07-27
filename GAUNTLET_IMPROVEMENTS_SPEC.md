# Gauntlet Mode — Improvement Spec (for the arcade builder)

Target version: builds on v4.6.27.9.1 (Gauntlet Start Hub Loader Hotfix).
Purpose: hand this to the process that maintains `gauntlet-mode-v46279.js` /
`gauntlet-mode-v46279.css` so these land as a properly-versioned, tested
hotfix (they are gameplay/UX changes that need to be *played* to verify).

Context on file layout (confirmed on this install):
- Game loads `arcade.js` from `/arcade_app/assets/js/arcade.js` (via
  `runtime.php` → `arcade_asset_url()`), and the builder also mirrors it to
  top-level `/assets/js/arcade.js`.
- `arcade.js` dynamically loads:
  - `/arcade_app/assets/js/gauntlet-progression-v46279.js` (career module)
  - `/assets/js/gauntlet-mode-v46279.js` (mode module, **top-level** `/assets/`)
- `gauntlet-mode-v46279.js` loads `/assets/css/modules/gauntlet-mode-v46279.css`
  and `gauntlet-progression-v46279.js` loads
  `/assets/css/modules/gauntlet-progression-v46279.css` — both **top-level**.
- Gauntlet renders into its own overlay `.dod-gauntlet-ui` (absolute, inset:0,
  z-index 31, pointer-events:none) over `.dod-gauntlet-canvas` (z-index 24).
- `body.gauntlet-active` hides the normal game HUD, `#mobile-controls`, and
  `#arcadeOverlay` (see the hide list at the top of `gauntlet-mode-v46279.css`).

Cache-busting note: every time a gauntlet asset changes, bump its `?v=` query.
The chain is: edit CSS → bump `STYLE_URL` `?v` in the mode JS → bump the mode
JS `?v` in `arcade.js`'s loader (`script.src = '/assets/js/gauntlet-mode-v46279.js?v=N'`).
`arcade.js` itself auto-busts via `filemtime`. (A control-sizing CSS tweak was
already applied locally at `?v=3` — see item 5.)

---

## 1. Tap-to-move controls (mobile)

**Now:** movement is a 4-way D-pad (`.gauntlet-dpad`, up/left/down/right) bottom-left.
**Want:** tap-anywhere-to-move — the fighter moves toward where the player taps/holds
on the play field — as the primary mobile scheme, with the D-pad kept as an
optional fallback (a toggle in the arcade Settings dialog, persisted to
`localStorage` under `dod_arcade_settings_v2`, consistent with existing settings).

Implementation notes:
- Add `touchstart`/`touchmove`/`touchend` (and pointer equivalents) on
  `.dod-gauntlet-canvas`. Convert touch point → canvas coords (account for
  `getBoundingClientRect()` scale) → set the fighter's move target; move toward
  it each tick, stop within a small deadzone.
- Keep it from fighting the action buttons: the buttons live in a separate
  layer with `pointer-events:auto`; the canvas handler should ignore touches
  that start on a control button.
- Preserve keyboard/desktop movement unchanged.
- **Must be playtested** for feel (deadzone, max speed, diagonal handling).

## 2. Clear Hack + Special attack buttons

**Now:** four action buttons (DASH / HEAVY / SPECIAL / ATK) in `.gauntlet-actions`.
**Want:** the primary attack read as **HACK** (the hack-and-slash basic attack)
and a clearly separate **SPECIAL**, so the two core combat actions are obvious.

Options (builder's call):
- Simplest: relabel `ATK` → `HACK` and keep SPECIAL, and visually promote both
  (bigger, primary-accent styling) while de-emphasizing DASH/HEAVY.
- If HACK should be its own move (not just a rename of the basic attack), wire a
  new action id in the gauntlet input map + combat logic.
- Confirm what HEAVY vs SPECIAL currently do so labels aren't redundant to players.

## 3. Music player + pause button inside Gauntlet

**Now:** the normal audio toolbar (`.arcade-stage-toolbar` in `game_shell.php`,
containing `#arcadeMuteBtn`, the music player `#arcadeMusicToggleBtn` /
prev / next / volume, and pause `#arcadeOverlayPause`) is not usable during a
Gauntlet run — Gauntlet has no in-overlay audio/pause controls.
**Want:** a **pause button** and **music controls** (at least play/pause + mute,
ideally prev/next/volume) reachable during Gauntlet.

Implementation notes:
- Add a small control cluster to `.dod-gauntlet-ui` (give that cluster
  `pointer-events:auto`; the overlay itself is `pointer-events:none`).
- Reuse the existing audio system rather than duplicating it — dispatch to /
  reuse the same handlers that drive `#arcadeMuteBtn` and the music player, and
  reuse `#arcadeOverlayPause`'s pause path so Gauntlet pause == game pause.
- Place it where it won't overlap the top HUD or the play field — e.g. a compact
  top-right pill, clear of `.gauntlet-score-copy`.

## 4. Continue-run with Shards (after death, in Gauntlet)

**Now:** dying in Gauntlet ends the run.
**Want:** on death, offer "Continue — spend N Shards" (and/or a Boss Rush /
continue token), matching how the main game's continue works.

Implementation notes:
- The endpoint already used elsewhere is **`/arcade_continue_run.php`** (POST,
  JSON `{runId, transactionId}`, header `X-Arcade-CSRF`). It's called by
  `arcade.js` (~line 3706) and via `server-authority.js`'s
  `DOD_SERVER_AUTH.continueRun` (`config.continueUrl || '/arcade_continue_run.php'`).
- **Dependency / blocker:** `arcade_continue_run.php` is present on live but was
  **missing from the local export** used for this review — confirm it's deployed
  before wiring Gauntlet to it. Reuse it (don't fork a second continue endpoint).
- Gauntlet death flow: show a continue modal (reuse `.gauntlet-modal`), call the
  continue endpoint, and on success resume the current run at the death point
  with whatever HP/recovery the continue grants; on decline/failure, go to the
  normal Gauntlet game-over/reward-save path.
- Server must remain authoritative on the shard cost and deduction (never trust
  the client for the spend).

## 5. Already applied locally (control sizing) — fold into your versioning

A low-risk CSS-only touch-target fix was applied to
`assets/css/modules/gauntlet-mode-v46279.css` in the mobile block
(`@media (pointer:coarse),(max-width:760px)`):
- `.gauntlet-dpad` cells 48px → **56px**, gap 3 → 4px
- `.gauntlet-actions` columns 58/68 → **70/78px**, rows 52/58 → **58/62px**, gap 5 → 6px
- button font 10 → **11px**, radius 4 → **9px**
- `.gauntlet-mobile-controls` bottom 8 → **16px** (more thumb clearance)

Cache: `STYLE_URL` bumped to `?v=3` in `gauntlet-mode-v46279.js`, and the mode-JS
loader in both `arcade.js` copies bumped to `?v=3`. Re-apply/renumber as needed
when you cut the next version so it isn't lost.

## 6. Admin coverage for Gauntlet (currently MISSING)

Gauntlet is the only mode/system with **no admin surface at all**. The server
side exists — `arcade_app/includes/arcade_gauntlet.php` tracks per-account
progression in `arcade_gauntlet_progress` (wins, best_score, highest_level,
boss_kills, mini_boss_kills, banked_shards_earned) and validates run summaries —
but nothing under `admin/` references Gauntlet. For contrast, Boss Rush has a
runtime on/off in `arcade_feature_access.php`, balance in `arcade_bosses.php`,
and the emergency switch in `arcade_diagnostics.php`. Gauntlet has none of that.

Add `admin/arcade_gauntlet.php` (linked from the arcade admin nav / control
center), following the existing admin page conventions — `require auth_check.php`,
`arcade_schema_ensure($pdo)`, CSRF via `$_SESSION['csrf']` + `hash_equals`, and
the same dark monospace styling as the other `admin/arcade_*.php` pages — with:

- **Stats / leaderboard (read-only):** top rows from `arcade_gauntlet_progress`
  (wins, best_score, highest_level, elite/boss kills, shards earned), searchable
  by customer. Immediate operator visibility into who's playing Gauntlet.
- **Enable / disable:** mirror the Boss Rush runtime-switch pattern
  (feature-access key + `STABILITY_RUNTIME`/diagnostics) so Gauntlet can be turned
  off without a code push.
- **Difficulty / reward tuning:** level count ("five levels"), Elite-mob rate,
  Dirt Crown boss settings, and the Banked-Shard/material reward curve — currently
  hardcoded in the JS + `arcade_gauntlet.php`. Move these into an editable settings
  row (e.g. `arcade_system_settings` or the balance store), with the **server
  staying authoritative** on the reward math (never trust client-submitted totals;
  `arcade_gauntlet.php` already validates summaries — keep that).

Also confirm/restore the two admin pages `admin_nav.php` links to but that are
absent from the current export: `admin/arcade_control_center.php` (the arcade
admin hub) and `admin/arcade_achievements.php`. They're referenced by the nav, so
they likely exist on live and simply weren't exported — verify (a 404 means
genuinely missing). Several admin tools (`arcade_ghosts`, `arcade_characters`,
`arcade_push`, `arcade_admin_catalog`, `arcade_growth`, `arcade_runway`,
`arcade_bestiary`) have no direct nav link and are probably reached *through* the
control center — restoring it fixes their access too.

## 7. Obvious, dynamic objective box

**Now:** the current-objective text (`<small id="gauntletObjective">`, set in
`updateHud()` at ~line 2288-2292) is buried inside `.gauntlet-level-copy`, one of
three cramped corner boxes in `.gauntlet-top-hud` (font-size 10px, shares space
with the level name). Players lose track of what actually clears the level mid-fight.

**Found while reviewing this:** the level-5 "CROWN INCOMING" branch (line 2291,
`else` when `!boss && !levelBossPending`) is effectively **dead text**.
`levelBossPending` only flips to `false` inside `spawnEnemy('boss')` itself
(line ~1493-1495: `if (levelBossPending && spawnRemaining<=0 && enemies.length===0
&& spawnTimer<=0) { spawnEnemy('boss'); levelBossPending=false; }`), and
`spawnEnemy` pushes the boss into `enemies` synchronously in that same call — so
by the time `updateHud()` reads `enemies.find(e => e.type==='boss')` next frame,
the boss already exists. There is no in-between frame where "CROWN INCOMING" can
actually show. (`showEncounterAlert('BOSS SIGNAL', ...)` at line 1381 does
telegraph the boss via the popup alert, so this isn't a total blind spot — but
the persistent objective line itself never gets to say it.)

**Want:** promote the objective into its own standalone, obviously-legible HUD
element — larger type, full-width or centered, color/urgency-coded by state —
distinct from the existing three-box `.gauntlet-top-hud` row. Reuse the existing
`refs.objective` / `#gauntletObjective` wiring (don't fork a second state
tracker), just re-parent and restyle it, and extend the state text to read more
like a call to action:

| Real game state (existing code) | Current text | Wanted phrasing |
|---|---|---|
| Levels 1-4, mobs remaining (`levelKills < def.kills`) | `X / Y DEFEATED` | `DEFEAT N REMAINING` (N = `def.kills - levelKills`) or `CLEAR WAVE — X/Y` |
| Level 5, mobs still spawning (`levelBossPending`) | `FINAL WAVE X / Y` | `FINAL WAVE — DEFEAT N REMAINING` |
| Level 5, boss about to spawn (the dead branch above) | `CROWN INCOMING` (never actually shows) | **Fix the underlying timing first** — hold `levelBossPending` true (and show `BOSS ARRIVING`) for a short telegraph beat (e.g. reuse the `showEncounterAlert` cadence, ~1-1.5s) before calling `spawnEnemy('boss')`, so this state has a real window to display |
| Level 5, boss alive | `CROWN XX%` | keep as-is, maybe prefix `DEFEAT THE CROWN — XX%` |
| Upgrade choice open (`gauntletUpgradeModal` shown, see `openUpgradeChoice()` ~line 2049) | modal card says "CHOOSE YOUR EDGE" | have the objective box also flip to `CHOOSE YOUR EDGE` while the modal is open, so it stays consistent with whatever's on screen (mobile users backgrounding/re-focusing the tab) |

No time-based "SURVIVE Ns" state exists anywhere in Gauntlet today (all levels are
kill-count or boss-HP gated) — don't invent one; only wire phrasing for states
that actually exist above.

Implementation notes:
- Add a new element (e.g. `#gauntletObjectiveBox`, sibling of `.gauntlet-top-hud`
  inside `.dod-gauntlet-ui`) instead of stacking more into the cramped
  `.gauntlet-level-copy` box. Keep `id="gauntletObjective"` on the text node
  inside it so `refs.objective = document.getElementById('gauntletObjective')`
  and the existing `updateHud()` assignment lines keep working unchanged.
- Style similar weight to `.gauntlet-level-splash`/`.gauntlet-encounter-alert`
  (bold, letter-spaced, neon-green border) but persistent (not a transient
  popup) and smaller/less intrusive than the full-screen splash — think a
  banner just under `.gauntlet-top-hud`.
- Reuse the existing `.danger` pulse pattern already on `.gauntlet-threat-line`
  (`gauntletThreatPulse` keyframe) for the "boss arriving" / low-mobs-remaining
  states, so urgency has a visual language already established in this HUD
  rather than a new one.
- Must not overlap `.gauntlet-threat-line` (currently `top:158px`) or the
  `.gauntlet-encounter-alert`/`.gauntlet-level-splash` popups — check all the
  responsive breakpoints already in `gauntlet-mode-v46279.css` (mobile portrait,
  landscape-compact, etc. — there are several `top:` overrides for
  `.gauntlet-threat-line` alone) since a new banner shifts vertical space on
  every one of them.
- **Must be playtested** — HUD layout changes at this density are easy to get
  right on desktop and wrong on the mobile breakpoints.

---

### Suggested build order
1. #5 control sizing (done, verify on device) → 2. #2 HACK/SPECIAL labels+styling →
3. #3 pause+music in overlay → 4. #6 Gauntlet admin (start with the read-only
stats view; low risk, high operator value) → 5. #7 objective box (self-contained,
moderate playtest effort, fixes the dead "CROWN INCOMING" state as a side effect)
→ 6. #1 tap-to-move (biggest playtest effort) → 7. #4 continue-with-shards
(after confirming `arcade_continue_run.php` is live).
