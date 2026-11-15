<?php
declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function clevo_stmt(mysqli $conn, string $sql, string $types = '', array $params = []): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt;
}

function clevo_role(string $role): string
{
    $role = strtolower(trim($role));
    return in_array($role, ['user', 'customer'], true) ? 'customer'
        : (in_array($role, ['admin', 'owner', 'driver'], true) ? $role : '');
}

function clevo_password_valid(string $password): bool
{
    // Bcrypt truncates after 72 bytes; reject rather than silently weakening passwords.
    return strlen($password) >= 8 && strlen($password) <= 72
        && !preg_match('/[\x00-\x1F\x7F]/', $password)
        && preg_match('/[a-z]/', $password) && preg_match('/[A-Z]/', $password)
        && preg_match('/[0-9]/', $password) && preg_match('/[^a-zA-Z0-9]/', $password);
}

function clevo_account(mysqli $conn, int $id, bool $lock = false): ?array
{
    return clevo_stmt($conn, 'SELECT UserID, UserName, Email, PhoneNumber, Password, Role, AccountStatus,
        AuthVersion, MustResetPassword, EmailVerifiedAt, PendingEmail, StationID FROM `user` WHERE UserID = ?' . ($lock ? ' FOR UPDATE' : ''), 'i', [$id])->get_result()->fetch_assoc();
}

function clevo_account_active(mysqli $conn, ?array $account): bool
{
    if (!$account || $account['AccountStatus'] !== 'active' || clevo_role($account['Role']) === '') return false;
    if (clevo_role($account['Role']) === 'owner') {
        $row = clevo_stmt($conn, "SELECT COUNT(*) AS total, SUM(admin_status = 'approved') AS approved FROM stations WHERE UserID = ?", 'i', [(int) $account['UserID']])->get_result()->fetch_assoc();
        if ((int) $row['total'] > 0 && (int) $row['approved'] === 0) return false;
    }
    return true;
}

function clevo_verify_credentials(mysqli $conn, string $login, string $password): ?array
{
    if (strlen($login) > 100 || strlen($password) > 72 || $password === '') return null;
    $account = clevo_stmt($conn, 'SELECT UserID FROM `user` WHERE Email = ? OR PhoneNumber = ? LIMIT 1', 'ss', [$login, $login])->get_result()->fetch_assoc();
    $account = $account ? clevo_account($conn, (int) $account['UserID']) : null;
    // An ephemeral dummy hash keeps non-account attempts on the password verification path.
    static $dummy;
    $dummy ??= password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $valid = password_verify($password, $account['Password'] ?? $dummy);
    if (!$valid || !clevo_account_active($conn, $account) || (int) $account['MustResetPassword'] !== 0) return null;
    if (password_needs_rehash($account['Password'], PASSWORD_DEFAULT)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        clevo_stmt($conn, 'UPDATE `user` SET Password = ? WHERE UserID = ? AND Password = ?', 'sis', [$hash, (int) $account['UserID'], $account['Password']]);
    }
    return $account;
}

function clevo_auth_session(mysqli $conn, array $account, ?int $job = null): array
{
    $sid = bin2hex(random_bytes(32));
    $ttl = max(3600, min(2592000, (int) clevo_env('REFRESH_TTL', '2592000')));
    if ($job !== null) $ttl = min($ttl, 43200);
    $expiry = gmdate('Y-m-d H:i:s', time() + $ttl);
    clevo_stmt($conn, 'INSERT INTO auth_sessions (SessionID, UserID, AuthVersion, RoleSnapshot, DriverBookingID, ExpiresAt) VALUES (?, ?, ?, ?, ?, ?)',
        'siisis', [$sid, (int) $account['UserID'], (int) $account['AuthVersion'], clevo_role($account['Role']), $job, $expiry]);
    return ['SessionID' => $sid, 'UserID' => (int) $account['UserID'], 'AuthVersion' => (int) $account['AuthVersion'],
        'RoleSnapshot' => clevo_role($account['Role']), 'DriverBookingID' => $job, 'ExpiresAt' => $expiry];
}

function clevo_access_token(array $account, array $session): string
{
    $now = time();
    $ttl = max(60, min(900, (int) clevo_env('JWT_ACCESS_TTL', '900')));
    return JWT::encode(['iss' => clevo_env('JWT_ISSUER', 'clevo'), 'aud' => clevo_env('JWT_AUDIENCE', 'clevo-mobile'),
        'sub' => (string) $account['UserID'], 'userID' => (int) $account['UserID'], 'role' => clevo_role($account['Role']),
        'sid' => $session['SessionID'], 'ver' => (int) $account['AuthVersion'], 'jti' => bin2hex(random_bytes(16)),
        'type' => 'access', 'iat' => $now, 'nbf' => $now, 'exp' => min($now + $ttl, strtotime($session['ExpiresAt'] . ' UTC'))],
        clevo_secret('JWT_SECRET'), 'HS256');
}

function clevo_validate_jwt(string $token): ?array
{
    if ($token === '' || strlen($token) > 4096) return null;
    try {
        $header = new stdClass();
        $payload = (array) JWT::decode($token, new Key(clevo_secret('JWT_SECRET'), 'HS256'), $header);
        $now = time();
        if (($header->typ ?? '') !== 'JWT' || ($header->alg ?? '') !== 'HS256'
            || ($payload['iss'] ?? null) !== clevo_env('JWT_ISSUER', 'clevo')
            || ($payload['aud'] ?? null) !== clevo_env('JWT_AUDIENCE', 'clevo-mobile')
            || ($payload['type'] ?? '') !== 'access' || !is_string($payload['sub'] ?? null)
            || !ctype_digit($payload['sub']) || (int) $payload['sub'] <= 0
            || ($payload['userID'] ?? null) !== (int) $payload['sub']
            || !is_int($payload['ver'] ?? null) || $payload['ver'] < 1
            || !is_string($payload['role'] ?? null) || clevo_role($payload['role']) !== $payload['role']
            || !preg_match('/^[a-f0-9]{64}$/D', $payload['sid'] ?? '')
            || !preg_match('/^[a-f0-9]{32}$/D', $payload['jti'] ?? '')) return null;
        foreach (['iat', 'nbf', 'exp'] as $claim) if (!is_int($payload[$claim] ?? null)) return null;
        if ($payload['iat'] > $now || $payload['nbf'] > $now || $payload['exp'] <= $now
            || $payload['exp'] <= $payload['iat'] || $payload['exp'] - $payload['iat'] > 900) return null;
        return $payload;
    } catch (Throwable $e) { return null; }
}

function clevo_session_valid(mysqli $conn, array $session, ?array $account): bool
{
    if (!clevo_account_active($conn, $account) || !empty($session['RevokedAt'])
        || strtotime($session['ExpiresAt'] . ' UTC') <= time()
        || (int) $session['AuthVersion'] !== (int) $account['AuthVersion']
        || ($session['RoleSnapshot'] ?? '') !== clevo_role($account['Role'])) return false;
    if (!empty($session['DriverBookingID'])) {
        return (bool) clevo_stmt($conn, "SELECT b.BookingID FROM booking b JOIN stations s ON s.StationID = b.StationID WHERE b.BookingID = ? AND b.DriverID = ? AND b.StationID = ? AND s.admin_status = 'approved'
            AND b.Service_Location = 'delivery' AND b.Status IN ('Accepted','Arrived','In Progress','Pickup','Washing','Returning')",
            'iii', [(int) $session['DriverBookingID'], (int) $account['UserID'], (int) $account['StationID']])->get_result()->fetch_assoc();
    }
    return true;
}

function clevo_authenticated_token(mysqli $conn, string $token): ?array
{
    $payload = clevo_validate_jwt($token);
    if (!$payload) return null;
    $account = clevo_account($conn, (int) $payload['sub']);
    $session = clevo_stmt($conn, 'SELECT * FROM auth_sessions WHERE SessionID = ? AND UserID = ?', 'si', [$payload['sid'], (int) $payload['sub']])->get_result()->fetch_assoc();
    if (!$session || !clevo_session_valid($conn, $session, $account)
        || $payload['ver'] !== (int) $account['AuthVersion'] || $payload['role'] !== clevo_role($account['Role'])) return null;
    return ['account' => $account, 'session' => $session, 'payload' => $payload];
}

function clevo_refresh_record(mysqli $conn, array $session): string
{
    $token = bin2hex(random_bytes(32));
    clevo_stmt($conn, 'INSERT INTO auth_refresh_tokens (TokenHash, SessionID, ExpiresAt) VALUES (?, ?, ?)', 'sss',
        [hash('sha256', $token), $session['SessionID'], $session['ExpiresAt']]);
    return $token;
}

function clevo_token_pair(mysqli $conn, array $account, ?int $job = null): array
{
    $expectedVersion = (int) $account['AuthVersion'];
    $conn->begin_transaction();
    try {
        // Serialize issuance with account reset/restriction changes.
        $account = clevo_account($conn, (int) $account['UserID'], true);
        if (!clevo_account_active($conn, $account) || (int) $account['AuthVersion'] !== $expectedVersion) throw new ClevoSecurityException('invalid_credentials', 401);
        $session = clevo_auth_session($conn, $account, $job);
        $refresh = clevo_refresh_record($conn, $session);
        $access = clevo_access_token($account, $session);
        $conn->commit();
        return ['access_token' => $access, 'refresh_token' => $refresh, 'token_type' => 'Bearer',
            'expires_in' => clevo_access_expires_in($access)];
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}

function clevo_rotate_refresh(mysqli $conn, string $token): array
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new ClevoSecurityException('invalid_refresh_token', 401);
    $hash = hash('sha256', $token);
    $lookup = clevo_stmt($conn, 'SELECT s.UserID, s.SessionID FROM auth_refresh_tokens t JOIN auth_sessions s ON s.SessionID = t.SessionID WHERE t.TokenHash = ?', 's', [$hash])->get_result()->fetch_assoc();
    if (!$lookup) throw new ClevoSecurityException('invalid_refresh_token', 401);
    $conn->begin_transaction();
    try {
        $account = clevo_account($conn, (int) $lookup['UserID'], true);
        $session = clevo_stmt($conn, 'SELECT * FROM auth_sessions WHERE SessionID = ? FOR UPDATE', 's', [$lookup['SessionID']])->get_result()->fetch_assoc();
        $record = clevo_stmt($conn, 'SELECT * FROM auth_refresh_tokens WHERE TokenHash = ? FOR UPDATE', 's', [$hash])->get_result()->fetch_assoc();
        if (!empty($record['UsedAt'])) {
            // Commit revocation before returning the replay error. Keep used records until family expiry.
            clevo_revoke_session($conn, $lookup['SessionID']);
            $conn->commit();
            throw new ClevoSecurityException('refresh_replay_detected', 401);
        }
        if (!$record || !empty($record['RevokedAt']) || !$session || !clevo_session_valid($conn, $session, $account)
            || strtotime($record['ExpiresAt'] . ' UTC') <= time()) throw new ClevoSecurityException('invalid_refresh_token', 401);
        clevo_stmt($conn, 'UPDATE auth_refresh_tokens SET UsedAt = UTC_TIMESTAMP() WHERE TokenHash = ?', 's', [$hash]);
        $refresh = clevo_refresh_record($conn, $session);
        $access = clevo_access_token($account, $session);
        $conn->commit();
        return ['access_token' => $access, 'refresh_token' => $refresh, 'token_type' => 'Bearer',
            'expires_in' => clevo_access_expires_in($access)];
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}

function clevo_revoke_session(mysqli $conn, string $sid): void
{
    clevo_stmt($conn, 'UPDATE auth_sessions SET RevokedAt = COALESCE(RevokedAt, UTC_TIMESTAMP()) WHERE SessionID = ?', 's', [$sid]);
    clevo_stmt($conn, 'UPDATE auth_refresh_tokens SET RevokedAt = COALESCE(RevokedAt, UTC_TIMESTAMP()) WHERE SessionID = ?', 's', [$sid]);
}
function clevo_revoke_user(mysqli $conn, int $id): void
{
    clevo_stmt($conn, 'UPDATE `user` SET AuthVersion = AuthVersion + 1, PendingEmail = NULL WHERE UserID = ?', 'i', [$id]);
    clevo_stmt($conn, 'UPDATE auth_challenges SET ConsumedAt = COALESCE(ConsumedAt, UTC_TIMESTAMP()) WHERE UserID = ?', 'i', [$id]);
    clevo_stmt($conn, 'UPDATE auth_sessions SET RevokedAt = COALESCE(RevokedAt, UTC_TIMESTAMP()) WHERE UserID = ?', 'i', [$id]);
    clevo_stmt($conn, 'UPDATE auth_refresh_tokens t JOIN auth_sessions s ON s.SessionID = t.SessionID SET t.RevokedAt = COALESCE(t.RevokedAt, UTC_TIMESTAMP()) WHERE s.UserID = ?', 'i', [$id]);
}

function clevo_web_login(mysqli $conn, array $account, ?int $job = null): void
{
    $expectedVersion = (int) $account['AuthVersion'];
    $conn->begin_transaction();
    try {
        $account = clevo_account($conn, (int) $account['UserID'], true);
        if (!clevo_account_active($conn, $account) || (int) $account['AuthVersion'] !== $expectedVersion) throw new ClevoSecurityException('invalid_credentials', 401);
        $auth = clevo_auth_session($conn, $account, $job);
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
    session_regenerate_id(true);
    $_SESSION = ['userID' => (int) $account['UserID'], 'userName' => $account['UserName'],
        'role' => clevo_role($account['Role']) === 'admin' ? 'Admin' : clevo_role($account['Role']),
        'auth_session_id' => $auth['SessionID'], 'auth_version' => (int) $account['AuthVersion'],
        'authenticated_at' => time(), 'last_seen' => time(), 'csrf_token' => bin2hex(random_bytes(32))];
    if ($job) $_SESSION['driver_booking_id'] = $job;
    // Retire the old long-lived JWT cookie. Web pages continue using secure PHP sessions.
    setcookie('access_token', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => clevo_production() || clevo_https(), 'httponly' => true, 'samesite' => 'Lax']);
    clevo_stmt($conn, 'UPDATE `user` SET Last_Login = UTC_TIMESTAMP() WHERE UserID = ?', 'i', [(int) $account['UserID']]);
}

function clevo_web_identity(mysqli $conn): void
{
    if (empty($_SESSION['userID'])) return;
    $account = clevo_account($conn, (int) $_SESSION['userID']);
    $sid = (string) ($_SESSION['auth_session_id'] ?? '');
    $session = $sid !== '' ? clevo_stmt($conn, 'SELECT * FROM auth_sessions WHERE SessionID = ? AND UserID = ?', 'si', [$sid, (int) $_SESSION['userID']])->get_result()->fetch_assoc() : null;
    if (!$session || !clevo_session_valid($conn, $session, $account)
        || time() - (int) ($_SESSION['last_seen'] ?? 0) > 1800
        || time() - (int) ($_SESSION['authenticated_at'] ?? 0) > 43200) {
        $_SESSION = ['csrf_token' => bin2hex(random_bytes(32))];
        session_regenerate_id(true);
        return;
    }
    $_SESSION['role'] = clevo_role($account['Role']) === 'admin' ? 'Admin' : clevo_role($account['Role']);
    $_SESSION['userName'] = $account['UserName'];
    $_SESSION['last_seen'] = time();
}

function clevo_current_user_id(): int { return (int) ($_SESSION['userID'] ?? 0); }
function clevo_current_role(): string { return clevo_role((string) ($_SESSION['role'] ?? '')); }
function clevo_require_login(string $redirect = '../general/login.php'): int
{
    if (clevo_current_user_id() <= 0) {
        header('Location: ' . $redirect);
        exit;
    }
    return clevo_current_user_id();
}
function clevo_require_role($roles, string $redirect = '../general/login.php'): int
{
    $id = clevo_require_login($redirect);
    if (!in_array(clevo_current_role(), array_map('clevo_role', (array) $roles), true)) throw new ClevoSecurityException('forbidden', 403);
    return $id;
}

function clevo_current_jwt_payload(): ?array
{
    global $conn;
    $auth = clevo_authenticated_token($conn, clevo_bearer_token());
    return $auth['payload'] ?? null;
}
function clevo_bearer_token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    return preg_match('/^Bearer ([a-zA-Z0-9._-]+)$/D', $header, $m) ? $m[1] : '';
}

function clevo_own_station(mysqli $conn, int $owner, int $station, bool $approved = false): bool
{
    return (bool) clevo_stmt($conn, 'SELECT StationID FROM stations WHERE StationID = ? AND UserID = ?'
        . ($approved ? " AND admin_status = 'approved'" : ''), 'ii', [$station, $owner])->get_result()->fetch_assoc();
}

function clevo_booking_visible(mysqli $conn, array $identity, int $id): ?array
{
    $account = $identity['account']; $user = (int) $account['UserID']; $role = clevo_role($account['Role']);
    $filter = match ($role) {
        'customer' => 'b.UserID = ?', 'owner' => 's.UserID = ?',
        'driver' => "b.DriverID = ? AND b.StationID = (SELECT StationID FROM user WHERE UserID = b.DriverID) AND s.admin_status = 'approved' AND b.Status IN ('Accepted','Arrived','In Progress','Pickup','Washing','Returning')",
        'admin' => '? > 0', default => '1 = 0 AND ? > 0',
    };
    $row = clevo_stmt($conn, "SELECT b.*, s.UserID AS OwnerID FROM booking b JOIN stations s ON s.StationID = b.StationID WHERE b.BookingID = ? AND $filter", 'ii', [$id, $user])->get_result()->fetch_assoc();
    if ($role === 'driver' && !empty($identity['session']['DriverBookingID']) && (int) $identity['session']['DriverBookingID'] !== $id) return null;
    return $row;
}

function clevo_new_challenge(mysqli $conn, int $id, string $purpose, string $secret, int $ttl, ?string $targetEmail = null): string
{
    $challenge = bin2hex(random_bytes(32));
    clevo_stmt($conn, 'UPDATE auth_challenges SET ConsumedAt = UTC_TIMESTAMP() WHERE UserID = ? AND Purpose = ? AND ConsumedAt IS NULL', 'is', [$id, $purpose]);
    clevo_stmt($conn, 'INSERT INTO auth_challenges (ChallengeID, UserID, Purpose, SecretHash, ExpiresAt, TargetEmail) VALUES (?, ?, ?, ?, ?, ?)',
        'sissss', [$challenge, $id, $purpose, hash_hmac('sha256', $secret, clevo_secret('APP_KEY')), gmdate('Y-m-d H:i:s', time() + $ttl), $targetEmail]);
    return $challenge;
}

function clevo_challenge_matches(?array $row, string $purpose, string $secret): bool
{
    return $row && $row['Purpose'] === $purpose && empty($row['ConsumedAt']) && (int) $row['Attempts'] < 5
        && strtotime($row['ExpiresAt'] . ' UTC') > time()
        && hash_equals($row['SecretHash'], hash_hmac('sha256', $secret, clevo_secret('APP_KEY')));
}

function clevo_forgot_password(mysqli $conn, string $email): string
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) throw new ClevoSecurityException('invalid_email', 422);
    $account = clevo_stmt($conn, 'SELECT UserID, UserName, Email FROM `user` WHERE Email = ? LIMIT 1', 's', [$email])->get_result()->fetch_assoc();
    $challenge = bin2hex(random_bytes(32));
    if ($account) {
        $otp = (string) random_int(100000, 999999);
        $conn->begin_transaction();
        try {
            clevo_account($conn, (int) $account['UserID'], true);
            $challenge = clevo_new_challenge($conn, (int) $account['UserID'], 'reset_otp', $otp, 300);
            $conn->commit();
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
        clevo_send_mail($account['Email'], $account['UserName'], 'CLEVO password reset',
            '<p>' . e($account['UserName']) . '</p><p>Your single-use reset code (expires in 5 minutes): <strong>' . e($otp) . '</strong></p>');
    } else {
        // Equivalent cryptographic work without creating a user or revealing existence.
        hash_hmac('sha256', bin2hex(random_bytes(32)), clevo_secret('APP_KEY'));
    }
    return $challenge;
}

function clevo_verify_reset_otp(mysqli $conn, string $challenge, string $otp): string
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $challenge) || !preg_match('/^\d{6}$/D', $otp)) throw new ClevoSecurityException('invalid_challenge', 422);
    $lookup = clevo_stmt($conn, 'SELECT UserID FROM auth_challenges WHERE ChallengeID = ?', 's', [$challenge])->get_result()->fetch_assoc();
    if (!$lookup) throw new ClevoSecurityException('invalid_challenge', 422);
    $conn->begin_transaction();
    try {
        clevo_account($conn, (int) $lookup['UserID'], true);
        $row = clevo_stmt($conn, 'SELECT * FROM auth_challenges WHERE ChallengeID = ? FOR UPDATE', 's', [$challenge])->get_result()->fetch_assoc();
        if (!clevo_challenge_matches($row, 'reset_otp', $otp)) {
            if ($row && $row['Purpose'] === 'reset_otp' && empty($row['ConsumedAt'])) {
                clevo_stmt($conn, 'UPDATE auth_challenges SET Attempts = Attempts + 1 WHERE ChallengeID = ?', 's', [$challenge]);
            }
            $conn->commit();
            throw new ClevoSecurityException('invalid_challenge', 422);
        }
        clevo_stmt($conn, 'UPDATE auth_challenges SET ConsumedAt = UTC_TIMESTAMP() WHERE ChallengeID = ?', 's', [$challenge]);
        $grant = bin2hex(random_bytes(32));
        $grantId = clevo_new_challenge($conn, (int) $row['UserID'], 'reset_grant', $grant, 300);
        $conn->commit();
        return $grantId . '.' . $grant;
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}

function clevo_reset_password(mysqli $conn, string $grant, string $password): void
{
    if (!clevo_password_valid($password)) throw new ClevoSecurityException('weak_password', 422);
    if (!preg_match('/^([a-f0-9]{64})\.([a-f0-9]{64})$/D', $grant, $parts)) throw new ClevoSecurityException('invalid_challenge', 422);
    $lookup = clevo_stmt($conn, 'SELECT UserID FROM auth_challenges WHERE ChallengeID = ?', 's', [$parts[1]])->get_result()->fetch_assoc();
    if (!$lookup) throw new ClevoSecurityException('invalid_challenge', 422);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $conn->begin_transaction();
    try {
        clevo_account($conn, (int) $lookup['UserID'], true);
        $row = clevo_stmt($conn, 'SELECT * FROM auth_challenges WHERE ChallengeID = ? FOR UPDATE', 's', [$parts[1]])->get_result()->fetch_assoc();
        if (!clevo_challenge_matches($row, 'reset_grant', $parts[2])) throw new ClevoSecurityException('invalid_challenge', 422);
        clevo_stmt($conn, 'UPDATE `user` SET Password = ?, MustResetPassword = 0, reset_otp = NULL, reset_otp_expiry = NULL WHERE UserID = ?', 'si', [$hash, (int) $row['UserID']]);
        clevo_stmt($conn, "UPDATE auth_challenges SET ConsumedAt = UTC_TIMESTAMP() WHERE UserID = ? AND Purpose IN ('reset_otp','reset_grant') AND ConsumedAt IS NULL", 'i', [(int) $row['UserID']]);
        clevo_revoke_user($conn, (int) $row['UserID']);
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}

function clevo_issue_verification(mysqli $conn, array $account, ?string $targetEmail = null): void
{
    $email = $targetEmail ?? $account['Email'];
    if (!filter_var($email ?? '', FILTER_VALIDATE_EMAIL)) throw new ClevoSecurityException('invalid_email', 422);
    $secret = bin2hex(random_bytes(32));
    $conn->begin_transaction();
    try {
        clevo_account($conn, (int) $account['UserID'], true);
        $id = clevo_new_challenge($conn, (int) $account['UserID'], $targetEmail ? 'email_change' : 'email', $secret, 3600, $targetEmail);
        if ($targetEmail) clevo_stmt($conn, 'UPDATE `user` SET PendingEmail = ? WHERE UserID = ?', 'si', [$targetEmail, (int) $account['UserID']]);
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
    // URL fragments never reach HTTP access logs. The landing page submits the token via POST.
    $link = clevo_public_url('Emails/verify.php#token=' . rawurlencode($id . '.' . $secret));
    clevo_send_mail($email, $account['UserName'], 'Verify your CLEVO email',
        '<p>' . e($account['UserName']) . '</p><a href="' . e($link) . '">Verify email (valid for one hour)</a>');
}

function clevo_verify_email(mysqli $conn, string $token): void
{
    if (!preg_match('/^([a-f0-9]{64})\.([a-f0-9]{64})$/D', $token, $parts)) throw new ClevoSecurityException('invalid_challenge', 422);
    $lookup = clevo_stmt($conn, 'SELECT UserID FROM auth_challenges WHERE ChallengeID = ?', 's', [$parts[1]])->get_result()->fetch_assoc();
    if (!$lookup) throw new ClevoSecurityException('invalid_challenge', 422);
    $conn->begin_transaction();
    try {
        $account = clevo_account($conn, (int) $lookup['UserID'], true);
        $row = clevo_stmt($conn, 'SELECT * FROM auth_challenges WHERE ChallengeID = ? FOR UPDATE', 's', [$parts[1]])->get_result()->fetch_assoc();
        $purpose = $row['Purpose'] ?? '';
        if (!in_array($purpose, ['email', 'email_change'], true) || !clevo_challenge_matches($row, $purpose, $parts[2]) || !$account || $account['AccountStatus'] === 'rejected') throw new ClevoSecurityException('invalid_challenge', 422);
        if ($purpose === 'email_change') {
            if (empty($row['TargetEmail']) || $row['TargetEmail'] !== $account['PendingEmail']) throw new ClevoSecurityException('invalid_challenge', 422);
            try {
                clevo_stmt($conn, 'UPDATE `user` SET Email = ?, PendingEmail = NULL WHERE UserID = ?', 'si', [$row['TargetEmail'], (int) $account['UserID']]);
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() === 1062) throw new ClevoSecurityException('email_unavailable', 409);
                throw $e;
            }
            clevo_revoke_user($conn, (int) $account['UserID']);
        }
        $status = $account['AccountStatus'];
        if (clevo_role($account['Role']) === 'customer' && $status === 'pending') $status = 'active';
        clevo_stmt($conn, 'UPDATE `user` SET EmailVerifiedAt = UTC_TIMESTAMP(), VerificationToken = NULL, AccountStatus = ? WHERE UserID = ?', 'si', [$status, (int) $account['UserID']]);
        clevo_stmt($conn, 'UPDATE auth_challenges SET ConsumedAt = UTC_TIMESTAMP() WHERE ChallengeID = ?', 's', [$parts[1]]);
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}

function clevo_register_account(mysqli $conn, array $input, ?string $document = null): array
{
    $role = (string) ($input['role'] ?? 'customer');
    $name = clevo_clean_string($input['full_name'] ?? '', 100);
    $email = strtolower(clevo_clean_string($input['email'] ?? '', 100));
    $phone = clevo_clean_string($input['phone'] ?? '', 20);
    $password = $input['password'] ?? '';
    if (!in_array($role, ['customer', 'owner'], true) || $name === '' || !is_string($input['email'] ?? null) || strlen($input['email']) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || !preg_match('/^07\d{8}$/D', $phone) || !is_string($password) || !clevo_password_valid($password)) throw new ClevoSecurityException('invalid_registration', 422);
    $station = clevo_clean_string($input['station_name'] ?? '', 100);
    if ($role === 'owner' && ($station === '' || !$document)) throw new ClevoSecurityException('owner_docs_required', 422);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $conn->begin_transaction();
    try {
        clevo_stmt($conn, "INSERT INTO `user` (UserName, Email, PhoneNumber, Password, Role, AccountStatus, VerificationFile) VALUES (?, ?, ?, ?, ?, 'pending', ?)", 'ssssss', [$name, $email, $phone, $hash, $role, $document]);
        $id = (int) $conn->insert_id;
        clevo_stmt($conn, 'INSERT INTO wallet (UserID, Balance) VALUES (?, 0)', 'i', [$id]);
        clevo_stmt($conn, 'INSERT INTO loyalty (UserID, PointsBalance) VALUES (?, 0)', 'i', [$id]);
        if ($role === 'owner') {
            clevo_stmt($conn, "INSERT INTO stations (UserID, Name, Status, admin_status, CommercialRecordPath) VALUES (?, ?, 'Closed', 'pending', ?)", 'iss', [$id, $station, $document]);
            clevo_stmt($conn, 'UPDATE `user` SET StationID = ? WHERE UserID = ?', 'ii', [(int) $conn->insert_id, $id]);
        }
        $conn->commit();
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        if ($e->getCode() === 1062) throw new ClevoSecurityException('registration_unavailable', 422);
        throw $e;
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
    $account = clevo_account($conn, $id);
    clevo_issue_verification($conn, $account);
    return $account;
}

function clevo_driver_code(mysqli $conn, string $login, string $code): ?array
{
    if (!preg_match('/^\d{6}$/D', $code)) return null;
    $account = clevo_stmt($conn, "SELECT UserID FROM `user` WHERE (Email = ? OR PhoneNumber = ?) AND LOWER(Role) = 'driver' LIMIT 1", 'ss', [$login, $login])->get_result()->fetch_assoc();
    if (!$account) return null;
    $conn->begin_transaction();
    try {
        $account = clevo_account($conn, (int) $account['UserID'], true);
        if (!clevo_account_active($conn, $account)) { $conn->rollback(); return null; }
        $jobs = clevo_stmt($conn, "SELECT b.BookingID, b.DriverAccessCodeHash FROM booking b JOIN stations s ON s.StationID = b.StationID WHERE b.DriverID = ? AND b.StationID = ? AND s.admin_status = 'approved'
            AND b.Status IN ('Accepted','Arrived','In Progress','Pickup','Washing','Returning') AND b.Service_Location = 'delivery'
            AND b.DriverAccessCodeExpiresAt > UTC_TIMESTAMP() AND b.DriverAccessCodeUsedAt IS NULL ORDER BY b.BookingID FOR UPDATE", 'ii', [(int) $account['UserID'], (int) $account['StationID']])->get_result();
        while ($job = $jobs->fetch_assoc()) {
            if (password_verify($code, $job['DriverAccessCodeHash'] ?? '')) {
                clevo_stmt($conn, 'UPDATE booking SET DriverAccessCodeUsedAt = UTC_TIMESTAMP(), DriverAccessCodeHash = NULL WHERE BookingID = ?', 'i', [(int) $job['BookingID']]);
                $conn->commit();
                return ['account' => $account, 'booking_id' => (int) $job['BookingID']];
            }
        }
        $conn->commit(); return null;
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}
