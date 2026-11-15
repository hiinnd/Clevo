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
    $stmt = $conn->prepare("INSERT INTO booking_services (BookingID, ServiceID, ServiceName, Price, DurationMinutes) VALUES (?, ?, ?, ?, ?)");
    foreach ($services as $service) {
        $serviceID = (int) ($service['ServiceID'] ?? 0);
        $name = (string) ($service['Name'] ?? '');
        $price = (float) ($service['Price'] ?? 0);
        $duration=(int)($service['DurationMinutes']??$service['Duration']??0);
        $stmt->bind_param("iisdi", $bookingID, $serviceID, $name, $price, $duration);
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
        if (!in_array($b['Status'],['Pending','Accepted','Rejected','Cancelled','Cancelled_Timeout'],true) || !in_array($method,['wallet','points','plan'],true)) throw new ClevoSecurityException('manual_refund_required',409);
        clevo_assert_booking_payment($conn,$b);
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
    try {
        $account=clevo_booking_actor($conn,$userID);
        if (clevo_role($account['Role'])!=='customer') throw new ClevoSecurityException('forbidden',403);
        return clevo_booking_transition($conn,$userID,$bookingID,'Cancelled','customer_cancelled');
    } catch (Throwable $e) { return clevo_booking_change_error($e); }
}

function clevo_booking_change_error(Throwable $e): array
{
    $reason=$e instanceof ClevoSecurityException?$e->reason:'booking_change_failed';
    if (!$e instanceof ClevoSecurityException) error_log('CLEVO booking operation failed: '.get_class($e));
    return ['status'=>'error','message'=>match($reason){'not_found'=>'booking_not_found','invalid_transition'=>'booking_locked',default=>$reason}];
}

function clevoParseRescheduleDateTime(string $date, string $time): ?array
{
    try {
        $when=clevo_booking_datetime(trim($date),trim($time));
        if ($when<=new DateTimeImmutable('now',$when->getTimezone())) return null;
        return ['date'=>$when->format('Y-m-d'),'time'=>$when->format('H:i:s')];
    } catch(Throwable $e) { return null; }
}

function clevoRescheduleBookingForUser(mysqli $conn, int $bookingID, int $userID, string $date, string $time): array
{
    try { return clevo_db_atomic($conn,function()use($conn,$bookingID,$userID,$date,$time):array {
        $b=clevo_booking_lock($conn,$bookingID);$s=$b['_station'];
        $a=clevo_booking_actor($conn,$userID);
        if (clevo_role($a['Role'])!=='customer'||(int)$b['UserID']!==$userID) throw new ClevoSecurityException('not_found',404);
        if (!clevoBookingCanBeChanged((string)$b['Status'])) throw new ClevoSecurityException('booking_locked',409);
        if (clevo_booking_is_expired($b)) throw new ClevoSecurityException('booking_expired',409);
        $when=clevo_booking_datetime($date,$time,$b['BookingTimeZone']?:null);
        $services=clevo_stmt($conn,'SELECT bs.ServiceID,bs.Price,bs.DurationMinutes,v.Duration,v.ArchivedAt,v.is_station,v.is_home,v.is_pickup FROM booking_services bs JOIN services v ON v.ServiceID=bs.ServiceID WHERE bs.BookingID=? ORDER BY bs.ServiceID FOR UPDATE','i',[$bookingID])->get_result()->fetch_all(MYSQLI_ASSOC);
        if (!$services) $services=clevo_stmt($conn,'SELECT * FROM services WHERE ServiceID=? AND StationID=? FOR UPDATE','ii',[(int)$b['ServiceID'],(int)$b['StationID']])->get_result()->fetch_all(MYSQLI_ASSOC);
        if (!$services) throw new ClevoSecurityException('service_unavailable',409);
        $quote=clevo_booking_schedule($s,$services,$b['Service_Location'],$when,$b['DurationMinutes']!==null?(int)$b['DurationMinutes']:null);
        clevo_booking_assert_capacity($conn,$s,$b['Service_Location'],$when,$quote['end'],$bookingID);
        $ids=array_map('intval',array_column($services,'ServiceID'));
        $fingerprint=clevo_booking_intent($userID,(int)$b['StationID'],$ids,$b['Service_Location'],$when,$b['Latitude']!==null?(float)$b['Latitude']:null,$b['Longitude']!==null?(float)$b['Longitude']:null,(string)$b['Address_Details'],$b['Payment_Method']==='plan');
        if(clevo_stmt($conn,'SELECT BookingID FROM booking WHERE ActiveRequestFingerprint=? AND BookingID<>? FOR UPDATE','si',[$fingerprint,$bookingID])->get_result()->fetch_assoc())throw new ClevoSecurityException('booking_already_exists',409);
        $utc=$when->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $end=$quote['end']->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        clevo_stmt($conn,'UPDATE booking SET Booking_Date=?,Booking_Time=?,ScheduledAtUtc=?,BookingTimeZone=?,ScheduledEndAtUtc=?,DurationMinutes=?,RequestFingerprint=? WHERE BookingID=?','sssssisi',[$when->format('Y-m-d'),$when->format('H:i:s'),$utc,$when->getTimezone()->getName(),$end,$quote['duration'],$fingerprint,$bookingID]);
        clevo_booking_event($conn,$bookingID,'Pending','Pending',$userID,'customer','customer_rescheduled');
        clevo_db_audit($conn,$userID,'booking.rescheduled','booking',$bookingID);
        clevoCreateNotification($conn,(int)$s['UserID'],'تم تعديل موعد الحجز','تم تعديل موعد الطلب #'.$bookingID,'info','incoming-orders.php','Booking rescheduled','Order #'.$bookingID.' was rescheduled.');
        clevoCreateNotification($conn,$userID,'تم تعديل موعد الحجز','تم حفظ موعد الطلب #'.$bookingID,'success','order-details.php?id='.$bookingID,'Booking rescheduled','Order #'.$bookingID.' was rescheduled.');
        return ['status'=>'success','message'=>'rescheduled','date'=>$when->format('Y-m-d'),'time'=>$when->format('H:i:s')];
    }); } catch(Throwable $e) { return clevo_booking_change_error($e); }
}

function cancelExpiredBookings(mysqli $conn, ?int $userID = null, ?int $stationID = null): void
{
    $where='';$types='';$params=[];
    if ($userID!==null) {$where.=' AND UserID=?';$types.='i';$params[]=$userID;}
    if ($stationID!==null) {$where.=' AND StationID=?';$types.='i';$params[]=$stationID;}
    $sql="SELECT BookingID FROM booking WHERE ((Status='Pending' AND created_at<=UTC_TIMESTAMP()-INTERVAL 10 MINUTE) OR (Status='Accepted' AND Service_Location IN ('home','delivery') AND (Payment_Method IS NULL OR Payment_Method='') AND COALESCE(Accepted_At,created_at)<=UTC_TIMESTAMP()-INTERVAL 10 MINUTE))".$where.' ORDER BY BookingID LIMIT 200';
    $ids=clevo_stmt($conn,$sql,$types,$params)->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($ids as $row) clevo_db_atomic($conn,function()use($conn,$row):void {
        $id=(int)$row['BookingID'];$b=clevo_booking_lock($conn,$id);
        if (!clevo_booking_is_expired($b)) return;
        clevo_booking_transition($conn,null,$id,'Cancelled_Timeout',$b['Status']==='Pending'?'request_expired':'payment_expired');
    });
}
