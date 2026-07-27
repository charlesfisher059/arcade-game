<?php
declare(strict_types=1);
/**
 * push_selftest.php
 * Path: /home2/asqrtyte/public_html/push_selftest.php
 *
 * Run this ONCE after uploading includes/webpush.php, then delete it or
 * restrict it -- it has no auth check and isn't meant to stay public
 * long-term. It replays the official RFC 8291 test vectors through this
 * server's actual PHP/OpenSSL and reports pass/fail for each step, so you
 * know the crypto works on THIS server before wiring it into anything
 * real.
 */
require_once __DIR__ . '/includes/webpush.php';

$result = webpush_selftest();
header('Content-Type: text/plain');
echo "PHP version: " . $result['php_version'] . "\n";
echo "openssl_pkey_derive() available: " . ($result['has_pkey_derive'] ? 'YES' : 'NO -- REQUIRES PHP 8.1+, this will not work') . "\n\n";
echo "OVERALL: " . ($result['pass'] ? 'PASS -- safe to use webpush_send()' : 'FAIL -- do not rely on this yet') . "\n\n";
foreach ($result['details'] as $key => $value) {
    if ($key === 'exception') {
        echo "EXCEPTION: " . $value . "\n";
        continue;
    }
    echo str_pad($key, 40) . ($value ? 'PASS' : 'FAIL') . "\n";
}