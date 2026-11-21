<?php
declare(strict_types=1);
require_once __DIR__.'/security/environment.php';
require_once __DIR__.'/security/auth.php';

function clevo_operations_jobs():array
{
    return ['notification-outbox'=>['booking-notification-worker.php',[],180],
        'notification-delivery'=>['notification-delivery-worker.php',[],180],
        'security-mail'=>['security-mail-worker.php',[],180],
        'booking-expiry'=>['expire-bookings.php',[],180],
        'tracking-retention'=>['purge-tracking.php',['--apply'],180],
        'payment-report'=>['reconcile-payments.php',[],900],
        'privacy-files'=>['process-account-erasure-files.php',['--apply'],900]];
}
function clevo_operations_heartbeat(string $job,array $status):void
{
    if(!isset(clevo_operations_jobs()[$job]))throw new RuntimeException('Unknown operational job.');
    $directory=rtrim(clevo_env('PRIVATE_STORAGE_PATH'),'/\\').'/ops';
    if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new RuntimeException('Operational storage unavailable.');
    $file=$directory.'/'.$job.'.json';$previous=is_file($file)?json_decode((string)file_get_contents($file),true):[];
    $data=array_replace(is_array($previous)?$previous:[],$status);
    $temp=$file.'.'.bin2hex(random_bytes(6)).'.tmp';
    if(file_put_contents($temp,json_encode($data,JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('Heartbeat unavailable.');
    chmod($temp,0600);
    if(!rename($temp,$file)){unlink($temp);throw new RuntimeException('Heartbeat update failed.');}
}
function clevo_operations_health():array
{
    $checks=[];$metrics=[];$db=null;
    try{clevo_validate_environment();$checks['configuration']=true;}catch(Throwable){$checks['configuration']=false;}
    $checks['maintenance']=clevo_env('MAINTENANCE_MODE','0')!=='1';
    try{
        $db=clevo_connect_database();$checks['database']=(int)$db->query('SELECT 1 ok')->fetch_assoc()['ok']===1;
        $deployed=$db->query('SELECT Version FROM security_schema_migrations')->fetch_all(MYSQLI_ASSOC);
        $expected=['001_auth_security','002_database_integrity','003_required_relationships','004_mobile_api','005_booking_workflow','006_commercial_payments','007_driver_tracking','008_notifications','009_privacy_requests'];
        $checks['schema']=!array_diff($expected,array_column($deployed,'Version'));
        $queue=$db->query("SELECT COUNT(*) queued,COALESCE(MAX(TIMESTAMPDIFF(SECOND,AvailableAt,UTC_TIMESTAMP(6))),0) oldest FROM notification_deliveries WHERE State='queued' AND AvailableAt<=UTC_TIMESTAMP(6)")->fetch_assoc();
        $metrics['delivery_queue_due']=(int)$queue['queued'];$metrics['delivery_oldest_due_seconds']=max(0,(int)$queue['oldest']);
        $metrics['security_mail_due']=(int)$db->query('SELECT COUNT(*) n FROM security_mail_queue WHERE SentAt IS NULL AND Attempts<5 AND AvailableAt<=UTC_TIMESTAMP(6)')->fetch_assoc()['n'];
    }catch(Throwable){$checks['database']=false;$checks['schema']=false;}
    $root=realpath(clevo_env('PRIVATE_STORAGE_PATH','')??'');
    $checks['storage']=$root&&is_readable($root)&&is_writable($root);
    $checks['workers']=true;
    if(clevo_env('OPS_REQUIRE_WORKERS','0')==='1')foreach(clevo_operations_jobs() as $job=>$definition){
        if($job==='privacy-files'&&clevo_env('ERASURE_RETENTION_REVIEW_APPROVED','0')!=='1')continue;
        $file=$root.'/ops/'.$job.'.json';$data=is_file($file)&&filesize($file)<8192?json_decode((string)file_get_contents($file),true):[];
        $success=(int)($data['last_success_unix']??0);$age=$success?max(0,time()-$success):PHP_INT_MAX;
        $metrics['worker_'.$job.'_age_seconds']=$age;$metrics['worker_'.$job.'_exit_code']=(int)($data['exit_code']??-1);
        if($age>$definition[2]||($data['exit_code']??-1)!==0)$checks['workers']=false;
    }
    if(clevo_env('OPS_REQUIRE_BACKUP','0')==='1'){
        $file=$root.'/ops/backup.json';$status=is_file($file)&&filesize($file)<8192?json_decode((string)file_get_contents($file),true):[];
        $age=isset($status['last_success_unix'])?max(0,time()-(int)$status['last_success_unix']):PHP_INT_MAX;
        $metrics['backup_age_seconds']=$age;$checks['backup']=$age<=(int)clevo_env('BACKUP_MAX_AGE_SECONDS','90000');
    }
    if($db)$db->close();
    return ['status'=>in_array(false,$checks,true)?'unavailable':'ready','checks'=>$checks,'metrics'=>$metrics];
}
function clevo_operations_prometheus(array $report):string
{
    $lines=['# TYPE clevo_ready gauge','clevo_ready '.($report['status']==='ready'?'1':'0')];
    foreach($report['checks'] as $key=>$value)$lines[]='clevo_check{check="'.$key.'"} '.($value?'1':'0');
    foreach($report['metrics'] as $key=>$value)$lines[]='clevo_'.str_replace('-','_',$key).' '.(int)$value;
    return implode("\n",$lines)."\n";
}
