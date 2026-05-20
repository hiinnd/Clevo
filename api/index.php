<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

include __DIR__ . '/../includes/config.php';
include __DIR__ . '/../includes/booking-helpers.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') {
    exit;
}

$request = explode('/', trim($_SERVER['PATH_INFO'] ?? '', '/'));
$resource = $request[0] ?? '';

switch ($resource) {
    case 'stations':
        if ($method == 'GET') {
            $stmt = $conn->prepare("SELECT StationID, Name, Lat, Lon, Location FROM stations WHERE admin_status = 'approved'");
            $stmt->execute();
            $result = $stmt->get_result();
            $stations = $result->fetch_all(MYSQLI_ASSOC);
            clevo_json_response('success', ['stations' => $stations], 'ok');
        }
        break;
    case 'bookings':
        if ($method == 'GET') {
            $payload = clevo_current_jwt_payload();
            $userID = (int) ($payload['userID'] ?? clevo_current_user_id());
            if ($userID <= 0) {
                clevo_json_response('error', [], 'unauthorized', 401);
            }
            cancelExpiredBookings($conn, $userID);
            $stmt = $conn->prepare("SELECT * FROM booking WHERE UserID = ?");
            $stmt->bind_param('i', $userID);
            $stmt->execute();
            $result = $stmt->get_result();
            $bookings = $result->fetch_all(MYSQLI_ASSOC);
            clevo_json_response('success', ['bookings' => $bookings], 'ok');
        }
        break;
    default:
        clevo_json_response('error', [], 'resource_not_found', 404);
}
?>
