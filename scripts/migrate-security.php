<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/security/environment.php';
require_once __DIR__ . '/../includes/security/http.php';
require_once __DIR__ . '/../includes/security/auth.php';
try {
    clevo_load_environment(); clevo_validate_environment();
    $conn = clevo_connect_database();
    $lock = clevo_stmt($conn, "SELECT GET_LOCK(CONCAT(DATABASE(), '.security-migration'), 10) AS acquired")->get_result()->fetch_assoc();
    if ((int) $lock['acquired'] !== 1) throw new RuntimeException('Migration lock unavailable.');
    $conn->query('CREATE TABLE IF NOT EXISTS security_schema_migrations (Version VARCHAR(100) PRIMARY KEY, Checksum CHAR(64) NOT NULL, AppliedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $conn->query('CREATE TABLE IF NOT EXISTS security_migration_steps (Version VARCHAR(100) NOT NULL, StepNumber INT NOT NULL, Checksum CHAR(64) NOT NULL, PRIMARY KEY(Version, StepNumber)) ENGINE=InnoDB');
    $version = '001_auth_security';
    $source = file_get_contents(__DIR__ . '/../migrations/' . $version . '.sql');
    $checksum = hash('sha256', $source . file_get_contents(__DIR__ . '/../migrations/000_base_compatibility.php')
        . file_get_contents(__DIR__ . '/../migrations/exposed-password-fingerprints.php'));
    $done = clevo_stmt($conn, 'SELECT Checksum FROM security_schema_migrations WHERE Version = ?', 's', [$version])->get_result()->fetch_assoc();
    if ($done) {
        if (!hash_equals($done['Checksum'], $checksum)) throw new RuntimeException('Applied migration changed.');
        echo "Security migration already applied.\n"; exit(0);
    }
    $started = clevo_stmt($conn, 'SELECT Checksum FROM security_migration_steps WHERE Version = ? AND StepNumber = 0', 's', [$version])->get_result()->fetch_assoc();
    if ($started && !hash_equals($started['Checksum'], $checksum)) throw new RuntimeException('Partial migration changed.');
    clevo_stmt($conn, 'INSERT IGNORE INTO security_migration_steps (Version, StepNumber, Checksum) VALUES (?, 0, ?)', 'ss', [$version, $checksum]);
    require __DIR__ . '/../migrations/000_base_compatibility.php';
    $clean = preg_replace('/^\s*--[^\r\n]*/m', '', $source);
    $statements = array_filter(array_map('trim', explode(';', $clean)));
    $step = 0;
    foreach ($statements as $statement) {
        $step++;
        $row = clevo_stmt($conn, 'SELECT Checksum FROM security_migration_steps WHERE Version = ? AND StepNumber = ?', 'si', [$version, $step])->get_result()->fetch_assoc();
        if ($row) {
            if (!hash_equals($row['Checksum'], $checksum)) throw new RuntimeException('Partial migration changed.');
            continue;
        }
        $conn->query($statement);
        clevo_stmt($conn, 'INSERT INTO security_migration_steps (Version, StepNumber, Checksum) VALUES (?, ?, ?)', 'sis', [$version, $step, $checksum]);
    }
    $fingerprints = require __DIR__ . '/../migrations/exposed-password-fingerprints.php';
    $users = $conn->query('SELECT UserID, Password FROM `user`'); $disabled = 0;
    $conn->begin_transaction();
    while ($user = $users->fetch_assoc()) {
        if ((password_get_info($user['Password'])['algoName'] ?? 'unknown') === 'unknown'
            || in_array(hash('sha256', $user['Password']), $fingerprints, true)) {
            $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            clevo_stmt($conn, 'UPDATE `user` SET Password = ?, MustResetPassword = 1, AuthVersion = AuthVersion + 1 WHERE UserID = ?', 'si', [$hash, (int) $user['UserID']]);
            $disabled++;
        }
    }
    clevo_stmt($conn, 'INSERT INTO security_schema_migrations (Version, Checksum) VALUES (?, ?)', 'ss', [$version, $checksum]);
    $conn->commit();
    echo "Security migration applied. Accounts requiring credential recovery: $disabled.\n";
} catch (Throwable $error) {
    if (isset($conn)) { try { $conn->rollback(); } catch (Throwable $ignored) {} }
    // No SQL or credential values in migration output.
    fwrite(STDERR, 'Migration failed (' . get_class($error) . ', code ' . (int) $error->getCode() . "). Review migration step state before retry.\n");
    exit(1);
}
