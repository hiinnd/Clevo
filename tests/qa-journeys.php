<?php
declare(strict_types=1);
require __DIR__.'/admin-helpers.php';
if(clevo_env('DB_PORT')!=='13379'||$base!=='http://127.0.0.1:18089')exit(2);

// Real HTTP/domain/database journeys. All payment evidence is signed LOCAL sandbox
// evidence and all external provider I/O is replaced by an explicitly injected closure.
$results=[];
function qa_scenario(int $number,callable $work):void {
    global $assertions,$failures,$results;
    $before=$assertions;$failed=count($failures);
    try{$work();}catch(Throwable $error){
        check(false,'Journey '.$number.' aborted: '.($error instanceof ClevoSecurityException?$error->reason:get_class($error)));
        throw $error;
    }finally{$results['scenario_'.$number]=['assertions'=>$assertions-$before,'failed'=>count($failures)-$failed];}
}
function qa_api(int $expected,string $method,string $path,?array $body=null,?string $token=null,?string $key=null):array {
    if($key!==null){
        global $base;
        $h=curl_init($base.'/api/v1/'.$path);
        curl_setopt_array($h,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token,'Idempotency-Key: '.$key],CURLOPT_POSTFIELDS=>json_encode($body?:new stdClass())]);
        $raw=(string)curl_exec($h);$response=[(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE),json_decode($raw,true),$raw];curl_close($h);
    }else $response=ad_http($method,'/api/v1/'.$path,$body,null,$token);
    $data=ad_expect($expected,$response,'Journey API '.$method.' '.preg_replace('/[0-9a-f]{24,}/','{opaque-id}',$path));
    if($response[0]!==$expected){
        $reason=$response[1]['error']['code']??'unknown';
        if(is_string($reason)&&preg_match('/^[a-z_]{1,80}$/D',$reason))echo 'Unexpected journey response code: '.$reason."\n";
        throw new RuntimeException('Unexpected journey HTTP status');
    }
    return $data;
}
function qa_has_booking(array $data,string $key,int $id):bool{return in_array($id,array_map('intval',array_column($data[$key]??[],'BookingID')),true);}
function qa_payload(array $order,string $type='Sale',?string $parent=null):array {
    global $profile,$merchant;
    $p=['profile_id'=>$profile,'merchant_id'=>$merchant,'cart_id'=>$order['OrderID'],'cart_currency'=>'JOD','cart_amount'=>clevo_finance_decimal((int)$order['AmountMinor']),
        'tran_currency'=>'JOD','tran_total'=>clevo_finance_decimal((int)$order['AmountMinor']),'tran_ref'=>$type==='Sale'?$order['ProviderReference']:'TST'.ad_key(),'tran_type'=>$type,
        'payment_result'=>['response_status'=>'A','transaction_time'=>gmdate('Y-m-d\TH:i:s\Z')]];
    if($parent)$p['previous_tran_ref']=$parent;
    return $p;
}
function qa_webhook(array $payload):void {
    global $base,$signingKey;
    $raw=json_encode($payload,JSON_THROW_ON_ERROR);$h=curl_init($base.'/api/v1/webhooks/paytabs');
    curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$raw,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Signature: '.hash_hmac('sha256',$raw,$signingKey)],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);
    curl_exec($h);$code=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
    check($code===200,'Matching locally signed sandbox webhook is accepted');
    if($code!==200)throw new RuntimeException('Local webhook failed');
}
function qa_checkout(int $user,array $input):array {
    global $db,$provider;
    $reply=clevo_checkout_create($db,$user,$input,ad_key(),$provider);
    return clevo_payment_order($db,$user,$reply['payment_order']['OrderID']);
}

$runtimeFile=__DIR__.'/../.security-test/testing-runtime.env';$original=file_get_contents($runtimeFile);$previous=[];
$password='Qa!'.ad_key();$owner=ad_actor('owner',$password);$otherOwner=ad_actor('owner',$password);ad_station($otherOwner);$driver=ad_actor('driver',$password);$otherDriver=ad_actor('driver',$password);$other=ad_actor('customer',$password);$admin=ad_actor('Admin',$password);
$station=ad_station($owner);clevo_stmt($db,'UPDATE user SET StationID=? WHERE UserID IN (?,?)','iii',[$station,$driver,$otherDriver]);
$tokens=[];foreach(['owner'=>$owner,'other_owner'=>$otherOwner,'driver'=>$driver,'other_driver'=>$otherDriver,'other'=>$other,'admin'=>$admin] as $role=>$id){
    $reply=qa_api(200,'POST','auth/login',['login'=>clevo_account($db,$id)['Email'],'password'=>$password]);$tokens[$role]=$reply['access_token'];
}
$signingKey=bin2hex(random_bytes(32));$profile=random_int(100000,999999);$merchant=random_int(100000,999999);
$settings=['CLEVO_SYNTHETIC_FINANCE'=>'0','PAYMENT_PROVIDER'=>'paytabs','PAYMENT_MODE'=>'sandbox','PAYTABS_TEST_PROFILE_CONFIRMED'=>'1','PAYTABS_PROFILE_ID'=>(string)$profile,'PAYTABS_MERCHANT_ID'=>(string)$merchant,'PAYTABS_SERVER_KEY'=>$signingKey];
$runtime=preg_replace('/^CLEVO_SYNTHETIC_FINANCE=.*$/m','CLEVO_SYNTHETIC_FINANCE=0',$original);
foreach($settings as $key=>$value){$previous[$key]=getenv($key);putenv($key.'='.$value);if($key!=='CLEVO_SYNTHETIC_FINANCE')$runtime.="\n$key=$value";}
$previous['APP_URL']=getenv('APP_URL');putenv('APP_URL=https://app.example.test');file_put_contents($runtimeFile,$runtime."\n");
$provider=new ClevoPayTabsSandbox($signingKey,$profile,$merchant,static fn($path,$payload)=>['tran_ref'=>'TST'.substr($payload['cart_id'],0,30),'redirect_url'=>'https://secure-jordan.paytabs.com/payment/page/'.$payload['cart_id']]);
try {
    qa_scenario(1,function()use($db,$password,&$customer,&$tokens){
        $email=ad_key().'@qa.example.test';
        qa_api(201,'POST','auth/register',['role'=>'customer','full_name'=>'QA customer','email'=>$email,'phone'=>'079'.random_int(1000000,9999999),'password'=>$password]);
        $customer=(int)clevo_stmt($db,'SELECT UserID FROM user WHERE Email=?','s',[$email])->get_result()->fetch_assoc()['UserID'];
        qa_api(401,'POST','auth/login',['login'=>$email,'password'=>$password]);
        $mail=clevo_decrypt_mail($db->query('SELECT PayloadCipher FROM security_mail_queue ORDER BY ID DESC LIMIT 1')->fetch_assoc()['PayloadCipher']);
        preg_match('/#token=([a-f0-9.]+)/',$mail['html'],$match);$grant=$match[1]??'';
        check($grant!=='','Encrypted local email queue contains verification grant without delivery');
        qa_api(200,'POST','auth/email/verify',['token'=>$grant]);qa_api(422,'POST','auth/email/verify',['token'=>$grant]);
        $login=qa_api(200,'POST','auth/login',['login'=>$email,'password'=>$password]);$tokens['customer']=$login['access_token'];
        $profile=qa_api(200,'PATCH','me',['full_name'=>'QA verified customer','car_name'=>'QA car'],$tokens['customer']);
        check(($profile['user']['name']??'')==='QA verified customer','Verified customer profile update persists');
    });
    qa_scenario(2,function()use($station,$tokens,&$service){
        qa_api(200,'PATCH','owner/stations/'.$station.'/availability',['status'=>'Active','has_at_station'=>true,'has_home_service'=>true,'has_delivery'=>true,'capacity'=>2,'home_capacity'=>2,'opening_time'=>'00:00','closing_time'=>'00:00','delivery_fee'=>'1.25'],$tokens['owner']);
        $created=qa_api(201,'POST','owner/stations/'.$station.'/services',['name_ar'=>'غسيل ضمان الجودة','name_en'=>'QA database wash','price'=>'10.25','duration'=>40,'is_station'=>true,'is_pickup'=>true],$tokens['owner']);$service=(int)$created['service_id'];
        $catalog=qa_api(200,'GET','stations/'.$station.'/services');$row=array_values(array_filter($catalog['services'],fn($r)=>(int)$r['ServiceID']===$service))[0]??[];
        check(($row['Price']??'')==='10.25'&&(int)($row['Duration']??0)===40,'Customer sees database price and duration from owner-created service');
    });
    qa_scenario(3,function()use($db,$station,$tokens,&$booking,&$bookingInput,&$service){
        $bookingInput=['station_id'=>$station,'service_ids'=>[$service],'service_location'=>'delivery','booking_date'=>(new DateTimeImmutable('+120 days'))->format('Y-m-d'),'booking_time'=>'10:00','latitude'=>31.95,'longitude'=>35.91,'address_details'=>'Synthetic QA pickup','Total_Price'=>'0.01'];
        $key=ad_key();$created=qa_api(200,'POST','bookings',$bookingInput,$tokens['customer'],$key);$booking=(int)$created['booking_id'];
        $retry=qa_api(200,'POST','bookings',$bookingInput,$tokens['customer'],$key);check((int)$retry['booking_id']===$booking,'Network retry creates one booking');
        check(qa_has_booking(qa_api(200,'GET','owner/bookings',null,$tokens['owner']),'bookings',$booking),'Owner sees customer booking');
        qa_api(200,'POST','owner/bookings/'.$booking.'/accept',[],$tokens['owner']);
        $detail=qa_api(200,'GET','bookings/'.$booking,null,$tokens['customer']);
        check($detail['booking']['Status']==='Accepted'&&$detail['booking']['Total_Price']==='11.50','Customer sees acceptance and backend service-plus-fee total; forged price ignored');
        check((int)clevo_stmt($db,'SELECT COUNT(*) n FROM notifications WHERE user_id=?','i',[(int)clevo_stmt($db,'SELECT UserID FROM stations WHERE StationID=?','i',[$station])->get_result()->fetch_assoc()['UserID']])->get_result()->fetch_assoc()['n']>0,'Booking event creates owner inbox record');
    });
    qa_scenario(4,function()use($db,$customer,$tokens,$owner,&$booking,&$order){
        $order=qa_checkout($customer,['purpose'=>'booking','booking_id'=>$booking]);
        check($order['State']==='pending'&&!$order['VerifiedAt']&&(int)$order['AmountMinor']===11500,'Local checkout remains pending at the authoritative amount');
        qa_webhook(qa_payload($order));qa_webhook(qa_payload($order));
        $state=qa_api(200,'GET','payment-orders/'.$order['OrderID'],null,$tokens['customer']);check($state['payment_order']['State']==='succeeded','API exposes only verified sandbox payment');
        check((int)clevo_stmt($db,"SELECT COUNT(*) n FROM financial_journals WHERE OrderID=? AND Kind='capture'",'s',[$order['OrderID']])->get_result()->fetch_assoc()['n']===1,'Duplicate webhook produces exactly one capture');
        check(clevo_finance_balance($db,'owner_payable:'.$owner)===0,'No owner earnings before fulfillment');
    });
    qa_scenario(5,function()use($tokens,$driver,&$booking){
        qa_api(200,'POST','owner/bookings/'.$booking.'/driver',['driver_id'=>$driver],$tokens['owner']);
        check(qa_has_booking(qa_api(200,'GET','driver/jobs',null,$tokens['driver']),'tasks',$booking),'Assigned driver sees the new job');
        qa_api(404,'GET','driver/jobs/'.$booking,null,$tokens['other_driver']);
        qa_api(409,'PATCH','driver/jobs/'.$booking.'/delivery-stage',['stage'=>'arrived_pickup'],$tokens['driver']);
        qa_api(200,'POST','driver/jobs/'.$booking.'/accept',[],$tokens['driver']);
        qa_api(200,'PATCH','driver/jobs/'.$booking.'/delivery-stage',['stage'=>'heading_pickup'],$tokens['driver']);
    });
    qa_scenario(6,function()use($tokens,&$booking,&$sample){
        $policy=qa_api(200,'GET','driver/jobs/'.$booking.'/tracking',null,$tokens['driver']);
        $sample=['assignment_id'=>$policy['tracking']['assignment_id'],'sequence'=>1,'latitude'=>31.95,'longitude'=>35.91,'accuracy_m'=>8,'captured_at'=>gmdate('Y-m-d\TH:i:s\Z')];
        qa_api(200,'POST','driver/jobs/'.$booking.'/locations',$sample,$tokens['driver']);
        $tracking=qa_api(200,'GET','bookings/'.$booking.'/tracking',null,$tokens['customer']);
        check($tracking['tracking']['location_state']==='fresh'&&(float)$tracking['tracking']['location']['accuracy_m']===8.0,'Owning customer receives fresh active-job GPS with accuracy');
        qa_api(404,'GET','bookings/'.$booking.'/tracking',null,$tokens['other']);
    });
    qa_scenario(7,function()use($db,$tokens,$owner,&$booking){
        qa_api(200,'PATCH','driver/jobs/'.$booking.'/delivery-stage',['stage'=>'arrived_pickup'],$tokens['driver']);
        $code=qa_api(200,'POST','bookings/'.$booking.'/confirmation-code',['kind'=>'pickup'],$tokens['customer']);
        qa_api(200,'POST','driver/jobs/'.$booking.'/confirm-handoff',['kind'=>'pickup','code'=>$code['confirmation_code']],$tokens['driver']);
        qa_api(200,'PATCH','driver/jobs/'.$booking.'/delivery-stage',['stage'=>'arrived_station'],$tokens['driver']);
        $paused=qa_api(200,'GET','bookings/'.$booking.'/tracking',null,$tokens['customer']);check(!$paused['tracking']['sharing_allowed'],'No GPS sharing while service takes place at station');
        qa_api(200,'POST','owner/bookings/'.$booking.'/service-completed',[],$tokens['owner']);
        qa_api(200,'PATCH','driver/jobs/'.$booking.'/delivery-stage',['stage'=>'returning_vehicle'],$tokens['driver']);
        $code=qa_api(200,'POST','bookings/'.$booking.'/confirmation-code',['kind'=>'delivery'],$tokens['customer']);
        qa_api(200,'POST','driver/jobs/'.$booking.'/confirm-handoff',['kind'=>'delivery','code'=>$code['confirmation_code']],$tokens['driver']);
        foreach([['customer','bookings/history','bookings'],['owner','owner/bookings?status=Completed','bookings'],['driver','driver/jobs/history','tasks'],['admin','admin/bookings?status=Completed','bookings']] as [$role,$path,$key])check(qa_has_booking(qa_api(200,'GET',$path,null,$tokens[$role]),$key,$booking),'Completed job appears in '.$role.' history');
        check(clevo_finance_balance($db,'owner_payable:'.$owner)===-10925,'Verified fulfillment recognizes exact 95-percent owner earnings');
        $journal=clevo_stmt($db,"SELECT CommissionMinor FROM financial_journals WHERE BookingID=? AND Kind='earnings'",'i',[$booking])->get_result()->fetch_assoc();check((int)$journal['CommissionMinor']===575,'Approved 5-percent commission includes post-discount service and fee basis');
        $done=qa_api(200,'GET','bookings/'.$booking.'/tracking',null,$tokens['customer']);check($done['tracking']['location']===null,'Completed booking exposes no retained location');
    });
    qa_scenario(8,function()use($db,$tokens,$customer,&$bookingInput){
        $input=array_replace($bookingInput,['service_location'=>'station','booking_time'=>'12:00']);$created=qa_api(200,'POST','bookings',$input,$tokens['customer']);$id=(int)$created['booking_id'];
        qa_api(200,'POST','owner/bookings/'.$id.'/accept',[],$tokens['owner']);$paid=qa_checkout($customer,['purpose'=>'booking','booking_id'=>$id]);qa_webhook(qa_payload($paid));
        qa_api(409,'POST','bookings/'.$id.'/cancel',[],$tokens['customer']);
        check(clevo_stmt($db,'SELECT Status FROM booking WHERE BookingID=?','i',[$id])->get_result()->fetch_assoc()['Status']==='Accepted','Online cancellation cannot invent an unconfirmed refund');
        qa_api(201,'POST','payment-orders/'.$paid['OrderID'].'/refund-requests',['reason'=>'QA cancellation before pickup'],$tokens['customer']);
        qa_webhook(qa_payload($paid,'Refund',$paid['ProviderReference']));
        $cancelled=qa_api(200,'GET','bookings/'.$id,null,$tokens['customer']);
        check($cancelled['booking']['Status']==='Cancelled','Verified pre-pickup refund automatically cancels the booking');
        qa_api(409,'POST','bookings/'.$id.'/cancel',[],$tokens['customer']);
        check(clevo_payment_order($db,$customer,$paid['OrderID'])['State']==='refunded','Cancellation follows matching verified full refund');
        check((int)clevo_stmt($db,"SELECT COUNT(*) n FROM financial_journals WHERE OrderID=? AND Kind='refund'",'s',[$paid['OrderID']])->get_result()->fetch_assoc()['n']===1,'Refund creates one immutable compensating journal');
        check((int)clevo_stmt($db,"SELECT COUNT(*) n FROM booking_status_history WHERE BookingID=? AND ToStatus='Cancelled'",'i',[$id])->get_result()->fetch_assoc()['n']===1,'Repeated terminal cancellation cannot duplicate status history');
    });
    qa_scenario(9,function()use($db,$tokens,$customer,$station,&$service){
        $plan=qa_api(201,'POST','admin/subscription-plans',['name_ar'=>'باقة ضمان الجودة','name_en'=>'QA Silver','price'=>'5.00','duration'=>1,'duration_type'=>'month','wash_count'=>2,'sandbox_policy'=>['tier'=>'silver','service_ids'=>[$service],'modes'=>['station'],'allow_multiple'=>false,'max_booking_amount'=>'11.000']],$tokens['admin']);$id=(int)$plan['plan_id'];
        $order=qa_checkout($customer,['purpose'=>'subscription','plan_id'=>$id]);
        check(!(bool)clevo_stmt($db,'SELECT id FROM user_plans WHERE FundingOrderID=?','s',[$order['OrderID']])->get_result()->fetch_assoc(),'Pending subscription payment confers no entitlement');
        qa_webhook(qa_payload($order));qa_webhook(qa_payload($order));
        check((int)clevo_stmt($db,'SELECT COUNT(*) n FROM user_plans WHERE FundingOrderID=?','s',[$order['OrderID']])->get_result()->fetch_assoc()['n']===1,'Verified subscription payment creates one entitlement despite replay');
        $booking=qa_api(200,'POST','bookings',['station_id'=>$station,'service_ids'=>[$service],'service_location'=>'station','booking_date'=>(new DateTimeImmutable('+2 days'))->format('Y-m-d'),'booking_time'=>'14:00','use_plan'=>true],$tokens['customer']);
        $detail=qa_api(200,'GET','bookings/'.$booking['booking_id'],null,$tokens['customer']);check($detail['booking']['Payment_Method']==='plan','Verified funded subscription supplies an eligible booking benefit');
    });
    qa_scenario(10,function()use($db,$tokens,$station,$customer,$other,&$booking,&$order,&$sample,&$service){
        qa_api(404,'GET','bookings/'.$booking,null,$tokens['other']);qa_api(404,'GET','payment-orders/'.$order['OrderID'],null,$tokens['other']);
        qa_api(404,'POST','driver/jobs/'.$booking.'/locations',$sample,$tokens['other_driver']);
        qa_api(403,'PATCH','owner/services/'.$service,['price'=>'0.01'],$tokens['other']);
        qa_api(404,'PATCH','owner/services/'.$service,['price'=>'0.01'],$tokens['other_owner']);
        qa_api(404,'PATCH','owner/stations/'.$station,['name'=>'Attempted foreign station change'],$tokens['other_owner']);
        // Historical fixture balances are deliberately NOT verified/spendable money.
        clevo_stmt($db,'INSERT INTO wallet (UserID,Balance) VALUES (?,91),(?,7) ON DUPLICATE KEY UPDATE Balance=VALUES(Balance)','ii',[$customer,$other]);
        $wallet=qa_api(200,'GET','me/wallet?user_id='.$customer,null,$tokens['other']);
        check(($wallet['wallet']['commercial_stored_value_enabled']??true)===false&&($wallet['wallet']['Balance']??'')==='7.00','Spoofing another wallet owner still returns only own frozen historical balance');
        qa_api(403,'GET','admin/bookings',null,$tokens['customer']);
        qa_api(404,'GET','me/wallet/'.$station,null,$tokens['other']);
    });
}catch(Throwable $error){echo 'QA journey stopped safely: '.($error instanceof ClevoSecurityException?$error->reason:get_class($error)).".\n";}
finally {
    file_put_contents($runtimeFile,$original);
    foreach($previous as $key=>$value)putenv($value===false?$key:$key.'='.$value);
    file_put_contents(__DIR__.'/../.security-test/qa-journeys.json',json_encode(['scenarios'=>$results,'provider'=>'local signed sandbox fixtures; no external charge','external_messages_sent'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
}
if(count($results)!==10)check(false,'All ten QA journeys executed');
finish_tests('Complete QA user journeys');
