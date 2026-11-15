<?php
declare(strict_types=1);
require_once __DIR__.'/booking-helpers.php';

function clevo_create_booking(mysqli $conn,int $user,int $station,array $input,string $key): array
{
    $ids=$input['service_ids']??[];
    if (!is_array($ids) || count($ids)<1 || count($ids)>30) throw new ClevoSecurityException('invalid_services',422);
    foreach ($ids as $id) if (!is_int($id) && !(is_string($id)&&ctype_digit($id))) throw new ClevoSecurityException('invalid_services',422);
    $ids=array_values(array_unique(array_map('intval',$ids))); sort($ids);
    $mode=(string)($input['service_location']??''); $date=(string)($input['booking_date']??''); $time=(string)($input['booking_time']??'');
    if (!in_array($mode,['station','home','delivery'],true)) throw new ClevoSecurityException('invalid_location',422);
    if (strlen($time)===5) $time.=':00';
    $zone=new DateTimeZone(clevo_env('BOOKING_TIMEZONE','Asia/Amman'));
    $when=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',"$date $time",$zone);
    if (!$when || $when->format('Y-m-d H:i:s')!=="$date $time") throw new ClevoSecurityException('invalid_booking_time',422);
    $lat=$input['latitude']??null; $lon=$input['longitude']??null;
    if ($mode!=='station' && (!is_numeric($lat)||!is_numeric($lon)||abs((float)$lat)>90||abs((float)$lon)>180)) throw new ClevoSecurityException('location_required',422);
    $lat=is_numeric($lat)?(float)$lat:null; $lon=is_numeric($lon)?(float)$lon:null;
    if (($lat!==null&&(!is_finite($lat)||abs($lat)>90))||($lon!==null&&(!is_finite($lon)||abs($lon)>180))) throw new ClevoSecurityException('invalid_coordinates',422);
    $address=clevo_clean_string($input['address_details']??'',2000); $usePlan=filter_var($input['use_plan']??false,FILTER_VALIDATE_BOOLEAN);
    $body=[$station,$ids,$mode,$date,$time,$lat,$lon,$address,$usePlan];
    return clevo_db_idempotent($conn,$user,'booking.create',$key,$body,function()use($conn,$user,$station,$ids,$mode,$date,$time,$when,$zone,$lat,$lon,$address,$usePlan):array {
        if ($when<=new DateTimeImmutable('now',$zone)) throw new ClevoSecurityException('invalid_booking_time',422);
        $s=clevo_stmt($conn,"SELECT * FROM stations WHERE StationID=? AND ArchivedAt IS NULL AND admin_status='approved' AND Status='Active' FOR UPDATE",'i',[$station])->get_result()->fetch_assoc();
        if (!$s) throw new ClevoSecurityException('station_unavailable',409);
        $flag=['station'=>'Has_At_Station','home'=>'Has_Home_Service','delivery'=>'Has_Delivery'][$mode];
        if (!(int)$s[$flag]) throw new ClevoSecurityException('service_unavailable',409);
        if ($s['OpeningTime'] && $s['ClosingTime']) {
            $open=$s['OpeningTime']; $close=$s['ClosingTime'];
            $inside=$open<$close?($time>=$open&&$time<$close):($open===$close||$time>=$open||$time<$close);
            if (!$inside) throw new ClevoSecurityException('outside_opening_hours',422);
        }
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $services=clevo_stmt($conn,"SELECT * FROM services WHERE StationID=? AND ServiceID IN ($placeholders) AND ArchivedAt IS NULL ORDER BY ServiceID FOR UPDATE",str_repeat('i',count($ids)+1),array_merge([$station],$ids))->get_result()->fetch_all(MYSQLI_ASSOC);
        if (count($services)!==count($ids)) throw new ClevoSecurityException('service_unavailable',409);
        $total=0; $kind=['station'=>'is_station','home'=>'is_home','delivery'=>'is_pickup'][$mode];
        foreach ($services as $service) { if (!(int)$service[$kind] || $service['Price']===null) throw new ClevoSecurityException('service_unavailable',409); $total+=clevo_db_money($service['Price']); }
        if ($total>9999999999) throw new ClevoSecurityException('invalid_amount',422);
        $plan=$usePlan?clevoUsePlanForBooking($conn,$user):null;
        if ($usePlan&&!$plan) throw new ClevoSecurityException('plan_unavailable',409);
        $method=$usePlan?'plan':''; $planId=$plan?(int)$plan['id']:null; $used=$usePlan?1:0;
        $order='C'.strtoupper(bin2hex(random_bytes(9))); $utc=$when->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        clevo_stmt($conn,"INSERT INTO booking (Order_Number,UserID,StationID,ServiceID,Service_Location,Latitude,Longitude,Address_Details,Booking_Date,Booking_Time,Total_Price,Admin_Commission,Payment_Method,UserPlanID,Plan_Washes_Used,ScheduledAtUtc,BookingTimeZone) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",'siiisddssssssiiss',[$order,$user,$station,$ids[0],$mode,$lat,$lon,$address,$date,$time,clevo_db_decimal($total),clevo_db_decimal((int)round($total/10)),$method,$planId,$used,$utc,$zone->getName()]);
        $booking=(int)$conn->insert_id;
        clevo_stmt($conn,'UPDATE booking SET OwnerIDSnapshot=? WHERE BookingID=?','ii',[(int)$s['UserID'],$booking]);
        clevoSaveBookingServices($conn,$booking,$services);
        if ($plan) clevo_stmt($conn,"INSERT INTO payments (BookingID,UserID,Method,Status,Amount) VALUES (?,?,'plan','recorded',0)",'ii',[$booking,$user]);
        clevoCreateNotification($conn,(int)$s['UserID'],'طلب جديد',"وصلك طلب جديد #$order بتاريخ $date الساعة $time.",'info','incoming-orders.php','New order',"Order #$order on $date at $time.");
        clevo_db_audit($conn,$user,'booking.created','booking',$booking);
        return ['status'=>'success','booking_id'=>$booking,'order_number'=>$order,'payment_method'=>$method];
    });
}

function clevo_wallet_entry(mysqli $conn,int $wallet,int $cents,string $type,string $key,?int $booking,string $description): void
{
    if ($cents===0) return;
    if ($cents<0 || !in_array($type,['credit','debit'],true)) throw new LogicException('Invalid ledger mutation.');
    $amount=clevo_db_decimal($cents);
    $sql=$type==='credit'?'UPDATE wallet SET Balance=Balance+? WHERE WalletID=? AND Balance>=0 AND Balance<=99999999.99-?':'UPDATE wallet SET Balance=Balance-? WHERE WalletID=? AND Balance>=?';
    $stmt=clevo_stmt($conn,$sql,'sis',[$amount,$wallet,$amount]);
    if ($stmt->affected_rows!==1) throw new ClevoSecurityException($type==='credit'?'wallet_balance_limit':'insufficient',409);
    clevo_stmt($conn,'INSERT INTO wallet_transactions (WalletID,Amount,TransactionType,Description_ar,Description_en,OperationKey,BookingID) VALUES (?,?,?,?,?,?,?)','isssssi',[$wallet,$amount,$type,$description,$description,$key,$booking]);
}

function clevo_purchase_plan(mysqli $conn,int $user,int $plan,string $key): array
{
    return clevo_db_idempotent($conn,$user,'plan.purchase',$key,[$plan],function()use($conn,$user,$plan):array {
        $p=clevo_stmt($conn,'SELECT * FROM plans WHERE id=? AND is_active=1 FOR UPDATE','i',[$plan])->get_result()->fetch_assoc();
        if (!$p) throw new ClevoSecurityException('plan_unavailable',409);
        $wallet=clevoEnsureWallet($conn,$user);
        $subscription=clevoCreateUserPlanSubscription($conn,$user,$plan);
        if (!$subscription) throw new RuntimeException('Subscription failed.');
        $id=(int)$subscription['user_plan_id'];
        clevo_wallet_entry($conn,(int)$wallet['WalletID'],clevo_db_money($p['price']),'debit',"subscription:$id:purchase",null,"Plan subscription #$id");
        clevo_stmt($conn,'UPDATE user_plans SET PurchasePrice=?,PurchasedWashes=?,PlanNameSnapshot=? WHERE id=?','sisi',[$p['price'],clevoPlanWashCount($p),$p['name_en']?:$p['name_ar'],$id]);
        clevo_db_audit($conn,$user,'plan.purchased','user_plans',$id);
        return ['status'=>'success','subscription_id'=>$id];
    });
}

function clevo_request_withdrawal(mysqli $conn,int $owner,string $key): array
{
    return clevo_db_idempotent($conn,$owner,'owner.withdrawal',$key,[],function()use($conn,$owner):array {
        $bank=clevoGetOwnerBankAccount($conn,$owner);
        if (!$bank) throw new ClevoSecurityException('bank_required',422);
        $wallet=clevoEnsureWallet($conn,$owner);
        $wallet=clevo_stmt($conn,'SELECT WalletID,Balance FROM wallet WHERE WalletID=? FOR UPDATE','i',[(int)$wallet['WalletID']])->get_result()->fetch_assoc();
        $amount=clevo_db_money($wallet['Balance']);
        if ($amount<=0) throw new ClevoSecurityException('empty_balance',409);
        if (!clevoRecordOwnerWithdrawalRequest($conn,$owner,(int)$wallet['WalletID'],$amount/100,$bank)) throw new RuntimeException('Withdrawal failed.');
        $id=(int)$conn->insert_id;
        clevo_wallet_entry($conn,(int)$wallet['WalletID'],$amount,'debit',"withdrawal:$id:reserve",null,'Owner withdrawal request');
        clevo_db_audit($conn,$owner,'withdrawal.requested','owner_withdrawal_requests',$id);
        clevoCreateRoleNotification($conn,'Admin','طلب سحب أرباح','وصل طلب سحب أرباح بانتظار المراجعة.','info','financial-reports.php','Withdrawal request','A withdrawal awaits review.');
        clevoCreateNotification($conn,$owner,'تم إرسال طلب السحب','تم تسجيل طلب سحب الأرباح وسيتم مراجعته من الإدارة.','success','owner-wallet.php','Withdrawal request sent','Your withdrawal awaits review.');
        return ['status'=>'success','amount'=>$amount/100,'withdrawal_id'=>$id];
    });
}

function clevo_pay_booking(mysqli $conn,int $user,int $booking,string $method,bool $usePoints,string $key): array
{
    if (!in_array($method,['cash','wallet','plan','visa'],true)) throw new ClevoSecurityException('invalid_payment_method',422);
    return clevo_db_idempotent($conn,$user,'booking.payment',$key,[$booking,$method,$usePoints],function()use($conn,$user,$booking,$method,$usePoints):array {
        $b=clevo_stmt($conn,'SELECT * FROM booking WHERE BookingID=? AND UserID=? FOR UPDATE','ii',[$booking,$user])->get_result()->fetch_assoc();
        if (!$b) throw new ClevoSecurityException('not_found',404);
        if ($b['Status']!=='Accepted' || !empty($b['Payment_Method'])) throw new ClevoSecurityException('payment_already_set_or_expired',409);
        if ($method==='cash'&&$b['Service_Location']!=='station') throw new ClevoSecurityException('not_allowed',422);
        // Lock all loyalty buckets before calculating a quote, not only before spending it.
        clevo_stmt($conn,'SELECT LoyaltyID FROM loyalty WHERE UserID=? ORDER BY LoyaltyID FOR UPDATE','i',[$user])->get_result()->fetch_all(MYSQLI_ASSOC);
        $owner=$b['OwnerIDSnapshot']??clevo_stmt($conn,'SELECT UserID FROM stations WHERE StationID=?','i',[(int)$b['StationID']])->get_result()->fetch_assoc()['UserID'];
        if ($method==='wallet') clevo_lock_wallets($conn,[$user,(int)$owner]);
        $preview=$method==='plan'?['points'=>0,'discount'=>0,'payable'=>(float)$b['Total_Price']]:clevoPreviewPointsDiscount($conn,$user,(float)$b['Total_Price'],$usePoints);
        if ($method==='visa'&&$preview['payable']>0) return ['status'=>'redirect_visa','use_points'=>$usePoints?1:0];
        if ($method==='plan') {
            $plan=clevoUsePlanForBooking($conn,$user);
            if (!$plan) throw new ClevoSecurityException('insufficient_plan',409);
            clevo_stmt($conn,'UPDATE booking SET UserPlanID=?,Plan_Washes_Used=1 WHERE BookingID=?','ii',[(int)$plan['id'],$booking]);
            $preview['payable']=0;
        } else {
            $preview=clevoApplyPointsDiscount($conn,$booking,$user,(float)$b['Total_Price'],$usePoints);
            if ($preview['payable']<=0) $method='points';
        }
        if ($method==='wallet') {
            $wallet=clevoEnsureWallet($conn,$user);
            clevo_wallet_entry($conn,(int)$wallet['WalletID'],clevo_db_money($preview['payable']),'debit',"booking:$booking:payment",$booking,"Booking payment #$booking");
        }
        clevo_stmt($conn,'UPDATE booking SET Payment_Method=? WHERE BookingID=?','si',[$method,$booking]);
        clevo_stmt($conn,'INSERT INTO payments (BookingID,UserID,Method,Status,Amount) VALUES (?,?,?,?,?)','iisss',[$booking,$user,$method,$method==='cash'?'pending':'recorded',clevo_db_decimal(clevo_db_money($preview['payable']))]);
        if (in_array($method,['wallet','points'],true)) clevoCreditOwnerForBooking($conn,$booking);
        clevo_db_audit($conn,$user,'booking.payment_recorded','booking',$booking);
        return ['status'=>'success'];
    });
}

function clevo_lock_wallets(mysqli $conn,array $users): void
{
    $users=array_values(array_unique(array_map('intval',$users))); sort($users);
    foreach($users as $user) {
        $wallet=clevoEnsureWallet($conn,$user);
        clevo_stmt($conn,'SELECT WalletID FROM wallet WHERE WalletID=? FOR UPDATE','i',[(int)$wallet['WalletID']])->get_result()->fetch_assoc();
    }
}

function clevo_complete_owner_booking(mysqli $conn,int $owner,int $booking): void
{
    clevo_db_atomic($conn,function()use($conn,$owner,$booking):void {
        $b=clevo_stmt($conn,"SELECT b.* FROM booking b JOIN stations s ON s.StationID=b.StationID WHERE b.BookingID=? AND s.UserID=? AND b.Service_Location IN ('station','home') FOR UPDATE",'ii',[$booking,$owner])->get_result()->fetch_assoc();
        if (!$b) throw new ClevoSecurityException('not_found',404);
        if ($b['Status']==='Completed') return;
        if (!in_array($b['Status'],['Accepted','In Progress'],true)||empty($b['Payment_Method'])) throw new ClevoSecurityException('payment_or_state_required',409);
        $receipt=clevo_stmt($conn,'SELECT Status FROM payments WHERE BookingID=?','i',[$booking])->get_result()->fetch_assoc();
        if ($receipt&&$receipt['Status']==='refunded') throw new ClevoSecurityException('payment_refunded',409);
        clevo_stmt($conn,"UPDATE booking SET Status='Completed' WHERE BookingID=?",'i',[$booking]);
        if (clevo_db_effect($conn,$booking,'completion_reward')) clevoAddLoyaltyPoints($conn,(int)$b['UserID'],(float)$b['Total_Price']);
        if ($b['Payment_Method']==='plan') clevoCreditOwnerForBooking($conn,$booking);
        clevo_stmt($conn,"UPDATE payments SET Status='recorded' WHERE BookingID=? AND Method='cash' AND Status='pending'",'i',[$booking]);
        clevo_db_audit($conn,$owner,'booking.completed','booking',$booking);
        clevoCreateNotification($conn,(int)$b['UserID'],'اكتمل الطلب','تم إكمال الخدمة بنجاح. يمكنك تقييمها الآن.','success',"order-details.php?id=$booking",'Order completed','Your service is complete.');
    });
}

function clevo_accept_owner_booking(mysqli $conn,int $owner,int $booking): void
{
    clevo_db_atomic($conn,function()use($conn,$owner,$booking):void {
        $b=clevo_stmt($conn,'SELECT b.* FROM booking b JOIN stations s ON s.StationID=b.StationID WHERE b.BookingID=? AND s.UserID=? AND s.ArchivedAt IS NULL FOR UPDATE','ii',[$booking,$owner])->get_result()->fetch_assoc();
        if (!$b) throw new ClevoSecurityException('not_found',404);
        if ($b['Status']==='Accepted') return;
        if ($b['Status']!=='Pending') throw new ClevoSecurityException('invalid_transition',409);
        clevo_stmt($conn,"UPDATE booking SET Status='Accepted',Accepted_At=UTC_TIMESTAMP(),notification_sent=0 WHERE BookingID=?",'i',[$booking]);
        $order=clevoBookingReference($b,$booking);
        $message=$b['Payment_Method']==='plan'?"Order #$order accepted using your plan.":"Order #$order accepted. Choose a payment method within 10 minutes.";
        $arabic=$b['Payment_Method']==='plan'?"تم قبول طلبك #$order وسيتم احتسابه من باقتك.":"تم قبول طلبك #$order. يرجى اختيار طريقة الدفع خلال 10 دقائق.";
        clevoCreateNotification($conn,(int)$b['UserID'],'تم قبول الطلب',$arabic,'success',"order-details.php?id=$booking",'Order accepted',$message);
        clevoCreateRoleNotification($conn,'Admin','قبول طلب من صاحب محطة',"تم قبول الطلب #$order.",'info','financial-reports.php','Order accepted by station owner',"Order #$order accepted.");
        clevo_db_audit($conn,$owner,'booking.accepted','booking',$booking);
    });
}

function clevo_record_driver_location(mysqli $conn,int $driver,int $booking,float $lat,float $lon): bool
{
    if (!is_finite($lat)||!is_finite($lon)||abs($lat)>90||abs($lon)>180) throw new ClevoSecurityException('invalid_coordinates',422);
    return clevo_db_atomic($conn,function()use($conn,$driver,$booking,$lat,$lon):bool {
        $b=clevo_stmt($conn,"SELECT b.BookingID FROM booking b JOIN user d ON d.UserID=b.DriverID AND d.StationID=b.StationID WHERE b.BookingID=? AND b.DriverID=? AND b.Status IN ('Accepted','Arrived','In Progress','Pickup','Washing','Returning') FOR UPDATE",'ii',[$booking,$driver])->get_result()->fetch_assoc();
        if (!$b) return false;
        clevo_stmt($conn,'UPDATE booking SET Driver_Lat=?,Driver_Lon=? WHERE BookingID=?','ddi',[$lat,$lon,$booking]);
        clevo_stmt($conn,'INSERT INTO tracking_records (BookingID,DriverID,Latitude,Longitude) VALUES (?,?,?,?)','iidd',[$booking,$driver,$lat,$lon]);
        return true;
    });
}

function clevo_archive_catalog(mysqli $conn,int $actor,string $kind,int $id): void
{
    clevo_db_atomic($conn,function()use($conn,$actor,$kind,$id):void {
        $account=clevo_account($conn,$actor,true); $role=$account?clevo_role($account['Role']):'';
        if (!$account||!clevo_account_active($conn,$account)) throw new ClevoSecurityException('forbidden',403);
        if ($kind==='service'&&$role==='owner') {
            $row=clevo_stmt($conn,'SELECT v.ServiceID FROM services v JOIN stations s ON s.StationID=v.StationID WHERE v.ServiceID=? AND s.UserID=? FOR UPDATE','ii',[$id,$actor])->get_result()->fetch_assoc();
            if (!$row) throw new ClevoSecurityException('not_found',404);
            clevo_stmt($conn,'UPDATE services SET ArchivedAt=COALESCE(ArchivedAt,UTC_TIMESTAMP(6)),is_station=0,is_home=0,is_pickup=0 WHERE ServiceID=?','i',[$id]);
        } elseif ($kind==='station'&&$role==='admin') {
            clevo_stmt($conn,"UPDATE stations SET ArchivedAt=COALESCE(ArchivedAt,UTC_TIMESTAMP(6)),Status='Closed',admin_status='archived' WHERE StationID=?",'i',[$id]);
        } elseif ($kind==='plan'&&$role==='admin') {
            clevo_stmt($conn,'UPDATE plans SET is_active=0 WHERE id=?','i',[$id]);
        } else throw new ClevoSecurityException('forbidden',403);
        clevo_db_audit($conn,$actor,'catalog.archived',$kind,$id);
    });
}
