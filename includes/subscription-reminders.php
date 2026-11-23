<?php
declare(strict_types=1);

/** UTC date policy matches existing eligibility. This never activates a plan. */
function clevo_subscription_expiry_batch(mysqli $db,int $limit=100):array
{
    $rows=clevo_stmt($db,"SELECT up.id,up.user_id FROM user_plans up WHERE up.status='active' AND up.end_date IS NOT NULL AND up.end_date<DATE_ADD(UTC_DATE(),INTERVAL 8 DAY) AND NOT EXISTS (SELECT 1 FROM request_idempotency r WHERE r.UserID=up.user_id AND r.Scope='subscription.expiry' AND r.KeyHash=CONVERT(SHA2(SHA2(CONCAT(IF(DATE(up.end_date)<UTC_DATE(),'expired','soon'),':',up.id,':',up.end_date),256),256) USING ascii) COLLATE ascii_bin AND r.ResponseJson IS NOT NULL) ORDER BY up.end_date,up.id LIMIT ?",'i',[max(1,min(500,$limit))])->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach($rows as $candidate)clevo_db_atomic($db,function()use($db,$candidate):void{
        $user=(int)$candidate['user_id'];clevo_account($db,$user,true);
        $plan=clevo_stmt($db,"SELECT up.*,p.name_ar,p.name_en,(DATE(up.end_date)<UTC_DATE()) IsExpired,DATEDIFF(DATE(up.end_date),UTC_DATE()) DaysLeft FROM user_plans up JOIN plans p ON p.id=up.plan_id WHERE up.id=? AND up.status='active' FOR UPDATE",'i',[(int)$candidate['id']])->get_result()->fetch_assoc();
        if(!$plan||(int)$plan['DaysLeft']>7)return;
        $event=$plan['IsExpired']?'expired':'soon';$id=(int)$plan['id'];$date=(string)$plan['end_date'];
        clevo_db_idempotent($db,$user,'subscription.expiry',hash('sha256',$event.':'.$id.':'.$date),[$event,$id,$date],function()use($db,$user,$plan,$event,$id):array{
            if($event==='expired'){
                clevo_stmt($db,"UPDATE user_plans SET status='expired' WHERE id=? AND status='active'",'i',[$id]);
                clevo_queue_notification($db,null,'Admin','انتهت باقة عميل','انتهى الاشتراك #'.$id.'. راجع سجل الاشتراك.','warning','manage-users.php','Plan expired','Subscription #'.$id.' expired. Review its recorded history.');
            }else{
                $days=max(0,(int)$plan['DaysLeft']);
                clevo_queue_notification($db,$user,null,'قرب انتهاء الباقة','الاشتراك #'.$id.' ينتهي خلال '.$days.' أيام. راجع باقاتك.','warning','customer-plans.php','Plan expiring soon','Subscription #'.$id.' expires in '.$days.' day(s). Review your plans.');
            }
            return ['recorded'=>true];
        });
    });
    return ['subscriptions_examined'=>count($rows)];
}
