<?php
declare(strict_types=1);

function clevo_api_driver(mysqli $db,string $action,array $ctx,array $args,array $input):never
{
    $user=(int)$ctx['account']['UserID'];$id=(int)($args['id']??0);
    if(in_array($action,['jobs','history'],true))clevo_api_result(['tasks'=>clevo_api_booking_list($db,$ctx,$action==='history'?'history':'active')]);
    $job=clevo_api_driver_job($db,$ctx,$id,true);
    if($action==='detail'){
        $dto=clevo_booking_dto($job,'driver')+['DriverAcceptedAt'=>$job['DriverAcceptedAt']??null];
        if(in_array($job['Status'],['Accepted','Arrived','In Progress','Pickup','Washing','Returning'],true))$dto['customer']=clevo_stmt($db,'SELECT UserName,PhoneNumber,CarName FROM user WHERE UserID=?','i',[(int)$job['UserID']])->get_result()->fetch_assoc();
        else unset($dto['Latitude'],$dto['Longitude'],$dto['Address_Details']);
        clevo_api_result(['booking'=>$dto]);
    }
    $result=clevo_api_mutate($db,$ctx,'driver.'.$action,['id'=>$id]+$input,function()use($db,$user,$id,$input,$action,$ctx):array{
        $job=clevo_api_driver_job($db,$ctx,$id);
        if($action==='location'){
            if(!clevo_record_driver_location($db,$user,$id,(float)$input['latitude'],(float)$input['longitude']))throw new ClevoSecurityException('not_found',404);
        }else{
            $target=$action==='accept'?'In Progress':$input['status'];
            if($action==='accept'&&!empty($job['DriverAcceptedAt']))return ['booking_id'=>$id,'status'=>$job['Status']];
            clevo_driver_transition($db,$user,$id,$target);
            if($action==='accept')clevo_stmt($db,'UPDATE booking SET DriverAcceptedAt=UTC_TIMESTAMP(6) WHERE BookingID=?','i',[$id]);
        }
        return ['booking_id'=>$id,'status'=>$action==='location'?$job['Status']:($action==='accept'?'In Progress':$input['status'])];
    });clevo_api_result($result);
}
