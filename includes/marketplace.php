<?php
declare(strict_types=1);
require_once __DIR__.'/commerce.php';
require_once __DIR__.'/support-helpers.php';

function clevo_market_station(mysqli $db,int $owner,int $id,bool $lock=true):array
{
    $row=clevo_stmt($db,"SELECT * FROM stations WHERE StationID=? AND UserID=? AND admin_status='approved' AND ArchivedAt IS NULL".($lock?' FOR UPDATE':''),'ii',[$id,$owner])->get_result()->fetch_assoc();
    if(!$row)throw new ClevoSecurityException('not_found',404);return $row;
}
function clevo_market_profile(mysqli $db,int $user,array $input):void
{
    clevo_db_atomic($db,function()use($db,$user,$input):void{
        $row=clevo_account($db,$user,true);if(!$row)throw new ClevoSecurityException('not_found',404);
        $name=trim($input['full_name']??$row['UserName']);$phone=$input['phone']??$row['PhoneNumber'];
        $car=$input['car_name']??clevo_stmt($db,'SELECT CarName FROM user WHERE UserID=?','i',[$user])->get_result()->fetch_assoc()['CarName'];
        if($name===''||!preg_match('/^07[0-9]{8}$/D',$phone))throw new ClevoSecurityException('invalid_profile',422);
        $email=strtolower(trim($input['email']??$row['Email']??''));
        if(isset($input['email'])&&(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>100))throw new ClevoSecurityException('invalid_email',422);
        if($email!==''&&$email!==strtolower((string)$row['Email'])){
            if(clevo_stmt($db,'SELECT UserID FROM user WHERE Email=? AND UserID<>?','si',[$email,$user])->get_result()->fetch_assoc())throw new ClevoSecurityException('profile_conflict',409);
            clevo_issue_verification($db,$row,$email);
        }
        clevo_stmt($db,'UPDATE user SET UserName=?,PhoneNumber=?,CarName=? WHERE UserID=?','sssi',[$name,$phone,$car,$user]);
        clevo_db_audit($db,$user,'profile.updated','user',$user);
    });
}
function clevo_market_service(mysqli $db,int $owner,int $station,int $id,array $input):int
{
    return clevo_db_atomic($db,function()use($db,$owner,$station,$id,$input):int{
        clevo_market_station($db,$owner,$station);
        $old=$id?clevo_stmt($db,'SELECT * FROM services WHERE ServiceID=? AND StationID=? AND ArchivedAt IS NULL FOR UPDATE','ii',[$id,$station])->get_result()->fetch_assoc():[];
        if($id&&!$old)throw new ClevoSecurityException('not_found',404);
        $ar=trim($input['name_ar']??$old['Name_ar']??'');$en=trim($input['name_en']??$old['Name_en']??$ar);
        $price=clevo_db_decimal(clevo_db_money($input['price']??$old['Price']??'0'));
        if($ar===''||$en===''||mb_strlen($ar)>100||mb_strlen($en)>100)throw new ClevoSecurityException('invalid_service',422);
        $descriptionAr=$input['description_ar']??$old['Description_ar']??'';$descriptionEn=$input['description_en']??$old['Description_en']??'';
        $duration=$input['duration']??$old['Duration']??null;
        $at=(int)($input['is_station']??$old['is_station']??true);$home=(int)($input['is_home']??$old['is_home']??false);$pickup=(int)($input['is_pickup']??$old['is_pickup']??false);
        if($id)clevo_stmt($db,'UPDATE services SET Name=?,Name_ar=?,Name_en=?,Description=?,Description_ar=?,Description_en=?,Price=?,Duration=?,is_station=?,is_home=?,is_pickup=? WHERE ServiceID=? AND StationID=?','sssssssiiiiii',[$en,$ar,$en,$descriptionEn,$descriptionAr,$descriptionEn,$price,$duration,$at,$home,$pickup,$id,$station]);
        else{clevo_stmt($db,'INSERT INTO services (StationID,Name,Name_ar,Name_en,Description,Description_ar,Description_en,Price,Duration,is_station,is_home,is_pickup) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)','isssssssiiii',[$station,$en,$ar,$en,$descriptionEn,$descriptionAr,$descriptionEn,$price,$duration,$at,$home,$pickup]);$id=(int)$db->insert_id;}
        clevo_db_audit($db,$owner,'service.saved','services',$id);return $id;
    });
}
function clevo_market_availability(mysqli $db,int $owner,int $station,array $input):void
{
    clevo_db_atomic($db,function()use($db,$owner,$station,$input):void{
        $old=clevo_market_station($db,$owner,$station);$open=$input['opening_time']??$old['OpeningTime'];$close=$input['closing_time']??$old['ClosingTime'];
        foreach(['open'=>&$open,'close'=>&$close] as &$time){if($time===null)continue;if(strlen((string)$time)===5)$time.=':00';if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/D',(string)$time))throw new ClevoSecurityException('invalid_hours',422);}unset($time);
        clevo_stmt($db,'UPDATE stations SET Status=?,OpeningTime=?,ClosingTime=?,Has_At_Station=?,Has_Home_Service=?,Has_Delivery=? WHERE StationID=?','sssiiii',[$input['status']??$old['Status'],$open,$close,(int)($input['has_at_station']??$old['Has_At_Station']),(int)($input['has_home_service']??$old['Has_Home_Service']),(int)($input['has_delivery']??$old['Has_Delivery']),$station]);
        clevo_db_audit($db,$owner,'station.availability','stations',$station);
    });
}
function clevo_market_driver_save(mysqli $db,int $owner,int $station,int $id,array $input):int
{
    return clevo_db_atomic($db,function()use($db,$owner,$station,$id,$input):int{
        clevo_market_station($db,$owner,$station);
        $old=$id?clevo_stmt($db,"SELECT * FROM user WHERE UserID=? AND StationID=? AND LOWER(Role)='driver' FOR UPDATE",'ii',[$id,$station])->get_result()->fetch_assoc():[];
        if($id&&!$old)throw new ClevoSecurityException('not_found',404);
        $name=trim($input['name']??$old['UserName']??'');$phone=$input['phone']??$old['PhoneNumber']??'';
        if($name===''||mb_strlen($name)>100||!preg_match('/^07[0-9]{8}$/D',$phone))throw new ClevoSecurityException('invalid_driver',422);
        $status=array_key_exists('active',$input)?($input['active']?'active':'rejected'):($old['AccountStatus']??'active');
        if($id&&$status==='rejected'&&clevo_stmt($db,"SELECT BookingID FROM booking WHERE DriverID=? AND Status IN ('Accepted','Arrived','In Progress','Pickup','Washing','Returning') LIMIT 1",'i',[$id])->get_result()->fetch_assoc())throw new ClevoSecurityException('driver_has_active_jobs',409);
        if($id){clevo_stmt($db,'UPDATE user SET UserName=?,PhoneNumber=?,AccountStatus=? WHERE UserID=?','sssi',[$name,$phone,$status,$id]);if($status!==$old['AccountStatus'])clevo_revoke_user($db,$id);}
        else{
            $email=isset($input['email'])?strtolower(trim($input['email'])):null;
            if($email!==null&&(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>100))throw new ClevoSecurityException('invalid_email',422);
            $hash=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);clevo_stmt($db,"INSERT INTO user (UserName,PhoneNumber,Email,Password,Role,AccountStatus,StationID,MustResetPassword) VALUES (?,?,?,?,'driver','active',?,1)",'ssssi',[$name,$phone,$email,$hash,$station]);$id=(int)$db->insert_id;
            if($email!==null)clevo_issue_verification($db,clevo_account($db,$id));
        }
        clevo_db_audit($db,$owner,'driver.saved','user',$id);return $id;
    });
}
function clevo_market_driver_invite(mysqli $db,int $owner,int $id,string $email):void
{
    clevo_db_atomic($db,function()use($db,$owner,$id,$email):void{
        $row=clevo_stmt($db,"SELECT u.UserID,u.Email,u.MustResetPassword,u.EmailVerifiedAt FROM user u JOIN stations s ON s.StationID=u.StationID WHERE u.UserID=? AND LOWER(u.Role)='driver' AND u.AccountStatus='active' AND s.UserID=? AND s.admin_status='approved' AND s.ArchivedAt IS NULL FOR UPDATE",'ii',[$id,$owner])->get_result()->fetch_assoc();
        if(!$row)throw new ClevoSecurityException('not_found',404);
        if($row['Email']!==null||!(int)$row['MustResetPassword']||$row['EmailVerifiedAt']!==null)throw new ClevoSecurityException('driver_identity_already_enrolled',409);
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>100)throw new ClevoSecurityException('invalid_email',422);
        clevo_stmt($db,'UPDATE user SET Email=? WHERE UserID=?','si',[strtolower($email),$id]);clevo_issue_verification($db,clevo_account($db,$id));clevo_db_audit($db,$owner,'driver.invited','user',$id);
    });
}
function clevo_market_assign(mysqli $db,int $owner,int $booking,int $driver):void
{
    clevo_db_atomic($db,function()use($db,$owner,$booking,$driver):void{
        $b=clevo_stmt($db,"SELECT b.* FROM booking b JOIN stations s ON s.StationID=b.StationID WHERE b.BookingID=? AND s.UserID=? AND s.ArchivedAt IS NULL AND s.admin_status='approved' FOR UPDATE",'ii',[$booking,$owner])->get_result()->fetch_assoc();
        if(!$b)throw new ClevoSecurityException('not_found',404);
        if(!in_array($b['Status'],['Pending','Accepted'],true)||$b['Service_Location']!=='delivery'||!empty($b['DriverAcceptedAt']))throw new ClevoSecurityException('invalid_assignment_state',409);
        if($b['Status']==='Pending'&&strtotime($b['created_at'].' UTC')<time()-600)throw new ClevoSecurityException('booking_expired',409);
        $d=clevo_stmt($db,"SELECT UserID FROM user WHERE UserID=? AND StationID=? AND LOWER(Role)='driver' AND AccountStatus='active' FOR UPDATE",'ii',[$driver,(int)$b['StationID']])->get_result()->fetch_assoc();
        if(!$d)throw new ClevoSecurityException('driver_not_available',409);
        if((int)$b['DriverID']===$driver&&$b['Status']==='Accepted')return;
        clevo_stmt($db,'UPDATE driver_assignments SET EndedAt=UTC_TIMESTAMP(6) WHERE BookingID=? AND EndedAt IS NULL','i',[$booking]);
        clevo_stmt($db,'UPDATE auth_sessions SET RevokedAt=UTC_TIMESTAMP() WHERE DriverBookingID=?','i',[$booking]);
        clevo_stmt($db,"UPDATE booking SET DriverID=?,Status='Accepted',Accepted_At=COALESCE(Accepted_At,UTC_TIMESTAMP()),notification_sent=0,DriverAccessCode=NULL,DriverAccessCodeHash=NULL,DriverAccessCodeExpiresAt=NULL,DriverAccessCodeUsedAt=NULL WHERE BookingID=?",'ii',[$driver,$booking]);
        clevo_stmt($db,'INSERT INTO driver_assignments (BookingID,DriverID,StationID,AssignedBy) VALUES (?,?,?,?)','iiii',[$booking,$driver,(int)$b['StationID'],$owner]);
        clevoCreateNotification($db,$driver,'مهمة جديدة','تم تعيين مهمة توصيل جديدة.','info','driver-dashboard.php','New job','A new delivery job has been assigned.');
        clevoCreateNotification($db,(int)$b['UserID'],'تم قبول الطلب','تم قبول الطلب وتعيين السائق.','success',"order-details.php?id=$booking",'Order accepted','Your order was accepted and assigned.');
        clevo_db_audit($db,$owner,'driver.assigned','booking',$booking);
    });
}
function clevo_market_reject(mysqli $db,int $owner,int $booking):void
{
    clevo_db_atomic($db,function()use($db,$owner,$booking):void{
        $b=clevo_stmt($db,'SELECT b.* FROM booking b JOIN stations s ON s.StationID=b.StationID WHERE b.BookingID=? AND s.UserID=? FOR UPDATE','ii',[$booking,$owner])->get_result()->fetch_assoc();
        if(!$b)throw new ClevoSecurityException('not_found',404);if($b['Status']==='Rejected')return;if($b['Status']!=='Pending')throw new ClevoSecurityException('invalid_transition',409);
        clevoRefundBookingPayment($db,$booking,'rejected');clevo_stmt($db,"UPDATE booking SET Status='Rejected' WHERE BookingID=?",'i',[$booking]);
        clevoCreateNotification($db,(int)$b['UserID'],'تم رفض الطلب','تم رفض الطلب من المحطة.','danger',"order-details.php?id=$booking",'Order rejected','The station rejected your order.');
        clevoCreateRoleNotification($db,'Admin','رفض طلب من صاحب محطة',"تم رفض الطلب #$booking من صاحب المحطة.",'warning','financial-reports.php','Order rejected by station owner',"Station owner rejected order #$booking.");
        clevo_db_audit($db,$owner,'booking.rejected','booking',$booking);
    });
}
function clevo_market_review(mysqli $db,int $user,int $booking,int $rating,string $comment):int
{
    return clevo_db_atomic($db,function()use($db,$user,$booking,$rating,$comment):int{
        $b=clevo_stmt($db,"SELECT b.BookingID,s.UserID OwnerID FROM booking b JOIN stations s ON s.StationID=b.StationID WHERE b.BookingID=? AND b.UserID=? AND b.Status='Completed' FOR UPDATE",'ii',[$booking,$user])->get_result()->fetch_assoc();
        if(!$b)throw new ClevoSecurityException('review_not_allowed',409);if($rating<1||$rating>5||mb_strlen($comment)>2000)throw new ClevoSecurityException('invalid_review',422);
        if(clevo_stmt($db,'SELECT ReviewID FROM reviews WHERE BookingID=?','i',[$booking])->get_result()->fetch_assoc())throw new ClevoSecurityException('already_reviewed',409);
        clevo_stmt($db,'INSERT INTO reviews (BookingID,Rating,Comment) VALUES (?,?,?)','iis',[$booking,$rating,$comment]);$id=(int)$db->insert_id;
        clevoCreateNotification($db,(int)$b['OwnerID'],'تقييم جديد','وصل تقييم جديد للخدمة.','info','owner-reviews.php','New review','A customer reviewed your service.');return $id;
    });
}
function clevo_market_review_reply(mysqli $db,int $owner,int $review,string $reply):void
{
    clevo_db_atomic($db,function()use($db,$owner,$review,$reply):void{
        $r=clevo_stmt($db,'SELECT r.ReviewID FROM reviews r JOIN booking b ON b.BookingID=r.BookingID JOIN stations s ON s.StationID=b.StationID WHERE r.ReviewID=? AND s.UserID=? FOR UPDATE','ii',[$review,$owner])->get_result()->fetch_assoc();
        if(!$r)throw new ClevoSecurityException('not_found',404);
        clevo_stmt($db,'UPDATE reviews SET Owner_Reply=? WHERE ReviewID=?','si',[$reply,$review]);
    });
}
function clevo_market_withdrawal_status(mysqli $db,int $admin,int $id,string $status):void
{
    clevo_db_atomic($db,function()use($db,$admin,$id,$status):void{
        $r=clevo_stmt($db,'SELECT * FROM owner_withdrawal_requests WHERE id=? FOR UPDATE','i',[$id])->get_result()->fetch_assoc();
        if(!$r)throw new ClevoSecurityException('not_found',404);if($r['status']===$status)return;
        if($r['status']!=='pending'||!in_array($status,['paid','rejected'],true))throw new ClevoSecurityException('withdrawal_already_processed',409);
        if($status==='rejected')clevo_wallet_entry($db,(int)$r['wallet_id'],clevo_db_money($r['amount']),'credit',"withdrawal:$id:refund",null,'Rejected withdrawal refund');
        clevo_stmt($db,'UPDATE owner_withdrawal_requests SET status=?,processed_at=UTC_TIMESTAMP() WHERE id=?','si',[$status,$id]);clevo_db_audit($db,$admin,'withdrawal.processed','owner_withdrawal_requests',$id);
    });
}
