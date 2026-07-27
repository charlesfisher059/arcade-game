<?php
/**
 * services_request.php — Diamonds Outta Dirt
 * Path: /home2/asqrtyte/public_html/services_request.php
 *
 * PURPOSE:
 * - Accept POSTed service requests from services.php (or /services route)
 * - Optional attachment upload (hardened)
 * - Insert request into DB (service_requests)
 * - Notify support via email (best-effort)
 *
 * SECURITY:
 * - Safe session bootstrap (secure cookie params + no regen on every hit)
 * - CSRF validation (accepts csrf_token + legacy csrf)
 * - Rate limiting (session + fallback file bucket)
 * - Strict input validation
 * - Hardened upload pipeline (MIME + ext + size + SVG script scan + PDF header check + double-ext block)
 * - Directory protection creation (uploads/services)
 * - DB guard (requires includes/init.php to provide $pdo)
 * - Safer mail subject/body + header injection prevention
 *
 * PATCH v2 (EMAIL DELIVERABILITY FIX):
 * - Changed notify + From address to myshineisnow@diamondsouttadirt.com
 *   (HostGator drops mail from no-reply@ addresses that aren't real mailboxes)
 */

declare(strict_types=1);

// =====================================================
// 0) SECURITY HEADERS
// =====================================================
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

// =====================================================
// 1) HTTPS DETECT + SAFE SESSION BOOTSTRAP
// =====================================================
$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (empty($_SESSION['__sid_regen'])) {
    session_regenerate_id(true);
    $_SESSION['__sid_regen'] = time();
}

// =====================================================
// 2) CONFIGURATION
// =====================================================
$notifyEmail = 'myshineisnow@diamondsouttadirt.com';

$uploadDir = __DIR__ . '/uploads/services';
$logDir    = __DIR__ . '/logs';
$rateDir   = __DIR__ . '/_data/rate_limits';

$maxSize   = 5 * 1024 * 1024; // 5MB

$allowedTypes = [
    'image/png',
    'image/jpeg',
    'image/jpg',
    'application/pdf',
    'image/svg+xml',
];

$allowedExtensions = ['png', 'jpg', 'jpeg', 'pdf', 'svg'];

$allowedServices = [
    'Custom Apparel Printing',
    'Embroidery Services',
    'Sublimation Printing',
    'Small-Batch Manufacturing',
    'Design Assistance',
    'Other',
];

// =====================================================
// 3) CREATE REQUIRED DIRECTORIES (SAFE)
// =====================================================
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
    @file_put_contents($uploadDir . '/index.html', '<!-- Directory listing disabled -->');

    $uht = $uploadDir . '/.htaccess';
    if (!file_exists($uht)) {
        @file_put_contents($uht, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar)$\">\n    Require all denied\n</FilesMatch>\n");
    }
}

if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
    @file_put_contents($logDir . '/index.html', '<!-- Directory listing disabled -->');
}

if (!is_dir($rateDir)) {
    @mkdir($rateDir, 0755, true);
    @file_put_contents($rateDir . '/index.html', '<!-- Rate directory -->');
}

// =====================================================
// 4) HELPERS
// =====================================================
function clean(string $v, int $max = 500): string
{
    $v = trim((string)$v);
    $v = preg_replace('/\s+/', ' ', $v) ?? $v;
    $v = preg_replace('/[^\P{C}\n\t]/u', '', $v) ?? $v;
    return mb_substr($v, 0, $max);
}

function logError(string $message, array $context = []): void
{
    $logDir = __DIR__ . '/logs';

    $logEntry = date('[Y-m-d H:i:s]') . ' ' . $message;
    if (!empty($context)) {
        $safe = [];
        foreach ($context as $k => $item) {
            if (is_array($item)) {
                $safe[$k] = mb_substr(json_encode($item, JSON_UNESCAPED_SLASHES) ?: '', 0, 1500);
            } else {
                $safe[$k] = mb_substr((string)$item, 0, 500);
            }
        }
        $logEntry .= ' - ' . (json_encode($safe, JSON_UNESCAPED_SLASHES) ?: '');
    }

    error_log($logEntry);
    @file_put_contents($logDir . '/services_errors.log', $logEntry . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function logSuccess(int $requestId, string $service, string $email): void
{
    $logDir = __DIR__ . '/logs';

    $logEntry = sprintf(
        '[%s] SUCCESS - Request #%d - Service: %s - Email: %s - IP: %s',
        date('Y-m-d H:i:s'),
        $requestId,
        $service,
        $email,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    );

    @file_put_contents($logDir . '/services_submissions.log', $logEntry . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function services_redirect_base(): string
{
    $docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    $hasClean = is_file($docRoot . '/services.php');
    return $hasClean ? '/services' : '/services.php';
}

function fail(string $reason): never
{
    $errorMessages = [
        'BAD_METHOD' => 'Invalid request method',
        'MISSING_FIELDS' => 'Please fill in all required fields',
        'BAD_EMAIL' => 'Please enter a valid email address',
        'UPLOAD_ERROR' => 'File upload error occurred',
        'FILE_TOO_LARGE' => 'File is too large (max 5MB)',
        'INVALID_FILE' => 'Invalid file type',
        'INVALID_FILE_EXT' => 'Invalid file extension',
        'UPLOAD_FAILED' => 'File upload failed',
        'UPLOAD_TAMPERED' => 'Invalid file upload',
        'SVG_SCRIPT_DETECTED' => 'SVG file contains scripts (not allowed)',
        'CSRF_INVALID' => 'Security token invalid. Please refresh and try again.',
        'RATE_LIMIT' => 'Too many requests. Please wait 5 minutes.',
        'DB_ERROR' => 'Database error. Please try again later.',
        'INVALID_SERVICE' => 'Invalid service selected',
    ];

    $message = $errorMessages[$reason] ?? 'An error occurred';

    logError("Form submission failed: {$reason}", [
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 255),
        'reason' => $reason,
    ]);

    $base = services_redirect_base();
    header('Location: ' . $base . '?error=' . rawurlencode($reason) . '&message=' . rawurlencode($message));
    exit;
}

function is_header_injection(string $v): bool
{
    return (bool)preg_match("/[\r\n]/", $v);
}

function rate_limit_fallback(string $key, int $windowSeconds, int $max): bool
{
    $dir = __DIR__ . '/_data/rate_limits';
    $path = $dir . '/' . $key . '.json';

    $now = time();
    $data = ['time' => $now, 'count' => 0];

    if (is_file($path)) {
        $raw = @file_get_contents($path);
        $parsed = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($parsed)) {
            $data['time'] = isset($parsed['time']) ? (int)$parsed['time'] : $now;
            $data['count'] = isset($parsed['count']) ? (int)$parsed['count'] : 0;
        }
    }

    if (($now - (int)$data['time']) < $windowSeconds) {
        if ((int)$data['count'] >= $max) {
            return false;
        }
        $data['count'] = (int)$data['count'] + 1;
    } else {
        $data['time'] = $now;
        $data['count'] = 1;
    }

    @file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX);
    return true;
}

// =====================================================
// 5) METHOD CHECK (MUST BE BEFORE RATE LIMIT / CSRF)
// =====================================================
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Method Not Allowed. Submit the form from the website.";
    exit;
}

// =====================================================
// 6) CSRF TOKEN SETUP
// =====================================================
if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
    $_SESSION['csrf'] = $_SESSION['csrf_token'];
}

// =====================================================
// 7) RATE LIMITING (SESSION + FALLBACK) — POST ONLY
// =====================================================
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$uaShort = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 180);

$rateLimitKey  = 'service_request_' . hash('sha256', $ip);
$rateLimitTime = 300; // 5 minutes
$maxRequests   = 3;

if (!isset($_SESSION[$rateLimitKey]) || !is_array($_SESSION[$rateLimitKey])) {
    $_SESSION[$rateLimitKey] = ['time' => time(), 'count' => 0];
}

$bucket = $_SESSION[$rateLimitKey];
$bucketTime = isset($bucket['time']) ? (int)$bucket['time'] : 0;
$bucketCount = isset($bucket['count']) ? (int)$bucket['count'] : 0;

if (time() - $bucketTime < $rateLimitTime) {
    if ($bucketCount >= $maxRequests) {
        $fallbackKey = 'svc_' . hash('sha256', $ip . '|' . $uaShort);
        if (!rate_limit_fallback($fallbackKey, $rateLimitTime, $maxRequests)) {
            fail('RATE_LIMIT');
        }
        fail('RATE_LIMIT');
    }
    $bucketCount++;
} else {
    $bucketTime = time();
    $bucketCount = 1;
}

$_SESSION[$rateLimitKey] = ['time' => $bucketTime, 'count' => $bucketCount];

$fallbackKey = 'svc_' . hash('sha256', $ip . '|' . $uaShort);
if (!rate_limit_fallback($fallbackKey, $rateLimitTime, $maxRequests)) {
    fail('RATE_LIMIT');
}

// =====================================================
// 8) CSRF TOKEN CHECK (accept csrf_token OR csrf)
// =====================================================
$postedToken = (string)($_POST['csrf_token'] ?? '');
if ($postedToken === '') {
    $postedToken = (string)($_POST['csrf'] ?? '');
}
$serverToken = (string)($_SESSION['csrf_token'] ?? '');
$legacyToken = (string)($_SESSION['csrf'] ?? '');

if ($postedToken === '' || (
    !hash_equals($serverToken, $postedToken) &&
    !($legacyToken !== '' && hash_equals($legacyToken, $postedToken))
)) {
    fail('CSRF_INVALID');
}

// =====================================================
// 9) DATABASE CONNECTION
// =====================================================
require_once __DIR__ . '/db_connect.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    logError('Database connection not available (missing $pdo)');
    fail('DB_ERROR');
}

// =====================================================
// 10) INPUT VALIDATION & SANITIZATION
// =====================================================
$name     = clean((string)($_POST['name'] ?? ''), 120);
$emailRaw = clean((string)($_POST['email'] ?? ''), 150);
$email    = filter_var($emailRaw, FILTER_SANITIZE_EMAIL);
$service  = clean((string)($_POST['service'] ?? ''), 80);
$message  = clean((string)($_POST['message'] ?? ''), 2000);

if ($name === '' || $email === '' || $service === '' || $message === '') {
    fail('MISSING_FIELDS');
}

if (!filter_var((string)$email, FILTER_VALIDATE_EMAIL)) {
    fail('BAD_EMAIL');
}

if (!in_array($service, $allowedServices, true)) {
    $service = 'Other';
}

// =====================================================
// 11) FILE UPLOAD (OPTIONAL) — HARDENED
// =====================================================
$uploadedFile = null;

if (!empty($_FILES['attachment']) && is_array($_FILES['attachment']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $err = (int)($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        fail('UPLOAD_ERROR');
    }

    $tmpName = (string)($_FILES['attachment']['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        fail('UPLOAD_TAMPERED');
    }

    $size = (int)($_FILES['attachment']['size'] ?? 0);
    if ($size <= 0 || $size > $maxSize) {
        fail('FILE_TOO_LARGE');
    }

    $originalName = (string)($_FILES['attachment']['name'] ?? '');
    $originalName = preg_replace('/[^\x20-\x7E]/', '', $originalName) ?? $originalName;
    $originalName = trim($originalName);

    $lower = strtolower($originalName);
    if (preg_match('/\.(php|phtml|phar|asp|aspx|jsp|cgi|pl)\./i', $lower)) {
        fail('INVALID_FILE_EXT');
    }

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) {
        fail('INVALID_FILE_EXT');
    }

    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string)@finfo_file($finfo, $tmpName) : '';
    if ($finfo) {
        @finfo_close($finfo);
    }

    if ($mime === '' || !in_array($mime, $allowedTypes, true)) {
        fail('INVALID_FILE');
    }

    if ($extension === 'svg') {
        $svgContent = @file_get_contents($tmpName);
        if (!is_string($svgContent) || $svgContent === '') {
            fail('INVALID_FILE');
        }
        if (preg_match('/<script\b/i', $svgContent) ||
            preg_match('/\bon\w+\s*=/i', $svgContent) ||
            preg_match('/javascript\s*:/i', $svgContent)
        ) {
            fail('SVG_SCRIPT_DETECTED');
        }
    }

    if ($extension === 'pdf') {
        $head = @file_get_contents($tmpName, false, null, 0, 5);
        if ($head !== '%PDF-') {
            fail('INVALID_FILE');
        }
    }

    $uniqueId = bin2hex(random_bytes(8));
    $safeBase = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($originalName));
    $safeBase = $safeBase !== '' ? $safeBase : ('file.' . $extension);
    $fileName = $uniqueId . '_' . time() . '_' . $safeBase;

    $dest = $uploadDir . '/' . $fileName;

    if (!@move_uploaded_file($tmpName, $dest)) {
        logError('File move failed', ['dest' => $dest]);
        fail('UPLOAD_FAILED');
    }

    @chmod($dest, 0644);

    $uploadedFile = 'uploads/services/' . $fileName;
}

// =====================================================
// 12) INSERT INTO DATABASE
// =====================================================
try {
    $stmt = $pdo->prepare("
        INSERT INTO service_requests
            (name, email, service_type, message, attachment, ip_address, user_agent, submitted_at)
        VALUES
            (:name, :email, :service, :message, :attachment, :ip, :ua, NOW())
    ");

    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 255);

    $stmt->execute([
        ':name'       => $name,
        ':email'      => $email,
        ':service'    => $service,
        ':message'    => $message,
        ':attachment' => $uploadedFile,
        ':ip'         => $ip,
        ':ua'         => $ua,
    ]);

    $requestId = (int)$pdo->lastInsertId();
    logSuccess($requestId, $service, (string)$email);
} catch (PDOException $e) {
    logError('Database insertion failed', [
        'error' => $e->getMessage(),
        'email' => (string)$email,
        'service' => $service,
    ]);
    fail('DB_ERROR');
}

// =====================================================
// 13) EMAIL NOTIFICATION (BEST EFFORT)
// =====================================================
$serviceSafe = preg_replace("/[\r\n]+/", ' ', $service);
$subject = 'New Service Request -- ' . $serviceSafe;

$safeReply = preg_replace("/[\r\n]+/", '', (string)$email);
if ($safeReply === '' || is_header_injection($safeReply)) {
    $safeReply = '';
}

$host = preg_replace("/[\r\n]+/", '', (string)($_SERVER['HTTP_HOST'] ?? 'diamondsouttadirt.com'));
$fromDomain = $host !== '' ? $host : 'diamondsouttadirt.com';

$emailBody =
"NEW SERVICE REQUEST #{$requestId}\n" .
"=========================================\n\n" .
"Name:    {$name}\n" .
"Email:   {$safeReply}\n" .
"Service: {$service}\n" .
"Date:    " . date('Y-m-d H:i:s') . "\n" .
"IP:      {$ip}\n\n" .
"MESSAGE:\n" .
wordwrap($message, 72) . "\n\n" .
"ATTACHMENT:\n" .
($uploadedFile ? basename($uploadedFile) . " ({$uploadedFile})" : 'None') . "\n\n" .
"=========================================\n" .
"This is an automated notification from the website service request form.\n";

$headers  = "From: Diamonds Outta Dirt <myshineisnow@diamondsouttadirt.com>\r\n";
$headers .= "Reply-To: " . ($safeReply !== '' ? $safeReply : 'myshineisnow@diamondsouttadirt.com') . "\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "Content-Transfer-Encoding: 8bit\r\n";

try {
    $mailSent = @mail($notifyEmail, $subject, $emailBody, $headers, '-f myshineisnow@diamondsouttadirt.com');

    if (!$mailSent) {
        logError('Email notification failed to send', ['request_id' => $requestId]);
    }
} catch (Throwable $t) {
    logError('Email exception', [
        'request_id' => $requestId,
        'error' => $t->getMessage(),
    ]);
}

// =====================================================
// 14) ROTATE CSRF TOKEN
// =====================================================
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$_SESSION['csrf'] = $_SESSION['csrf_token'];

// =====================================================
// 15) SUCCESS REDIRECTION
// =====================================================
$base = services_redirect_base();
header('Location: ' . $base . '?success=1&id=' . $requestId);
exit;