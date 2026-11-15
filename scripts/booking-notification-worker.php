<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/config.php';
try {
    $count=clevo_flush_notifications($conn,200);
    echo "Booking/application notification outbox delivered: $count.\n";
} catch(Throwable $e) { fwrite(STDERR,"Notification outbox delivery failed; committed events remain queued.\n"); exit(1); }
