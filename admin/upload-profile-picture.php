<?php

include_once __DIR__ . '/../includes/config.php';
include_once __DIR__ . '/../includes/app-helpers.php';

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../general/login.php");
    exit();
}

clevo_require_csrf_token();

$admin_id = (int) ($_SESSION['userID'] ?? 0);
$candidate = basename(parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH) ?: '');
$redirect = preg_match('/^[a-z-]+\.php$/D', $candidate) && is_file(__DIR__ . '/' . $candidate) ? $candidate : 'admin-dashboard.php';
$separator = str_contains($redirect, '?') ? '&' : '?';

if (!isset($_FILES['admin_pic_upload'])) {
    header("Location: $redirect{$separator}upload=missing");
    exit();
}

$newName = clevoSaveUploadedProfileImage($_FILES['admin_pic_upload'], 'admin', $admin_id);
if (!$newName) {
    header("Location: $redirect{$separator}upload=failed");
    exit();
}

$stmt = $conn->prepare("UPDATE `user` SET ProfilePic = ? WHERE UserID = ? AND Role = 'Admin'");
$stmt->bind_param("si", $newName, $admin_id);

if ($stmt->execute()) {
    header("Location: $redirect{$separator}upload=success");
    exit();
}

header("Location: $redirect{$separator}upload=db_failed");
exit();
