<?php
declare(strict_types=1);
require_once __DIR__.'/booking-helpers.php';
require_once __DIR__.'/booking-workflow.php';
require_once __DIR__.'/finance.php';
require_once __DIR__.'/logistics.php';
require_once __DIR__.'/notifications.php';

function clevo_create_booking(mysqli $conn,int $user,int $station,array $input,string $key): array
{
    $ids=$input['service_ids']??[];
    if (!is_array($ids) || count($ids)<1 || count($ids)>30) throw new ClevoSecurityException('invalid_services',422);
    foreach ($ids as $id) if (!is_int($id) && !(is_string($id)&&ctype_digit($id))) throw new ClevoSecurityException('invalid_services',422);
    $ids=array_values(array_unique(array_map('intval',$ids))); sort($ids);
    $mode=(string)($input['service_location']??''); $date=(string)($input['booking_date']??''); $time=(string)($input['booking_time']??'');
    if (!in_array($mode,['station','home','delivery'],true)) throw new ClevoSecurityException('invalid_location',422);
    if (strlen($time)===5) $time.=':00';
    $when=clevo_booking_datetime($date,$time);$zone=$when->getTimezone();
    $lat=$input['latitude']??null; $lon=$input['longitude']??null;
    if ($mode!=='station' && (!is_numeric($lat)||!is_numeric($lon)||abs((float)$lat)>90||abs((float)$lon)>180)) throw new ClevoSecurityException('location_required',422);
    $lat=is_numeric($lat)?(float)$lat:null; $lon=is_numeric($lon)?(float)$lon:null;
    if (($lat!==null&&(!is_finite($lat)||abs($lat)>90))||($lon!==null&&(!is_finite($lon)||abs($lon)>180))) throw new ClevoSecurityException('invalid_coordinates',422);
    if ($mode==='station') {$lat=null;$lon=null;}
    else {$lat=round($lat,8);$lon=round($lon,8);}
    $address=clevo_clean_string($input['address_details']??'',2000); $usePlan=filter_var($input['use_plan']??false,FILTER_VALIDATE_BOOLEAN);
    $body=[$station,$ids,$mode,$date,$time,$lat,$lon,$address,$usePlan];
    return clevo_db_idempotent($conn,$user,'booking.create',$key,$body,function()use($conn,$user,$station,$ids,$mode,$date,$time,$when,$zone,$lat,$lon,$address,$usePlan):array {
        if ($when<=new DateTimeImmutable('now',$zone)) throw new ClevoSecurityException('invalid_booking_time',422);
        $s=clevo_stmt($conn,"SELECT * FROM stations WHERE StationID=? AND ArchivedAt IS NULL AND admin_status='approved' AND Status='Active' FOR UPDATE",'i',[$station])->get_result()->fetch_assoc();
        if (!$s) throw new ClevoSecurityException('station_unavailable',409);
        $account=clevo_booking_actor($conn,$user);
        if (clevo_role($account['Role'])!=='customer') throw new ClevoSecurityException('forbidden',403);
        cancelExpiredBookings($conn,null,$station);
        $fingerprint=clevo_booking_intent($user,$station,$ids,$mode,$when,$lat,$lon,$address,$usePlan);
        $existing=clevo_stmt($conn,'SELECT BookingID,Order_Number,Payment_Method FROM booking WHERE ActiveRequestFingerprint=? FOR UPDATE','s',[$fingerprint])->get_result()->fetch_assoc();
        if ($existing) return ['status'=>'success','booking_id'=>(int)$existing['BookingID'],'order_number'=>$existing['Order_Number'],'payment_method'=>$existing['Payment_Method']];
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $services=clevo_stmt($conn,"SELECT * FROM services WHERE StationID=? AND ServiceID IN ($placeholders) AND ArchivedAt IS NULL ORDER BY ServiceID FOR UPDATE",str_repeat('i',count($ids)+1),array_merge([$station],$ids))->get_result()->fetch_all(MYSQLI_ASSOC);
        if (count($services)!==count($ids)) throw new ClevoSecurityException('service_unavailable',409);
        $quote=clevo_booking_schedule($s,$services,$mode,$when);$total=$quote['total'];
        clevo_booking_assert_capacity($conn,$s,$mode,$when,$quote['end']);
        $plan=$usePlan?clevoUsePlanForBooking($conn,$user,['service_ids'=>$ids,'mode'=>$mode,'total_minor'=>$total*10]):null;
        if ($usePlan&&!$plan) throw new ClevoSecurityException('plan_unavailable',409);
        $method=$usePlan?'plan':''; $planId=$plan?(int)$plan['id']:null; $used=$usePlan?1:0;
        $order='C'.strtoupper(bin2hex(random_bytes(9))); $utc=$when->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        clevo_stmt($conn,"INSERT INTO booking (Order_Number,UserID,StationID,ServiceID,Service_Location,Latitude,Longitude,Address_Details,Booking_Date,Booking_Time,Total_Price,Admin_Commission,Payment_Method,UserPlanID,Plan_Washes_Used,ScheduledAtUtc,BookingTimeZone) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",'siiisddssssssiiss',[$order,$user,$station,$ids[0],$mode,$lat,$lon,$address,$date,$time,clevo_db_decimal($total),'0.00',$method,$planId,$used,$utc,$zone->getName()]);
        $booking=(int)$conn->insert_id;
        clevo_stmt($conn,'UPDATE booking SET FinancialVersion=1 WHERE BookingID=?','i',[$booking]);
        clevo_finance_snapshot($conn,$booking,$total*10);
        $end=$quote['end']->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        clevo_stmt($conn,'UPDATE booking SET OwnerIDSnapshot=?,DurationMinutes=?,ScheduledEndAtUtc=?,ServicesSubtotal=?,AdditionalFee=?,RequestFingerprint=? WHERE BookingID=?','iissssi',[(int)$s['UserID'],$quote['duration'],$end,clevo_db_decimal($quote['subtotal']),clevo_db_decimal($quote['fee']),$fingerprint,$booking]);
        foreach ($services as &$service) $service['DurationMinutes']=$quote['durations'][(int)$service['ServiceID']];unset($service);
        clevoSaveBookingServices($conn,$booking,$services);
        if($plan)clevo_finance_plan_reserve($conn,$plan,$booking);
        clevo_booking_event($conn,$booking,null,'Pending',$user,'customer','booking_requested');
        if ($plan) clevo_stmt($conn,"INSERT INTO payments (BookingID,UserID,Method,Status,Amount) VALUES (?,?,'plan','recorded',0)",'ii',[$booking,$user]);
        clevoCreateNotification($conn,(int)$s['UserID'],'طلب جديد',"وصلك طلب جديد #$order بتاريخ $date الساعة $time.",'info',"incoming-orders.php?id=$booking",'New order',"Order #$order on $date at $time.");
        clevo_db_audit($conn,$user,'booking.created','booking',$booking);
        return ['status'=>'success','booking_id'=>$booking,'order_number'=>$order,'payment_method'=>$method];
    });
}

function clevo_wallet_entry(mysqli $conn,int $wallet,int $cents,string $type,string $key,?int $booking,string $description): void
{
    clevo_finance_require_legacy_fixture($conn);
    if ($cents===0) return;
    if ($cents<0 || !in_array($type,['credit','debit'],true)) throw new LogicException('Invalid ledger mutation.');
    $amount=clevo_db_decimal($cents);
    $sql=$type==='credit'?'UPDATE wallet SET Balance=Balance+? WHERE WalletID=? AND Balance>=0 AND Balance<=99999999.99-?':'UPDATE wallet SET Balance=Balance-? WHERE WalletID=? AND Balance>=?';
    $stmt=clevo_stmt($conn,$sql,'sis',[$amount,$wallet,$amount]);
    if ($stmt->affected_rows!==1) throw new ClevoSecurityException($type==='credit'?'wallet_balance_limit':'insufficient',409);
    clevo_stmt($conn,'INSERT INTO wallet_transactions (WalletID,Amount,TransactionType,Description_ar,Description_en,OperationKey,BookingID) VALUES (?,?,?,?,?,?,?)','isssssi',[$wallet,$amount,$type,$description,$description,$key,$booking]);
    clevo_finance_post($conn,'legacy:'.$key,'legacy_wallet','legacy_fixture',['legacy_wallet:'.$wallet=>($type==='credit'?-$cents:$cents)*10,'legacy_control'=>($type==='credit'?$cents:-$cents)*10],['booking'=>$booking]);
}

function clevo_redeem_loyalty_wallet(mysqli $conn,int $user,int $points,string $key):array
{
    clevo_finance_require_legacy_fixture($conn);
    if(!in_array($points,[40,200],true))throw new ClevoSecurityException('invalid_points',422);
    return clevo_db_idempotent($conn,$user,'loyalty.wallet',$key,[$points],function()use($conn,$user,$points,$key):array {
        $actor=clevo_booking_actor($conn,$user);
        if(clevo_role($actor['Role'])!=='customer')throw new ClevoSecurityException('forbidden',403);
        $wallet=clevoEnsureWallet($conn,$user);
        if(!clevoSpendLoyaltyPoints($conn,$user,$points))throw new ClevoSecurityException('insufficient_points',409);
        clevo_wallet_entry($conn,(int)$wallet['WalletID'],intdiv($points*100,40),'credit','loyalty:'.hash('sha256',$user.':'.$key),null,'Synthetic loyalty redemption');
        return ['redeemed'=>true];
    });
}

function clevo_purchase_plan(mysqli $conn,int $user,int $plan,string $key): array
{
    clevo_finance_require_legacy_fixture($conn);
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
    clevo_finance_require_legacy_fixture($conn,'provider_settlements_not_approved');
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
    if($method==='visa')throw new ClevoSecurityException('payment_gateway_not_configured',503);
    if($method==='wallet'||$usePoints)clevo_finance_require_legacy_fixture($conn);
    return clevo_db_idempotent($conn,$user,'booking.payment',$key,[$booking,$method,$usePoints],function()use($conn,$user,$booking,$method,$usePoints):array {
        $b=clevo_stmt($conn,'SELECT * FROM booking WHERE BookingID=? AND UserID=? FOR UPDATE','ii',[$booking,$user])->get_result()->fetch_assoc();
        if (!$b) throw new ClevoSecurityException('not_found',404);
        if ($b['Status']!=='Accepted' || !empty($b['Payment_Method'])) throw new ClevoSecurityException('payment_already_set_or_expired',409);
        clevo_payment_window($b);
        clevo_payment_open_booking($conn,$booking);
        if ($method==='cash'&&$b['Service_Location']!=='station') throw new ClevoSecurityException('not_allowed',422);
        // Lock all loyalty buckets before calculating a quote, not only before spending it.
        clevo_stmt($conn,'SELECT LoyaltyID FROM loyalty WHERE UserID=? ORDER BY LoyaltyID FOR UPDATE','i',[$user])->get_result()->fetch_all(MYSQLI_ASSOC);
        $owner=$b['OwnerIDSnapshot']??clevo_stmt($conn,'SELECT UserID FROM stations WHERE StationID=?','i',[(int)$b['StationID']])->get_result()->fetch_assoc()['UserID'];
        if ($method==='wallet') clevo_lock_wallets($conn,[$user,(int)$owner]);
        $preview=$method==='plan'?['points'=>0,'discount'=>0,'payable'=>(float)$b['Total_Price']]:clevoPreviewPointsDiscount($conn,$user,(float)$b['Total_Price'],$usePoints);
        if ($method==='plan') {
            $serviceIDs=array_map('intval',array_column(clevo_stmt($conn,'SELECT ServiceID FROM booking_services WHERE BookingID=?','i',[$booking])->get_result()->fetch_all(MYSQLI_ASSOC),'ServiceID'));
            if(!$serviceIDs)$serviceIDs=[(int)$b['ServiceID']];
            $plan=clevoUsePlanForBooking($conn,$user,['service_ids'=>$serviceIDs,'mode'=>$b['Service_Location'],'total_minor'=>clevo_finance_minor($b['Total_Price'])]);
            if (!$plan) throw new ClevoSecurityException('insufficient_plan',409);
            clevo_stmt($conn,'UPDATE booking SET UserPlanID=?,Plan_Washes_Used=1 WHERE BookingID=?','ii',[(int)$plan['id'],$booking]);
            clevo_finance_plan_reserve($conn,$plan,$booking);
            $preview['payable']=0;
        } else {
            $preview=clevoApplyPointsDiscount($conn,$booking,$user,(float)$b['Total_Price'],$usePoints);
            if ($preview['payable']<=0) {clevo_finance_require_legacy_fixture($conn,'promotion_funding_not_approved');$method='points';}
        }
        if($method!=='plan')clevo_finance_snapshot($conn,$booking,clevo_finance_minor($preview['payable']));
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
    clevo_booking_transition($conn,$owner,$booking,'Completed','owner_completed');
}

function clevo_assert_booking_payment(mysqli $conn,array $booking,bool $cashCollection=false):void
{
    $id=(int)$booking['BookingID'];$method=(string)$booking['Payment_Method'];
    $p=clevo_stmt($conn,'SELECT UserID,Method,Status,Amount,Currency,FundingOrderID,VerificationKind,VerifiedAt FROM payments WHERE BookingID=?','i',[$id])->get_result()->fetch_assoc();
    if($p&&$p['Status']==='refunded')throw new ClevoSecurityException('payment_refunded',409);
    if($p&&(int)$p['UserID']!==(int)$booking['UserID'])throw new ClevoSecurityException('legacy_payment_requires_reconciliation',409);
    if($p&&$p['Currency']!=='JOD')throw new ClevoSecurityException('legacy_payment_requires_reconciliation',409);
    if($method==='plan'){
        if((int)$booking['Plan_Washes_Used']<=0||!(int)$booking['UserPlanID']||!clevo_stmt($conn,'SELECT id FROM user_plans WHERE id=? AND user_id=?','ii',[(int)$booking['UserPlanID'],(int)$booking['UserID']])->get_result()->fetch_assoc())throw new ClevoSecurityException('legacy_payment_requires_reconciliation',409);
        if($p&&($p['Method']!=='plan'||$p['Status']!=='recorded'))throw new ClevoSecurityException('payment_required',409);
        if($p&&clevo_db_money($p['Amount'])!==0)throw new ClevoSecurityException('legacy_payment_requires_reconciliation',409);
        clevo_finance_assert_plan($conn,$booking);
        return;
    }
    if($method==='cash'&&$cashCollection&&$booking['Service_Location']==='station'){
        if(!$p)clevo_stmt($conn,"INSERT INTO payments (BookingID,UserID,Method,Status,Amount) VALUES (?,?,'cash','recorded',?)",'iis',[$id,(int)$booking['UserID'],clevo_db_decimal(clevo_db_money(clevoBookingPayableAmount($booking)))]);
        elseif($p['Method']!=='cash')throw new ClevoSecurityException('payment_required',409);
        elseif(clevo_db_money($p['Amount'])!==clevo_db_money(clevoBookingPayableAmount($booking)))throw new ClevoSecurityException('legacy_payment_requires_reconciliation',409);
        return;
    }
    if(!$p)throw new ClevoSecurityException('legacy_payment_requires_reconciliation',409);
    clevo_finance_assert_payment($conn,$booking,$p);
    if(!in_array($method,['wallet','points','visa'],true)||$p['Method']!==$method||$p['Status']!=='recorded')throw new ClevoSecurityException('payment_required',409);
    if(clevo_db_money($p['Amount'])!==clevo_db_money(clevoBookingPayableAmount($booking)))throw new ClevoSecurityException('legacy_payment_requires_reconciliation',409);
}

function clevo_accept_owner_booking(mysqli $conn,int $owner,int $booking): void
{
    clevo_booking_transition($conn,$owner,$booking,'Accepted','owner_accepted');
}

function clevo_payment_window(array $booking):void
{
    if(in_array($booking['Service_Location'],['home','delivery'],true)&&strtotime(($booking['Accepted_At']??$booking['created_at']).' UTC')<time()-600)throw new ClevoSecurityException('payment_expired',409);
}

function clevo_record_driver_location(mysqli $conn,int $driver,int $booking,float $lat,float $lon,array $metadata=[]): bool
{
    try{clevo_delivery_location($conn,$driver,$booking,['latitude'=>$lat,'longitude'=>$lon]+$metadata,true);return true;}
    catch(ClevoSecurityException $e){if($e->httpStatus===404)return false;throw $e;}
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
