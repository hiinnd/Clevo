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
function clevo_mail_message_id(string $identity):string
{
    $domain=substr(strrchr(clevo_env('MAIL_FROM_ADDRESS','')??'','@')?:'@notifications.invalid',1);
    if(!preg_match('/^[A-Za-z0-9.-]+$/D',$domain))$domain='notifications.invalid';
    return '<clevo-'.hash('sha256',$identity).'@'.$domain.'>';
}
function clevo_send_mail(string $to, string $name, string $subject, string $html): bool
{
    global $conn;
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $subject)) return false;
    $html='<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0;background:#f0f9ff;font-family:Arial,sans-serif"><table role="presentation" width="100%"><tr><td align="center" style="padding:20px 12px"><table role="presentation" width="100%" style="max-width:600px;background:white;border-radius:20px"><tr><td style="padding:28px"><h1 style="color:#0284c7">CLEVO</h1><p dir="rtl">رسالة خاصة بحسابك — لا تشارك رموز التحقق.</p>'.$html.'<p style="color:#475569;font-size:12px">Account security · أمان الحساب</p></td></tr></table></td></tr></table></body></html>';
    $expires_at=time()+($subject==='CLEVO password reset'?300:($subject==='Verify your CLEVO email'?3600:86400));
    clevo_stmt($conn, 'INSERT INTO security_mail_queue (PayloadCipher) VALUES (?)', 's', [clevo_encrypt_mail(compact('to', 'name', 'subject', 'html','expires_at'))]);
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
    if(isset($payload['message_id']))$mail->MessageID=$payload['message_id'];
    $mail->Body = $payload['html']; $mail->AltBody = strip_tags($payload['html']);
    $mail->send();
}
