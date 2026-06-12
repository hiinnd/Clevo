<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$host = "localhost";     
$user = "root";          
$password = "";          
$database = "clevo";     

$conn = new mysqli($host, $user, $password, $database);


if ($conn->connect_error) {
    die("فشل الاتصال: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");


$jwt_secret = getenv('CLEVO_JWT_SECRET') ?: 'CLEVO_SECRET_KEY_CHANGE_IN_PRODUCTION';


if (!isset($_SESSION['userID']) && isset($_SESSION['user_id'])) {
    $_SESSION['userID'] = $_SESSION['user_id'];
}
if (!isset($_SESSION['userName']) && isset($_SESSION['user_name'])) {
    $_SESSION['userName'] = $_SESSION['user_name'];
}
if (!isset($_SESSION['role']) && isset($_SESSION['user_role'])) {
    $_SESSION['role'] = $_SESSION['user_role'];
}
if (isset($_SESSION['role'])) {
    $clevo_role = strtolower((string) $_SESSION['role']);
    if ($clevo_role === 'admin') {
        $_SESSION['role'] = 'Admin';
    } elseif (in_array($clevo_role, ['owner', 'driver', 'customer', 'user'], true)) {
        $_SESSION['role'] = $clevo_role;
    }
}


function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_input(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generate_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function clevo_json_response(string $status, array $data = [], string $message = '', int $httpCode = 200): void {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => $status,
        'data' => $data,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function clevo_require_csrf_token(bool $json = false): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validate_csrf_token((string) $token)) {
        if ($json) {
            clevo_json_response('error', [], 'csrf_failed', 403);
        }
        http_response_code(403);
        die('CSRF token validation failed.');
    }
}

function clevoEnsurePasswordResetColumns(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $db = $conn->query("SELECT DATABASE() AS db")->fetch_assoc()['db'] ?? '';
    if ($db === '') {
        return;
    }

    $columns = [];
    $stmt = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'user'");
    $stmt->bind_param("s", $db);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $columns[$row['COLUMN_NAME']] = true;
    }

    if (!isset($columns['reset_otp'])) {
        $conn->query("ALTER TABLE `user` ADD COLUMN reset_otp VARCHAR(10) DEFAULT NULL AFTER CurrentAccessCode");
    }
    if (!isset($columns['reset_otp_expiry'])) {
        $conn->query("ALTER TABLE `user` ADD COLUMN reset_otp_expiry DATETIME DEFAULT NULL AFTER reset_otp");
    }
}

function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function clevo_current_user_id(): int {
    return (int) ($_SESSION['userID'] ?? 0);
}

function clevo_current_role(): string {
    return strtolower((string) ($_SESSION['role'] ?? ''));
}

function clevo_require_login(string $redirect = '../general/login.php'): int {
    $userID = clevo_current_user_id();
    if ($userID <= 0) {
        header("Location: $redirect");
        exit;
    }
    return $userID;
}

function clevo_require_role($roles, string $redirect = '../general/login.php'): int {
    $userID = clevo_require_login($redirect);
    $roles = array_map('strtolower', (array) $roles);
    if (!in_array(clevo_current_role(), $roles, true)) {
        header("Location: $redirect");
        exit;
    }
    return $userID;
}

function clevo_post_int(string $key, int $default = 0): int {
    return filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT) ?: $default;
}

function clevo_get_int(string $key, int $default = 0): int {
    return filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT) ?: $default;
}

function clevo_clean_string($value, int $maxLength = 255): string {
    $value = trim((string) $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength, 'UTF-8') : substr($value, 0, $maxLength);
}

function clevo_base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function clevo_base64url_decode(string $data): string {
    $padding = strlen($data) % 4;
    if ($padding) {
        $data .= str_repeat('=', 4 - $padding);
    }
    return base64_decode(strtr($data, '-_', '+/')) ?: '';
}

function clevo_generate_jwt(int $userID, string $role, int $ttl = 86400): string {
    global $jwt_secret;
    $header = ['typ' => 'JWT', 'alg' => 'HS256'];
    $payload = [
        'iss' => 'clevo',
        'sub' => (string) $userID,
        'userID' => $userID,
        'role' => $role,
        'iat' => time(),
        'exp' => time() + $ttl
    ];
    $head = clevo_base64url_encode(json_encode($header));
    $body = clevo_base64url_encode(json_encode($payload));
    $signature = clevo_base64url_encode(hash_hmac('sha256', "$head.$body", $jwt_secret, true));
    return "$head.$body.$signature";
}

function clevo_validate_jwt(string $token): ?array {
    global $jwt_secret;
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$head, $body, $signature] = $parts;
    $expected = clevo_base64url_encode(hash_hmac('sha256', "$head.$body", $jwt_secret, true));
    if (!hash_equals($expected, $signature)) {
        return null;
    }

    $payload = json_decode(clevo_base64url_decode($body), true);
    if (!is_array($payload) || (int) ($payload['exp'] ?? 0) < time()) {
        return null;
    }

    return $payload;
}

function clevo_bearer_token(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($header, 'Bearer ') === 0) {
        return trim(substr($header, 7));
    }
    return (string) ($_COOKIE['access_token'] ?? $_SESSION['jwt_token'] ?? '');
}

function clevo_current_jwt_payload(): ?array {
    $token = clevo_bearer_token();
    return $token !== '' ? clevo_validate_jwt($token) : null;
}
?>
