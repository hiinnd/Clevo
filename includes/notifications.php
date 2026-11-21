<?php
declare(strict_types=1);

function clevo_notification_preferences(mysqli $db,int $user):array
{
    return clevo_stmt($db,'SELECT Language,PushEnabled,EmailEnabled FROM notification_preferences WHERE UserID=?','i',[$user])->get_result()->fetch_assoc()?:['Language'=>'ar','PushEnabled'=>1,'EmailEnabled'=>1];
}
function clevo_notification_device(mysqli $db,array $ctx,array $input):array
{
    $user=(int)$ctx['account']['UserID'];$token=$input['token'];
    return clevo_db_atomic($db,function()use($db,$ctx,$input,$user,$token):array{
        // A token is a delivery address, never login authority. Rebinding requires an
        // authenticated account, and all deliveries recheck that session before send.
        $hash=hash_hmac('sha256',$token,clevo_secret('APP_KEY'));
        $old=clevo_stmt($db,'SELECT DeviceID,UserID,SessionID FROM mobile_devices WHERE TokenHash=? FOR UPDATE','s',[$hash])->get_result()->fetch_assoc();
        if($old&&(int)$old['UserID']!==$user&&clevo_stmt($db,'SELECT SessionID FROM auth_sessions WHERE SessionID=? AND RevokedAt IS NULL AND ExpiresAt>UTC_TIMESTAMP()','s',[$old['SessionID']])->get_result()->fetch_assoc())throw new ClevoSecurityException('device_binding_conflict',409);
        $id=$old['DeviceID']??bin2hex(random_bytes(16));
        clevo_stmt($db,'INSERT INTO mobile_devices (DeviceID,UserID,SessionID,TokenHash,TokenCipher,Platform) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE UserID=VALUES(UserID),SessionID=VALUES(SessionID),TokenCipher=VALUES(TokenCipher),Platform=VALUES(Platform),RevokedAt=NULL,UpdatedAt=UTC_TIMESTAMP(6)','sissss',[$id,$user,$ctx['session']['SessionID'],$hash,clevo_encrypt_mail(['token'=>$token]),$input['platform']]);
        return ['device_id'=>$id];
    });
}
function clevo_notification_link(string $url,string $role):array
{
    if(preg_match('~^(?:order-details|booking-history|incoming-orders|driver-dashboard)\.php\?id=([1-9][0-9]{0,9})$~D',$url,$m))return ['screen'=>$role==='driver'?'driver_job':($role==='owner'?'owner_booking':'booking'),'entity_id'=>$m[1]];
    if($url==='customer-plans.php')return ['screen'=>'subscriptions','entity_id'=>''];
    if(preg_match('~^support.php\?ticket_id=([1-9][0-9]{0,9})$~D',$url,$m))return ['screen'=>'support','entity_id'=>$m[1]];
    return ['screen'=>'notifications','entity_id'=>''];
}
function clevo_notification_schedule(mysqli $db,int $id):void
{
    $n=clevo_stmt($db,'SELECT user_id FROM notifications WHERE id=?','i',[$id])->get_result()->fetch_assoc();
    if(!$n||!$n['user_id'])return; // Role broadcasts stay in-app; never fan out privileged details.
    $user=(int)$n['user_id'];
    foreach(clevo_stmt($db,'SELECT DeviceID FROM mobile_devices WHERE UserID=? AND RevokedAt IS NULL','i',[$user])->get_result()->fetch_all(MYSQLI_ASSOC) as $device){$key=hash('sha256',$id.':push:'.$device['DeviceID']);clevo_stmt($db,"INSERT IGNORE INTO notification_deliveries (NotificationID,UserID,DeviceID,Channel,DedupKey) VALUES (?,?,?,'push',?)",'iiss',[$id,$user,$device['DeviceID'],$key]);}
    clevo_stmt($db,"INSERT IGNORE INTO notification_deliveries (NotificationID,UserID,Channel,DedupKey) VALUES (?,?,'email',?)",'iis',[$id,$user,hash('sha256',$id.':email')]);
}
function clevo_notification_email(mysqli $db,array $n,array $account,string $language):array
{
    $ar=$language==='ar';$escape=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $title=$ar?$n['title']:($n['title_en']?:'CLEVO update');$body=$ar?$n['body']:($n['body_en']?:'Open CLEVO for details.');
    $details='';$link=clevo_notification_link($n['target_url']??'',clevo_role($account['Role']));
    if($link['entity_id']!==''){
        $b=clevo_stmt($db,'SELECT b.*,s.UserID OwnerID FROM booking b JOIN stations s ON s.StationID=b.StationID WHERE b.BookingID=? AND (b.UserID=? OR s.UserID=?)','iii',[(int)$link['entity_id'],(int)$account['UserID'],(int)$account['UserID']])->get_result()->fetch_assoc();
        if($b){$details='<p>'.$escape($b['Order_Number']).' · '.$escape($b['Booking_Date']).' '.$escape($b['Booking_Time']).'</p><ul>';
            foreach(clevo_stmt($db,'SELECT ServiceName,Price FROM booking_services WHERE BookingID=? ORDER BY BookingServiceID','i',[(int)$b['BookingID']])->get_result()->fetch_all(MYSQLI_ASSOC) as $service)$details.='<li>'.$escape($service['ServiceName']).' — '.$escape($service['Price']).' JOD</li>';
            $details.='</ul><p>'.($ar?'رسوم إضافية: ':'Additional fees: ').$escape($b['AdditionalFee']).' JOD<br>'.($ar?'الإجمالي بعد الخصم: ':'Total after discount: ').$escape(clevoBookingPayableAmount($b)).' JOD</p>';
        }
    }
    $home=clevo_public_url('general/login.php');
    $html='<!doctype html><html lang="'.($ar?'ar':'en').'" dir="'.($ar?'rtl':'ltr').'"><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0;background:#f0f9ff;font-family:Arial,sans-serif;color:#0f172a"><table role="presentation" width="100%"><tr><td align="center" style="padding:20px 12px"><table role="presentation" width="100%" style="max-width:600px;background:white;border-radius:20px"><tr><td style="padding:28px"><h1 style="color:#0284c7">CLEVO</h1><h2>'.$escape($title).'</h2><p>'.$escape($body).'</p>'.$details.'<p><a style="display:inline-block;padding:14px 24px;background:#0284c7;color:white;border-radius:24px" href="'.$escape($home).'">'.($ar?'فتح CLEVO':'Open CLEVO').'</a></p><p style="font-size:12px;color:#475569">'.($ar?'راجع التطبيق للحالة الحالية. لا تشارك رموز التأكيد أو بيانات الدخول.':'Open the app for current status. Never share login credentials.').'</p></td></tr></table></td></tr></table></body></html>';
    return ['to'=>$account['Email'],'name'=>$account['UserName'],'subject'=>$title,'html'=>$html];
}
final class ClevoDeliveryRetry extends RuntimeException
{
    public function __construct(public int $delay){parent::__construct('fcm_retry');}
}
final class ClevoFcmSender
{
    private ?string $access=null;private int $expires=0;
    public function __construct(private array $account,private ?Closure $transport=null){}
    private function request(string $url,array|string $body,array $headers):array
    {
        if($this->transport)return ($this->transport)($url,$body,$headers);
        $buffer='';$retry=60;$h=curl_init($url);curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>is_array($body)?json_encode($body,JSON_THROW_ON_ERROR):$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HEADERFUNCTION=>static function($h,$line)use(&$retry){if(stripos($line,'Retry-After:')===0){$v=trim(substr($line,12));$retry=max(60,min(86400,ctype_digit($v)?(int)$v:((strtotime($v)?:time()+60)-time())));}return strlen($line);},CURLOPT_WRITEFUNCTION=>static function($h,$chunk)use(&$buffer){if(strlen($buffer)+strlen($chunk)>65536)return 0;$buffer.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);if($ok===false)throw new RuntimeException('transport_unknown');return ['status'=>$status,'body'=>json_decode($buffer,true)??[],'retry_after'=>$retry];
    }
    public function send(string $token,array $message):void
    {
        $project=$this->account['project_id']??'';if(!preg_match('/^[a-z][a-z0-9-]{4,62}$/D',$project))throw new RuntimeException('fcm_configuration');
        if(!$this->access||$this->expires<time()+60){
            $encode=static fn($value)=>rtrim(strtr(base64_encode(json_encode($value,JSON_THROW_ON_ERROR)),'+/','-_'),'=');
            $unsigned=$encode(['alg'=>'RS256','typ'=>'JWT']).'.'.$encode(['iss'=>$this->account['client_email'],'scope'=>'https://www.googleapis.com/auth/firebase.messaging','aud'=>'https://oauth2.googleapis.com/token','iat'=>time(),'exp'=>time()+3600]);
            if(!openssl_sign($unsigned,$signature,$this->account['private_key'],OPENSSL_ALGO_SHA256))throw new RuntimeException('fcm_configuration');
            $jwt=$unsigned.'.'.rtrim(strtr(base64_encode($signature),'+/','-_'),'=');
            $r=$this->request('https://oauth2.googleapis.com/token',http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt]),['Content-Type: application/x-www-form-urlencoded']);
            if($r['status']!==200||!is_string($r['body']['access_token']??null))throw new RuntimeException('fcm_oauth_failed');$this->access=$r['body']['access_token'];$this->expires=time()+min(3600,(int)($r['body']['expires_in']??300));
        }
        $r=$this->request('https://fcm.googleapis.com/v1/projects/'.$project.'/messages:send',['message'=>['token'=>$token]+$message],['Content-Type: application/json','Authorization: Bearer '.$this->access]);
        if($r['status']===200&&isset($r['body']['name']))return;
        foreach($r['body']['error']['details']??[] as $detail)if(($detail['errorCode']??'')==='UNREGISTERED')throw new RuntimeException('fcm_unregistered');
        if(in_array($r['status'],[429,503],true))throw new ClevoDeliveryRetry((int)($r['retry_after']??60));
        throw new RuntimeException(in_array($r['status'],[401,403],true)?'fcm_configuration':(in_array($r['status'],[400,404],true)?'fcm_payload_rejected':'fcm_retry'));
    }
}
function clevo_fcm_sender():ClevoFcmSender
{
    static $sender;
    if(!clevo_secure_environment()||clevo_env('NOTIFICATION_EXTERNAL_SEND','0')!=='1'||clevo_env('FCM_ENABLED','0')!=='1'||clevo_env('ERASURE_WORKER_FENCE','0')==='1')throw new RuntimeException('external_delivery_disabled');
    if($sender instanceof ClevoFcmSender)return $sender;
    $file=realpath(clevo_env('FCM_SERVICE_ACCOUNT_FILE','')??'');$root=realpath(dirname(__DIR__));
    if(!$file||!is_file($file)||clevo_path_within($file,$root))throw new RuntimeException('fcm_configuration');
    $account=json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);if(($account['type']??'')!=='service_account'||!hash_equals(clevo_env('FCM_EXPECTED_PROJECT_ID','')??'',(string)($account['project_id']??''))||empty($account['project_id']))throw new RuntimeException('fcm_configuration');return $sender=new ClevoFcmSender($account);
}
function clevo_notification_worker(mysqli $db,?Closure $push=null,?Closure $mail=null,int $limit=50):array
{
    clevo_stmt($db,'UPDATE mobile_devices SET RevokedAt=UTC_TIMESTAMP(6),TokenCipher=NULL WHERE RevokedAt IS NULL AND UpdatedAt<UTC_TIMESTAMP()-INTERVAL 30 DAY');
    clevo_stmt($db,"UPDATE notification_deliveries SET State='dead',LastError='attempts_exhausted',Claim=NULL,LeaseUntil=NULL WHERE State='sending' AND LeaseUntil<UTC_TIMESTAMP(6) AND Attempts>=5");
    $counts=['sent'=>0,'skipped'=>0,'failed'=>0];
    for($i=0;$i<max(1,min(200,$limit));$i++){
        $claim=bin2hex(random_bytes(16));$row=clevo_db_atomic($db,function()use($db,$claim){
            $r=clevo_stmt($db,"SELECT * FROM notification_deliveries WHERE ((State='queued' AND AvailableAt<=UTC_TIMESTAMP(6)) OR (State='sending' AND LeaseUntil<UTC_TIMESTAMP(6))) AND Attempts<5 ORDER BY DeliveryID LIMIT 1 FOR UPDATE")->get_result()->fetch_assoc();if(!$r)return null;
            clevo_stmt($db,"UPDATE notification_deliveries SET State='sending',Attempts=Attempts+1,Claim=?,LeaseUntil=UTC_TIMESTAMP(6)+INTERVAL 2 MINUTE WHERE DeliveryID=?",'si',[$claim,(int)$r['DeliveryID']]);return $r;
        });if(!$row)break;
        $id=(int)$row['DeliveryID'];$state='sent';$error=null;$delay=min(3600,60*(2**(int)$row['Attempts']));
        try{
            $n=clevo_stmt($db,'SELECT * FROM notifications WHERE id=? AND user_id=?','ii',[(int)$row['NotificationID'],(int)$row['UserID']])->get_result()->fetch_assoc();$a=clevo_account($db,(int)$row['UserID']);$prefs=clevo_notification_preferences($db,(int)$row['UserID']);
            if(!$n||!$a||strtotime($n['created_at'].' UTC')<time()-86400||!clevo_account_active($db,$a)||(int)$prefs[$row['Channel']==='push'?'PushEnabled':'EmailEnabled']!==1)$state='skipped';
            elseif($row['Channel']==='push'){
                $device=clevo_stmt($db,'SELECT d.* FROM mobile_devices d JOIN auth_sessions s ON s.SessionID=d.SessionID AND s.UserID=d.UserID WHERE d.DeviceID=? AND d.UserID=? AND d.RevokedAt IS NULL AND d.UpdatedAt>UTC_TIMESTAMP()-INTERVAL 30 DAY AND s.RevokedAt IS NULL AND s.ExpiresAt>UTC_TIMESTAMP() AND s.AuthVersion=?','sii',[$row['DeviceID'],(int)$row['UserID'],(int)$a['AuthVersion']])->get_result()->fetch_assoc();
                if(!$device)$state='skipped';else{
                    $link=clevo_notification_link($n['target_url']??'',clevo_role($a['Role']));$message=['notification'=>['title'=>'CLEVO','body'=>$prefs['Language']==='ar'?'لديك تحديث جديد. افتح التطبيق للاطلاع عليه.':'You have an update. Open CLEVO for details.'],'data'=>['notification_id'=>(string)$n['id'],'event_id'=>$row['DedupKey']]+$link,'android'=>['ttl'=>'3600s','notification'=>['tag'=>$row['DedupKey']]],'apns'=>['headers'=>['apns-push-type'=>'alert','apns-priority'=>'10','apns-collapse-id'=>substr($row['DedupKey'],0,64),'apns-expiration'=>(string)(time()+3600)],'payload'=>['aps'=>['sound'=>'default']]]];
                    $token=clevo_decrypt_mail($device['TokenCipher'])['token'];if($push)$push($token,$message);else clevo_fcm_sender()->send($token,$message);
                }
            }elseif(!$a['EmailVerifiedAt']||!filter_var($a['Email'],FILTER_VALIDATE_EMAIL))$state='skipped';else{
                $payload=clevo_notification_email($db,$n,$a,$prefs['Language']);$payload['message_id']=clevo_mail_message_id($row['DedupKey']);
                if($mail)$mail($payload);else{if(!clevo_production()||clevo_env('NOTIFICATION_EXTERNAL_SEND','0')!=='1'||clevo_env('MAIL_TRANSPORT')!=='smtp')throw new RuntimeException('external_delivery_disabled');clevo_deliver_mail($payload);}
            }
        }catch(Throwable $e){$state=((int)$row['Attempts']>=4||in_array($e->getMessage(),['fcm_unregistered','fcm_payload_rejected'],true))?'dead':'queued';$error=in_array($e->getMessage(),['external_delivery_disabled','fcm_unregistered','fcm_configuration','fcm_payload_rejected','fcm_oauth_failed'],true)?$e->getMessage():'transport_unknown';
            if($e instanceof ClevoDeliveryRetry)$delay=max($delay,$e->delay);
            if($error==='fcm_unregistered')clevo_stmt($db,'UPDATE mobile_devices SET RevokedAt=UTC_TIMESTAMP(6),TokenCipher=NULL WHERE DeviceID=? AND UserID=?','si',[$row['DeviceID'],(int)$row['UserID']]);
        }
        clevo_stmt($db,"UPDATE notification_deliveries SET State=?,LastError=?,Claim=NULL,LeaseUntil=NULL,SentAt=IF(?='sent',UTC_TIMESTAMP(6),NULL),AvailableAt=UTC_TIMESTAMP(6)+INTERVAL ? SECOND WHERE DeliveryID=? AND Claim=?",'sssiis',[$state,$error,$state,$delay, $id,$claim]);
        $counts[$state==='sent'?'sent':($state==='skipped'?'skipped':'failed')]++;
    }return $counts;
}
