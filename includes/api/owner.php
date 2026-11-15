<?php
declare(strict_types=1);

function clevo_api_owner(mysqli $db,string $action,array $ctx,array $args,array $input):never
{
    $user=(int)$ctx['account']['UserID'];$id=(int)($args['id']??0);
    if($action==='stations')clevo_api_result(['stations'=>clevo_api_list($db,'StationID,Name,Location,Lat,Lon,Status,admin_status,OpeningTime,ClosingTime,Has_At_Station,Has_Home_Service,Has_Delivery,BookingCapacity,HomeCapacity,DefaultServiceMinutes,DeliveryBufferMinutes,StationFee,HomeFee,DeliveryFee','FROM stations','UserID=? AND ArchivedAt IS NULL','i',[$user],['id'=>'StationID','name'=>'Name'],'id','StationID')]);
    if($action==='bookings')clevo_api_result(['bookings'=>clevo_api_booking_list($db,$ctx,'all')]);
    if($action==='services'){
        clevo_market_station($db,$user,$id,false);
        clevo_api_result(['services'=>clevo_api_list($db,'ServiceID,Name_ar,Name_en,Description_ar,Description_en,Price,Duration,is_station,is_home,is_pickup','FROM services','StationID=? AND ArchivedAt IS NULL','i',[$id],['id'=>'ServiceID','price'=>'Price'],'id','ServiceID')]);
    }
    if($action==='drivers')clevo_api_result(['drivers'=>clevo_api_list($db,'u.UserID,u.UserName,u.PhoneNumber,u.StationID,u.AccountStatus','FROM user u JOIN stations s ON s.StationID=u.StationID',"s.UserID=? AND LOWER(u.Role)='driver'",'i',[$user],['id'=>'u.UserID','name'=>'u.UserName'],'id','u.UserID')]);
    if($action==='bank')clevo_api_result(['bank_account'=>clevoGetOwnerBankAccount($db,$user)]);
    if($action==='withdrawals')clevo_api_result(['withdrawals'=>clevo_api_list($db,'id,amount,status,requested_at,processed_at','FROM owner_withdrawal_requests','owner_id=?','i',[$user],['id'=>'id','requested_at'=>'requested_at','amount'=>'amount'],'id','id')]);
    if($action==='earnings'){
        $wallet=clevo_stmt($db,'SELECT WalletID,Balance FROM wallet WHERE UserID=?','i',[$user])->get_result()->fetch_assoc();
        $summary=clevo_stmt($db,"SELECT COALESCE(SUM(CASE WHEN b.Status='Completed' THEN b.Total_Price ELSE 0 END),0) nominal_completed_gmv,COALESCE(SUM(CASE WHEN b.Status='Completed' THEN b.Admin_Commission ELSE 0 END),0) nominal_commission,COUNT(*) booking_count FROM booking b JOIN stations s ON s.StationID=b.StationID WHERE COALESCE(b.OwnerIDSnapshot,s.UserID)=?",'i',[$user])->get_result()->fetch_assoc();
        $summary['available_balance']=$wallet['Balance']??'0.00';
        clevo_api_result(['earnings'=>$summary,'transactions'=>clevo_api_list($db,'t.TransactionID,t.Amount,t.TransactionType,t.Description_ar,t.Description_en,t.CreatedAt,t.BookingID','FROM wallet_transactions t JOIN wallet w ON w.WalletID=t.WalletID','w.UserID=?','i',[$user],['id'=>'t.TransactionID','created_at'=>'t.CreatedAt','amount'=>'t.Amount'],'id','t.TransactionID')]);
    }
    if($action==='reviews')clevo_api_result(['reviews'=>clevo_api_list($db,'r.ReviewID,r.BookingID,r.Rating,r.Comment,r.Owner_Reply','FROM reviews r JOIN booking b ON b.BookingID=r.BookingID JOIN stations s ON s.StationID=b.StationID','s.UserID=?','i',[$user],['id'=>'r.ReviewID','rating'=>'r.Rating'],'id','r.ReviewID')]);
    if($action==='driver_code'){
        clevo_rate_limit($db,'api:driver-code',(string)$user,10,60);
        clevo_api_result(['driver_code'=>clevo_issue_driver_code($db,$user,$id),'expires_in'=>900]);
    }
    $result=clevo_api_mutate($db,$ctx,'owner.'.$action,['id'=>$id]+$input,function()use($db,$user,$id,$input,$action):array{
        switch($action){
            case 'station_update':
                $s=clevo_market_station($db,$user,$id);
                if(array_key_exists('latitude',$input)!==array_key_exists('longitude',$input))throw new ClevoSecurityException('coordinates_required',422);
                if(isset($input['location_url'])&&(!filter_var($input['location_url'],FILTER_VALIDATE_URL)||!preg_match('~^https?://~i',$input['location_url'])))throw new ClevoSecurityException('invalid_location_url',422);
                clevo_stmt($db,'UPDATE stations SET Name=?,Location=?,Location_URL=?,PhoneNumber=?,Lat=?,Lon=? WHERE StationID=?','ssssddi',[$input['name']??$s['Name'],$input['location']??$s['Location'],$input['location_url']??$s['Location_URL'],$input['phone']??$s['PhoneNumber'],$input['latitude']??$s['Lat'],$input['longitude']??$s['Lon'],$id]);clevo_db_audit($db,$user,'station.updated','stations',$id);return ['station_id'=>$id];
            case 'station_image':
                clevo_market_station($db,$user,$id);$ref=clevo_save_upload($_FILES['image'],'stations');clevo_stmt($db,'UPDATE stations SET Image=? WHERE StationID=?','si',[$ref,$id]);return ['image'=>clevo_media_url($ref)];
            case 'bank_update':
                if(!clevoSaveOwnerBankAccount($db,$user,$input))throw new ClevoSecurityException('invalid_bank_account',422);clevo_db_audit($db,$user,'bank.updated','user',$user);return ['saved'=>true];
            case 'withdrawal_create':return clevo_request_withdrawal($db,$user,hash('sha256','owner.withdrawal:'.clevo_api_key()));
            case 'service_create':return ['service_id'=>clevo_market_service($db,$user,$id,0,$input)];
            case 'service_update':
                $row=clevo_api_require_row(clevo_stmt($db,'SELECT v.StationID FROM services v JOIN stations s ON s.StationID=v.StationID WHERE v.ServiceID=? AND s.UserID=? AND v.ArchivedAt IS NULL','ii',[$id,$user])->get_result()->fetch_assoc());
                return ['service_id'=>clevo_market_service($db,$user,(int)$row['StationID'],$id,$input)];
            case 'service_delete':clevo_archive_catalog($db,$user,'service',$id);return ['service_id'=>$id,'archived'=>true];
            case 'availability':clevo_market_availability($db,$user,$id,$input);return ['station_id'=>$id];
            case 'accept':clevo_accept_owner_booking($db,$user,$id);break;
            case 'reject':clevo_market_reject($db,$user,$id);break;
            case 'complete':clevo_complete_owner_booking($db,$user,$id);break;
            case 'assign':clevo_market_assign($db,$user,$id,$input['driver_id']);break;
            case 'driver_create':return ['driver_id'=>clevo_market_driver_save($db,$user,$input['station_id'],0,$input),'activation'=>isset($input['email'])?'email_verification_and_password_reset':'owner_task_code'];
            case 'driver_invite':clevo_market_driver_invite($db,$user,$id,$input['email']);return ['driver_id'=>$id,'activation'=>'email_verification_and_password_reset'];
            case 'driver_update':case 'driver_delete':
                $row=clevo_api_require_row(clevo_stmt($db,"SELECT u.StationID FROM user u JOIN stations s ON s.StationID=u.StationID WHERE u.UserID=? AND s.UserID=? AND LOWER(u.Role)='driver'",'ii',[$id,$user])->get_result()->fetch_assoc());
                return ['driver_id'=>clevo_market_driver_save($db,$user,(int)$row['StationID'],$id,$action==='driver_delete'?['active'=>false]:$input)];
            case 'review_reply':clevo_market_review_reply($db,$user,$id,$input['message']);return ['review_id'=>$id];
            default:throw new LogicException('Unimplemented owner route.');
        }
        return ['booking_id'=>$id];
    });clevo_api_result($result,in_array($action,['service_create','driver_create','withdrawal_create'],true)?201:200);
}
