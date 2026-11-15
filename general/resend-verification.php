<?php
require_once __DIR__ . '/../includes/config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); clevo_json_response('error', [], 'method_not_allowed', 405); }
$email = clevo_clean_string($_POST['email'] ?? '', 101);
clevo_auth_throttle($conn, 'forgot', $email);
clevo_resend_verification($conn, $email);
clevo_json_response('success', [], 'If verification is required, an email will be sent.');
