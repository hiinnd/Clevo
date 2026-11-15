<?php
declare(strict_types=1);

function clevo_api_payment_dto(array $p):array {return array_intersect_key($p,array_flip(['PaymentID','BookingID','Method','Status','Amount','Currency','CreatedAt','RefundedAt']));}
function clevo_market_payment_session(mysqli $db,int $user,array $input):array
{
    $method=$input['method'];if($method==='visa')throw new ClevoSecurityException('payment_gateway_not_configured',503);
    $b=clevo_api_require_row(clevo_stmt($db,'SELECT * FROM booking WHERE BookingID=? AND UserID=? FOR UPDATE','ii',[$input['booking_id'],$user])->get_result()->fetch_assoc());
    if($b['Status']!=='Accepted'||!empty($b['Payment_Method']))throw new ClevoSecurityException('payment_already_set_or_expired',409);
    clevo_payment_window($b);
    if($method==='cash'&&$b['Service_Location']!=='station')throw new ClevoSecurityException('not_allowed',422);
    $points=(bool)($input['use_points']??false);$quote=$method==='plan'?['payable'=>0,'points'=>0]:clevoPreviewPointsDiscount($db,$user,(float)$b['Total_Price'],$points);
    if($method==='plan'&&!clevoGetActiveUserPlan($db,$user))throw new ClevoSecurityException('insufficient_plan',409);
    $id=bin2hex(random_bytes(24));
    clevo_stmt($db,"INSERT INTO payment_sessions (SessionID,UserID,BookingID,Method,UsePoints,QuotedAmount,QuotedPoints,ExpiresAt) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(6)+INTERVAL 10 MINUTE)",'siisiss',[$id,$user,(int)$b['BookingID'],$method,(int)$points,clevo_db_decimal(clevo_db_money($quote['payable'])),clevo_db_decimal(clevo_db_money($quote['points']))]);
    return ['payment_session'=>clevo_market_session_read($db,$user,$id)];
}
function clevo_market_session_read(mysqli $db,int $user,string $id,bool $lock=false):array
{
    $r=clevo_api_require_row(clevo_stmt($db,'SELECT SessionID,BookingID,Method,UsePoints,QuotedAmount,QuotedPoints,Status,PaymentID,ExpiresAt,CreatedAt,(ExpiresAt<=UTC_TIMESTAMP(6)) IsExpired FROM payment_sessions WHERE SessionID=? AND UserID=?'.($lock?' FOR UPDATE':''),'si',[$id,$user])->get_result()->fetch_assoc());
    if($r['Status']==='created'&&(int)$r['IsExpired'])$r['Status']='expired';unset($r['IsExpired']);return $r;
}
function clevo_market_refund_request(mysqli $db,int $user,array $input):int
{
    $b=clevo_api_require_row(clevo_stmt($db,'SELECT b.BookingID FROM booking b WHERE b.BookingID=? AND b.UserID=? FOR UPDATE','ii',[$input['booking_id'],$user])->get_result()->fetch_assoc());
    $p=clevo_stmt($db,"SELECT Method,Status FROM payments WHERE BookingID=? AND UserID=?",'ii',[(int)$b['BookingID'],$user])->get_result()->fetch_assoc();
    if(!$p)throw new ClevoSecurityException('legacy_payment_requires_reconciliation',409);
    if($p['Status']!=='recorded')throw new ClevoSecurityException('payment_not_refundable',409);
    clevo_stmt($db,'INSERT INTO refund_requests (UserID,BookingID,Reason) VALUES (?,?,?)','iis',[$user,(int)$b['BookingID'],$input['reason']]);return (int)$db->insert_id;
}
function clevo_api_payments(mysqli $db,string $action,array $ctx,array $args,array $input):never
{
    $user=(int)$ctx['account']['UserID'];$id=(int)($args['id']??0);$session=$args['session']??'';
    if($action==='wallet'){
        $wallets=clevo_stmt($db,'SELECT Balance FROM wallet WHERE UserID=?','i',[$user])->get_result()->fetch_all(MYSQLI_ASSOC);if(count($wallets)>1)throw new ClevoSecurityException('wallet_requires_reconciliation',409);
        clevo_api_result(['wallet'=>$wallets[0]??['Balance'=>'0.00']]);
    }
    if($action==='wallet_transactions')clevo_api_result(['transactions'=>clevo_api_list($db,'t.TransactionID,t.Amount,t.TransactionType,t.Description_ar,t.Description_en,t.CreatedAt,t.BookingID','FROM wallet_transactions t JOIN wallet w ON w.WalletID=t.WalletID','w.UserID=?','i',[$user],['id'=>'t.TransactionID','amount'=>'t.Amount','created_at'=>'t.CreatedAt'],'id','t.TransactionID')]);
    if($action==='subscriptions')clevo_api_result(['subscriptions'=>clevo_api_list($db,'id,plan_id,start_date,end_date,status,remaining_washes,PurchasePrice,PurchasedWashes,PlanNameSnapshot,created_at','FROM user_plans','user_id=?','i',[$user],['id'=>'id','created_at'=>'created_at'],'id','id')]);
    if($action==='purchase'){
        if($input['method']!=='wallet')throw new ClevoSecurityException('payment_gateway_not_configured',503);
        clevo_api_result(clevo_purchase_plan($db,$user,$input['plan_id'],clevo_api_key()),201);
    }
    if($action==='booking_pay')clevo_api_result(clevo_pay_booking($db,$user,$id,$input['method'],(bool)($input['use_points']??false),clevo_api_key()));
    if($action==='list'){
        $where='UserID=?';$params=[$user];$types='i';$status=clevo_api_enum('status',['pending','recorded','refunded']);if($status){$where.=' AND Status=?';$params[]=$status;$types.='s';}
        $rows=clevo_api_list($db,'PaymentID,BookingID,Method,Status,Amount,Currency,CreatedAt,RefundedAt','FROM payments',$where,$types,$params,['id'=>'PaymentID','amount'=>'Amount','created_at'=>'CreatedAt'],'id','PaymentID');clevo_api_result(['payments'=>$rows]);
    }
    if($action==='detail')clevo_api_result(['payment'=>clevo_api_payment_dto(clevo_api_require_row(clevo_stmt($db,'SELECT * FROM payments WHERE PaymentID=? AND UserID=?','ii',[$id,$user])->get_result()->fetch_assoc()))]);
    if($action==='session')clevo_api_result(['payment_session'=>clevo_market_session_read($db,$user,$session)]);
    if($action==='session_create'){
        $r=clevo_api_mutate($db,$ctx,'payment.session',$input,fn()=>clevo_market_payment_session($db,$user,$input));clevo_api_result($r,201);
    }
    if($action==='confirm'){
        clevo_market_session_read($db,$user,$session);
        $r=clevo_api_mutate($db,$ctx,'payment.confirm',['session'=>$session],function()use($db,$user,$session):array{
            $p=clevo_market_session_read($db,$user,$session,true);if($p['Status']==='expired')throw new ClevoSecurityException('payment_session_expired',409);
            if($p['Status']!=='confirmed'){
                $b=clevo_api_require_row(clevo_stmt($db,'SELECT * FROM booking WHERE BookingID=? AND UserID=? FOR UPDATE','ii',[(int)$p['BookingID'],$user])->get_result()->fetch_assoc());
                clevo_stmt($db,'SELECT LoyaltyID FROM loyalty WHERE UserID=? ORDER BY LoyaltyID FOR UPDATE','i',[$user])->get_result()->fetch_all(MYSQLI_ASSOC);
                $q=$p['Method']==='plan'?['payable'=>0,'points'=>0]:clevoPreviewPointsDiscount($db,$user,(float)$b['Total_Price'],(bool)$p['UsePoints']);
                if(clevo_db_money($q['payable'])!==clevo_db_money($p['QuotedAmount'])||clevo_db_money($q['points'])!==clevo_db_money($p['QuotedPoints']))throw new ClevoSecurityException('payment_quote_changed',409);
                clevo_pay_booking($db,$user,(int)$p['BookingID'],$p['Method'],(bool)$p['UsePoints'],hash('sha256','payment-session:'.$session));
                $receipt=clevo_stmt($db,'SELECT PaymentID FROM payments WHERE BookingID=? AND UserID=?','ii',[(int)$p['BookingID'],$user])->get_result()->fetch_assoc();
                clevo_stmt($db,"UPDATE payment_sessions SET Status='confirmed',PaymentID=? WHERE SessionID=?",'is',[(int)$receipt['PaymentID'],$session]);
            }
            return ['payment_session'=>clevo_market_session_read($db,$user,$session)];
        });clevo_api_result($r);
    }
    if($action==='refunds')clevo_api_result(['refund_requests'=>clevo_api_list($db,'RefundID,BookingID,Reason,Status,DecisionNote,CreatedAt,ProcessedAt','FROM refund_requests','UserID=?','i',[$user],['id'=>'RefundID','created_at'=>'CreatedAt'],'id','RefundID')]);
    if($action==='refund_create'){$r=clevo_api_mutate($db,$ctx,'refund.request',$input,fn()=>['refund_id'=>clevo_market_refund_request($db,$user,$input)]);clevo_api_result($r,201);}
    throw new LogicException('Unimplemented payment route.');
}
