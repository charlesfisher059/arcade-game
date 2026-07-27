<?php
declare(strict_types=1);

/**
 * casting.php
 * Path: /home2/asqrtyte/public_html/casting.php
 *
 * Public submission form for models/talent interested in photoshoots,
 * events, runway, etc. Submissions land in admin/model_submissions.php
 * for review.
 */

$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => $https,
        'cookie_samesite' => 'Lax',
    ]);
}

require_once __DIR__ . '/db_connect.php';

if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
}

function cast_compress_image(string $path, int $maxDimension = 1400): void {
    if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) return;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) return;

    $info = @getimagesize($path);
    if ($info === false) return;
    [$origW, $origH] = $info;
    if ($origW <= 0 || $origH <= 0 || ($origW <= $maxDimension && $origH <= $maxDimension)) return;

    $img = null;
    if ($ext === 'jpg' || $ext === 'jpeg') $img = @imagecreatefromjpeg($path);
    elseif ($ext === 'png') $img = @imagecreatefrompng($path);
    elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) $img = @imagecreatefromwebp($path);
    if (!$img) return;

    $scale = $maxDimension / max($origW, $origH);
    $newW = max(1, (int)round($origW * $scale));
    $newH = max(1, (int)round($origH * $scale));
    $resized = imagecreatetruecolor($newW, $newH);
    if ($ext === 'png' || $ext === 'webp') {
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
    }
    imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
    imagedestroy($img);

    if ($ext === 'jpg' || $ext === 'jpeg') @imagejpeg($resized, $path, 84);
    elseif ($ext === 'png') @imagepng($resized, $path, 6);
    elseif ($ext === 'webp' && function_exists('imagewebp')) @imagewebp($resized, $path, 84);
    imagedestroy($resized);
}

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf'];

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Security check failed. Please refresh and try again.';
    } elseif (trim((string)($_POST['website'] ?? '')) !== '') {
        // Honeypot field -- real users never fill this in. Silently pretend
        // success so bots don't learn the honeypot exists.
        $success = true;
    } else {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $instagram = trim((string)($_POST['instagram'] ?? ''));
        $portfolio = trim((string)($_POST['portfolio'] ?? ''));
        $interestType = (string)($_POST['interest_type'] ?? 'photoshoot');
        $height = trim((string)($_POST['height'] ?? ''));
        $sizes = trim((string)($_POST['sizes'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $message = trim((string)($_POST['message'] ?? ''));
        $ageConfirmed = isset($_POST['age_confirmed']);

        if ($fullName === '' || mb_strlen($fullName) < 2) $errors[] = 'Please enter your full name.';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
        if (!$ageConfirmed) $errors[] = 'You must confirm you are 18 years of age or older to submit.';
        if ($portfolio !== '' && !filter_var($portfolio, FILTER_VALIDATE_URL)) $errors[] = 'Portfolio link must be a valid URL.';

        $validInterests = ['photoshoot', 'event', 'runway', 'other'];
        if (!in_array($interestType, $validInterests, true)) $interestType = 'other';

        // Rate limit: max 3 submissions per IP per rolling 24 hours.
        // Checked before photo processing so a rate-limited request never
        // wastes effort on uploads/compression it's about to reject anyway.
        $ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . 'dod_casting_salt');
        if (empty($errors)) {
            try {
                $rateStmt = $pdo->prepare("
                    SELECT COUNT(*) FROM model_submissions
                    WHERE ip_hash = ? AND created_at >= (NOW() - INTERVAL 1 DAY)
                ");
                $rateStmt->execute([$ipHash]);
                if ((int)$rateStmt->fetchColumn() >= 3) {
                    $errors[] = "You've reached the maximum number of submissions for today. Please try again tomorrow.";
                }
            } catch (Throwable $e) {
                error_log('[CASTING_RATE_LIMIT] ' . $e->getMessage());
                // Fail open -- a rate-limit check failing shouldn't block a
                // legitimate submission
            }
        }

        // Handle up to 3 photo uploads
        $photoResults = [null, null, null];
        if (empty($errors)) {
            $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/images/casting-submissions/';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

            for ($i = 1; $i <= 3; $i++) {
                $fieldName = 'photo_' . $i;
                if (empty($_FILES[$fieldName]) || ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if (($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $errors[] = "Photo " . $i . " failed to upload. Please try again.";
                    continue;
                }

                $tmp = $_FILES[$fieldName]['tmp_name'];
                if (!is_uploaded_file($tmp)) { $errors[] = "Photo {$i} upload was invalid."; continue; }

                $mime = @mime_content_type($tmp);
                $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!isset($allowed[$mime])) { $errors[] = "Photo {$i} must be a JPG, PNG, or WebP image."; continue; }

                if (($_FILES[$fieldName]['size'] ?? 0) > (15 * 1024 * 1024)) {
                    $errors[] = "Photo {$i} is too large (max 15MB).";
                    continue;
                }

                $filename = 'cast_' . time() . '_' . $i . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
                $targetPath = $uploadDir . $filename;

                if (move_uploaded_file($tmp, $targetPath)) {
                    cast_compress_image($targetPath);
                    $photoResults[$i - 1] = '/images/casting-submissions/' . $filename;
                } else {
                    $errors[] = "Photo {$i} could not be saved. Please try again.";
                }
            }
        }

        if (empty($errors)) {
            try {
                $pdo->prepare("
                    INSERT INTO model_submissions
                        (full_name, email, phone, instagram_handle, portfolio_url, interest_type,
                         height, sizes, location, message, photo_1, photo_2, photo_3, age_confirmed, ip_hash)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
                ")->execute([
                    $fullName, $email, $phone ?: null, $instagram ?: null, $portfolio ?: null, $interestType,
                    $height ?: null, $sizes ?: null, $location ?: null, $message ?: null,
                    $photoResults[0], $photoResults[1], $photoResults[2], $ipHash,
                ]);

                $fromEmail = 'myshineisnow@diamondsouttadirt.com';
                $headers = "From: Diamonds Outta Dirt <{$fromEmail}>\r\nReply-To: {$fromEmail}\r\n"
                         . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";

                // Notify admin
                @mail(
                    $fromEmail,
                    'New casting submission: ' . $fullName,
                    "New submission received.\n\nName: {$fullName}\nEmail: {$email}\nInterest: {$interestType}\n\nReview it in the admin panel: https://diamondsouttadirt.com/admin/model_submissions.php",
                    $headers,
                    '-f ' . $fromEmail
                );

                // Confirm to applicant
                if (!str_contains($email, "\n") && !str_contains($email, "\r")) {
                    @mail(
                        $email,
                        "We've got your submission",
                        "Hey " . explode(' ', $fullName)[0] . ",\n\nThanks for submitting to Diamonds Outta Dirt. We've received your info and will reach out if it's a fit for an upcoming shoot or event.\n\n-- Diamonds Outta Dirt",
                        $headers,
                        '-f ' . $fromEmail
                    );
                }

                $success = true;
            } catch (Throwable $e) {
                error_log('[CASTING_SUBMIT] ' . $e->getMessage());
                $errors[] = 'Something went wrong submitting your application. Please try again.';
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
<title>CASTING CALL | DIAMONDS OUTTA DIRT</title>
<meta name="description" content="Apply to model or appear in a photoshoot, event, or runway show for Diamonds Outta Dirt.">
<link rel="icon" href="/favicon.ico" sizes="any">
<?php if (is_file(__DIR__ . '/includes/tracking_pixels.php')) { require_once __DIR__ . '/includes/tracking_pixels.php'; } ?>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Cormorant+Garamond:wght@500;600;700&family=Syncopate:wght@700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;}
body{margin:0;background:#000;color:#eee;font-family:'Space Mono',monospace;}
.header{padding:20px 5%;border-bottom:1px solid #1a1a1a;display:flex;justify-content:space-between;align-items:center;}
.logo{font-family:'Syncopate',sans-serif;font-size:.72rem;letter-spacing:3px;color:#00ff9d;text-decoration:none;}
.nav a{color:#ccc;text-decoration:none;font-size:.7rem;letter-spacing:1px;margin-left:20px;}
.nav a:hover{color:#00ff9d;}
.wrap{max-width:560px;margin:0 auto;padding:50px 20px 100px;}
h1{font-family:'Cormorant Garamond',serif;font-size:2.2rem;color:#fff;margin:0 0 8px;text-align:center;}
.sub{text-align:center;color:#888;font-size:.74rem;letter-spacing:.5px;margin:0 0 36px;line-height:1.6;}
.success-box{background:rgba(0,255,157,0.06);border:1px solid #00ff9d;border-radius:10px;padding:30px;text-align:center;}
.success-box h2{font-family:'Cormorant Garamond',serif;color:#fff;font-size:1.5rem;margin:0 0 10px;}
.success-box p{color:#aaa;font-size:.8rem;line-height:1.6;}
.error-box{background:rgba(255,77,77,0.08);border:1px solid #ff4d4d;border-radius:8px;padding:16px;margin-bottom:22px;}
.error-box p{margin:4px 0;color:#ff8080;font-size:.72rem;}
label{display:block;font-size:.64rem;color:#888;letter-spacing:.5px;margin:18px 0 6px;text-transform:uppercase;}
label.required::after{content:" *";color:#ff8080;}
input[type=text],input[type=email],input[type=tel],input[type=url],textarea,select{
    width:100%;background:#0a0a0a;border:1px solid #2a2a2a;color:#eee;padding:11px 12px;
    border-radius:5px;font-family:inherit;font-size:.8rem;box-sizing:border-box;
}
textarea{min-height:110px;resize:vertical;}
input:focus,textarea:focus,select:focus{outline:none;border-color:#00ff9d;}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
@media (max-width:480px){.grid-2{grid-template-columns:1fr;}}
.photo-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:6px;}
.photo-slot{border:1px dashed #333;border-radius:6px;padding:10px;text-align:center;}
.photo-slot input{font-size:.6rem;color:#888;}
.age-row{display:flex;align-items:flex-start;gap:10px;margin-top:22px;padding:14px;background:#0a0a0a;border:1px solid #222;border-radius:6px;}
.age-row input{margin-top:2px;accent-color:#00ff9d;flex-shrink:0;}
.age-row span{font-size:.7rem;color:#ccc;line-height:1.5;}
.honeypot{position:absolute;left:-9999px;opacity:0;pointer-events:none;}
.submit-btn{width:100%;background:#00ff9d;color:#000;border:none;padding:15px;border-radius:5px;
    font-family:inherit;font-weight:700;font-size:.78rem;letter-spacing:1.5px;text-transform:uppercase;
    cursor:pointer;margin-top:26px;}
.submit-btn:hover{filter:brightness(1.08);}
.hint{font-size:.6rem;color:#666;margin-top:4px;}
</style>
</head>
<body>
<div class="header">
    <a href="/" class="logo">DIAMONDS OUTTA DIRT</a>
    <nav class="nav">
        <a href="/shop">Shop</a>
        <a href="/exchange">Exchange</a>
    </nav>
</div>

<div class="wrap">
    <h1>Casting Call</h1>
    <p class="sub">Apply to model or appear in an upcoming photoshoot, event, or runway show.</p>

    <?php if ($success): ?>
        <div class="success-box">
            <h2>Application Received</h2>
            <p>Thanks for submitting. We'll reach out if it's a fit for something upcoming. Check your email for confirmation.</p>
        </div>
    <?php else: ?>

        <?php if (!empty($errors)): ?>
        <div class="error-box">
            <?php foreach ($errors as $err): ?><p>&bull; <?= h($err) ?></p><?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <div class="honeypot" aria-hidden="true">
                <label for="website">Website</label>
                <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
            </div>

            <label class="required">Full Name</label>
            <input type="text" name="full_name" maxlength="150" required value="<?= h((string)($_POST['full_name'] ?? '')) ?>">

            <div class="grid-2">
                <div>
                    <label class="required">Email</label>
                    <input type="email" name="email" maxlength="255" required value="<?= h((string)($_POST['email'] ?? '')) ?>">
                </div>
                <div>
                    <label>Phone</label>
                    <input type="tel" name="phone" maxlength="30" value="<?= h((string)($_POST['phone'] ?? '')) ?>">
                </div>
            </div>

            <div class="grid-2">
                <div>
                    <label>Instagram Handle</label>
                    <input type="text" name="instagram" maxlength="100" placeholder="@yourhandle" value="<?= h((string)($_POST['instagram'] ?? '')) ?>">
                </div>
                <div>
                    <label>Portfolio / Website</label>
                    <input type="url" name="portfolio" maxlength="500" placeholder="https://..." value="<?= h((string)($_POST['portfolio'] ?? '')) ?>">
                </div>
            </div>

            <label class="required">Interested In</label>
            <select name="interest_type" required>
                <option value="photoshoot">Photoshoot</option>
                <option value="event">Event / Appearance</option>
                <option value="runway">Runway / Show</option>
                <option value="other">Other</option>
            </select>

            <div class="grid-2">
                <div>
                    <label>Height</label>
                    <input type="text" name="height" maxlength="20" placeholder="e.g. 5'8&quot;" value="<?= h((string)($_POST['height'] ?? '')) ?>">
                </div>
                <div>
                    <label>Sizes</label>
                    <input type="text" name="sizes" maxlength="100" placeholder="e.g. S/M, 32W" value="<?= h((string)($_POST['sizes'] ?? '')) ?>">
                </div>
            </div>

            <label>Location</label>
            <input type="text" name="location" maxlength="150" placeholder="City, State" value="<?= h((string)($_POST['location'] ?? '')) ?>">

            <label>Tell us about yourself</label>
            <textarea name="message" maxlength="2000" placeholder="Experience, availability, anything else we should know"><?= h((string)($_POST['message'] ?? '')) ?></textarea>

            <label>Photos (up to 3)</label>
            <div class="photo-row">
                <div class="photo-slot"><input type="file" name="photo_1" accept="image/jpeg,image/png,image/webp"></div>
                <div class="photo-slot"><input type="file" name="photo_2" accept="image/jpeg,image/png,image/webp"></div>
                <div class="photo-slot"><input type="file" name="photo_3" accept="image/jpeg,image/png,image/webp"></div>
            </div>
            <div class="hint">JPG, PNG, or WebP. Max 15MB each.</div>

            <div class="age-row">
                <input type="checkbox" name="age_confirmed" id="age_confirmed" required>
                <label for="age_confirmed" style="margin:0;text-transform:none;"><span>I confirm that I am 18 years of age or older.</span></label>
            </div>

            <button type="submit" class="submit-btn">Submit Application</button>
        </form>

    <?php endif; ?>
</div>
</body>
</html>