<?php
declare(strict_types=1);
require_once __DIR__.'/marketplace.php';
require_once __DIR__.'/api/http.php';
require_once __DIR__.'/api/contracts.php';
require_once __DIR__.'/verification.php';

function clevo_admin_row(?array $row):array {if(!$row)throw new ClevoSecurityException('not_found',404);return $row;}
function clevo_admin_actor(mysqli $db,int $actor):array {
    $a=clevo_account($db,$actor);
    if(!$a||clevo_role($a['Role'])!=='admin'||!clevo_account_active($db,$a))throw new ClevoSecurityException('forbidden',403);
    return $a;
}

function clevo_market_plan(mysqli $db,int $actor,int $id,array $input):int
{
    clevo_admin_actor($db,$actor);
    clevo_api_validate($input,clevo_api_contracts()[$id?'PlanPatch':'PlanCreate']);
    $old=$id?clevo_admin_row(clevo_stmt($db,'SELECT * FROM plans WHERE id=? FOR UPDATE','i',[$id])->get_result()->fetch_assoc()):[];
    $defaults=['description_ar'=>'','description_en'=>'','included_services_ar'=>'','included_services_en'=>'','is_active'=>true];$v=array_replace($defaults,$old,$input);
    $price=clevo_db_decimal(clevo_db_money($v['price']));
    if(!trim($v['name_ar'])||!trim($v['name_en']))throw new ClevoSecurityException('invalid_plan',422);
    $params=[$v['name_ar'],$v['name_en'],$price,$v['duration'],$v['duration_type'],$v['wash_count'],$v['description_ar'],$v['description_en'],$v['included_services_ar'],$v['included_services_en'],(int)$v['is_active']];
    if($id){$params[]=$id;clevo_stmt($db,'UPDATE plans SET name_ar=?,name_en=?,price=?,duration=?,duration_type=?,wash_count=?,description_ar=?,description_en=?,included_services_ar=?,included_services_en=?,is_active=? WHERE id=?','sssisissssii',$params);}
    else{clevo_stmt($db,'INSERT INTO plans (name_ar,name_en,price,duration,duration_type,wash_count,description_ar,description_en,included_services_ar,included_services_en,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?)','sssisissssi',$params);$id=(int)$db->insert_id;}
    if(isset($input['sandbox_policy'])){
        $policy=$input['sandbox_policy'];$ids=$policy['service_ids'];
        $slots=implode(',',array_fill(0,count($ids),'?'));
        $count=(int)clevo_stmt($db,"SELECT COUNT(*) n FROM services WHERE ArchivedAt IS NULL AND ServiceID IN ($slots)",str_repeat('i',count($ids)),$ids)->get_result()->fetch_assoc()['n'];
        if($count!==count($ids))throw new ClevoSecurityException('subscription_service_unavailable',422);
        clevo_finance_plan_terms(array_replace($v,['id'=>$id,'SandboxPolicy'=>json_encode($policy,JSON_THROW_ON_ERROR)]));
        clevo_stmt($db,'UPDATE plans SET SandboxPolicy=? WHERE id=?','si',[json_encode($policy,JSON_THROW_ON_ERROR),$id]);
    }
    clevo_db_audit($db,$actor,'plan.saved','plans',$id);return $id;
}
function clevo_market_account_status(mysqli $db,int $actor,int $id,string $status):void
{
    clevo_admin_actor($db,$actor);
    clevo_api_validate(['status'=>$status],clevo_api_contracts()['AccountStatus']);
    if($actor===$id)throw new ClevoSecurityException('self_status_change_forbidden',409);
    // Serialize administrator restriction decisions so two admins cannot remove the last active one.
    $admins=clevo_stmt($db,"SELECT UserID FROM user WHERE LOWER(Role)='admin' AND AccountStatus='active' ORDER BY UserID FOR UPDATE")->get_result()->fetch_all(MYSQLI_ASSOC);
    $row=clevo_admin_row(clevo_account($db,$id,true));
    if(clevo_role($row['Role'])==='driver'&&$status!=='active'&&clevo_stmt($db,"SELECT BookingID FROM booking WHERE DriverID=? AND Status IN ('Accepted','Arrived','In Progress','Pickup','Washing','Returning') LIMIT 1",'i',[$id])->get_result()->fetch_assoc())throw new ClevoSecurityException('driver_has_active_jobs',409);
    if(clevo_role($row['Role'])==='admin'&&$status!=='active'&&$row['AccountStatus']==='active'&&count($admins)<=1)throw new ClevoSecurityException('last_admin_required',409);
    if($status==='active'&&clevo_role($row['Role'])!=='driver'&&!$row['EmailVerifiedAt'])throw new ClevoSecurityException('email_verification_required',409);
    clevo_stmt($db,'UPDATE user SET AccountStatus=? WHERE UserID=?','si',[$status,$id]);
    if($status!=='active'){
        if(clevo_role($row['Role'])==='owner'){
            // Suspend new work without cancelling, deleting or repricing existing bookings.
            clevo_stmt($db,"UPDATE stations SET Status='Closed' WHERE UserID=? AND ArchivedAt IS NULL",'i',[$id]);
            clevo_db_audit($db,$actor,'owner.stations_closed','user',$id);
        }
        clevo_revoke_user($db,$id);
        if(!clevo_stmt($db,'SELECT id FROM blacklist WHERE user_id=? LIMIT 1','i',[$id])->get_result()->fetch_assoc())clevo_stmt($db,"INSERT INTO blacklist (user_id,reason) VALUES (?,'Restricted by administrator')",'i',[$id]);
    }else clevo_stmt($db,'DELETE FROM blacklist WHERE user_id=?','i',[$id]);
    clevo_db_audit($db,$actor,'user.status_changed','user',$id);
}
function clevo_market_refund_decision(mysqli $db,int $admin,int $id,array $input):void
{
    clevo_admin_actor($db,$admin);
    clevo_api_validate($input,clevo_api_contracts()['Decision']);
    $r=clevo_admin_row(clevo_stmt($db,'SELECT * FROM refund_requests WHERE RefundID=? FOR UPDATE','i',[$id])->get_result()->fetch_assoc());
    if($r['Status']!=='pending')throw new ClevoSecurityException('refund_already_processed',409);
    if($input['status']==='approved'){
        $b=clevo_booking_lock($db,(int)$r['BookingID']);
        if(!in_array($b['Status'],['Pending','Accepted','Rejected','Cancelled','Cancelled_Timeout'],true))throw new ClevoSecurityException('manual_refund_required',409);
        if(!in_array($b['Payment_Method'],['wallet','points','plan'],true))throw new ClevoSecurityException('manual_refund_required',409);
        if(in_array($b['Status'],['Pending','Accepted'],true))clevo_booking_transition($db,$admin,(int)$r['BookingID'],'Cancelled','approved_refund');
        else clevoRefundBookingPayment($db,(int)$r['BookingID'],'approved_refund');
    }
    clevo_stmt($db,'UPDATE refund_requests SET Status=?,DecisionNote=?,ProcessedBy=?,ProcessedAt=UTC_TIMESTAMP(6) WHERE RefundID=?','ssii',[$input['status'],$input['note'],$admin,$id]);
    clevoCreateNotification($db,(int)$r['UserID'],'تحديث طلب الاسترجاع','تم تحديث حالة طلب الاسترجاع.','info','booking-history.php','Refund request update','Your refund request was reviewed.');clevo_db_audit($db,$admin,'refund.reviewed','refund_requests',$id);
}

require_once __DIR__.'/admin-commands.php';
require_once __DIR__.'/admin-reports.php';

function clevo_admin_web_command(mysqli $db,int $actor,string $action,int $id,array $input):bool
{
    try{clevo_admin_command($db,$actor,$action,$id,$input);return true;}
    catch(ClevoSecurityException $error){$_SESSION['admin_error']=$error->reason;return false;}
}

function clevo_admin_profile_save(mysqli $db,int $actor,string $filename):void
{
    clevo_admin_actor($db,$actor);
    clevo_db_atomic($db,function()use($db,$actor,$filename):void{
        clevo_stmt($db,'UPDATE user SET ProfilePic=? WHERE UserID=?','si',[$filename,$actor]);
        clevo_db_audit($db,$actor,'admin.profile_image','user',$actor);
    });
}
