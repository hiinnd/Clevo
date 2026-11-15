<?php
declare(strict_types=1);

final class ClevoSecurityException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $httpStatus = 400)
    {
        parent::__construct($reason);
    }
}

function clevo_json_response(string $status, array $data = [], string $message = '', int $httpCode = 200): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['status' => $status, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function clevo_js($value): string
{
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
}

function clevo_clean_string($value, int $maxLength = 255): string
{
    if (!is_scalar($value) && $value !== null) throw new ClevoSecurityException('invalid_input', 422);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', trim((string) $value));
    if ($text === null) throw new ClevoSecurityException('invalid_input', 422);
    return mb_substr($text, 0, $maxLength, 'UTF-8');
}

function clevo_post_int(string $key, int $default = 0): int
{
    return filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT) ?: $default;
}
function clevo_get_int(string $key, int $default = 0): int
{
    return filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT) ?: $default;
}

function clevo_start_session(): void
{
    if (PHP_SAPI === 'cli' || (defined('CLEVO_API') && CLEVO_API)) return;
    if (session_status() !== PHP_SESSION_NONE) throw new RuntimeException('Session started before security bootstrap.');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_name(clevo_production() ? '__Host-CLEVOSESSION' : 'CLEVOSESSION');
    $directory = rtrim(clevo_env('PRIVATE_STORAGE_PATH', '') ?? '', '/\\') . '/sessions';
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException('Session storage unavailable.');
    session_save_path($directory);
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => clevo_production() || clevo_https(),
        'httponly' => true, 'samesite' => 'Lax']);
    if (!@session_start()) throw new RuntimeException('Session storage unavailable.');
    $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
}

function generate_csrf_token(): string
{
    return $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
}
function validate_csrf_token($token): bool
{
    return is_string($token) && $token !== '' && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(generate_csrf_token()) . '">';
}
function clevo_require_csrf_token(bool $json = false): void
{
    if (!validate_csrf_token($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        throw new ClevoSecurityException('csrf_failed', 403);
    }
}

function clevo_read_json(): array
{
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        throw new ClevoSecurityException('content_type_required', 415);
    }
    $raw = file_get_contents('php://input', false, null, 0, 16385);
    if ($raw === false || strlen($raw) > 16384) throw new ClevoSecurityException('payload_too_large', 413);
    try {
        $value = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) { throw new ClevoSecurityException('invalid_json', 400); }
    if (!is_array($value) || !str_starts_with(ltrim($raw), '{')) throw new ClevoSecurityException('invalid_json', 400);
    return $value;
}

function clevo_allowed_origin(string $origin): bool
{
    return $origin !== '' && in_array($origin, array_filter(array_map('trim', explode(',', clevo_env('CORS_ALLOWED_ORIGINS', '') ?? ''))), true);
}
function clevo_cors(): void
{
    header('Vary: Origin');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        if (!clevo_allowed_origin($origin)) throw new ClevoSecurityException('origin_not_allowed', 403);
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key');
        // Bearer-only API; no credentialed cookie CORS and no wildcard origins.
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function clevo_rate_limit(mysqli $conn, string $scope, string $identity, int $limit, int $window): void
{
    $now = time();
    $bucket = intdiv($now, $window) * $window;
    $key = hash_hmac('sha256', $scope . "\0" . $identity, clevo_secret('APP_KEY'));
    $expires = $bucket + $window;
    $stmt = $conn->prepare('INSERT INTO security_rate_limits (BucketKey, WindowStart, Hits, ExpiresAt) VALUES (?, ?, 1, ?)
        ON DUPLICATE KEY UPDATE Hits = Hits + 1');
    $stmt->bind_param('sii', $key, $bucket, $expires);
    $stmt->execute();
    $stmt = $conn->prepare('SELECT Hits FROM security_rate_limits WHERE BucketKey = ? AND WindowStart = ?');
    $stmt->bind_param('si', $key, $bucket);
    $stmt->execute();
    if ((int) $stmt->get_result()->fetch_assoc()['Hits'] > $limit) {
        header('Retry-After: ' . max(1, $expires - $now));
        throw new ClevoSecurityException('rate_limited', 429);
    }
}

function clevo_auth_throttle(mysqli $conn, string $scope, string $identifier = ''): void
{
    $rules = ['login' => [30, 10, 900], 'register' => [10, 5, 3600],
        'forgot' => [15, 5, 3600], 'verify' => [30, 8, 900], 'refresh' => [120, 60, 60]];
    [$ipLimit, $accountLimit, $window] = $rules[$scope] ?? [60, 30, 60];
    clevo_rate_limit($conn, $scope . ':ip', $_SERVER['REMOTE_ADDR'] ?? 'cli', $ipLimit, $window);
    if ($identifier !== '') clevo_rate_limit($conn, $scope . ':account', strtolower(trim($identifier)), $accountLimit, $window);
}
