<?php
require_once __DIR__ . '/config.php';
$reference = clevo_clean_string($_GET['ref'] ?? '', 255);
$documentUser = (int) ($_GET['user_id'] ?? 0);
if ($documentUser) {
    clevo_require_role('admin');
    $row = clevo_stmt($conn, 'SELECT VerificationFile FROM `user` WHERE UserID = ?', 'i', [$documentUser])->get_result()->fetch_assoc();
    $reference = (string) ($row['VerificationFile'] ?? '');
}
if (preg_match('/^private:(profiles|stations|verification):([a-f0-9]{48}\.(?:jpg|png|gif|webp|pdf))$/D', $reference, $parts)) {
    if ($parts[1] === 'verification') {
        $user = clevo_require_login();
        if (clevo_current_role() !== 'admin' && !clevo_stmt($conn, 'SELECT UserID FROM `user` WHERE UserID = ? AND VerificationFile = ?', 'is', [$user, $reference])->get_result()->fetch_assoc()) throw new ClevoSecurityException('not_found', 404);
    }
    $file = rtrim(clevo_env('PRIVATE_STORAGE_PATH', '') ?? '', '/\\') . '/' . $parts[1] . '/' . $parts[2];
} elseif ($documentUser && $reference !== '') {
    // Authorized legacy document delivery only; no request-controlled filesystem path.
    $file = dirname(__DIR__) . '/uploads/verification/' . basename($reference);
} else { throw new ClevoSecurityException('not_found', 404); }
if (!is_file($file) || is_link($file)) throw new ClevoSecurityException('not_found', 404);
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'], true)) throw new ClevoSecurityException('not_found', 404);
header('Content-Type: ' . $mime);
header('Content-Security-Policy: sandbox');
header('Content-Disposition: ' . ($mime === 'application/pdf' ? 'attachment' : 'inline') . '; filename="document.' . pathinfo($file, PATHINFO_EXTENSION) . '"');
header('Content-Length: ' . filesize($file));
readfile($file);
