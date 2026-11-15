<?php
declare(strict_types=1);

function clevo_api_booking_list(mysqli $db,array $ctx,string $scope):array
{
    $a=$ctx['account'];$role=clevo_role($a['Role']);$user=(int)$a['UserID'];$where=[];$types='';$params=[];
    if($role==='customer'){$where[]='b.UserID=?';$params[]=$user;$types.='i';}
    elseif($role==='owner'){$where[]='s.UserID=?';$params[]=$user;$types.='i';}
    elseif($role==='driver'){
        $where[]="b.DriverID=? AND b.StationID=? AND s.admin_status='approved'";$params=[$user,(int)$a['StationID']];$types='ii';
        if(!empty($ctx['session']['DriverBookingID'])){$where[]='b.BookingID=?';$params[]=(int)$ctx['session']['DriverBookingID'];$types.='i';}
    }elseif($role!=='admin')throw new ClevoSecurityException('forbidden',403);
    $statuses=['Pending','Accepted','Arrived','In Progress','Pickup','Washing','Returning','Rejected','Cancelled','Cancelled_Timeout','Completed'];
    if($scope==='history')$where[]="b.Status IN ('Rejected','Cancelled','Cancelled_Timeout','Completed')";
    if($scope==='active')$where[]="b.Status IN ('Accepted','Arrived','In Progress','Pickup','Washing','Returning')";
    $status=clevo_api_enum('status',$statuses);if($status!==null){$where[]='b.Status=?';$params[]=$status;$types.='s';}
    $after=clevo_api_query('after_id');if($after!==null){$where[]='b.BookingID>?';$params[]=clevo_api_integer('after_id',0,0);$types.='i';}
    $rows=clevo_api_list($db,'b.*','FROM booking b JOIN stations s ON s.StationID=b.StationID',$where?implode(' AND ',$where):'1=1',$types,$params,['id'=>'b.BookingID','date'=>'b.Booking_Date','price'=>'b.Total_Price'],'id','b.BookingID');
    return array_map(function($b)use($role,$scope){$dto=clevo_booking_dto($b,$role);if($role==='driver'&&$scope==='history')unset($dto['Latitude'],$dto['Longitude'],$dto['Address_Details']);return $dto;},$rows);
}
function clevo_api_booking_change(array $result):array
{
    if(($result['status']??'')!=='success'){
        $reason=$result['message']??'';
        if(in_array($reason,['booking_not_found','invalid_booking'],true))throw new ClevoSecurityException('not_found',404);
        throw new ClevoSecurityException(in_array($reason,['booking_locked','refund_failed','invalid_datetime','legacy_payment_requires_reconciliation'],true)?$reason:'booking_conflict',409);
    }
    return $result;
}
function clevo_api_bookings(mysqli $db,string $action,array $ctx,array $args,array $input):never
{
    $user=(int)$ctx['account']['UserID'];$role=clevo_role($ctx['account']['Role']);$id=(int)($args['id']??0);
    if($action==='create')clevo_api_result(clevo_create_booking($db,$user,$input['station_id'],$input,clevo_api_key()));
    if(in_array($action,['list','history'],true))clevo_api_result(['bookings'=>clevo_api_booking_list($db,$ctx,$action==='history'?'history':'all')]);
    $b=clevo_api_booking($db,$ctx,$id);
    if($action==='detail'){
        $dto=clevo_booking_dto($b,$role);
        if($role==='owner'&&in_array($b['Status'],['Pending','Accepted','Arrived','In Progress','Pickup','Washing','Returning'],true))$dto['customer']=clevo_stmt($db,'SELECT UserName,PhoneNumber,CarName FROM user WHERE UserID=?','i',[(int)$b['UserID']])->get_result()->fetch_assoc();
        if(in_array($role,['customer','owner'],true)&&$b['Service_Location']==='delivery'&&in_array($b['Status'],['Accepted','Arrived','In Progress','Pickup','Washing','Returning'],true)&&!empty($b['DriverID']))$dto['driver']=clevo_stmt($db,"SELECT UserID id,UserName name,PhoneNumber phone FROM user WHERE UserID=? AND StationID=? AND LOWER(Role)='driver' AND AccountStatus='active'",'ii',[(int)$b['DriverID'],(int)$b['StationID']])->get_result()->fetch_assoc();
        if($role!=='driver')$dto['services']=clevo_stmt($db,'SELECT ServiceID,ServiceName,Price FROM booking_services WHERE BookingID=? ORDER BY BookingServiceID','i',[$id])->get_result()->fetch_all(MYSQLI_ASSOC);
        clevo_api_result(['booking'=>$dto]);
    }
    if($action==='status')clevo_api_result(['booking'=>['BookingID'=>$id,'Status'=>$b['Status'],'Driver_Lat'=>$b['Driver_Lat'],'Driver_Lon'=>$b['Driver_Lon'],'DriverAcceptedAt'=>$b['DriverAcceptedAt']??null]]);
    if($action==='price'){
        $receipt=$b['ReceiptStatus']!==null?['Amount'=>$b['ReceiptAmount'],'Currency'=>$b['ReceiptCurrency'],'Status'=>$b['ReceiptStatus']]:null;
        $net=clevo_db_decimal(clevo_db_money(clevoBookingPayableAmount($b)));$planMethod=$b['Payment_Method']==='plan';
        $plan=$planMethod&&(int)$b['Plan_Washes_Used']>0&&(int)$b['UserPlanID']>0&&(int)$b['UserID']>0&&(int)$b['PlanOwnerUserID']===(int)$b['UserID'];
        $state=$receipt['Status']??(empty($b['Payment_Method'])?'unpaid':($plan?'plan_legacy':'unverified_legacy'));
        if($receipt&&((int)$b['ReceiptUserID']!==(int)$b['UserID']||$b['ReceiptMethod']!==$b['Payment_Method']||$b['ReceiptCurrency']!=='JOD'||clevo_db_money($b['ReceiptAmount'])!==($planMethod?0:clevo_db_money($net))||($planMethod&&!$plan&&$state!=='refunded')))$state='reconciliation_required';
        $terminal=in_array($b['Status'],['Cancelled','Cancelled_Timeout','Rejected'],true);
        $outstanding=in_array($state,['unverified_legacy','reconciliation_required'],true)?null:($terminal||$plan||in_array($state,['recorded','refunded'],true)?'0.00':$net);
        $payable=$planMethod?($plan||$state==='refunded'?'0.00':null):$net;
        clevo_api_result(['price_breakdown'=>['BookingID'=>$id,'currency'=>'JOD','total'=>$b['Total_Price'],'points_used'=>$b['Points_Used'],'discount'=>$b['Points_Discount'],'payable'=>$payable,'outstanding'=>$outstanding,'settlement_state'=>$state,'plan_covered'=>$plan&&!$terminal&&!in_array($state,['refunded','reconciliation_required'],true),'payment_method'=>$b['Payment_Method'],'receipt'=>$receipt,'items'=>clevo_stmt($db,'SELECT ServiceID,ServiceName,Price FROM booking_services WHERE BookingID=? ORDER BY BookingServiceID','i',[$id])->get_result()->fetch_all(MYSQLI_ASSOC)]]);
    }
    $result=clevo_api_mutate($db,$ctx,'booking.'.$action,['id'=>$id]+$input,function()use($db,$action,$id,$user,$input){
        $r=$action==='cancel'?clevoCancelBookingForUser($db,$id,$user):clevoRescheduleBookingForUser($db,$id,$user,$input['booking_date'],$input['booking_time']);
        clevo_api_booking_change($r);clevo_db_audit($db,$user,'booking.'.$action,'booking',$id);return $r;
    });
    clevo_api_result($result);
}
