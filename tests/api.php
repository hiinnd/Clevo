<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/../includes/api/http.php';
require_once __DIR__.'/../includes/api/contracts.php';
require_once __DIR__.'/../includes/api/routes.php';
if(clevo_env('APP_ENV')!=='testing'||clevo_env('DB_HOST')!=='127.0.0.1'||!preg_match('/^clevo_security_test_[a-f0-9]{12}$/D',clevo_env('DB_DATABASE','')??'')){fwrite(STDERR,"Use tests/run-isolated.ps1.\n");exit(2);}
$conn=clevo_connect_database();
if(!clevo_path_within(realpath($conn->query('SELECT @@datadir n')->fetch_assoc()['n']),realpath(__DIR__.'/../.security-test')))throw new RuntimeException('Isolated datadir required.');
$conn->query('DELETE FROM security_rate_limits');
$base=rtrim(clevo_env('APP_URL'),'/').'/api/v1/';$calls=0;$coverage=[];
function api_call(string $method,string $path,?array $data=null,?string $token=null,?string $key=null,array $headers=[]):array
{
    global $conn,$base,$calls,$coverage;
    if(++$calls%20===0)$conn->query('DELETE FROM security_rate_limits');
    if($token!==null)$headers[]='Authorization: Bearer '.$token;
    if($key!==null)$headers[]='Idempotency-Key: '.$key;
    $h=curl_init($base.$path);if($data!==null)$headers[]='Content-Type: application/json';$responseHeaders='';
    curl_setopt_array($h,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$headers]);
    if($data!==null)curl_setopt($h,CURLOPT_POSTFIELDS,json_encode($data===[]?new stdClass():$data));
    curl_setopt($h,CURLOPT_HEADERFUNCTION,static function($h,string $line)use(&$responseHeaders){$responseHeaders.=$line;return strlen($line);});
    $raw=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);$json=is_string($raw)?json_decode($raw,true):null;
    $coverage[$method.' '.explode('?',$path)[0]]=$status;
    return [$status,$json,(string)$raw,$responseHeaders];
}
function api_expect(int $expected,array $response,string $label):array
{
    [$code,$json]=$response;check($code===$expected,$label.' (HTTP '.$code.')');
    if($code!==204&&is_array($json))check(isset($json['status'],$json['data'],$json['message'],$json['meta']['request_id'])&&preg_match('/^[a-f0-9]{32}$/D',$json['meta']['request_id']),'Envelope/request ID: '.$label);
    if($code>=400&&is_array($json))check(isset($json['error']['code'],$json['error']['details'])&&$json['status']==='error','Structured error: '.$label);
    return $json['data']??[];
}
function api_key():string{return bin2hex(random_bytes(16));}
function api_user(string $role,string $password):int
{
    global $conn;$suffix=bin2hex(random_bytes(6));$phone='0798'.str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
    clevo_stmt($conn,"INSERT INTO user (UserName,Email,PhoneNumber,Password,Role,AccountStatus,EmailVerifiedAt) VALUES (?,?,?,?,?,'active',UTC_TIMESTAMP())",'sssss',['API '.$role,$suffix.'@api.example.test',$phone,password_hash($password,PASSWORD_DEFAULT),$role]);return (int)$conn->insert_id;
}
function api_login(int $id,string $password):string
{
    global $conn;$mail=clevo_stmt($conn,'SELECT Email FROM user WHERE UserID=?','i',[$id])->get_result()->fetch_assoc()['Email'];
    $data=api_expect(200,api_call('POST','auth/login',['login'=>$mail,'password'=>$password]),'API actor login');return $data['access_token']??'';
}
function api_booking_fixture(int $customer,int $station,int $service,string $status='Pending',string $mode='station',?int $driver=null):int
{
    global $conn;
    clevo_stmt($conn,"INSERT INTO booking (UserID,StationID,ServiceID,DriverID,Order_Number,Status,Service_Location,Booking_Date,Booking_Time,Total_Price,Admin_Commission,Accepted_At,OwnerIDSnapshot) SELECT ?,?,?,?, ?,?,?,CURDATE()+INTERVAL 2 DAY,'10:00:00',10,1,IF(?='Accepted',UTC_TIMESTAMP(),NULL),UserID FROM stations WHERE StationID=?",'iiiissssi',[$customer,$station,$service,$driver,'API'.bin2hex(random_bytes(7)),$status,$mode,$status,$station]);
    $id=(int)$conn->insert_id;static $slot=0;$slot++;$when=(new DateTimeImmutable('+2 days 00:00:00'))->modify('+'.$slot.' hours');
    clevo_stmt($conn,'UPDATE booking SET Booking_Date=?,Booking_Time=? WHERE BookingID=?','ssi',[$when->format('Y-m-d'),$when->format('H:i:s'),$id]);return $id;
}
$password='Api!'.bin2hex(random_bytes(12));
$customer=api_user('customer',$password);$other=api_user('User',$password);$owner=api_user('owner',$password);$owner2=api_user('owner',$password);$driver=api_user('driver',$password);$driver2=api_user('driver',$password);$admin=api_user('Admin',$password);
foreach([$owner,$owner2]as $uid)clevo_stmt($conn,"INSERT INTO stations (UserID,Name,Location,Lat,Lon,Status,admin_status,Has_At_Station,Has_Home_Service,Has_Delivery) VALUES (?,'API fixture','Amman',31.95,35.91,'Active','approved',1,1,1)",'i',[$uid]);
$conn->query('UPDATE stations SET BookingCapacity=1000,HomeCapacity=1000');
$station=(int)clevo_stmt($conn,'SELECT StationID FROM stations WHERE UserID=?','i',[$owner])->get_result()->fetch_assoc()['StationID'];$station2=$station+1;
clevo_stmt($conn,'UPDATE user SET StationID=? WHERE UserID=?','ii',[$station,$driver]);clevo_stmt($conn,'UPDATE user SET StationID=? WHERE UserID=?','ii',[$station2,$driver2]);
foreach([$station,$station2]as $sid)clevo_stmt($conn,"INSERT INTO services (StationID,Name,Name_ar,Name_en,Price,is_station,is_home,is_pickup) VALUES (?,'API wash','API wash','API wash',10,1,1,1)",'i',[$sid]);
$service=(int)clevo_stmt($conn,'SELECT ServiceID FROM services WHERE StationID=?','i',[$station])->get_result()->fetch_assoc()['ServiceID'];$service2=$service+1;
clevo_stmt($conn,'INSERT INTO wallet (UserID,Balance) VALUES (?,200),(?,200),(?,0)','iii',[$customer,$other,$owner]);
$tokens=[];foreach(['customer'=>$customer,'other'=>$other,'owner'=>$owner,'owner2'=>$owner2,'driver'=>$driver,'driver2'=>$driver2,'admin'=>$admin]as $name=>$uid)$tokens[$name]=api_login($uid,$password);
$c=$tokens['customer'];$o=$tokens['owner'];$d=$tokens['driver'];$ad=$tokens['admin'];

// Every registry operation gets real method/auth/role boundary requests, not a filename assertion.
foreach(clevo_api_routes()as $route){
    $path=str_replace(['{id}','{session}'],['2147483647',str_repeat('a',48)],$route['path']);
    if($route['roles']!=='public')api_expect(401,api_call($route['method'],$path,[]),'Authentication gate '.$route['method'].' '.$route['path']);
    api_expect(405,api_call('TRACE',$path),'Method gate '.$route['path']);
    if(!in_array($route['roles'],['any','public'],true)){
        $wrongRoles=array_values(array_diff(['customer','owner','driver','admin'],explode(',',$route['roles'])));$wrong=$tokens[$wrongRoles[0]];
        api_expect(403,api_call($route['method'],$path,[],$wrong,api_key()),'Role gate '.$route['path']);
    }
}
api_expect(404,api_call('GET','unknown-endpoint'),'Unknown route is structured 404');
api_expect(401,api_call('GET','stations',null,'malformed'),'Public endpoint rejects invalid explicit bearer');
$data=api_expect(200,api_call('GET','stations?query=API&location=Amman&min_price=5&max_price=20&min_rating=0&lat=31.95&lng=35.91&radius_km=5&sort=distance&direction=asc&per_page=1'),'Public catalog filters and location sorting');
check(count($data['stations']??[])===1&&isset($data['stations'][0]['DistanceKm']),'Catalog pagination and real distance');
check(str_starts_with($data['stations'][0]['Image']??'','http'),'Catalog image URL is absolute');
api_expect(200,api_call('GET','stations/search?query=API&sort=rating'),'Station search alias');
api_expect(422,api_call('GET','stations?sort=Name%20DESC%3BDROP%20TABLE%20user'),'Sort injection rejected');
api_expect(422,api_call('GET','stations?lat=99&lng=0'),'Invalid coordinates rejected');
api_expect(422,api_call('GET','stations?lat=31&radius_km=5'),'Incomplete coordinates rejected');
api_expect(422,api_call('GET','stations?min_price=20&max_price=5'),'Invalid price interval rejected');
api_expect(422,api_call('GET','stations?per_page=101'),'Unbounded page size rejected');
foreach(["stations/$station","stations/$station/services","stations/$station/reviews","stations/$station/availability?date=2027-01-20",'subscription-plans']as $path)api_expect(200,api_call('GET',$path),'Public read '.$path);
api_expect(404,api_call('GET','stations/2147483647'),'Unpublished/missing station hidden');

$profile=api_expect(200,api_call('PATCH','me',['full_name'=>'API Updated','car_name'=>'Example'],$c,api_key()),'Profile update');check(($profile['user']['name']??'')==='API Updated','Updated profile returned');
api_expect(422,api_call('PATCH','me',['role'=>'Admin'],$c,api_key()),'Profile cannot change role');
api_expect(422,api_call('PATCH','me',['full_name'=>['invalid']],$c,api_key()),'Nested input cannot bypass scalar validation');
$address=['label'=>'Home','address'=>'Example street','latitude'=>31.95,'longitude'=>35.91,'is_default'=>true];$key=api_key();
$addr=api_expect(201,api_call('POST','me/addresses',$address,$c,$key),'Saved address create');$addressID=(int)($addr['address_id']??0);
$again=api_expect(201,api_call('POST','me/addresses',$address,$c,$key),'Saved address retry');check($again===$addr,'Address idempotency returns same record');
api_expect(409,api_call('POST','me/addresses',array_replace($address,['label'=>'Work']),$c,$key),'Address key payload conflict');
api_expect(404,api_call('PUT',"me/addresses/$addressID",$address,$tokens['other'],api_key()),'Other user address update denied');
api_expect(404,api_call('DELETE',"me/addresses/$addressID",[],$tokens['other'],api_key()),'Other user address deletion denied');
api_expect(200,api_call('PUT',"me/addresses/$addressID",array_replace($address,['label'=>'Updated']),$c,api_key()),'Saved address update');
api_expect(200,api_call('GET','me/addresses?page=1&per_page=1',$data=null,$c),'Saved address pagination');
api_expect(200,api_call('DELETE',"me/addresses/$addressID",[],$c,api_key()),'Saved address archive');
check((bool)clevo_stmt($conn,'SELECT ArchivedAt FROM saved_addresses WHERE AddressID=?','i',[$addressID])->get_result()->fetch_assoc()['ArchivedAt'],'Address history preserved');

// Real multipart avatar using the shared private-upload/MIME path.
$png=__DIR__.'/../.security-test/api-avatar.png';file_put_contents($png,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9a0AAAAASUVORK5CYII='));
$h=curl_init($base.'me/avatar');curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$c,'Idempotency-Key: '.api_key()],CURLOPT_POSTFIELDS=>['avatar'=>new CURLFile($png,'image/png','avatar.png')]]);$json=json_decode(curl_exec($h),true);$code=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);check($code===200&&str_contains($json['data']['profile_image']??'','includes/media.php'),'API avatar is validated, private and delivered by absolute public-image URL');
clevo_stmt($conn,'UPDATE user SET VerificationFile=? WHERE UserID=?','si',[clevo_stmt($conn,'SELECT ProfilePic FROM user WHERE UserID=?','i',[$customer])->get_result()->fetch_assoc()['ProfilePic'],$owner]);
// An invalid-kind reference never becomes a filesystem path; no token grants arbitrary file access.
api_expect(403,api_call('GET',"admin/users/$owner/verification-document",null,$c),'Customer cannot download another verification document');
$docRoot=rtrim(clevo_env('PRIVATE_STORAGE_PATH'),'/\\').'/verification';if(!is_dir($docRoot))mkdir($docRoot,0700,true);$docName=bin2hex(random_bytes(24)).'.png';copy($png,$docRoot.'/'.$docName);
clevo_stmt($conn,'UPDATE user SET VerificationFile=? WHERE UserID=?','si',['private:verification:'.$docName,$owner]);
[$status,,$binary,$headers]=api_call('GET',"admin/users/$owner/verification-document",null,$ad);check($status===200&&$binary===file_get_contents($png)&&str_contains($headers,'image/png'),'Bearer admin may download the protected document');
[$status,,$binary]=api_call('GET','me/verification-document',null,$o);check($status===200&&$binary===file_get_contents($png),'Owner may download own verification document');
api_expect(404,api_call('GET','me/verification-document',null,$c),'Customer cannot select another document owner');

api_expect(200,api_call('GET','owner/stations',null,$o),'Owner stations');
api_expect(200,api_call('GET',"owner/stations/$station/services",null,$o),'Owner service list');
api_expect(404,api_call('GET',"owner/stations/$station2/services",null,$o),'Other station service list denied');
$s=api_expect(201,api_call('POST',"owner/stations/$station/services",['name_ar'=>'API exterior','name_en'=>'API exterior','price'=>'5.00','is_station'=>true],$o,api_key()),'Owner service create');$newService=(int)($s['service_id']??0);
$modeCatalog=api_expect(200,api_call('GET','stations?query=API&mode=home&max_price=6'),'Mode-specific service prices drive filtering');check(count($modeCatalog['stations']??[])===0,'Cheap station-only services cannot advertise cheap home service');
api_expect(422,api_call('PATCH',"owner/services/$newService",['price'=>1.001],$o,api_key()),'Sub-cent price rejected');
api_expect(200,api_call('PATCH',"owner/services/$newService",['price'=>'6.00'],$o,api_key()),'Owner price update');
api_expect(404,api_call('PATCH',"owner/services/$service2",['price'=>'1.00'],$o,api_key()),'Other owner service update denied');
api_expect(200,api_call('PATCH',"owner/stations/$station/availability",['status'=>'Busy','opening_time'=>'08:00','closing_time'=>'22:00'],$o,api_key()),'Owner availability update');
api_expect(200,api_call('PATCH',"owner/stations/$station/availability",['status'=>'Active'],$o,api_key()),'Owner availability restored');
api_expect(422,api_call('PATCH',"owner/stations/$station/availability",['opening_time'=>'25:99'],$o,api_key()),'Invalid opening clock rejected');
api_expect(200,api_call('PATCH',"owner/stations/$station",['location'=>'API Updated location','latitude'=>31.95,'longitude'=>35.91],$o,api_key()),'Owner station information update');
api_expect(404,api_call('PATCH',"owner/stations/$station2",['name'=>'Not mine'],$o,api_key()),'Other owner station update denied');
$h=curl_init($base."owner/stations/$station/image");curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$o,'Idempotency-Key: '.api_key()],CURLOPT_POSTFIELDS=>['image'=>new CURLFile($png,'image/png','station.png')]]);$image=json_decode(curl_exec($h),true);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);check($status===200&&isset($image['data']['image']),'Owner station image uses shared validated upload storage');

$body=['station_id'=>$station,'service_ids'=>[$service],'service_location'=>'station','booking_date'=>(new DateTimeImmutable('+2 days'))->format('Y-m-d'),'booking_time'=>'10:00'];$key=api_key();
$created=api_expect(200,api_call('POST','bookings',$body,$c,$key),'API booking create');$booking=(int)($created['booking_id']??0);
$repeat=api_expect(200,api_call('POST','bookings',$body,$c,$key),'API booking idempotent retry');check($created===$repeat,'Booking retry returns one resource');
api_expect(422,api_call('POST','bookings',$body,$c),'Booking requires idempotency key');
api_expect(200,api_call('GET',"bookings/$booking",null,$c),'View own booking');
api_expect(404,api_call('GET',"bookings/$booking",null,$tokens['other']),'Cross-customer booking denied');
api_expect(200,api_call('GET',"bookings/$booking/status",null,$c),'Booking status');
api_expect(200,api_call('GET',"bookings/$booking/price-breakdown",null,$c),'Server price breakdown');
api_expect(200,api_call('GET','bookings?per_page=1&sort=date',null,$c),'Booking list pagination');
api_expect(200,api_call('GET','bookings/history',null,$c),'Booking history');
api_expect(200,api_call('GET','owner/bookings',null,$o),'Owner station-scoped booking list');
api_expect(404,api_call('POST',"owner/bookings/$booking/accept",[],$tokens['owner2'],api_key()),'Other station acceptance denied');
api_expect(200,api_call('POST',"owner/bookings/$booking/accept",[],$o,api_key()),'Owner accepts booking');

$session=api_expect(201,api_call('POST','payment-sessions',['booking_id'=>$booking,'method'=>'wallet'],$c,api_key()),'Create uncharged internal payment session');$sessionID=$session['payment_session']['SessionID']??str_repeat('a',48);
check((float)clevo_stmt($conn,'SELECT Balance FROM wallet WHERE UserID=?','i',[$customer])->get_result()->fetch_assoc()['Balance']===200.0,'Session creation does not debit wallet');
api_expect(404,api_call('GET',"payment-sessions/$sessionID",null,$tokens['other']),'Cross-user payment session denied');
api_expect(200,api_call('GET',"payment-sessions/$sessionID",null,$c),'Own payment session status');
$confirmKey=api_key();$confirmed=api_expect(200,api_call('POST',"payment-sessions/$sessionID/confirm",[],$c,$confirmKey),'Confirm wallet session');
api_expect(200,api_call('POST',"payment-sessions/$sessionID/confirm",[],$c,$confirmKey),'Confirm retry');
check((float)clevo_stmt($conn,'SELECT Balance FROM wallet WHERE UserID=?','i',[$customer])->get_result()->fetch_assoc()['Balance']===190.0,'Session confirmation debits once');
$paymentID=(int)($confirmed['payment_session']['PaymentID']??0);
foreach([['1.00','JOD'],['10.00','USD']] as [$receiptAmount,$receiptCurrency]){
    clevo_stmt($conn,'UPDATE payments SET Amount=?,Currency=? WHERE PaymentID=?','ssi',[$receiptAmount,$receiptCurrency,$paymentID]);
    $inconsistent=api_expect(200,api_call('GET',"bookings/$booking/price-breakdown",null,$c),'Inconsistent historical receipt is presented for reconciliation');
    check(($inconsistent['price_breakdown']['settlement_state']??'')==='reconciliation_required'&&array_key_exists('outstanding',$inconsistent['price_breakdown'])&&$inconsistent['price_breakdown']['outstanding']===null,'Receipt amount/currency mismatch never claims a settled zero balance');
    api_expect(409,api_call('POST',"owner/bookings/$booking/complete",[],$o,api_key()),'Mismatched receipt cannot authorize completion');
}
clevo_stmt($conn,"UPDATE payments SET Amount='10.00',Currency='JOD' WHERE PaymentID=?",'i',[$paymentID]);
api_expect(200,api_call('GET',"payments/$paymentID",null,$c),'Payment status');
api_expect(404,api_call('GET',"payments/$paymentID",null,$tokens['other']),'Other customer payment hidden');
foreach(['payments','me/wallet','me/wallet/transactions','me/subscriptions','notifications','owner/earnings']as $path)api_expect(200,api_call('GET',$path,null,str_starts_with($path,'owner/')?$o:$c),'Read scoped '.$path);
$refund=api_expect(201,api_call('POST','refund-requests',['booking_id'=>$booking,'reason'=>'Please cancel'],$c,api_key()),'Customer refund request');$refundID=(int)($refund['refund_id']??0);
api_expect(200,api_call('GET','refund-requests',null,$c),'Own refund request list');
api_expect(200,api_call('PATCH',"admin/refund-requests/$refundID",['status'=>'approved','note'=>'Cancelled before service'],$ad,api_key()),'Admin approves eligible internal refund');
check((float)clevo_stmt($conn,'SELECT Balance FROM wallet WHERE UserID=?','i',[$customer])->get_result()->fetch_assoc()['Balance']===200.0,'Approved refund restores balance');
api_expect(409,api_call('PATCH',"admin/refund-requests/$refundID",['status'=>'approved','note'=>'Again'],$ad,api_key()),'Second refund review cannot mint funds');
$fresh=api_booking_fixture($customer,$station,$service,'Accepted');
$unfundedPlan=api_booking_fixture($customer,$station,$service,'Accepted');clevo_stmt($conn,"UPDATE booking SET Payment_Method='plan' WHERE BookingID=?",'i',[$unfundedPlan]);
api_expect(409,api_call('POST',"owner/bookings/$unfundedPlan/complete",[],$o,api_key()),'Legacy forged plan marker cannot mint payout/completion reward');
api_expect(503,api_call('POST','payment-sessions',['booking_id'=>$fresh,'method'=>'visa'],$c,api_key()),'Unconfigured card provider is unavailable, never simulated');
api_expect(200,api_call('POST',"bookings/$fresh/payments",['method'=>'cash'],$c,api_key()),'Existing direct payment route retained');
api_expect(200,api_call('POST',"owner/bookings/$fresh/complete",[],$o,api_key()),'Owner completes paid job');
$review=api_expect(201,api_call('POST',"bookings/$fresh/reviews",['rating'=>5,'comment'=>'Example review'],$c,api_key()),'Completed own booking review');$reviewID=(int)($review['review_id']??0);
$rating=api_expect(200,api_call('GET',"stations/$station"),'Station details use actual review average');check((float)($rating['station']['Rating']??0)===5.0,'Detail rating agrees with catalog aggregate instead of stale cache');
api_expect(409,api_call('POST',"bookings/$fresh/reviews",['rating'=>5],$c,api_key()),'Duplicate review rejected');
api_expect(200,api_call('PATCH',"owner/reviews/$reviewID/reply",['message'=>'Thank you'],$o,api_key()),'Owner review reply');
api_expect(404,api_call('PATCH',"owner/reviews/$reviewID/reply",['message'=>'Not mine'],$tokens['owner2'],api_key()),'Other owner review reply denied');
api_expect(200,api_call('GET','me/reviews',null,$c),'Customer reviews');api_expect(200,api_call('GET','owner/reviews',null,$o),'Owner reviews');

$pending=api_booking_fixture($customer,$station,$service);
api_expect(200,api_call('POST',"bookings/$pending/reschedule",['booking_date'=>(new DateTimeImmutable('+3 days'))->format('Y-m-d'),'booking_time'=>'11:00'],$c,api_key()),'Pending reschedule uses shared service');
api_expect(200,api_call('POST',"bookings/$pending/cancel",[],$c,api_key()),'Pending cancellation uses shared service');
$reject=api_booking_fixture($customer,$station,$service);$rejectKey=api_key();api_expect(200,api_call('POST',"owner/bookings/$reject/reject",[],$o,$rejectKey),'Owner rejects pending booking');
$rejectionNotices=static fn():int=>(int)clevo_stmt($conn,"SELECT COUNT(*) n FROM notifications WHERE target_role='Admin' AND body_en=?",'s',["Station owner rejected order #$reject."])->get_result()->fetch_assoc()['n'];
check($rejectionNotices()===1,'Shared rejection retains the existing administrator notification');
api_expect(200,api_call('POST',"owner/bookings/$reject/reject",[],$o,$rejectKey),'Owner rejection retry returns the committed result');
check($rejectionNotices()===1,'Owner rejection replay does not duplicate administrator notifications');

$newDriver=api_expect(201,api_call('POST','owner/drivers',['station_id'=>$station,'name'=>'API New driver','phone'=>'0799'.str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT)],$o,api_key()),'Owner driver provisioning without shared password');$newDriverID=(int)($newDriver['driver_id']??0);
api_expect(200,api_call('PATCH',"owner/drivers/$newDriverID",['name'=>'API Updated driver'],$o,api_key()),'Driver profile update');
api_expect(404,api_call('DELETE',"owner/drivers/$driver2",[],$o,api_key()),'Other station driver deactivation denied');
api_expect(200,api_call('GET','owner/drivers',null,$o),'Owner driver list');
$inviteEmail=bin2hex(random_bytes(6)).'@api.example.test';
api_expect(404,api_call('POST',"owner/drivers/$newDriverID/invitation",['email'=>$inviteEmail],$tokens['owner2'],api_key()),'Other owner cannot enroll driver identity');
api_expect(200,api_call('POST',"owner/drivers/$newDriverID/invitation",['email'=>$inviteEmail],$o,api_key()),'Owner sends initial driver email invitation');
$inviteMail=clevo_decrypt_mail($conn->query('SELECT PayloadCipher FROM security_mail_queue ORDER BY ID DESC LIMIT 1')->fetch_assoc()['PayloadCipher']);preg_match('/#token=([^"<]+)/',$inviteMail['html'],$inviteMatch);
api_expect(200,api_call('POST','auth/email/verify',['token'=>rawurldecode(html_entity_decode($inviteMatch[1]??''))]),'Driver verifies invitation address');
$forgot=api_expect(200,api_call('POST','auth/password/forgot',['email'=>$inviteEmail]),'Driver requests secure initial password setup');
$otpMail=clevo_decrypt_mail($conn->query('SELECT PayloadCipher FROM security_mail_queue ORDER BY ID DESC LIMIT 1')->fetch_assoc()['PayloadCipher']);preg_match('/<strong>([0-9]{6})<\/strong>/',$otpMail['html'],$otpMatch);
$grant=api_expect(200,api_call('POST','auth/password/verify',['challenge_id'=>$forgot['challenge_id'],'otp'=>$otpMatch[1]??'']),'Driver invitation password OTP verification');
$driverPassword='Driver1!'.bin2hex(random_bytes(12));
api_expect(200,api_call('POST','auth/password/reset',['reset_grant'=>$grant['reset_grant']??'','password'=>$driverPassword]),'Driver sets a private hashed password');
$persistentDriver=api_login($newDriverID,$driverPassword);api_expect(200,api_call('GET','driver/jobs/history',null,$persistentDriver),'Invited driver can use persistent account/history scope');
api_expect(409,api_call('POST',"owner/drivers/$newDriverID/invitation",['email'=>'replacement@api.example.test'],$o,api_key()),'Owner cannot replace enrolled driver recovery identity');
api_expect(200,api_call('DELETE',"owner/drivers/$newDriverID",[],$o,api_key()),'Driver deactivation preserves account');
$delivery=api_booking_fixture($customer,$station,$service,'Pending','delivery');
api_expect(200,api_call('POST',"owner/bookings/$delivery/driver",['driver_id'=>$driver],$o,api_key()),'Owner delivery assignment');
$code=api_expect(200,api_call('POST',"owner/bookings/$delivery/driver-code",[],$o),'Owner one-time task grant issuance');
check(preg_match('/^[0-9]{6}$/D',$code['driver_code']??'')===1,'Grant returned only through authorized issuance');
$driverMail=clevo_stmt($conn,'SELECT Email FROM user WHERE UserID=?','i',[$driver])->get_result()->fetch_assoc()['Email'];
$taskPair=api_expect(200,api_call('POST','auth/driver-exchanges',['login'=>$driverMail,'code'=>$code['driver_code']]),'Task-bound mobile login');$taskToken=$taskPair['access_token']??'';
api_expect(403,api_call('PATCH','me',['email'=>'attacker@api.example.test'],$taskToken,api_key()),'Task grant cannot bootstrap full-account recovery authority');
api_expect(403,api_call('GET','notifications',null,$taskToken),'Task grant cannot inspect unrelated job inbox');
api_expect(403,api_call('GET','me/wallet',null,$taskToken),'Task grant cannot read account finances');
api_expect(403,api_call('POST','auth/logout-all',[],$taskToken),'Task grant cannot revoke other device sessions');
$otherJob=api_booking_fixture($customer,$station,$service,'Accepted','delivery',$driver);
api_expect(404,api_call('GET',"driver/jobs/$otherJob",null,$taskToken),'Task grant cannot read different job for same driver');
api_expect(200,api_call('POST',"bookings/$delivery/payments",['method'=>'wallet'],$c,api_key()),'Delivery prepayment');
$contact=api_expect(200,api_call('GET',"bookings/$delivery",null,$c),'Customer may contact the driver on own active delivery');check((int)($contact['booking']['driver']['id']??0)===$driver&&!isset($contact['booking']['DriverAccessCodeHash']),'Assigned driver contact is scoped and omits credentials');
foreach(['driver/jobs','driver/tasks',"driver/jobs/$delivery"]as $path)api_expect(200,api_call('GET',$path,null,$d),'Driver assigned read '.$path);
$acceptKey=api_key();api_expect(200,api_call('POST',"driver/jobs/$delivery/accept",[],$d,$acceptKey),'Driver accepts paid assigned job');api_expect(200,api_call('POST',"driver/jobs/$delivery/accept",[],$d,$acceptKey),'Driver acceptance retry');
api_expect(404,api_call('POST',"driver/jobs/$delivery/location",['latitude'=>31.9,'longitude'=>35.9],$tokens['driver2'],api_key()),'Other driver GPS write denied');
api_expect(200,api_call('POST',"driver/jobs/$delivery/location",['latitude'=>31.9,'longitude'=>35.9],$d,api_key()),'Assigned GPS update');
api_expect(409,api_call('PATCH',"driver/jobs/$delivery/status",['status'=>'Completed'],$d,api_key()),'Driver cannot skip lifecycle');
foreach(['Pickup','Washing','Returning','Completed']as $state)api_expect(200,api_call('PATCH',"driver/jobs/$delivery/status",['status'=>$state],$d,api_key()),'Driver lifecycle '.$state);
api_expect(401,api_call('GET',"driver/jobs/$delivery",null,$taskToken),'Completion revokes task-code session');
$history=api_expect(200,api_call('GET','driver/jobs/history',null,$d),'Driver job history');check(count($history['tasks']??[])>=1&&!isset($history['tasks'][0]['Address_Details']),'History omits historical customer address');
api_expect(200,api_call('GET','driver/tasks/history',null,$d),'Legacy task alias history');

// Session expiry/quote changes cannot reserve or spend money silently.
$sessionBooking=api_booking_fixture($customer,$station,$service,'Accepted');
$p=api_expect(201,api_call('POST','payment-sessions',['booking_id'=>$sessionBooking,'method'=>'wallet'],$c,api_key()),'Expiring session fixture');$expiredID=$p['payment_session']['SessionID'];
clevo_stmt($conn,'UPDATE payment_sessions SET ExpiresAt=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE SessionID=?','s',[$expiredID]);
$expired=api_expect(200,api_call('GET',"payment-sessions/$expiredID",null,$c),'Expired session read');check(($expired['payment_session']['Status']??'')==='expired','Expired state is derived without GET mutation');
api_expect(409,api_call('POST',"payment-sessions/$expiredID/confirm",[],$c,api_key()),'Expired session cannot confirm');
$q=api_expect(201,api_call('POST','payment-sessions',['booking_id'=>$sessionBooking,'method'=>'wallet','use_points'=>true],$c,api_key()),'Points quote session');$quoteID=$q['payment_session']['SessionID'];clevoAddLoyaltyPoints($conn,$customer,1);
$balanceBefore=clevo_stmt($conn,'SELECT Balance FROM wallet WHERE UserID=?','i',[$customer])->get_result()->fetch_assoc()['Balance'];
api_expect(409,api_call('POST',"payment-sessions/$quoteID/confirm",[],$c,api_key()),'Changed points quote requires renewed consent');
check(clevo_stmt($conn,'SELECT Balance FROM wallet WHERE UserID=?','i',[$customer])->get_result()->fetch_assoc()['Balance']===$balanceBefore,'Changed quote consumes no money');
$late=api_booking_fixture($customer,$station,$service,'Accepted','home');clevo_stmt($conn,'UPDATE booking SET Accepted_At=UTC_TIMESTAMP()-INTERVAL 11 MINUTE,created_at=UTC_TIMESTAMP()-INTERVAL 12 MINUTE WHERE BookingID=?','i',[$late]);
api_expect(409,api_call('POST',"bookings/$late/payments",['method'=>'wallet'],$c,api_key()),'Expired home-payment window is enforced without web page visits');
$oldPending=api_booking_fixture($customer,$station,$service);clevo_stmt($conn,'UPDATE booking SET created_at=UTC_TIMESTAMP()-INTERVAL 11 MINUTE WHERE BookingID=?','i',[$oldPending]);
api_expect(409,api_call('POST',"owner/bookings/$oldPending/accept",[],$o,api_key()),'Expired pending order cannot be accepted');
$p=proc_open([PHP_BINARY,__DIR__.'/../scripts/expire-bookings.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));fclose($pipes[0]);stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($p)===0,'Headless booking expiry worker runs without PHP page traffic');
check(clevo_stmt($conn,'SELECT Status FROM booking WHERE BookingID=?','i',[$oldPending])->get_result()->fetch_assoc()['Status']==='Cancelled_Timeout','Expiry worker applies shared terminal state transactionally');

// Withdrawal reserves and manual administrator decisions use the same shared service as web pages.
$bank=['account_holder_name'=>'Synthetic owner','bank_name'=>'Synthetic bank','iban'=>'JO00TEST00000000000000000000000'];
api_expect(200,api_call('PUT','owner/bank-account',$bank,$o,api_key()),'Owner saves private bank details');api_expect(200,api_call('GET','owner/bank-account',null,$o),'Owner reads own bank details');
$withdraw=api_expect(201,api_call('POST','owner/withdrawals',[],$o,api_key()),'Owner withdrawal reserves available earnings');$withdrawID=(int)($withdraw['withdrawal_id']??0);
api_expect(200,api_call('GET','owner/withdrawals',null,$o),'Owner withdrawal history');
api_expect(200,api_call('GET',"admin/withdrawals/$withdrawID",null,$ad),'Audited administrator bank snapshot read');
$decisionKey=api_key();api_expect(200,api_call('PATCH',"admin/withdrawals/$withdrawID",['status'=>'rejected'],$ad,$decisionKey),'Admin withdrawal rejection restores reserved funds');
api_expect(200,api_call('PATCH',"admin/withdrawals/$withdrawID",['status'=>'rejected'],$ad,$decisionKey),'Withdrawal decision replay');
api_expect(409,api_call('PATCH',"admin/withdrawals/$withdrawID",['status'=>'paid'],$ad,api_key()),'Processed withdrawal cannot change outcome');

$large=['full_name'=>str_repeat('x',17000)];api_expect(413,api_call('PATCH','me',$large,$c,api_key()),'JSON payload size is bounded before validation');
[$status,,$raw,$corsHeaders]=api_call('OPTIONS','me/addresses/1',null,null,null,['Origin: https://app.example.test','Access-Control-Request-Method: PUT']);
check($status===204&&str_contains($corsHeaders,'PUT')&&str_contains($corsHeaders,'X-Request-ID'),'Web-client CORS admits PUT and exposes correlation/retry headers');

$ticket=api_expect(201,api_call('POST','support-requests',['subject'=>'Booking question','message'=>'Please help','category'=>'dispute','booking_id'=>$fresh],$c,api_key()),'Dispute backed by owned booking');$ticketID=(int)($ticket['ticket_id']??0);
api_expect(404,api_call('GET',"support-requests/$ticketID",null,$tokens['other']),'Other user support request denied');
foreach(['support-requests',"support-requests/$ticketID","support-requests/$ticketID/messages"]as $path)api_expect(200,api_call('GET',$path,null,$c),'Own support read '.$path);
api_expect(201,api_call('POST',"support-requests/$ticketID/messages",['message'=>'Additional details'],$c,api_key()),'Customer support reply');
api_expect(201,api_call('POST',"admin/disputes/$ticketID/messages",['message'=>'We are reviewing'],$ad,api_key()),'Admin dispute reply');
api_expect(200,api_call('PATCH',"admin/disputes/$ticketID/status",['status'=>'Closed'],$ad,api_key()),'Admin dispute closes without deletion');
api_expect(409,api_call('POST',"support-requests/$ticketID/messages",['message'=>'Closed reply'],$c,api_key()),'Closed support thread cannot receive new messages');
api_expect(200,api_call('POST','notifications/read-all',[],$c),'Per-recipient notification read-all');
check((bool)clevo_stmt($conn,'SELECT id FROM support_tickets WHERE id=?','i',[$ticketID])->get_result()->fetch_assoc(),'Support history remains after closing');

$plan=api_expect(201,api_call('POST','admin/subscription-plans',['name_ar'=>'API plan','name_en'=>'API plan','price'=>'15.00','duration'=>1,'duration_type'=>'month','wash_count'=>3],$ad,api_key()),'Admin plan create');$planID=(int)($plan['plan_id']??0);
api_expect(200,api_call('PATCH',"admin/subscription-plans/$planID",['price'=>'20.00'],$ad,api_key()),'Admin plan price update');
$purchase=api_expect(201,api_call('POST','me/subscriptions',['plan_id'=>$planID,'method'=>'wallet'],$c,api_key()),'Wallet subscription payment');$subscriptionID=(int)($purchase['subscription_id']??0);
$planOrder=api_expect(200,api_call('POST','bookings',array_replace($body,['use_plan'=>true]),$c,api_key()),'Plan-covered booking uses purchased entitlement');$planOrderID=(int)($planOrder['booking_id']??0);
$planPrice=api_expect(200,api_call('GET',"bookings/$planOrderID/price-breakdown",null,$c),'Plan price breakdown distinguishes nominal value and customer outlay');
check(($planPrice['price_breakdown']['payable']??'')==='0.00'&&($planPrice['price_breakdown']['outstanding']??'')==='0.00'&&($planPrice['price_breakdown']['plan_covered']??false),'Plan entitlement does not present nominal price as cash owed');
api_expect(200,api_call('POST',"bookings/$planOrderID/cancel",[],$c,api_key()),'Plan-backed cancellation restores entitlement through shared refund');
api_expect(503,api_call('POST','me/subscriptions',['plan_id'=>$planID,'method'=>'visa'],$c,api_key()),'Subscription card mock unavailable');
api_expect(200,api_call('PATCH',"admin/subscriptions/$subscriptionID",['status'=>'cancelled'],$ad,api_key()),'Admin subscription cancellation preserves history');
api_expect(200,api_call('DELETE',"admin/subscription-plans/$planID",[],$ad,api_key()),'Admin plan retirement preserves purchases');
foreach(['admin/users','admin/users?role=driver','admin/stations','admin/bookings','admin/financial-reports','admin/subscription-plans','admin/subscriptions','admin/support-requests','admin/disputes','admin/refund-requests','admin/withdrawals',"admin/users/$customer","admin/stations/$station","admin/bookings/$booking","admin/support-requests/$ticketID","admin/disputes/$ticketID"]as $path)api_expect(200,api_call('GET',$path,null,$ad),'Admin read '.$path);
api_expect(409,api_call('PATCH',"admin/users/$admin/status",['status'=>'rejected'],$ad,api_key()),'Administrator cannot self-restrict through status API');
api_expect(200,api_call('PATCH',"admin/users/$other/status",['status'=>'rejected'],$ad,api_key()),'Admin restriction revokes account sessions');
api_expect(401,api_call('GET','me',null,$tokens['other']),'Restricted account token immediately denied');
api_expect(200,api_call('PATCH',"admin/users/$other/status",['status'=>'active'],$ad,api_key()),'Admin restores verified account');
api_expect(200,api_call('PATCH',"admin/stations/$station/status",['admin_status'=>'approved'],$ad,api_key()),'Admin station approval respects verified owner');
api_expect(200,api_call('DELETE',"owner/services/$newService",[],$o,api_key()),'Service archive through API');
api_expect(200,api_call('DELETE',"admin/stations/$station2",[],$ad,api_key()),'Admin station archive through API');
api_expect(404,api_call('GET',"stations/$station2"),'Archived station hidden from public API');

// Recovery-address changes are staged atomically with the encrypted verification mail.
$oldEmail=clevo_stmt($conn,'SELECT Email FROM user WHERE UserID=?','i',[$customer])->get_result()->fetch_assoc()['Email'];$newEmail=bin2hex(random_bytes(6)).'@api.example.test';$profileKey=api_key();
$mailBefore=(int)$conn->query('SELECT COUNT(*) n FROM security_mail_queue')->fetch_assoc()['n'];
api_expect(200,api_call('PATCH','me',['email'=>$newEmail],$c,$profileKey),'API recovery-email change is staged');
api_expect(200,api_call('PATCH','me',['email'=>$newEmail],$c,$profileKey),'Recovery-email change retry');
$identity=clevo_stmt($conn,'SELECT Email,PendingEmail FROM user WHERE UserID=?','i',[$customer])->get_result()->fetch_assoc();check($identity['Email']===$oldEmail&&$identity['PendingEmail']===$newEmail,'Unverified address never replaces current recovery identity');
check((int)$conn->query('SELECT COUNT(*) n FROM security_mail_queue')->fetch_assoc()['n']===$mailBefore+1,'Idempotent profile change queues one encrypted message');
$mail=clevo_decrypt_mail($conn->query('SELECT PayloadCipher FROM security_mail_queue ORDER BY ID DESC LIMIT 1')->fetch_assoc()['PayloadCipher']);preg_match('/#token=([^"<]+)/',$mail['html'],$verifyMatch);$verifyToken=rawurldecode(html_entity_decode($verifyMatch[1]??''));
api_expect(200,api_call('POST','auth/email/verify',['token'=>$verifyToken]),'Mobile email verification consumes single-use grant');
api_expect(401,api_call('GET','me',null,$c),'Verified identity change revokes prior mobile session');
$c=api_login($customer,$password);api_expect(200,api_call('GET','me',null,$c),'Verified address can authenticate into current profile');

// These policy assertions are deliberately independent of the generated registry contract.
foreach(['admin/users','admin/stations','admin/bookings','admin/financial-reports','admin/subscription-plans','admin/subscriptions','admin/support-requests','admin/disputes','admin/refund-requests','admin/withdrawals'] as $path)api_expect(403,api_call('GET',$path,null,$c),'Customer cannot enter privileged module '.$path);
api_expect(403,api_call('PATCH',"admin/users/$other/status",['status'=>'rejected'],$c,api_key()),'Customer cannot execute an administrator account mutation');
api_expect(403,api_call('POST',"owner/stations/$station/services",['name_ar'=>'Attempt','name_en'=>'Attempt','price'=>'1.00'],$c,api_key()),'Customer cannot create merchant catalog entries');
api_expect(403,api_call('GET','admin/financial-reports',null,$o),'Station owner cannot read platform financial reports');

// Fault injection on the disposable runtime identity proves SQL details never escape a 500.
$runtime=clevo_env('TEST_RUNTIME_USERNAME');$dbName=clevo_env('DB_DATABASE');
if(!preg_match('/^clevo_rt_[a-f0-9]{12}$/D',$runtime??''))throw new RuntimeException('Unexpected test runtime identity.');
$conn->query("REVOKE SELECT ON `$dbName`.services FROM '$runtime'@'127.0.0.1'");
$fault=api_call('GET','stations');api_expect(500,$fault,'Unexpected SQL privilege failure is a structured internal error');
check(($fault[1]['error']['code']??'')==='internal_error'&&!str_contains($fault[2],'mysqli')&&!str_contains($fault[2],'SELECT')&&!str_contains($fault[2],$password),'500 response excludes SQL, exceptions and credentials');
$conn->query("GRANT SELECT ON `$dbName`.services TO '$runtime'@'127.0.0.1'");api_expect(200,api_call('GET','stations'),'Service recovers after isolated fault is removed');

// Persist only coverage/status evidence; never save request bodies, tokens, passwords or task grants.
file_put_contents(__DIR__.'/../.security-test/api-coverage.json',json_encode($coverage,JSON_PRETTY_PRINT));
echo 'API requests exercised: '.$calls.'; runtime registry operations: '.count(clevo_api_routes()).".\n";
finish_tests('Versioned REST API suite');
