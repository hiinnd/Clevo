<?php
declare(strict_types=1);

/** The web dashboard and mobile admin routes enter the same transactional commands. */
function clevo_admin_command(mysqli $db,int $actor,string $action,int $id,array $input):array
{
    clevo_admin_actor($db,$actor);
    $schemas=['booking_cancel'=>'BookingCancellation','user_status'=>'AccountStatus','station_status'=>'StationStatus',
        'station_archive'=>'Empty','plan_create'=>'PlanCreate','plan_update'=>'PlanPatch','plan_delete'=>'Empty',
        'subscription_status'=>'SubscriptionStatus','support_reply'=>'Reply','support_status'=>'TicketStatus',
        'refund_decision'=>'Decision','withdrawal_status'=>'WithdrawalStatus','service_create'=>'ServiceCreate',
        'service_update'=>'ServicePatch','service_archive'=>'Empty','driver_create'=>'DriverCreate','driver_update'=>'DriverPatch',
        'settlement_request'=>'SettlementRequest'];
    if(!isset($schemas[$action]))throw new ClevoSecurityException('invalid_admin_action',422);
    clevo_api_validate($input,clevo_api_contracts()[$schemas[$action]]);
    if(!in_array($action,['plan_create','driver_create','settlement_request'],true)&&$id<1)throw new ClevoSecurityException('invalid_id',422);
    return clevo_db_atomic($db,function()use($db,$actor,$action,$id,$input):array{
        switch($action){
            case 'booking_cancel':clevo_booking_transition($db,$actor,$id,'Cancelled',$input['reason']);return ['booking_id'=>$id];
            case 'user_status':clevo_market_account_status($db,$actor,$id,$input['status']);return ['user_id'=>$id];
            case 'station_status':
                $s=clevo_admin_row(clevo_stmt($db,'SELECT * FROM stations WHERE StationID=? FOR UPDATE','i',[$id])->get_result()->fetch_assoc());
                if($s['ArchivedAt'])throw new ClevoSecurityException('station_archived',409);
                if($input['admin_status']==='approved'){
                    $owner=clevo_admin_row(clevo_account($db,(int)$s['UserID'],true));
                    if(clevo_role($owner['Role'])!=='owner'||!$owner['EmailVerifiedAt'])throw new ClevoSecurityException('owner_verification_required',409);
                    if($owner['AccountStatus']==='rejected'||clevo_stmt($db,'SELECT id FROM blacklist WHERE user_id=? LIMIT 1 FOR UPDATE','i',[(int)$s['UserID']])->get_result()->fetch_assoc())throw new ClevoSecurityException('owner_restricted',409);
                    clevo_verification_file(clevo_station_verification_reference($db,$id));
                    clevo_stmt($db,"UPDATE user SET AccountStatus='active',StationID=COALESCE(StationID,?) WHERE UserID=?",'ii',[$id,(int)$s['UserID']]);
                }
                clevo_stmt($db,"UPDATE stations SET admin_status=?,Status=IF(?='approved',Status,'Closed') WHERE StationID=?",'ssi',[$input['admin_status'],$input['admin_status'],$id]);
                clevoCreateNotification($db,(int)$s['UserID'],'تحديث حالة المحطة','تم تحديث حالة اعتماد المحطة.','info','owner-dashboard.php','Station review','Your station approval status was updated.');
                clevo_db_audit($db,$actor,'station.moderated','stations',$id);return ['station_id'=>$id];
            case 'station_archive':clevo_archive_catalog($db,$actor,'station',$id);return ['station_id'=>$id,'archived'=>true];
            case 'plan_create':return ['plan_id'=>clevo_market_plan($db,$actor,0,$input)];
            case 'plan_update':return ['plan_id'=>clevo_market_plan($db,$actor,$id,$input)];
            case 'plan_delete':clevo_admin_row(clevo_stmt($db,'SELECT id FROM plans WHERE id=? FOR UPDATE','i',[$id])->get_result()->fetch_assoc());clevo_archive_catalog($db,$actor,'plan',$id);return ['plan_id'=>$id,'active'=>false];
            case 'subscription_status':
                clevo_admin_row(clevo_stmt($db,'SELECT id FROM user_plans WHERE id=? FOR UPDATE','i',[$id])->get_result()->fetch_assoc());
                clevo_stmt($db,"UPDATE user_plans SET status='cancelled' WHERE id=?",'i',[$id]);clevo_db_audit($db,$actor,'subscription.cancelled','user_plans',$id);return ['subscription_id'=>$id];
            case 'support_reply':clevo_market_support_reply($db,$actor,$id,$input['message'],true);return ['ticket_id'=>$id];
            case 'support_status':
                clevo_market_ticket($db,$actor,$id,true,true);clevo_stmt($db,'UPDATE support_tickets SET status=? WHERE id=?','si',[$input['status'],$id]);clevo_db_audit($db,$actor,'support.status','support_tickets',$id);return ['ticket_id'=>$id];
            case 'refund_decision':clevo_market_refund_decision($db,$actor,$id,$input);return ['refund_id'=>$id];
            case 'withdrawal_status':clevo_market_withdrawal_status($db,$actor,$id,$input['status']);return ['withdrawal_id'=>$id];
            case 'service_create':
            case 'service_update':
            case 'service_archive':
                $station=$action==='service_create'?$id:(int)clevo_admin_row(clevo_stmt($db,'SELECT StationID FROM services WHERE ServiceID=?','i',[$id])->get_result()->fetch_assoc())['StationID'];
                $owner=(int)clevo_admin_row(clevo_stmt($db,'SELECT UserID FROM stations WHERE StationID=?','i',[$station])->get_result()->fetch_assoc())['UserID'];
                if($action==='service_archive'){clevo_archive_catalog($db,$actor,'service',$id);return ['service_id'=>$id];}
                $saved=clevo_market_service($db,$owner,$station,$action==='service_create'?0:$id,$input,$actor);
                clevo_db_audit($db,$actor,'admin.service.saved','services',$saved);return ['service_id'=>$saved];
            case 'driver_create':
            case 'driver_update':
                $station=$action==='driver_create'?$input['station_id']:(int)clevo_admin_row(clevo_stmt($db,"SELECT StationID FROM user WHERE UserID=? AND LOWER(Role)='driver'",'i',[$id])->get_result()->fetch_assoc())['StationID'];
                $owner=(int)clevo_admin_row(clevo_stmt($db,'SELECT UserID FROM stations WHERE StationID=?','i',[$station])->get_result()->fetch_assoc())['UserID'];
                if($action==='driver_update'&&array_key_exists('active',$input))clevo_market_account_status($db,$actor,$id,$input['active']?'active':'rejected');
                $saved=clevo_market_driver_save($db,$owner,$station,$action==='driver_create'?0:$id,$input,$actor);
                clevo_db_audit($db,$actor,'admin.driver.saved','user',$saved);return ['driver_id'=>$saved];
            case 'settlement_request':return clevo_finance_settlement_request($db,$actor,$input['owner_id'],clevo_finance_minor($input['amount']));
        }
        throw new LogicException('Unimplemented admin command.');
    });
}
