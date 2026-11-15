<?php
require_once __DIR__ . '/../includes/config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); clevo_json_response('error', [], 'method_not_allowed', 405); }
$email = clevo_clean_string($_POST['email'] ?? '', 101);
clevo_auth_throttle($conn, 'forgot', $email);
unset($_SESSION['reset_grant'], $_SESSION['reset_user_id']);
$_SESSION['reset_challenge_id'] = clevo_forgot_password($conn, $email);
clevo_json_response('success', [], 'If the account exists, a reset code will be sent.');
