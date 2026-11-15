<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
if(clevo_env('APP_ENV')!=='testing'||clevo_env('DB_HOST')!=='127.0.0.1'||!preg_match('/^clevo_security_test_[a-f0-9]{12}$/D',clevo_env('DB_DATABASE','')??''))exit(2);
$port=(int)($argv[1]??0);if($port!==18090)exit(2);
$conn=clevo_connect_database();
$a=$conn->query("SELECT UserID FROM user WHERE LOWER(Role) IN ('customer','user') AND AccountStatus='active' ORDER BY UserID DESC LIMIT 1")->fetch_assoc();
$pair=clevo_token_pair($conn,clevo_account($conn,(int)$a['UserID']));
$h=curl_init("http://127.0.0.1:$port/api/v1/me");curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$pair['access_token']]]);$json=json_decode(curl_exec($h),true);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
check($status===200&&(int)($json['data']['user']['id']??0)===(int)$a['UserID'],'Real Apache rewrite forwards bearer authorization into PHP');
check(isset($json['meta']['request_id']),'Real Apache response has request correlation');
finish_tests('Apache bearer/API suite');
