<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/../includes/marketplace.php';
if(clevo_env('APP_ENV')!=='testing'||clevo_env('DB_HOST')!=='127.0.0.1'||!preg_match('/^clevo_security_test_[a-f0-9]{12}$/D',clevo_env('DB_DATABASE','')??''))exit(2);
$db=clevo_connect_database();
if(strcasecmp(realpath($db->query('SELECT @@datadir d')->fetch_assoc()['d'])?:'',realpath(__DIR__.'/../.security-test/mysql')?:'!')!==0)exit(2);
$db->query('DELETE FROM security_rate_limits');
$base=rtrim(clevo_env('APP_URL'),'/');$calls=0;
function bw_http(string $method,string $path,?array $body=null,?string $token=null,?string $key=null,?string $jar=null,bool $form=false):array {
    global $base,$db,$calls;if(++$calls%20===0)$db->query('DELETE FROM security_rate_limits');
    $headers=[];if($token)$headers[]='Authorization: Bearer '.$token;if($key)$headers[]='Idempotency-Key: '.$key;
    if($body!==null)$headers[]='Content-Type: '.($form?'application/x-www-form-urlencoded':'application/json');
    $h=curl_init($base.$path);curl_setopt_array($h,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$headers]);
    if($body!==null)curl_setopt($h,CURLOPT_POSTFIELDS,$form?http_build_query($body):json_encode($body?:new stdClass()));
    if($jar){curl_setopt($h,CURLOPT_COOKIEFILE,$jar);curl_setopt($h,CURLOPT_COOKIEJAR,$jar);}
    $raw=(string)curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
    return [$status,json_decode($raw,true),$raw];
}
function bw_expect(int $code,array $response,string $label):array {check($response[0]===$code,$label.' (HTTP '.$response[0].')');return $response[1]['data']??[];}
function bw_key():string{return bin2hex(random_bytes(16));}
function bw_cli(string $script):array {
    $p=proc_open([PHP_BINARY,__DIR__.'/../scripts/'.$script],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out];
}
function bw_inline_valid(string $html):bool {
    $p=proc_open(['node',__DIR__.'/check-inline-js.js'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));fwrite($pipes[0],$html);fclose($pipes[0]);stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return proc_close($p)===0;
}
function bw_actor(string $role,string $password):int {
    global $db;$suffix=bin2hex(random_bytes(6));
    clevo_stmt($db,"INSERT INTO user (UserName,Email,PhoneNumber,Password,Role,AccountStatus,EmailVerifiedAt) VALUES (?,?,?,?,?,'active',UTC_TIMESTAMP())",'sssss',['Workflow '.$role,$suffix.'@workflow.example.test','078'.str_pad((string)random_int(0,9999999),7,'0',STR_PAD_LEFT),password_hash($password,PASSWORD_DEFAULT),$role]);return (int)$db->insert_id;
}
function bw_race(array $a,array $b):array {
    $jobs=[];$start=microtime(true)+0.5;
    foreach([$a,$b] as $input){$p=proc_open([PHP_BINARY,__DIR__.'/booking-race.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));fwrite($pipes[0],json_encode($input+['start'=>$start]));fclose($pipes[0]);$jobs[]=[$p,$pipes];}
    $results=[];foreach($jobs as [$p,$pipes]){$raw=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);check($code===0,'Independent concurrent booking worker exits cleanly');$results[]=json_decode($raw,true)??['ok'=>false,'reason'=>'worker_failed'];}return $results;
}
function bw_login(int $user,string $password):string {
    global $db;$jar=__DIR__.'/../.security-test/bw-'.bw_key().'.cookies';
    $page=bw_http('GET','/general/login.php',null,null,null,$jar);preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$page[2],$m);
    $email=clevo_account($db,$user)['Email'];$reply=bw_http('POST','/general/login.php',['login_action'=>1,'login_input'=>$email,'password'=>$password,'csrf_token'=>$m[1]??''],null,null,$jar,true);
    check($reply[0]===302,'Existing PHP login remains usable for workflow actors');return $jar;
}
function bw_csrf(string $path,string $jar):string {$page=bw_http('GET',$path,null,null,null,$jar);check($page[0]===200,'Existing PHP booking/owner page renders');check(bw_inline_valid($page[2]),'Rendered owner workflow JavaScript parses after adding duration/capacity controls');preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$page[2],$m);return $m[1]??'';}

$password='Workflow!'.bw_key();$customer=bw_actor('customer',$password);$other=bw_actor('User',$password);$owner=bw_actor('owner',$password);$otherOwner=bw_actor('owner',$password);$driver=bw_actor('driver',$password);$otherDriver=bw_actor('driver',$password);$admin=bw_actor('Admin',$password);
clevo_stmt($db,"INSERT INTO stations (UserID,Name,Location,Status,admin_status,OpeningTime,ClosingTime,DefaultServiceMinutes) VALUES (?,'Workflow station','Amman','Active','approved','08:00','22:00',45)",'i',[$owner]);$station=(int)$db->insert_id;
clevo_stmt($db,'UPDATE user SET StationID=? WHERE UserID IN (?,?)','iii',[$station,$driver,$otherDriver]);
$service=clevo_market_service($db,$owner,$station,0,['name_ar'=>'خدمة اختبار','name_en'=>'Workflow wash','price'=>'10.25','is_station'=>true,'is_home'=>true,'is_pickup'=>true]);
$extra=clevo_market_service($db,$owner,$station,0,['name_ar'=>'إضافة','name_en'=>'Workflow extra','price'=>'7.75','duration'=>15,'is_station'=>true,'is_home'=>true,'is_pickup'=>true]);
$date=(new DateTimeImmutable('+7 days'))->format('Y-m-d');$body=['service_ids'=>[$service,$extra],'service_location'=>'station','booking_date'=>$date,'booking_time'=>'10:00','total_price'=>0,'price'=>0,'duration'=>1,'additional_fee'=>0];
check((int)clevo_stmt($db,'SELECT Duration FROM services WHERE ServiceID=?','i',[$service])->get_result()->fetch_assoc()['Duration']===45,'Owner service without duration uses the station database default');
rejects(fn()=>clevo_create_booking($db,$customer,$station,$body,bw_key()),'Unconfigured capacity fails safely before creating a booking','station_capacity_not_configured');
clevo_market_availability($db,$owner,$station,['capacity'=>1,'home_capacity'=>1,'station_fee'=>'1.25','home_fee'=>'2.00','delivery_fee'=>'3.00','delivery_buffer_minutes'=>30]);
$tokens=[];foreach(['customer'=>$customer,'other'=>$other,'owner'=>$owner,'otherOwner'=>$otherOwner,'driver'=>$driver,'admin'=>$admin] as $role=>$actor)$tokens[$role]=clevo_token_pair($db,clevo_account($db,$actor))['access_token'];
$catalog=bw_expect(200,bw_http('GET',"/api/v1/stations/$station/services"),'Published owner services are exposed through the API');
check(count($catalog['services']??[])===2&&(int)$catalog['services'][0]['Duration']>0,'Catalog returns database durations and live owner services');
$availability=bw_expect(200,bw_http('GET',"/api/v1/stations/$station/availability?date=$date&time=10:00&mode=station&service_ids=$service,$extra"),'Availability quote checks real requested services');
check(($availability['availability']['appointment']['total']??'')==='19.25'&&($availability['availability']['appointment']['duration_minutes']??0)===60,'Availability includes server fees and summed service duration');
$key=bw_key();$created=clevo_create_booking($db,$customer,$station,$body,$key);$booking=(int)$created['booking_id'];
$stored=clevo_stmt($db,'SELECT * FROM booking WHERE BookingID=?','i',[$booking])->get_result()->fetch_assoc();
check($stored['Total_Price']==='19.25'&&$stored['ServicesSubtotal']==='18.00'&&$stored['AdditionalFee']==='1.25','Server ignores forged client prices/fees and snapshots the database quote');
check((int)$stored['DurationMinutes']===60&&$stored['ScheduledAtUtc']==="$date 07:00:00"&&$stored['ScheduledEndAtUtc']==="$date 08:00:00",'Multiple-service reservation stores a precise UTC interval');
check(clevo_create_booking($db,$customer,$station,$body,$key)===$created,'Stable key replays the same booking');
check(clevo_create_booking($db,$customer,$station,$body,bw_key())['booking_id']===$booking,'Repeated identical intent with a different key does not duplicate an active booking');
check(clevo_create_booking($db,$customer,$station,array_replace($body,['latitude'=>31.95,'longitude'=>35.91,'address_details'=>'Unused station address','use_plan'=>true]),bw_key())['booking_id']===$booking,'Changed payment preference or unused station GPS does not manufacture a duplicate reservation');
rejects(fn()=>clevo_create_booking($db,$other,$station,$body,bw_key()),'A full station cannot accept another customer at the same time','booking_capacity_exceeded');
rejects(fn()=>clevo_create_booking($db,$other,$station,array_replace($body,['booking_time'=>'10:59']),bw_key()),'Partial interval overlap is rejected','booking_capacity_exceeded');
$adjacent=clevo_create_booking($db,$other,$station,array_replace($body,['booking_time'=>'11:00']),bw_key());check($adjacent['booking_id']!==$booking,'Half-open interval boundary permits a booking exactly at the previous end');
$reschedule=clevoRescheduleBookingForUser($db,$booking,$customer,$date,'11:00');check($reschedule['status']==='error','Reschedule cannot overbook another reservation');
check(clevo_stmt($db,'SELECT Booking_Time FROM booking WHERE BookingID=?','i',[$booking])->get_result()->fetch_assoc()['Booking_Time']==='10:00:00','Failed reschedule preserves the original appointment');
rejects(fn()=>clevo_create_booking($db,$customer,$station,array_replace($body,['booking_time'=>'21:30']),bw_key()),'Full service duration must fit inside station hours','outside_opening_hours');
clevo_market_availability($db,$owner,$station,['status'=>'Busy']);rejects(fn()=>clevo_create_booking($db,$customer,$station,array_replace($body,['booking_time'=>'14:00']),bw_key()),'Busy station rejects new bookings','station_unavailable');clevo_market_availability($db,$owner,$station,['status'=>'Active']);
rejects(fn()=>clevo_booking_transition($db,$otherOwner,$booking,'Accepted','forbidden'),'Unrelated owner cannot accept','not_found');
rejects(fn()=>clevo_booking_transition($db,$customer,$booking,'Completed','forged'),'Customer cannot complete a booking','invalid_transition');
rejects(fn()=>clevo_booking_transition($db,$admin,$booking,'Completed','forged'),'Admin cannot skip operational/financial completion','invalid_transition');
clevo_market_reject($db,$owner,$booking);
check(clevo_stmt($db,'SELECT Status FROM booking WHERE BookingID=?','i',[$booking])->get_result()->fetch_assoc()['Status']==='Rejected','Owner rejection uses the central state machine');
rejects(fn()=>clevo_booking_transition($db,$owner,$booking,'Accepted','revive'),'Rejected booking cannot be revived','invalid_transition');
$replacement=clevo_create_booking($db,$customer,$station,$body,bw_key());check($replacement['booking_id']!==$booking,'Rejected booking releases capacity and the active-intent key');
$cancel=clevoCancelBookingForUser($db,(int)$replacement['booking_id'],$customer);check($cancel['status']==='success','Customer can cancel a Pending booking');
check(clevoCancelBookingForUser($db,(int)$adjacent['booking_id'],$customer)['status']==='error','Customer cancellation cannot access another customer');
$runtime=new mysqli('127.0.0.1',clevo_env('TEST_RUNTIME_USERNAME'),clevo_env('TEST_RUNTIME_PASSWORD'),clevo_env('DB_DATABASE'),(int)clevo_env('DB_PORT'));
rejects(fn()=>$runtime->query('UPDATE booking_status_history SET Reason=\'rewrite\' WHERE EventID=0'),'Runtime database identity cannot rewrite booking status history');
rejects(fn()=>$runtime->query('DELETE FROM booking_status_history WHERE EventID=0'),'Runtime database identity cannot delete booking status history');$runtime->close();

// Different customers and different keys compete for exactly one remaining slot.
$raceBody=array_replace($body,['booking_time'=>'14:00']);$left=['op'=>'create','actor'=>$customer,'station'=>$station,'body'=>$raceBody,'key'=>bw_key()];$right=array_replace($left,['actor'=>$other,'key'=>bw_key()]);$race=bw_race($left,$right);
check(count(array_filter($race,fn($r)=>$r['ok']))===1,'Concurrent distinct requests cannot oversell station capacity');
check(in_array('booking_capacity_exceeded',array_column($race,'reason'),true),'Losing concurrent request gets a controlled capacity conflict');
$winner=$race[0]['ok']?$race[0]['result']:$race[1]['result'];$raceBooking=(int)$winner['booking_id'];$raceCustomer=$race[0]['ok']?$customer:$other;
$left=['op'=>'transition','actor'=>$owner,'booking'=>$raceBooking,'target'=>'Accepted','reason'=>'owner_accepted'];$right=['op'=>'transition','actor'=>$raceCustomer,'booking'=>$raceBooking,'target'=>'Cancelled','reason'=>'customer_cancelled'];$decisions=bw_race($left,$right);
check(count(array_filter($decisions,fn($r)=>$r['ok']))===1,'Concurrent acceptance and customer cancellation yield one valid decision');

// Identical intent, distinct keys and two processes still consume just one slot.
$same=array_replace($body,['booking_time'=>'16:00']);$left=['op'=>'create','actor'=>$customer,'station'=>$station,'body'=>$same,'key'=>bw_key()];$right=array_replace($left,['key'=>bw_key()]);$dupes=bw_race($left,$right);
check($dupes[0]['ok']&&$dupes[1]['ok']&&$dupes[0]['result']['booking_id']===$dupes[1]['result']['booking_id'],'Concurrent retry with different keys resolves to one active booking');

// Status events and queued notifications are rolled back with the business transaction.
$rollbackBody=array_replace($body,['booking_date'=>(new DateTimeImmutable('+8 days'))->format('Y-m-d')]);
$eventBefore=(int)$db->query('SELECT COUNT(*) n FROM booking_status_history')->fetch_assoc()['n'];$notificationBefore=(int)$db->query('SELECT COUNT(*) n FROM notifications')->fetch_assoc()['n'];$outboxBefore=(int)$db->query('SELECT COUNT(*) n FROM notification_outbox')->fetch_assoc()['n'];
clevo_db_begin($db);$rolled=clevo_create_booking($db,$customer,$station,$rollbackBody,bw_key());
check((int)$db->query('SELECT COUNT(*) n FROM notifications')->fetch_assoc()['n']===$notificationBefore,'Notification is not triggered before the outer commit');
check((int)$db->query('SELECT COUNT(*) n FROM notification_outbox')->fetch_assoc()['n']===$outboxBefore+1,'Transactional outbox is queued before commit');clevo_db_rollback($db);
check((int)$db->query('SELECT COUNT(*) n FROM booking_status_history')->fetch_assoc()['n']===$eventBefore&&(int)$db->query('SELECT COUNT(*) n FROM notification_outbox')->fetch_assoc()['n']===$outboxBefore,'Rollback removes status events, reservations and queued notifications');
check(!(bool)clevo_stmt($db,'SELECT BookingID FROM booking WHERE BookingID=?','i',[(int)$rolled['booking_id']])->get_result()->fetch_assoc(),'Outer rollback never leaves a phantom booking');
clevo_db_begin($db);$committed=clevo_create_booking($db,$customer,$station,$rollbackBody,bw_key());check((int)$db->query('SELECT COUNT(*) n FROM notifications')->fetch_assoc()['n']===$notificationBefore,'Nested service does not dispatch notification');clevo_db_commit($db);
check((int)$db->query('SELECT COUNT(*) n FROM notifications')->fetch_assoc()['n']===$notificationBefore+1,'Notification is dispatched after the successful outer commit');

// A committed undelivered outbox record is recoverable, and two workers deliver it once.
clevo_stmt($db,"INSERT INTO notification_outbox (UserID,Title,TitleEn,Body,BodyEn,Type) VALUES (?,'Recovery','Recovery','Queued','Queued','info')",'i',[$customer]);$outbox=(int)$db->insert_id;
$race=bw_race(['op'=>'outbox'],['op'=>'outbox']);
$delivered=clevo_stmt($db,'SELECT NotificationID,DeliveredAt FROM notification_outbox WHERE OutboxID=?','i',[$outbox])->get_result()->fetch_assoc();
check(!empty($delivered['DeliveredAt'])&&!empty($delivered['NotificationID']),'Outbox workers recover an undelivered committed notification');
check((int)$db->query('SELECT COUNT(*) n FROM notifications')->fetch_assoc()['n']===$notificationBefore+2,'Concurrent outbox workers produce exactly one notification');

// Expiry disregards stale legacy notification flags and restores paid plan capacity once.
$expired=clevo_create_booking($db,$customer,$station,array_replace($body,['booking_date'=>(new DateTimeImmutable('+9 days'))->format('Y-m-d')]),bw_key());$expiredID=(int)$expired['booking_id'];
clevo_stmt($db,'UPDATE booking SET created_at=UTC_TIMESTAMP()-INTERVAL 11 MINUTE,notification_sent=1 WHERE BookingID=?','i',[$expiredID]);
rejects(fn()=>clevo_accept_owner_booking($db,$owner,$expiredID),'Expired Pending cannot be accepted','booking_expired');cancelExpiredBookings($db,null,$station);cancelExpiredBookings($db,null,$station);
check(clevo_stmt($db,'SELECT Status FROM booking WHERE BookingID=?','i',[$expiredID])->get_result()->fetch_assoc()['Status']==='Cancelled_Timeout','Shared expiry records the terminal state even with a stale notification flag');
check((int)clevo_stmt($db,"SELECT COUNT(*) n FROM booking_status_history WHERE BookingID=? AND ToStatus='Cancelled_Timeout'",'i',[$expiredID])->get_result()->fetch_assoc()['n']===1,'Repeated expiry records one status event');

// Database-backed home teams are independent; pickup/return shares the wash pool.
$delivery=array_replace($body,['service_location'=>'delivery','latitude'=>31.95,'longitude'=>35.91,'booking_date'=>(new DateTimeImmutable('+10 days'))->format('Y-m-d')]);$deliveryA=clevo_create_booking($db,$customer,$station,$delivery,bw_key());$job=(int)$deliveryA['booking_id'];
check((int)clevo_stmt($db,'SELECT DurationMinutes FROM booking WHERE BookingID=?','i',[$job])->get_result()->fetch_assoc()['DurationMinutes']===90,'Pickup/return includes configured travel buffer in the reservation');
rejects(fn()=>clevo_create_booking($db,$other,$station,array_replace($delivery,['service_location'=>'station']),bw_key()),'Pickup and station work share the wash pool','booking_capacity_exceeded');
$home=clevo_create_booking($db,$other,$station,array_replace($delivery,['service_location'=>'home']),bw_key());check($home['booking_id']!==$job,'A separate configured home team can work in parallel');
clevo_market_availability($db,$owner,$station,['capacity'=>2]);$deliveryB=clevo_create_booking($db,$other,$station,$delivery,bw_key());$job2=(int)$deliveryB['booking_id'];
clevo_market_assign($db,$owner,$job,$driver);rejects(fn()=>clevo_market_assign($db,$owner,$job2,$driver),'Driver cannot be assigned overlapping pickup/return jobs','driver_schedule_conflict');clevo_market_assign($db,$owner,$job2,$otherDriver);
rejects(fn()=>clevo_driver_transition($db,$driver,$job,'Completed'),'Driver cannot skip pickup/wash/return stages','invalid_transition');
clevo_stmt($db,'INSERT INTO wallet (UserID,Balance) VALUES (?,100),(?,0)','ii',[$customer,$owner]);clevo_pay_booking($db,$customer,$job,'wallet',false,bw_key());
foreach(['In Progress','Arrived','Pickup','Washing','Returning','Completed'] as $target)clevo_driver_transition($db,$driver,$job,$target);
rejects(fn()=>clevo_driver_transition($db,$driver,$job,'Pickup'),'Completed job cannot be restarted','invalid_transition');
rejects(fn()=>clevoRefundBookingPayment($db,$job),'Completed service requires reviewed manual refund handling','manual_refund_required');
$events=bw_expect(200,bw_http('GET',"/api/v1/bookings/$job/events",null,$tokens['customer']),'Customer can read the real status-event history');
check(count($events['events']??[])===8,'History includes request, acceptance and each driver transition');
foreach($events['events']??[] as $event)check(!empty($event['ActorRole'])&&!empty($event['Reason'])&&!empty($event['ChangedAt'])&&!array_key_exists('ActorID',$event),'Public scoped history includes role/time/reason without unrelated actor IDs');
bw_expect(404,bw_http('GET',"/api/v1/bookings/$job/events",null,$tokens['other']),'Other customer cannot read booking history');
$adminEvents=bw_expect(200,bw_http('GET',"/api/v1/bookings/$job/events",null,$tokens['admin']),'Admin reads the same status history');check(isset($adminEvents['events'][0]['ActorID']),'Authorized administrator history includes the accountable actor ID');
$adminCancel=bw_expect(200,bw_http('POST',"/api/v1/admin/bookings/$job2/cancel",['reason'=>'Customer dispute before service'], $tokens['admin'],bw_key()),'Admin uses the same pre-service cancellation state machine');
check(($adminCancel['booking_id']??0)===$job2,'Admin cancellation returns the same booking identity');
bw_expect(409,bw_http('POST',"/api/v1/admin/bookings/$job/cancel",['reason'=>'Already completed'],$tokens['admin'],bw_key()),'Admin cannot cancel completed work');

// Existing PHP publication and booking adapters are exercised with real sessions/CSRF.
$ownerJar=bw_login($owner,$password);$ownerCsrf=bw_csrf('/owner/manage-services.php',$ownerJar);
$webName='Web published '.bin2hex(random_bytes(4));$webSave=bw_http('POST','/owner/manage-services.php',['save_service'=>1,'edit_id'=>0,'name_ar'=>$webName,'name_en'=>$webName,'price'=>'6.50','duration'=>25,'type_station'=>1,'type_home'=>1,'csrf_token'=>$ownerCsrf],null,null,$ownerJar,true);check($webSave[0]===302,'Existing owner form publishes a service through the shared service');
$webService=(int)clevo_stmt($db,'SELECT ServiceID FROM services WHERE StationID=? AND Name_en=?','is',[$station,$webName])->get_result()->fetch_assoc()['ServiceID'];
$customerJar=bw_login($customer,$password);$page=bw_http('GET',"/user/station-details.php?id=$station",null,null,null,$customerJar);check($page[0]===200&&str_contains($page[2],$webName),'Owner-created service appears on Customer Station Details');
check(bw_inline_valid($page[2]),'Rendered customer catalog/pricing/retry JavaScript parses');
$apiServices=bw_expect(200,bw_http('GET',"/api/v1/stations/$station/services"),'Same published service is available to Flutter');check(in_array($webService,array_map('intval',array_column($apiServices['services']??[],'ServiceID')),true),'Web and API use the same published service catalog');
preg_match('/const csrfToken = "([a-f0-9]+)"/',$page[2],$m);if(!$m)preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$page[2],$m);
$webBody=['action'=>'create_booking','csrf_token'=>$m[1]??'','idempotency_key'=>bw_key(),'service_ids'=>json_encode([$webService]),'service_location'=>'station','booking_date'=>(new DateTimeImmutable('+11 days'))->format('Y-m-d'),'booking_time'=>'10:00','total_price'=>'0.01'];
$webResult=bw_http('POST',"/user/station-details.php?id=$station",$webBody,null,null,$customerJar,true);check($webResult[0]===200&&($webResult[1]['status']??'')==='success','PHP customer page creates a booking through the same capacity/pricing service');
$webBooking=(int)($webResult[1]['booking_id']??0);$webStored=clevo_stmt($db,'SELECT Total_Price,DurationMinutes FROM booking WHERE BookingID=?','i',[$webBooking])->get_result()->fetch_assoc();check($webStored&&$webStored['Total_Price']==='7.75'&&(int)$webStored['DurationMinutes']===25,'Web booking uses server service price, fee and duration');
clevo_archive_catalog($db,$owner,'service',$webService);$page=bw_http('GET',"/user/station-details.php?id=$station",null,null,null,$customerJar);check(!str_contains($page[2],$webName),'Archived services disappear from the Customer Station Details catalog');

// Catalog edits cannot rewrite a booked quote or a rescheduled duration snapshot.
$snapshotBody=array_replace($body,['booking_date'=>(new DateTimeImmutable('+12 days'))->format('Y-m-d')]);
$snapshot=clevo_create_booking($db,$customer,$station,$snapshotBody,bw_key());$snapshotID=(int)$snapshot['booking_id'];
clevo_market_service($db,$owner,$station,$extra,['price'=>'9.75','duration'=>30]);
$rescheduled=clevoRescheduleBookingForUser($db,$snapshotID,$customer,$snapshotBody['booking_date'],'12:00');check($rescheduled['status']==='success','Reschedule revalidates capacity while preserving the booked quote');
$snapshotRow=clevo_stmt($db,'SELECT Total_Price,DurationMinutes,AdditionalFee FROM booking WHERE BookingID=?','i',[$snapshotID])->get_result()->fetch_assoc();
check($snapshotRow['Total_Price']==='19.25'&&(int)$snapshotRow['DurationMinutes']===60&&$snapshotRow['AdditionalFee']==='1.25','Catalog duration/price edits do not change an existing reservation');
$newQuote=clevo_booking_availability($db,$station,['date'=>$snapshotBody['booking_date'],'time'=>'14:00','mode'=>'station','service_ids'=>[$service,$extra]]);
check($newQuote['duration_minutes']===75&&$newQuote['total']==='21.25','New availability quotes use the updated database price and duration');
clevo_market_service($db,$owner,$station,$extra,['price'=>'7.75','duration'=>15]);

// Existing subscriptions cover the whole selected booking, including the configured fee.
clevo_stmt($db,"INSERT INTO plans (name_ar,name_en,price,wash_count) VALUES ('Workflow plan','Workflow plan',10,2)");$planID=(int)$db->insert_id;
clevo_stmt($db,'INSERT INTO user_plans (user_id,plan_id,start_date,end_date,remaining_washes) VALUES (?,?,CURDATE(),CURDATE()+INTERVAL 30 DAY,2)','ii',[$customer,$planID]);$userPlan=(int)$db->insert_id;
$planBody=array_replace($body,['booking_date'=>(new DateTimeImmutable('+13 days'))->format('Y-m-d'),'use_plan'=>true]);
$planBooking=clevo_create_booking($db,$customer,$station,$planBody,bw_key());$planOrder=(int)$planBooking['booking_id'];
check((int)clevo_stmt($db,'SELECT remaining_washes FROM user_plans WHERE id=?','i',[$userPlan])->get_result()->fetch_assoc()['remaining_washes']===1,'Multi-service subscription booking consumes one wash under the existing policy');
clevo_market_reject($db,$owner,$planOrder);clevo_market_reject($db,$owner,$planOrder);
check((int)clevo_stmt($db,'SELECT remaining_washes FROM user_plans WHERE id=?','i',[$userPlan])->get_result()->fetch_assoc()['remaining_washes']===2,'Rejected paid subscription booking restores the wash once');
$planBooking=clevo_create_booking($db,$customer,$station,$planBody,bw_key());$planOrder=(int)$planBooking['booking_id'];clevo_stmt($db,'UPDATE booking SET created_at=UTC_TIMESTAMP()-INTERVAL 11 MINUTE WHERE BookingID=?','i',[$planOrder]);cancelExpiredBookings($db,null,$station);cancelExpiredBookings($db,null,$station);
check((int)clevo_stmt($db,'SELECT remaining_washes FROM user_plans WHERE id=?','i',[$userPlan])->get_result()->fetch_assoc()['remaining_washes']===2,'Expired paid subscription booking restores the wash once');
$planBooking=clevo_create_booking($db,$customer,$station,$planBody,bw_key());$planOrder=(int)$planBooking['booking_id'];clevoCancelBookingForUser($db,$planOrder,$customer);clevoCancelBookingForUser($db,$planOrder,$customer);
check((int)clevo_stmt($db,'SELECT remaining_washes FROM user_plans WHERE id=?','i',[$userPlan])->get_result()->fetch_assoc()['remaining_washes']===2,'Customer cancellation of a Pending subscription booking restores it once');

// A mixed points/wallet cancellation reverses every component atomically.
clevo_stmt($db,'INSERT INTO loyalty (UserID,PointsBalance) VALUES (?,100)','i',[$other]);clevo_stmt($db,'INSERT INTO wallet (UserID,Balance) VALUES (?,100)','i',[$other]);
$discountBooking=clevo_create_booking($db,$other,$station,array_replace($body,['booking_date'=>(new DateTimeImmutable('+14 days'))->format('Y-m-d')]),bw_key());$discountID=(int)$discountBooking['booking_id'];clevo_accept_owner_booking($db,$owner,$discountID);clevo_pay_booking($db,$other,$discountID,'wallet',true,bw_key());
$discount=clevo_stmt($db,'SELECT Points_Discount,Payable_Amount FROM booking WHERE BookingID=?','i',[$discountID])->get_result()->fetch_assoc();
check($discount['Points_Discount']==='2.50'&&$discount['Payable_Amount']==='16.75','Loyalty discount applies to the backend total including the configured fee');
check(clevoCancelBookingForUser($db,$discountID,$other)['status']==='error','Customer cannot bypass the existing Accepted-booking refund review policy');
clevo_booking_transition($db,$admin,$discountID,'Cancelled','approved_pre_service_cancellation');
check(clevo_stmt($db,'SELECT Balance FROM wallet WHERE UserID=?','i',[$other])->get_result()->fetch_assoc()['Balance']==='100.00'&&clevoGetLoyaltyPoints($db,$other)===100.0,'Admin pre-service cancellation restores wallet and points exactly');
rejects(fn()=>clevo_booking_transition($db,$admin,$discountID,'Cancelled','repeat'),'Different cancellation request cannot repeat a terminal refund','invalid_transition');

// Unpaid remote acceptance expires independently of page visits.
$remote=clevo_create_booking($db,$other,$station,array_replace($delivery,['booking_date'=>(new DateTimeImmutable('+15 days'))->format('Y-m-d')]),bw_key());$remoteID=(int)$remote['booking_id'];clevo_accept_owner_booking($db,$owner,$remoteID);clevo_stmt($db,'UPDATE booking SET Accepted_At=UTC_TIMESTAMP()-INTERVAL 11 MINUTE WHERE BookingID=?','i',[$remoteID]);
rejects(fn()=>clevo_pay_booking($db,$other,$remoteID,'wallet',false,bw_key()),'Late remote payment cannot revive an expired request','payment_expired');cancelExpiredBookings($db,null,$station);
check(clevo_stmt($db,'SELECT Status FROM booking WHERE BookingID=?','i',[$remoteID])->get_result()->fetch_assoc()['Status']==='Cancelled_Timeout','Unpaid remote request expires through the same state machine');
rejects(fn()=>clevo_booking_transition($db,null,$snapshotID,'Cancelled_Timeout','request_expired'),'System expiry cannot cancel a fresh request','invalid_transition');

// Two reschedules race for one new appointment; the loser retains its old reservation.
clevo_market_availability($db,$owner,$station,['capacity'=>1]);$rescheduleDate=(new DateTimeImmutable('+16 days'))->format('Y-m-d');
$leftBooking=clevo_create_booking($db,$customer,$station,array_replace($body,['booking_date'=>$rescheduleDate,'booking_time'=>'08:00']),bw_key());
$rightBooking=clevo_create_booking($db,$other,$station,array_replace($body,['booking_date'=>$rescheduleDate,'booking_time'=>'11:00']),bw_key());
$moveA=['op'=>'reschedule','actor'=>$customer,'booking'=>$leftBooking['booking_id'],'date'=>$rescheduleDate,'time'=>'14:00'];$moveB=array_replace($moveA,['actor'=>$other,'booking'=>$rightBooking['booking_id']]);$moves=bw_race($moveA,$moveB);
check(count(array_filter($moves,fn($r)=>$r['ok']))===1,'Concurrent reschedules cannot oversell one new slot');
check((int)clevo_stmt($db,"SELECT COUNT(*) n FROM booking WHERE BookingID IN (?,?) AND Booking_Time='14:00:00'",'ii',[$leftBooking['booking_id'],$rightBooking['booking_id']])->get_result()->fetch_assoc()['n']===1,'Only the winning reschedule changes its appointment');

// Peak concurrent occupancy matters, rather than the number of intersecting rows.
clevo_market_availability($db,$owner,$station,['capacity'=>2]);$peakDate=(new DateTimeImmutable('+17 days'))->format('Y-m-d');
$shortBody=array_replace($body,['service_ids'=>[$extra],'booking_date'=>$peakDate,'booking_time'=>'10:00']);clevo_create_booking($db,$customer,$station,$shortBody,bw_key());
clevo_create_booking($db,$other,$station,array_replace($shortBody,['booking_time'=>'10:45']),bw_key());
$spanning=clevo_create_booking($db,$customer,$station,array_replace($body,['booking_date'=>$peakDate]),bw_key());check($spanning['booking_id']>0,'Sequential existing intervals do not incorrectly consume two simultaneous capacity units');

// DB grant failure after commit leaves a durable queue item without failing/repeating the booking.
$runtimeUser=clevo_env('TEST_RUNTIME_USERNAME');$database=clevo_env('DB_DATABASE');$notifyBody=array_replace($body,['station_id'=>$station,'booking_date'=>(new DateTimeImmutable('+18 days'))->format('Y-m-d')]);
if(!preg_match('/^clevo_rt_[a-f0-9]{12}$/D',$runtimeUser??''))throw new RuntimeException('Unexpected runtime test identity.');
$db->query("REVOKE INSERT ON `$database`.notifications FROM '$runtimeUser'@'127.0.0.1'");
$deferred=bw_expect(200,bw_http('POST','/api/v1/bookings',$notifyBody,$tokens['customer'],bw_key()),'Committed booking succeeds when only post-commit notification delivery fails');$deferredID=(int)($deferred['booking_id']??0);
$queued=(int)$db->query('SELECT COUNT(*) n FROM notification_outbox WHERE DeliveredAt IS NULL')->fetch_assoc()['n'];check($deferredID>0&&$queued>=1,'Delivery failure leaves the committed booking and durable pending notification intact');
$db->query("GRANT INSERT ON `$database`.notifications TO '$runtimeUser'@'127.0.0.1'");[$workerCode]=bw_cli('booking-notification-worker.php');check($workerCode===0,'Scheduled notification worker CLI runs successfully on the isolated queue');
check((int)$db->query('SELECT COUNT(*) n FROM notification_outbox WHERE DeliveredAt IS NULL')->fetch_assoc()['n']===0,'Notification worker can drain committed pending notifications after recovery');

// Legacy records are retained and conservatively consume capacity without invented snapshots.
rejects(fn()=>clevo_booking_interval(['ScheduledAtUtc'=>'0000-00-00 00:00:00','ScheduledEndAtUtc'=>null,'DurationMinutes'=>30,'Status'=>'Accepted','Service_Location'=>'station'],['DefaultServiceMinutes'=>30,'DeliveryBufferMinutes'=>0]),'Zero-date legacy UTC timestamp is not silently normalized into a valid reservation','legacy_schedule_requires_reconciliation');
clevo_market_availability($db,$owner,$station,['capacity'=>1]);$legacyDate=(new DateTimeImmutable('+19 days'))->format('Y-m-d');
clevo_stmt($db,"INSERT INTO booking (UserID,StationID,ServiceID,Status,Service_Location,Booking_Date,Booking_Time,Total_Price,Payment_Method) VALUES (?,?,?,'Accepted','station',?,'10:00',10,'cash')",'iiis',[$customer,$station,$service,$legacyDate]);$legacyID=(int)$db->insert_id;
clevo_stmt($db,'INSERT INTO booking_services (BookingID,ServiceID,ServiceName,Price) VALUES (?,?,?,10),(?,?,?,7)','iisiis',[$legacyID,$service,'Legacy one',$legacyID,$extra,'Legacy two']);
rejects(fn()=>clevo_create_booking($db,$other,$station,array_replace($body,['booking_date'=>$legacyDate,'booking_time'=>'11:00']),bw_key()),'Legacy multi-service duration reserves the database default per service','booking_capacity_exceeded');
check(clevo_stmt($db,'SELECT DurationMinutes,ScheduledEndAtUtc FROM booking WHERE BookingID=?','i',[$legacyID])->get_result()->fetch_assoc()===['DurationMinutes'=>null,'ScheduledEndAtUtc'=>null],'Legacy projection does not rewrite historical duration or end timestamps');
clevo_stmt($db,"INSERT INTO booking (UserID,StationID,ServiceID,Status,Service_Location) VALUES (?,?,?,'Accepted','station')",'iii',[$customer,$station,$service]);$unknownID=(int)$db->insert_id;
[$preflightCode,$preflightOutput]=bw_cli('booking-preflight.php');$preflight=json_decode($preflightOutput,true);
check($preflightCode===2&&($preflight['active_bookings_without_usable_appointment']??0)>=1&&($preflight['read_only']??false),'Read-only deployment preflight identifies unusable legacy appointments');
rejects(fn()=>clevo_create_booking($db,$other,$station,array_replace($body,['booking_date'=>(new DateTimeImmutable('+20 days'))->format('Y-m-d')]),bw_key()),'Unusable legacy appointment fails closed instead of silently undercounting capacity','legacy_schedule_requires_reconciliation');
clevo_booking_transition($db,$admin,$unknownID,'Cancelled','Unscheduled legacy request cancelled before service');

// Overnight hours check the whole reservation across civil date boundaries.
clevo_market_availability($db,$owner,$station,['opening_time'=>'22:00','closing_time'=>'06:00']);$nightDate=(new DateTimeImmutable('+20 days'))->format('Y-m-d');
$night=clevo_create_booking($db,$customer,$station,array_replace($body,['booking_date'=>$nightDate,'booking_time'=>'23:30']),bw_key());check($night['booking_id']>0,'Valid overnight appointment crosses midnight');
rejects(fn()=>clevo_create_booking($db,$other,$station,array_replace($body,['booking_date'=>(new DateTimeImmutable('+21 days'))->format('Y-m-d'),'booking_time'=>'05:30']),bw_key()),'Overnight appointment cannot finish after the closing boundary','outside_opening_hours');
clevo_market_availability($db,$owner,$station,['opening_time'=>'00:00','closing_time'=>'00:00']);
check(clevo_create_booking($db,$customer,$station,array_replace($body,['booking_date'=>(new DateTimeImmutable('+21 days'))->format('Y-m-d'),'booking_time'=>'23:30']),bw_key())['booking_id']>0,'Equal opening/closing times preserve 24-hour operation across midnight');
clevo_market_availability($db,$owner,$station,['opening_time'=>'08:00','closing_time'=>'22:00']);

// Known unfinished overruns retain their resource until legitimate completion.
clevo_stmt($db,"INSERT INTO booking (UserID,StationID,ServiceID,Status,Service_Location,Booking_Date,Booking_Time,Total_Price,Payment_Method) VALUES (?,?,?,'In Progress','station',CURDATE()-INTERVAL 1 DAY,'10:00',10,'cash')",'iii',[$customer,$station,$service]);$overrun=(int)$db->insert_id;
rejects(fn()=>clevo_create_booking($db,$other,$station,array_replace($body,['booking_date'=>(new DateTimeImmutable('+22 days'))->format('Y-m-d')]),bw_key()),'Known overdue unfinished work does not silently release its capacity','booking_capacity_exceeded');
clevo_complete_owner_booking($db,$owner,$overrun);
check(clevo_create_booking($db,$other,$station,array_replace($body,['booking_date'=>(new DateTimeImmutable('+22 days'))->format('Y-m-d')]),bw_key())['booking_id']>0,'Legitimate completion releases an overrun reservation');

// The driver's graph is monotone; the old Arrived -> on-the-way cycle is forbidden.
clevo_stmt($db,"INSERT INTO stations (UserID,Name,Status,admin_status,Has_Delivery,BookingCapacity,HomeCapacity,OpeningTime,ClosingTime) VALUES (?,'Driver workflow','Active','approved',1,1,1,'00:00','00:00')",'i',[$owner]);$driverStation=(int)$db->insert_id;
$driverService=clevo_market_service($db,$owner,$driverStation,0,['name_ar'=>'استلام','name_en'=>'Pickup','price'=>'5.00','duration'=>30,'is_pickup'=>true]);$isolatedDriver=bw_actor('driver',$password);clevo_stmt($db,'UPDATE user SET StationID=? WHERE UserID=?','ii',[$driverStation,$isolatedDriver]);
$soon=(new DateTimeImmutable('now',new DateTimeZone('Asia/Amman')))->modify('+10 minutes');$soonBody=['service_ids'=>[$driverService],'service_location'=>'delivery','latitude'=>31.95,'longitude'=>35.91,'booking_date'=>$soon->format('Y-m-d'),'booking_time'=>$soon->format('H:i:s')];
$monotone=clevo_create_booking($db,$customer,$driverStation,$soonBody,bw_key());$monotoneID=(int)$monotone['booking_id'];clevo_market_assign($db,$owner,$monotoneID,$isolatedDriver);clevo_pay_booking($db,$customer,$monotoneID,'wallet',false,bw_key());clevo_driver_transition($db,$isolatedDriver,$monotoneID,'In Progress');clevo_driver_transition($db,$isolatedDriver,$monotoneID,'Arrived');
rejects(fn()=>clevo_driver_transition($db,$isolatedDriver,$monotoneID,'In Progress'),'Arrived driver cannot move backwards to on-the-way','invalid_transition');
foreach(['Pickup','Washing','Returning','Completed'] as $status)clevo_driver_transition($db,$isolatedDriver,$monotoneID,$status);
clevo_accept_owner_booking($db,$owner,(int)$home['booking_id']);clevo_pay_booking($db,$other,(int)$home['booking_id'],'wallet',false,bw_key());clevo_complete_owner_booking($db,$owner,(int)$home['booking_id']);
check(clevo_stmt($db,'SELECT Status FROM booking WHERE BookingID=?','i',[(int)$home['booking_id']])->get_result()->fetch_assoc()['Status']==='Completed','At-home crew service completes through the same funded owner workflow');

echo "Booking workflow HTTP requests: $calls.\n";
finish_tests('Shared booking workflow suite');
