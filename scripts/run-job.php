<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/operations.php';
$lock=null;$job=$argv[1]??'';$exitCode=1;
try{
    clevo_load_environment();clevo_validate_environment();$jobs=clevo_operations_jobs();
    if(count($argv)!==2||!isset($jobs[$job]))throw new RuntimeException('Allowlisted job required.');
    if(clevo_env('MAINTENANCE_MODE','0')==='1')throw new RuntimeException('Jobs are stopped during maintenance.');
    if(clevo_env('ERASURE_WORKER_FENCE','0')==='1'&&in_array($job,['notification-outbox','notification-delivery','security-mail'],true))throw new RuntimeException('Outbound worker fence is active.');
    $directory=rtrim(clevo_env('PRIVATE_STORAGE_PATH'),'/\\').'/ops';if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new RuntimeException('Worker storage unavailable.');
    $lock=fopen($directory.'/'.$job.'.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Worker already active.');chmod($directory.'/'.$job.'.lock',0600);
    clevo_operations_heartbeat($job,['started_unix'=>time(),'exit_code'=>-1]);
    $process=proc_open([PHP_BINARY,'-d','display_errors=0','-d','zend.exception_ignore_args=1',__DIR__.'/'.$jobs[$job][0],...$jobs[$job][1]],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Worker launch failed.');
    fclose($pipes[0]);stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exitCode=proc_close($process);
    $status=['finished_unix'=>time(),'exit_code'=>$exitCode];if($exitCode===0)$status['last_success_unix']=time();clevo_operations_heartbeat($job,$status);
    echo json_encode(['event'=>'job.finished','job'=>$job,'exit_code'=>$exitCode,'time_utc'=>gmdate('c')],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable){fwrite(STDERR,"Operational job failed or refused; inspect protected health counters.\n");$exitCode=1;}
finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
exit($exitCode===0?0:1);
