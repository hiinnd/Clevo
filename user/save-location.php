<?php

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


if ($mapsUrl === '') {
    $mapsUrl = 'https://www.google.com/maps?q=' . $latitude . ',' . $longitude;
}

$stmt = $conn->prepare("UPDATE `user`
    SET LastLatitude = ?, LastLongitude = ?, LastAddress = ?, LastLocationUrl = ?, LastLocationUpdatedAt = NOW()
    WHERE UserID = ?");
$stmt->bind_param("ddssi", $latitude, $longitude, $address, $mapsUrl, $userID);

echo json_encode(['status' => $stmt->execute() ? 'success' : 'error']);
