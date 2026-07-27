<?php
declare(strict_types=1);

/**
 * arcade_health.php
 * Path: /home2/asqrtyte/public_html/arcade_health.php
 *
 * Lightweight public health-check endpoint, suitable for an uptime
 * monitor. Deliberately doesn't expose which tables are missing (that
 * detail lives in admin/arcade_system.php, behind admin auth) -- a
 * public endpoint should say "healthy or not", not hand out a map of
 * what's broken.
 */

require __DIR__ . '/arcade_app/bootstrap.php';
require_once ARCADE_APP_PATH . '/includes/arcade_health.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$report = arcade_health_report($pdo ?? null);

http_response_code($report['ok'] ? 200 : 503);
echo json_encode([
    'ok' => $report['ok'],
    'maintenance_mode' => $report['maintenance_mode'],
    'version' => arcade_version_string(),
    'schema_version' => $report['schema_version'],
    'checked_at' => $report['checked_at'],
], JSON_UNESCAPED_SLASHES);
