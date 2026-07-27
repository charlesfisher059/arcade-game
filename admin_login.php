<?php
declare(strict_types=1);

/**
 * admin_login.php (legacy redirect)
 * Path: /home2/asqrtyte/public_html/admin_login.php
 *
 * Purpose:
 * - Redirect old admin login endpoint to the current hardened admin portal.
 *
 * Notes:
 * - Uses 302 by default (safe during migration).
 * - Change to 301 once you're sure everything is permanent.
 */

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

header('Location: /admin/login.php', true, 302);
exit;