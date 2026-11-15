<?php
declare(strict_types=1);

function clevo_payment_refund_request(mysqli $db,int $user,string $id,string $reason):array
{
    $order=clevo_payment_order($db,$user,$id,false,true);
    if(!in_array($order['State'],['succeeded','refund_due'],true))throw new ClevoSecurityException('payment_not_refundable',409);
    $old=clevo_stmt($db,'SELECT RefundID FROM provider_refunds WHERE OrderID=?','s',[$id])->get_result()->fetch_assoc();if($old)return ['refund_id'=>(int)$old['RefundID']];
    clevo_stmt($db,'INSERT INTO provider_refunds (OrderID,RequestedBy,Reason,AmountMinor) VALUES (?,?,?,?)','sisi',[$id,$user,$reason,(int)$order['AmountMinor']]);return ['refund_id'=>(int)$db->insert_id];
}
function clevo_payment_refund_submit(mysqli $db,int $admin,int $id,?ClevoPaymentProvider $provider=null):array
{
    $provider??=clevo_payment_provider();
    if(clevo_production())throw new ClevoSecurityException('payment_production_not_approved',503);
    $ref=clevo_stmt($db,'SELECT OrderID FROM provider_refunds WHERE RefundID=?','i',[$id])->get_result()->fetch_assoc();if(!$ref)throw new ClevoSecurityException('not_found',404);
    $order=clevo_db_atomic($db,function()use($db,$admin,$id,$ref):?array {
        $a=clevo_booking_actor($db,$admin);if(clevo_role($a['Role'])!=='admin')throw new ClevoSecurityException('forbidden',403);
        $o=clevo_payment_order($db,0,$ref['OrderID'],true,true);
        $r=clevo_stmt($db,'SELECT * FROM provider_refunds WHERE RefundID=? FOR UPDATE','i',[$id])->get_result()->fetch_assoc();
        if($r['State']!=='requested')return null;
        if(!in_array($o['State'],['succeeded','refund_due'],true))throw new ClevoSecurityException('payment_not_refundable',409);
        if($o['State']==='succeeded'&&$o['Purpose']==='booking'){
            $b=clevo_booking_lock($db,(int)$o['BookingID']);
            if(!in_array($b['Status'],['Pending','Accepted'],true)||!empty($b['DriverAcceptedAt']))throw new ClevoSecurityException('manual_refund_required',409);
        }elseif($o['State']==='succeeded'){
            clevo_stmt($db,'SELECT id FROM user_plans WHERE FundingOrderID=? FOR UPDATE','s',[$o['OrderID']])->get_result()->fetch_all(MYSQLI_ASSOC);
            $used=clevo_stmt($db,"SELECT r.RedemptionID FROM subscription_redemptions r JOIN user_plans up ON up.id=r.UserPlanID WHERE up.FundingOrderID=? AND r.State<>'released' LIMIT 1 FOR UPDATE",'s',[$o['OrderID']])->get_result()->fetch_assoc();
            if($used)throw new ClevoSecurityException('manual_refund_required',409);
            clevo_stmt($db,'UPDATE user_plans SET PaymentHold=1 WHERE FundingOrderID=?','s',[$o['OrderID']]);
        }
        clevo_stmt($db,"UPDATE provider_refunds SET State='submitting',SubmittedBy=? WHERE RefundID=?",'ii',[$admin,$id]);clevo_db_audit($db,$admin,'provider_refund.submitted','provider_refund',$id);return $o;
    });
    if($order){
        try{
            $event=$provider->refund($order);
            if($event['type']!=='refund'||$event['order']!==$order['OrderID']||$event['parent']!==$order['ProviderReference']||$event['amount']!==(int)$order['AmountMinor'])throw new ClevoSecurityException('payment_reference_mismatch',422);
            clevo_stmt($db,"UPDATE provider_refunds SET ProviderReference=?,State='pending' WHERE RefundID=? AND State='submitting'",'si',[$event['reference'],$id]);
            // Submission is not confirmation: a separate authenticated status query or
            // signed callback must verify the actual refund before any ledger reversal.
            $queryOrder=$order;$queryOrder['ProviderReference']=$event['reference'];
            $confirmed=$provider->query($queryOrder);
            if($confirmed['reference']!==$event['reference']||$confirmed['order']!==$order['OrderID']||$confirmed['type']!=='refund'||$confirmed['parent']!==$order['ProviderReference'])throw new ClevoSecurityException('payment_reference_mismatch',422);
            clevo_payment_apply_evidence($db,$confirmed,hash('sha256','query:'.json_encode($confirmed,JSON_THROW_ON_ERROR)),'authenticated_query');
        }catch(Throwable $e){
            clevo_stmt($db,"UPDATE provider_refunds SET State='unknown' WHERE RefundID=? AND State='submitting'",'i',[$id]);error_log('CLEVO refund requires reconciliation: '.get_class($e));
        }
    }
    return ['refund'=>clevo_stmt($db,'SELECT RefundID,OrderID,State,AmountMinor,CreatedAt,UpdatedAt FROM provider_refunds WHERE RefundID=?','i',[$id])->get_result()->fetch_assoc()];
}
function clevo_payment_apply_refund(mysqli $db,array $order,array $event):array
{
    $id=$order['OrderID'];$r=clevo_stmt($db,'SELECT * FROM provider_refunds WHERE OrderID=? FOR UPDATE','s',[$id])->get_result()->fetch_assoc();
    if(!$r){
        clevo_stmt($db,"INSERT INTO provider_refunds (OrderID,RequestedBy,Origin,Reason,AmountMinor,ProviderReference,State) VALUES (?,?,'provider_notification','Signed provider-reported refund',?,?,'pending')",'siis',[$id,(int)$order['UserID'],(int)$order['AmountMinor'],$event['reference']]);
        $r=clevo_stmt($db,'SELECT * FROM provider_refunds WHERE OrderID=?','s',[$id])->get_result()->fetch_assoc();
    }
    if($r['State']==='succeeded')return ['state'=>'refunded','duplicate'=>true];
    if(!empty($r['ProviderReference'])&&$r['ProviderReference']!==$event['reference'])throw new ClevoSecurityException('payment_reference_mismatch',422);
    if($event['status']!=='A'){
        $state=in_array($event['status'],['D','E','C','V','X'],true)?'failed':'pending';
        clevo_stmt($db,'UPDATE provider_refunds SET State=?,ProviderReference=? WHERE RefundID=?','ssi',[$state,$event['reference'],(int)$r['RefundID']]);
        if($state==='failed')clevo_stmt($db,'UPDATE user_plans SET PaymentHold=0 WHERE FundingOrderID=?','s',[$id]);
        return ['state'=>$order['State'],'refund_state'=>$state];
    }
    if(!in_array($order['State'],['succeeded','refund_due'],true))throw new ClevoSecurityException('refund_capture_confirmation_required',409);
    $amount=(int)$order['AmountMinor'];$entries=['provider_clearing'=>-$amount];
    $recognized=clevo_stmt($db,"SELECT JournalID,GrossMinor FROM financial_journals WHERE OrderID=? AND Kind='earnings'",'s',[$id])->get_result()->fetch_all(MYSQLI_ASSOC);$recognizedAmount=0;
    foreach($recognized as $journal){
        $recognizedAmount+=(int)$journal['GrossMinor'];
        foreach(clevo_stmt($db,"SELECT AccountKey,SignedMinor FROM financial_entries WHERE JournalID=? AND (AccountKey LIKE 'owner_payable:%' OR AccountKey='platform_commission')",'i',[(int)$journal['JournalID']])->get_result()->fetch_all(MYSQLI_ASSOC) as $line)$entries[$line['AccountKey']]=($entries[$line['AccountKey']]??0)-(int)$line['SignedMinor'];
    }
    $account=$order['State']==='refund_due'?'unapplied_captures:'.$id:($order['Purpose']==='booking'?'booking_deferred:'.$order['BookingID']:'subscription_deferred:'.$id);
    $entries[$account]=$amount-$recognizedAmount;
    $journal=clevo_finance_post($db,'refund:'.$id,'refund','sandbox',$entries,['order'=>$id,'booking'=>$order['BookingID'],'gross'=>$amount]);
    clevo_finance_receipt($db,$journal,(int)$order['UserID'],$order['BookingID'],$id,$amount,'verified_paytabs_sandbox_refund');
    clevo_stmt($db,"UPDATE provider_refunds SET State='succeeded',ProviderReference=? WHERE RefundID=?",'si',[$event['reference'],(int)$r['RefundID']]);
    clevo_stmt($db,"UPDATE payment_orders SET State='refunded' WHERE OrderID=?",'s',[$id]);
    clevo_stmt($db,"UPDATE payments SET Status='refunded',RefundedAt=UTC_TIMESTAMP(6) WHERE FundingOrderID=?",'s',[$id]);
    clevo_stmt($db,"UPDATE user_plans SET status='cancelled',remaining_washes=0,PaymentHold=1 WHERE FundingOrderID=?",'s',[$id]);
    if($order['BookingID']){
        $b=clevo_booking_lock($db,(int)$order['BookingID']);
        if(in_array($b['Status'],['Pending','Accepted'],true)&&empty($b['DriverAcceptedAt']))clevo_booking_transition($db,null,(int)$order['BookingID'],'Cancelled','provider_refund_confirmed');
    }
    clevoCreateNotification($db,(int)$order['UserID'],'تم تأكيد الاسترجاع','تم تأكيد نتيجة الاسترجاع التجريبية من مزود الدفع.','info','booking-history.php','Sandbox refund confirmed','The provider confirmed the sandbox refund.');
    return ['state'=>'refunded','duplicate'=>false];
}
