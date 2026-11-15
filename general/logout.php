<?php
require_once __DIR__ . '/../includes/config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); http_response_code(405); exit('Logout requires POST.'); }
if (!empty($_SESSION['auth_session_id'])) clevo_revoke_session($conn, (string) $_SESSION['auth_session_id']);
$_SESSION = [];
setcookie(session_name(), '', ['expires' => time()-3600, 'path' => '/', 'secure' => clevo_production() || clevo_https(), 'httponly' => true, 'samesite' => 'Lax']);
setcookie('access_token', '', ['expires' => time()-3600, 'path' => '/', 'secure' => clevo_production() || clevo_https(), 'httponly' => true, 'samesite' => 'Lax']);
session_destroy(); header('Location: login.php'); exit;
