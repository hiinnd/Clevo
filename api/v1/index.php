<?php
declare(strict_types=1);
define('CLEVO_API', true);
require_once __DIR__ . '/../../includes/config.php';

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

$identity = clevo_authenticated_token($conn, clevo_bearer_token());
if (!$identity) clevo_json_response('error', [], 'unauthorized', 401);
$account = $identity['account']; $user = (int) $account['UserID']; $role = clevo_role($account['Role']);
clevo_rate_limit($conn, 'api:user', (string) $user, 120, 60);
if (in_array($method, ['POST', 'PATCH', 'DELETE'], true)) clevo_rate_limit($conn, 'api:sensitive', (string) $user, 30, 60);

if ($route === 'auth/logout' || $route === 'auth/logout-all') {
    if ($method !== 'POST') { header('Allow: POST'); clevo_json_response('error', [], 'method_not_allowed', 405); }
    $conn->begin_transaction();
    try {
        if ($route === 'auth/logout-all') clevo_revoke_user($conn, $user);
        else clevo_revoke_session($conn, $identity['session']['SessionID']);
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
    clevo_json_response('success', [], 'logged_out');
}
if ($route === 'me') {
    if ($method !== 'GET') { header('Allow: GET'); clevo_json_response('error', [], 'method_not_allowed', 405); }
    clevo_json_response('success', ['user' => ['id' => $user, 'name' => $account['UserName'], 'role' => $role,
        'email' => $account['Email'], 'phone' => $account['PhoneNumber']]]);
}
if ($route === 'me/wallet') {
    if ($method !== 'GET') { header('Allow: GET'); clevo_json_response('error', [], 'method_not_allowed', 405); }
    $wallets = clevo_stmt($conn, 'SELECT Balance FROM wallet WHERE UserID = ?', 'i', [$user])->get_result()->fetch_all(MYSQLI_ASSOC);
    if (count($wallets) > 1) throw new ClevoSecurityException('wallet_requires_reconciliation', 409);
    clevo_json_response('success', ['wallet' => $wallets[0] ?? ['Balance' => '0.00']]);
}
if ($route === 'notifications' || preg_match('~^notifications/(\d+)/read$~D', $route, $notice)) {
    if (isset($notice[1])) {
        if ($method !== 'PATCH') { header('Allow: PATCH'); clevo_json_response('error', [], 'method_not_allowed', 405); }
        clevo_mark_notification($conn, $user, $role, (int) $notice[1]);
        clevo_json_response('success');
    }
    if ($method !== 'GET') { header('Allow: GET'); clevo_json_response('error', [], 'method_not_allowed', 405); }
    $rows = clevo_stmt($conn, "SELECT n.id, n.title, n.title_en, n.body, n.body_en, n.created_at,
        IF(n.user_id IS NULL, nr.ReadAt IS NOT NULL, n.is_read) AS is_read FROM notifications n
        LEFT JOIN notification_reads nr ON nr.NotificationID = n.id AND nr.UserID = ?
        WHERE (n.user_id = ? OR (n.user_id IS NULL AND LOWER(n.target_role) = ?)) AND n.id > ? ORDER BY n.id LIMIT 50",
        'iisi', [$user, $user, $role, max(0, (int) ($_GET['after_id'] ?? 0))])->get_result()->fetch_all(MYSQLI_ASSOC);
    clevo_json_response('success', ['notifications' => $rows]);
}
if ($route === 'bookings' || preg_match('~^(?:bookings|driver/tasks)/(\d+)$~D', $route, $booking)) {
    if ($method !== 'GET') { header('Allow: GET'); clevo_json_response('error', [], 'method_not_allowed', 405); }
    if (str_starts_with($route, 'driver/') && $role !== 'driver') throw new ClevoSecurityException('forbidden', 403);
    if (isset($booking[1])) {
        $row = clevo_booking_visible($conn, $identity, (int) $booking[1]);
        if (!$row) throw new ClevoSecurityException('not_found', 404);
        clevo_json_response('success', ['booking' => clevo_booking_dto($row, $role)]);
    }
    if ($role !== 'customer') throw new ClevoSecurityException('forbidden', 403);
    $rows = clevo_stmt($conn, 'SELECT BookingID, Order_Number, StationID, Status, Service_Location, Booking_Date, Booking_Time, Total_Price, Payable_Amount FROM booking WHERE UserID = ? AND BookingID > ? ORDER BY BookingID LIMIT 50',
        'ii', [$user, max(0, (int) ($_GET['after_id'] ?? 0))])->get_result()->fetch_all(MYSQLI_ASSOC);
    clevo_json_response('success', ['bookings' => $rows]);
}
if ($route === 'driver/tasks') {
    if ($method !== 'GET') { header('Allow: GET'); clevo_json_response('error', [], 'method_not_allowed', 405); }
    if ($role !== 'driver') throw new ClevoSecurityException('forbidden', 403);
    $job = (int) ($identity['session']['DriverBookingID'] ?? 0);
    $rows = clevo_stmt($conn, "SELECT b.BookingID, b.Order_Number, b.Status, b.Service_Location FROM booking b JOIN stations s ON s.StationID = b.StationID WHERE b.DriverID = ? AND b.StationID = ? AND s.admin_status = 'approved'
        AND b.Status IN ('Accepted','Arrived','In Progress','Pickup','Washing','Returning') AND (? = 0 OR b.BookingID = ?) ORDER BY b.BookingID DESC LIMIT 50",
        'iiii', [$user, (int) $account['StationID'], $job, $job])->get_result()->fetch_all(MYSQLI_ASSOC);
    clevo_json_response('success', ['tasks' => $rows]);
}
if (preg_match('~^owner/stations/(\d+)/services$~D', $route, $station)) {
    if ($role !== 'owner') throw new ClevoSecurityException('forbidden', 403);
    if (!clevo_own_station($conn, $user, (int) $station[1])) throw new ClevoSecurityException('not_found', 404);
    if ($method !== 'GET') { header('Allow: GET'); clevo_json_response('error', [], 'method_not_allowed', 405); }
    $rows = clevo_stmt($conn, 'SELECT ServiceID, Name_ar, Name_en, Description_ar, Description_en, Price, is_station, is_home, is_pickup FROM services WHERE StationID = ? ORDER BY ServiceID LIMIT 100', 'i', [(int) $station[1]])->get_result()->fetch_all(MYSQLI_ASSOC);
    clevo_json_response('success', ['services' => $rows]);
}
if ($route === 'admin/security-status') {
    if ($role !== 'admin') throw new ClevoSecurityException('forbidden', 403);
    if ($method !== 'GET') { header('Allow: GET'); clevo_json_response('error', [], 'method_not_allowed', 405); }
    clevo_json_response('success', ['authentication' => 'enabled', 'role' => 'admin']);
}
clevo_json_response('error', [], 'resource_not_found', 404);
