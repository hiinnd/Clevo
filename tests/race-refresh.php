<?php
require __DIR__ . '/bootstrap.php';
try {
    clevo_validate_environment(); $conn = clevo_connect_database();
    $token = trim(fgets(STDIN));
    clevo_rotate_refresh($conn, $token);
    echo "rotated\n";
} catch (ClevoSecurityException $error) { echo $error->reason . "\n"; }
catch (Throwable $error) { echo 'error_' . (int) $error->getCode() . "\n"; exit(1); }
