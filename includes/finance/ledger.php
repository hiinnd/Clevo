<?php
declare(strict_types=1);

// Financial amounts are integer JOD fils. Legacy price/wallet amounts remain hundredths.
function clevo_finance_minor(mixed $amount): int
{
    if(is_float($amount)){
        if(!is_finite($amount))throw new ClevoSecurityException('invalid_amount',422);
        $amount=rtrim(rtrim(sprintf('%.10F',$amount),'0'),'.');
    }
    if(!is_int($amount)&&!is_string($amount))throw new ClevoSecurityException('invalid_amount',422);
    if(!preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,3}))?$/D',(string)$amount,$m))throw new ClevoSecurityException('invalid_amount',422);
    return (int)$m[1]*1000+(int)str_pad($m[2]??'',3,'0');
}
function clevo_finance_decimal(int $minor):string
{
    if($minor<0)throw new LogicException('Negative display amount.');
    return intdiv($minor,1000).'.'.str_pad((string)($minor%1000),3,'0',STR_PAD_LEFT);
}
function clevo_finance_commission(int $basis):int
{
    if($basis<0||$basis>99999999999)throw new ClevoSecurityException('invalid_amount',422);
    // Approved 2026-11-16: 500 basis points on services + fees AFTER discounts.
    return intdiv($basis*500+5000,10000);
}
function clevo_finance_snapshot(mysqli $db,int $booking,int $basis):void
{
    $commission=clevo_finance_commission($basis);$owner=$basis-$commission;
    clevo_stmt($db,'UPDATE booking SET CommissionRateBps=500,CommissionBasisMinor=?,CommissionMinor=?,OwnerAmountMinor=?,Admin_Commission=? WHERE BookingID=? AND FinancialVersion=1','iiisi',[$basis,$commission,$owner,clevo_db_decimal(intdiv($commission+5,10)),$booking]);
}
function clevo_finance_fixture_allowed(mysqli $db):bool
{
    if(clevo_env('APP_ENV')!=='testing'||clevo_env('CLEVO_SYNTHETIC_FINANCE')!=='1')return false;
    if(!preg_match('/^clevo_(security|database)_test_[a-f0-9]{12}$/D',clevo_env('DB_DATABASE','')??''))return false;
    $expected=realpath(dirname(__DIR__,2).'/.security-test/mysql');
    $actual=realpath($db->query('SELECT @@datadir d')->fetch_assoc()['d']);
    return $expected&&$actual&&strcasecmp($expected,$actual)===0;
}
function clevo_finance_require_legacy_fixture(mysqli $db,string $reason='wallet_regulatory_review_required'):void
{
    if(!clevo_finance_fixture_allowed($db))throw new ClevoSecurityException($reason,503);
}
function clevo_finance_post(mysqli $db,string $key,string $kind,string $book,array $entries,array $meta=[]):int
{
    if(!in_array($book,['sandbox','cash','legacy_fixture'],true)||!preg_match('/^[A-Za-z0-9:_-]{1,150}$/D',$key))throw new LogicException('Invalid journal identity.');
    $debit=0;$credit=0;
    foreach($entries as $account=>$minor){
        if(!preg_match('/^[a-z0-9:_-]{1,100}$/D',$account)||!is_int($minor))throw new LogicException('Invalid journal line.');
        if($minor>0)$debit+=$minor;else $credit-=$minor;
    }
    if($debit!==$credit||$debit<=0)throw new LogicException('Unbalanced journal.');
    $sortedEntries=$entries;$sortedMeta=$meta;ksort($sortedEntries);ksort($sortedMeta);$content=hash('sha256',json_encode([$kind,$book,$sortedEntries,$sortedMeta],JSON_THROW_ON_ERROR));
    return clevo_db_atomic($db,function()use($db,$key,$kind,$book,$entries,$meta,$debit,$credit,$content):int {
        $old=clevo_stmt($db,'SELECT JournalID,Kind,Book,ContentHash,DebitMinor,CreditMinor FROM financial_journals WHERE OperationKey=? FOR UPDATE','s',[$key])->get_result()->fetch_assoc();
        if($old){
            $lines=clevo_stmt($db,'SELECT AccountKey,SignedMinor FROM financial_entries WHERE JournalID=? ORDER BY AccountKey','i',[(int)$old['JournalID']])->get_result()->fetch_all(MYSQLI_ASSOC);$actual=[];
            foreach($lines as $line)$actual[$line['AccountKey']]=(int)$line['SignedMinor'];$expected=array_filter($entries,fn($v)=>$v!==0);ksort($expected);ksort($actual);
            if(!hash_equals($old['ContentHash'],$content)||$old['Kind']!==$kind||$old['Book']!==$book||(int)$old['DebitMinor']!==$debit||$actual!==$expected)throw new ClevoSecurityException('financial_operation_conflict',409);
            return (int)$old['JournalID'];
        }
        clevo_stmt($db,'INSERT INTO financial_journals (OperationKey,Kind,Book,ContentHash,BookingID,OrderID,OwnerID,GrossMinor,CommissionMinor,OwnerMinor,DebitMinor,CreditMinor) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)','ssssisi' .'iiiii',[$key,$kind,$book,$content,$meta['booking']??null,$meta['order']??null,$meta['owner']??null,$meta['gross']??null,$meta['commission']??null,$meta['owner_minor']??null,$debit,$credit]);$id=(int)$db->insert_id;
        foreach($entries as $account=>$minor)if($minor!==0)clevo_stmt($db,'INSERT INTO financial_entries (JournalID,AccountKey,SignedMinor) VALUES (?,?,?)','isi',[$id,$account,$minor]);
        return $id;
    });
}
function clevo_finance_receipt(mysqli $db,int $journal,int $user,?int $booking,?string $order,int $amount,string $kind):void
{
    if(clevo_stmt($db,'SELECT ReceiptID FROM financial_receipts WHERE JournalID=?','i',[$journal])->get_result()->fetch_assoc())return;
    clevo_stmt($db,'INSERT INTO financial_receipts (ReceiptNumber,JournalID,UserID,BookingID,OrderID,AmountMinor,EvidenceKind) VALUES (?,?,?,?,?,?,?)','siiisis',['CLV-'.strtoupper(bin2hex(random_bytes(12))),$journal,$user,$booking,$order,$amount,$kind]);
}
function clevo_finance_balance(mysqli $db,string $account):int
{
    return (int)clevo_stmt($db,'SELECT COALESCE(SUM(SignedMinor),0) n FROM financial_entries WHERE AccountKey=?','s',[$account])->get_result()->fetch_assoc()['n'];
}
