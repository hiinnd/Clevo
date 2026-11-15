<?php
declare(strict_types=1);

function clevo_finance_booking_amounts(array $b,int $basis):array
{
    $commission=(int)($b['FinancialVersion']??0)===1?clevo_finance_commission($basis):clevo_finance_minor($b['Admin_Commission']??'0');
    if($commission>$basis)throw new ClevoSecurityException('legacy_commission_requires_reconciliation',409);
    return ['gross'=>$basis,'commission'=>$commission,'owner_minor'=>$basis-$commission];
}
function clevo_finance_complete(mysqli $db,array $b):void
{
    $id=(int)$b['BookingID'];$method=$b['Payment_Method'];$owner=(int)($b['OwnerIDSnapshot']??$b['_station']['UserID']??0);
    if($owner<1)throw new ClevoSecurityException('financial_owner_requires_reconciliation',409);
    if($method==='cash'){
        $basis=clevo_finance_minor(clevoBookingPayableAmount($b));$amounts=clevo_finance_booking_amounts($b,$basis);
        if($amounts['commission']>0){
            $journal=clevo_finance_post($db,"booking:$id:recognition",'cash_commission','cash',["cash_commission_due:$owner"=>$amounts['commission'],'platform_commission'=>-$amounts['commission']],$amounts+['owner'=>$owner,'booking'=>$id]);
            clevo_finance_receipt($db,$journal,(int)$b['UserID'],$id,null,$basis,'owner_cash_attestation');
        }
        clevo_finance_snapshot($db,$id,$basis);
        clevo_stmt($db,"UPDATE payments SET VerificationKind='owner_cash_attestation',VerifiedAt=UTC_TIMESTAMP(6) WHERE BookingID=? AND Method='cash'",'i',[$id]);
        return;
    }
    if($method==='plan'){
        $redemption=clevo_stmt($db,"SELECT r.*,up.FundingOrderID FROM subscription_redemptions r JOIN user_plans up ON up.id=r.UserPlanID WHERE r.BookingID=? AND r.State IN ('reserved','completed') FOR UPDATE",'i',[$id])->get_result()->fetch_assoc();
        if(!$redemption){clevo_finance_require_legacy_fixture($db,'subscription_funding_requires_reconciliation');return;}
        $basis=(int)$redemption['AmountMinor'];$order=$redemption['FundingOrderID'];$account='subscription_deferred:'.$order;
    }elseif($method==='visa'){
        $p=clevo_stmt($db,"SELECT p.FundingOrderID,o.AmountMinor FROM payments p JOIN payment_orders o ON o.OrderID=p.FundingOrderID WHERE p.BookingID=? AND o.State='succeeded' AND o.VerifiedAt IS NOT NULL",'i',[$id])->get_result()->fetch_assoc();
        if(!$p)throw new ClevoSecurityException('payment_confirmation_required',409);
        $order=$p['FundingOrderID'];$basis=(int)$p['AmountMinor'];$account='booking_deferred:'.$id;
    }else{
        clevo_finance_require_legacy_fixture($db);return;
    }
    $amounts=clevo_finance_booking_amounts($b,$basis);$entries=[$account=>$basis,"owner_payable:$owner"=>-$amounts['owner_minor'],'platform_commission'=>-$amounts['commission']];
    clevo_finance_post($db,"booking:$id:recognition",'earnings','sandbox',$entries,$amounts+['booking'=>$id,'order'=>$order,'owner'=>$owner]);
    clevo_finance_snapshot($db,$id,$basis);
    if(isset($redemption))clevo_stmt($db,"UPDATE subscription_redemptions SET State='completed' WHERE RedemptionID=?",'i',[(int)$redemption['RedemptionID']]);
}
function clevo_finance_assert_payment(mysqli $db,array $b,array $p):void
{
    $order=$p['FundingOrderID']??null;
    if($order){
        if(clevo_production())throw new ClevoSecurityException('payment_production_not_approved',503);
        $proof=clevo_stmt($db,"SELECT OrderID FROM payment_orders WHERE OrderID=? AND UserID=? AND BookingID=? AND State='succeeded' AND VerifiedAt IS NOT NULL FOR UPDATE",'sii',[$order,(int)$b['UserID'],(int)$b['BookingID']])->get_result()->fetch_assoc();
        if(!$proof)throw new ClevoSecurityException('payment_confirmation_required',409);
        if(clevo_stmt($db,"SELECT RefundID FROM provider_refunds WHERE OrderID=? AND State IN ('submitting','pending','unknown')",'s',[$order])->get_result()->fetch_assoc())throw new ClevoSecurityException('payment_refund_pending',409);
    }elseif(in_array($b['Payment_Method'],['wallet','points','visa'],true))clevo_finance_require_legacy_fixture($db,'legacy_funding_requires_reconciliation');
}
function clevo_finance_assert_plan(mysqli $db,array $b):void
{
    $plan=clevo_stmt($db,'SELECT FundingOrderID FROM user_plans WHERE id=? AND user_id=?','ii',[(int)$b['UserPlanID'],(int)$b['UserID']])->get_result()->fetch_assoc();
    if(empty($plan['FundingOrderID'])){clevo_finance_require_legacy_fixture($db,'subscription_funding_requires_reconciliation');return;}
    if(clevo_production())throw new ClevoSecurityException('subscription_production_not_approved',503);
    $proof=clevo_stmt($db,"SELECT r.RedemptionID FROM subscription_redemptions r JOIN user_plans up ON up.id=r.UserPlanID JOIN payment_orders o ON o.OrderID=up.FundingOrderID WHERE r.BookingID=? AND up.user_id=? AND r.State IN ('reserved','completed') AND o.State='succeeded' AND o.VerifiedAt IS NOT NULL AND o.UserID=up.user_id AND up.PaymentHold=0 FOR UPDATE",'ii',[(int)$b['BookingID'],(int)$b['UserID']])->get_result()->fetch_assoc();
    if(!$proof)throw new ClevoSecurityException('subscription_funding_requires_reconciliation',409);
}
function clevo_finance_verified_refund(mysqli $db,int $booking):bool
{
    return (bool)clevo_stmt($db,"SELECT p.PaymentID FROM payments p JOIN payment_orders o ON o.OrderID=p.FundingOrderID JOIN provider_refunds r ON r.OrderID=o.OrderID WHERE p.BookingID=? AND o.State='refunded' AND r.State='succeeded'",'i',[$booking])->get_result()->fetch_assoc();
}
