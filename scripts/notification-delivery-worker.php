<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('CLEVO_NOTIFICATION_WORKER',true);
require_once __DIR__.'/../includes/config.php';
try{
    clevo_flush_notifications($conn,200);
    if(!clevo_production()||clevo_env('NOTIFICATION_EXTERNAL_SEND','0')!=='1'){echo "External delivery disabled; inbox delivered and outbound jobs retained.\n";exit;}
    echo json_encode(clevo_notification_worker($conn),JSON_THROW_ON_ERROR)."\n";
}
catch(Throwable $e){fwrite(STDERR,"Notification worker failed; inspect private delivery counters.\n");exit(1);}
