<?php

include(__DIR__ . "/../includes/config.php");

$token = trim((string) ($_GET['token'] ?? ''));

if ($token === '') {
    echo "No token found.";
    exit();
}

$stmt = $conn->prepare("SELECT UserID, AccountStatus FROM `user` WHERE VerificationToken = ? LIMIT 1");
$stmt->bind_param("s", $token);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user || $user['AccountStatus'] === 'rejected') {
    echo "Verification failed or this account is already verified.";
    exit();
}

$user_id = (int) $user['UserID'];
$update = $conn->prepare("UPDATE `user` SET AccountStatus = 'active', VerificationToken = NULL WHERE UserID = ?");
$update->bind_param("i", $user_id);
$update->execute();

header("Location: verify_success.php");
exit();
?>
