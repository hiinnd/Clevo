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

$stmt = $conn->prepare("SELECT b.Status, b.Latitude, b.Longitude, b.Driver_Lat, b.Driver_Lon
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

$lat = $order['Driver_Lat'] ?? $order['Latitude'] ?? 31.985;
$lng = $order['Driver_Lon'] ?? $order['Longitude'] ?? 35.898;

clevo_json_response('success', [
    'lat' => (float) $lat,
    'lng' => (float) $lng,
    'orderStatus' => $order['Status'],
    'hasArrived' => in_array($order['Status'], ['Arrived', 'Pickup', 'Washing', 'Returning', 'Completed'], true),
], 'ok');
?>
