<?php
declare(strict_types=1);
if(!defined('CLEVO_API_V1')){http_response_code(404);exit;}
// Existing authentication controllers preserved; bootstrap and routing live in index.php.
$route = trim((string) ($_SERVER['PATH_INFO'] ?? ''), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$public = ['auth/login', 'auth/refresh', 'auth/register', 'auth/password/forgot', 'auth/password/verify', 'auth/password/reset', 'auth/email/verify', 'auth/email/resend', 'auth/driver-exchanges'];
clevo_rate_limit($conn, 'api:ip', $_SERVER['REMOTE_ADDR'] ?? '', 240, 60);

if (in_array($route, $public, true)) {
    if ($method !== 'POST') { header('Allow: POST'); clevo_json_response('error', [], 'method_not_allowed', 405); }
    $multipart = $route === 'auth/register' && str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data;');
    $input = $multipart ? $_POST : clevo_read_json();
    $text = static fn(string $key, int $max = 255): string => clevo_clean_string($input[$key] ?? '', $max);
    if ($route === 'auth/login' || $route === 'auth/driver-exchanges') {
        $login = $text('login', 101);
        clevo_auth_throttle($conn, 'login', $login);
        $job = null;
        if ($route === 'auth/driver-exchanges') {
            $exchange = clevo_driver_code($conn, $login, $text('code', 7));
            $account = $exchange['account'] ?? null; $job = $exchange['booking_id'] ?? null;
        } else {
            $password = $input['password'] ?? null;
            $account = is_string($password) ? clevo_verify_credentials($conn, $login, $password) : null;
        }
        if (!$account) clevo_json_response('error', [], 'invalid_credentials', 401);
        clevo_json_response('success', clevo_token_pair($conn, $account, $job));
    }
    if ($route === 'auth/refresh') {
        clevo_auth_throttle($conn, 'refresh');
        clevo_json_response('success', clevo_rotate_refresh($conn, $text('refresh_token', 65)));
    }
    if ($route === 'auth/register') {
        clevo_auth_throttle($conn, 'register', $text('email', 101));
        if (!in_array($input['role'] ?? 'customer', ['customer', 'owner'], true)) throw new ClevoSecurityException('invalid_registration', 422);
        $document = ($input['role'] ?? '') === 'owner' ? clevo_save_upload($_FILES['verification_file'] ?? [], 'verification') : null;
        clevo_register_account($conn, $input, $document);
        clevo_json_response('success', [], 'verification_required', 201);
    }
    if ($route === 'auth/password/forgot') {
        $email = $text('email', 101); clevo_auth_throttle($conn, 'forgot', $email);
        clevo_json_response('success', ['challenge_id' => clevo_forgot_password($conn, $email)], 'If the account exists, a reset code will be sent.');
    }
    if ($route === 'auth/password/verify') {
        clevo_auth_throttle($conn, 'verify', $text('challenge_id', 65));
        clevo_json_response('success', ['reset_grant' => clevo_verify_reset_otp($conn, $text('challenge_id', 65), $text('otp', 7))]);
    }
    if ($route === 'auth/password/reset') {
        clevo_auth_throttle($conn, 'verify');
        $password = $input['password'] ?? null;
        if (!is_string($password)) throw new ClevoSecurityException('weak_password', 422);
        clevo_reset_password($conn, $text('reset_grant', 130), $password);
        clevo_json_response('success', [], 'password_reset');
    }
    if ($route === 'auth/email/verify') {
        clevo_auth_throttle($conn, 'verify');
        clevo_verify_email($conn, $text('token', 130));
        clevo_json_response('success', [], 'email_verified');
    }
    if ($route === 'auth/email/resend') {
        $email = $text('email', 101); clevo_auth_throttle($conn, 'forgot', $email);
        clevo_resend_verification($conn, $email);
        clevo_json_response('success', [], 'If verification is required, an email will be sent.');
    }
}

