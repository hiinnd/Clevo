<?php
declare(strict_types=1);
require __DIR__.'/admin-helpers.php';
require_once __DIR__.'/../includes/subscription-reminders.php';
require_once __DIR__.'/../includes/operations.php';
$password='FinalFix!'.ad_key();$user=ad_actor('customer',$password);$scope='final.hash';$key=ad_key();$body=['password'=>$password,'confirm'=>true];$calls=0;
clevo_db_idempotent($db,$user,$scope,$key,$body,function()use(&$calls):array{$calls++;return ['ok'=>true];});
$stored=clevo_stmt($db,'SELECT RequestHash,RequestHashVersion,ResponseJson FROM request_idempotency WHERE UserID=? AND Scope=?','is',[$user,$scope])->get_result()->fetch_assoc();
$legacy=hash('sha256',json_encode($body,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
check((int)$stored['RequestHashVersion']===2&&!hash_equals($legacy,$stored['RequestHash']),'Password-bearing request has keyed digest, not a fast public password verifier');
check(hash_equals(clevo_db_request_hash($legacy),$stored['RequestHash']),'Digest is tied to protected application key');
clevo_db_idempotent($db,$user,$scope,$key,$body,function()use(&$calls):array{$calls++;return [];});check($calls===1,'Secure request replay does not duplicate committed effect');
rejects(fn()=>clevo_db_idempotent($db,$user,$scope,$key,['password'=>'different','confirm'=>true],fn()=>[]),'Changed body still conflicts','idempotency_conflict');
$oldKey=ad_key();$cache='{"legacy":true}';
clevo_stmt($db,'INSERT INTO request_idempotency (UserID,Scope,KeyHash,RequestHash,ResponseJson) VALUES (?,?,?,?,?)','issss',[$user,'final.legacy',hash('sha256',$oldKey),$legacy,$cache]);
check(!clevo_operations_health()['checks']['idempotency_hashes'],'Readiness detects a late legacy writer leaving unkeyed request digests');
clevo_db_harden_idempotency($db);clevo_db_harden_idempotency($db);
check(clevo_operations_health()['checks']['idempotency_hashes'],'Readiness recovers only after request digest hardening');
$upgraded=clevo_stmt($db,'SELECT RequestHash,RequestHashVersion,ResponseJson FROM request_idempotency WHERE UserID=? AND Scope=?','is',[$user,'final.legacy'])->get_result()->fetch_assoc();
check((int)$upgraded['RequestHashVersion']===2&&hash_equals(clevo_db_request_hash($legacy),$upgraded['RequestHash']),'Legacy digest backfill is retry-safe');
check($upgraded['ResponseJson']===$cache,'Backfill preserves committed response');
check(clevo_db_idempotent($db,$user,'final.legacy',$oldKey,$body,fn()=>throw new RuntimeException('Unexpected replay'))===['legacy'=>true],'Legacy committed operation replays after upgrade');
$simple=ad_http('GET','/api/v1/stations?per_page=1&sort=id&direction=asc');$aggregate=ad_http('GET','/api/v1/stations?per_page=1&sort=id&direction=asc&min_rating=0');
ad_expect(200,$simple,'Optimized default catalog works');ad_expect(200,$aggregate,'Aggregate-filter catalog works');
check($simple[1]['meta']['pagination']['total']===$aggregate[1]['meta']['pagination']['total'],'Fast count preserves catalog pagination total');
check($simple[1]['data']['stations']===$aggregate[1]['data']['stations'],'Fast count preserves station data, prices and sorting');
clevo_stmt($db,"INSERT INTO plans (name_ar,name_en,price,duration,duration_type,wash_count) VALUES ('اختبار','Test',10,1,'month',3)");$planID=(int)$db->insert_id;
clevo_stmt($db,"INSERT INTO user_plans (user_id,plan_id,start_date,end_date,status,remaining_washes) VALUES (?,?,UTC_DATE(),DATE_ADD(UTC_DATE(),INTERVAL 3 DAY),'active',3)",'ii',[$user,$planID]);$soon=(int)$db->insert_id;
clevo_stmt($db,"INSERT INTO user_plans (user_id,plan_id,start_date,end_date,status,remaining_washes) VALUES (?,?,DATE_SUB(UTC_DATE(),INTERVAL 30 DAY),DATE_SUB(UTC_DATE(),INTERVAL 1 DAY),'active',2)",'ii',[$user,$planID]);$expired=(int)$db->insert_id;
clevo_subscription_expiry_batch($db);clevo_subscription_expiry_batch($db);
check((int)clevo_stmt($db,"SELECT COUNT(*) n FROM notification_outbox WHERE UserID=? AND TitleEn='Plan expiring soon' AND BodyEn LIKE ?",'is',[$user,'Subscription #'.$soon.' %'])->get_result()->fetch_assoc()['n']===1,'Repeated expiry worker queues one reminder per subscription');
check(clevo_stmt($db,'SELECT status,remaining_washes FROM user_plans WHERE id=?','i',[$expired])->get_result()->fetch_assoc()===['status'=>'expired','remaining_washes'=>2],'Expiry preserves purchased washes and never activates a plan');
check((int)clevo_stmt($db,"SELECT COUNT(*) n FROM notification_outbox WHERE TargetRole='Admin' AND TitleEn='Plan expired' AND BodyEn LIKE ?",'s',['Subscription #'.$expired.' %'])->get_result()->fetch_assoc()['n']===1,'Expired subscription notifies Admin once after commit');
clevo_stmt($db,"INSERT INTO user_plans (user_id,plan_id,start_date,end_date,status,remaining_washes) VALUES (?,?,UTC_DATE(),DATE_ADD(UTC_DATE(),INTERVAL 2 DAY),'active',3),(?,?,UTC_DATE(),DATE_ADD(UTC_DATE(),INTERVAL 4 DAY),'active',3)",'iiii',[$user,$planID,$user,$planID]);
clevo_subscription_expiry_batch($db,1);clevo_subscription_expiry_batch($db,1);
check((int)clevo_stmt($db,"SELECT COUNT(*) n FROM notification_outbox WHERE UserID=? AND TitleEn='Plan expiring soon'",'i',[$user])->get_result()->fetch_assoc()['n']===3,'Bounded worker progresses beyond already-notified subscriptions');
$owner=ad_actor('owner',$password);$otherOwner=ad_actor('owner',$password);
$station=ad_station($owner);$station2=ad_station($otherOwner);
$service=clevo_market_service($db,$owner,$station,0,['name_ar'=>'اختبار','name_en'=>'Test','price'=>'10','duration'=>30,'is_station'=>true]);
$service2=clevo_market_service($db,$otherOwner,$station2,0,['name_ar'=>'اختبار','name_en'=>'Test','price'=>'10','duration'=>30,'is_station'=>true]);
$booking=ad_booking($user,$station,$service);$foreign=ad_booking($user,$station2,$service2);
clevo_stmt($db,"INSERT INTO subscription_redemptions (UserPlanID,BookingID,SlotNumber,AmountMinor) VALUES (?,?,1,10000),(?,?,2,10000)",'iiii',[$soon,$booking,$soon,$foreign]);
$token=clevo_token_pair($db,clevo_account($db,$owner))['access_token'];
$usage=ad_expect(200,ad_http('GET','/api/v1/owner/subscription-usage',null,null,$token),'Owner can read plan usage for their bookings');
check(count($usage['redemptions'])===1&&(int)$usage['redemptions'][0]['BookingID']===$booking,'Owner usage excludes other owners even for the same customer plan');
check(!array_intersect(['UserID','UserPlanID','Email','PhoneNumber'],array_keys($usage['redemptions'][0])),'Owner usage does not expose a customer-wide subscription or identity');
$customerToken=clevo_token_pair($db,clevo_account($db,$user))['access_token'];
ad_expect(403,ad_http('GET','/api/v1/owner/subscription-usage',null,null,$customerToken),'Customer cannot enter owner subscription reporting');
$document=ad_document();clevo_stmt($db,'UPDATE user SET VerificationFile=? WHERE UserID=?','si',[$document,$user]);
check(!clevo_discard_unreferenced_verification($db,$document),'Registration cleanup refuses a committed account document');
$verification=rtrim(clevo_env('PRIVATE_STORAGE_PATH'),'/\\').'/verification';$count=count(glob($verification.'/*')?:[]);
$image=__DIR__.'/../.security-test/final-registration.png';file_put_contents($image,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9a0AAAAASUVORK5CYII='));
$http=curl_init($base.'/api/v1/auth/register');curl_setopt_array($http,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_POSTFIELDS=>['role'=>'owner','full_name'=>'Invalid registration fixture','email'=>'invalid','phone'=>'0791234567','password'=>$password,'station_name'=>'Test','verification_file'=>new CURLFile($image,'image/png','document.png')]]);
curl_exec($http);check((int)curl_getinfo($http,CURLINFO_RESPONSE_CODE)===422,'Invalid multipart owner application is rejected');curl_close($http);
check(count(glob($verification.'/*')?:[])===$count,'Failed multipart application removes its unreferenced private upload');
$email=ad_key().'@final.example.test';$phone='079'.random_int(1000000,9999999);
$http=curl_init($base.'/api/v1/auth/register');curl_setopt_array($http,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_POSTFIELDS=>['role'=>'owner','full_name'=>'Owner onboarding fixture','email'=>$email,'phone'=>$phone,'password'=>$password,'station_name'=>'Pending application fixture','verification_file'=>new CURLFile($image,'image/png','document.png')]]);
curl_exec($http);check((int)curl_getinfo($http,CURLINFO_RESPONSE_CODE)===201,'Real multipart owner onboarding API accepts a valid document');curl_close($http);
$registered=clevo_stmt($db,'SELECT u.Role,u.AccountStatus,s.StationID,s.admin_status,s.Status FROM user u JOIN stations s ON s.UserID=u.UserID WHERE u.Email=?','s',[$email])->get_result()->fetch_assoc();
check($registered&&$registered['Role']==='owner'&&$registered['AccountStatus']==='pending'&&$registered['admin_status']==='pending'&&$registered['Status']==='Closed','Owner onboarding persists pending closed application without approval bypass');
if($registered)ad_expect(404,ad_http('GET','/api/v1/stations/'.$registered['StationID']),'Unapproved station stays out of customer catalog');
ad_expect(401,ad_http('POST','/api/v1/auth/login',['login'=>$email,'password'=>$password]),'Pending owner registration cannot log in or access owner features');
// Exercise the deployed subscription-worker grant profile on this guarded schema.
clevo_stmt($db,"INSERT INTO user_plans (user_id,plan_id,start_date,end_date,status,remaining_washes) VALUES (?,?,UTC_DATE(),DATE_ADD(UTC_DATE(),INTERVAL 5 DAY),'active',3)",'ii',[$user,$planID]);$restrictedPlan=(int)$db->insert_id;
$workerUser='final_sub_'.bin2hex(random_bytes(6));$workerPassword=ad_key();$database=clevo_env('DB_DATABASE');
$db->query("CREATE USER '$workerUser'@'127.0.0.1' IDENTIFIED BY '$workerPassword'");
$worker=null;
try{
    foreach(['security_schema_migrations'=>'SELECT','user'=>'SELECT','plans'=>'SELECT','user_plans'=>'SELECT, UPDATE','request_idempotency'=>'SELECT, INSERT, UPDATE','notification_outbox'=>'INSERT'] as $table=>$grants)$db->query("GRANT $grants ON `$database`.`$table` TO '$workerUser'@'127.0.0.1'");
    $worker=new mysqli('127.0.0.1',$workerUser,$workerPassword,$database,(int)clevo_env('DB_PORT'));$worker->set_charset('utf8mb4');$worker->query("SET time_zone='+00:00'");
    if(!defined('CLEVO_NOTIFICATION_WORKER'))define('CLEVO_NOTIFICATION_WORKER',true);
    clevo_subscription_expiry_batch($worker);
    check((int)clevo_stmt($db,"SELECT COUNT(*) n FROM notification_outbox WHERE UserID=? AND TitleEn='Plan expiring soon' AND BodyEn LIKE ?",'is',[$user,'Subscription #'.$restrictedPlan.' %'])->get_result()->fetch_assoc()['n']===1,'Narrow subscription-worker grants process real reminders');
    foreach(['UPDATE user SET Role=Role WHERE 1=0','UPDATE booking SET Status=Status WHERE 1=0','DELETE FROM notification_outbox WHERE 1=0'] as $statement){
        try{$worker->query($statement);check(false,'Subscription worker cannot mutate unrelated account/booking/history');}
        catch(mysqli_sql_exception $error){check((int)$error->getCode()===1142,'Subscription worker cannot mutate unrelated account/booking/history');}
    }
}finally{if($worker)$worker->close();$db->query("DROP USER '$workerUser'@'127.0.0.1'");}
finish_tests('Final internal security/performance/subscription/upload fixes');
