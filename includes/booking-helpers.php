<?php
function clevoEnsureBookingWorkflowColumns(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $db = $conn->query("SELECT DATABASE() AS db")->fetch_assoc()['db'] ?? '';
    if ($db === '') {
        return;
    }

    $columns = [];
    $stmt = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'booking'");
    $stmt->bind_param("s", $db);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $columns[$row['COLUMN_NAME']] = true;
    }

    if (!isset($columns['Accepted_At'])) {
        $conn->query("ALTER TABLE booking ADD COLUMN Accepted_At DATETIME DEFAULT NULL AFTER Payment_Method");
    }
}

function cancelExpiredBookings(mysqli $conn, ?int $userID = null): void
{
    clevoEnsureBookingWorkflowColumns($conn);

    $sql = "SELECT BookingID, UserID, Order_Number
            FROM booking
            WHERE Status = 'Pending'
              AND created_at < (NOW() - INTERVAL 10 MINUTE)
              AND COALESCE(notification_sent, 0) = 0";

    if ($userID !== null) {
        $sql .= " AND UserID = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $userID);
    } else {
        $stmt = $conn->prepare($sql);
    }

    $stmt->execute();
    $expired = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($expired as $booking) {
        $bookingID = (int) $booking['BookingID'];
        $customerID = (int) $booking['UserID'];
        $orderNumber = $booking['Order_Number'] ?: $bookingID;

        $update = $conn->prepare("UPDATE booking SET Status = 'Cancelled_Timeout', notification_sent = 1 WHERE BookingID = ? AND Status = 'Pending'");
        $update->bind_param("i", $bookingID);
        $update->execute();

        if ($update->affected_rows > 0) {
            $title = "انتهت مهلة الطلب";
            $body = "لم يتم الرد على طلبك #$orderNumber خلال 10 دقائق. يمكنك حجز محطة أخرى الآن.";
            $type = "danger";
            $notice = $conn->prepare("INSERT INTO notifications (user_id, title, body, type) VALUES (?, ?, ?, ?)");
            $notice->bind_param("isss", $customerID, $title, $body, $type);
            $notice->execute();
        }
    }

    $paymentSql = "SELECT BookingID, UserID, Order_Number
            FROM booking
            WHERE Status = 'Accepted'
              AND Service_Location IN ('home', 'delivery')
              AND (Payment_Method IS NULL OR Payment_Method = '')
              AND COALESCE(Accepted_At, created_at) < (NOW() - INTERVAL 5 MINUTE)
              AND COALESCE(notification_sent, 0) = 0";

    if ($userID !== null) {
        $paymentSql .= " AND UserID = ?";
        $paymentStmt = $conn->prepare($paymentSql);
        $paymentStmt->bind_param("i", $userID);
    } else {
        $paymentStmt = $conn->prepare($paymentSql);
    }

    $paymentStmt->execute();
    $paymentExpired = $paymentStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($paymentExpired as $booking) {
        $bookingID = (int) $booking['BookingID'];
        $customerID = (int) $booking['UserID'];
        $orderNumber = $booking['Order_Number'] ?: $bookingID;

        $update = $conn->prepare("UPDATE booking SET Status = 'Cancelled_Timeout', notification_sent = 1 WHERE BookingID = ? AND Status = 'Accepted' AND Service_Location IN ('home', 'delivery') AND (Payment_Method IS NULL OR Payment_Method = '')");
        $update->bind_param("i", $bookingID);
        $update->execute();

        if ($update->affected_rows > 0) {
            $title = "تم إلغاء الطلب";
            $body = "تم إلغاء طلبك #$orderNumber لأن الدفع لم يكتمل خلال 5 دقائق بعد الموافقة.";
            $type = "danger";
            $notice = $conn->prepare("INSERT INTO notifications (user_id, title, body, type) VALUES (?, ?, ?, ?)");
            $notice->bind_param("isss", $customerID, $title, $body, $type);
            $notice->execute();
        }
    }
}
?>
