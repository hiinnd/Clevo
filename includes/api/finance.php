<?php
declare(strict_types=1);

function clevo_api_finance(mysqli $db,string $action,array $ctx,array $args,array $input):never
{
    if($action==='webhook'){
        $provider=clevo_payment_provider();
        if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json')throw new ClevoSecurityException('content_type_required',415);
        $body=(string)file_get_contents('php://input',false,null,0,65537);if(strlen($body)>65536)throw new ClevoSecurityException('payload_too_large',413);
        $event=$provider->verify($body,(string)($_SERVER['HTTP_SIGNATURE']??''));
        clevo_api_result(['received'=>true]+clevo_payment_apply_evidence($db,$event,hash('sha256',$body),'signed_webhook'));
    }
    $user=(int)$ctx['account']['UserID'];$role=clevo_role($ctx['account']['Role']);$id=(int)($args['id']??0);$session=$args['session']??'';
    if($action==='checkout'){
        if(isset($input['booking_id'],$input['plan_id']))throw new ClevoSecurityException('invalid_payment_target',422);
        clevo_api_result(clevo_checkout_create($db,$user,$input,clevo_api_key()),201);
    }
    if($action==='orders'){
        $rows=clevo_api_list($db,'*','FROM payment_orders','UserID=?','i',[$user],['id'=>'OrderID','created_at'=>'CreatedAt'],'created_at','OrderID');clevo_api_result(['payment_orders'=>array_map('clevo_payment_order_dto',$rows)]);
    }
    if($action==='order')clevo_api_result(['payment_order'=>clevo_payment_order_dto(clevo_payment_order($db,$user,$session))]);
    if($action==='cancel'){
        $result=clevo_api_mutate($db,$ctx,'online.cancel',['order'=>$session],function()use($db,$user,$session):array {
            $o=clevo_payment_order($db,$user,$session,false,true);
            if(!in_array($o['State'],['prepared','creating','pending','unknown'],true))throw new ClevoSecurityException('payment_not_cancellable',409);
            clevo_stmt($db,"UPDATE payment_orders SET CancelRequestedAt=COALESCE(CancelRequestedAt,UTC_TIMESTAMP(6)),State=IF(State='prepared','cancelled',State) WHERE OrderID=?",'s',[$session]);
            return ['order_id'=>$session,'state'=>$o['State']==='prepared'?'cancelled':$o['State'],'cancel_requested'=>true,'provider_cancellation_confirmed'=>false];
        });clevo_api_result($result);
    }
    if($action==='reconcile'){
        $o=clevo_payment_order($db,$user,$session);clevo_rate_limit($db,'payment:provider-query',empty($o['ProviderReference'])?'cart':'reference',empty($o['ProviderReference'])?15:45,60);
        clevo_api_result(clevo_payment_reconcile_order($db,$o));
    }
    if($action==='refund_request')clevo_api_result(clevo_api_mutate($db,$ctx,'online.refund-request',['order'=>$session]+$input,fn()=>clevo_payment_refund_request($db,$user,$session,$input['reason'])),201);
    if($action==='refund_submit'){
        clevo_db_idempotent($db,$user,'online.refund-submit',clevo_api_key(),[$id],fn()=>['refund_id'=>$id]);
        clevo_api_result(clevo_payment_refund_submit($db,$user,$id));
    }
    if($action==='refunds'){
        $where=$role==='admin'?'1=1':'o.UserID=?';
        clevo_api_result(['refunds'=>clevo_api_list($db,'r.RefundID,r.OrderID,r.State,r.AmountMinor,r.Reason,r.Origin,r.CreatedAt,r.UpdatedAt','FROM provider_refunds r JOIN payment_orders o ON o.OrderID=r.OrderID',$where,$role==='admin'?'':'i',$role==='admin'?[]:[$user],['id'=>'r.RefundID','created_at'=>'r.CreatedAt'],'id','r.RefundID')]);
    }
    if($action==='receipts')clevo_api_result(['receipts'=>clevo_api_list($db,'ReceiptID,ReceiptNumber,BookingID,OrderID,AmountMinor,EvidenceKind,IssuedAt','FROM financial_receipts','UserID=?','i',[$user],['id'=>'ReceiptID','created_at'=>'IssuedAt'],'id','ReceiptID')]);
    if($action==='receipt'){
        $where=$role==='admin'?'ReceiptID=?':'ReceiptID=? AND UserID=?';
        $r=clevo_api_require_row(clevo_stmt($db,'SELECT ReceiptID,ReceiptNumber,BookingID,OrderID,AmountMinor,EvidenceKind,IssuedAt FROM financial_receipts WHERE '.$where,$role==='admin'?'i':'ii',$role==='admin'?[$id]:[$id,$user])->get_result()->fetch_assoc());$r['currency']='JOD';$r['amount']=clevo_finance_decimal((int)$r['AmountMinor']);$r['is_tax_invoice']=false;clevo_api_result(['receipt'=>$r]);
    }
    if($action==='report')clevo_api_result(['reconciliation'=>clevo_finance_report($db)]);
    if($action==='owner_statement'){
        // Scope through the owner's actual account lines. A package refund may reverse
        // several owners and has no single OwnerID on its immutable journal header.
        $from="FROM financial_journals j JOIN (SELECT JournalID,SUM(CASE WHEN AccountKey LIKE 'owner_payable:%' THEN -SignedMinor ELSE 0 END) OwnerPayableDeltaMinor,SUM(CASE WHEN AccountKey LIKE 'cash_commission_due:%' THEN SignedMinor ELSE 0 END) CashCommissionDueDeltaMinor FROM financial_entries WHERE AccountKey IN (?,?) GROUP BY JournalID) own ON own.JournalID=j.JournalID";
        clevo_api_result(['summary'=>clevo_finance_owner_summary($db,$user),'transactions'=>clevo_api_list($db,'j.JournalID,j.BookingID,j.OrderID,j.Kind,j.Book,j.CreatedAt,own.OwnerPayableDeltaMinor,own.CashCommissionDueDeltaMinor',$from,'1=1','ss',['owner_payable:'.$user,'cash_commission_due:'.$user],['id'=>'j.JournalID','created_at'=>'j.CreatedAt'],'id','j.JournalID')]);
    }
    if($action==='settlements'){
        $where=$role==='admin'?'1=1':'OwnerID=?';
        clevo_api_result(['settlements'=>clevo_api_list($db,'SettlementID,OwnerID,AmountMinor,State,CreatedAt','FROM financial_settlements',$where,$role==='admin'?'':'i',$role==='admin'?[]:[$user],['id'=>'SettlementID','created_at'=>'CreatedAt'],'id','SettlementID')]);
    }
    if($action==='settlement_request')clevo_api_result(clevo_api_mutate($db,$ctx,'finance.settlement-request',$input,fn()=>clevo_finance_settlement_request($db,$user,$input['owner_id'],clevo_finance_minor($input['amount']))),201);
    throw new LogicException('Unknown financial API action.');
}
