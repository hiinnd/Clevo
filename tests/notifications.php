<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
if(clevo_env('APP_ENV')!=='testing'||!preg_match('/^clevo_security_test_[a-f0-9]{12}$/D',clevo_env('DB_DATABASE','')??''))exit(2);
$db=clevo_connect_database();if(strcasecmp(realpath($db->query('SELECT @@datadir d')->fetch_assoc()['d'])?:'',realpath(__DIR__.'/../.security-test/mysql')?:'!')!==0)exit(2);
$db->query('DELETE FROM security_rate_limits');
$hash=password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT);
clevo_stmt($db,"INSERT INTO user (UserName,Email,PhoneNumber,Password,Role,AccountStatus,EmailVerifiedAt) VALUES ('Notification test','notifications@example.test','0799998877',?,'customer','active',UTC_TIMESTAMP())",'s',[$hash]);$user=(int)$db->insert_id;$account=clevo_account($db,$user);$session=clevo_auth_session($db,$account);$ctx=['account'=>$account,'session'=>$session];
$token='fixture:'.bin2hex(random_bytes(48));$device=clevo_notification_device($db,$ctx,['token'=>$token,'platform'=>'ios']);
check(clevo_notification_device($db,$ctx,['token'=>$token,'platform'=>'ios'])===$device,'Token registration deduplicates a device');
$row=clevo_stmt($db,'SELECT * FROM mobile_devices WHERE DeviceID=?','s',[$device['device_id']])->get_result()->fetch_assoc();check($row['TokenCipher']!==$token&&clevo_decrypt_mail($row['TokenCipher'])['token']===$token,'Device token stored encrypted and never in its identifier');
$before=(int)$db->query('SELECT COUNT(*) n FROM notifications')->fetch_assoc()['n'];
try{clevo_db_atomic($db,function()use($db,$user){clevoCreateNotification($db,$user,'تجربة','تجربة','info',null,'Test','Test');throw new RuntimeException('rollback');});}catch(RuntimeException $e){}
check((int)$db->query('SELECT COUNT(*) n FROM notifications')->fetch_assoc()['n']===$before,'Rolled back business event creates no inbox or outbound delivery');
clevoCreateNotification($db,$user,'تحديث الحجز','التفاصيل في التطبيق','info','order-details.php?id=123','Booking update','Open the app for details');
$id=(int)$db->query('SELECT MAX(id) n FROM notifications')->fetch_assoc()['n'];clevo_notification_schedule($db,$id);clevo_notification_schedule($db,$id);
check((int)clevo_stmt($db,'SELECT COUNT(*) n FROM notification_deliveries WHERE NotificationID=?','i',[$id])->get_result()->fetch_assoc()['n']===2,'One event schedules one email and one device delivery despite retries');
// Isolate these deliveries from the pre-existing regression fixtures without altering history.
$db->query("UPDATE notification_deliveries SET AvailableAt=UTC_TIMESTAMP()+INTERVAL 1 DAY WHERE NotificationID<>$id");
$pushes=[];$emails=[];$push=function($t,$m)use(&$pushes,$token){check($t===$token,'Only the registered device is targeted');check(!str_contains(json_encode($m),'التفاصيل')&&!isset($m['data']['token']),'Push carries no business body, credential or device token');check($m['data']['screen']==='booking'&&$m['data']['entity_id']==='123','Push route is an allowlisted structured deep link');$pushes[]=$m;};
$mail=function($p)use(&$emails){check(str_contains($p['html'],'max-width:600px')&&str_contains($p['html'],'dir="rtl"'),'Arabic branded HTML is responsive and right-to-left');check(isset($p['message_id']),'Email has stable retry message identity');$emails[]=$p;};
$counts=clevo_notification_worker($db,$push,$mail);check($counts['sent']===2&&count($pushes)===1&&count($emails)===1,'Actual worker processes both channels through isolated transports');
check(clevo_notification_worker($db,$push,$mail)['sent']===0,'A second worker run cannot resend acknowledged deliveries');
clevo_stmt($db,"INSERT INTO notification_preferences VALUES (?,'en',0,0)",'i',[$user]);
clevoCreateNotification($db,$user,'اختبار','اختبار','info',null,'Test','Test');$next=(int)$db->query('SELECT MAX(id) n FROM notifications')->fetch_assoc()['n'];
$counts=clevo_notification_worker($db,static function(){throw new RuntimeException('should_not_send');},static function(){throw new RuntimeException('should_not_send');});check($counts['skipped']===2,'Channel opt-out is checked at delivery time');
clevo_stmt($db,'UPDATE notification_preferences SET PushEnabled=1,EmailEnabled=1 WHERE UserID=?','i',[$user]);
clevoCreateNotification($db,$user,'اختبار','اختبار','info',null,'Test','Test');$retry=(int)$db->query('SELECT MAX(id) n FROM notifications')->fetch_assoc()['n'];
$counts=clevo_notification_worker($db,static function(){throw new RuntimeException('temporary');},static function(){throw new RuntimeException('temporary');});check($counts['failed']===2,'Transient transport failure preserves retriable records');
check((int)clevo_stmt($db,"SELECT COUNT(*) n FROM notification_deliveries WHERE NotificationID=? AND State='queued' AND Attempts=1 AND LastError='transport_unknown' AND AvailableAt>UTC_TIMESTAMP()",'i',[$retry])->get_result()->fetch_assoc()['n']===2,'Retries back off and persist sanitized error codes');
clevo_stmt($db,'UPDATE auth_sessions SET RevokedAt=UTC_TIMESTAMP() WHERE SessionID=?','s',[$session['SessionID']]);
clevoCreateNotification($db,$user,'اختبار','اختبار','info',null,'Test','Test');
$counts=clevo_notification_worker($db,static function(){throw new RuntimeException('should_not_send');},static function(){});check($counts['skipped']>=1,'Logout/revocation prevents subsequent push to that session');
rejects(fn()=>clevo_fcm_sender(),'FCM refuses missing approval/configuration');
check(clevo_notification_link('https://evil.example/?token=secret','customer')['screen']==='notifications','Untrusted target URLs cannot create external deep links');
$html=clevo_notification_email($db,['title'=>'<script>','body'=>'<img src=x onerror=alert(1)>','title_en'=>'<script>','body_en'=>'<img>','target_url'=>''], $account,'en')['html'];check(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;'),'Email templates escape untrusted notification text');
// Real OAuth signing + HTTP-v1 serialization exercised without any network or real key.
$options=['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA,'config'=>dirname(PHP_BINARY).'/extras/ssl/openssl.cnf'];$key=openssl_pkey_new($options);openssl_pkey_export($key,$private,null,$options);$calls=0;
$sender=new ClevoFcmSender(['project_id'=>'clevo-fixture','client_email'=>'sender@fixture.invalid','private_key'=>$private],function($url,$body,$headers)use(&$calls){$calls++;if(str_contains($url,'oauth2'))return ['status'=>200,'body'=>['access_token'=>'fixture-access','expires_in'=>3600]];check(isset($body['message']['token'])&&str_starts_with($url,'https://fcm.googleapis.com/v1/projects/'),'HTTP v1 sends the typed message to the fixed Google host');return ['status'=>200,'body'=>['name'=>'projects/fixture/messages/1']];});
$sender->send($token,['data'=>['event_id'=>'fixture']]);$sender->send($token,['data'=>['event_id'=>'fixture2']]);check($calls===3,'OAuth token is reused within its safe lifetime');
$bearer=clevo_token_pair($db,clevo_account($db,$user))['access_token'];
$request=static function(string $method,string $path,?array $body=null,?string $token=null):array{
    $h=curl_init(rtrim(clevo_env('APP_URL'),'/').'/api/v1/'.$path);$headers=['Content-Type: application/json'];if($token)$headers[]='Authorization: Bearer '.$token;
    curl_setopt_array($h,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>$headers]);if($body!==null)curl_setopt($h,CURLOPT_POSTFIELDS,json_encode($body));$text=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);return [$status,json_decode($text,true)];
};
[$status]=$request('POST','me/notification-devices',['token'=>'test:'.bin2hex(random_bytes(24)),'platform'=>'android']);check($status===401,'Anonymous registration cannot bind devices');
[$status,$response]=$request('POST','me/notification-devices',['token'=>'test:'.bin2hex(random_bytes(24)),'platform'=>'android'],$bearer);check($status===200&&isset($response['data']['device_id']),'Authenticated device API returns an opaque identifier only');$registered=$response['data']['device_id']??'';
[$status]=$request('PUT','me/notification-preferences',['language'=>'en','push_enabled'=>false,'email_enabled'=>false],$bearer);check($status===200,'Notification preference API persists opt-out');
[$status,$response]=$request('GET','me/notification-preferences',null,$bearer);check($status===200&&(int)$response['data']['preferences']['PushEnabled']===0,'Preferences are account scoped and readable');
[$status,$response]=$request('GET','notifications',null,$bearer);check($status===200&&isset($response['data']['notifications'][0]['route']),'Notification history includes validated Flutter route data');
[$status]=$request('GET','admin/notification-deliveries',null,$bearer);check($status===403,'Customers cannot inspect global delivery monitoring');
[$status]=$request('POST','me/notification-devices/revoke',['device_id'=>$registered],$bearer);check($status===200,'Device revocation API is idempotent and scoped');
echo "No SMTP, Firebase or APNs message was sent; all transports were local closures.\n";finish_tests('Notification service suite');
