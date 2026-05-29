<?php
include_once __DIR__ . "/plan-helpers.php";

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
    $stmt = $conn->prepare("SELECT COLUMN_NAME, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'booking'");
    $stmt->bind_param("s", $db);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $columns[$row['COLUMN_NAME']] = $row;
    }

    if (!isset($columns['Accepted_At'])) {
        $conn->query("ALTER TABLE booking ADD COLUMN Accepted_At DATETIME DEFAULT NULL AFTER Payment_Method");
    }
    if (isset($columns['Status']) && !str_contains((string) $columns['Status']['COLUMN_TYPE'], "'Cancelled'")) {
        $conn->query("ALTER TABLE booking MODIFY Status ENUM('Pending','Accepted','Arrived','In Progress','Pickup','Washing','Returning','Rejected','Cancelled','Cancelled_Timeout','Completed') DEFAULT 'Pending'");
    }
}

function clevoBookingCanBeChanged(string $status): bool
{
    return $status === 'Pending';
}

function clevoBookingReference(array $booking, int $bookingID = 0): string
{
    $orderNumber = trim((string) ($booking['Order_Number'] ?? ''));
    if ($orderNumber !== '') {
        return $orderNumber;
    }
    return (string) ($bookingID ?: ($booking['BookingID'] ?? ''));
}

function clevoBookingHasWalletTransaction(mysqli $conn, int $walletID, string $descriptionEn): bool
{
    $stmt = $conn->prepare("SELECT TransactionID FROM wallet_transactions WHERE WalletID = ? AND Description_en = ? LIMIT 1");
    $stmt->bind_param("is", $walletID, $descriptionEn);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_assoc();
}

function clevoReverseOwnerPayoutForBooking(mysqli $conn, array $booking): bool
{
    $method = (string) ($booking['Payment_Method'] ?? '');
    if (!in_array($method, ['visa', 'wallet', 'points'], true)) {
        return false;
    }

    $ownerID = (int) ($booking['OwnerID'] ?? 0);
    if ($ownerID <= 0) {
        return false;
    }

    $bookingID = (int) ($booking['BookingID'] ?? 0);
    $orderNumber = clevoBookingReference($booking, $bookingID);
    $wallet = clevoEnsureWallet($conn, $ownerID);
    $walletID = (int) $wallet['WalletID'];
    $earningDescEn = "Owner booking earning #$orderNumber";
    $reversalDescEn = "Owner booking earning reversal #$orderNumber";

    if (clevoBookingHasWalletTransaction($conn, $walletID, $reversalDescEn)) {
        return true;
    }
    if (!clevoBookingHasWalletTransaction($conn, $walletID, $earningDescEn)) {
        return false;
    }

    $amount = clevoCalculateOwnerPayout((float) ($booking['Total_Price'] ?? 0), (float) ($booking['Admin_Commission'] ?? 0));
    if ($amount <= 0 || !clevoDebitWallet($conn, $walletID, $amount)) {
        return false;
    }

    $type = "debit";
    $descAr = "عكس أرباح طلب #$orderNumber";
    clevoRecordWalletTransaction($conn, $walletID, $amount, $type, $descAr, $reversalDescEn);
    return true;
}

function clevoRefundBookingPayment(mysqli $conn, int $bookingID, string $reason = 'cancelled'): array
{
    clevoEnsurePlanColumns($conn);

    $stmt = $conn->prepare("SELECT b.*, s.UserID AS OwnerID
        FROM booking b
        JOIN stations s ON b.StationID = s.StationID
        WHERE b.BookingID = ? LIMIT 1");
    $stmt->bind_param("i", $bookingID);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();

    if (!$booking) {
        return ['refunded' => false, 'method' => '', 'amount' => 0, 'message' => 'booking_not_found'];
    }

    $method = (string) ($booking['Payment_Method'] ?? '');
    $amount = round((float) ($booking['Total_Price'] ?? 0), 2);
    $orderNumber = clevoBookingReference($booking, $bookingID);
    $result = ['refunded' => false, 'method' => $method, 'amount' => $amount, 'message' => 'no_payment'];

    if ($method === 'plan') {
        clevoRestorePlanWashForBooking($conn, $bookingID);
        return ['refunded' => true, 'method' => $method, 'amount' => 0, 'message' => 'plan_restored'];
    }

    if (in_array($method, ['visa', 'wallet'], true) && $amount > 0) {
        $wallet = clevoEnsureWallet($conn, (int) $booking['UserID']);
        $walletID = (int) $wallet['WalletID'];
        $descEn = "Booking refund #$orderNumber";

        if (!clevoBookingHasWalletTransaction($conn, $walletID, $descEn)) {
            if (!clevoCreditWallet($conn, $walletID, $amount)) {
                return ['refunded' => false, 'method' => $method, 'amount' => $amount, 'message' => 'wallet_refund_failed'];
            }
            $type = "credit";
            $descAr = $method === 'visa'
                ? "استرجاع دفع فيزا للطلب #$orderNumber"
                : "استرجاع دفع المحفظة للطلب #$orderNumber";
            clevoRecordWalletTransaction($conn, $walletID, $amount, $type, $descAr, $descEn);
        }

        clevoReverseOwnerPayoutForBooking($conn, $booking);
        return ['refunded' => true, 'method' => $method, 'amount' => $amount, 'message' => 'wallet_refunded'];
    }

    if ($method === 'points') {
        $points = (int) ceil($amount * 100);
        clevoAddLoyaltyPoints($conn, (int) $booking['UserID'], $points);
        clevoReverseOwnerPayoutForBooking($conn, $booking);
        return ['refunded' => true, 'method' => $method, 'amount' => $points, 'message' => 'points_refunded'];
    }

    return $result;
}

function clevoCancelBookingForUser(mysqli $conn, int $bookingID, int $userID): array
{
    if ($bookingID <= 0 || $userID <= 0) {
        return ['status' => 'error', 'message' => 'invalid_booking'];
    }

    clevoEnsureBookingWorkflowColumns($conn);
    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("SELECT b.*, s.UserID AS OwnerID, s.Name AS StationName
            FROM booking b
            JOIN stations s ON b.StationID = s.StationID
            WHERE b.BookingID = ? AND b.UserID = ? LIMIT 1 FOR UPDATE");
        $stmt->bind_param("ii", $bookingID, $userID);
        $stmt->execute();
        $booking = $stmt->get_result()->fetch_assoc();

        if (!$booking) {
            throw new RuntimeException('booking_not_found');
        }
        if (!clevoBookingCanBeChanged((string) $booking['Status'])) {
            throw new RuntimeException('booking_locked');
        }

        $refund = clevoRefundBookingPayment($conn, $bookingID, 'cancelled');
        if (($refund['message'] ?? '') === 'wallet_refund_failed') {
            throw new RuntimeException('refund_failed');
        }

        $update = $conn->prepare("UPDATE booking
            SET Status = 'Cancelled', DriverID = NULL, Accepted_At = NULL, notification_sent = 1
            WHERE BookingID = ? AND UserID = ? AND Status = 'Pending'");
        $update->bind_param("ii", $bookingID, $userID);
        $update->execute();
        if ($update->affected_rows <= 0) {
            throw new RuntimeException('cancel_failed');
        }

        $orderNumber = clevoBookingReference($booking, $bookingID);
        $ownerID = (int) ($booking['OwnerID'] ?? 0);
        if ($ownerID > 0) {
            clevoCreateNotification($conn, $ownerID, 'تم إلغاء الحجز', "قام العميل بإلغاء الطلب #$orderNumber.", 'warning');
        }
        if ((int) ($booking['DriverID'] ?? 0) > 0) {
            clevoCreateNotification($conn, (int) $booking['DriverID'], 'تم إلغاء المهمة', "تم إلغاء الطلب #$orderNumber من قبل العميل.", 'warning');
        }

        $userBody = ($refund['refunded'] ?? false)
            ? "تم إلغاء طلبك #$orderNumber وحفظ المبلغ/الرصيد المستحق في حسابك."
            : "تم إلغاء طلبك #$orderNumber بنجاح.";
        clevoCreateNotification($conn, $userID, 'تم إلغاء الحجز', $userBody, 'success');

        $conn->commit();
        return ['status' => 'success', 'message' => 'cancelled', 'refund' => $refund];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

function clevoParseRescheduleDateTime(string $date, string $time): ?array
{
    $date = trim($date);
    $time = trim($time);
    if ($date === '' || $time === '') {
        return null;
    }

    $time = substr($time, 0, 5);
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', "$date $time");
    if (!$dt || $dt->format('Y-m-d') !== $date || $dt->format('H:i') !== $time) {
        return null;
    }
    if ($dt < new DateTimeImmutable('-1 minute')) {
        return null;
    }

    return ['date' => $dt->format('Y-m-d'), 'time' => $dt->format('H:i:s')];
}

function clevoRescheduleBookingForUser(mysqli $conn, int $bookingID, int $userID, string $date, string $time): array
{
    if ($bookingID <= 0 || $userID <= 0) {
        return ['status' => 'error', 'message' => 'invalid_booking'];
    }

    $slot = clevoParseRescheduleDateTime($date, $time);
    if (!$slot) {
        return ['status' => 'error', 'message' => 'invalid_datetime'];
    }

    clevoEnsureBookingWorkflowColumns($conn);
    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("SELECT b.*, s.UserID AS OwnerID
            FROM booking b
            JOIN stations s ON b.StationID = s.StationID
            WHERE b.BookingID = ? AND b.UserID = ? LIMIT 1 FOR UPDATE");
        $stmt->bind_param("ii", $bookingID, $userID);
        $stmt->execute();
        $booking = $stmt->get_result()->fetch_assoc();

        if (!$booking) {
            throw new RuntimeException('booking_not_found');
        }
        if (!clevoBookingCanBeChanged((string) $booking['Status'])) {
            throw new RuntimeException('booking_locked');
        }

        $update = $conn->prepare("UPDATE booking
            SET Booking_Date = ?, Booking_Time = ?, Status = 'Pending', DriverID = NULL, Accepted_At = NULL, notification_sent = 0
            WHERE BookingID = ? AND UserID = ? AND Status = 'Pending'");
        $update->bind_param("ssii", $slot['date'], $slot['time'], $bookingID, $userID);
        $update->execute();
        if ($update->affected_rows <= 0) {
            throw new RuntimeException('reschedule_failed');
        }

        $orderNumber = clevoBookingReference($booking, $bookingID);
        $ownerID = (int) ($booking['OwnerID'] ?? 0);
        if ($ownerID > 0) {
            clevoCreateNotification($conn, $ownerID, 'تم تعديل موعد الحجز', "تم تعديل موعد الطلب #$orderNumber إلى {$slot['date']} الساعة {$slot['time']}. يرجى مراجعة الطلب من جديد.", 'info');
        }
        if ((int) ($booking['DriverID'] ?? 0) > 0) {
            clevoCreateNotification($conn, (int) $booking['DriverID'], 'تم تعديل موعد المهمة', "تم تعديل موعد الطلب #$orderNumber، وسيتم تأكيده من المحطة من جديد.", 'info');
        }
        clevoCreateNotification($conn, $userID, 'تم تعديل موعد الحجز', "تم حفظ الموعد الجديد للطلب #$orderNumber مع الحفاظ على طريقة الدفع والمستحقات.", 'success');

        $conn->commit();
        return ['status' => 'success', 'message' => 'rescheduled', 'date' => $slot['date'], 'time' => $slot['time']];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['status' => 'error', 'message' => $e->getMessage()];
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
            clevoRestorePlanWashForBooking($conn, $bookingID);
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
              AND COALESCE(Accepted_At, created_at) < (NOW() - INTERVAL 10 MINUTE)
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
            clevoRestorePlanWashForBooking($conn, $bookingID);
            $title = "تم إلغاء الطلب";
            $body = "تم إلغاء طلبك #$orderNumber لأن الدفع لم يكتمل خلال 10 دقائق بعد الموافقة.";
            $type = "danger";
            $notice = $conn->prepare("INSERT INTO notifications (user_id, title, body, type) VALUES (?, ?, ?, ?)");
            $notice->bind_param("isss", $customerID, $title, $body, $type);
            $notice->execute();
        }
    }
}
?>
