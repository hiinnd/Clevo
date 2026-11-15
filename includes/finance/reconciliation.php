<?php
declare(strict_types=1);

function clevo_finance_report(mysqli $db):array
{
    $broken=(int)$db->query('SELECT COUNT(*) n FROM (SELECT j.JournalID,j.DebitMinor,j.CreditMinor,COALESCE(SUM(CASE WHEN e.SignedMinor>0 THEN e.SignedMinor ELSE 0 END),0) d,COALESCE(SUM(CASE WHEN e.SignedMinor<0 THEN -e.SignedMinor ELSE 0 END),0) c FROM financial_journals j LEFT JOIN financial_entries e ON e.JournalID=j.JournalID GROUP BY j.JournalID,j.DebitMinor,j.CreditMinor HAVING d<>c OR d<>j.DebitMinor OR c<>j.CreditMinor) q')->fetch_assoc()['n'];
    $orders=$db->query('SELECT Environment,State,COUNT(*) count,COALESCE(SUM(AmountMinor),0) amount_minor FROM payment_orders GROUP BY Environment,State ORDER BY State')->fetch_all(MYSQLI_ASSOC);
    $unproven=(int)$db->query("SELECT COUNT(*) n FROM payments WHERE Status='recorded' AND VerificationKind IS NULL")->fetch_assoc()['n'];
    $mismatch=(int)$db->query("SELECT COUNT(*) n FROM payment_orders o LEFT JOIN payments p ON p.FundingOrderID=o.OrderID WHERE o.Purpose='booking' AND o.State='succeeded' AND (p.PaymentID IS NULL OR p.Status<>'recorded' OR p.UserID<>o.UserID OR ROUND(p.Amount*1000)<>o.AmountMinor)")->fetch_assoc()['n'];
    $refundDue=(int)$db->query("SELECT COUNT(*) n FROM payment_orders WHERE State IN ('unknown','refund_due')")->fetch_assoc()['n'];
    $books=$db->query("SELECT j.Book,COALESCE(SUM(CASE WHEN e.AccountKey='platform_commission' THEN -e.SignedMinor ELSE 0 END),0) commission_revenue_minor,COALESCE(SUM(CASE WHEN e.AccountKey LIKE 'owner_payable:%' THEN -e.SignedMinor ELSE 0 END),0) owner_payable_minor,COALESCE(SUM(CASE WHEN e.AccountKey LIKE 'cash_commission_due:%' THEN e.SignedMinor ELSE 0 END),0) cash_commission_due_minor FROM financial_entries e JOIN financial_journals j ON j.JournalID=e.JournalID GROUP BY j.Book ORDER BY j.Book")->fetch_all(MYSQLI_ASSOC);
    $refundUnknown=(int)$db->query("SELECT COUNT(*) n FROM provider_refunds WHERE State IN ('submitting','unknown')")->fetch_assoc()['n'];
    $multipleRefunds=(int)$db->query("SELECT COUNT(*) n FROM (SELECT OrderID FROM payment_evidence WHERE TransactionType='refund' AND ResultCode='A' GROUP BY OrderID HAVING COUNT(DISTINCT ProviderReference)>1) q")->fetch_assoc()['n'];
    return ['currency'=>'JOD','minor_unit_exponent'=>3,'unbalanced_or_incomplete_journals'=>$broken,'verified_booking_receipt_mismatches'=>$mismatch,'unproven_legacy_recorded_payments'=>$unproven,'orders_requiring_review'=>$refundDue,'refunds_with_unknown_outcome'=>$refundUnknown,'orders_with_multiple_provider_refunds'=>$multipleRefunds,'orders'=>$orders,'balances_by_book'=>$books,'provider_clearing_minor'=>clevo_finance_balance($db,'provider_clearing'),'external_bank_settlement_reconciled'=>false,'production_payments_enabled'=>false];
}
function clevo_finance_owner_summary(mysqli $db,int $owner):array
{
    return ['currency'=>'JOD','minor_unit_exponent'=>3,'sandbox_payable_minor'=>-clevo_finance_balance($db,'owner_payable:'.$owner),'cash_commission_due_minor'=>clevo_finance_balance($db,'cash_commission_due:'.$owner),'production_settlements_enabled'=>false];
}
function clevo_finance_settlement_request(mysqli $db,int $admin,int $owner,int $minor):array
{
    $a=clevo_booking_actor($db,$admin);if(clevo_role($a['Role'])!=='admin')throw new ClevoSecurityException('forbidden',403);
    $recipient=clevo_account($db,$owner,true);if(!$recipient||clevo_role($recipient['Role'])!=='owner')throw new ClevoSecurityException('not_found',404);
    $available=-clevo_finance_balance($db,'owner_payable:'.$owner);
    if($minor<1||$minor>$available)throw new ClevoSecurityException('insufficient_settlement_payable',409);
    if(clevo_stmt($db,"SELECT SettlementID FROM financial_settlements WHERE OwnerID=? AND State IN ('blocked','pending') LIMIT 1 FOR UPDATE",'i',[$owner])->get_result()->fetch_assoc())throw new ClevoSecurityException('settlement_request_exists',409);
    clevo_stmt($db,"INSERT INTO financial_settlements (OwnerID,AmountMinor,State) VALUES (?,?,'blocked')",'ii',[$owner,$minor]);$id=(int)$db->insert_id;clevo_db_audit($db,$admin,'settlement.blocked_request','financial_settlement',$id);
    return ['settlement_id'=>$id,'state'=>'blocked','blocker'=>'provider_marketplace_contract_not_approved','funds_transferred'=>false];
}
