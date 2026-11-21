<?php
declare(strict_types=1);

function clevo_admin_period(string $from,string $to):array
{
    foreach([$from,$to] as $date){
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new ClevoSecurityException('invalid_report_date',422);
    }
    if($from>$to)throw new ClevoSecurityException('invalid_report_date',422);
    $zone=new DateTimeZone(clevo_env('BOOKING_TIMEZONE','Asia/Amman'));
    $start=new DateTimeImmutable($from,$zone);$end=(new DateTimeImmutable($to,$zone))->modify('+1 day');
    return [$start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),$end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
}

/** Keep cards, chart and export on one committed ledger snapshot, even under READ COMMITTED. */
function clevo_admin_read_snapshot(mysqli $db,callable $work):array
{
    $depths=&clevo_db_depths();
    if(($depths[$db]??0)>0)return $work();
    $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    $depths[$db]=1;
    try{$result=$work();$db->commit();return $result;}
    catch(Throwable $error){$db->rollback();throw $error;}
    finally{$depths[$db]=0;}
}

function clevo_admin_financial_report(mysqli $db,string $from,string $to,string $book='cash',bool $totalsOnly=false):array
{
    return clevo_admin_read_snapshot($db,fn()=>clevo_admin_financial_read($db,$from,$to,$book,$totalsOnly));
}

function clevo_admin_financial_read(mysqli $db,string $from,string $to,string $book,bool $totalsOnly):array
{
    if(!in_array($book,['cash','sandbox'],true))throw new ClevoSecurityException('invalid_financial_book',422);
    [$start,$end]=clevo_admin_period($from,$to);
    // Aggregate each journal once: joining header amounts directly to lines multiplies GMV.
    $lines="SELECT j.JournalID,j.BookingID,j.Kind,j.Book,j.CreatedAt,j.GrossMinor,
        SUM(CASE WHEN e.AccountKey='platform_commission' THEN -e.SignedMinor ELSE 0 END) commission_minor,
        SUM(CASE WHEN e.AccountKey LIKE 'owner_payable:%' THEN -e.SignedMinor ELSE 0 END) payable_minor,
        SUM(CASE WHEN e.AccountKey LIKE 'cash_commission_due:%' THEN e.SignedMinor ELSE 0 END) cash_due_minor
        FROM financial_journals j JOIN financial_entries e ON e.JournalID=j.JournalID
        WHERE j.CreatedAt>=? AND j.CreatedAt<? AND j.Book=?
        GROUP BY j.JournalID,j.BookingID,j.Kind,j.Book,j.CreatedAt,j.GrossMinor";
    $totals=clevo_stmt($db,"SELECT COUNT(CASE WHEN Kind IN ('earnings','cash_commission') THEN 1 END) recognized_bookings,
        COALESCE(SUM(CASE WHEN Kind IN ('earnings','cash_commission') THEN GrossMinor ELSE 0 END),0) recognized_gross_minor,
        COALESCE(SUM(CASE WHEN Kind='refund' THEN GrossMinor ELSE 0 END),0) refunded_minor,
        COALESCE(SUM(commission_minor),0) commission_minor,COALESCE(SUM(payable_minor),0) owner_payable_movement_minor,
        COALESCE(SUM(cash_due_minor),0) cash_commission_movement_minor FROM ($lines) q",'sss',[$start,$end,$book])->get_result()->fetch_assoc();
    foreach($totals as &$value)$value=(int)$value;unset($value);
    if($totalsOnly)return ['totals'=>$totals];
    // Stream journal rows instead of materializing the entire period in PHP memory.
    $daily=clevo_stmt($db,"SELECT CreatedAt,Kind,GrossMinor,commission_minor FROM ($lines) q ORDER BY CreatedAt,JournalID",'sss',[$start,$end,$book]);
    $daily->bind_result($createdAt,$kind,$grossMinor,$commissionMinor);
    $days=[];$zone=new DateTimeZone(clevo_env('BOOKING_TIMEZONE','Asia/Amman'));
    while($daily->fetch()){$day=(new DateTimeImmutable($createdAt,new DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d');
        $days[$day]??=['date'=>$day,'gross_minor'=>0,'commission_minor'=>0,'refund_minor'=>0];
        if(in_array($kind,['earnings','cash_commission'],true))$days[$day]['gross_minor']+=(int)$grossMinor;
        if($kind==='refund')$days[$day]['refund_minor']+=(int)$grossMinor;
        $days[$day]['commission_minor']+=(int)$commissionMinor;
    }
    $daily->close();
    $top=clevo_stmt($db,"SELECT s.Name,SUM(q.GrossMinor)/1000 revenue FROM ($lines) q JOIN booking b ON b.BookingID=q.BookingID JOIN stations s ON s.StationID=b.StationID WHERE q.Kind IN ('earnings','cash_commission') GROUP BY s.StationID,s.Name ORDER BY revenue DESC,s.StationID LIMIT 3",'sss',[$start,$end,$book])->get_result()->fetch_all(MYSQLI_ASSOC);
    $history=clevo_stmt($db,"SELECT q.JournalID,q.BookingID,q.CreatedAt Booking_Date,u.UserName,COALESCE(q.GrossMinor,0)/1000 Total_Price,q.commission_minor/1000 Admin_Commission,q.Kind Status FROM ($lines) q LEFT JOIN booking b ON b.BookingID=q.BookingID LEFT JOIN user u ON u.UserID=b.UserID ORDER BY q.CreatedAt DESC,q.JournalID DESC LIMIT 50",'sss',[$start,$end,$book])->get_result()->fetch_all(MYSQLI_ASSOC);
    return ['currency'=>'JOD','minor_unit_exponent'=>3,'book'=>$book,'from'=>$from,'to'=>$to,'timezone'=>$zone->getName(),'totals'=>$totals,'daily'=>array_values($days),'top_stations'=>$top,'history'=>$history,'external_bank_settlement_reconciled'=>false];
}

function clevo_admin_payment_state(mysqli $db,int $booking):string
{
    $row=clevo_stmt($db,"SELECT r.EvidenceKind,j.Kind,j.Book,o.State,o.VerifiedAt,
        EXISTS(SELECT 1 FROM provider_refunds pr WHERE pr.OrderID=o.OrderID AND pr.State='succeeded') refund_verified
        FROM financial_receipts r JOIN booking b ON b.BookingID=r.BookingID AND b.UserID=r.UserID
        JOIN financial_journals j ON j.JournalID=r.JournalID AND j.GrossMinor=r.AmountMinor
        LEFT JOIN payment_orders o ON o.OrderID=r.OrderID AND o.UserID=r.UserID AND o.BookingID=r.BookingID
        WHERE r.BookingID=? ORDER BY r.ReceiptID DESC LIMIT 1",'i',[$booking])->get_result()->fetch_assoc();
    if($row){
        if($row['EvidenceKind']==='verified_paytabs_sandbox_refund'&&$row['Kind']==='refund'&&$row['Book']==='sandbox'&&$row['State']==='refunded'&&$row['VerifiedAt']&&$row['refund_verified'])return 'refunded_sandbox';
        if($row['EvidenceKind']==='owner_cash_attestation'&&$row['Kind']==='cash_commission'&&$row['Book']==='cash')return 'cash_attested';
        if($row['EvidenceKind']==='signed_or_queried_paytabs_sandbox'&&$row['Kind']==='capture'&&$row['Book']==='sandbox'&&$row['VerifiedAt']&&$row['State']==='succeeded')return 'verified_sandbox';
        if($row['State']==='refund_due'&&$row['VerifiedAt'])return 'refund_due_sandbox';
    }
    $plan=clevo_stmt($db,"SELECT r.State FROM subscription_redemptions r JOIN user_plans up ON up.id=r.UserPlanID JOIN booking b ON b.BookingID=r.BookingID AND b.UserID=up.user_id JOIN payment_orders o ON o.OrderID=up.FundingOrderID AND o.UserID=up.user_id WHERE r.BookingID=? AND r.State IN ('reserved','completed') AND o.State='succeeded' AND o.VerifiedAt IS NOT NULL AND up.PaymentHold=0",'i',[$booking])->get_result()->fetch_assoc();
    return $plan?'verified_subscription_sandbox':'unverified';
}

function clevo_admin_csv_cell(mixed $value):string
{
    // Ledger amounts are trusted integer fils, including legitimate negative reversals.
    // Untrusted text still receives formula-injection protection below.
    if(is_int($value))return (string)$value;
    $text=(string)($value??'');
    // Spreadsheet formula injection includes leading whitespace and tab/newline prefixes.
    return preg_match('/^[\s\x00-\x1F]*[=+@-]|^[\t\r\n]/u',$text)?"'".$text:$text;
}

function clevo_admin_dashboard_stats(mysqli $db):array
{
    return clevo_admin_read_snapshot($db,fn()=>clevo_admin_dashboard_read($db));
}

function clevo_admin_dashboard_read(mysqli $db):array
{
    $zone=new DateTimeZone(clevo_env('BOOKING_TIMEZONE','Asia/Amman'));
    $today=(new DateTimeImmutable('now',$zone))->format('Y-m-d');[$start,$end]=clevo_admin_period($today,$today);
    $counts=clevo_stmt($db,"SELECT
        (SELECT COUNT(*) FROM stations WHERE admin_status='approved' AND ArchivedAt IS NULL) approved_stations,
        (SELECT COUNT(*) FROM stations WHERE admin_status='pending' AND ArchivedAt IS NULL) pending_station_approvals,
        (SELECT COUNT(*) FROM user WHERE LOWER(Role) IN ('customer','user')) customers,
        (SELECT COUNT(*) FROM booking WHERE created_at>=? AND created_at<?) bookings_created_today,
        (SELECT COALESCE(AVG(Rating),0) FROM reviews) average_rating",'ss',[$start,$end])->get_result()->fetch_assoc();
    $daily=clevo_admin_financial_report($db,$today,$today,'cash',true);
    $monthly=clevo_admin_financial_report($db,substr($today,0,7).'-01',$today,'cash',true);
    return $counts+['date'=>$today,'timezone'=>$zone->getName(),'currency'=>'JOD','cash_commission_today_minor'=>$daily['totals']['commission_minor'],'cash_commission_month_minor'=>$monthly['totals']['commission_minor']];
}
