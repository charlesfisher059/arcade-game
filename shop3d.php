<?php
/**
 * shop3d.php — 3D SHOP ENTRY POINT (v4.8 REFLECTION ENV + EXPOSURE HOTKEYS + NORMALIZE MATERIALS + STATS + SHOPPABLE PEDESTALS)
 * Path: /home2/asqrtyte/public_html/shop3d.php
 * Loads: /assets/models/environment.glb
 *
 * PATCH v4.8 (SHOPPABLE PRODUCTS):
 * - Real products now fetched from /api/inventory.php and arranged as
 *   clickable glowing pedestals in a circle around the diamond centerpiece.
 * - Click/tap a pedestal to open a product panel (name, price, Add to Bag,
 *   View Details) -- reuses the site's real cart endpoint (/api/cart-add.php)
 *   with the full product payload, so items don't land in the cart at $0.
 * - No proximity auto-snap / camera locking -- click-to-open only, so
 *   orbit controls are never disabled and can't get stuck.
 * - Uses /css/shop3d.css for the panel UI (previously not linked on this page).
 * - Exit link now points to / instead of /index.php (avoids an extra 301).
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$HTTPS = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
      || (isset($_SERVER['SERVER_PORT']) && (int)($_SERVER['SERVER_PORT']) === 443)
      || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

if (session_status() === PHP_SESSION_NONE) {
    $opts = [
        'cookie_httponly' => true,
        'cookie_secure'   => $HTTPS,
    ];
    if (defined('PHP_VERSION_ID') && PHP_VERSION_ID >= 70300) {
        $opts['cookie_samesite'] = 'Lax';
    }
    session_start($opts);
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$debug = isset($_GET['debug']) && $_GET['debug'] === '1';
?>
<!DOCTYPE html>
<html lang="en" class="tech-archive shop3d-mode">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>SHOP 3D // DIAMONDS OUTTA DIRT</title>
    <?php if (is_file(__DIR__ . '/includes/tracking_pixels.php')) { require_once __DIR__ . '/includes/tracking_pixels.php'; } ?>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#000000">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="DOD">
    <script src="/js/pwa-init.js" defer></script>

    <link rel="stylesheet" href="/css/master.css">
    <link rel="stylesheet" href="/css/shop3d.css">
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Syncopate:wght@400;700&display=swap" rel="stylesheet">

    <style>
        :root { --accent: #00ff00; --border: #222; --bg: #000; }

        html, body {
            margin: 0; padding: 0;
            width: 100%; height: 100%;
            overflow: hidden;
            background: var(--bg);
            color: #fff;
            font-family: 'Space Mono', monospace;
        }

        #shop3d-root { position: fixed; inset: 0; background: #000; z-index: 1; }

        .shop3d-hud {
            position: fixed; top: 0; left: 0; width: 100%;
            padding: 18px 20px; z-index: 10;
            display: flex; justify-content: space-between; align-items: flex-start;
            pointer-events: none;
        }
        .hud-item { pointer-events: auto; }
        .hud-status {
            font-size: 0.62rem;
            color: var(--accent);
            letter-spacing: 2px;
            text-transform: uppercase;
            text-shadow: 0 0 10px rgba(0,255,0,0.06);
        }
        .status-dot {
            display: inline-block; width: 6px; height: 6px;
            background: var(--accent); border-radius: 50%; margin-right: 8px;
            box-shadow: 0 0 5px var(--accent); animation: pulse 2s infinite;
        }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.3; } 100% { opacity: 1; } }

        .exit-archive {
            text-decoration: none; color: #fff; border: 1px solid var(--border);
            padding: 8px 15px; font-size: 0.62rem; transition: 0.3s; background: rgba(0,0,0,0.55);
        }
        .exit-archive:hover { border-color: var(--accent); color: var(--accent); }

        #boot-loader {
            position: fixed; inset: 0; background: #000; z-index: 100;
            display: flex; flex-direction: column; justify-content: center; align-items: center;
            transition: opacity 0.8s ease;
        }
        .boot-text { font-family: 'Syncopate', sans-serif; font-size: 1.15rem; margin-bottom: 18px; color: #fff; }
        .progress-track { width: 320px; height: 2px; background: #222; margin-bottom: 14px; position: relative; }
        .loader-fill { width: 0%; height: 100%; background: var(--accent); transition: width 0.2s linear; box-shadow: 0 0 10px var(--accent); }
        .loader-status { font-size: 0.72rem; color: #666; letter-spacing: 1px; text-transform: uppercase; max-width: 88vw; text-align:center; }

        #debug-terminal {
            position: fixed;
            left: 18px;
            bottom: 18px;
            z-index: 999;
            width: min(620px, 92vw);
            max-height: 46vh;
            overflow: auto;
            border: 1px solid rgba(0,255,0,0.35);
            background: rgba(0,0,0,0.72);
            box-shadow: 0 0 22px rgba(0,255,0,0.10);
            padding: 12px 12px 10px;
            font-size: 0.68rem;
            color: #b7ffb7;
            display: none;
            white-space: pre-wrap;
            line-height: 1.35;
        }
        #debug-terminal strong { color: #00ff00; }
        #debug-terminal .dim { color: #6ea36e; }
        #debug-terminal .warn { color: #ffcc00; }
        #debug-terminal .bad  { color: #ff5555; }

        /* Pedestal hover cursor when hovering an interactive product marker */
        #shop3d-root.hovering-product { cursor: pointer; }

        /* Defensive rule (duplicated from shop3d.css): guarantees the
           full-screen #shop3d-ui wrapper never blocks clicks to the canvas
           underneath when no panel is open, even if shop3d.css fails to
           load for any reason. Without pointer-events:none here, this
           fixed full-viewport div would silently swallow every click/tap
           on the 3D scene, which looks identical to "pedestals aren't
           clickable" from the user's perspective. */
        #shop3d-ui { pointer-events: none; }
        #shop3d-ui.visible { pointer-events: auto; }

        /* ===============================
           MOBILE
           - Activated by JS adding .shop3d-mobile to <body> (also unlocks
             the .shop3d-mobile rules already defined in shop3d.css)
        =============================== */
        @media (max-width: 768px) {
            .shop3d-hud {
                padding: 14px 14px;
            }
            .hud-status {
                font-size: 0.58rem;
            }
            /* Desktop keyboard hotkeys are meaningless on a touchscreen -- hide that line */
            .hud-status .hotkeys-line {
                display: none;
            }
            .exit-archive {
                padding: 10px 12px;
                font-size: 0.6rem;
                white-space: nowrap;
            }
            .boot-text { font-size: 0.95rem; }
            .progress-track { width: 260px; }
            #debug-terminal {
                left: 10px;
                right: 10px;
                bottom: 10px;
                width: auto;
                max-height: 34vh;
                font-size: 0.62rem;
            }
        }

        @media (max-width: 480px) {
            .shop3d-hud {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            .hud-item:last-child {
                align-self: flex-end;
            }
        }
    </style>
<?php @include_once __DIR__ . '/includes/bg_styles.php'; ?>
<script src="/js/telemetry.js" defer></script>
</head>

<body class="shop3d">

<div id="boot-loader">
    <div class="boot-text" id="boot-title">INITIALIZING_CORE</div>
    <div class="progress-track"><div class="loader-fill" id="loader-bar"></div></div>
    <div class="loader-status" id="loader-msg">[SYS_CHECK: CONNECTING...]</div>
</div>

<div class="shop3d-hud" id="hud">
    <div class="hud-item">
        <div class="hud-status" id="hud-status">
            <span class="status-dot"></span>ACTIVE_SESSION: 3D_SHOWROOM_v4.8
            <div class="dim hotkeys-line" style="margin-top:6px; font-size:0.6rem; letter-spacing:1px;">
                GRID: <span id="hud-grid">ON</span>
                // EXP: <span id="hud-exp">1.80</span>
                // NORM: <span id="hud-norm">OFF</span>
                // KEYS: [G]RID [H]UD [D]IAMOND [E]NV [M]AT_DBG [N]ORM [+][-]EXP [X]AXIS
            </div>
            <div class="dim" style="margin-top:4px; font-size:0.6rem; letter-spacing:1px;">
                TAP A GLOWING MARKER TO VIEW A PRODUCT
            </div>
        </div>
    </div>
    <div class="hud-item">
        <a href="/" class="exit-archive">CLOSE_3D_MODULE</a>
    </div>
</div>

<div id="shop3d-root"></div>
<div id="debug-terminal"></div>

<!-- Product panel (shop3d.css provides all styling for these classes) -->
<div id="shop3d-ui">
    <div class="shop3d-panel" id="shop3d-panel" aria-hidden="true">
        <div class="shop3d-meta">
            <div class="shop3d-title" id="shop3d-title">PRODUCT</div>
            <div class="shop3d-price" id="shop3d-price">$0.00</div>
        </div>
        <div class="shop3d-actions" id="shop3d-actions">
            <button class="shop3d-btn primary" type="button" id="shop3d-add-btn">ADD TO BAG</button>
            <button class="shop3d-btn secondary" type="button" id="shop3d-view-btn">VIEW DETAILS</button>
        </div>
    </div>
    <button class="shop3d-exit" type="button" id="shop3d-exit-btn" aria-label="Exit product view">X</button>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<script src="https://cdn.jsdelivr.net/gh/mrdoob/three.js@r128/examples/js/controls/OrbitControls.js"></script>
<script src="https://cdn.jsdelivr.net/gh/mrdoob/three.js@r128/examples/js/loaders/GLTFLoader.js"></script>
<script src="https://cdn.jsdelivr.net/gh/mrdoob/three.js@r128/examples/js/environments/RoomEnvironment.js"></script>

<script>
(function(){
    const DEBUG = <?php echo $debug ? 'true' : 'false'; ?>;

    const ENV_URL_PLAIN = '/assets/models/environment.glb';
    const ENV_URL_BUST  = ENV_URL_PLAIN + '?v=' + Date.now();

    const term = document.getElementById('debug-terminal');
    function logLine(txt, cls) {
        if (!term) return;
        const line = document.createElement('div');
        if (cls) line.className = cls;
        line.textContent = txt;
        term.appendChild(line);
        term.scrollTop = term.scrollHeight;
    }
    function logKV(k, v, cls) {
        if (!term) return;
        const line = document.createElement('div');
        line.innerHTML = '<strong>' + k + '</strong> ' + String(v);
        if (cls) line.className = cls;
        term.appendChild(line);
        term.scrollTop = term.scrollHeight;
    }
    function bootTerm() {
        if (!term) return;
        term.style.display = DEBUG ? 'block' : 'none';
        if (DEBUG) {
            logLine('DEBUG TERMINAL ONLINE');
            logLine('DOD DEBUG // v4.8');
            logKV('ENV_URL_PLAIN:', ENV_URL_PLAIN);
            logKV('ENV_URL_BUST :', ENV_URL_BUST);
        }
    }

    const LoaderUI = {
        bar: document.getElementById('loader-bar'),
        msg: document.getElementById('loader-msg'),
        screen: document.getElementById('boot-loader'),
        set: function(percent, text) {
            const p = Math.max(0, Math.min(100, percent || 0));
            if (this.bar) this.bar.style.width = p + '%';
            if (this.msg) this.msg.innerText = text || '';
        },
        warn: function(text) {
            if (this.msg) {
                this.msg.innerText = text || 'WARNING';
                this.msg.style.color = '#ffcc00';
            }
            if (DEBUG) logLine(text || 'WARNING', 'warn');
        },
        error: function(text) {
            if (this.msg) {
                this.msg.innerText = text || 'ERROR';
                this.msg.style.color = 'red';
            }
            if (DEBUG) logLine(text || 'ERROR', 'bad');
        },
        finish: function() {
            this.set(100, 'ACCESS_GRANTED');
            setTimeout(() => {
                if (this.screen) {
                    this.screen.style.opacity = '0';
                    setTimeout(() => this.screen.remove(), 800);
                }
            }, 450);
        }
    };

    function sniffGLB(arrayBuffer) {
        const u8 = new Uint8Array(arrayBuffer);
        if (u8.length < 12) return { ok:false, reason:'TOO_SMALL' };
        const magic = String.fromCharCode(u8[0], u8[1], u8[2], u8[3]);
        return { ok: magic === 'glTF', magic };
    }

    function boundsOf(obj) {
        const box = new THREE.Box3().setFromObject(obj);
        const size = box.getSize(new THREE.Vector3());
        const center = box.getCenter(new THREE.Vector3());
        return { box, size, center };
    }

    function frameObject(camera, controls, object3d, padding) {
        padding = padding || 1.2;
        const b = boundsOf(object3d);
        const size = b.size, center = b.center;

        if (!isFinite(size.x + size.y + size.z) || (size.x === 0 && size.y === 0 && size.z === 0)) {
            return { ok:false };
        }

        const diag = Math.sqrt(size.x*size.x + size.y*size.y + size.z*size.z);
        const targetDim = Math.max(Math.max(size.x, size.z) * 1.35, diag * 0.65);

        const fov = camera.fov * (Math.PI / 180);
        let dist = (targetDim / 2) / Math.tan(fov / 2);
        dist *= padding;

        camera.position.set(center.x, center.y + (size.y * 0.10), center.z + dist);

        camera.near = Math.max(0.01, dist / 250);
        camera.far  = Math.max(2000, dist * 14);
        camera.updateProjectionMatrix();

        controls.target.copy(center);
        controls.update();

        return {
            ok:true,
            center: { x:center.x, y:center.y, z:center.z },
            size: { x:size.x, y:size.y, z:size.z },
            dist: dist
        };
    }

    function collectStats(root) {
        const stats = { meshes: 0, materials: 0, tris: 0, drawables: 0 };
        const mats = new Set();
        if (!root) return stats;

        root.traverse((obj) => {
            if (!obj) return;
            if (obj.isMesh) {
                stats.meshes += 1;
                stats.drawables += 1;
                const g = obj.geometry;
                if (g) {
                    if (g.index && g.index.count) {
                        stats.tris += Math.floor(g.index.count / 3);
                    } else if (g.attributes && g.attributes.position && g.attributes.position.count) {
                        stats.tris += Math.floor(g.attributes.position.count / 3);
                    }
                }
                const m = obj.material;
                if (Array.isArray(m)) {
                    m.forEach(mm => mm && mats.add(mm.uuid));
                } else if (m) {
                    mats.add(m.uuid);
                }
            } else if (obj.isLine || obj.isPoints || obj.isSprite) {
                stats.drawables += 1;
            }
        });

        stats.materials = mats.size;
        return stats;
    }

    window.addEventListener('load', function(){
        bootTerm();

        // Mobile detection -- also activates the .shop3d-mobile rules
        // already defined in /css/shop3d.css (previously unused since
        // nothing ever added this class)
        const isMobile = /iPhone|iPad|Android/i.test(navigator.userAgent) || window.innerWidth < 768;
        if (isMobile) {
            document.body.classList.add('shop3d-mobile');
        }

        if (typeof THREE === 'undefined') {
            LoaderUI.error('ERROR: THREE_NOT_LOADED');
            return;
        }

        LoaderUI.set(10, 'LIBRARIES_LOADED');

        const container = document.getElementById('shop3d-root');
        if (!container) {
            LoaderUI.error('ERROR: MISSING_ROOT_CONTAINER');
            return;
        }

        const hud = document.getElementById('hud');
        const hudGrid = document.getElementById('hud-grid');
        const hudExp  = document.getElementById('hud-exp');
        const hudNorm = document.getElementById('hud-norm');

        const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false });
        renderer.setSize(window.innerWidth, window.innerHeight);
        renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        renderer.toneMapping = THREE.ACESFilmicToneMapping;
        renderer.outputEncoding = THREE.sRGBEncoding;
        renderer.physicallyCorrectLights = true;

        let exposure = 1.80;
        renderer.toneMappingExposure = exposure;

        container.appendChild(renderer.domElement);
        renderer.domElement.style.touchAction = 'none';

        const scene = new THREE.Scene();
        scene.background = new THREE.Color(0x020202);
        scene.fog = new THREE.Fog(0x050505, 5, 2500);

        try {
            const pmrem = new THREE.PMREMGenerator(renderer);
            pmrem.compileEquirectangularShader();
            const roomEnv = new THREE.RoomEnvironment();
            const envRT = pmrem.fromScene(roomEnv, 0.04);
            scene.environment = envRT.texture;
            if (DEBUG) logKV('IBL_ENV:', 'RoomEnvironment (PMREM) ENABLED', 'dim');
        } catch (e) {
            if (DEBUG) logKV('IBL_ENV_FAIL:', e, 'warn');
        }

        const camera = new THREE.PerspectiveCamera(isMobile ? 58 : 45, window.innerWidth / window.innerHeight, 0.05, 12000);
        camera.position.set(0, 1.6, isMobile ? 8.5 : 6);

        const ambient = new THREE.AmbientLight(0xffffff, 1.10);
        scene.add(ambient);

        const hemi = new THREE.HemisphereLight(0xffffff, 0x111111, 0.85);
        scene.add(hemi);

        const key = new THREE.DirectionalLight(0xffffff, 3.00);
        key.position.set(6, 12, 6);
        scene.add(key);

        const rim = new THREE.PointLight(0x00ff00, 3.25, 4000);
        rim.position.set(-4, 3, -4);
        scene.add(rim);

        const controls = new THREE.OrbitControls(camera, renderer.domElement);
        controls.enableDamping = true;
        controls.enablePan = false;
        controls.minPolarAngle = Math.PI / 3.2;
        controls.maxPolarAngle = Math.PI / 1.75;
        controls.minDistance = 1.5;
        controls.maxDistance = 6000;

        window.addEventListener('resize', () => {
            camera.aspect = window.innerWidth / window.innerHeight;
            camera.updateProjectionMatrix();
            renderer.setSize(window.innerWidth, window.innerHeight);
        });

        LoaderUI.set(20, 'ENGINE_READY');

        let showGrid = true;
        const grid = new THREE.GridHelper(60, 60, 0x333333, 0x111111);
        grid.position.y = 0;
        scene.add(grid);

        const axis = new THREE.AxesHelper(10);
        axis.visible = false;
        scene.add(axis);

        let fallbackRoom = null;
        function makeFallbackRoom() {
            if (fallbackRoom) return;
            const roomGeo = new THREE.BoxGeometry(18, 9, 18);
            const roomMat = new THREE.MeshStandardMaterial({
                color: 0x070707, roughness: 0.95, metalness: 0.05, side: THREE.BackSide
            });
            fallbackRoom = new THREE.Mesh(roomGeo, roomMat);
            fallbackRoom.position.y = 4.0;
            scene.add(fallbackRoom);
        }

        let showDiamond = true;
        const diamondGeo = new THREE.OctahedronGeometry(1.2, 0);
        const diamondMat = new THREE.MeshPhysicalMaterial({
            color: 0x111111,
            roughness: 0.12,
            metalness: 0.9,
            transparent: true,
            opacity: 0.82,
            transmission: 0.22,
            clearcoat: 1.0
        });
        const diamond = new THREE.Mesh(diamondGeo, diamondMat);
        diamond.position.set(0, 1.5, 0);

        const coreGeo = new THREE.OctahedronGeometry(0.7, 1);
        const coreMat = new THREE.MeshBasicMaterial({ color: 0x00ff00, wireframe: true });
        const core = new THREE.Mesh(coreGeo, coreMat);
        diamond.add(core);

        const cageGeo = new THREE.OctahedronGeometry(1.4, 0);
        const cageMat = new THREE.MeshBasicMaterial({ color: 0xffffff, wireframe: true, transparent: true, opacity: 0.10 });
        const cage = new THREE.Mesh(cageGeo, cageMat);
        diamond.add(cage);

        scene.add(diamond);

        let envRoot = null;
        let showEnv = true;

        let forceEnvMaterial = false;
        const forcedMat = new THREE.MeshStandardMaterial({
            color: 0x00ff00,
            roughness: 0.6,
            metalness: 0.1,
            wireframe: true
        });

        let normalizeEnvMaterials = false;
        const originalMatState = new Map();

        function rememberMaterial(m) {
            if (!m || !m.uuid || originalMatState.has(m.uuid)) return;
            const snap = {
                metalness: (typeof m.metalness === 'number') ? m.metalness : null,
                roughness: (typeof m.roughness === 'number') ? m.roughness : null,
                color: (m.color && m.color.isColor) ? [m.color.r, m.color.g, m.color.b] : null,
                emissive: (m.emissive && m.emissive.isColor) ? [m.emissive.r, m.emissive.g, m.emissive.b] : null,
                emissiveIntensity: (typeof m.emissiveIntensity === 'number') ? m.emissiveIntensity : null,
                opacity: (typeof m.opacity === 'number') ? m.opacity : null,
                transparent: (typeof m.transparent === 'boolean') ? m.transparent : null
            };
            originalMatState.set(m.uuid, snap);
        }

        function restoreMaterial(m) {
            if (!m || !m.uuid) return;
            const snap = originalMatState.get(m.uuid);
            if (!snap) return;

            if (snap.color && m.color && m.color.isColor) m.color.setRGB(snap.color[0], snap.color[1], snap.color[2]);
            if (snap.emissive && m.emissive && m.emissive.isColor) m.emissive.setRGB(snap.emissive[0], snap.emissive[1], snap.emissive[2]);
            if (snap.metalness !== null && typeof m.metalness === 'number') m.metalness = snap.metalness;
            if (snap.roughness !== null && typeof m.roughness === 'number') m.roughness = snap.roughness;
            if (snap.emissiveIntensity !== null && typeof m.emissiveIntensity === 'number') m.emissiveIntensity = snap.emissiveIntensity;
            if (snap.opacity !== null && typeof m.opacity === 'number') m.opacity = snap.opacity;
            if (snap.transparent !== null && typeof m.transparent === 'boolean') m.transparent = snap.transparent;

            m.needsUpdate = true;
        }

        function normalizeMaterial(m) {
            if (!m) return;
            const isPBR = (m.isMeshStandardMaterial || m.isMeshPhysicalMaterial);
            if (!isPBR) return;

            rememberMaterial(m);

            if (typeof m.metalness === 'number') m.metalness = Math.min(m.metalness, 0.35);
            if (typeof m.roughness === 'number') m.roughness = Math.max(m.roughness, 0.65);

            if (m.color && m.color.isColor) {
                const luma = (m.color.r + m.color.g + m.color.b) / 3;
                if (luma < 0.10) {
                    const boost = 0.18;
                    m.color.r = Math.min(1, m.color.r + boost);
                    m.color.g = Math.min(1, m.color.g + boost);
                    m.color.b = Math.min(1, m.color.b + boost);
                }
            }

            if (typeof m.transparent === 'boolean' && typeof m.opacity === 'number') {
                if (m.transparent && m.opacity < 0.05) {
                    m.opacity = 0.15;
                }
            }

            m.needsUpdate = true;
        }

        let envBoxHelper = null;

        function applyEnvMaterialModes() {
            if (!envRoot) return;

            envRoot.traverse(obj => {
                if (!obj || !obj.isMesh) return;
                const mats = Array.isArray(obj.material) ? obj.material : [obj.material];
                mats.forEach(m => { if (m) rememberMaterial(m); });

                if (forceEnvMaterial) {
                    obj.material = forcedMat;
                    if (obj.material) {
                        obj.material.side = THREE.DoubleSide;
                        obj.material.needsUpdate = true;
                    }
                } else {
                    mats.forEach(m => {
                        if (!m) return;
                        m.side = THREE.DoubleSide;
                        if (normalizeEnvMaterials) {
                            normalizeMaterial(m);
                        } else {
                            restoreMaterial(m);
                            m.side = THREE.DoubleSide;
                        }
                        m.needsUpdate = true;
                    });
                    obj.material = Array.isArray(obj.material) ? obj.material : obj.material;
                }
            });

            if (envBoxHelper) envBoxHelper.visible = (forceEnvMaterial && DEBUG);
        }

        function syncHUD() {
            if (hudGrid) hudGrid.textContent = showGrid ? 'ON' : 'OFF';
            if (hudExp)  hudExp.textContent = exposure.toFixed(2);
            if (hudNorm) hudNorm.textContent = normalizeEnvMaterials ? 'ON' : 'OFF';
        }

        function setExposure(next) {
            exposure = Math.max(0.25, Math.min(3.50, next));
            renderer.toneMappingExposure = exposure;
            syncHUD();
            if (DEBUG) logKV('EXPOSURE:', exposure.toFixed(2), 'dim');
        }

        const gltfLoader = new THREE.GLTFLoader();

        async function loadEnvironment() {
            LoaderUI.set(30, 'FETCHING_ENVIRONMENT...');
            if (DEBUG) logKV('FETCH:', ENV_URL_PLAIN);

            let res;
            try {
                res = await fetch(ENV_URL_BUST, { cache: 'no-store' });
            } catch (e) {
                LoaderUI.warn('ENV_FETCH_FAILED');
                if (DEBUG) logKV('FETCH_ERROR:', e, 'bad');
                makeFallbackRoom();
                return;
            }

            if (DEBUG) {
                logKV('HTTP:', res.status + ' ' + res.statusText);
                logKV('CONTENT-TYPE:', res.headers.get('content-type') || 'n/a');
            }

            if (!res.ok) {
                LoaderUI.warn('ENV_HTTP_' + res.status);
                makeFallbackRoom();
                return;
            }

            const buf = await res.arrayBuffer();
            if (DEBUG) logKV('BYTES:', buf.byteLength);

            const sniff = sniffGLB(buf);
            if (!sniff.ok) {
                const preview = new TextDecoder().decode(new Uint8Array(buf).slice(0, 180));
                if (DEBUG) {
                    logKV('GLB_MAGIC:', sniff.magic || 'n/a', 'bad');
                    logKV('PREVIEW:', preview.replace(/\s+/g,' ').trim(), 'bad');
                }
                LoaderUI.warn('ENV_NOT_GLB // CHECK_FILE_CONTENT');
                makeFallbackRoom();
                return;
            }

            LoaderUI.set(55, 'PARSING_ENVIRONMENT...');
            const basePath = ENV_URL_PLAIN.substring(0, ENV_URL_PLAIN.lastIndexOf('/') + 1);

            try {
                gltfLoader.parse(
                    buf,
                    basePath,
                    (gltf) => {
                        envRoot = gltf.scene;

                        if (!envRoot) {
                            LoaderUI.warn('ENV_EMPTY_SCENE');
                            makeFallbackRoom();
                            return;
                        }

                        const raw = boundsOf(envRoot);
                        const rawMax = Math.max(raw.size.x, raw.size.y, raw.size.z);

                        if (DEBUG) {
                            logKV('RAW_CENTER:', JSON.stringify({x:raw.center.x,y:raw.center.y,z:raw.center.z}));
                            logKV('RAW_SIZE  :', JSON.stringify({x:raw.size.x,y:raw.size.y,z:raw.size.z}));
                            logKV('RAW_MAX   :', rawMax.toFixed(3));
                        }

                        if (rawMax > 120) {
                            const targetMax = 60;
                            const s = targetMax / rawMax;
                            envRoot.scale.setScalar(s);
                            if (DEBUG) logKV('AUTO_SCALE:', 'APPLIED x' + s.toFixed(6), 'dim');
                        } else {
                            if (DEBUG) logKV('AUTO_SCALE:', 'SKIPPED', 'dim');
                        }

                        const b = boundsOf(envRoot);
                        const center = b.center;
                        const minY = b.box.min.y;

                        envRoot.position.x -= center.x;
                        envRoot.position.z -= center.z;
                        envRoot.position.y -= minY;

                        scene.add(envRoot);

                        envRoot.traverse(obj => {
                            if (!obj || !obj.isMesh) return;
                            const mats = Array.isArray(obj.material) ? obj.material : [obj.material];
                            mats.forEach(m => {
                                if (!m) return;
                                rememberMaterial(m);
                                m.side = THREE.DoubleSide;
                                m.needsUpdate = true;
                            });
                        });

                        envBoxHelper = new THREE.BoxHelper(envRoot, 0x00ff00);
                        envBoxHelper.visible = false;
                        scene.add(envBoxHelper);

                        const framed = frameObject(camera, controls, envRoot, 1.15);

                        if (framed.ok) {
                            const maxDim = Math.max(framed.size.x, framed.size.y, framed.size.z);
                            scene.fog.near = Math.max(5, maxDim * 0.02);
                            scene.fog.far  = Math.max(300, maxDim * 6.0);
                            controls.maxDistance = Math.max(120, maxDim * 10.0);
                            controls.minDistance = Math.max(0.4, maxDim * 0.01);
                            controls.update();
                        }

                        const st = collectStats(envRoot);

                        LoaderUI.set(70, 'ENVIRONMENT_LOADED');

                        if (DEBUG) {
                            const after = boundsOf(envRoot);
                            logKV('ENV_SCENE:', 'ADDED');
                            logKV('AUTO_FRAME:', framed.ok ? 'OK' : 'FAILED', framed.ok ? 'dim' : 'bad');
                            if (framed.ok) {
                                logKV('CENTER:', JSON.stringify(framed.center));
                                logKV('SIZE:', JSON.stringify(framed.size));
                                logKV('FOG:', 'near=' + scene.fog.near.toFixed(2) + ' far=' + scene.fog.far.toFixed(2), 'dim');
                                logKV('CTRL_MAXDIST:', controls.maxDistance.toFixed(2), 'dim');
                            }
                            logKV('POST_SIZE:', JSON.stringify({x:after.size.x,y:after.size.y,z:after.size.z}), 'dim');
                            logKV('STATS:', 'meshes=' + st.meshes + ' mats=' + st.materials + ' tris=' + st.tris + ' draw=' + st.drawables, 'dim');
                            logLine('TIP: If it still looks too dark, press [N] to normalize materials, or [+] to boost exposure.', 'dim');
                        }

                        applyEnvMaterialModes();
                    },
                    (err) => {
                        LoaderUI.warn('ENV_PARSE_FAILED');
                        if (DEBUG) logKV('PARSE_ERROR:', err, 'bad');
                        makeFallbackRoom();
                    }
                );
            } catch (e) {
                LoaderUI.warn('ENV_THROWN');
                if (DEBUG) logKV('PARSE_THROW:', e, 'bad');
                makeFallbackRoom();
            }
        }

        /* =====================================================================
           SHOPPABLE PRODUCT PEDESTALS (v4.8)
        ===================================================================== */

        const pedestalGroup = new THREE.Group();
        pedestalGroup.name = 'ProductPedestals';
        scene.add(pedestalGroup);

        const pedestalRadius = 5.5;
        const productById = new Map();

        // Shared loader for product photos (composited onto a framed card texture)
        const productTexLoader = new THREE.TextureLoader();

        function makeProductCardTexture(image) {
            const cardW = 256, cardH = 320;
            const canvas = document.createElement('canvas');
            canvas.width = cardW;
            canvas.height = cardH;
            const ctx = canvas.getContext('2d');

            // Dark backing so light/white product photos stay readable
            ctx.fillStyle = '#0a0a0a';
            ctx.fillRect(0, 0, cardW, cardH);

            const pad = 10;
            const innerW = cardW - pad * 2;
            const innerH = cardH - pad * 2;

            const imgRatio = image.naturalWidth / image.naturalHeight || 1;
            const boxRatio = innerW / innerH;
            let drawW, drawH, dx, dy;
            if (imgRatio > boxRatio) {
                drawH = innerH;
                drawW = innerH * imgRatio;
                dx = pad - (drawW - innerW) / 2;
                dy = pad;
            } else {
                drawW = innerW;
                drawH = innerW / imgRatio;
                dx = pad;
                dy = pad - (drawH - innerH) / 2;
            }

            ctx.save();
            ctx.beginPath();
            ctx.rect(pad, pad, innerW, innerH);
            ctx.clip();
            ctx.drawImage(image, dx, dy, drawW, drawH);
            ctx.restore();

            // Brand-green frame border
            ctx.strokeStyle = '#00ff00';
            ctx.lineWidth = 5;
            ctx.strokeRect(2, 2, cardW - 4, cardH - 4);

            return canvas;
        }

        function addProductImageCard(group, product) {
            const url = product.image_url || product.image || '';
            if (!url) return; // no photo -- wireframe icon remains the visual

            productTexLoader.load(
                url,
                (loadedTex) => {
                    const img = loadedTex.image;
                    let canvas;
                    try {
                        canvas = makeProductCardTexture(img);
                    } catch (e) {
                        if (DEBUG) logKV('CARD_COMPOSITE_FAIL:', product.name, 'warn');
                        loadedTex.dispose();
                        return;
                    }

                    const cardTexture = new THREE.CanvasTexture(canvas);
                    cardTexture.needsUpdate = true;

                    const material = new THREE.SpriteMaterial({ map: cardTexture, transparent: true });
                    const sprite = new THREE.Sprite(material);
                    const aspect = canvas.width / canvas.height;
                    const worldHeight = 1.5;
                    sprite.scale.set(worldHeight * aspect, worldHeight, 1);
                    sprite.position.y = 2.25;
                    group.add(sprite);
                    group.userData.card = sprite;

                    loadedTex.dispose(); // only needed the raw image for canvas compositing
                },
                undefined,
                () => {
                    if (DEBUG) logKV('PRODUCT_IMG_FAILED:', product.name + ' -> ' + url, 'warn');
                }
            );
        }

        function buildPedestal(product, index, total) {
            const angle = (index / total) * Math.PI * 2;
            const x = Math.cos(angle) * pedestalRadius;
            const z = Math.sin(angle) * pedestalRadius;

            const group = new THREE.Group();
            group.position.set(x, 0, z);
            group.userData.productId = product.id;

            const ringGeo = new THREE.TorusGeometry(0.55, 0.045, 10, 32);
            const ringMat = new THREE.MeshBasicMaterial({ color: 0x00ff00, transparent: true, opacity: 0.85 });
            const ring = new THREE.Mesh(ringGeo, ringMat);
            ring.rotation.x = Math.PI / 2;
            ring.position.y = 0.05;
            group.add(ring);

            const beamGeo = new THREE.CylinderGeometry(0.018, 0.018, 3.2, 8, 1, true);
            const beamMat = new THREE.MeshBasicMaterial({
                color: 0x00ff00,
                transparent: true,
                opacity: 0.22,
                side: THREE.DoubleSide
            });
            const beam = new THREE.Mesh(beamGeo, beamMat);
            beam.position.y = 1.6;
            group.add(beam);

            const iconGeo = new THREE.OctahedronGeometry(0.22, 0);
            const iconMat = new THREE.MeshBasicMaterial({ color: 0x00ff00, wireframe: true });
            const icon = new THREE.Mesh(iconGeo, iconMat);
            icon.position.y = 3.3;
            group.add(icon);

            // Invisible larger hit-sphere so pedestals are easier to tap
            // accurately on touchscreens (the visible icon alone is too
            // small a target on a phone). Wraps the whole beacon column.
            const hitGeo = new THREE.SphereGeometry(isMobile ? 0.9 : 0.6, 8, 8);
            const hitMat = new THREE.MeshBasicMaterial({ visible: false });
            const hitSphere = new THREE.Mesh(hitGeo, hitMat);
            hitSphere.position.y = 1.8;
            group.add(hitSphere);

            // Floating product photo card (async -- appears once the image loads)
            addProductImageCard(group, product);

            group.userData.spinPart = icon;
            group.userData.ring = ring;

            pedestalGroup.add(group);
            return group;
        }

        async function loadShopProducts() {
            LoaderUI.set(80, 'FETCHING_CATALOG...');
            try {
                const res = await fetch('/api/inventory.php?limit=12', { cache: 'no-store' });
                const data = await res.json();

                if (!data || !data.ok || !Array.isArray(data.products) || data.products.length === 0) {
                    if (DEBUG) logKV('CATALOG:', 'EMPTY_OR_UNAVAILABLE', 'warn');
                    LoaderUI.set(90, 'CATALOG_EMPTY');
                    return;
                }

                const products = data.products;
                if (DEBUG) logKV('CATALOG:', products.length + ' PRODUCTS LOADED', 'dim');

                products.forEach((p, i) => {
                    productById.set(String(p.id), p);
                    buildPedestal(p, i, products.length);
                });

                LoaderUI.set(92, 'PEDESTALS_PLACED');
            } catch (e) {
                if (DEBUG) logKV('CATALOG_FETCH_FAILED:', e, 'bad');
                LoaderUI.warn('CATALOG_FETCH_FAILED');
            }
        }

        const shop3dUI = document.getElementById('shop3d-ui');
        const panelEl = document.getElementById('shop3d-panel');
        const titleEl = document.getElementById('shop3d-title');
        const priceEl = document.getElementById('shop3d-price');
        const addBtn = document.getElementById('shop3d-add-btn');
        const viewBtn = document.getElementById('shop3d-view-btn');
        const exitBtn = document.getElementById('shop3d-exit-btn');

        let currentProductId = null;

        function showProductPanel(productId) {
            const product = productById.get(String(productId));
            if (!product) return;

            currentProductId = productId;

            titleEl.textContent = (product.name || 'PRODUCT').toUpperCase();
            priceEl.textContent = product.price ? ('$' + Number(product.price).toFixed(2)) : '';

            panelEl.classList.add('active');
            panelEl.setAttribute('aria-hidden', 'false');
            if (shop3dUI) shop3dUI.classList.add('visible');

            if (typeof window.dodTrack === 'function') {
                window.dodTrack('product_view', {
                    id: product.id,
                    name: product.name,
                    category: product.category,
                    value: product.price,
                    source: 'shop3d'
                });
            }
        }

        function hideProductPanel() {
            currentProductId = null;
            panelEl.classList.remove('active');
            panelEl.setAttribute('aria-hidden', 'true');
            if (shop3dUI) shop3dUI.classList.remove('visible');
        }

        async function addCurrentToCart() {
            if (!currentProductId) return;
            const product = productById.get(String(currentProductId));
            if (!product) return;

            const originalLabel = addBtn.textContent;
            addBtn.disabled = true;

            try {
                const res = await fetch('/api/cart-add.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        product_id: product.id,
                        name: product.name || 'Product',
                        price: product.price || 0,
                        image: product.image || product.image_url || '',
                        size: 'OS',
                        quantity: 1
                    })
                });
                const result = await res.json();

                addBtn.textContent = (result && result.success) ? 'ADDED' : 'ERROR';
                addBtn.classList.add('pulse');

                if (result && result.success) {
                    window.dispatchEvent(new CustomEvent('cartUpdated', { detail: { count: result.count } }));
                    if (typeof window.dodTrack === 'function') {
                        window.dodTrack('add_to_cart_click', {
                            id: product.id,
                            name: product.name,
                            value: product.price,
                            source: 'shop3d'
                        });
                    }
                }
            } catch (e) {
                addBtn.textContent = 'ERROR';
                if (DEBUG) logKV('CART_ADD_FAILED:', e, 'bad');
            } finally {
                setTimeout(() => {
                    addBtn.textContent = originalLabel;
                    addBtn.classList.remove('pulse');
                    addBtn.disabled = false;
                }, 1000);
            }
        }

        if (addBtn) addBtn.addEventListener('click', addCurrentToCart);
        if (viewBtn) viewBtn.addEventListener('click', () => {
            if (!currentProductId) return;
            const product = productById.get(String(currentProductId));
            const slug = product && product.slug ? product.slug : null;
            window.location.href = slug ? ('/product/' + encodeURIComponent(slug)) : ('/product/' + currentProductId);
        });
        if (exitBtn) exitBtn.addEventListener('click', hideProductPanel);

        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && currentProductId) hideProductPanel();
        });

        const raycaster = new THREE.Raycaster();
        const pointer = new THREE.Vector2();

        function pointerFromEvent(e) {
            const rect = renderer.domElement.getBoundingClientRect();
            const cx = (e.clientX !== undefined) ? e.clientX : (e.changedTouches && e.changedTouches[0] ? e.changedTouches[0].clientX : 0);
            const cy = (e.clientY !== undefined) ? e.clientY : (e.changedTouches && e.changedTouches[0] ? e.changedTouches[0].clientY : 0);
            return {
                x: ((cx - rect.left) / rect.width) * 2 - 1,
                y: -((cy - rect.top) / rect.height) * 2 + 1
            };
        }

        function findProductIdFromHit(obj) {
            let cur = obj;
            while (cur) {
                if (cur.userData && cur.userData.productId) return cur.userData.productId;
                cur = cur.parent;
            }
            return null;
        }

        function onCanvasClick(e) {
            const p = pointerFromEvent(e);
            pointer.x = p.x;
            pointer.y = p.y;
            raycaster.setFromCamera(pointer, camera);

            const hits = raycaster.intersectObjects(pedestalGroup.children, true);
            if (DEBUG) logKV('TAP:', 'pedestals=' + pedestalGroup.children.length + ' hits=' + hits.length, 'dim');
            if (!hits.length) return;

            const productId = findProductIdFromHit(hits[0].object);
            if (DEBUG) logKV('TAP_PRODUCT_ID:', String(productId), 'dim');
            if (productId != null) showProductPanel(productId);
        }

        // Manual tap/click detection via pointerdown+pointerup (rather than
        // relying solely on the browser's synthetic 'click' event). Some
        // builds of THREE.OrbitControls call preventDefault() on touch
        // events in a way that can suppress the synthetic click entirely,
        // which made pedestals untappable on some devices even though the
        // raycasting logic itself was correct. This tracks movement
        // distance between press and release ourselves, so a genuine tap
        // (small movement, short duration) always triggers selection --
        // independent of whether the browser decided to fire 'click'.
        //
        // Multiple listener types (click / pointerup / touchend) are bound
        // as a safety net across browsers, so a debounce guard prevents a
        // single physical tap from triggering selection more than once.
        let pointerDownPos = null;
        let pointerDownTime = 0;
        let lastTapHandledAt = 0;
        const TAP_MOVE_THRESHOLD = 12; // px
        const TAP_MAX_DURATION = 600;  // ms
        const TAP_DEBOUNCE_MS = 400;

        function getEventXY(e) {
            if (e.clientX !== undefined) return { x: e.clientX, y: e.clientY };
            const t = (e.changedTouches && e.changedTouches[0]) || (e.touches && e.touches[0]);
            return t ? { x: t.clientX, y: t.clientY } : { x: 0, y: 0 };
        }

        function handleTapSelection(e) {
            const now = Date.now();
            if (now - lastTapHandledAt < TAP_DEBOUNCE_MS) return;
            lastTapHandledAt = now;
            onCanvasClick(e);
        }

        function onPointerDownForTap(e) {
            const xy = getEventXY(e);
            pointerDownPos = xy;
            pointerDownTime = Date.now();
        }

        function onPointerUpForTap(e) {
            if (!pointerDownPos) return;
            const xy = getEventXY(e);
            const dx = xy.x - pointerDownPos.x;
            const dy = xy.y - pointerDownPos.y;
            const dist = Math.sqrt(dx * dx + dy * dy);
            const duration = Date.now() - pointerDownTime;
            pointerDownPos = null;

            if (dist <= TAP_MOVE_THRESHOLD && duration <= TAP_MAX_DURATION) {
                handleTapSelection(e);
            }
        }

        renderer.domElement.addEventListener('click', handleTapSelection);
        renderer.domElement.addEventListener('pointerdown', onPointerDownForTap);
        renderer.domElement.addEventListener('pointerup', onPointerUpForTap);
        // Extra safety net for older mobile browsers that don't fully
        // support pointer events on canvas elements
        renderer.domElement.addEventListener('touchstart', onPointerDownForTap, { passive: true });
        renderer.domElement.addEventListener('touchend', onPointerUpForTap, { passive: true });

        renderer.domElement.addEventListener('mousemove', (e) => {
            const p = pointerFromEvent(e);
            pointer.x = p.x;
            pointer.y = p.y;
            raycaster.setFromCamera(pointer, camera);
            const hits = raycaster.intersectObjects(pedestalGroup.children, true);
            container.classList.toggle('hovering-product', hits.length > 0);
        });

        window.addEventListener('keydown', (e) => {
            const k = (e.key || '').toLowerCase();

            if (k === 'g') {
                showGrid = !showGrid;
                grid.visible = showGrid;
                syncHUD();
            }
            if (k === 'h') {
                if (hud) hud.style.display = (hud.style.display === 'none') ? '' : 'none';
            }
            if (k === 'd') {
                showDiamond = !showDiamond;
                diamond.visible = showDiamond;
            }
            if (k === 'e') {
                showEnv = !showEnv;
                if (envRoot) envRoot.visible = showEnv;
            }
            if (k === 'm') {
                forceEnvMaterial = !forceEnvMaterial;
                applyEnvMaterialModes();
                if (DEBUG) logKV('MAT_DBG:', forceEnvMaterial ? 'ON (wireframe)' : 'OFF', 'dim');
            }
            if (k === 'n') {
                normalizeEnvMaterials = !normalizeEnvMaterials;
                applyEnvMaterialModes();
                syncHUD();
                if (DEBUG) logKV('NORM_MAT:', normalizeEnvMaterials ? 'ON (readable)' : 'OFF (original)', 'dim');
            }
            if (k === '+' || k === '=') {
                setExposure(exposure + 0.10);
            }
            if (k === '-' || k === '_') {
                setExposure(exposure - 0.10);
            }
            if (k === 'x' && DEBUG) {
                axis.visible = !axis.visible;
                logKV('AXIS:', axis.visible ? 'ON' : 'OFF', 'dim');
            }
        });

        syncHUD();

        Promise.allSettled([
            loadEnvironment(),
            loadShopProducts()
        ]).finally(() => {
            setTimeout(() => LoaderUI.finish(), 650);
        });

        function animate() {
            requestAnimationFrame(animate);

            if (showDiamond) {
                diamond.rotation.y += 0.005;
                diamond.position.y = 1.5 + Math.sin(Date.now() * 0.0015) * 0.10;
                core.rotation.y -= 0.01;
                core.rotation.x += 0.005;
            }

            const t = Date.now() * 0.001;
            pedestalGroup.children.forEach((g) => {
                if (g.userData.spinPart) {
                    g.userData.spinPart.rotation.y += 0.02;
                    g.userData.spinPart.rotation.x += 0.01;
                }
                if (g.userData.ring) {
                    const pulse = 0.75 + Math.sin(t * 2 + g.position.x) * 0.15;
                    g.userData.ring.material.opacity = pulse;
                }
                if (g.userData.card) {
                    g.userData.card.position.y = 2.25 + Math.sin(t * 1.1 + g.position.z) * 0.06;
                }
            });

            controls.update();
            renderer.render(scene, camera);
        }
        animate();
    });
})();
</script>
</body>
</html>