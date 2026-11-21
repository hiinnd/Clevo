<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

function clevo_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value !== false ? $value : (isset($_ENV[$key]) ? (string) $_ENV[$key] : $default);
}

function clevo_load_environment(): void
{
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;
    $file = getenv('CLEVO_ENV_FILE');
    $file = $file !== false && $file !== '' ? $file : dirname(__DIR__, 2) . '/.env';
    if (is_file($file)) {
        Dotenv\Dotenv::createImmutable(dirname($file), basename($file))->load();
    }
}

function clevo_production(): bool
{
    return clevo_env('APP_ENV', 'production') === 'production';
}

function clevo_secure_environment(): bool
{
    return in_array(clevo_env('APP_ENV', 'production'), ['production', 'staging'], true);
}

function clevo_secret(string $name): string
{
    $value = clevo_env($name, '') ?? '';
    if (str_starts_with($value, 'base64:')) {
        $value = base64_decode(substr($value, 7), true) ?: '';
    }
    if (strlen($value) < 32 || preg_match('/change.?me|replace.?me|example/i', $value)) {
        throw new RuntimeException('Required security configuration is missing.');
    }
    return $value;
}

/** Backup/restore identities do not need JWT, SMTP or application encryption secrets. */
function clevo_validate_database_environment():void
{
    if(!in_array(clevo_env('APP_ENV','production'),['production','staging','testing','development'],true))throw new RuntimeException('Invalid environment.');
    foreach(['DB_HOST','DB_DATABASE','DB_USERNAME','PRIVATE_STORAGE_PATH'] as $name)if(trim(clevo_env($name,'')??'')==='')throw new RuntimeException('Required database configuration missing.');
    if(clevo_secure_environment()){
        if(strtolower(clevo_env('DB_USERNAME','')??'')==='root'||clevo_env('DB_PASSWORD','')==='')throw new RuntimeException('Restricted database identity required.');
        $private=realpath(clevo_env('PRIVATE_STORAGE_PATH','')??'');$web=realpath($_SERVER['DOCUMENT_ROOT']??dirname(__DIR__,2));
        if(!$private||($web&&clevo_path_within($private,$web)))throw new RuntimeException('Private storage must be outside the web root.');
        if(!in_array(clevo_env('DB_HOST'),['127.0.0.1','localhost','::1'],true)&&trim(clevo_env('DB_TLS_CA_FILE','')??'')==='')throw new RuntimeException('Remote database TLS verification required.');
    }
}

function clevo_validate_environment(): void
{
    clevo_validate_database_environment();
    if (!in_array(clevo_env('APP_ENV', 'production'), ['production', 'staging', 'development', 'testing'], true)) {
        throw new RuntimeException('Invalid environment.');
    }
    clevo_secret('APP_KEY');
    clevo_secret('JWT_SECRET');
    if (hash_equals(clevo_secret('APP_KEY'), clevo_secret('JWT_SECRET'))) {
        throw new RuntimeException('Independent security keys are required.');
    }
    foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'APP_URL', 'PRIVATE_STORAGE_PATH'] as $name) {
        if (trim(clevo_env($name, '') ?? '') === '') throw new RuntimeException('Required configuration is missing.');
    }
    $url = parse_url(clevo_env('APP_URL', '') ?? '');
    if (!is_array($url) || empty($url['host']) || !in_array($url['scheme'] ?? '', ['http', 'https'], true)
        || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
        throw new RuntimeException('Invalid application origin.');
    }
    foreach (array_filter(array_map('trim', explode(',', clevo_env('CORS_ALLOWED_ORIGINS', '') ?? ''))) as $origin) {
        $parts = parse_url($origin);
        if (!is_array($parts) || empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)
            || str_contains($origin, '*') || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
            || isset($parts['fragment']) || isset($parts['path'])) throw new RuntimeException('CORS requires exact origins.');
        if (clevo_secure_environment() && $parts['scheme'] !== 'https') throw new RuntimeException('HTTPS origins required.');
    }
    if (clevo_secure_environment()) {
        if ($url['scheme'] !== 'https' || clevo_env('DB_PASSWORD', '') === ''
            || strtolower(clevo_env('DB_USERNAME', '') ?? '') === 'root') {
            throw new RuntimeException('Unsafe production configuration.');
        }
        if (clevo_production() || clevo_env('NOTIFICATION_EXTERNAL_SEND','0') === '1') {
            if (clevo_env('MAIL_TRANSPORT','smtp') !== 'smtp') throw new RuntimeException('SMTP required.');
            foreach (['SMTP_HOST', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'MAIL_FROM_ADDRESS'] as $name) {
                if (trim(clevo_env($name, '') ?? '') === '') throw new RuntimeException('Required mail configuration is missing.');
            }
            if (!clevo_production() && trim(clevo_env('STAGING_EMAIL_ALLOWLIST','')??'')==='') throw new RuntimeException('Staging recipients required.');
        }
        $private = realpath(clevo_env('PRIVATE_STORAGE_PATH', '') ?? '');
        $docroot = realpath($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2));
        if (!$private || ($docroot && clevo_path_within($private, $docroot))) {
            throw new RuntimeException('Private storage must exist outside the document root.');
        }
    }
}

function clevo_path_within(string $path, string $root): bool
{
    $path = strtolower(str_replace('\\', '/', $path));
    $root = rtrim(strtolower(str_replace('\\', '/', $root)), '/');
    return $path === $root || str_starts_with($path, $root . '/');
}

function clevo_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    $trusted = array_filter(array_map('trim', explode(',', clevo_env('TRUSTED_PROXIES', '') ?? '')));
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', $trusted, true)
        && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function clevo_connect_database(): mysqli
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = mysqli_init();
    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    $flags=0;$ca=clevo_env('DB_TLS_CA_FILE','')??'';
    if($ca!==''){
        $ca=realpath($ca)?:'';
        if(!$ca||!is_file($ca)||!is_readable($ca))throw new RuntimeException('Database CA unavailable.');
        $conn->ssl_set(null,null,$ca,null,null);
        $conn->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT,true);$flags=MYSQLI_CLIENT_SSL;
    }elseif(clevo_secure_environment()&&!in_array(clevo_env('DB_HOST'),['127.0.0.1','localhost','::1'],true))throw new RuntimeException('Remote database TLS required.');
    $conn->real_connect(clevo_env('DB_HOST'), clevo_env('DB_USERNAME'), clevo_env('DB_PASSWORD', ''),
        clevo_env('DB_DATABASE'), (int) clevo_env('DB_PORT', '3306'),null,$flags);
    if($flags&&!($conn->query("SHOW STATUS LIKE 'Ssl_cipher'")->fetch_assoc()['Value']??''))throw new RuntimeException('Database connection was not encrypted.');
    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '+00:00'");
    // Do not let a permissive server silently truncate financial amounts or identifiers.
    // Keep deployment-specific modes (including ONLY_FULL_GROUP_BY) intact.
    $conn->query("SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'STRICT_TRANS_TABLES')");
    return $conn;
}

function clevo_public_url(string $path): string
{
    return rtrim(clevo_env('APP_URL', '') ?? '', '/') . '/' . ltrim($path, '/');
}
