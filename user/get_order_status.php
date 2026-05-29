<?php
session_start();
include(__DIR__ . "/../includes/config.php");
include(__DIR__ . "/../includes/app-helpers.php");

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['userID'])) {
    clevo_json_response('error', [], 'unauthorized', 401);
}

clevoEnsureTrackingColumns($conn);

$bookingID = (int) ($_GET['id'] ?? 0);
$userID = (int) $_SESSION['userID'];
$role = strtolower($_SESSION['role'] ?? $_SESSION['Role'] ?? '');

$stmt = $conn->prepare("SELECT b.Status, b.Latitude, b.Longitude, b.Driver_Lat, b.Driver_Lon,
        b.Service_Location, s.Name AS StationName, s.Location AS StationLocation, s.Lat AS StationLat, s.Lon AS StationLon
    FROM booking b
    JOIN stations s ON b.StationID = s.StationID
    WHERE b.BookingID = ?
      AND (b.UserID = ? OR s.UserID = ? OR b.DriverID = ?)
    LIMIT 1");
$stmt->bind_param("iiii", $bookingID, $userID, $userID, $userID);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    clevo_json_response('error', [], 'not_found', 404);
}

$customerLat = $order['Latitude'] ?? null;
$customerLng = $order['Longitude'] ?? null;
$stationLat = $order['StationLat'] ?? null;
$stationLng = $order['StationLon'] ?? null;
$fallbackLat = $customerLat ?? $stationLat ?? 31.985;
$fallbackLng = $customerLng ?? $stationLng ?? 35.898;
$lat = $order['Driver_Lat'] ?? $fallbackLat;
$lng = $order['Driver_Lon'] ?? $fallbackLng;

clevo_json_response('success', [
    'lat' => (float) $lat,
    'lng' => (float) $lng,
    'driver' => [
        'lat' => (float) $lat,
        'lng' => (float) $lng,
    ],
    'customer' => [
        'lat' => $customerLat !== null ? (float) $customerLat : null,
        'lng' => $customerLng !== null ? (float) $customerLng : null,
    ],
    'station' => [
        'name' => (string) ($order['StationName'] ?? ''),
        'location' => (string) ($order['StationLocation'] ?? ''),
        'lat' => $stationLat !== null ? (float) $stationLat : null,
        'lng' => $stationLng !== null ? (float) $stationLng : null,
    ],
    'serviceLocation' => $order['Service_Location'],
    'orderStatus' => $order['Status'],
    'hasArrived' => in_array($order['Status'], ['Arrived', 'Pickup', 'Washing', 'Returning', 'Completed'], true),
], 'ok');
?>
