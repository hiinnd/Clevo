<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security/environment.php';
require_once __DIR__ . '/../includes/security/http.php';
require_once __DIR__ . '/../includes/security/auth.php';
require_once __DIR__ . '/../includes/security/resources.php';
require_once __DIR__ . '/../includes/security/mail.php';
require_once __DIR__ . '/../includes/security/uploads.php';
require_once __DIR__ . '/../includes/security/driver.php';
require_once __DIR__ . '/../includes/security/schema.php';
require_once __DIR__ . '/../includes/commerce.php';
date_default_timezone_set('UTC');
ini_set('zend.exception_ignore_args', '1');
clevo_load_environment();
$assertions = 0; $failures = [];
function check(bool $value, string $label): void
{
    global $assertions, $failures;
    $assertions++;
    if (!$value) $failures[] = $label;
}
function rejects(callable $operation, string $label, ?string $reason = null): void
{
    try { $operation(); check(false, $label); }
    catch (Throwable $error) { check($reason === null || ($error instanceof ClevoSecurityException && $error->reason === $reason), $label); }
}
function finish_tests(string $suite): never
{
    global $assertions, $failures;
    foreach ($failures as $label) echo 'FAIL: ' . $label . "\n";
    echo $suite . ': ' . ($assertions - count($failures)) . '/' . $assertions . ' assertions passed; ' . count($failures) . " failed.\n";
    exit($failures ? 1 : 0);
}
