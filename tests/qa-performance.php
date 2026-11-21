<?php
declare(strict_types=1);
require __DIR__.'/admin-helpers.php';
if(clevo_env('DB_PORT')!=='13379'||$base!=='http://127.0.0.1:18089')exit(2);
// Bounded local smoke load, never a production stress test or capacity certificate.
$db->query('DELETE FROM security_rate_limits');
$admin=ad_actor('Admin','Perf!'.ad_key());$token=clevo_token_pair($db,clevo_account($db,$admin))['access_token'];
function qa_load(string $name,string $path,int $requests,int $concurrency,?string $token=null):array {
    global $base;
    $multi=curl_multi_init();$pending=0;$active=[];$latencies=[];$statuses=[];$errors=0;$transportErrors=[];$start=microtime(true);
    while($pending<$requests||$active){
        while($pending<$requests&&count($active)<$concurrency){
            $h=curl_init($base.'/api/v1/'.$path);
            curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$token?['Authorization: Bearer '.$token]:[]]);
            $active[spl_object_id($h)]=$h;curl_multi_add_handle($multi,$h);$pending++;
        }
        do{$code=curl_multi_exec($multi,$running);}while($code===CURLM_CALL_MULTI_PERFORM);
        if($code!==CURLM_OK)throw new RuntimeException('Local load client failed');
        while($message=curl_multi_info_read($multi)){
            $h=$message['handle'];$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);$statuses[$status]=($statuses[$status]??0)+1;
            $latencies[]=(float)curl_getinfo($h,CURLINFO_TOTAL_TIME)*1000;
            $body=json_decode((string)curl_multi_getcontent($h),true);
            if($message['result']!==CURLE_OK||$status!==200||($body['status']??'')!=='success'){
                $errors++;
                $transportErrors[]=['curl_code'=>$message['result'],'http_status'=>$status,'connect_ms'=>round((float)curl_getinfo($h,CURLINFO_CONNECT_TIME)*1000,2),'first_byte_ms'=>round((float)curl_getinfo($h,CURLINFO_STARTTRANSFER_TIME)*1000,2)];
            }
            unset($active[spl_object_id($h)]);curl_multi_remove_handle($multi,$h);curl_close($h);
        }
        if($running&&curl_multi_select($multi,0.2)===-1)usleep(1000);
    }
    $seconds=microtime(true)-$start;curl_multi_close($multi);sort($latencies,SORT_NUMERIC);
    check(count($latencies)===$requests,$name.' completes all scheduled requests');
    check($errors===0,$name.' has no HTTP, transport or JSON errors');
    $percentile=static fn(float $p)=>round($latencies[max(0,(int)ceil(count($latencies)*$p)-1)],2);
    return ['name'=>$name,'requests'=>$requests,'concurrency'=>$concurrency,'errors'=>$errors,'transport_failures'=>$transportErrors,'http_statuses'=>$statuses,'p50_ms'=>$percentile(0.5),'p95_ms'=>$percentile(0.95),'max_ms'=>round(max($latencies),2),'requests_per_second'=>round($requests/max($seconds,0.001),2)];
}
$runs=[];
foreach([1,5,10] as $concurrency)$runs[]=qa_load('catalog','stations?per_page=25&sort=rating',50,$concurrency);
$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Amman')))->format('Y-m-d');
$runs[]=qa_load('admin_ledger_report','admin/financial-reports?from='.$today.'&to='.$today.'&book=cash',20,5,$token);
$counts=$db->query('SELECT (SELECT COUNT(*) FROM stations) stations,(SELECT COUNT(*) FROM booking) bookings,(SELECT COUNT(*) FROM financial_journals) journals')->fetch_assoc();
$result=['environment'=>'isolated PHP built-in server + MariaDB, loopback only','fixture_counts'=>$counts,'runs'=>$runs,'limitations'=>'Small synthetic dataset; PHP built-in server serializes requests. No approved latency/throughput SLO, sustained soak, production topology or native-device measurement.'];
file_put_contents(__DIR__.'/../.security-test/qa-performance.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
finish_tests('Bounded isolated QA load');
