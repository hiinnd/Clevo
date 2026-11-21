<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/config.php';
if(!in_array('--apply',$argv,true)){echo "Read-only: reviewed erasure files require --apply.\n";exit;}
try{echo json_encode(clevo_privacy_cleanup_files($conn),JSON_THROW_ON_ERROR)."\n";}
catch(Throwable){fwrite(STDERR,"Reviewed account erasure files remain pending; no personal data emitted.\n");exit(1);}
