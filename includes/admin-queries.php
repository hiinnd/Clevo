<?php
declare(strict_types=1);

function clevo_admin_rows(mysqli $db,int $actor,string $view,int $page,string $query='',string $status=''):array
{
    clevo_admin_actor($db,$actor);
    $specs=[
        'bookings'=>['b.BookingID,b.Order_Number,b.UserID,b.StationID,b.Booking_Date,b.Booking_Time,b.Status,b.Payment_Method,b.Total_Price,s.Name station_name,u.UserName customer','booking b JOIN stations s ON s.StationID=b.StationID JOIN user u ON u.UserID=b.UserID','b.BookingID','b.Order_Number','b.Status','1=1'],
        'people'=>['u.UserID,u.UserName,u.Email,u.PhoneNumber,u.Role,u.AccountStatus,u.EmailVerifiedAt,u.StationID','user u','u.UserID','u.UserName','u.AccountStatus','1=1'],
        'services'=>['v.ServiceID,v.StationID,v.Name_ar,v.Name_en,v.Price,v.Duration,v.is_station,v.is_home,v.is_pickup,v.ArchivedAt,s.Name station_name','services v JOIN stations s ON s.StationID=v.StationID','v.ServiceID','v.Name_en','','v.ArchivedAt IS NULL'],
        'subscriptions'=>['up.id,up.user_id,up.plan_id,up.status,up.remaining_washes,up.start_date,up.end_date,up.PlanNameSnapshot,up.FundingOrderID,u.UserName','user_plans up JOIN user u ON u.UserID=up.user_id','up.id','u.UserName','up.status','1=1'],
        'refunds'=>['r.RefundID,r.BookingID,r.UserID,r.Reason,r.Status,r.DecisionNote,r.CreatedAt','refund_requests r','r.RefundID','r.Reason','r.Status','1=1'],
        'provider_refunds'=>['r.RefundID,r.OrderID,r.State,r.AmountMinor,r.Reason,r.CreatedAt','provider_refunds r','r.RefundID','r.Reason','r.State','1=1'],
        'disputes'=>['t.id,t.user_id,t.user_name,t.subject,t.Category,t.BookingID,t.status,t.created_at','support_tickets t','t.id','t.subject','t.status',"t.Category='dispute'"],
        'support'=>['t.id,t.user_id,t.user_name,t.subject,t.Category,t.BookingID,t.status,t.created_at','support_tickets t','t.id','t.subject','t.status','1=1'],
        'earnings'=>['u.UserID,u.UserName,u.AccountStatus','user u','u.UserID','u.UserName','u.AccountStatus',"LOWER(u.Role)='owner'"],
        'settlements'=>['s.SettlementID,s.OwnerID,s.AmountMinor,s.State,s.ProviderReference,s.CreatedAt,u.UserName','financial_settlements s JOIN user u ON u.UserID=s.OwnerID','s.SettlementID','u.UserName','s.State','1=1'],
        'audit'=>['a.AuditID,a.ActorID,a.Action,a.EntityType,a.EntityID,a.CreatedAt','audit_logs a','a.AuditID','a.Action','','1=1'],
    ];
    if(!isset($specs[$view])||$page<1||$page>100000||mb_strlen($query)>100||mb_strlen($status)>40)throw new ClevoSecurityException('invalid_filter',422);
    [$select,$from,$id,$search,$state,$where]=$specs[$view];$params=[];$types='';
    if($query!==''){$where.=" AND $search LIKE ?";$params[]='%'.addcslashes($query,'\\%_').'%';$types.='s';}
    if($status!==''&&$state!==''){$where.=" AND $state=?";$params[]=$status;$types.='s';}
    $params[]=26;$params[]=($page-1)*25;$types.='ii';
    $rows=clevo_stmt($db,"SELECT $select FROM $from WHERE $where ORDER BY $id DESC LIMIT ? OFFSET ?",$types,$params)->get_result()->fetch_all(MYSQLI_ASSOC);
    $more=count($rows)>25;$rows=array_slice($rows,0,25);
    if($view==='bookings')foreach($rows as &$row)$row['PaymentEvidence']=clevo_admin_payment_state($db,(int)$row['BookingID']);
    if($view==='earnings')foreach($rows as &$row)$row+=clevo_finance_owner_summary($db,(int)$row['UserID']);
    return ['rows'=>$rows,'has_more'=>$more,'page'=>$page];
}
