<?php
declare(strict_types=1);

/**
 * music_submit.php
 * Path: /home2/asqrtyte/public_html/music_submit.php
 *
 * Public page where artists can submit a track for consideration in the
 * DOD Arcade's gameplay music rotation. Accepts either a link (SoundCloud,
 * Spotify, YouTube, direct MP3 URL, etc.) or a direct audio file upload.
 * Submissions land in arcade_music_submissions for review in
 * admin/arcade_music.php -- nothing here touches the live playlist
 * directly; every submission needs a human decision first.
 */

require __DIR__ . '/db_connect.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(503);
    exit('The database is temporarily unavailable. Please try again shortly.');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
}
if (!function_exists('msub_music_safe_name')) {
    function msub_music_safe_name(string $name): string {
        $name = trim($name);
        $name = preg_replace('/[^\pL\pN._-]+/u', '_', $name) ?? $name;
        $name = preg_replace('/_+/', '_', $name) ?? $name;
        $name = trim($name, "._- \t\n\r\0\x0B");
        if ($name === '') $name = 'track';
        if (strlen($name) > 120) $name = substr($name, 0, 120);
        return $name;
    }
}

// ---- Idempotent schema setup (safe to run every request; no-op once applied) ----
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS arcade_music_submissions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        artist_name VARCHAR(150) NOT NULL,
        email VARCHAR(190) NOT NULL,
        track_title VARCHAR(150) NOT NULL,
        genre VARCHAR(60) NULL,
        submission_type ENUM('link','file') NOT NULL,
        track_link VARCHAR(500) NULL,
        audio_file VARCHAR(300) NULL,
        message VARCHAR(2000) NULL,
        status ENUM('new','reviewed','approved','rejected') NOT NULL DEFAULT 'new',
        admin_note VARCHAR(2000) NULL,
        ip_hash VARCHAR(64) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_status (status),
        KEY idx_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS arcade_music_submission_attempts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ip_hash VARCHAR(64) NOT NULL,
        email VARCHAR(190) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ip_time (ip_hash, created_at),
        KEY idx_email_time (email, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    error_log('[MUSIC_SUBMIT_SCHEMA] ' . $e->getMessage());
}

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['csrf'];

// A fresh per-page-load timestamp -- rejects submissions that come back
// faster than a real person could plausibly fill out this form, a classic
// signal of an automated bot rather than a human visitor.
if (empty($_SESSION['music_submit_form_started'])) {
    $_SESSION['music_submit_form_started'] = time();
}
$formStartedAt = (int)$_SESSION['music_submit_form_started'];

$ipHash = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|dod-music-submit');

$flash = '';
$flashType = '';
$success = false;

// Pre-fill values on validation failure so the artist doesn't have to
// retype everything.
$old = [
    'artist_name' => '', 'email' => '', 'track_title' => '', 'genre' => '',
    'submission_type' => 'link', 'track_link' => '', 'message' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($old as $key => $_) {
        if (isset($_POST[$key])) $old[$key] = trim((string)$_POST[$key]);
    }

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $flash = 'Security check failed. Please refresh the page and try again.';
        $flashType = 'err';
    } elseif (!empty($_POST['website'])) {
        // Honeypot -- invisible to real visitors via CSS, but a bot filling
        // every field on the page will fill this one too. Fail silently
        // with a generic message rather than revealing the trap exists.
        $flash = 'Something went wrong. Please try again.';
        $flashType = 'err';
    } elseif ((time() - $formStartedAt) < 3) {
        $flash = 'Please take a moment and try submitting again.';
        $flashType = 'err';
    } else {
        // Rate limiting -- mirrors the same spirit as the signup form's bot
        // protection. Generous enough for a real artist submitting a
        // handful of tracks, tight enough to blunt spam floods.
        $rateLimited = false;
        try {
            $ipStmt = $pdo->prepare("SELECT COUNT(*) FROM arcade_music_submission_attempts WHERE ip_hash = ? AND created_at >= NOW() - INTERVAL 1 DAY");
            $ipStmt->execute([$ipHash]);
            $ipCount = (int)$ipStmt->fetchColumn();

            $emailCount = 0;
            if ($old['email'] !== '') {
                $emailStmt = $pdo->prepare("SELECT COUNT(*) FROM arcade_music_submission_attempts WHERE email = ? AND created_at >= NOW() - INTERVAL 1 DAY");
                $emailStmt->execute([strtolower($old['email'])]);
                $emailCount = (int)$emailStmt->fetchColumn();
            }

            if ($ipCount >= 5 || $emailCount >= 3) {
                $rateLimited = true;
            }
        } catch (Throwable $e) {
            error_log('[MUSIC_SUBMIT_RATE_LIMIT] ' . $e->getMessage());
        }

        if ($rateLimited) {
            $flash = "You've reached the submission limit for today. Please try again tomorrow.";
            $flashType = 'err';
        } else {
            // ---- Field validation ----
            $errors = [];
            if ($old['artist_name'] === '') $errors[] = 'Artist name is required.';
            if ($old['email'] === '' || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
            if ($old['track_title'] === '') $errors[] = 'Track title is required.';
            $submissionType = in_array($old['submission_type'], ['link', 'file'], true) ? $old['submission_type'] : 'link';

            $trackLink = null;
            $audioFilePath = null;

            if ($submissionType === 'link') {
                if ($old['track_link'] === '' || !filter_var($old['track_link'], FILTER_VALIDATE_URL)) {
                    $errors[] = 'A valid track link is required.';
                } else {
                    $trackLink = $old['track_link'];
                }
            } else {
                $file = $_FILES['audio_file'] ?? null;
                if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    $errors[] = 'Please choose an audio file to upload.';
                } elseif (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    $errors[] = 'The file upload failed. Please try again.';
                } else {
                    $tmp = (string)$file['tmp_name'];
                    $maxBytes = 20 * 1024 * 1024; // 20MB
                    if (!is_uploaded_file($tmp)) {
                        $errors[] = 'Invalid upload.';
                    } elseif ((int)$file['size'] > $maxBytes) {
                        $errors[] = 'File too large (max 20MB).';
                    } else {
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mime = (string)finfo_file($finfo, $tmp);
                        finfo_close($finfo);
                        $allowedMime = ['audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav', 'audio/x-m4a' => 'm4a', 'audio/mp4' => 'm4a'];

                        if (!isset($allowedMime[$mime])) {
                            $errors[] = 'Unsupported file type. Please use MP3, OGG, WAV, or M4A.';
                        } else {
                            $ext = $allowedMime[$mime];
                            $uploadDir = __DIR__ . '/uploads/arcade_music_submissions/';
                            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

                            $filename = msub_music_safe_name($old['artist_name'] . '_' . $old['track_title']) . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                            $targetPath = $uploadDir . $filename;

                            if (@move_uploaded_file($tmp, $targetPath)) {
                                $audioFilePath = '/uploads/arcade_music_submissions/' . $filename;
                            } else {
                                $errors[] = 'Failed to save the uploaded file. Please try again.';
                            }
                        }
                    }
                }
            }

            if (!empty($errors)) {
                $flash = implode(' ', $errors);
                $flashType = 'err';
            } else {
                try {
                    $pdo->prepare("
                        INSERT INTO arcade_music_submissions
                            (artist_name, email, track_title, genre, submission_type, track_link, audio_file, message, ip_hash)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $old['artist_name'], $old['email'], $old['track_title'],
                        $old['genre'] !== '' ? $old['genre'] : null,
                        $submissionType, $trackLink, $audioFilePath,
                        $old['message'] !== '' ? substr($old['message'], 0, 2000) : null,
                        $ipHash,
                    ]);
                    $pdo->prepare("INSERT INTO arcade_music_submission_attempts (ip_hash, email) VALUES (?, ?)")
                        ->execute([$ipHash, strtolower($old['email'])]);

                    unset($_SESSION['music_submit_form_started']);
                    $success = true;
                    $old = ['artist_name' => '', 'email' => '', 'track_title' => '', 'genre' => '', 'submission_type' => 'link', 'track_link' => '', 'message' => ''];
                } catch (Throwable $e) {
                    error_log('[MUSIC_SUBMIT_INSERT] ' . $e->getMessage());
                    $flash = 'Something went wrong saving your submission. Please try again.';
                    $flashType = 'err';
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Submit Music | Diamonds Outta Dirt Arcade</title>
<meta name="description" content="Submit your track for consideration in the Diamonds Outta Dirt Arcade's gameplay music rotation.">
<style>
*{box-sizing:border-box;}
body{margin:0;background:#050505;color:#eee;font-family:'Space Mono',monospace,'Courier New',Courier;padding:32px 20px 80px;}
.wrap{max-width:560px;margin:0 auto;}
h1{font-size:1.3rem;color:#fff;letter-spacing:1px;margin:0 0 8px;}
.sub{color:#999;font-size:.78rem;line-height:1.55;margin:0 0 24px;}
.flash-ok{background:rgba(0,255,157,0.08);border:1px solid #1a4;border-radius:6px;padding:14px;font-size:.78rem;color:#6bff9f;margin-bottom:20px;line-height:1.5;}
.flash-err{background:rgba(255,80,80,0.08);border:1px solid #944;border-radius:6px;padding:14px;font-size:.78rem;color:#ff8080;margin-bottom:20px;line-height:1.5;}
.section{background:#0a0a0a;border:1px solid #222;border-radius:10px;padding:22px;}
label{display:block;font-size:.62rem;color:#6bff9f;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;margin-top:16px;}
label:first-of-type{margin-top:0;}
input[type=text],input[type=email],input[type=url],input[type=file],textarea,select{
    width:100%;background:#050505;border:1px solid #2a2a2a;border-radius:5px;color:#eee;
    padding:10px 12px;font-family:inherit;font-size:.78rem;
}
textarea{min-height:80px;resize:vertical;}
.hint{color:#666;font-size:.62rem;margin-top:5px;}
.type-toggle{display:flex;gap:8px;margin-top:16px;}
.type-toggle label{
    flex:1;display:flex;align-items:center;justify-content:center;margin:0;
    padding:12px;border:1px solid #2a2a2a;border-radius:6px;cursor:pointer;
    text-transform:none;font-size:.72rem;color:#ccc;background:#050505;
}
.type-toggle input{width:auto;margin-right:8px;}
.type-toggle input:checked + span{color:#6bff9f;}
.type-panel{display:none;}
.type-panel.active{display:block;}
.btn{width:100%;margin-top:22px;padding:13px;border-radius:6px;border:1px solid #1a4;background:rgba(0,255,157,0.1);color:#6bff9f;font-family:inherit;font-size:.78rem;text-transform:uppercase;letter-spacing:.08em;cursor:pointer;}
.btn:hover{background:rgba(0,255,157,0.18);}
/* Honeypot: hidden from real visitors, still visible to basic bots that
   don't render CSS, and never announced to screen readers. */
.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;}
</style>
</head>
<body>
<div class="wrap">
    <h1>Submit Your Music</h1>
    <p class="sub">Got a track that fits the Diamonds Outta Dirt Arcade? Send it over. We review every submission personally &mdash; if it's a fit, we'll reach out about adding it to the game's music rotation.</p>

    <?php if ($success): ?>
        <div class="flash-ok">Thanks for the submission! We'll be in touch if it's a fit for the arcade.</div>
    <?php endif; ?>
    <?php if ($flash): ?><div class="flash-<?= $flashType === 'ok' ? 'ok' : 'err' ?>"><?= h($flash) ?></div><?php endif; ?>

    <div class="section">
        <form method="post" enctype="multipart/form-data" id="musicSubmitForm">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <div class="hp" aria-hidden="true">
                <label for="website">Leave this field blank</label>
                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <label>Artist / Band Name</label>
            <input type="text" name="artist_name" value="<?= h($old['artist_name']) ?>" required>

            <label>Email</label>
            <input type="email" name="email" value="<?= h($old['email']) ?>" required>

            <label>Track Title</label>
            <input type="text" name="track_title" value="<?= h($old['track_title']) ?>" required>

            <label>Genre (optional)</label>
            <input type="text" name="genre" value="<?= h($old['genre']) ?>" placeholder="e.g. synthwave, hip-hop, ambient">

            <label>How would you like to submit?</label>
            <div class="type-toggle">
                <label>
                    <input type="radio" name="submission_type" value="link" <?= $old['submission_type'] !== 'file' ? 'checked' : '' ?> onchange="musicSubmitToggle('link')">
                    <span>Link</span>
                </label>
                <label>
                    <input type="radio" name="submission_type" value="file" <?= $old['submission_type'] === 'file' ? 'checked' : '' ?> onchange="musicSubmitToggle('file')">
                    <span>Upload File</span>
                </label>
            </div>

            <div class="type-panel" id="panel-link">
                <label>Track Link</label>
                <input type="url" name="track_link" value="<?= h($old['track_link']) ?>" placeholder="SoundCloud, Spotify, YouTube, etc.">
                <div class="hint">Make sure the link is set to public / shareable.</div>
            </div>

            <div class="type-panel" id="panel-file">
                <label>Audio File</label>
                <input type="file" name="audio_file" accept="audio/mpeg,audio/mp3,audio/ogg,audio/wav,audio/x-m4a,audio/mp4">
                <div class="hint">MP3, OGG, WAV, or M4A — max 20MB.</div>
            </div>

            <label>Message (optional)</label>
            <textarea name="message" maxlength="2000" placeholder="Anything else you'd like us to know?"><?= h($old['message']) ?></textarea>

            <button type="submit" class="btn">Submit Track</button>
        </form>
    </div>
</div>
<script>
function musicSubmitToggle(type) {
    document.getElementById('panel-link').classList.toggle('active', type === 'link');
    document.getElementById('panel-file').classList.toggle('active', type === 'file');
}
musicSubmitToggle(document.querySelector('input[name="submission_type"]:checked').value);
</script>
</body>
</html>