<?php
session_start();

include_once(__DIR__ . "/../includes/config.php");

require_once '../vendor/autoload.php';

include(__DIR__ . "/../Emails/reset_otp.php");

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    echo json_encode([
        'status' => 'error'
    ]);

    exit;
}

clevo_require_csrf_token(true);

$email = trim($_POST['email'] ?? '');

if (empty($email)) {

    echo json_encode([
        'status' => 'error',
        'message' => 'Email required'
    ]);

    exit;
}


$stmt = $conn->prepare("
SELECT UserID, UserName, Email
FROM user
WHERE Email = ?
");

$stmt->bind_param("s", $email);

$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {

    echo json_encode([
        'status' => 'error',
        'message' => 'User not found'
    ]);

    exit;
}

$user = $result->fetch_assoc();


$otp = rand(100000, 999999);

$expiry = date("Y-m-d H:i:s", strtotime("+5 minutes"));


$update = $conn->prepare("
UPDATE user
SET reset_otp = ?, reset_otp_expiry = ?
WHERE UserID = ?
");

$update->bind_param("ssi", $otp, $expiry, $user['UserID']);

$update->execute();


$_SESSION['reset_user_id'] = $user['UserID'];


$sent = sendResetOTP(
    $user['Email'],
    $user['UserName'],
    $otp
);

if ($sent) {

    echo json_encode([
        'status' => 'success'
    ]);
} else {

    echo json_encode([
        'status' => 'error',
        'message' => 'Email failed'
    ]);
}
