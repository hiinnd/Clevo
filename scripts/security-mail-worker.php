<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('CLEVO_NOTIFICATION_WORKER',true);
require_once __DIR__ . '/../includes/config.php';
if(clevo_env('ERASURE_WORKER_FENCE','0')==='1'){echo "Privacy erasure fence active; outbound worker stopped.\n";exit;}
if(!clevo_secure_environment()||clevo_env('NOTIFICATION_EXTERNAL_SEND','0')!=='1'||clevo_env('MAIL_TRANSPORT')!=='smtp'){echo "External SMTP disabled; queued messages retained.\n";exit;}
for($n=0;$n<50;$n++){
    $claim=bin2hex(random_bytes(16));
    $row=clevo_db_atomic($conn,function()use($conn,$claim){
        $row=clevo_stmt($conn,'SELECT * FROM security_mail_queue WHERE SentAt IS NULL AND Attempts<5 AND AvailableAt<=UTC_TIMESTAMP() AND (LeaseUntil IS NULL OR LeaseUntil<UTC_TIMESTAMP()) ORDER BY ID LIMIT 1 FOR UPDATE')->get_result()->fetch_assoc();
        if(!$row)return null;
        clevo_stmt($conn,'UPDATE security_mail_queue SET Claim=?,LeaseUntil=UTC_TIMESTAMP(6)+INTERVAL 2 MINUTE,Attempts=Attempts+1 WHERE ID=?','si',[$claim,(int)$row['ID']]);return $row;
    });if(!$row)break;
    try{
        $payload=clevo_decrypt_mail($row['PayloadCipher']);$payload['message_id']=clevo_mail_message_id('security:'.hash_hmac('sha256',(string)$row['ID'],clevo_secret('APP_KEY')));
        if(!isset($payload['expires_at'])||(int)$payload['expires_at']<=time()){
            clevo_stmt($conn,"UPDATE security_mail_queue SET Attempts=5,PayloadCipher='',LastError='expired_or_legacy_review',Claim=NULL,LeaseUntil=NULL WHERE ID=? AND Claim=?",'is',[(int)$row['ID'],$claim]);continue;
        }
        clevo_deliver_mail($payload);
        clevo_stmt($conn,"UPDATE security_mail_queue SET SentAt=UTC_TIMESTAMP(),PayloadCipher='',Claim=NULL,LeaseUntil=NULL,LastError=NULL WHERE ID=? AND Claim=?",'is',[(int)$row['ID'],$claim]);
    }catch(Throwable $e){clevo_stmt($conn,"UPDATE security_mail_queue SET Claim=NULL,LeaseUntil=NULL,LastError='smtp_unknown',AvailableAt=UTC_TIMESTAMP()+INTERVAL 5 MINUTE WHERE ID=? AND Claim=?",'is',[(int)$row['ID'],$claim]);}
}
// Preserve used refresh records until family expiry so replays remain detectable.
$conn->query('DELETE FROM security_rate_limits WHERE ExpiresAt < UNIX_TIMESTAMP() - 86400');
$conn->query('DELETE FROM auth_sessions WHERE ExpiresAt < UTC_TIMESTAMP() - INTERVAL 1 DAY');
$conn->query('DELETE FROM auth_challenges WHERE ExpiresAt < UTC_TIMESTAMP() - INTERVAL 1 DAY');
