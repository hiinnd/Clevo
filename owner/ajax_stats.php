<?php
session_start();
include_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

// Require owner role; this will redirect to login if not authenticated.
$owner_id = clevo_require_role('owner');
if (!$owner_id) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$active_stmt = $conn->prepare("SELECT COUNT(*) AS total
    FROM booking b
    JOIN stations s ON b.StationID = s.StationID
    WHERE b.Status IN ('Accepted','Arrived','In Progress','Pickup','Washing','Returning') AND s.UserID = ?");
$active_stmt->bind_param("i", $owner_id);
$active_stmt->execute();
$active_count = (int) ($active_stmt->get_result()->fetch_assoc()['total'] ?? 0);

$today_stmt = $conn->prepare("SELECT COUNT(*) AS total
    FROM booking b
    JOIN stations s ON b.StationID = s.StationID
    WHERE DATE(b.Booking_Date) = CURDATE() AND s.UserID = ?");
$today_stmt->bind_param("i", $owner_id);
$today_stmt->execute();
$today_count = (int) ($today_stmt->get_result()->fetch_assoc()['total'] ?? 0);

$earnings_stmt = $conn->prepare("SELECT COALESCE(SUM(GREATEST(b.Total_Price - COALESCE(b.Admin_Commission, 0), 0)), 0) AS total
    FROM booking b
    JOIN stations s ON b.StationID = s.StationID
    WHERE DATE(b.Booking_Date) = CURDATE() AND b.Status = 'Completed' AND s.UserID = ?");
$earnings_stmt->bind_param("i", $owner_id);
$earnings_stmt->execute();
$today_earnings = (float) ($earnings_stmt->get_result()->fetch_assoc()['total'] ?? 0);

$notif_count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0");
$notif_count_stmt->bind_param("i", $owner_id);
$notif_count_stmt->execute();
$unread_count = (int) ($notif_count_stmt->get_result()->fetch_assoc()['total'] ?? 0);

echo json_encode([
    'active_count' => $active_count,
    'today_count' => $today_count,
    'today_earnings' => $today_earnings,
    'unread_count' => $unread_count
]);
