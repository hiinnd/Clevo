<?php
session_start();
include_once(__DIR__ . "/../includes/config.php");
header('Content-Type: application/json');
clevoEnsurePasswordResetColumns($conn);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'status' => 'error'
    ]);
    exit;
}
clevo_require_csrf_token(true);
$otp = trim($_POST['otp'] ?? '');
if (!isset($_SESSION['reset_user_id'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Session expired'
    ]);
    exit;
}
$user_id = $_SESSION['reset_user_id'];
$stmt = $conn->prepare("
SELECT reset_otp, reset_otp_expiry
FROM user
WHERE UserID = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
if (!$user) {
    echo json_encode([
        'status' => 'error'
    ]);
    exit;
}

if (trim($otp) != trim($user['reset_otp'])){
    echo json_encode([
        'status' => 'error',
        'message' => 'Wrong OTP'
    ]);
    exit;
}

if (strtotime($user['reset_otp_expiry']) < time()) {
    echo json_encode([
        'status' => 'error',
        'message' => 'OTP expired'
    ]);
    exit;
}
echo json_encode([
    'status' => 'success'
]);
