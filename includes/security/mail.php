<?php
declare(strict_types=1);

function clevo_encrypt_mail(array $payload): string
{
    $iv = random_bytes(12); $tag = '';
    $cipher = openssl_encrypt(json_encode($payload, JSON_THROW_ON_ERROR), 'aes-256-gcm',
        hash('sha256', clevo_secret('APP_KEY'), true), OPENSSL_RAW_DATA, $iv, $tag, 'clevo-mail-v1');
    if ($cipher === false) throw new RuntimeException('Mail encryption failed.');
    return base64_encode($iv . $tag . $cipher);
}
function clevo_decrypt_mail(string $encrypted): array
{
    $data = base64_decode($encrypted, true);
    if ($data === false || strlen($data) < 29) throw new RuntimeException('Invalid mail record.');
    $plain = openssl_decrypt(substr($data, 28), 'aes-256-gcm', hash('sha256', clevo_secret('APP_KEY'), true),
        OPENSSL_RAW_DATA, substr($data, 0, 12), substr($data, 12, 16), 'clevo-mail-v1');
    if ($plain === false) throw new RuntimeException('Invalid mail record.');
    return json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
}
function clevo_send_mail(string $to, string $name, string $subject, string $html): bool
{
    global $conn;
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $subject)) return false;
    clevo_stmt($conn, 'INSERT INTO security_mail_queue (PayloadCipher) VALUES (?)', 's', [clevo_encrypt_mail(compact('to', 'name', 'subject', 'html'))]);
    return true;
}
function clevo_deliver_mail(array $payload): void
{
    if (clevo_env('MAIL_TRANSPORT', 'smtp') === 'null' && !clevo_production()) return;
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = clevo_env('SMTP_HOST', '') ?? '';
    $mail->Port = (int) clevo_env('SMTP_PORT', '587');
    $mail->SMTPAuth = true;
    $mail->Username = clevo_env('SMTP_USERNAME', '') ?? '';
    $mail->Password = clevo_env('SMTP_PASSWORD', '') ?? '';
    $mail->SMTPSecure = $mail->Port === 465 ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->SMTPDebug = 0; $mail->Timeout = 15; $mail->CharSet = 'UTF-8';
    $mail->setFrom(clevo_env('MAIL_FROM_ADDRESS', '') ?? '', clevo_env('MAIL_FROM_NAME', 'CLEVO') ?? 'CLEVO');
    $mail->addAddress($payload['to'], $payload['name']);
    $mail->isHTML(true); $mail->Subject = $payload['subject'];
    $mail->Body = $payload['html']; $mail->AltBody = strip_tags($payload['html']);
    $mail->send();
}
