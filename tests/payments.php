<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/../includes/marketplace.php';
if(clevo_env('APP_ENV')!=='testing'||clevo_env('DB_HOST')!=='127.0.0.1'||!preg_match('/^clevo_security_test_[a-f0-9]{12}$/D',clevo_env('DB_DATABASE','')??''))exit(2);
$db=clevo_connect_database();if(strcasecmp(realpath($db->query('SELECT @@datadir d')->fetch_assoc()['d'])?:'',realpath(__DIR__.'/../.security-test/mysql')?:'!')!==0)exit(2);
$runtimeFile=__DIR__.'/../.security-test/testing-runtime.env';$original=file_get_contents($runtimeFile);$base=rtrim(clevo_env('APP_URL'),'/');$calls=0;
function pf_http(string $method,string $path,?array $data=null,?string $token=null,?string $key=null,?string $raw=null,?string $signature=null):array{
    global $db,$base,$calls;if(++$calls%20===0)$db->query('DELETE FROM security_rate_limits');$headers=[];
    if($token)$headers[]='Authorization: Bearer '.$token;if($key)$headers[]='Idempotency-Key: '.$key;if($signature!==null)$headers[]='Signature: '.$signature;
    if($data!==null||$raw!==null)$headers[]='Content-Type: application/json';$h=curl_init($base.$path);
    curl_setopt_array($h,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>$headers]);
    if($data!==null||$raw!==null)curl_setopt($h,CURLOPT_POSTFIELDS,$raw??json_encode($data?:new stdClass()));$body=(string)curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);return [$status,json_decode($body,true),$body];
}
function pf_expect(int $status,array $reply,string $label):array{check($reply[0]===$status,$label.' (HTTP '.$reply[0].')');return $reply[1]['data']??[];}
function pf_key():string{return bin2hex(random_bytes(16));}
function pf_actor(string $role,string $password):int{
    global $db;$random=bin2hex(random_bytes(6));clevo_stmt($db,"INSERT INTO user (UserName,Email,PhoneNumber,Password,Role,AccountStatus,EmailVerifiedAt) VALUES (?,?,?,?,?,'active',UTC_TIMESTAMP())",'sssss',['Finance '.$role,$random.'@finance.example.test','077'.str_pad((string)random_int(0,9999999),7,'0',STR_PAD_LEFT),password_hash($password,PASSWORD_DEFAULT),$role]);return (int)$db->insert_id;
}
function pf_booking(int $user,?int $selected=null,bool $plan=false):int{
    global $db,$station,$service;static $day=20;$day++;
    $body=['service_ids'=>[$selected??$service],'service_location'=>'station','booking_date'=>(new DateTimeImmutable('+'.$day.' days'))->format('Y-m-d'),'booking_time'=>'10:00','use_plan'=>$plan];
    return (int)clevo_create_booking($db,$user,$station,$body,pf_key())['booking_id'];
}
function pf_payload(array $o,string $status='A',string $type='Sale',?string $reference=null,?string $parent=null):array{
    global $profile,$merchant;
    $p=['profile_id'=>$profile,'merchant_id'=>$merchant,'cart_id'=>$o['OrderID'],'cart_currency'=>'JOD','cart_amount'=>clevo_finance_decimal((int)$o['AmountMinor']),'tran_currency'=>'JOD','tran_total'=>$status==='A'?clevo_finance_decimal((int)$o['AmountMinor']):'0.000','tran_ref'=>$reference??$o['ProviderReference'],'tran_type'=>$type,'payment_result'=>['response_status'=>$status,'transaction_time'=>'2026-11-16T00:00:00Z']];if($parent!==null)$p['previous_tran_ref']=$parent;return $p;
}
function pf_webhook(array $payload,int $status=200,string $label='Verified callback'):array{
    global $signingKey;$raw=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);return pf_expect($status,pf_http('POST','/api/v1/webhooks/paytabs',null,null,null,$raw,hash_hmac('sha256',$raw,$signingKey)),$label);
}
function pf_race(array $a,array $b):array{
    $jobs=[];$start=microtime(true)+0.6;
    foreach([$a,$b]as $input){$p=proc_open([PHP_BINARY,__DIR__.'/payment-race.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));fwrite($pipes[0],json_encode($input+['start'=>$start]));fclose($pipes[0]);$jobs[]=[$p,$pipes];}
    $out=[];foreach($jobs as[$p,$pipes]){$data=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);check($exit===0,'Concurrent financial worker exits cleanly');$out[]=json_decode($data,true)??['ok'=>false];}return $out;
}
function pf_web_page(int $user,string $password,string $path,?array $submit=null):string{
    global $base,$db;$jar=__DIR__.'/../.security-test/finance-'.pf_key().'.cookies';
    $h=curl_init($base.'/general/login.php');curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar,CURLOPT_TIMEOUT=>15]);$html=(string)curl_exec($h);curl_close($h);preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$html,$m);
    $h=curl_init($base.'/general/login.php');curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['login_action'=>1,'login_input'=>clevo_account($db,$user)['Email'],'password'=>$password,'csrf_token'=>$m[1]??'']),CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar,CURLOPT_TIMEOUT=>15]);curl_exec($h);check((int)curl_getinfo($h,CURLINFO_RESPONSE_CODE)===302,'Existing PHP financial login works');curl_close($h);
    $h=curl_init($base.$path);curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar,CURLOPT_TIMEOUT=>15]);$html=(string)curl_exec($h);check((int)curl_getinfo($h,CURLINFO_RESPONSE_CODE)===200,'Existing financial page renders with actual session');curl_close($h);
    if($submit!==null){preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$html,$m);$h=curl_init($base.$path);curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($submit+['csrf_token'=>$m[1]??'']),CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar,CURLOPT_TIMEOUT=>15]);$html=(string)curl_exec($h);check((int)curl_getinfo($h,CURLINFO_RESPONSE_CODE)===200,'Legacy financial POST returns a controlled page');curl_close($h);}
    $p=proc_open(['node',__DIR__.'/check-inline-js.js'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));fwrite($pipes[0],$html);fclose($pipes[0]);stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($p)===0,'Rendered financial page JavaScript parses');return $html;
}

$password='Financial!'.pf_key();$user=pf_actor('customer',$password);$other=pf_actor('customer',$password);$owner=pf_actor('owner',$password);$admin=pf_actor('Admin',$password);
clevo_stmt($db,"INSERT INTO stations (UserID,Name,Status,admin_status,BookingCapacity,HomeCapacity) VALUES (?,'Financial tests','Active','approved',1000,1000)",'i',[$owner]);$station=(int)$db->insert_id;
$service=clevo_market_service($db,$owner,$station,0,['name_ar'=>'اختبار مالي','name_en'=>'Financial test','price'=>'1.25','duration'=>30,'is_station'=>true]);
$token=clevo_token_pair($db,clevo_account($db,$user))['access_token'];$otherToken=clevo_token_pair($db,clevo_account($db,$other))['access_token'];$ownerToken=clevo_token_pair($db,clevo_account($db,$owner))['access_token'];$adminToken=clevo_token_pair($db,clevo_account($db,$admin))['access_token'];
$booking=pf_booking($user);clevo_accept_owner_booking($db,$owner,$booking);
pf_expect(503,pf_http('POST','/api/v1/payment-orders',['purpose'=>'booking','booking_id'=>$booking],$token,pf_key()),'No credentials means no hosted checkout, never fake success');
check(!(bool)clevo_stmt($db,'SELECT OrderID FROM payment_orders WHERE BookingID=?','i',[$booking])->get_result()->fetch_assoc(),'Missing provider configuration creates no payment order');
$signingKey=bin2hex(random_bytes(32));$profile=random_int(100000,999999);$merchant=random_int(100000,999999);
$settings=['CLEVO_SYNTHETIC_FINANCE'=>'0','PAYMENT_PROVIDER'=>'paytabs','PAYMENT_MODE'=>'sandbox','PAYTABS_TEST_PROFILE_CONFIRMED'=>'1','PAYTABS_PROFILE_ID'=>(string)$profile,'PAYTABS_MERCHANT_ID'=>(string)$merchant,'PAYTABS_SERVER_KEY'=>$signingKey];$previous=[];
$runtime=preg_replace('/^CLEVO_SYNTHETIC_FINANCE=.*$/m','CLEVO_SYNTHETIC_FINANCE=0',$original);
foreach($settings as $key=>$value){$previous[$key]=getenv($key);putenv($key.'='.$value);if($key!=='CLEVO_SYNTHETIC_FINANCE')$runtime.="\n$key=$value";}
file_put_contents($runtimeFile,$runtime."\n");$previousURL=getenv('APP_URL');putenv('APP_URL=https://app.example.test');
try{
    check(clevo_finance_minor('1.250')===1250&&clevo_finance_decimal(1187)==='1.187','JOD amounts preserve integer fils');
    check(clevo_finance_commission(1250)===63&&clevo_finance_commission(16750)===838,'Approved five-percent after-discount basis rounds half-up to fils');
    foreach(['1.0001','-1.00','1e2','01.00']as $invalid)rejects(fn()=>clevo_finance_minor($invalid),'Noncanonical/overprecision financial amount rejected');
    check(clevo_finance_subscription_end(new DateTimeImmutable('2027-01-31 12:34:56',new DateTimeZone('UTC')),1,'month')->format('Y-m-d H:i:s')==='2027-02-28 12:34:56','New subscriptions clamp month-end anniversaries');
    check(clevo_finance_subscription_end(new DateTimeImmutable('2028-02-29',new DateTimeZone('UTC')),1,'year')->format('Y-m-d')==='2029-02-28','New subscription leap-year anniversary clamps correctly');
    rejects(fn()=>clevo_finance_post($db,'test:unbalanced','test','legacy_fixture',['test_debit'=>100,'test_credit'=>-90]),'Unbalanced journals cannot be posted');
    $j=clevo_finance_post($db,'test:immutable','test','legacy_fixture',['test_debit'=>100,'test_credit'=>-100]);
    check(clevo_finance_post($db,'test:immutable','test','legacy_fixture',['test_debit'=>100,'test_credit'=>-100])===$j,'Same journal intent replays the immutable posting');
    rejects(fn()=>clevo_finance_post($db,'test:immutable','test','legacy_fixture',['test_debit'=>200,'test_credit'=>-200]),'Journal key cannot rewrite a posted amount','financial_operation_conflict');
    clevo_stmt($db,'INSERT INTO wallet (UserID,Balance) VALUES (?,100)','i',[$user]);
    pf_expect(503,pf_http('POST',"/api/v1/bookings/$booking/payments",['method'=>'wallet'],$token,pf_key()),'Real wallet spending stays blocked without regulatory/provider review');
    pf_expect(503,pf_http('POST','/api/v1/me/subscriptions',['plan_id'=>1,'method'=>'wallet'],$token,pf_key()),'Unverified stored-value subscription activation is blocked');
    rejects(fn()=>clevoCreateUserPlanSubscription($db,$user,1),'Low-level legacy activation cannot bypass payment verification','payment_confirmation_required');
    $oldWallet=clevoEnsureWallet($db,$user);
    rejects(fn()=>clevoCreditWallet($db,(int)$oldWallet['WalletID'],1),'Legacy wallet credit helper cannot bypass the commercial freeze','wallet_regulatory_review_required');
    rejects(fn()=>clevoDebitWallet($db,(int)$oldWallet['WalletID'],1),'Legacy wallet debit helper cannot bypass the commercial freeze','wallet_regulatory_review_required');
    rejects(fn()=>clevoRecordWalletTransaction($db,(int)$oldWallet['WalletID'],1,'credit','test','test'),'Legacy wallet record helper cannot manufacture evidence','wallet_regulatory_review_required');
    rejects(fn()=>clevo_redeem_loyalty_wallet($db,$user,40,pf_key()),'Loyalty cannot mint unapproved stored value','wallet_regulatory_review_required');
    $wallet=pf_expect(200,pf_http('GET','/api/v1/me/wallet',null,$token),'Historical wallet remains readable');check(($wallet['wallet']['spendable']??true)===false,'Wallet DTO explicitly marks frozen funds unspendable');
    $createCalls=0;$queryPayload=null;$lastRequest=[];
    $provider=new ClevoPayTabsSandbox($signingKey,$profile,$merchant,function($path,$payload)use(&$createCalls,&$queryPayload,&$lastRequest):array{
        $lastRequest=$payload;
        if($path==='/payment/query')return $queryPayload;
        $createCalls++;return ['tran_ref'=>'TST'.substr($payload['cart_id'],0,30),'redirect_url'=>'https://secure-jordan.paytabs.com/payment/page/'.$payload['cart_id']];
    });
    $requestKey=pf_key();$reply=clevo_checkout_create($db,$user,['purpose'=>'booking','booking_id'=>$booking],$requestKey,$provider);$id=$reply['payment_order']['OrderID'];$order=clevo_payment_order($db,$user,$id);
    check($createCalls===1&&$lastRequest['cart_amount']==='1.250'&&$lastRequest['cart_currency']==='JOD','Backend creates a hosted sale from the database amount');
    check($order['State']==='pending'&&empty($order['VerifiedAt']),'Checkout URL is pending, not paid');
    check(!str_contains($order['CheckoutCipher'],'https://'),'Hosted checkout credential is encrypted at rest');
    clevo_checkout_create($db,$user,['purpose'=>'booking','booking_id'=>$booking],$requestKey,$provider);clevo_checkout_create($db,$user,['purpose'=>'booking','booking_id'=>$booking],pf_key(),$provider);check($createCalls===1,'Same/different-key retries do not repeat provider session creation');
    rejects(fn()=>clevo_pay_booking($db,$user,$booking,'cash',false,pf_key()),'A pending online attempt cannot race a cash declaration','online_payment_in_progress');
    pf_expect(404,pf_http('GET','/api/v1/payment-orders/'.$id,null,$otherToken),'Other customer cannot view an online payment order');
    pf_expect(200,pf_http('POST','/api/v1/return.php',['respStatus'=>'A','cart_id'=>$id]),'Browser return acknowledges navigation only');check(clevo_payment_order($db,$user,$id)['State']==='pending','Forged browser success does not confirm payment');
    $payload=pf_payload($order);$raw=json_encode($payload,JSON_THROW_ON_ERROR);pf_expect(401,pf_http('POST','/api/v1/webhooks/paytabs',null,null,null,$raw,str_repeat('0',64)),'Invalid webhook signature rejected');
    $wrong=$payload;$wrong['cart_amount']='1.260';$wrong['tran_total']='1.260';pf_webhook($wrong,422,'Signed wrong amount is not sufficient proof');
    $wrong=$payload;$wrong['cart_currency']='USD';pf_webhook($wrong,422,'Signed wrong currency rejected');
    $wrong=$payload;$wrong['merchant_id']=$merchant+1;pf_webhook($wrong,422,'Signed wrong merchant rejected');
    $wrong=$payload;$wrong['tran_type']='Auth';pf_webhook($wrong,422,'Authorization-only event is never treated as a captured sale');
    pf_webhook(pf_payload($order,'H'),200,'Fraud hold remains pending');check(clevo_payment_order($db,$user,$id)['State']==='pending','Hold cannot activate or pay');
    $captured=pf_webhook($payload,200,'Signed matching sale confirms the actual order');check(($captured['state']??'')==='succeeded','Verified sale reaches succeeded');
    check(clevo_finance_balance($db,'owner_payable:'.$owner)===0,'Capture holds funds until fulfillment, without premature owner earnings');
    check((bool)clevo_stmt($db,"SELECT PaymentID FROM payments WHERE BookingID=? AND FundingOrderID=? AND Status='recorded' AND VerifiedAt IS NOT NULL",'is',[$booking,$id])->get_result()->fetch_assoc(),'Booking receipt references verified payment evidence');
    pf_webhook($payload,200,'Exact webhook replay acknowledged without duplicate charge effects');$different=$payload;$different['payment_result']['transaction_time']='2026-11-16T00:00:01Z';pf_webhook($different,200,'Semantic replay cannot duplicate money');
    check((int)clevo_stmt($db,"SELECT COUNT(*) n FROM financial_journals WHERE OrderID=? AND Kind='capture'",'s',[$id])->get_result()->fetch_assoc()['n']===1,'Only one capture journal exists');
    pf_webhook(pf_payload($order,'D'),200,'Out-of-order failure cannot downgrade a confirmed sale');check(clevo_payment_order($db,$user,$id)['State']==='succeeded','Late failure preserves confirmed money');
    clevo_complete_owner_booking($db,$owner,$booking);clevo_complete_owner_booking($db,$owner,$booking);
    check(clevo_finance_balance($db,'owner_payable:'.$owner)===-1187,'Owner earnings use the precise 95 percent remainder only after completion');
    check(clevo_stmt($db,'SELECT Balance FROM wallet WHERE UserID=?','i',[$user])->get_result()->fetch_assoc()['Balance']==='100.00','Online payment does not spend a historical wallet');
    $statement=pf_expect(200,pf_http('GET','/api/v1/owner/financial-statement',null,$ownerToken),'Owner reads the new exact-fils statement');check(($statement['summary']['sandbox_payable_minor']??0)===1187,'Owner statement agrees with immutable ledger');
    pf_expect(403,pf_http('GET','/api/v1/admin/payment-reconciliation',null,$token),'Customer cannot read global reconciliation');
    $receipts=pf_expect(200,pf_http('GET','/api/v1/financial-receipts',null,$token),'Customer receipt history is available');$receiptID=(int)$receipts['receipts'][0]['ReceiptID'];
    $r=pf_expect(200,pf_http('GET','/api/v1/financial-receipts/'.$receiptID,null,$token),'Own receipt reads verified details');check(($r['receipt']['is_tax_invoice']??true)===false,'Payment receipt does not pretend to be a Jordanian tax invoice');
    pf_expect(404,pf_http('GET','/api/v1/financial-receipts/'.$receiptID,null,$otherToken),'Other customer cannot read a receipt');
    $settleKey=pf_key();$settlement=pf_expect(201,pf_http('POST','/api/v1/admin/settlements',['owner_id'=>$owner,'amount'=>'1.187'],$adminToken,$settleKey),'Admin can record an explicitly blocked settlement request');
    check(($settlement['state']??'')==='blocked'&&($settlement['funds_transferred']??true)===false,'Unapproved marketplace payout is not a successful transfer');pf_expect(201,pf_http('POST','/api/v1/admin/settlements',['owner_id'=>$owner,'amount'=>'1.187'],$adminToken,$settleKey),'Blocked settlement request retry is idempotent');

    // Cash is a separate physical collection attestation, never an online capture.
    $cash=pf_booking($user);clevo_accept_owner_booking($db,$owner,$cash);clevo_pay_booking($db,$user,$cash,'cash',false,pf_key());
    check(!(bool)clevo_stmt($db,'SELECT JournalID FROM financial_journals WHERE BookingID=?','i',[$cash])->get_result()->fetch_assoc(),'Cash selection moves no money and earns no commission yet');
    clevo_complete_owner_booking($db,$owner,$cash);
    check(clevo_finance_balance($db,'cash_commission_due:'.$owner)===63,'Confirmed physical cash collection creates a commission receivable, not a fake bank settlement');
    check(clevo_finance_balance($db,'owner_payable:'.$owner)===-1187,'Cash already collected by the owner is not also credited for payout');

    // A full provider refund stays pending until separate verified confirmation.
    $refundBook=pf_booking($user);clevo_accept_owner_booking($db,$owner,$refundBook);$refundOrderReply=clevo_checkout_create($db,$user,['purpose'=>'booking','booking_id'=>$refundBook],pf_key(),$provider);$refundOrder=clevo_payment_order($db,$user,$refundOrderReply['payment_order']['OrderID']);pf_webhook(pf_payload($refundOrder));
    $requested=pf_expect(201,pf_http('POST','/api/v1/payment-orders/'.$refundOrder['OrderID'].'/refund-requests',['reason'=>'Cancel before work'],$token,pf_key()),'Customer refund request does not issue credit');$refundID=(int)$requested['refund_id'];$refundRef='TST'.strtoupper(pf_key());$refundCalls=0;
    $refundProvider=new ClevoPayTabsSandbox($signingKey,$profile,$merchant,function($path,$payload)use(&$refundCalls,$refundOrder,$refundRef):array{if($path==='/payment/request')$refundCalls++;return pf_payload($refundOrder,'P','Refund',$refundRef,$refundOrder['ProviderReference']);});
    clevo_payment_refund_submit($db,$admin,$refundID,$refundProvider);clevo_payment_refund_submit($db,$admin,$refundID,$refundProvider);check($refundCalls===1,'Refund submit retry cannot send a second refund request');
    check(clevo_payment_order($db,$user,$refundOrder['OrderID'])['State']==='succeeded','Pending refund does not pretend money was returned');
    rejects(fn()=>clevo_complete_owner_booking($db,$owner,$refundBook),'In-flight refund blocks conflicting service completion','payment_refund_pending');
    $refundPayload=pf_payload($refundOrder,'A','Refund',$refundRef,$refundOrder['ProviderReference']);pf_webhook($refundPayload,200,'Signed refund confirms reversal');pf_webhook($refundPayload,200,'Refund webhook replay is safe');
    check(clevo_payment_order($db,$user,$refundOrder['OrderID'])['State']==='refunded','Only confirmed refund changes payment to refunded');
    check(clevo_stmt($db,'SELECT Status FROM booking WHERE BookingID=?','i',[$refundBook])->get_result()->fetch_assoc()['Status']==='Cancelled','Verified pre-service refund uses the shared booking state machine');
    check((int)clevo_stmt($db,"SELECT COUNT(*) n FROM financial_journals WHERE OrderID=? AND Kind='refund'",'s',[$refundOrder['OrderID']])->get_result()->fetch_assoc()['n']===1,'Exactly one compensating refund journal exists');

    // Unknown create outcome is reconciled by query, never by another create call.
    $unknownBooking=pf_booking($user);clevo_accept_owner_booking($db,$owner,$unknownBooking);$attempts=0;
    $uncertain=new ClevoPayTabsSandbox($signingKey,$profile,$merchant,function($path,$payload)use(&$attempts,&$queryPayload):array{if($path==='/payment/query')return [$queryPayload];$attempts++;throw new RuntimeException('Synthetic transport timeout.');});
    $previousErrorLog=ini_get('error_log');ini_set('error_log',__DIR__.'/../.security-test/financial-expected-errors.log');
    try{$unknown=clevo_checkout_create($db,$user,['purpose'=>'booking','booking_id'=>$unknownBooking],pf_key(),$uncertain);}finally{ini_set('error_log',$previousErrorLog);}$unknownID=$unknown['payment_order']['OrderID'];
    clevo_checkout_create($db,$user,['purpose'=>'booking','booking_id'=>$unknownBooking],pf_key(),$uncertain);check($attempts===1&&$unknown['payment_order']['State']==='unknown','Ambiguous timeout is durable and cannot trigger duplicate create');
    $unknownOrder=clevo_payment_order($db,$user,$unknownID);$queryPayload=pf_payload($unknownOrder,'A','Sale','TST'.pf_key());$recovered=clevo_payment_reconcile_order($db,$unknownOrder,$uncertain);check($recovered['state']==='succeeded','Authenticated query recovers a lost successful callback/response');

    $lateBooking=pf_booking($user);clevo_accept_owner_booking($db,$owner,$lateBooking);$lateReply=clevo_checkout_create($db,$user,['purpose'=>'booking','booking_id'=>$lateBooking],pf_key(),$provider);$late=clevo_payment_order($db,$user,$lateReply['payment_order']['OrderID']);clevo_booking_transition($db,$admin,$lateBooking,'Cancelled','Cancelled before provider confirmation');
    $lateEvent=pf_webhook(pf_payload($late),200,'Late capture is recorded as a refund liability');check($lateEvent['state']==='refund_due','Late capture never revives a cancelled booking');
    check(!(bool)clevo_stmt($db,'SELECT PaymentID FROM payments WHERE BookingID=?','i',[$lateBooking])->get_result()->fetch_assoc(),'Late capture does not mark the booking paid');

    // Concurrent creates and concurrent valid callbacks use independent DB connections.
    $concurrentBook=pf_booking($user);clevo_accept_owner_booking($db,$owner,$concurrentBook);file_put_contents(__DIR__.'/../.security-test/payment-create-count','');
    $left=['mode'=>'checkout','user'=>$user,'input'=>['purpose'=>'booking','booking_id'=>$concurrentBook],'key'=>pf_key()];$right=array_replace($left,['key'=>pf_key()]);$race=pf_race($left,$right);
    check($race[0]['ok']&&$race[1]['ok']&&$race[0]['result']['order_id']===$race[1]['result']['order_id'],'Concurrent different-key checkout requests share one order');
    check(substr_count(file_get_contents(__DIR__.'/../.security-test/payment-create-count'),"attempt\n")===1,'Only one provider creation is attempted across concurrent processes');
    $concurrent=clevo_payment_order($db,$user,$race[0]['result']['order_id']);$raw=json_encode(pf_payload($concurrent),JSON_THROW_ON_ERROR);$eventJob=['mode'=>'webhook','body'=>$raw,'signature'=>hash_hmac('sha256',$raw,$signingKey)];$race=pf_race($eventJob,$eventJob);
    check($race[0]['ok']&&$race[1]['ok'],'Concurrent signed webhook retries are acknowledged');check((int)clevo_stmt($db,"SELECT COUNT(*) n FROM financial_journals WHERE OrderID=? AND Kind='capture'",'s',[$concurrent['OrderID']])->get_result()->fetch_assoc()['n']===1,'Concurrent webhook processing produces one capture');

    $concurrentRefund=json_encode(pf_payload($concurrent,'A','Refund','TST'.pf_key(),$concurrent['ProviderReference']),JSON_THROW_ON_ERROR);
    $race=pf_race(['mode'=>'complete','owner'=>$owner,'booking'=>$concurrentBook],['mode'=>'webhook','body'=>$concurrentRefund,'signature'=>hash_hmac('sha256',$concurrentRefund,$signingKey)]);
    check($race[1]['ok']&&clevo_payment_order($db,$user,$concurrent['OrderID'])['State']==='refunded','Concurrent completion and signed refund serialize to a verified refund');
    check((int)clevo_stmt($db,"SELECT COALESCE(SUM(e.SignedMinor),0) n FROM financial_entries e JOIN financial_journals j ON j.JournalID=e.JournalID WHERE j.OrderID=? AND (e.AccountKey LIKE 'owner_payable:%' OR e.AccountKey='platform_commission')",'s',[$concurrent['OrderID']])->get_result()->fetch_assoc()['n']===0,'Refund/completion race leaves no owner or platform earnings for refunded money');

    // Tier names alone do not authorize features. Only snapshotted, paid sandbox terms do.
    $planService=clevo_market_service($db,$owner,$station,0,['name_ar'=>'غسيل الباقة','name_en'=>'Plan wash','price'=>'3.00','duration'=>30,'is_station'=>true]);
    clevo_stmt($db,"INSERT INTO plans (name_ar,name_en,price,duration,duration_type,wash_count) VALUES ('Silver sandbox','Silver sandbox',5,1,'month',3)");$planID=(int)$db->insert_id;
    $planRequest=['purpose'=>'subscription','plan_id'=>$planID];rejects(fn()=>clevo_checkout_create($db,$user,$planRequest,pf_key(),$provider),'Unreviewed plan labels/features cannot accept payment','subscription_terms_not_approved');
    $policy=['tier'=>'silver','service_ids'=>[$planService],'modes'=>['station'],'allow_multiple'=>false,'max_booking_amount'=>'3.000'];
    pf_expect(200,pf_http('PATCH','/api/v1/admin/subscription-plans/'.$planID,['sandbox_policy'=>$policy],$adminToken,pf_key()),'Admin configures explicit sandbox-only plan rules');
    $invalidDuration=clevo_stmt($db,'SELECT * FROM plans WHERE id=?','i',[$planID])->get_result()->fetch_assoc();$invalidDuration['duration']=121;rejects(fn()=>clevo_finance_plan_terms($invalidDuration),'Unsupported subscription duration is rejected before accepting funds','subscription_terms_not_approved');
    $subscriptionOrderReply=clevo_checkout_create($db,$user,$planRequest,pf_key(),$provider);$subscriptionOrder=clevo_payment_order($db,$user,$subscriptionOrderReply['payment_order']['OrderID']);
    check(!(bool)clevo_stmt($db,'SELECT id FROM user_plans WHERE FundingOrderID=?','s',[$subscriptionOrder['OrderID']])->get_result()->fetch_assoc(),'Subscription remains inactive before verified payment');
    pf_webhook(pf_payload($subscriptionOrder),200,'Verified subscription sale activates snapshotted entitlement');pf_webhook(pf_payload($subscriptionOrder),200,'Subscription payment replay cannot activate twice');
    $up=clevo_stmt($db,'SELECT * FROM user_plans WHERE FundingOrderID=?','s',[$subscriptionOrder['OrderID']])->get_result()->fetch_assoc();check($up&&(int)$up['remaining_washes']===3&&!empty($up['StartsAtUtc'])&&!empty($up['ExpiresAtUtc']),'Paid subscription has verified funding, exact quota and exclusive UTC expiry');
    check((int)clevo_stmt($db,'SELECT COUNT(*) n FROM user_plans WHERE FundingOrderID=?','s',[$subscriptionOrder['OrderID']])->get_result()->fetch_assoc()['n']===1,'Verified order activates exactly one subscription');
    rejects(fn()=>pf_booking($user,$service,true),'Descriptive plan marketing does not cover an ineligible service','service_not_covered_by_subscription');
    $a=pf_booking($user,$planService,true);$b=pf_booking($user,$planService,true);$c=pf_booking($user,$planService,true);rejects(fn()=>pf_booking($user,$planService,true),'Funded plan cannot overspend its wash quota','plan_unavailable');
    check((int)clevo_stmt($db,"SELECT SUM(AmountMinor) n FROM subscription_redemptions WHERE UserPlanID=? AND State<>'released'",'i',[(int)$up['id']])->get_result()->fetch_assoc()['n']===5000,'Fils allocation conserves the complete purchase price including remainder');
    clevoCancelBookingForUser($db,$b,$user);$replacement=pf_booking($user,$planService,true);
    check((int)clevo_stmt($db,"SELECT SUM(AmountMinor) n FROM subscription_redemptions WHERE UserPlanID=? AND State<>'released'",'i',[(int)$up['id']])->get_result()->fetch_assoc()['n']===5000,'Cancelled redemption reuses its funded slot without minting budget');
    foreach([$a,$c,$replacement]as $orderBook){clevo_accept_owner_booking($db,$owner,$orderBook);clevo_complete_owner_booking($db,$owner,$orderBook);}
    check(clevo_finance_balance($db,'subscription_deferred:'.$subscriptionOrder['OrderID'])===0,'Fully consumed plan releases exactly its collected funding, never nominal service totals');
    $fullRefundRequest=clevo_db_atomic($db,fn()=>clevo_payment_refund_request($db,$user,$subscriptionOrder['OrderID'],'Used plan needs manual review'));rejects(fn()=>clevo_payment_refund_submit($db,$admin,$fullRefundRequest['refund_id'],$refundProvider),'Used subscription cannot be automatically refunded','manual_refund_required');

    // A provider-initiated, verified refund is a financial fact even after service.
    // It reverses earned liabilities/revenue without rewriting completed service history.
    $partialRefund=pf_payload($order,'A','Refund','TST'.pf_key(),$order['ProviderReference']);$partialRefund['cart_amount']='1.249';$partialRefund['tran_total']='1.249';pf_webhook($partialRefund,422,'Unsupported partial refund cannot issue a full reversal');
    pf_webhook(pf_payload($order,'A','Refund','TST'.pf_key(),$order['ProviderReference']),200,'Provider-reported completed-service refund reverses earned allocations');
    check(clevo_stmt($db,'SELECT Status FROM booking WHERE BookingID=?','i',[$booking])->get_result()->fetch_assoc()['Status']==='Completed','Refund does not pretend completed work never happened');
    pf_webhook(pf_payload($subscriptionOrder,'A','Refund','TST'.pf_key(),$subscriptionOrder['ProviderReference']),200,'Verified full package refund reverses all funded service allocations');
    check(clevo_finance_balance($db,'owner_payable:'.$owner)===0,'All owner earnings for refunded online collections are compensated exactly');
    $reversedStatement=pf_expect(200,pf_http('GET','/api/v1/owner/financial-statement?per_page=100',null,$ownerToken),'Owner history includes compensating refunds as well as earnings');
    check(count(array_filter($reversedStatement['transactions']??[],fn($r)=>$r['Kind']==='refund'&&(int)$r['OwnerPayableDeltaMinor']<0))>=2,'Booking and multi-booking package refunds appear in owner transaction history');
    check(array_sum(array_map(fn($r)=>(int)$r['OwnerPayableDeltaMinor'],$reversedStatement['transactions']))===0,'Owner statement transaction deltas reconcile to the exact net payable');
    $unrelatedOwner=pf_actor('owner',$password);$unrelatedOwnerToken=clevo_token_pair($db,clevo_account($db,$unrelatedOwner))['access_token'];
    $unrelatedStatement=pf_expect(200,pf_http('GET','/api/v1/owner/financial-statement',null,$unrelatedOwnerToken),'Unrelated owner can access only their own financial accounts');check(($unrelatedStatement['transactions']??null)===[],'Owner statement never exposes another owner earnings or refunds');
    check(clevo_finance_balance($db,'subscription_deferred:'.$subscriptionOrder['OrderID'])===0,'Full package reversal preserves a balanced deferred account');
    $cancelledPlan=clevo_stmt($db,'SELECT status,remaining_washes,PaymentHold FROM user_plans WHERE FundingOrderID=?','s',[$subscriptionOrder['OrderID']])->get_result()->fetch_assoc();check($cancelledPlan['status']==='cancelled'&&(int)$cancelledPlan['remaining_washes']===0&&(int)$cancelledPlan['PaymentHold']===1,'Confirmed subscription refund revokes remaining entitlement');

    $failedBook=pf_booking($user);clevo_accept_owner_booking($db,$owner,$failedBook);$failedKey=pf_key();$failedReply=clevo_checkout_create($db,$user,['purpose'=>'booking','booking_id'=>$failedBook],$failedKey,$provider);$failedOrder=clevo_payment_order($db,$user,$failedReply['payment_order']['OrderID']);
    $failure=pf_payload($failedOrder,'D');unset($failure['tran_type']);pf_webhook($failure,200,'Signed decline without optional type is bound to the known transaction reference');check(clevo_payment_order($db,$user,$failedOrder['OrderID'])['State']==='failed','Declined transaction reaches failed without a charge');
    $before=$createCalls;clevo_checkout_create($db,$user,['purpose'=>'booking','booking_id'=>$failedBook],$failedKey,$provider);check($createCalls===$before,'A failed attempt key never silently retries charging');
    $cancelReply=clevo_checkout_create($db,$user,['purpose'=>'booking','booking_id'=>$failedBook],pf_key(),$provider);$cancelOrder=clevo_payment_order($db,$user,$cancelReply['payment_order']['OrderID']);pf_webhook(pf_payload($cancelOrder,'C'),200,'Provider cancellation confirms cancelled status');check(clevo_payment_order($db,$user,$cancelOrder['OrderID'])['State']==='cancelled','Provider cancellation frees the uncharged attempt');
    check(!(bool)clevo_stmt($db,'SELECT PaymentID FROM payments WHERE BookingID=?','i',[$failedBook])->get_result()->fetch_assoc(),'Failed/cancelled attempts create no paid receipt');

    $cancelIntentReply=clevo_checkout_create($db,$user,['purpose'=>'booking','booking_id'=>$failedBook],pf_key(),$provider);$cancelIntent=clevo_payment_order($db,$user,$cancelIntentReply['payment_order']['OrderID']);
    $requestCancel=pf_expect(200,pf_http('POST','/api/v1/payment-orders/'.$cancelIntent['OrderID'].'/cancel',[],$token,pf_key()),'Customer can request cancellation without claiming provider confirmation');check(($requestCancel['provider_cancellation_confirmed']??true)===false,'Client cancellation is not a fabricated provider result');
    $cancelView=pf_expect(200,pf_http('GET','/api/v1/payment-orders/'.$cancelIntent['OrderID'],null,$token),'Cancelled-intent status remains queryable');check($cancelView['payment_order']['checkout_url']===null,'Cancelled intent stops exposing its old hosted URL');
    $cancelLate=pf_webhook(pf_payload($cancelIntent),200,'Capture after local cancellation is held for refund review');check($cancelLate['state']==='refund_due','Locally cancelled intent cannot mark the booking paid');

    $outOfOrderBook=pf_booking($user);clevo_accept_owner_booking($db,$owner,$outOfOrderBook);$oo=clevo_checkout_create($db,$user,['purpose'=>'booking','booking_id'=>$outOfOrderBook],pf_key(),$provider);$oo=clevo_payment_order($db,$user,$oo['payment_order']['OrderID']);$refundFirst=pf_payload($oo,'A','Refund','TST'.pf_key(),$oo['ProviderReference']);
    pf_webhook($refundFirst,409,'Refund before original capture evidence is retriable, not invented money');pf_webhook(pf_payload($oo),200,'Delayed original sale can be processed');pf_webhook($refundFirst,200,'Retried refund applies after matching capture evidence arrives');check(clevo_payment_order($db,$user,$oo['OrderID'])['State']==='refunded','Out-of-order events resolve to one correct compensated result');

    $page=pf_web_page($user,$password,'/includes/payment-gateway.php?id='.$failedBook);check(!str_contains($page,'id="cardNum"')&&!str_contains($page,'id="cardCVV"')&&str_contains($page,'start_checkout'),'Legacy PHP checkout uses hosted payment without local PAN/CVV collection');
    $page=pf_web_page($user,$password,'/user/customer-plans.php?subscribed=1');check(!preg_match('/class="alert alert-success[^\"]*"/',$page),'Spoofed subscribed query cannot display an unverified subscription-success alert');
    pf_web_page($owner,$password,'/owner/owner-wallet.php');
    $walletBefore=clevo_stmt($db,'SELECT Balance FROM wallet WHERE UserID=?','i',[$user])->get_result()->fetch_assoc()['Balance'];
    $loyaltyPage=pf_web_page($user,$password,'/user/loyalty.php',['redeem_points'=>'1','points_to_deduct'=>'40','idempotency_key'=>pf_key()]);
    check(str_contains($loyaltyPage,'wallet_frozen')&&clevo_stmt($db,'SELECT Balance FROM wallet WHERE UserID=?','i',[$user])->get_result()->fetch_assoc()['Balance']===$walletBefore,'Actual legacy loyalty POST cannot change the frozen wallet');

    $restricted=new mysqli('127.0.0.1',clevo_env('TEST_RUNTIME_USERNAME'),clevo_env('TEST_RUNTIME_PASSWORD'),clevo_env('DB_DATABASE'),(int)clevo_env('DB_PORT'));
    foreach(['financial_journals','financial_entries','financial_receipts','payment_evidence']as $table){rejects(fn()=>$restricted->query("UPDATE $table SET ".($table==='financial_entries'?'SignedMinor=0':($table==='financial_receipts'?'AmountMinor=0':($table==='payment_evidence'?"ResultCode='A'":"Kind='rewrite'"))).' WHERE 1=0'),'Runtime identity cannot rewrite immutable '.$table);rejects(fn()=>$restricted->query("DELETE FROM $table WHERE 1=0"),'Runtime identity cannot delete immutable '.$table);}$restricted->close();
    $report=pf_expect(200,pf_http('GET','/api/v1/admin/payment-reconciliation',null,$adminToken),'Admin reconciliation compares receipts and balanced journals');
    check(($report['reconciliation']['unbalanced_or_incomplete_journals']??-1)===0&&($report['reconciliation']['verified_booking_receipt_mismatches']??-1)===0,'Internal accounting reconciliation has no imbalance or verified receipt mismatch');
    check(($report['reconciliation']['external_bank_settlement_reconciled']??true)===false,'Internal reconciliation does not pretend a bank statement was reconciled');
    putenv('APP_ENV=production');rejects(fn()=>clevo_payment_provider(),'Production online processing cannot be enabled by test credentials','payment_production_not_approved');check(!clevo_finance_fixture_allowed($db),'Production cannot use the synthetic wallet escape hatch');
    rejects(fn()=>clevo_payment_apply_evidence($db,['order'=>$id],pf_key(),'signed_webhook'),'Deep evidence application is also blocked in production','payment_production_not_approved');
    rejects(fn()=>clevo_finance_activate_plan($db,$subscriptionOrder),'Sandbox subscription cannot activate in production','subscription_production_not_approved');putenv('APP_ENV=testing');
}finally{
    file_put_contents($runtimeFile,$original);foreach($previous as $key=>$value){if($value===false)putenv($key);else putenv($key.'='.$value);}if($previousURL===false)putenv('APP_URL');else putenv('APP_URL='.$previousURL);putenv('APP_ENV=testing');
}
echo "Financial/webhook HTTP requests: $calls; no external payment service contacted.\n";
finish_tests('Commercial financial/webhook suite');
