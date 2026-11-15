<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/config.php';
for ($n = 0; $n < 50; $n++) {
    $conn->begin_transaction();
    $row = $conn->query('SELECT * FROM security_mail_queue WHERE SentAt IS NULL AND Attempts < 5 AND AvailableAt <= UTC_TIMESTAMP() ORDER BY ID LIMIT 1 FOR UPDATE')->fetch_assoc();
    if (!$row) { $conn->commit(); break; }
    try {
        clevo_deliver_mail(clevo_decrypt_mail($row['PayloadCipher']));
        clevo_stmt($conn, "UPDATE security_mail_queue SET SentAt = UTC_TIMESTAMP(), PayloadCipher = '', Attempts = Attempts + 1 WHERE ID = ?", 'i', [(int) $row['ID']]);
    } catch (Throwable $error) {
        clevo_stmt($conn, 'UPDATE security_mail_queue SET Attempts = Attempts + 1, AvailableAt = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE ID = ?', 'i', [(int) $row['ID']]);
        error_log('CLEVO mail delivery failed; queue record ' . (int) $row['ID']);
    }
    $conn->commit();
}
// Preserve used refresh records until family expiry so replays remain detectable.
$conn->query('DELETE FROM security_rate_limits WHERE ExpiresAt < UNIX_TIMESTAMP() - 86400');
$conn->query('DELETE FROM auth_sessions WHERE ExpiresAt < UTC_TIMESTAMP() - INTERVAL 1 DAY');
$conn->query('DELETE FROM auth_challenges WHERE ExpiresAt < UTC_TIMESTAMP() - INTERVAL 1 DAY');
