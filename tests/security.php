<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
putenv('APP_ENV=testing');
putenv('APP_KEY=base64:' . base64_encode(random_bytes(32)));
putenv('JWT_SECRET=base64:' . base64_encode(random_bytes(64)));
putenv('JWT_ISSUER=clevo'); putenv('JWT_AUDIENCE=clevo-mobile');
putenv('APP_URL=http://localhost/ClevoProject'); putenv('DB_HOST=127.0.0.1');
putenv('DB_DATABASE=unit_test'); putenv('DB_USERNAME=unit_test');
$tmp = dirname(__DIR__) . '/.security-test/unit';
if (!is_dir($tmp)) mkdir($tmp, 0700, true);
putenv('PRIVATE_STORAGE_PATH=' . $tmp);

check(clevo_role('Admin') === 'admin' && clevo_role('User') === 'customer', 'Legacy role aliases normalize');
check(clevo_role('owner') === 'owner' && clevo_role('driver') === 'driver', 'All four roles retained');
check(clevo_role('administrator') === '', 'Unknown role is denied');
$password = 'Aa!' . bin2hex(random_bytes(12));
check(clevo_password_valid($password), 'Strong password accepted');
check(!clevo_password_valid('short') && !clevo_password_valid(str_repeat('Aa!1', 19)), 'Short and bcrypt-truncated passwords denied');
$hash = password_hash($password, PASSWORD_DEFAULT);
check(password_verify($password, $hash) && !password_verify($hash, $hash), 'Modern hashing and no pass-the-hash fallback');

$account = ['UserID' => 7, 'AuthVersion' => 3, 'Role' => 'customer'];
$session = ['SessionID' => bin2hex(random_bytes(32)), 'ExpiresAt' => gmdate('Y-m-d H:i:s', time() + 3600)];
$token = clevo_access_token($account, $session);
$payload = clevo_validate_jwt($token);
check($payload !== null && $payload['sub'] === '7', 'Valid JWT accepted');
check(clevo_validate_jwt($token . 'x') === null, 'Tampered JWT rejected');
check(clevo_validate_jwt('') === null && clevo_validate_jwt('x.y.z') === null, 'Malformed JWT rejected');
$sign = static fn(array $claims): string => Firebase\JWT\JWT::encode($claims, clevo_secret('JWT_SECRET'), 'HS256');
foreach (['iss' => 'foreign', 'aud' => 'foreign', 'type' => 'refresh', 'role' => 'unknown', 'sub' => '0', 'userID' => 8, 'sid' => 'bad', 'jti' => 'bad', 'ver' => 0] as $key => $value) {
    $bad = $payload; $bad[$key] = $value;
    check(clevo_validate_jwt($sign($bad)) === null, 'JWT rejects invalid ' . $key);
}
foreach (['exp', 'iat', 'nbf'] as $key) {
    $bad = $payload; $bad[$key] = $key === 'exp' ? time() - 1 : time() + 60;
    check(clevo_validate_jwt($sign($bad)) === null, 'JWT rejects invalid time ' . $key);
}
$bad = $payload; unset($bad['exp']);
check(clevo_validate_jwt($sign($bad)) === null, 'JWT requires expiry');
$parts = explode('.', $token);
$none = rtrim(strtr(base64_encode('{"typ":"JWT","alg":"none"}'), '+/', '-_'), '=') . '.' . $parts[1] . '.';
check(clevo_validate_jwt($none) === null, 'Unsigned JWT rejected');
$badHeader = Firebase\JWT\JWT::encode($payload, clevo_secret('JWT_SECRET'), 'HS384');
check(clevo_validate_jwt($badHeader) === null, 'Wrong algorithm rejected');

$_SESSION = ['csrf_token' => bin2hex(random_bytes(32))];
check(validate_csrf_token($_SESSION['csrf_token']) && !validate_csrf_token([]) && !validate_csrf_token(''), 'CSRF constant-time/type/empty checks');
$_POST = []; $_SERVER['HTTP_X_CSRF_TOKEN'] = 'invalid';
rejects(fn() => clevo_require_csrf_token(true), 'Missing CSRF denied', 'csrf_failed');
$_SERVER['HTTP_X_CSRF_TOKEN'] = $_SESSION['csrf_token'];
clevo_require_csrf_token(true); check(true, 'Header CSRF accepted');
check(e('<script>"&') === '&lt;script&gt;&quot;&amp;', 'HTML escaping');
check(!str_contains(clevo_js('</script>'), '</script>'), 'Script-context escaping');
rejects(fn() => clevo_clean_string(['injection']), 'Array input rejected');

putenv('CORS_ALLOWED_ORIGINS=https://app.example.test,https://admin.example.test');
check(clevo_allowed_origin('https://app.example.test'), 'Exact CORS origin accepted');
check(!clevo_allowed_origin('https://app.example.test.attacker.test') && !clevo_allowed_origin('null') && !clevo_allowed_origin(''), 'CORS rejects unapproved origins');
putenv('CORS_ALLOWED_ORIGINS=*');
rejects(fn() => clevo_validate_environment(), 'Wildcard CORS configuration rejected');
putenv('CORS_ALLOWED_ORIGINS=https://app.example.test');
$_SERVER['REMOTE_ADDR'] = '127.0.0.2'; $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
putenv('TRUSTED_PROXIES=127.0.0.1');
check(!clevo_https(), 'Untrusted forwarded HTTPS ignored');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1'; check(clevo_https(), 'Trusted forwarded HTTPS accepted');
clevo_validate_environment(); check(true, 'Explicit testing configuration validates');
$saved = getenv('JWT_SECRET'); putenv('JWT_SECRET=');
rejects(fn() => clevo_validate_environment(), 'Missing signing secret fails safely'); putenv('JWT_SECRET=' . $saved);
putenv('APP_ENV=production');
rejects(fn() => clevo_validate_environment(), 'Insecure production configuration fails safely'); putenv('APP_ENV=testing');
check(!clevo_path_within('C:/private', 'C:/public') && clevo_path_within('C:/public/secret', 'C:/public'), 'Private path boundary');

$cipher = clevo_encrypt_mail(['to' => 'test@example.test', 'html' => '<p>private body</p>']);
check(!str_contains($cipher, 'private body') && clevo_decrypt_mail($cipher)['html'] === '<p>private body</p>', 'Mail payload encrypted/authenticated at rest');
$raw = base64_decode($cipher); $raw[30] = chr(ord($raw[30]) ^ 1);
rejects(fn() => clevo_decrypt_mail(base64_encode($raw)), 'Mail ciphertext tampering rejected');

$png = $tmp . '/image.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9a0AAAAASUVORK5CYII='));
$upload = ['name' => 'image.png', 'tmp_name' => $png, 'error' => UPLOAD_ERR_OK, 'type' => 'application/octet-stream'];
check(clevo_inspect_upload($upload)['mime'] === 'image/png', 'Real MIME inspected instead of client MIME');
$bad = $upload; $bad['name'] = 'image.php';
rejects(fn() => clevo_inspect_upload($bad), 'Executable extension denied despite image MIME');
$bad = $upload; $bad['error'] = UPLOAD_ERR_PARTIAL;
rejects(fn() => clevo_inspect_upload($bad), 'Partial upload rejected');
$polyglot = $tmp . '/polyglot.png'; file_put_contents($polyglot, file_get_contents($png) . '<?php echo 1;');
$bad = $upload; $bad['tmp_name'] = $polyglot;
rejects(fn() => clevo_inspect_upload($bad), 'PHP image polyglot rejected');
rejects(fn() => clevo_save_upload($upload, 'profiles'), 'Non-HTTP local file cannot be moved as upload');
rejects(fn() => clevo_inspect_upload($upload, false, 1), 'Actual upload bytes enforce size limit');
unlink($png); unlink($polyglot);
finish_tests('Security unit suite');
