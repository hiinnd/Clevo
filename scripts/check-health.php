<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/operations.php';
try{
    clevo_load_environment();$report=clevo_operations_health();
    echo in_array('--prometheus',$argv,true)?clevo_operations_prometheus($report):json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
    exit($report['status']==='ready'?0:1);
}catch(Throwable){fwrite(STDERR,"Health unavailable; no configuration values emitted.\n");exit(1);}
