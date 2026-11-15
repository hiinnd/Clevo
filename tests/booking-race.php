<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
if(clevo_env('APP_ENV')!=='testing'||clevo_env('DB_HOST')!=='127.0.0.1'||!preg_match('/^clevo_(security|database)_test_[a-f0-9]{12}$/D',clevo_env('DB_DATABASE','')??''))exit(2);
$db=clevo_connect_database();
if(strcasecmp(realpath($db->query('SELECT @@datadir d')->fetch_assoc()['d'])?:'',realpath(__DIR__.'/../.security-test/mysql')?:'!')!==0)exit(2);
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
while(microtime(true)<$input['start'])usleep(1000);
try {
    $result=match($input['op']){
        'create'=>clevo_create_booking($db,$input['actor'],$input['station'],$input['body'],$input['key']),
        'transition'=>clevo_booking_transition($db,$input['actor'],$input['booking'],$input['target'],$input['reason']),
        'reschedule'=>clevoRescheduleBookingForUser($db,$input['booking'],$input['actor'],$input['date'],$input['time']),
        'assign'=>clevo_market_assign($db,$input['actor'],$input['booking'],$input['driver']),
        'outbox'=>clevo_flush_notifications($db,200),
        default=>throw new RuntimeException('Unknown test operation.'),
    };
    echo json_encode(['ok'=>!is_array($result)||($result['status']??'success')==='success','result'=>$result]);
} catch(Throwable $e){echo json_encode(['ok'=>false,'reason'=>$e instanceof ClevoSecurityException?$e->reason:'database_conflict']);}
