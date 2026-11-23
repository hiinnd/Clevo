<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('CLEVO_NOTIFICATION_WORKER',true);
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/subscription-reminders.php';
try{echo json_encode(clevo_subscription_expiry_batch($conn),JSON_THROW_ON_ERROR)."\n";}
catch(Throwable){fwrite(STDERR,"Subscription reminder batch failed; safe to retry.\n");exit(1);}