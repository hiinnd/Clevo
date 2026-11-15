<?php
declare(strict_types=1);
// Startup probe only: no schema/data writes and no deployment credentials.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$port = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
$expected = realpath(__DIR__ . '/../.security-test/mysql');
if (!$port || $port < 1024 || $port === 3306 || !$expected) exit(11);
mysqli_report(MYSQLI_REPORT_OFF);
try {
    $db = @new mysqli('127.0.0.1', 'root', '', '', $port);
    if ($db->connect_errno) exit(10);
    $actual = realpath($db->query('SELECT @@datadir AS dir')->fetch_assoc()['dir']);
    exit($actual && strcasecmp($actual, $expected) === 0 ? 0 : 11);
} catch (Throwable $e) { exit(10); }
