<?php
// Legacy template adapter. Secrets and mail transport live in security/mail.php.
function sendResetOTP($toEmail, $userName, $otp): bool {
    return clevo_send_mail((string) $toEmail, (string) $userName, 'CLEVO password reset',
        '<p>' . e($userName) . '</p><p>Single-use code (5 minutes): ' . e($otp) . '</p>');
}
