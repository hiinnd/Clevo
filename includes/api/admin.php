<?php
declare(strict_types=1);

require_once __DIR__.'/../admin-services.php';
require_once __DIR__.'/../admin-queries.php';

function clevo_api_admin(mysqli $db,string $action,array $ctx,array $args,array $input):never
{
    $user=(int)$ctx['account']['UserID'];$id=(int)($args['id']??0);
    clevo_admin_actor($db,$user);
    if($action==='notification_delivery')clevo_api_result(['delivery_counts'=>$db->query('SELECT Channel,State,LastError,COUNT(*) count,MAX(Attempts) max_attempts FROM notification_deliveries GROUP BY Channel,State,LastError')->fetch_all(MYSQLI_ASSOC)]);
    if($action==='statistics')clevo_api_result(['statistics'=>clevo_admin_dashboard_stats($db)]);
    if(in_array($action,['services','drivers','audit'],true)){
        $view=$action==='drivers'?'people':$action;
        if($action==='drivers'){
            clevo_api_result(['drivers'=>clevo_api_list($db,'UserID,UserName,Email,PhoneNumber,StationID,AccountStatus','FROM user',"LOWER(Role)='driver'",'',[],['id'=>'UserID'],'id','UserID')]);
        }
        clevo_api_result([$action=>clevo_admin_rows($db,$user,$view,clevo_api_integer('page',1,1,100000),clevo_api_query('query',''),clevo_api_query('status',''))]);
    }
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
    if($action==='booking')clevo_api_result(['booking'=>clevo_booking_dto(clevo_api_booking($db,$ctx,$id),'admin')+['PaymentEvidence'=>clevo_admin_payment_state($db,$id)]]);
    if($action==='financial'){
        $start=clevo_api_query('from','1970-01-01');$end=clevo_api_query('to','9999-12-30');
        foreach([$start,$end]as $date){$p=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$p||$p->format('Y-m-d')!==$date)clevo_api_invalid('query.date');}if($start>$end)clevo_api_invalid('query.date');
        $book=clevo_api_enum('book',['cash','sandbox'],'cash');
        $report=clevo_admin_financial_report($db,$start,$end,$book);
        clevo_api_result(['report'=>$report]);
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
        return clevo_admin_command($db,$user,$action,$id,$input);
    });clevo_api_result($result,in_array($action,['plan_create','support_reply','service_create','driver_create'],true)?201:200);
}
