<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
if(clevo_env('APP_ENV')!=='testing'||clevo_env('DB_HOST')!=='127.0.0.1'||!preg_match('/^clevo_database_test_[a-f0-9]{12}$/D',clevo_env('DB_DATABASE','')??''))exit(2);
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); $conn=clevo_connect_database();
// Both processes wait for the same instant, then acquire independent connections/locks.
while(microtime(true)<$input['start'])usleep(1000);
try {
    if($input['op']==='booking') $result=clevo_create_booking($conn,$input['user'],$input['station'],$input['body'],$input['key']);
    elseif($input['op']==='payment') $result=clevo_pay_booking($conn,$input['user'],$input['booking'],'wallet',$input['points']??false,$input['key']);
    elseif($input['op']==='plan') $result=clevo_create_booking($conn,$input['user'],$input['station'],$input['body'],$input['key']);
    else throw new RuntimeException('Unknown operation.');
    echo json_encode(['ok'=>true,'result'=>$result]);
}catch(Throwable $e){echo json_encode(['ok'=>false,'reason'=>$e instanceof ClevoSecurityException?$e->reason:'database_conflict']);}
