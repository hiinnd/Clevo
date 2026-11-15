<?php
require_once __DIR__ . '/../includes/config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); clevo_json_response('error', [], 'method_not_allowed', 405); }
$challenge = (string) ($_SESSION['reset_challenge_id'] ?? '');
clevo_auth_throttle($conn, 'verify', $challenge);
$_SESSION['reset_grant'] = clevo_verify_reset_otp($conn, $challenge, clevo_clean_string($_POST['otp'] ?? '', 7));
clevo_json_response('success');
