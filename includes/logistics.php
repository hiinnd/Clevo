<?php
declare(strict_types=1);

function clevo_delivery_stages():array
{
    return ['assigned','accepted','heading_pickup','arrived_pickup','vehicle_collected','arrived_station','service_completed','returning_vehicle','delivered'];
}
function clevo_delivery_booking_status(string $stage):string
{
    return ['assigned'=>'Accepted','accepted'=>'Accepted','heading_pickup'=>'In Progress','arrived_pickup'=>'Arrived','vehicle_collected'=>'Pickup','arrived_station'=>'Washing','service_completed'=>'Washing','returning_vehicle'=>'Returning','delivered'=>'Completed'][$stage]??'';
}
function clevo_delivery_assignment(mysqli $db,array $b,bool $lock=false):array
{
    $r=clevo_stmt($db,'SELECT * FROM driver_assignments WHERE BookingID=? AND DriverID=? AND StationID=? AND EndedAt IS NULL'.($lock?' FOR UPDATE':''),'iii',[(int)$b['BookingID'],(int)$b['DriverID'],(int)$b['StationID']])->get_result()->fetch_assoc();
    if(!$r)throw new ClevoSecurityException('assignment_required',409);return $r;
}
function clevo_delivery_event(mysqli $db,array $b,array $assignment,?string $from,string $to,?int $actor,string $role,string $reason):void
{
    clevo_stmt($db,'INSERT INTO delivery_events (BookingID,AssignmentID,FromStage,ToStage,ActorID,ActorRole,Reason) VALUES (?,?,?,?,?,?,?)','iississ',[(int)$b['BookingID'],(int)$assignment['AssignmentID'],$from,$to,$actor,$role,$reason]);
}
function clevo_delivery_driver(mysqli $db,array $b,int $driver):array
{
    $a=clevo_booking_actor($db,$driver);$s=$b['_station'];
    if(clevo_role($a['Role'])!=='driver'||(int)$b['DriverID']!==$driver||(int)$a['StationID']!==(int)$b['StationID']||$b['Service_Location']!=='delivery'||$s['ArchivedAt']!==null||$s['admin_status']!=='approved')throw new ClevoSecurityException('not_found',404);
    return $a;
}
function clevo_delivery_advance(mysqli $db,int $actor,int $booking,string $target,bool $confirmed=false):array
{
    return clevo_db_atomic($db,function()use($db,$actor,$booking,$target,$confirmed):array{
        $b=clevo_booking_lock($db,$booking);$a=clevo_booking_actor($db,$actor);$role=clevo_role($a['Role']);
        if($role==='driver')clevo_delivery_driver($db,$b,$actor);
        elseif($role!=='owner'||(int)$b['_station']['UserID']!==$actor||$b['_station']['ArchivedAt']!==null||$b['_station']['admin_status']!=='approved')throw new ClevoSecurityException('not_found',404);
        if((int)$b['DeliveryWorkflowVersion']!==1)throw new ClevoSecurityException('logistics_adoption_required',409);
        $assignment=clevo_delivery_assignment($db,$b,true);$from=$b['DeliveryStage'];$stages=clevo_delivery_stages();$position=array_search($from,$stages,true);
        if($target===$from)return ['booking_id'=>$booking,'status'=>$b['Status'],'delivery_stage'=>$from];
        if(!in_array($b['Status'],clevo_booking_active_states(),true)||$position===false||($stages[$position+1]??null)!==$target)throw new ClevoSecurityException('invalid_transition',409);
        if(($target==='service_completed')!==($role==='owner'))throw new ClevoSecurityException('forbidden_transition',403);
        if(in_array($target,['vehicle_collected','delivered'],true)&&!$confirmed)throw new ClevoSecurityException('handoff_confirmation_required',409);
        if(empty($b['Payment_Method']))throw new ClevoSecurityException('payment_required',409);
        clevo_assert_booking_payment($db,$b);
        if($target==='accepted'){
            clevo_stmt($db,'SELECT UserID FROM user WHERE UserID=? FOR UPDATE','i',[$actor])->get_result()->fetch_assoc();
            if(clevo_stmt($db,"SELECT BookingID FROM booking WHERE DriverID=? AND BookingID<>? AND Status IN ('Accepted','In Progress','Arrived','Pickup','Washing','Returning') AND (DriverAcceptedAt IS NOT NULL OR Status<>'Accepted') LIMIT 1 FOR UPDATE",'ii',[$actor,$booking])->get_result()->fetch_assoc())throw new ClevoSecurityException('driver_has_active_job',409);
            [$start,$end]=clevo_booking_interval($b,$b['_station']);if($end<=time())throw new ClevoSecurityException('booking_slot_elapsed',409);
            $at=new DateTimeImmutable('@'.min(time(),$start));$until=new DateTimeImmutable('@'.$end);
            clevo_booking_assert_capacity($db,$b['_station'],'delivery',$at,$until,$booking);
            clevo_booking_assert_capacity($db,$b['_station'],'delivery',$at,$until,$booking,$actor);
            clevo_stmt($db,'UPDATE driver_assignments SET AcceptedAt=UTC_TIMESTAMP(6) WHERE AssignmentID=?','i',[(int)$assignment['AssignmentID']]);
            clevo_stmt($db,'UPDATE booking SET DriverAcceptedAt=UTC_TIMESTAMP(6) WHERE BookingID=?','i',[$booking]);
        }
        $status=clevo_delivery_booking_status($target);
        clevo_stmt($db,'UPDATE booking SET DeliveryStage=? WHERE BookingID=?','si',[$target,$booking]);
        if($status!==$b['Status'])clevo_booking_transition($db,$actor,$booking,$status,'logistics_progress');
        if(in_array($target,['arrived_station','service_completed'],true))clevo_stmt($db,'UPDATE booking SET Driver_Lat=NULL,Driver_Lon=NULL WHERE BookingID=?','i',[$booking]);
        clevo_delivery_event($db,$b,$assignment,$from,$target,$actor,$role,'stage_confirmed');
        // Booking transitions deliver their existing messages; stage notifications also
        // inform the station, including driver-originated and same-booking-state events.
        foreach(array_unique([(int)$b['UserID'],(int)$b['_station']['UserID'],(int)$b['DriverID']]) as $recipient)if($recipient!==$actor)clevoCreateNotification($db,$recipient,'تحديث الاستلام والتسليم','تم تحديث مرحلة المهمة.','info',$recipient===(int)$b['UserID']?"order-details.php?id=$booking":($recipient===(int)$b['DriverID']?'driver-dashboard.php':'incoming-orders.php'),'Delivery update','Delivery stage: '.$target.'. Open CLEVO for current status.');
        return ['booking_id'=>$booking,'status'=>$status,'delivery_stage'=>$target];
    });
}
function clevo_delivery_legacy_transition(mysqli $db,int $driver,int $booking,string $target):void
{
    $b=clevo_booking_lock($db,$booking);clevo_delivery_driver($db,$b,$driver);
    if(!in_array($b['Status'],clevo_booking_active_states(),true))throw new ClevoSecurityException('invalid_transition',409);
    $stage=['In Progress'=>'heading_pickup','Arrived'=>'arrived_pickup','Pickup'=>'vehicle_collected','Washing'=>'arrived_station','Returning'=>'returning_vehicle','Completed'=>'delivered'][$target]??'';
    if($stage==='')throw new ClevoSecurityException('invalid_transition',409);
    // Compatibility adapters retain their URLs and status vocabulary, with the same
    // strict progression and customer handoff requirements as the mobile API.
    if($b['DeliveryStage']==='assigned'&&$stage==='heading_pickup')clevo_delivery_advance($db,$driver,$booking,'accepted');
    clevo_delivery_advance($db,$driver,$booking,$stage);
}
function clevo_delivery_adopt(mysqli $db,int $owner,int $booking):array
{
    return clevo_db_atomic($db,function()use($db,$owner,$booking):array{
        $b=clevo_booking_lock($db,$booking);clevo_market_station($db,$owner,(int)$b['StationID']);
        if($b['Service_Location']!=='delivery'||!in_array($b['Status'],['Accepted','In Progress','Arrived','Pickup','Washing','Returning'],true))throw new ClevoSecurityException('invalid_assignment_state',409);
        if((int)$b['DeliveryWorkflowVersion']===1)return ['adopted'=>true];
        clevo_delivery_driver($db,$b,(int)$b['DriverID']);
        clevo_stmt($db,'SELECT UserID FROM user WHERE UserID=? FOR UPDATE','i',[(int)$b['DriverID']])->get_result()->fetch_assoc();
        $r=clevo_stmt($db,'SELECT * FROM driver_assignments WHERE BookingID=? AND EndedAt IS NULL FOR UPDATE','i',[$booking])->get_result()->fetch_assoc();
        if($r&&((int)$r['DriverID']!==(int)$b['DriverID']||(int)$r['StationID']!==(int)$b['StationID']))throw new ClevoSecurityException('assignment_requires_reconciliation',409);
        if(!$r){clevo_stmt($db,'INSERT INTO driver_assignments (BookingID,DriverID,StationID,AssignedBy) VALUES (?,?,?,?)','iiii',[$booking,(int)$b['DriverID'],(int)$b['StationID'],$owner]);$r=clevo_delivery_assignment($db,$b,true);}
        $stage=['Accepted'=>empty($b['DriverAcceptedAt'])?'assigned':'accepted','In Progress'=>'heading_pickup','Arrived'=>'arrived_pickup','Pickup'=>'vehicle_collected','Washing'=>'arrived_station','Returning'=>'returning_vehicle'][$b['Status']];
        if($stage!=='assigned')clevo_stmt($db,'UPDATE driver_assignments SET AcceptedAt=COALESCE(AcceptedAt,UTC_TIMESTAMP(6)) WHERE AssignmentID=?','i',[(int)$r['AssignmentID']]);
        clevo_stmt($db,'UPDATE booking SET DeliveryWorkflowVersion=1,DeliveryStage=?,Driver_Lat=NULL,Driver_Lon=NULL WHERE BookingID=?','si',[$stage,$booking]);
        clevo_delivery_event($db,$b,$r,null,$stage,$owner,'owner','legacy_stage_adopted_not_historical_proof');
        return ['adopted'=>true,'delivery_stage'=>$stage];
    });
}
function clevo_delivery_issue_confirmation(mysqli $db,int $customer,int $booking,string $kind,string $key):array
{
    if(!in_array($kind,['pickup','delivery'],true))throw new ClevoSecurityException('invalid_confirmation_kind',422);
    $result=clevo_db_idempotent($db,$customer,'delivery.issue',$key,[$booking,$kind],function()use($db,$customer,$booking,$kind):array{
        $b=clevo_booking_lock($db,$booking);$a=clevo_booking_actor($db,$customer);
        if(clevo_role($a['Role'])!=='customer'||(int)$b['UserID']!==$customer)throw new ClevoSecurityException('not_found',404);
        if($b['DeliveryStage']!==($kind==='pickup'?'arrived_pickup':'returning_vehicle'))throw new ClevoSecurityException('handoff_not_available',409);
        clevo_delivery_driver($db,$b,(int)$b['DriverID']);$assignment=clevo_delivery_assignment($db,$b,true);
        clevo_rate_limit($db,'delivery:issue',$booking.':'.$kind,3,600);
        $code=(string)random_int(100000,999999);$nonce=bin2hex(random_bytes(16));$hash=password_hash($code,PASSWORD_DEFAULT);
        clevo_stmt($db,'INSERT INTO delivery_confirmations (AssignmentID,Kind,Nonce,CodeHash,ExpiresAt) VALUES (?,?,?,?,UTC_TIMESTAMP(6)+INTERVAL 10 MINUTE) ON DUPLICATE KEY UPDATE Nonce=VALUES(Nonce),CodeHash=VALUES(CodeHash),Attempts=0,IssuedAt=UTC_TIMESTAMP(6),ExpiresAt=VALUES(ExpiresAt),ConsumedAt=NULL','isss',[(int)$assignment['AssignmentID'],$kind,$nonce,$hash]);
        return ['assignment_id'=>(int)$assignment['AssignmentID'],'nonce'=>$nonce,'cipher'=>clevo_encrypt_mail(['code'=>$code])];
    });
    // Never persist a plaintext confirmation code in idempotency receipts.
    $b=clevo_booking_lock($db,$booking);
    if((int)$b['UserID']!==$customer||$b['DeliveryStage']!==($kind==='pickup'?'arrived_pickup':'returning_vehicle'))throw new ClevoSecurityException('handoff_not_available',409);
    $r=clevo_stmt($db,'SELECT Nonce,ExpiresAt,ConsumedAt FROM delivery_confirmations WHERE AssignmentID=? AND Kind=?','is',[$result['assignment_id'],$kind])->get_result()->fetch_assoc();
    if(!$r||$r['Nonce']!==$result['nonce']||$r['ConsumedAt']!==null||strtotime($r['ExpiresAt'].' UTC')<=time())throw new ClevoSecurityException('confirmation_expired_or_superseded',409);
    return ['confirmation_code'=>clevo_decrypt_mail($result['cipher'])['code'],'kind'=>$kind,'expires_at'=>str_replace(' ','T',$r['ExpiresAt']).'Z'];
}
function clevo_delivery_confirm(mysqli $db,int $driver,int $booking,string $kind,string $code,string $key):array
{
    if(!in_array($kind,['pickup','delivery'],true)||!preg_match('/^[0-9]{6}$/D',$code))throw new ClevoSecurityException('invalid_confirmation',422);
    $result=clevo_db_idempotent($db,$driver,'delivery.confirm',$key,[$booking,$kind,hash_hmac('sha256',$code,clevo_env('APP_KEY'))],function()use($db,$driver,$booking,$kind,$code):array{
        $b=clevo_booking_lock($db,$booking);clevo_delivery_driver($db,$b,$driver);$a=clevo_delivery_assignment($db,$b,true);
        if($b['DeliveryStage']!==($kind==='pickup'?'arrived_pickup':'returning_vehicle'))throw new ClevoSecurityException('invalid_transition',409);
        $r=clevo_stmt($db,'SELECT * FROM delivery_confirmations WHERE AssignmentID=? AND Kind=? FOR UPDATE','is',[(int)$a['AssignmentID'],$kind])->get_result()->fetch_assoc();
        if(!$r||$r['ConsumedAt']!==null||strtotime($r['ExpiresAt'].' UTC')<=time()||(int)$r['Attempts']>=8)return ['accepted'=>false];
        if(!password_verify($code,$r['CodeHash']??'')){clevo_stmt($db,'UPDATE delivery_confirmations SET Attempts=Attempts+1 WHERE ConfirmationID=?','i',[(int)$r['ConfirmationID']]);return ['accepted'=>false];}
        $changed=clevo_delivery_advance($db,$driver,$booking,$kind==='pickup'?'vehicle_collected':'delivered',true);
        clevo_stmt($db,'UPDATE delivery_confirmations SET ConsumedAt=UTC_TIMESTAMP(6),CodeHash=NULL WHERE ConfirmationID=?','i',[(int)$r['ConfirmationID']]);
        return ['accepted'=>true]+$changed;
    });
    if(!$result['accepted'])throw new ClevoSecurityException('invalid_or_expired_confirmation',422);return $result;
}
function clevo_delivery_tracking_stage(?string $stage):bool
{
    return in_array($stage,['heading_pickup','arrived_pickup','vehicle_collected','returning_vehicle'],true);
}
function clevo_delivery_location(mysqli $db,int $driver,int $booking,array $input,bool $legacy=false):array
{
    $lat=$input['latitude']??null;$lon=$input['longitude']??null;$accuracy=$input['accuracy_m']??null;
    foreach([$lat,$lon] as $number)if(!is_numeric($number)||!is_finite((float)$number))throw new ClevoSecurityException('invalid_coordinates',422);
    if(abs((float)$lat)>90||abs((float)$lon)>180||(!$legacy&&$accuracy===null)||($accuracy!==null&&(!is_numeric($accuracy)||!is_finite((float)$accuracy)||(float)$accuracy<0||(float)$accuracy>5000)))throw new ClevoSecurityException('invalid_coordinates',422);
    $mode=$input['capture_mode']??'foreground';if(!in_array($mode,['foreground','background'],true))throw new ClevoSecurityException('invalid_capture_mode',422);
    // Background operation is not approved in this phase. An arbitrary env toggle is not approval.
    if($mode==='background')throw new ClevoSecurityException('background_tracking_not_approved',403);
    $stamp=$input['captured_at']??($legacy?gmdate('Y-m-d\TH:i:s\Z'):null);
    if(!is_string($stamp)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/D',$stamp))throw new ClevoSecurityException('invalid_capture_time',422);
    try{$captured=new DateTimeImmutable($stamp,new DateTimeZone('UTC'));}catch(Throwable $e){throw new ClevoSecurityException('invalid_capture_time',422);}
    if($captured->format('Y-m-d\TH:i:s')!==substr($stamp,0,19)||$captured->getTimestamp()>time()+15||$captured->getTimestamp()<time()-120)throw new ClevoSecurityException('location_outside_time_window',409);
    return clevo_db_atomic($db,function()use($db,$driver,$booking,$input,$legacy,$lat,$lon,$accuracy,$captured,$mode):array{
        $b=clevo_booking_lock($db,$booking);clevo_delivery_driver($db,$b,$driver);$a=clevo_delivery_assignment($db,$b,true);
        if(!clevo_delivery_tracking_stage($b['DeliveryStage'])||!in_array($b['Status'],clevo_booking_active_states(),true)||empty($a['AcceptedAt']))throw new ClevoSecurityException('location_sharing_inactive',409);
        clevo_assert_booking_payment($db,$b);
        if(isset($input['assignment_id'])&&(int)$input['assignment_id']!==(int)$a['AssignmentID'])throw new ClevoSecurityException('assignment_changed',409);
        if(!$legacy&&!isset($input['assignment_id'],$input['sequence']))throw new ClevoSecurityException('location_metadata_required',422);
        $sequence=$input['sequence']??((int)$a['LastSequence']+1);
        if(!is_int($sequence)||$sequence<1||$sequence>2147483647)throw new ClevoSecurityException('invalid_sequence',422);
        $lat=round((float)$lat,8);$lon=round((float)$lon,8);$accuracy=$accuracy===null?null:round((float)$accuracy,2);$at=$captured->format('Y-m-d H:i:s.u');
        $hash=hash('sha256',json_encode([$lat,$lon,$accuracy,$at,$mode],JSON_THROW_ON_ERROR));
        if($sequence<=(int)$a['LastSequence']){
            $old=clevo_stmt($db,'SELECT SampleHash FROM tracking_records WHERE AssignmentID=? AND SequenceNumber=?','ii',[(int)$a['AssignmentID'],$sequence])->get_result()->fetch_assoc();
            $expected=$sequence===(int)$a['LastSequence']?$a['LastSampleHash']:($old['SampleHash']??null);
            if($expected!==null&&!hash_equals($expected,$hash))throw new ClevoSecurityException('location_sequence_conflict',409);
            return ['stored'=>false,'duplicate'=>true,'last_sequence'=>(int)$a['LastSequence']];
        }
        if($a['LastCapturedAt']!==null&&$at<$a['LastCapturedAt'])throw new ClevoSecurityException('out_of_order_location',409);
        clevo_rate_limit($db,'delivery:gps',(string)$a['AssignmentID'],12,60);
        clevo_stmt($db,'INSERT INTO tracking_records (BookingID,DriverID,Latitude,Longitude,AssignmentID,SequenceNumber,CapturedAt,AccuracyMeters,CaptureMode,SampleHash) VALUES (?,?,?,?,?,?,?,?,?,?)','iiddiisdss',[$booking,$driver,$lat,$lon,(int)$a['AssignmentID'],$sequence,$at,$accuracy,$mode,$hash]);
        clevo_stmt($db,'UPDATE driver_assignments SET LastSequence=?,LastCapturedAt=?,LastSampleHash=? WHERE AssignmentID=?','issi',[$sequence,$at,$hash,(int)$a['AssignmentID']]);
        clevo_stmt($db,'UPDATE booking SET Driver_Lat=?,Driver_Lon=? WHERE BookingID=?','ddi',[$lat,$lon,$booking]);
        return ['stored'=>true,'duplicate'=>false,'last_sequence'=>$sequence];
    });
}
function clevo_delivery_tracking(mysqli $db,int $viewer,int $booking,?int $bound=null):array
{
    return clevo_db_atomic($db,fn()=>clevo_delivery_tracking_snapshot($db,$viewer,$booking,$bound));
}
function clevo_delivery_tracking_snapshot(mysqli $db,int $viewer,int $booking,?int $bound=null):array
{
    $a=clevo_booking_actor($db,$viewer);$role=clevo_role($a['Role']);$b=clevo_booking_lock($db,$booking);$s=$b['_station'];
    if($bound&&$bound!==$booking)throw new ClevoSecurityException('not_found',404);
    $allowed=($role==='customer'&&(int)$b['UserID']===$viewer)||($role==='owner'&&(int)$s['UserID']===$viewer&&$s['ArchivedAt']===null&&$s['admin_status']==='approved')||($role==='driver'&&(int)$b['DriverID']===$viewer&&(int)$a['StationID']===(int)$b['StationID']&&$s['admin_status']==='approved'&&$s['ArchivedAt']===null);
    if(!$allowed)throw new ClevoSecurityException('not_found',404);
    $assignment=clevo_stmt($db,'SELECT * FROM driver_assignments WHERE BookingID=? AND DriverID=? AND EndedAt IS NULL','ii',[$booking,(int)$b['DriverID']])->get_result()->fetch_assoc();
    $active=$assignment&&!empty($assignment['AcceptedAt'])&&$b['Service_Location']==='delivery'&&in_array($b['Status'],clevo_booking_active_states(),true)&&clevo_delivery_tracking_stage($b['DeliveryStage'])&&$s['ArchivedAt']===null&&$s['admin_status']==='approved';
    if($active){$driver=clevo_account($db,(int)$b['DriverID']);$active=$driver&&clevo_role($driver['Role'])==='driver'&&clevo_account_active($db,$driver)&&(int)$driver['StationID']===(int)$b['StationID'];}
    if($active){try{clevo_assert_booking_payment($db,$b);}catch(ClevoSecurityException $e){$active=false;}}
    $location=null;$state=$active?'waiting':'inactive';
    if($active){
        $r=clevo_stmt($db,'SELECT Latitude,Longitude,AccuracyMeters,CapturedAt,RecordedAt,SequenceNumber FROM tracking_records WHERE AssignmentID=? AND SequenceNumber=?','ii',[(int)$assignment['AssignmentID'],(int)$assignment['LastSequence']])->get_result()->fetch_assoc();
        if($r&&$r['CapturedAt']!==null){$age=max(0,time()-strtotime($r['CapturedAt'].' UTC'));$state=$age>30?'stale':'fresh';
            if($age<=120&&$r['AccuracyMeters']!==null&&(float)$r['AccuracyMeters']<=100)$location=['latitude'=>(float)$r['Latitude'],'longitude'=>(float)$r['Longitude'],'accuracy_m'=>(float)$r['AccuracyMeters'],'captured_at'=>str_replace(' ','T',$r['CapturedAt']).'Z','received_at'=>str_replace(' ','T',$r['RecordedAt']).'Z','age_seconds'=>$age,'sequence'=>(int)$r['SequenceNumber']];
            elseif($age<=120)$state='inaccurate';
        }
    }
    return ['booking_id'=>$booking,'booking_status'=>$b['Status'],'delivery_stage'=>$b['DeliveryStage'],'assignment_id'=>$assignment?(int)$assignment['AssignmentID']:null,'last_sequence'=>$assignment?(int)$assignment['LastSequence']:0,'sharing_allowed'=>(bool)$active,'location_state'=>$state,'location'=>$location,'server_time'=>gmdate('Y-m-d\TH:i:s\Z'),'poll_after_seconds'=>10,'background_tracking_allowed'=>false];
}
function clevo_delivery_stop(mysqli $db,array $b,?int $actor,string $role,string $target):void
{
    $id=(int)$b['BookingID'];
    if($target!=='Completed'&&(int)$b['DeliveryWorkflowVersion']===1){
        $assignment=clevo_stmt($db,'SELECT * FROM driver_assignments WHERE BookingID=? AND EndedAt IS NULL FOR UPDATE','i',[$id])->get_result()->fetch_assoc();
        if($assignment)clevo_delivery_event($db,$b,$assignment,$b['DeliveryStage'],'cancelled',$actor,$role,'booking_terminated');
        clevo_stmt($db,"UPDATE booking SET DeliveryStage='cancelled' WHERE BookingID=?",'i',[$id]);
    }
    clevo_stmt($db,'UPDATE booking SET Driver_Lat=NULL,Driver_Lon=NULL WHERE BookingID=?','i',[$id]);
    clevo_stmt($db,'UPDATE delivery_confirmations c JOIN driver_assignments a ON a.AssignmentID=c.AssignmentID SET c.CodeHash=NULL WHERE a.BookingID=?','i',[$id]);
}
function clevo_delivery_purge(mysqli $db):array
{
    $hours=(int)clevo_env('TRACKING_RETENTION_HOURS','1');if($hours<1||$hours>24)throw new RuntimeException('Invalid tracking retention.');
    return clevo_db_atomic($db,function()use($db,$hours):array{
        $rows=clevo_stmt($db,"SELECT r.TrackingID FROM tracking_records r JOIN booking b ON b.BookingID=r.BookingID LEFT JOIN driver_assignments a ON a.AssignmentID=r.AssignmentID WHERE r.RecordedAt<UTC_TIMESTAMP(6)-INTERVAL ? HOUR OR b.Status IN ('Completed','Cancelled','Rejected','Cancelled_Timeout') OR a.EndedAt IS NOT NULL ORDER BY r.TrackingID LIMIT 1000",'i',[$hours])->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach($rows as $row)clevo_stmt($db,'DELETE FROM tracking_records WHERE TrackingID=?','i',[(int)$row['TrackingID']]);
        $expired=clevo_stmt($db,'UPDATE delivery_confirmations SET CodeHash=NULL WHERE CodeHash IS NOT NULL AND ExpiresAt<=UTC_TIMESTAMP(6) LIMIT 1000')->affected_rows;
        return ['gps_records_deleted'=>count($rows),'expired_code_hashes_cleared'=>$expired];
    });
}
