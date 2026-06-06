<?php
session_start();
include_once __DIR__ . '/../includes/config.php';

$userID = clevo_require_role(['customer', 'user'], '../general/login.php');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'method_not_allowed']);
    exit;
}

$latitude = filter_input(INPUT_POST, 'latitude', FILTER_VALIDATE_FLOAT);
$longitude = filter_input(INPUT_POST, 'longitude', FILTER_VALIDATE_FLOAT);
$address = clevo_clean_string($_POST['address'] ?? '', 500);
$mapsUrl = clevo_clean_string($_POST['google_maps_url'] ?? '', 255);

if ($latitude === false || $latitude === null || $longitude === false || $longitude === null || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
    echo json_encode(['status' => 'error', 'message' => 'invalid_location']);
    exit;
}

$conn->query("ALTER TABLE `user` ADD COLUMN IF NOT EXISTS `LastLatitude` DECIMAL(10,8) DEFAULT NULL");
$conn->query("ALTER TABLE `user` ADD COLUMN IF NOT EXISTS `LastLongitude` DECIMAL(11,8) DEFAULT NULL");
$conn->query("ALTER TABLE `user` ADD COLUMN IF NOT EXISTS `LastAddress` TEXT DEFAULT NULL");
$conn->query("ALTER TABLE `user` ADD COLUMN IF NOT EXISTS `LastLocationUrl` VARCHAR(255) DEFAULT NULL");
$conn->query("ALTER TABLE `user` ADD COLUMN IF NOT EXISTS `LastLocationUpdatedAt` TIMESTAMP NULL DEFAULT NULL");

if ($mapsUrl === '') {
    $mapsUrl = 'https://www.google.com/maps?q=' . $latitude . ',' . $longitude;
}

$stmt = $conn->prepare("UPDATE `user`
    SET LastLatitude = ?, LastLongitude = ?, LastAddress = ?, LastLocationUrl = ?, LastLocationUpdatedAt = NOW()
    WHERE UserID = ?");
$stmt->bind_param("ddssi", $latitude, $longitude, $address, $mapsUrl, $userID);

echo json_encode(['status' => $stmt->execute() ? 'success' : 'error']);
