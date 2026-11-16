<?php
declare(strict_types=1);

function clevo_api_driver(mysqli $db,string $action,array $ctx,array $args,array $input):never
{
    $user=(int)$ctx['account']['UserID'];$id=(int)($args['id']??0);
    if(in_array($action,['jobs','history'],true))clevo_api_result(['tasks'=>clevo_api_booking_list($db,$ctx,$action==='history'?'history':'active')]);
    $job=clevo_api_driver_job($db,$ctx,$id,true);
    if($action==='tracking')clevo_api_result(['tracking'=>clevo_delivery_tracking($db,$user,$id,(int)($ctx['session']['DriverBookingID']??0))]);
    if($action==='confirmation')clevo_api_result(clevo_delivery_confirm($db,$user,$id,$input['kind'],$input['code'],clevo_api_key()));
    if($action==='locations')clevo_api_result(clevo_delivery_location($db,$user,$id,$input));
    if($action==='detail'){
        $dto=clevo_booking_dto($job,'driver')+['DriverAcceptedAt'=>$job['DriverAcceptedAt']??null,'tracking'=>clevo_delivery_tracking($db,$user,$id,(int)($ctx['session']['DriverBookingID']??0))];
        if(in_array($job['Status'],['Accepted','Arrived','In Progress','Pickup','Washing','Returning'],true))$dto['customer']=clevo_stmt($db,'SELECT UserName,PhoneNumber,CarName FROM user WHERE UserID=?','i',[(int)$job['UserID']])->get_result()->fetch_assoc();
        else unset($dto['Latitude'],$dto['Longitude'],$dto['Address_Details']);
        clevo_api_result(['booking'=>$dto]);
    }
    $result=clevo_api_mutate($db,$ctx,'driver.'.$action,['id'=>$id]+$input,function()use($db,$user,$id,$input,$action,$ctx):array{
        $job=clevo_api_driver_job($db,$ctx,$id);
        if($action==='location'){
            if(!clevo_record_driver_location($db,$user,$id,(float)$input['latitude'],(float)$input['longitude'],array_diff_key($input,['latitude'=>1,'longitude'=>1])))throw new ClevoSecurityException('not_found',404);
        }else{
            if($action==='stage')return clevo_delivery_advance($db,$user,$id,$input['stage']);
            if($action==='accept')return clevo_delivery_advance($db,$user,$id,'accepted');
            $target=$action==='accept'?'In Progress':$input['status'];
            if($action==='accept'&&!empty($job['DriverAcceptedAt']))return ['booking_id'=>$id,'status'=>$job['Status']];
            clevo_driver_transition($db,$user,$id,$target);
            if($action==='accept')clevo_stmt($db,'UPDATE booking SET DriverAcceptedAt=UTC_TIMESTAMP(6) WHERE BookingID=?','i',[$id]);
        }
        return ['booking_id'=>$id,'status'=>$action==='location'?$job['Status']:($action==='accept'?'In Progress':$input['status'])];
    });clevo_api_result($result);
}
