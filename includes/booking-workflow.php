<?php
declare(strict_types=1);
require_once __DIR__.'/booking-notifications.php';

function clevo_booking_active_states(): array
{
    return ['Pending','Accepted','In Progress','Arrived','Pickup','Washing','Returning'];
}

function clevo_booking_driver_targets(string $from): array
{
    return ['Accepted'=>['In Progress','Arrived'],'In Progress'=>['Arrived','Pickup'],'Arrived'=>['Pickup'],'Pickup'=>['Washing'],'Washing'=>['Returning'],'Returning'=>['Completed']][$from]??[];
}

function clevo_booking_services(mysqli $db,int $station): array
{
    return clevo_stmt($db,'SELECT v.*,CASE WHEN v.Duration>0 THEN v.Duration ELSE s.DefaultServiceMinutes END AS Duration FROM services v JOIN stations s ON s.StationID=v.StationID WHERE v.StationID=? AND v.ArchivedAt IS NULL ORDER BY v.ServiceID','i',[$station])->get_result()->fetch_all(MYSQLI_ASSOC);
}

function clevo_booking_policy(array $s): array
{
    return ['capacity'=>$s['BookingCapacity'],'home_capacity'=>$s['HomeCapacity'],'default_service_minutes'=>(int)$s['DefaultServiceMinutes'],'delivery_buffer_minutes'=>(int)$s['DeliveryBufferMinutes'],'station_fee'=>$s['StationFee'],'home_fee'=>$s['HomeFee'],'delivery_fee'=>$s['DeliveryFee'],'pickup_shares_station_capacity'=>true];
}

function clevo_booking_availability(mysqli $db,int $station,array $input): array
{
    return clevo_db_atomic($db,function()use($db,$station,$input):array {
        $s=clevo_stmt($db,"SELECT * FROM stations WHERE StationID=? AND ArchivedAt IS NULL AND admin_status='approved' FOR UPDATE",'i',[$station])->get_result()->fetch_assoc();
        if (!$s) throw new ClevoSecurityException('not_found',404);
        $when=clevo_booking_datetime($input['date'],$input['time']);$mode=$input['mode'];
        $ids=$input['service_ids'];
        $services=array_values(array_filter(clevo_booking_services($db,$station),static fn($row)=>in_array((int)$row['ServiceID'],$ids,true)));
        if (!$ids||count($services)!==count($ids)) throw new ClevoSecurityException('service_unavailable',409);
        $quote=clevo_booking_schedule($s,$services,$mode,$when);
        $capacity=clevo_booking_capacity($db,$s,$mode,$when,$quote['end']);
        return ['available'=>$capacity['remaining']>0,'capacity'=>$capacity,'duration_minutes'=>$quote['duration'],'starts_at_utc'=>$when->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),'ends_at_utc'=>$quote['end']->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),'services_subtotal'=>clevo_db_decimal($quote['subtotal']),'additional_fee'=>clevo_db_decimal($quote['fee']),'total'=>clevo_db_decimal($quote['total']),'currency'=>'JOD'];
    });
}

function clevo_booking_datetime(string $date,string $time,?string $timezone=null): DateTimeImmutable
{
    if (strlen($time)===5) $time.=':00';
    $zone=new DateTimeZone($timezone??clevo_env('BOOKING_TIMEZONE','Asia/Amman'));
    $when=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',"$date $time",$zone);
    if (!$when || $when->format('Y-m-d H:i:s')!=="$date $time") throw new ClevoSecurityException('invalid_booking_time',422);
    return $when;
}

function clevo_booking_actor(mysqli $db,int $actor): array
{
    $account=clevo_account($db,$actor);
    if (!clevo_account_active($db,$account)) throw new ClevoSecurityException('forbidden',403);
    return $account;
}

function clevo_booking_lock(mysqli $db,int $id): array
{
    $ref=clevo_stmt($db,'SELECT StationID FROM booking WHERE BookingID=?','i',[$id])->get_result()->fetch_assoc();
    if (!$ref) throw new ClevoSecurityException('not_found',404);
    // All capacity/transition/reschedule/assignment writers acquire the station first.
    $station=clevo_stmt($db,'SELECT * FROM stations WHERE StationID=? FOR UPDATE','i',[(int)$ref['StationID']])->get_result()->fetch_assoc();
    $booking=clevo_stmt($db,'SELECT b.*,(SELECT COUNT(*) FROM booking_services bs WHERE bs.BookingID=b.BookingID) LegacyServiceCount FROM booking b WHERE BookingID=? FOR UPDATE','i',[$id])->get_result()->fetch_assoc();
    if (!$station || !$booking) throw new ClevoSecurityException('not_found',404);
    $booking['_station']=$station;
    return $booking;
}

function clevo_booking_intent(int $user,int $station,array $ids,string $mode,DateTimeImmutable $when,?float $lat,?float $lon,string $address,bool $plan): string
{
    sort($ids);
    // A payment preference does not make a second reservation. Station requests also
    // ignore unused customer GPS/address fields left over from a location-mode switch.
    if ($mode==='station') {$lat=null;$lon=null;$address='';}
    return hash('sha256',json_encode([$user,$station,$ids,$mode,$when->format('Y-m-d H:i:sP'),$lat,$lon,$address],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
}

function clevo_booking_schedule(array $station,array $services,string $mode,DateTimeImmutable $when,?int $fixedDuration=null): array
{
    if ($station['ArchivedAt']!==null || $station['admin_status']!=='approved' || $station['Status']!=='Active') throw new ClevoSecurityException('station_unavailable',409);
    $flag=['station'=>'Has_At_Station','home'=>'Has_Home_Service','delivery'=>'Has_Delivery'][$mode]??null;
    if (!$flag || !(int)$station[$flag]) throw new ClevoSecurityException('service_unavailable',409);
    $durations=[];$subtotal=0;
    foreach ($services as $service) {
        $kind=['station'=>'is_station','home'=>'is_home','delivery'=>'is_pickup'][$mode];
        if (isset($service[$kind]) && (!(int)$service[$kind] || $service['ArchivedAt']!==null)) throw new ClevoSecurityException('service_unavailable',409);
        $subtotal+=clevo_db_money($service['Price']);
        $duration=(int)($service['DurationMinutes']??$service['Duration']??0);
        $durations[(int)$service['ServiceID']]=$duration>0?$duration:(int)$station['DefaultServiceMinutes'];
    }
    $minutes=$fixedDuration??(array_sum($durations)+($mode==='delivery'?(int)$station['DeliveryBufferMinutes']:0));
    if ($minutes<1 || $minutes>1440) throw new ClevoSecurityException('invalid_service_duration',422);
    $end=$when->modify("+$minutes minutes");
    if ($when<=new DateTimeImmutable('now',$when->getTimezone())) throw new ClevoSecurityException('invalid_booking_time',422);
    $open=$station['OpeningTime'];$close=$station['ClosingTime'];
    if ($open!==null && $close!==null && $open!==$close) {
        $day=$when->format('Y-m-d');$windowStart=clevo_booking_datetime($day,$open,$when->getTimezone()->getName());
        $windowEnd=clevo_booking_datetime($day,$close,$when->getTimezone()->getName());
        if ($close<=$open) {
            if ($when<$windowStart) $windowStart=$windowStart->modify('-1 day');
            else $windowEnd=$windowEnd->modify('+1 day');
        }
        if ($when<$windowStart || $end>$windowEnd) throw new ClevoSecurityException('outside_opening_hours',422);
    }
    $fee=clevo_db_money($station[['station'=>'StationFee','home'=>'HomeFee','delivery'=>'DeliveryFee'][$mode]]);
    if ($subtotal+$fee>9999999999) throw new ClevoSecurityException('invalid_amount',422);
    return ['duration'=>$minutes,'end'=>$end,'subtotal'=>$subtotal,'fee'=>$fee,'total'=>$subtotal+$fee,'durations'=>$durations];
}

function clevo_booking_interval(array $booking,array $station): array
{
    try {
        $start=!empty($booking['ScheduledAtUtc'])?clevo_booking_datetime(substr($booking['ScheduledAtUtc'],0,10),substr($booking['ScheduledAtUtc'],11,8),'UTC'):clevo_booking_datetime((string)$booking['Booking_Date'],(string)$booking['Booking_Time'],$booking['BookingTimeZone']?:clevo_env('BOOKING_TIMEZONE','Asia/Amman'));
        $duration=(int)($booking['DurationMinutes']??0);
        if ($duration<=0) $duration=(int)$station['DefaultServiceMinutes']*max(1,(int)($booking['LegacyServiceCount']??1))+($booking['Service_Location']==='delivery'?(int)$station['DeliveryBufferMinutes']:0);
        $end=!empty($booking['ScheduledEndAtUtc'])?clevo_booking_datetime(substr($booking['ScheduledEndAtUtc'],0,10),substr($booking['ScheduledEndAtUtc'],11,8),'UTC'):$start->modify("+$duration minutes");
        if ($end<=$start) throw new RuntimeException('Invalid interval.');
        $from=$start->getTimestamp();$to=$end->getTimestamp();
        if (!empty($booking['DriverAcceptedAt'])) $from=min($from,clevo_booking_datetime(substr($booking['DriverAcceptedAt'],0,10),substr($booking['DriverAcceptedAt'],11,8),'UTC')->getTimestamp());
        // An unfinished service that has overrun has no known release time. Keep its
        // resource occupied until a legitimate terminal transition, rather than oversell it.
        if ($to<=time() && in_array($booking['Status'],['In Progress','Arrived','Pickup','Washing','Returning'],true)) $to=PHP_INT_MAX;
        return [$from,$to];
    } catch(Throwable $e) { throw new ClevoSecurityException('legacy_schedule_requires_reconciliation',409); }
}

function clevo_booking_capacity(mysqli $db,array $station,string $mode,DateTimeImmutable $start,DateTimeImmutable $end,int $ignore=0,?int $driver=null): array
{
    $pool=$mode==='home'?'home':'station';
    $capacity=(int)($station[$pool==='home'?'HomeCapacity':'BookingCapacity']??0);
    if ($capacity<1) throw new ClevoSecurityException('station_capacity_not_configured',409);
    $sql="SELECT b.*,(SELECT COUNT(*) FROM booking_services bs WHERE bs.BookingID=b.BookingID) LegacyServiceCount FROM booking b WHERE b.Status IN ('Pending','Accepted','In Progress','Arrived','Pickup','Washing','Returning') AND b.BookingID<>? AND ".($driver===null?'b.StationID=?':"b.DriverID=?").' ORDER BY b.BookingID FOR UPDATE';
    $rows=clevo_stmt($db,$sql,'ii',[$ignore,$driver??(int)$station['StationID']])->get_result()->fetch_all(MYSQLI_ASSOC);
    $from=$start->getTimestamp();$to=$end->getTimestamp();$events=[];
    foreach ($rows as $booking) {
        if ($driver===null && (($booking['Service_Location']==='home'?'home':'station')!==$pool)) continue;
        [$a,$b]=clevo_booking_interval($booking,$station);
        if ($a>=$to || $b<=$from) continue;
        $events[]=[max($a,$from),1];$events[]=[min($b,$to),-1];
    }
    usort($events,static fn($a,$b)=>$a[0]<=>$b[0]?:$a[1]<=>$b[1]);
    $current=0;$peak=0;
    foreach ($events as [, $delta]) {$current+=$delta;$peak=max($peak,$current);}
    return ['pool'=>$pool,'capacity'=>$driver===null?$capacity:1,'occupied'=>$peak,'remaining'=>max(0,($driver===null?$capacity:1)-$peak)];
}

function clevo_booking_assert_capacity(mysqli $db,array $station,string $mode,DateTimeImmutable $when,DateTimeImmutable $end,int $ignore=0,?int $driver=null): void
{
    $result=clevo_booking_capacity($db,$station,$mode,$when,$end,$ignore,$driver);
    if ($result['remaining']<1) throw new ClevoSecurityException($driver===null?'booking_capacity_exceeded':'driver_schedule_conflict',409);
}

function clevo_booking_event(mysqli $db,int $id,?string $from,string $to,?int $actor,string $role,string $reason): void
{
    if ($reason==='' || mb_strlen($reason)>500 || preg_match('/[\x00-\x1F\x7F]/u',$reason)) throw new ClevoSecurityException('invalid_transition_reason',422);
    clevo_stmt($db,'INSERT INTO booking_status_history (BookingID,FromStatus,ToStatus,ActorID,ActorRole,Reason) VALUES (?,?,?,?,?,?)','ississ',[$id,$from,$to,$actor,$role,$reason]);
}

function clevo_booking_is_expired(array $b): bool
{
    if ($b['Status']==='Pending') return strtotime($b['created_at'].' UTC')<=time()-600;
    return $b['Status']==='Accepted' && in_array($b['Service_Location'],['home','delivery'],true) && empty($b['Payment_Method']) && strtotime(($b['Accepted_At']??$b['created_at']).' UTC')<=time()-600;
}

function clevo_booking_transition(mysqli $db,?int $actor,int $id,string $target,string $reason): array
{
    return clevo_db_atomic($db,function()use($db,$actor,$id,$target,$reason):array {
        $b=clevo_booking_lock($db,$id);$s=$b['_station'];$from=(string)$b['Status'];
        $a=$actor!==null?clevo_booking_actor($db,$actor):null;$role=$a?clevo_role($a['Role']):'system';
        if ($role==='owner' && ((int)$s['UserID']!==$actor || $s['admin_status']!=='approved' || $s['ArchivedAt']!==null)) throw new ClevoSecurityException('not_found',404);
        if ($role==='customer' && (int)$b['UserID']!==$actor) throw new ClevoSecurityException('not_found',404);
        if ($role==='driver' && ((int)$b['DriverID']!==$actor || (int)$a['StationID']!==(int)$b['StationID'] || $b['Service_Location']!=='delivery' || $s['admin_status']!=='approved' || $s['ArchivedAt']!==null)) throw new ClevoSecurityException('invalid_transition',409);
        if($role==='driver'&&($reason!=='logistics_progress'||(int)$b['DeliveryWorkflowVersion']!==1||clevo_delivery_booking_status($b['DeliveryStage']??'')!==$target))throw new ClevoSecurityException('invalid_transition',409);
        $valid=match($role) {
            'owner'=>($from==='Pending' && in_array($target,['Accepted','Rejected'],true)) || (in_array($from,['Accepted','In Progress'],true) && $target==='Completed' && in_array($b['Service_Location'],['station','home'],true)),
            'customer'=>$from==='Pending' && $target==='Cancelled',
            'driver'=>in_array($target,clevo_booking_driver_targets($from),true),
            'admin'=>in_array($from,['Pending','Accepted'],true) && $target==='Cancelled' && empty($b['DriverAcceptedAt']),
            'system'=>($target==='Cancelled_Timeout' && in_array($reason,['request_expired','payment_expired'],true) && clevo_booking_is_expired($b)) || ($target==='Cancelled'&&$reason==='provider_refund_confirmed'&&in_array($from,['Pending','Accepted'],true)&&empty($b['DriverAcceptedAt'])&&clevo_finance_verified_refund($db,$id)),
            default=>false,
        };
        // Preserve the web owner's existing safe repeated accept/complete/reject behavior.
        if ($role==='owner' && $from===$target && in_array($target,['Accepted','Completed','Rejected'],true)) return ['status'=>'success'];
        if (!$valid) throw new ClevoSecurityException('invalid_transition',409);
        if ($target==='Accepted') {
            if (clevo_booking_is_expired($b)) throw new ClevoSecurityException('booking_expired',409);
            if ($s['Status']!=='Active') throw new ClevoSecurityException('station_unavailable',409);
            if (!empty($b['ScheduledEndAtUtc']) && strtotime($b['ScheduledEndAtUtc'].' UTC')<=time()) throw new ClevoSecurityException('booking_slot_elapsed',409);
        }
        if ($role==='driver' && $from==='Accepted') {
            [$scheduledStart,$scheduledEnd]=clevo_booking_interval($b,$s);
            if ($scheduledEnd<=time()) throw new ClevoSecurityException('booking_slot_elapsed',409);
            $actualStart=new DateTimeImmutable('@'.min(time(),$scheduledStart));$end=new DateTimeImmutable('@'.$scheduledEnd);
            clevo_booking_assert_capacity($db,$s,'delivery',$actualStart,$end,$id);
            clevo_booking_assert_capacity($db,$s,'delivery',$actualStart,$end,$id,$actor);
        }
        if ($role==='driver' || $target==='Completed') {
            if (empty($b['Payment_Method'])) throw new ClevoSecurityException('payment_required',409);
            clevo_assert_booking_payment($db,$b,$role==='owner');
        }
        $refund=null;
        if (in_array($target,['Cancelled','Rejected','Cancelled_Timeout'],true)) $refund=clevoRefundBookingPayment($db,$id,$reason);
        clevo_stmt($db,'UPDATE booking SET Status=? WHERE BookingID=? AND Status=?','sis',[$target,$id,$from]);
        if ($target==='Accepted') clevo_stmt($db,'UPDATE booking SET Accepted_At=UTC_TIMESTAMP(),notification_sent=0 WHERE BookingID=?','i',[$id]);
        if ($role==='driver') clevo_stmt($db,'UPDATE booking SET DriverAcceptedAt=COALESCE(DriverAcceptedAt,UTC_TIMESTAMP(6)) WHERE BookingID=?','i',[$id]);
        if (!in_array($target,clevo_booking_active_states(),true)) {
            clevo_delivery_stop($db,$b,$actor,$role,$target);
            clevo_stmt($db,'UPDATE booking SET notification_sent=1,DriverAccessCode=NULL,DriverAccessCodeHash=NULL,DriverAccessCodeExpiresAt=NULL WHERE BookingID=?','i',[$id]);
            clevo_stmt($db,'UPDATE driver_assignments SET EndedAt=UTC_TIMESTAMP(6) WHERE BookingID=? AND EndedAt IS NULL','i',[$id]);
            clevo_stmt($db,'UPDATE auth_sessions SET RevokedAt=UTC_TIMESTAMP() WHERE DriverBookingID=?','i',[$id]);
        }
        if ($target==='Completed') {
            if (clevo_db_effect($db,$id,'completion_reward')) clevoAddLoyaltyPoints($db,(int)$b['UserID'],(float)$b['Total_Price']);
            if ($b['Payment_Method']==='plan') clevoCreditOwnerForBooking($db,$id);
            clevo_stmt($db,"UPDATE payments SET Status='recorded' WHERE BookingID=? AND Method='cash' AND Status='pending'",'i',[$id]);
            clevo_finance_complete($db,$b);
        }
        clevo_booking_event($db,$id,$from,$target,$actor,$role,$reason);
        clevo_db_audit($db,$actor,'booking.status_changed','booking',$id);
        clevo_booking_status_notifications($db,$b,$target,$role);
        return ['status'=>'success','message'=>strtolower($target),'refund'=>$refund];
    });
}

function clevo_booking_status_notifications(mysqli $db,array $b,string $target,string $role): void
{
    $id=(int)$b['BookingID'];$order=clevoBookingReference($b,$id);
    $ar=['Accepted'=>'تم قبول الطلب','Rejected'=>'تم رفض الطلب','Cancelled'=>'تم إلغاء الحجز','Cancelled_Timeout'=>'انتهت مهلة الطلب','In Progress'=>'السائق في الطريق','Arrived'=>'وصل السائق','Pickup'=>'تم استلام السيارة','Washing'=>'جاري الغسيل','Returning'=>'جاري إرجاع السيارة','Completed'=>'اكتمل الطلب'];
    $title=$ar[$target]??'تحديث الطلب';$en='Order '.$target;
    clevoCreateNotification($db,(int)$b['UserID'],$title,"الطلب #$order: $title",'info',"order-details.php?id=$id",$en,"Order #$order: $target.");
    if (in_array($role,['customer','system','admin'],true)) clevoCreateNotification($db,(int)$b['_station']['UserID'],$title,"الطلب #$order: $title",'info','incoming-orders.php',$en,"Order #$order: $target.");
    if (!empty($b['DriverID']) && $role!=='driver') clevoCreateNotification($db,(int)$b['DriverID'],$title,"الطلب #$order: $title",'info','driver-dashboard.php',$en,"Order #$order: $target.");
    if ($role==='owner' && in_array($target,['Accepted','Rejected'],true)) clevoCreateRoleNotification($db,'Admin',$title,"الطلب #$id: $title",$target==='Rejected'?'warning':'info','financial-reports.php',$target==='Rejected'?'Order rejected by station owner':'Order accepted by station owner',$target==='Rejected'?"Station owner rejected order #$id.":"Order #$order accepted.");
}
