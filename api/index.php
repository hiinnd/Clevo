<?php
// Legacy read routes retain their URLs and response envelope; mobile credentials are bearer-only.
define('CLEVO_API', true);
require_once __DIR__ . '/../includes/config.php';
$route = trim($_SERVER['PATH_INFO'] ?? '', '/');
if (!in_array($route, ['stations','bookings'], true)) clevo_json_response('error', [], 'resource_not_found', 404);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET'); clevo_json_response('error', [], 'method_not_allowed', 405); }
clevo_rate_limit($conn, 'legacy-api:ip', $_SERVER['REMOTE_ADDR'] ?? '', 120, 60);
if ($route === 'stations') {
    $rows = $conn->query("SELECT StationID, Name, Lat, Lon, Location FROM stations WHERE admin_status = 'approved' ORDER BY StationID LIMIT 100")->fetch_all(MYSQLI_ASSOC);
    clevo_json_response('success', ['stations' => $rows], 'ok');
}
$identity = clevo_authenticated_token($conn, clevo_bearer_token());
if (!$identity) clevo_json_response('error', [], 'unauthorized', 401);
if (clevo_role($identity['account']['Role']) !== 'customer') throw new ClevoSecurityException('forbidden', 403);
$rows = clevo_stmt($conn, 'SELECT BookingID, Order_Number, StationID, Service_Location, Booking_Date, Booking_Time, Status, Total_Price, Payable_Amount FROM booking WHERE UserID = ? ORDER BY BookingID DESC LIMIT 50', 'i', [(int) $identity['account']['UserID']])->get_result()->fetch_all(MYSQLI_ASSOC);
clevo_json_response('success', ['bookings' => $rows], 'ok');
