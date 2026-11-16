<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/config.php';
try{
    if(!in_array('--apply',$argv,true)){echo "Tracking cleanup is read-only unless --apply is supplied. Run bounded cleanup each minute using a dedicated worker identity.\n";exit;}
    echo json_encode(clevo_delivery_purge($conn),JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){fwrite(STDERR,"Tracking cleanup failed. No location or credential emitted.\n");exit(1);}
