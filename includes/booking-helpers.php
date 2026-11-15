<?php
include_once __DIR__ . "/plan-helpers.php";
include_once __DIR__ . "/app-helpers.php";

function clevoEnsureBookingServicesTable(mysqli $conn): void
{
    clevo_require_schema($conn, 'booking_services', []);
}

function clevoEnsureBookingPaymentColumns(mysqli $conn): void
{
    clevo_require_schema($conn, 'booking', ['Points_Used','Points_Discount','Payable_Amount']);
}

function clevoSaveBookingServices(mysqli $conn, int $bookingID, array $services): void
{
    if ($bookingID <= 0 || empty($services)) {
        return;
    }

    clevoEnsureBookingServicesTable($conn);
    $stmt = $conn->prepare("INSERT INTO booking_services (BookingID, ServiceID, ServiceName, Price) VALUES (?, ?, ?, ?)");
    foreach ($services as $service) {
        $serviceID = (int) ($service['ServiceID'] ?? 0);
        $name = (string) ($service['Name'] ?? '');
        $price = (float) ($service['Price'] ?? 0);
        $stmt->bind_param("iisd", $bookingID, $serviceID, $name, $price);
        $stmt->execute();
    }
}

function clevoExtractServiceNamesFromAddress(?string $addressDetails): array
{
    $addressDetails = (string) $addressDetails;
    $marker = 'الخدمات:';
    $pos = mb_strpos($addressDetails, $marker);
    if ($pos === false) {
        return [];
    }

    $servicesText = trim(mb_substr($addressDetails, $pos + mb_strlen($marker)));
    $servicesText = strtok($servicesText, "\r\n") ?: $servicesText;
    return array_values(array_filter(array_map('trim', explode(' + ', $servicesText))));
}

function clevoBookingServicesSummary(mysqli $conn, array $booking): string
{
    $bookingID = (int) ($booking['BookingID'] ?? 0);
    if ($bookingID > 0) {
        clevoEnsureBookingServicesTable($conn);
        $stmt = $conn->prepare("SELECT ServiceName FROM booking_services WHERE BookingID = ? ORDER BY BookingServiceID ASC");
        $stmt->bind_param("i", $bookingID);
        $stmt->execute();
        $rows = $stmt->get_result();
        $names = [];
        while ($row = $rows->fetch_assoc()) {
            $name = trim((string) ($row['ServiceName'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }
        if ($names) {
            return implode(' + ', $names);
        }
    }

    $fromAddress = clevoExtractServiceNamesFromAddress($booking['Address_Details'] ?? '');
    if ($fromAddress) {
        return implode(' + ', $fromAddress);
    }

    return (string) ($booking['service_name'] ?? '');
}

function clevoBookingPayableAmount(array $booking): float
{
    if (array_key_exists('Payable_Amount', $booking) && $booking['Payable_Amount'] !== null && $booking['Payable_Amount'] !== '') {
        return max(0, round((float) $booking['Payable_Amount'], 2));
    }
    return max(0, round((float) ($booking['Total_Price'] ?? 0) - (float) ($booking['Points_Discount'] ?? 0), 2));
}

function clevoPreviewPointsDiscount(mysqli $conn, int $userID, float $totalPrice, bool $usePoints): array
{
    if (!$usePoints || $totalPrice <= 0) {
        return ['points' => 0.0, 'discount' => 0.0, 'payable' => max(0, round($totalPrice, 2))];
    }

    $totalCents=clevo_db_money($totalPrice);
    $balance=max(0,(int)floor(clevoGetLoyaltyPoints($conn,$userID)));
    $points=min($balance,intdiv($totalCents*40+99,100));
    // Preserve 40 points/JOD and half-up two-decimal discount; derive the remainder
    // from the rounded discount so payable + discount always equals the quote.
    $discountCents=min($totalCents,intdiv($points*100+20,40));
    return ['points'=>(float)$points,'discount'=>$discountCents/100,'payable'=>($totalCents-$discountCents)/100];
}

function clevoApplyPointsDiscount(mysqli $conn, int $bookingID, int $userID, float $totalPrice, bool $usePoints): array
{
    clevoEnsureBookingPaymentColumns($conn);
    $preview = clevoPreviewPointsDiscount($conn, $userID, $totalPrice, $usePoints);
    if ($preview['points'] > 0 && !clevoSpendLoyaltyPoints($conn, $userID, (float) $preview['points'])) {
        throw new RuntimeException('insufficient_points');
    }

    $stmt = $conn->prepare("UPDATE booking SET Points_Used = ?, Points_Discount = ?, Payable_Amount = ? WHERE BookingID = ? AND UserID = ?");
    $stmt->bind_param("dddii", $preview['points'], $preview['discount'], $preview['payable'], $bookingID, $userID);
    $stmt->execute();
    return $preview;
}

function clevoEnsureBookingWorkflowColumns(mysqli $conn): void
{
    clevo_require_schema($conn, 'booking', ['Accepted_At']);
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
    $bookingID=(int)($booking['BookingID']??0);
    return clevo_db_atomic($conn,function()use($conn,$bookingID):bool {
        $b=clevo_stmt($conn,'SELECT b.BookingID,COALESCE(b.OwnerIDSnapshot,s.UserID) AS OwnerID FROM booking b JOIN stations s ON s.StationID=b.StationID WHERE b.BookingID=? FOR UPDATE','i',[$bookingID])->get_result()->fetch_assoc();
        if (!$b) return false;
        $earning=clevo_stmt($conn,'SELECT Amount FROM wallet_transactions WHERE OperationKey=?','s',["booking:$bookingID:owner_earning"])->get_result()->fetch_assoc();
        if (!$earning) return false; // Unstructured historical earnings need reconciliation.
        if (!clevo_db_effect($conn,$bookingID,'owner_reversal')) return true;
        $wallet=clevoEnsureWallet($conn,(int)$b['OwnerID']);
        clevo_wallet_entry($conn,(int)$wallet['WalletID'],clevo_db_money($earning['Amount']),'debit',"booking:$bookingID:owner_reversal",$bookingID,"Owner booking earning reversal #$bookingID");
        return true;
    });
}

function clevoRefundBookingPayment(mysqli $conn, int $bookingID, string $reason = 'cancelled'): array
{
    return clevo_db_atomic($conn,function()use($conn,$bookingID):array {
        $b=clevo_stmt($conn,'SELECT b.*,COALESCE(b.OwnerIDSnapshot,s.UserID) AS OwnerID FROM booking b JOIN stations s ON s.StationID=b.StationID WHERE b.BookingID=? FOR UPDATE','i',[$bookingID])->get_result()->fetch_assoc();
        if (!$b) throw new ClevoSecurityException('booking_not_found',404);
        $method=(string)$b['Payment_Method'];
        if (!$method || $method==='cash') return ['refunded'=>false,'method'=>$method,'amount'=>0,'message'=>'no_payment'];
        $payment=clevo_stmt($conn,'SELECT * FROM payments WHERE BookingID=? FOR UPDATE','i',[$bookingID])->get_result()->fetch_assoc();
        if ($method!=='plan'&&!$payment) throw new ClevoSecurityException('legacy_payment_requires_reconciliation',409);
        if (!clevo_db_effect($conn,$bookingID,'refund')) return ['refunded'=>true,'method'=>$method,'amount'=>0,'message'=>'already_refunded'];
        if ($method==='plan') {
            clevoRestorePlanWashForBooking($conn,$bookingID);
        } else {
            if ($payment['Status']!=='recorded') throw new ClevoSecurityException('payment_not_refundable',409);
            clevo_stmt($conn,'SELECT LoyaltyID FROM loyalty WHERE UserID=? ORDER BY LoyaltyID FOR UPDATE','i',[(int)$b['UserID']])->get_result()->fetch_all(MYSQLI_ASSOC);
            clevo_lock_wallets($conn,[(int)$b['UserID'],(int)$b['OwnerID']]);
            $earned=clevo_stmt($conn,"SELECT Amount FROM wallet_transactions WHERE OperationKey=?",'s',["booking:$bookingID:owner_earning"])->get_result()->fetch_assoc();
            if ($earned) clevoReverseOwnerPayoutForBooking($conn,$b);
            if (in_array($method,['wallet','visa'],true)) {
                $wallet=clevoEnsureWallet($conn,(int)$b['UserID']);
                clevo_wallet_entry($conn,(int)$wallet['WalletID'],clevo_db_money($payment['Amount']),'credit',"booking:$bookingID:refund",$bookingID,"Booking refund #$bookingID");
            }
            if ((float)$b['Points_Used']>0) clevoAddLoyaltyPoints($conn,(int)$b['UserID'],(float)$b['Points_Used']);
        }
        clevo_stmt($conn,"UPDATE payments SET Status='refunded',RefundedAt=UTC_TIMESTAMP(6) WHERE BookingID=?",'i',[$bookingID]);
        clevo_db_audit($conn,null,'booking.refunded','booking',$bookingID);
        return ['refunded'=>true,'method'=>$method,'amount'=>$payment?(float)$payment['Amount']:0,'message'=>$method==='plan'?'plan_restored':'wallet_refunded'];
    });
}

function clevoCancelBookingForUser(mysqli $conn, int $bookingID, int $userID): array
{
    if ($bookingID <= 0 || $userID <= 0) {
        return ['status' => 'error', 'message' => 'invalid_booking'];
    }

    clevoEnsureBookingWorkflowColumns($conn);
    clevo_db_begin($conn);

    try {
        $stmt = $conn->prepare("SELECT b.*, COALESCE(b.OwnerIDSnapshot,s.UserID) AS OwnerID, s.Name AS StationName
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
            clevoCreateNotification($conn, $ownerID, 'تم إلغاء الحجز', "قام العميل بإلغاء الطلب #$orderNumber.", 'warning', 'incoming-orders.php');
        }
        if ((int) ($booking['DriverID'] ?? 0) > 0) {
            clevoCreateNotification($conn, (int) $booking['DriverID'], 'تم إلغاء المهمة', "تم إلغاء الطلب #$orderNumber من قبل العميل.", 'warning', 'driver-dashboard.php');
        }

        $userBody = ($refund['refunded'] ?? false)
            ? "تم إلغاء طلبك #$orderNumber وحفظ المبلغ/الرصيد المستحق في حسابك."
            : "تم إلغاء طلبك #$orderNumber بنجاح.";
        clevoCreateNotification($conn, $userID, 'تم إلغاء الحجز', $userBody, 'success', 'order-details.php?id=' . $bookingID);

        clevo_db_commit($conn);
        return ['status' => 'success', 'message' => 'cancelled', 'refund' => $refund];
    } catch (Throwable $e) {
        clevo_db_rollback($conn);
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
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "$date $time", new DateTimeZone(clevo_env('BOOKING_TIMEZONE','Asia/Amman')));
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
    clevo_db_begin($conn);

    try {
        $stmt = $conn->prepare("SELECT b.*, COALESCE(b.OwnerIDSnapshot,s.UserID) AS OwnerID
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
            SET Booking_Date = ?, Booking_Time = ?, ScheduledAtUtc = ?, BookingTimeZone = ?, Status = 'Pending', DriverID = NULL, Accepted_At = NULL, notification_sent = 0
            WHERE BookingID = ? AND UserID = ? AND Status = 'Pending'");
        $zone=clevo_env('BOOKING_TIMEZONE','Asia/Amman');
        $utc=(new DateTimeImmutable($slot['date'].' '.$slot['time'],new DateTimeZone($zone)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $update->bind_param("ssssii", $slot['date'], $slot['time'], $utc, $zone, $bookingID, $userID);
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
            clevoCreateNotification($conn, (int) $booking['DriverID'], 'تم تعديل موعد المهمة', "تم تعديل موعد الطلب #{$orderNumber}، وسيتم تأكيده من المحطة من جديد.", 'info');
        }
        clevoCreateNotification($conn, $userID, 'تم تعديل موعد الحجز', "تم حفظ الموعد الجديد للطلب #$orderNumber مع الحفاظ على طريقة الدفع والمستحقات.", 'success');

        clevo_db_commit($conn);
        return ['status' => 'success', 'message' => 'rescheduled', 'date' => $slot['date'], 'time' => $slot['time']];
    } catch (Throwable $e) {
        clevo_db_rollback($conn);
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

function cancelExpiredBookings(mysqli $conn, ?int $userID = null): void
{
    $where=$userID===null?'':' AND UserID=?';
    $sql="SELECT BookingID FROM booking WHERE Status IN ('Pending','Accepted') AND COALESCE(notification_sent,0)=0 AND created_at<UTC_TIMESTAMP()-INTERVAL 10 MINUTE".$where." LIMIT 200";
    $ids=clevo_stmt($conn,$sql,$userID===null?'':'i',$userID===null?[]:[$userID])->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($ids as $id) clevo_db_atomic($conn,function()use($conn,$id):void {
        $bookingID=(int)$id['BookingID'];
        $b=clevo_stmt($conn,"SELECT * FROM booking WHERE BookingID=? AND COALESCE(notification_sent,0)=0 AND ((Status='Pending' AND created_at<UTC_TIMESTAMP()-INTERVAL 10 MINUTE) OR (Status='Accepted' AND Service_Location IN ('home','delivery') AND (Payment_Method IS NULL OR Payment_Method='') AND COALESCE(Accepted_At,created_at)<UTC_TIMESTAMP()-INTERVAL 10 MINUTE)) FOR UPDATE",'i',[$bookingID])->get_result()->fetch_assoc();
        if (!$b) return;
        if (!empty($b['Payment_Method'])) clevoRefundBookingPayment($conn,$bookingID,'timeout');
        clevo_stmt($conn,"UPDATE booking SET Status='Cancelled_Timeout',notification_sent=1 WHERE BookingID=?",'i',[$bookingID]);
        clevo_stmt($conn,'UPDATE driver_assignments SET EndedAt=UTC_TIMESTAMP(6) WHERE BookingID=? AND EndedAt IS NULL','i',[$bookingID]);
        clevo_db_audit($conn,null,'booking.expired','booking',$bookingID);
        clevoCreateNotification($conn,(int)$b['UserID'],'انتهت مهلة الطلب','انتهت مهلة الرد أو الدفع للطلب. يمكنك حجز محطة أخرى الآن.','danger',"order-details.php?id=$bookingID",'Order expired','The order response/payment window expired.');
    });
}
