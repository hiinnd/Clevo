<?php
declare(strict_types=1);

function clevo_payment_order(mysqli $db,int $user,string $id,bool $admin=false,bool $lock=false):array
{
    $sql='SELECT * FROM payment_orders WHERE OrderID=?'.($admin?'':' AND UserID=?').($lock?' FOR UPDATE':'');
    $row=clevo_stmt($db,$sql,$admin?'s':'si',$admin?[$id]:[$id,$user])->get_result()->fetch_assoc();
    if(!$row)throw new ClevoSecurityException('not_found',404);return $row;
}
function clevo_payment_order_dto(array $row):array
{
    $dto=array_intersect_key($row,array_flip(['OrderID','BookingID','PlanID','Purpose','State','Currency','CreatedAt','UpdatedAt','ExpiresAt','VerifiedAt','CancelRequestedAt']));
    $dto['amount_minor']=(int)$row['AmountMinor'];$dto['amount']=clevo_finance_decimal((int)$row['AmountMinor']);$dto['sandbox']=true;$dto['checkout_url']=null;
    if($row['State']==='pending'&&empty($row['CancelRequestedAt'])&&!empty($row['CheckoutCipher'])&&strtotime($row['ExpiresAt'].' UTC')>time())$dto['checkout_url']=clevo_decrypt_mail($row['CheckoutCipher'])['url']??null;
    return $dto;
}
function clevo_payment_open_booking(mysqli $db,int $booking):void
{
    if(clevo_stmt($db,"SELECT OrderID FROM payment_orders WHERE BookingID=? AND State IN ('prepared','creating','pending','unknown','refund_due') LIMIT 1 FOR UPDATE",'i',[$booking])->get_result()->fetch_assoc())throw new ClevoSecurityException('online_payment_in_progress',409);
}
function clevo_checkout_create(mysqli $db,int $user,array $input,string $key,?ClevoPaymentProvider $provider=null):array
{
    $purpose=$input['purpose']??'';
    if(!in_array($purpose,['booking','subscription'],true)||isset($input['booking_id'],$input['plan_id']))throw new ClevoSecurityException('invalid_payment_target',422);
    $target=$input[$purpose==='booking'?'booking_id':'plan_id']??0;
    if(!is_int($target)||$target<1||$target>2147483647)throw new ClevoSecurityException('invalid_payment_target',422);
    $provider??=clevo_payment_provider();
    if(clevo_production())throw new ClevoSecurityException('payment_production_not_approved',503);
    if(parse_url(clevo_public_url('api/v1/webhooks/paytabs'),PHP_URL_SCHEME)!=='https')throw new ClevoSecurityException('payment_callback_https_required',503);
    $purpose=$input['purpose'];$target=(int)($input[$purpose==='booking'?'booking_id':'plan_id']??0);
    $prepared=clevo_db_idempotent($db,$user,'online.prepare',$key,[$purpose,$target],function()use($db,$user,$purpose,$target):array {
        $a=clevo_account($db,$user,true);
        if(!clevo_account_active($db,$a)||clevo_role($a['Role'])!=='customer')throw new ClevoSecurityException('forbidden',403);
        $intent=$purpose.':'.$user.':'.$target;
        $old=clevo_stmt($db,'SELECT OrderID FROM payment_orders WHERE ActiveIntent=? FOR UPDATE','s',[$intent])->get_result()->fetch_assoc();
        if($old)return ['order_id'=>$old['OrderID']];
        $terms=null;$booking=null;$plan=null;
        if($purpose==='booking'){
            $b=clevo_booking_lock($db,$target);
            if((int)$b['UserID']!==$user)throw new ClevoSecurityException('not_found',404);
            if($b['Status']!=='Accepted'||!empty($b['Payment_Method']))throw new ClevoSecurityException('payment_already_set_or_expired',409);
            clevo_payment_window($b);$amount=clevo_finance_minor(clevoBookingPayableAmount($b));$booking=$target;
        }elseif($purpose==='subscription'){
            if(clevoGetActiveUserPlan($db,$user,true))throw new ClevoSecurityException('active_subscription_exists',409);
            if(clevo_stmt($db,"SELECT OrderID FROM payment_orders WHERE UserID=? AND Purpose='subscription' AND State IN ('prepared','creating','pending','unknown') FOR UPDATE",'i',[$user])->get_result()->fetch_assoc())throw new ClevoSecurityException('subscription_payment_in_progress',409);
            $p=clevo_stmt($db,'SELECT * FROM plans WHERE id=? AND is_active=1 FOR UPDATE','i',[$target])->get_result()->fetch_assoc();
            if(!$p)throw new ClevoSecurityException('plan_unavailable',409);
            $terms=clevo_finance_plan_terms($p);$amount=$terms['price_minor'];$plan=$target;
        }else throw new ClevoSecurityException('invalid_payment_purpose',422);
        if($amount<=0)throw new ClevoSecurityException('invalid_amount',422);
        $id=bin2hex(random_bytes(24));
        clevo_stmt($db,"INSERT INTO payment_orders (OrderID,UserID,BookingID,PlanID,Purpose,Provider,Environment,AmountMinor,TermsSnapshot,IntentKey,ExpiresAt) VALUES (?,?,?,?,?,'paytabs','sandbox',?,?,?,UTC_TIMESTAMP(6)+INTERVAL 10 MINUTE)",'siiisiss',[$id,$user,$booking,$plan,$purpose,$amount,$terms?json_encode($terms,JSON_THROW_ON_ERROR):null,$intent]);
        clevo_db_audit($db,$user,'payment.prepared','payment_order',0);return ['order_id'=>$id];
    });
    $id=$prepared['order_id'];
    $claim=clevo_db_atomic($db,function()use($db,$user,$id):bool {
        $order=clevo_payment_order($db,$user,$id,false,true);
        if($order['State']!=='prepared')return false;
        clevo_stmt($db,"UPDATE payment_orders SET State='creating' WHERE OrderID=?",'s',[$id]);return true;
    });
    if($claim){
        $order=clevo_payment_order($db,$user,$id);
        try{
            // Never hold a database transaction across the provider call. One persisted claim,
            // one create attempt; a timeout is UNKNOWN, never an automatic second charge attempt.
            $created=$provider->create($order,clevo_account($db,$user));
            clevo_db_atomic($db,function()use($db,$id,$created):void {
                clevo_stmt($db,"UPDATE payment_orders SET ProviderReference=?,CheckoutCipher=?,State='pending' WHERE OrderID=? AND State='creating'",'sss',[$created['reference'],clevo_encrypt_mail(['url'=>$created['url']]),$id]);
            });
        }catch(Throwable $e){
            clevo_stmt($db,"UPDATE payment_orders SET State='unknown' WHERE OrderID=? AND State='creating'",'s',[$id]);
            error_log('CLEVO payment creation requires reconciliation: '.get_class($e));
        }
    }
    return ['payment_order'=>clevo_payment_order_dto(clevo_payment_order($db,$user,$id))];
}

function clevo_payment_apply_evidence(mysqli $db,array $event,string $hash,string $source):array
{
    if(clevo_production())throw new ClevoSecurityException('payment_production_not_approved',503);
    return clevo_db_atomic($db,function()use($db,$event,$hash,$source):array {
        $id=$event['order'];$order=clevo_payment_order($db,0,$id,true,true);
        if($order['Provider']!=='paytabs'||$order['Environment']!=='sandbox'||$event['amount']!==(int)$order['AmountMinor'])throw new ClevoSecurityException('payment_amount_mismatch',422);
        if(!in_array($source,['signed_webhook','authenticated_query'],true))throw new LogicException('Unverified source.');
        if(clevo_stmt($db,'SELECT EvidenceID FROM payment_evidence WHERE EventHash=?','s',[$hash])->get_result()->fetch_assoc())return ['state'=>$order['State'],'duplicate'=>true];
        if($event['type']==='unknown'){
            $refund=clevo_stmt($db,'SELECT ProviderReference FROM provider_refunds WHERE OrderID=?','s',[$id])->get_result()->fetch_assoc();
            if(!empty($order['ProviderReference'])&&$event['reference']===$order['ProviderReference'])$event['type']='sale';
            elseif(!empty($refund['ProviderReference'])&&$event['reference']===$refund['ProviderReference']){$event['type']='refund';$event['parent']??=$order['ProviderReference'];}
            else throw new ClevoSecurityException('payment_reconciliation_required',409);
        }
        $isRefund=$event['type']==='refund';
        if(!$isRefund&&!empty($order['ProviderReference'])&&!hash_equals($order['ProviderReference'],$event['reference']))throw new ClevoSecurityException('payment_reference_mismatch',422);
        if($isRefund&&($event['parent']??'')!==$order['ProviderReference'])throw new ClevoSecurityException('payment_reference_mismatch',422);
        clevo_stmt($db,'INSERT INTO payment_evidence (OrderID,EventHash,Source,ProviderReference,TransactionType,ResultCode,AmountMinor) VALUES (?,?,?,?,?,?,?)','ssssssi',[$id,$hash,$source,$event['reference'],$event['type'],$event['status'],$event['amount']]);
        if($isRefund)return clevo_payment_apply_refund($db,$order,$event);
        if(empty($order['ProviderReference']))clevo_stmt($db,'UPDATE payment_orders SET ProviderReference=? WHERE OrderID=?','ss',[$event['reference'],$id]);
        if(in_array($order['State'],['succeeded','refund_due','refunded'],true))return ['state'=>$order['State'],'duplicate'=>true];
        $status=$event['status'];
        if($status!=='A'){
            $state=match($status){'D','E'=>'failed','C','V','X'=>'cancelled',default=>'pending'};
            clevo_stmt($db,'UPDATE payment_orders SET State=? WHERE OrderID=?','ss',[$state,$id]);return ['state'=>$state];
        }
        $user=(int)$order['UserID'];$account=clevo_account($db,$user,true);
        $valid=clevo_account_active($db,$account)&&empty($order['CancelRequestedAt'])&&strtotime($order['ExpiresAt'].' UTC')>=time();$b=null;
        if($order['Purpose']==='booking'){
            $b=clevo_booking_lock($db,(int)$order['BookingID']);
            $valid=$valid&&$b['Status']==='Accepted'&&empty($b['Payment_Method'])&&clevo_finance_minor(clevoBookingPayableAmount($b))===(int)$order['AmountMinor'];
            if($valid){try{clevo_payment_window($b);}catch(ClevoSecurityException $e){$valid=false;}}
        }else{
            // A second independent paid plan never overwrites an active entitlement.
            if(clevoGetActiveUserPlan($db,$user,true))$valid=false;
        }
        $amount=(int)$order['AmountMinor'];$liability=$valid?($order['Purpose']==='booking'?'booking_deferred:'.$order['BookingID']:'subscription_deferred:'.$id):'unapplied_captures:'.$id;
        $journal=clevo_finance_post($db,'capture:'.$id,'capture','sandbox',['provider_clearing'=>$amount,$liability=>-$amount],['order'=>$id,'booking'=>$order['BookingID'],'gross'=>$amount]);
        $state=$valid?'succeeded':'refund_due';
        clevo_stmt($db,'UPDATE payment_orders SET State=?,VerifiedAt=UTC_TIMESTAMP(6) WHERE OrderID=?','ss',[$state,$id]);
        clevo_finance_receipt($db,$journal,$user,$order['BookingID'],$id,$amount,'signed_or_queried_paytabs_sandbox');
        if($valid&&$b){
            clevo_stmt($db,"INSERT INTO payments (BookingID,UserID,Method,Status,Amount,Provider,ProviderReference,FundingOrderID,VerificationKind,VerifiedAt) VALUES (?,?,'visa','recorded',?,'paytabs',?,?,'paytabs_sandbox',UTC_TIMESTAMP(6))",'iisss',[(int)$b['BookingID'],$user,clevo_db_decimal(intdiv($amount,10)),$event['reference'],$id]);
            clevo_stmt($db,"UPDATE booking SET Payment_Method='visa' WHERE BookingID=?",'i',[(int)$b['BookingID']]);clevo_finance_snapshot($db,(int)$b['BookingID'],$amount);
        }elseif($valid){$order['State']='succeeded';clevo_finance_activate_plan($db,$order);}
        clevoCreateNotification($db,$user,$valid?'تم تأكيد الدفع':'دفعة تحتاج مراجعة',$valid?'تم تأكيد نتيجة الدفع التجريبية.':'وصل تأكيد دفع بعد انتهاء الأهلية ويحتاج إلى مراجعة واسترجاع.','info','booking-history.php',$valid?'Sandbox payment confirmed':'Payment review required',$valid?'Verified sandbox payment result.':'A confirmed payment needs reconciliation/refund review.');
        clevo_db_audit($db,null,'payment.confirmation_processed','payment_order',0);
        return ['state'=>$state,'duplicate'=>false];
    });
}

function clevo_payment_reconcile_order(mysqli $db,array $order,?ClevoPaymentProvider $provider=null):array
{
    $provider??=clevo_payment_provider();$event=$provider->query($order);
    if($event['order']!==$order['OrderID'])throw new ClevoSecurityException('payment_reference_mismatch',422);
    if(!empty($order['ProviderReference'])&&$event['reference']!==$order['ProviderReference'])throw new ClevoSecurityException('payment_reference_mismatch',422);
    return clevo_payment_apply_evidence($db,$event,hash('sha256','query:'.json_encode($event,JSON_THROW_ON_ERROR)),'authenticated_query');
}
