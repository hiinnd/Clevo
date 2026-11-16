<?php
// Exercises the real handoff and station confirmation services; no privileged bypass.
function test_delivery_finish(mysqli $db,int $owner,int $customer,int $driver,int $booking):void
{
    $b=clevo_stmt($db,'SELECT * FROM booking WHERE BookingID=?','i',[$booking])->get_result()->fetch_assoc();
    if((int)$b['DeliveryWorkflowVersion']!==1)clevo_delivery_adopt($db,$owner,$booking);
    for($i=0;$i<10;$i++){
        $b=clevo_stmt($db,'SELECT DeliveryStage FROM booking WHERE BookingID=?','i',[$booking])->get_result()->fetch_assoc();$stage=$b['DeliveryStage'];
        if($stage==='delivered')return;
        if(in_array($stage,['arrived_pickup','returning_vehicle'],true)){
            $kind=$stage==='arrived_pickup'?'pickup':'delivery';$code=clevo_delivery_issue_confirmation($db,$customer,$booking,$kind,bin2hex(random_bytes(16)));
            clevo_delivery_confirm($db,$driver,$booking,$kind,$code['confirmation_code'],bin2hex(random_bytes(16)));
        }else{
            $stages=clevo_delivery_stages();$next=$stages[array_search($stage,$stages,true)+1];
            clevo_delivery_advance($db,$next==='service_completed'?$owner:$driver,$booking,$next);
        }
    }
    throw new RuntimeException('Delivery fixture did not complete.');
}
