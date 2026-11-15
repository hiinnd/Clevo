<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
require_once __DIR__ . '/security/environment.php';
require_once __DIR__ . '/security/http.php';
require_once __DIR__ . '/security/auth.php';
require_once __DIR__ . '/security/mail.php';
require_once __DIR__ . '/security/uploads.php';
require_once __DIR__ . '/security/resources.php';
require_once __DIR__ . '/security/driver.php';
require_once __DIR__ . '/security/schema.php';

set_exception_handler(static function (Throwable $error): void {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'CLEVO command failed: ' . get_class($error) . "\n");
        exit(1);
    }
    if ($error instanceof ClevoSecurityException) clevo_json_response('error', [], $error->reason, $error->httpStatus);
    error_log('CLEVO request failed: ' . get_class($error));
    if (defined('CLEVO_API') && CLEVO_API) clevo_json_response('error', [], 'service_unavailable', 503);
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Service temporarily unavailable. Please contact support.';
});
clevo_load_environment();
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
date_default_timezone_set('UTC');
clevo_validate_environment();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store');
if (clevo_https()) header('Strict-Transport-Security: max-age=31536000');
if (PHP_SAPI !== 'cli' && clevo_production() && !clevo_https()) throw new ClevoSecurityException('https_required', 400);
if (defined('CLEVO_API') && CLEVO_API) clevo_cors();
clevo_start_session();
$conn = clevo_connect_database();
$securitySchema = clevo_stmt($conn, "SELECT Version FROM security_schema_migrations WHERE Version = '001_auth_security'")->get_result()->fetch_assoc();
if (!$securitySchema) throw new RuntimeException('Security migration required.');
if (!(defined('CLEVO_API') && CLEVO_API) && PHP_SAPI !== 'cli') {
    clevo_web_identity($conn);
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $area = basename(dirname($script)); $page = basename($script);
    if (in_array($area, ['admin', 'owner', 'driver'], true)) clevo_require_role($area);
    if ($area === 'user' && !in_array($page, ['support.php', 'live-tracking.php', 'get_order_status.php'], true)) clevo_require_role('customer');
    if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        clevo_require_csrf_token(true);
        clevo_rate_limit($conn, 'web-mutation:ip', $_SERVER['REMOTE_ADDR'] ?? '', 180, 60);
        if (clevo_current_user_id()) clevo_rate_limit($conn, 'web-mutation:user', (string) clevo_current_user_id(), 90, 60);
    }
}
function clevoEnsurePasswordResetColumns(mysqli $conn): void {}
