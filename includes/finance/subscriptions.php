<?php
declare(strict_types=1);

function clevo_finance_plan_terms(array $plan):array
{
    $policy=json_decode($plan['SandboxPolicy']??'null',true);
    if(!is_array($policy)||!in_array($policy['tier']??'',['silver','gold','diamond'],true)||!is_array($policy['service_ids']??null)||!$policy['service_ids']||!is_array($policy['modes']??null)||!$policy['modes']||!is_bool($policy['allow_multiple']??null)||clevo_finance_minor($policy['max_booking_amount']??'0')<=0||(int)$plan['wash_count']<1)throw new ClevoSecurityException('subscription_terms_not_approved',503);
    $price=clevo_finance_minor($plan['price']);$washes=(int)$plan['wash_count'];
    if($price<$washes||$washes>10000)throw new ClevoSecurityException('subscription_terms_not_approved',503);
    if(array_diff($policy['modes'],['station','home','delivery'])||!in_array($plan['duration_type'],['day','month','year'],true)||(int)$plan['duration']<1||(int)$plan['duration']>120)throw new ClevoSecurityException('subscription_terms_not_approved',503);
    foreach($policy['service_ids'] as $id)if(!is_int($id)||$id<1||$id>2147483647)throw new ClevoSecurityException('subscription_terms_not_approved',503);
    return ['plan_id'=>(int)$plan['id'],'name_ar'=>$plan['name_ar'],'name_en'=>$plan['name_en'],'price_minor'=>$price,'washes'=>$washes,'duration'=>(int)$plan['duration'],'duration_type'=>$plan['duration_type'],'policy'=>$policy];
}
function clevo_finance_subscription_end(DateTimeImmutable $start,int $duration,string $unit):DateTimeImmutable
{
    if($duration<1||$duration>120)throw new ClevoSecurityException('invalid_subscription_duration',422);
    if($unit==='day')return $start->modify("+$duration days");
    if(!in_array($unit,['month','year'],true))throw new ClevoSecurityException('invalid_subscription_duration',422);
    $months=$unit==='year'?$duration*12:$duration;
    $target=$start->setDate((int)$start->format('Y'),(int)$start->format('m'),1)->modify("+$months months");
    return $target->setDate((int)$target->format('Y'),(int)$target->format('m'),min((int)$start->format('d'),(int)$target->format('t')));
}
function clevo_finance_plan_validate(mysqli $db,array $plan,array $context):array
{
    if(clevo_production())throw new ClevoSecurityException('subscription_production_not_approved',503);
    $funding=clevo_stmt($db,"SELECT * FROM payment_orders WHERE OrderID=? AND UserID=? AND Purpose='subscription' AND State='succeeded' AND VerifiedAt IS NOT NULL FOR UPDATE",'si',[$plan['FundingOrderID'],(int)$plan['user_id']])->get_result()->fetch_assoc();
    if(!$funding)throw new ClevoSecurityException('subscription_funding_requires_reconciliation',409);
    $terms=json_decode($plan['TermsSnapshot']??'null',true);$policy=$terms['policy']??[];
    $ids=$context['service_ids']??[];$mode=$context['mode']??'';$total=$context['total_minor']??PHP_INT_MAX;
    if(!$ids||array_diff($ids,$policy['service_ids']??[])||!in_array($mode,$policy['modes']??[],true)||(!$policy['allow_multiple']&&count($ids)>1)||$total>clevo_finance_minor($policy['max_booking_amount']??'0'))throw new ClevoSecurityException('service_not_covered_by_subscription',409);
    return $terms;
}
function clevo_finance_plan_reserve(mysqli $db,array $plan,int $booking):void
{
    if(empty($plan['FundingOrderID']))return; // Isolated legacy fixture path only.
    $terms=json_decode($plan['TermsSnapshot'],true);$count=(int)$terms['washes'];$price=(int)$terms['price_minor'];
    $used=array_map('intval',array_column(clevo_stmt($db,"SELECT SlotNumber FROM subscription_redemptions WHERE UserPlanID=? AND State<>'released' FOR UPDATE",'i',[(int)$plan['id']])->get_result()->fetch_all(MYSQLI_ASSOC),'SlotNumber'));
    $slot=1;while(in_array($slot,$used,true)&&$slot<=$count)$slot++;
    if($slot>$count)throw new ClevoSecurityException('insufficient_plan',409);
    $amount=intdiv($price,$count)+($slot<=$price%$count?1:0);
    clevo_stmt($db,'INSERT INTO subscription_redemptions (UserPlanID,BookingID,SlotNumber,AmountMinor) VALUES (?,?,?,?)','iiii',[(int)$plan['id'],$booking,$slot,$amount]);
    clevo_finance_snapshot($db,$booking,$amount);
}
function clevo_finance_plan_release(mysqli $db,int $booking,int $plan):void
{
    $row=clevo_stmt($db,'SELECT RedemptionID,State FROM subscription_redemptions WHERE BookingID=? AND UserPlanID=? FOR UPDATE','ii',[$booking,$plan])->get_result()->fetch_assoc();
    if(!$row||$row['State']!=='reserved')throw new ClevoSecurityException('subscription_funding_requires_reconciliation',409);
    clevo_stmt($db,"UPDATE subscription_redemptions SET State='released' WHERE RedemptionID=?",'i',[(int)$row['RedemptionID']]);
}
function clevo_finance_activate_plan(mysqli $db,array $order):int
{
    if(clevo_production())throw new ClevoSecurityException('subscription_production_not_approved',503);
    $terms=json_decode($order['TermsSnapshot'],true);$washes=(int)$terms['washes'];
    $existing=clevo_stmt($db,'SELECT id FROM user_plans WHERE FundingOrderID=?','s',[$order['OrderID']])->get_result()->fetch_assoc();
    if($existing)return (int)$existing['id'];
    // Only a verified, committed-with-this-activation provider result can reach this helper.
    $proof=clevo_stmt($db,"SELECT OrderID FROM payment_orders WHERE OrderID=? AND State='succeeded' AND VerifiedAt IS NOT NULL AND UserID=? AND Purpose='subscription'",'si',[$order['OrderID'],(int)$order['UserID']])->get_result()->fetch_assoc();
    if(!$proof)throw new ClevoSecurityException('payment_confirmation_required',409);
    $starts=new DateTimeImmutable('now',new DateTimeZone('UTC'));$expires=clevo_finance_subscription_end($starts,(int)$terms['duration'],$terms['duration_type']);
    clevo_stmt($db,"INSERT INTO user_plans (user_id,plan_id,start_date,end_date,status,remaining_washes,PurchasePrice,PurchasedWashes,PlanNameSnapshot,FundingOrderID,TermsSnapshot,StartsAtUtc,ExpiresAtUtc) VALUES (?,?,?,?,'active',?,?,?,?,?,?,?,?)",'iissisisssss',[(int)$order['UserID'],(int)$order['PlanID'],$starts->format('Y-m-d'),$expires->modify('-1 second')->format('Y-m-d'),$washes,clevo_db_decimal(intdiv((int)$order['AmountMinor'],10)),$washes,$terms['name_en'],$order['OrderID'],json_encode($terms,JSON_THROW_ON_ERROR),$starts->format('Y-m-d H:i:s.u'),$expires->format('Y-m-d H:i:s.u')]);
    $id=(int)$db->insert_id;
    clevoCreateNotification($db,(int)$order['UserID'],'تم تفعيل الاشتراك التجريبي','تم تأكيد تمويل باقتك التجريبية.','success','customer-plans.php','Sandbox subscription activated','Your verified sandbox subscription is active.');
    return $id;
}
