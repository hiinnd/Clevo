<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/config.php';
try {
    // Read-only counts. Do not print customer records, addresses or payment information.
    $unconfigured=(int)$conn->query("SELECT COUNT(*) n FROM stations WHERE ArchivedAt IS NULL AND admin_status='approved' AND (((Has_At_Station=1 OR Has_Delivery=1) AND BookingCapacity IS NULL) OR (Has_Home_Service=1 AND HomeCapacity IS NULL))")->fetch_assoc()['n'];
    $legacy=['derived_durations'=>0,'missing_appointments'=>0];
    $rows=$conn->query("SELECT b.*,s.DefaultServiceMinutes,s.DeliveryBufferMinutes,(SELECT COUNT(*) FROM booking_services bs WHERE bs.BookingID=b.BookingID) LegacyServiceCount FROM booking b JOIN stations s ON s.StationID=b.StationID WHERE b.Status IN ('Pending','Accepted','In Progress','Arrived','Pickup','Washing','Returning')");
    while($row=$rows->fetch_assoc()){
        if($row['DurationMinutes']===null)$legacy['derived_durations']++;
        try{clevo_booking_interval($row,$row);}catch(Throwable $e){$legacy['missing_appointments']++;}
    }
    $pending=(int)$conn->query('SELECT COUNT(*) n FROM notification_outbox WHERE DeliveredAt IS NULL')->fetch_assoc()['n'];
    echo json_encode(['migration'=>'005_booking_workflow','unconfigured_approved_stations'=>$unconfigured,'active_bookings_using_legacy_duration_policy'=>(int)$legacy['derived_durations'],'active_bookings_without_usable_appointment'=>(int)$legacy['missing_appointments'],'pending_notification_outbox'=>$pending,'read_only'=>true],JSON_PRETTY_PRINT)."\n";
    if($unconfigured || (int)$legacy['missing_appointments']>0){fwrite(STDERR,"Booking rollout needs station configuration/legacy schedule reconciliation before opening traffic.\n");exit(2);}
} catch(Throwable $e){fwrite(STDERR,"Booking preflight failed; verify migrations and configuration.\n");exit(1);}
