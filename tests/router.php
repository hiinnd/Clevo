<?php
// Test-only PHP development-server router. Production requires the supplied server denies.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if (preg_match('~(?:^|/)(?:\.env[^/]*|\.git|\.vscode|\.security-test|tests|vendor|scripts|migrations|docs)(?:/|$)|clevo\.sql|composer\.(?:json|lock)|^/uploads/verification/~i', $path)) {
    http_response_code(403); exit;
}
if (preg_match('~^/api/v1(?:/(.*))?$~', $path, $m) && !str_starts_with($path, '/api/v1/index.php')) {
    $_SERVER['PATH_INFO'] = '/' . ($m[1] ?? '');
    require __DIR__ . '/../api/v1/index.php'; return true;
}
return false;
