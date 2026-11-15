<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/config.php';
// Run each minute with a dedicated protected environment. Shared transactions guard races
// with owner acceptance/payment; no schema changes or outbound provider calls occur here.
try {
    cancelExpiredBookings($conn);
    echo "Booking expiry batch completed (up to 200 candidates).\n";
} catch(Throwable $e){fwrite(STDERR,"Booking expiry batch needs reconciliation/retry. No exception or financial data emitted.\n");exit(1);}
