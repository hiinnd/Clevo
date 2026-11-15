<?php
declare(strict_types=1);

function clevo_market_plan(mysqli $db,int $actor,int $id,array $input):int
{
    $old=$id?clevo_api_require_row(clevo_stmt($db,'SELECT * FROM plans WHERE id=? FOR UPDATE','i',[$id])->get_result()->fetch_assoc()):[];
    $defaults=['description_ar'=>'','description_en'=>'','included_services_ar'=>'','included_services_en'=>'','is_active'=>true];$v=array_replace($defaults,$old,$input);
    $price=clevo_db_decimal(clevo_db_money($v['price']));
    if(!trim($v['name_ar'])||!trim($v['name_en']))throw new ClevoSecurityException('invalid_plan',422);
    $params=[$v['name_ar'],$v['name_en'],$price,$v['duration'],$v['duration_type'],$v['wash_count'],$v['description_ar'],$v['description_en'],$v['included_services_ar'],$v['included_services_en'],(int)$v['is_active']];
    if($id){$params[]=$id;clevo_stmt($db,'UPDATE plans SET name_ar=?,name_en=?,price=?,duration=?,duration_type=?,wash_count=?,description_ar=?,description_en=?,included_services_ar=?,included_services_en=?,is_active=? WHERE id=?','sssisissssii',$params);}
    else{clevo_stmt($db,'INSERT INTO plans (name_ar,name_en,price,duration,duration_type,wash_count,description_ar,description_en,included_services_ar,included_services_en,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?)','sssisissssi',$params);$id=(int)$db->insert_id;}
    clevo_db_audit($db,$actor,'plan.saved','plans',$id);return $id;
}
function clevo_market_account_status(mysqli $db,int $actor,int $id,string $status):void
{
    if($actor===$id)throw new ClevoSecurityException('self_status_change_forbidden',409);
    // Serialize administrator restriction decisions so two admins cannot remove the last active one.
    $admins=clevo_stmt($db,"SELECT UserID FROM user WHERE LOWER(Role)='admin' AND AccountStatus='active' ORDER BY UserID FOR UPDATE")->get_result()->fetch_all(MYSQLI_ASSOC);
    $row=clevo_api_require_row(clevo_account($db,$id,true));
    if(clevo_role($row['Role'])==='admin'&&$status!=='active'&&$row['AccountStatus']==='active'&&count($admins)<=1)throw new ClevoSecurityException('last_admin_required',409);
    if($status==='active'&&clevo_role($row['Role'])!=='driver'&&!$row['EmailVerifiedAt'])throw new ClevoSecurityException('email_verification_required',409);
    clevo_stmt($db,'UPDATE user SET AccountStatus=? WHERE UserID=?','si',[$status,$id]);
    if($status!=='active'){
        clevo_revoke_user($db,$id);
        if(!clevo_stmt($db,'SELECT id FROM blacklist WHERE user_id=? LIMIT 1','i',[$id])->get_result()->fetch_assoc())clevo_stmt($db,"INSERT INTO blacklist (user_id,reason) VALUES (?,'Restricted by administrator')",'i',[$id]);
    }else clevo_stmt($db,'DELETE FROM blacklist WHERE user_id=?','i',[$id]);
    clevo_db_audit($db,$actor,'user.status_changed','user',$id);
}
function clevo_market_refund_decision(mysqli $db,int $admin,int $id,array $input):void
{
    $r=clevo_api_require_row(clevo_stmt($db,'SELECT * FROM refund_requests WHERE RefundID=? FOR UPDATE','i',[$id])->get_result()->fetch_assoc());
    if($r['Status']!=='pending')throw new ClevoSecurityException('refund_already_processed',409);
    if($input['status']==='approved'){
        $b=clevo_api_require_row(clevo_stmt($db,'SELECT Status,Payment_Method FROM booking WHERE BookingID=? FOR UPDATE','i',[(int)$r['BookingID']])->get_result()->fetch_assoc());
        if(!in_array($b['Status'],['Pending','Accepted','Rejected','Cancelled','Cancelled_Timeout'],true))throw new ClevoSecurityException('manual_refund_required',409);
        if(!in_array($b['Payment_Method'],['wallet','points','plan'],true))throw new ClevoSecurityException('manual_refund_required',409);
        clevoRefundBookingPayment($db,(int)$r['BookingID'],'approved_refund');
        clevo_stmt($db,"UPDATE booking SET Status='Cancelled',notification_sent=1 WHERE BookingID=?",'i',[(int)$r['BookingID']]);
        clevo_stmt($db,'UPDATE driver_assignments SET EndedAt=UTC_TIMESTAMP(6) WHERE BookingID=? AND EndedAt IS NULL','i',[(int)$r['BookingID']]);
        clevo_stmt($db,'UPDATE auth_sessions SET RevokedAt=UTC_TIMESTAMP() WHERE DriverBookingID=?','i',[(int)$r['BookingID']]);
    }
    clevo_stmt($db,'UPDATE refund_requests SET Status=?,DecisionNote=?,ProcessedBy=?,ProcessedAt=UTC_TIMESTAMP(6) WHERE RefundID=?','ssii',[$input['status'],$input['note'],$admin,$id]);
    clevoCreateNotification($db,(int)$r['UserID'],'تحديث طلب الاسترجاع','تم تحديث حالة طلب الاسترجاع.','info','booking-history.php','Refund request update','Your refund request was reviewed.');clevo_db_audit($db,$admin,'refund.reviewed','refund_requests',$id);
}
function clevo_api_admin(mysqli $db,string $action,array $ctx,array $args,array $input):never
{
    $user=(int)$ctx['account']['UserID'];$id=(int)($args['id']??0);
    if($action==='security')clevo_api_result(['authentication'=>'enabled','role'=>'admin']);
    if($action==='verification')clevo_api_verification($db,$id);
    if($action==='users'){
        $where=['1=1'];$params=[];$types='';
        $role=clevo_api_enum('role',['admin','owner','customer','driver']);if($role){$where[]=$role==='customer'?"LOWER(Role) IN ('user','customer')":'LOWER(Role)=?';if($role!=='customer'){$params[]=$role;$types.='s';}}
        $status=clevo_api_enum('status',['active','pending','rejected']);if($status){$where[]='AccountStatus=?';$params[]=$status;$types.='s';}
        $q=clevo_api_query('query');if($q){$where[]='(UserName LIKE ? OR Email LIKE ? OR PhoneNumber LIKE ?)';$like='%'.addcslashes($q,'\\%_').'%';array_push($params,$like,$like,$like);$types.='sss';}
        clevo_api_result(['users'=>clevo_api_list($db,'UserID,UserName,Email,PhoneNumber,Role,AccountStatus,StationID,EmailVerifiedAt','FROM user',implode(' AND ',$where),$types,$params,['id'=>'UserID','name'=>'UserName'],'id','UserID')]);
    }
    if($action==='user')clevo_api_result(['user'=>clevo_api_require_row(clevo_stmt($db,'SELECT UserID,UserName,Email,PhoneNumber,Role,AccountStatus,StationID,EmailVerifiedAt FROM user WHERE UserID=?','i',[$id])->get_result()->fetch_assoc())]);
    if($action==='stations'){
        $where='1=1';$params=[];$types='';$status=clevo_api_enum('status',['approved','pending','blocked','archived']);if($status){$where='admin_status=?';$params[]=$status;$types='s';}
        clevo_api_result(['stations'=>clevo_api_list($db,'StationID,UserID,Name,Location,Status,admin_status,ArchivedAt','FROM stations',$where,$types,$params,['id'=>'StationID','name'=>'Name'],'id','StationID')]);
    }
    if($action==='station')clevo_api_result(['station'=>clevo_api_require_row(clevo_stmt($db,'SELECT StationID,UserID,Name,Location,Lat,Lon,PhoneNumber,Status,admin_status,ArchivedAt FROM stations WHERE StationID=?','i',[$id])->get_result()->fetch_assoc())]);
    if($action==='bookings')clevo_api_result(['bookings'=>clevo_api_booking_list($db,$ctx,'all')]);
    if($action==='booking')clevo_api_result(['booking'=>clevo_booking_dto(clevo_api_booking($db,$ctx,$id),'admin')]);
    if($action==='financial'){
        $start=clevo_api_query('from','1970-01-01');$end=clevo_api_query('to','9999-12-30');
        foreach([$start,$end]as $date){$p=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$p||$p->format('Y-m-d')!==$date)clevo_api_invalid('query.date');}if($start>$end)clevo_api_invalid('query.date');
        $report=clevo_stmt($db,"SELECT COUNT(*) completed_bookings,COALESCE(SUM(Total_Price),0) nominal_gmv,COALESCE(SUM(Admin_Commission),0) nominal_commission FROM booking WHERE Status='Completed' AND Booking_Date>=? AND Booking_Date<=?",'ss',[$start,$end])->get_result()->fetch_assoc();
        $report['receipts']=clevo_stmt($db,'SELECT Method,Status,COALESCE(SUM(Amount),0) amount,COUNT(*) count FROM payments WHERE CreatedAt>=? AND CreatedAt<DATE_ADD(?,INTERVAL 1 DAY) GROUP BY Method,Status ORDER BY Method,Status','ss',[$start,$end])->get_result()->fetch_all(MYSQLI_ASSOC);
        $report['currency']='JOD';$report['from']=$start;$report['to']=$end;$report['reconciled_accounting']=false;clevo_api_result(['report'=>$report]);
    }
    if($action==='plans')clevo_api_result(['plans'=>clevo_api_list($db,'id,name_ar,name_en,price,duration,duration_type,wash_count,is_active,description_ar,description_en,included_services_ar,included_services_en','FROM plans','1=1','',[],['id'=>'id','price'=>'price'],'id','id')]);
    if($action==='subscriptions')clevo_api_result(['subscriptions'=>clevo_api_list($db,'id,user_id,plan_id,start_date,end_date,status,remaining_washes,PurchasePrice,PurchasedWashes,PlanNameSnapshot','FROM user_plans','1=1','',[],['id'=>'id','end_date'=>'end_date'],'id','id')]);
    if(in_array($action,['support','disputes'],true)){
        $where=$action==='disputes'?"Category='dispute'":'1=1';$params=[];$types='';$status=clevo_api_enum('status',['New','Replied','Closed']);if($status){$where.=' AND status=?';$params[]=$status;$types='s';}
        clevo_api_result(['support_requests'=>clevo_api_list($db,'id,user_id,user_name,user_type,subject,status,Category,BookingID,created_at','FROM support_tickets',$where,$types,$params,['id'=>'id','created_at'=>'created_at'],'id','id')]);
    }
    if($action==='support_detail'){
        $t=clevo_api_ticket($db,$user,$id,true);clevo_api_result(['support_request'=>clevo_api_ticket_dto($t),'messages'=>clevo_api_list($db,'MessageID,SenderRole,Message,CreatedAt','FROM support_messages','TicketID=?','i',[$id],['id'=>'MessageID','created_at'=>'CreatedAt'],'id','MessageID')]);
    }
    if($action==='refunds')clevo_api_result(['refund_requests'=>clevo_api_list($db,'RefundID,UserID,BookingID,Reason,Status,DecisionNote,CreatedAt,ProcessedAt','FROM refund_requests','1=1','',[],['id'=>'RefundID','created_at'=>'CreatedAt'],'id','RefundID')]);
    if($action==='withdrawals')clevo_api_result(['withdrawals'=>clevo_api_list($db,'id,owner_id,wallet_id,amount,status,requested_at,processed_at','FROM owner_withdrawal_requests','1=1','',[],['id'=>'id','amount'=>'amount','requested_at'=>'requested_at'],'id','id')]);
    if($action==='withdrawal'){
        $r=clevo_api_require_row(clevo_stmt($db,'SELECT id,owner_id,amount,status,bank_snapshot,requested_at,processed_at FROM owner_withdrawal_requests WHERE id=?','i',[$id])->get_result()->fetch_assoc());clevo_db_audit($db,$user,'withdrawal.bank_viewed','owner_withdrawal_requests',$id);$r['bank_snapshot']=json_decode($r['bank_snapshot']??'null',true);clevo_api_result(['withdrawal'=>$r]);
    }
    $result=clevo_api_mutate($db,$ctx,'admin.'.$action,['id'=>$id]+$input,function()use($db,$user,$id,$action,$input):array{
        switch($action){
            case 'user_status':clevo_market_account_status($db,$user,$id,$input['status']);return ['user_id'=>$id];
            case 'station_status':
                $s=clevo_api_require_row(clevo_stmt($db,'SELECT UserID,ArchivedAt FROM stations WHERE StationID=? FOR UPDATE','i',[$id])->get_result()->fetch_assoc());
                if($s['ArchivedAt'])throw new ClevoSecurityException('station_archived',409);
                if($input['admin_status']==='approved'){
                    $owner=clevo_api_require_row(clevo_account($db,(int)$s['UserID'],true));
                    if(clevo_role($owner['Role'])!=='owner'||!$owner['EmailVerifiedAt'])throw new ClevoSecurityException('owner_verification_required',409);
                    if($owner['AccountStatus']==='rejected')throw new ClevoSecurityException('owner_restricted',409);
                    clevo_stmt($db,"UPDATE user SET AccountStatus='active' WHERE UserID=?",'i',[(int)$s['UserID']]);
                }
                clevo_stmt($db,'UPDATE stations SET admin_status=?,Status=IF(?=\'approved\',Status,\'Closed\') WHERE StationID=?','ssi',[$input['admin_status'],$input['admin_status'],$id]);
                clevo_db_audit($db,$user,'station.moderated','stations',$id);return ['station_id'=>$id];
            case 'station_archive':clevo_archive_catalog($db,$user,'station',$id);return ['station_id'=>$id,'archived'=>true];
            case 'plan_create':return ['plan_id'=>clevo_market_plan($db,$user,0,$input)];
            case 'plan_update':return ['plan_id'=>clevo_market_plan($db,$user,$id,$input)];
            case 'plan_delete':clevo_api_require_row(clevo_stmt($db,'SELECT id FROM plans WHERE id=? FOR UPDATE','i',[$id])->get_result()->fetch_assoc());clevo_archive_catalog($db,$user,'plan',$id);return ['plan_id'=>$id,'active'=>false];
            case 'subscription_status':clevo_api_require_row(clevo_stmt($db,'SELECT id FROM user_plans WHERE id=? FOR UPDATE','i',[$id])->get_result()->fetch_assoc());clevo_stmt($db,"UPDATE user_plans SET status='cancelled' WHERE id=?",'i',[$id]);clevo_db_audit($db,$user,'subscription.cancelled','user_plans',$id);return ['subscription_id'=>$id];
            case 'support_reply':clevo_market_support_reply($db,$user,$id,$input['message'],true);return ['ticket_id'=>$id];
            case 'support_status':clevo_api_ticket($db,$user,$id,true,true);clevo_stmt($db,'UPDATE support_tickets SET status=? WHERE id=?','si',[$input['status'],$id]);clevo_db_audit($db,$user,'support.status','support_tickets',$id);return ['ticket_id'=>$id];
            case 'refund_decision':clevo_market_refund_decision($db,$user,$id,$input);return ['refund_id'=>$id];
            case 'withdrawal_status':clevo_market_withdrawal_status($db,$user,$id,$input['status']);return ['withdrawal_id'=>$id];
            default:throw new LogicException('Unimplemented admin route.');
        }
    });clevo_api_result($result,in_array($action,['plan_create','support_reply'],true)?201:200);
}
