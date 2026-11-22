<?php
declare(strict_types=1);
require __DIR__.'/admin-helpers.php';
require_once __DIR__.'/../includes/privacy.php';
require_once __DIR__.'/../includes/operations.php';
$runtimeFile=__DIR__.'/../.security-test/testing-runtime.env';$runtimeOriginal=file_get_contents($runtimeFile);$environmentOriginal=$_ENV;
function op_set(string $key,string $value,bool $http=false):void{
    global $runtimeFile;$_ENV[$key]=$value;
    if($http){$file=file_get_contents($runtimeFile);$file=preg_replace('/^'.preg_quote($key,'/').'=[^\r\n]*\r?\n?/m','',$file);file_put_contents($runtimeFile,rtrim($file)."\n".$key.'='.$value."\n");}
}
function op_command(array $args):array{
    $p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out,$err];
}
try{
    $pw='ProductionTest!'.ad_key();$customer=ad_actor('customer',$pw);$other=ad_actor('customer',$pw);$admin=ad_actor('Admin',$pw);$owner=ad_actor('owner',$pw);$driver=ad_actor('driver',$pw);
    $ct=clevo_token_pair($db,clevo_account($db,$customer))['access_token'];$ot=clevo_token_pair($db,clevo_account($db,$other))['access_token'];$at=clevo_token_pair($db,clevo_account($db,$admin))['access_token'];
    ad_expect(200,ad_http('GET','/health/live.php'),'Public liveness works without authentication');
    ad_expect(401,ad_http('GET','/health/ready.php'),'Readiness requires a separate bearer');
    ad_expect(401,ad_http('GET','/health/metrics.php?token='.ad_key()),'Query token is not accepted');
    ad_expect(401,ad_http('GET','/health/ready.php',null,null,$ct),'Application JWT is not a health credential');
    $health=clevo_env('OPS_HEALTH_TOKEN');$ready=ad_http('GET','/health/ready.php',null,null,$health);
    ad_expect(200,$ready,'Readiness verifies migrated DB and writable private storage');
    check(array_keys($ready[1])===['status'],'Public readiness body contains no diagnostics or secrets');
    check(str_contains($ready[3],'no-store'),'Health responses disable caching');
    $metrics=ad_http('GET','/health/metrics.php',null,null,$health);ad_expect(200,$metrics,'Authenticated metrics work');check(str_contains($metrics[2],'clevo_delivery_queue_due'),'Queue counters exported');
    op_set('MAINTENANCE_MODE','1',true);ad_expect(503,ad_http('GET','/health/ready.php',null,null,$health),'Maintenance removes instance from readiness');
    ad_expect(503,ad_http('GET','/api/v1/legal'),'Maintenance blocks normal application traffic');op_set('MAINTENANCE_MODE','0',true);
    op_set('OPS_REQUIRE_WORKERS','1');
    foreach(clevo_operations_jobs() as $job=>$definition)clevo_operations_heartbeat($job,['last_success_unix'=>time(),'exit_code'=>0]);
    check(clevo_operations_health()['checks']['workers'],'Fresh successful workers are healthy');
    clevo_operations_heartbeat('notification-delivery',['exit_code'=>-1,'started_unix'=>time()]);check(clevo_operations_health()['checks']['workers'],'Running worker with recent success stays healthy');
    clevo_operations_heartbeat('notification-delivery',['exit_code'=>1]);check(!clevo_operations_health()['checks']['workers'],'Failed worker is unhealthy');
    clevo_operations_heartbeat('notification-delivery',['exit_code'=>0,'last_success_unix'=>time()-1000]);check(!clevo_operations_health()['checks']['workers'],'Stale worker is unhealthy');op_set('OPS_REQUIRE_WORKERS','0');
    op_set('OPS_REQUIRE_BACKUP','1');$ops=rtrim(clevo_env('PRIVATE_STORAGE_PATH'),'/\\').'/ops';
    file_put_contents($ops.'/backup.json',json_encode(['last_success_unix'=>time()-100000]));check(!clevo_operations_health()['checks']['backup'],'Stale backup fails readiness');
    file_put_contents($ops.'/backup.json',json_encode(['last_success_unix'=>time()]));check(clevo_operations_health()['checks']['backup'],'Fresh encrypted-backup marker is healthy');op_set('OPS_REQUIRE_BACKUP','0');
    [$code]=op_command([PHP_BINARY,__DIR__.'/../scripts/run-job.php','not-a-command']);check($code!==0,'Worker runner rejects unlisted commands');
    [$code,$result]=op_command([PHP_BINARY,__DIR__.'/../scripts/run-job.php','booking-expiry']);check($code===0&&(json_decode($result,true)['exit_code']??-1)===0,'Allowlisted job runs and records successful completion');
    ad_expect(503,ad_http('GET','/general/privacy.php?lang=en'),'Unapproved privacy text is explicitly draft');
    ad_expect(503,ad_http('GET','/general/terms.php'),'Unapproved terms are explicitly draft');
    ad_expect(200,ad_http('GET','/general/delete-account.php'),'Deletion web entry is available without installing app');
    ad_expect(403,ad_http('POST','/general/delete-account.php',['login'=>'unknown@example.test','password'=>$pw,'confirm'=>'yes']),'Web erasure rejects missing CSRF');
    ad_expect(302,ad_http('GET','/admin/privacy-requests.php'),'Admin privacy page requires web login');
    [$adminJar]=ad_login($admin,$pw);ad_expect(200,ad_http('GET','/admin/privacy-requests.php',null,$adminJar),'Admin privacy review page renders from real database');
    ad_expect(403,ad_http('POST','/admin/privacy-requests.php',['request_id'=>'1','password'=>$pw],$adminJar),'Admin erasure rejects missing CSRF');
    ad_expect(401,ad_http('POST','/api/v1/me/account-deletion-request',['password'=>$pw,'confirm'=>true]),'Anonymous API cannot request deletion');
    ad_expect(401,ad_http('POST','/api/v1/me/account-deletion-request',['password'=>'incorrect','confirm'=>true],null,$ct),'Deletion requires password reauthentication');
    ad_expect(422,ad_http('POST','/api/v1/me/account-deletion-request',['password'=>$pw,'confirm'=>false],null,$ct),'Deletion requires explicit confirmation');
    ad_expect(422,ad_http('POST','/api/v1/me/account-deletion-request',['password'=>$pw,'confirm'=>true,'user_id'=>$other],null,$ct),'Client cannot select another identity');
    $request=ad_expect(202,ad_http('POST','/api/v1/me/account-deletion-request',['password'=>$pw,'confirm'=>true],null,$ct),'Customer requests deletion through real API')['request'];$requestID=(int)$request['RequestID'];
    $again=clevo_privacy_request($db,$customer,$pw);check((int)$again['RequestID']===$requestID,'Repeated requests reuse one record');
    $own=ad_expect(200,ad_http('GET','/api/v1/me/account-deletion-request',null,null,$ot),'Other user can only query self');check($own['request']===null,'Other identity cannot read this request');
    ad_expect(403,ad_http('GET','/api/v1/admin/account-deletion-requests',null,null,$ct),'Customer cannot read admin queue');
    ad_expect(200,ad_http('GET','/api/v1/admin/account-deletion-requests',null,null,$at),'Admin can review queue');
    clevo_privacy_request($db,$customer,$pw,true);check(clevo_privacy_read($db,$customer)['State']==='cancelled','Pending request can be cancelled');
    clevo_privacy_request($db,$customer,$pw);
    $review=['password'=>$pw,'review_reference'=>'TEST/REVIEW','retention_reviewed'=>true,'external_cleanup_reviewed'=>true];
    rejects(fn()=>clevo_privacy_fulfill($db,$admin,$requestID,$review),'Erasure refuses unapproved policy','privacy_policy_not_approved');
    foreach(['LEGAL_POLICY_APPROVED'=>'1','LEGAL_OPERATOR_NAME'=>'Synthetic operator','PRIVACY_CONTACT_EMAIL'=>'privacy@example.test','LEGAL_POLICY_VERSION'=>'test-v1','ERASURE_RETENTION_REVIEW_APPROVED'=>'1','RETENTION_DESCRIPTION_AR'=>'Test only','RETENTION_DESCRIPTION_EN'=>'Test only'] as $k=>$v)op_set($k,$v);
    rejects(fn()=>clevo_privacy_fulfill($db,$admin,$requestID,$review),'Erasure requires drained outbound workers','outbound_workers_must_be_drained');op_set('ERASURE_WORKER_FENCE','1');
    rejects(fn()=>clevo_privacy_fulfill($db,$customer,$requestID,$review),'Non-admin cannot fulfill requests','forbidden');
    rejects(fn()=>clevo_privacy_fulfill($db,$admin,$requestID,array_replace($review,['password'=>'wrong'])),'Administrator must reauthenticate','reauthentication_failed');
    $station=ad_station($owner);$service=clevo_market_service($db,$owner,$station,0,['name_ar'=>'Test','name_en'=>'Test','price'=>'10.00','duration'=>30,'is_station'=>true]);$booking=ad_booking($customer,$station,$service);
    rejects(fn()=>clevo_privacy_fulfill($db,$admin,$requestID,$review),'Unresolved booking blocks erasure','active_work_requires_resolution');
    check(clevoCancelBookingForUser($db,$booking,$customer)['status']==='success','Booking obligations can be resolved through shared cancellation service');
    $profiles=rtrim(clevo_env('PRIVATE_STORAGE_PATH'),'/\\').'/profiles';if(!is_dir($profiles))mkdir($profiles,0700,true);$name=ad_key().'.png';file_put_contents($profiles.'/'.$name,'synthetic private artifact');
    clevo_stmt($db,'UPDATE user SET ProfilePic=? WHERE UserID=?','si',['private:profiles:'.$name,$customer]);
    clevo_stmt($db,'INSERT INTO tracking_records (BookingID,DriverID,Latitude,Longitude) VALUES (?,?,31.9,35.9)','ii',[$booking,$driver]);
    clevo_stmt($db,'UPDATE booking SET Driver_Lat=31.9,Driver_Lon=35.9 WHERE BookingID=?','i',[$booking]);
    $journals=(int)$db->query('SELECT COUNT(*) n FROM financial_journals')->fetch_assoc()['n'];
    $limited=new mysqli('127.0.0.1',clevo_env('TEST_RUNTIME_USERNAME'),clevo_env('TEST_RUNTIME_PASSWORD'),clevo_env('DB_DATABASE'),(int)clevo_env('DB_PORT'));$limited->set_charset('utf8mb4');$limited->query("SET time_zone='+00:00'");
    $erased=clevo_privacy_fulfill($limited,$admin,$requestID,$review);check($erased['state']==='processing','Web runtime can redact without GPS DELETE privilege');
    check(is_file($profiles.'/'.$name),'Profile cleanup is durably deferred');check(clevo_authenticated_token($db,$ct)===null,'Erasure revokes access tokens');
    $record=clevo_account($db,$customer);check($record['Email']===null&&$record['AccountStatus']==='rejected','Erasure removes account identity and access');
    check(clevo_stmt($db,'SELECT Driver_Lat FROM booking WHERE BookingID=?','i',[$booking])->get_result()->fetch_assoc()['Driver_Lat']===null,'Legacy driver location cache is cleared');
    $privacyUser='clevo_pv_'.bin2hex(random_bytes(6));$privacyPassword=ad_key();$database=clevo_env('DB_DATABASE');
    $db->query("CREATE USER '$privacyUser'@'127.0.0.1' IDENTIFIED BY '$privacyPassword'");
    foreach(['account_deletion_requests'=>'SELECT, UPDATE','account_erasure_files'=>'SELECT, UPDATE','user'=>'SELECT','booking'=>'SELECT','tracking_records'=>'SELECT, DELETE','security_schema_migrations'=>'SELECT'] as $table=>$grants)$db->query("GRANT $grants ON `$database`.`$table` TO '$privacyUser'@'127.0.0.1'");
    $privacyWorker=new mysqli('127.0.0.1',$privacyUser,$privacyPassword,$database,(int)clevo_env('DB_PORT'));$privacyWorker->set_charset('utf8mb4');$privacyWorker->query("SET time_zone='+00:00'");
    rejects(fn()=>$privacyWorker->query('DELETE FROM financial_journals WHERE JournalID=0'),'Privacy worker cannot delete financial evidence');
    rejects(fn()=>$privacyWorker->query("UPDATE audit_logs SET Action='changed' WHERE AuditID=0"),'Privacy worker cannot rewrite audit history');
    clevo_privacy_cleanup_files($privacyWorker);check(!is_file($profiles.'/'.$name),'Restricted reviewed worker removes private file');
    check((int)clevo_stmt($db,'SELECT COUNT(*) n FROM tracking_records WHERE BookingID=?','i',[$booking])->get_result()->fetch_assoc()['n']===0,'Dedicated worker removes account GPS');
    check(clevo_privacy_read($db,$customer)['State']==='completed','Completion follows location and file cleanup');
    check((int)$db->query('SELECT COUNT(*) n FROM financial_journals')->fetch_assoc()['n']===$journals,'Financial evidence is preserved');
    clevo_privacy_cleanup_files($db);check(clevo_privacy_read($db,$customer)['State']==='completed','Cleanup retries safely');
    rejects(fn()=>clevo_privacy_request($db,$customer,$pw,true),'Erased account cannot reactivate old password','reauthentication_failed');
    $backupDir=rtrim(clevo_env('PRIVATE_STORAGE_PATH'),'/\\').'/backups';$before=glob($backupDir.'/.client_*.cnf')?:[];
    putenv('MYSQLDUMP_BINARY='.__DIR__.'/missing-client');[$failed]=op_command([PHP_BINARY,__DIR__.'/../scripts/backup-database.php']);putenv('MYSQLDUMP_BINARY');
    check($failed!==0,'Native backup client failure is reported');check((glob($backupDir.'/.client_*.cnf')?:[])===$before,'Failed backup cleans credential option file');
    $saved=$_ENV;$temp=sys_get_temp_dir().'/clevo-stage-test-'.ad_key();mkdir($temp,0700);
    try{
        foreach(['APP_ENV'=>'staging','APP_URL'=>'https://staging.example.test','PRIVATE_STORAGE_PATH'=>$temp,'DB_USERNAME'=>'restricted','DB_PASSWORD'=>ad_key(),'MAIL_TRANSPORT'=>'null','NOTIFICATION_EXTERNAL_SEND'=>'0'] as $k=>$v)op_set($k,$v);
        clevo_validate_environment();check(clevo_secure_environment()&&!clevo_production(),'Staging uses transport security without becoming production');
        op_set('APP_URL','http://staging.example.test');rejects(fn()=>clevo_validate_environment(),'Staging rejects plain HTTP');op_set('APP_URL','https://staging.example.test');
        op_set('DB_HOST','db.example.test');rejects(fn()=>clevo_validate_database_environment(),'Remote database requires verified TLS');
        op_set('DB_HOST','127.0.0.1');op_set('DB_USERNAME','root');rejects(fn()=>clevo_validate_environment(),'Staging rejects root runtime identity');
        op_set('DB_USERNAME','restricted');op_set('NOTIFICATION_EXTERNAL_SEND','1');rejects(fn()=>clevo_validate_environment(),'Staging external mail requires explicit SMTP/recipient setup');
    }finally{$_ENV=$saved;rmdir($temp);}
    [$code,$report]=op_command([PHP_BINARY,__DIR__.'/../scripts/production-preflight.php']);check($code!==0&&!json_decode($report,true)['ready'],'Unapproved local configuration cannot pass launch preflight');
}finally{file_put_contents($runtimeFile,$runtimeOriginal);$_ENV=$environmentOriginal;}
finish_tests('Production preparation suite');
