<?php
declare(strict_types=1);
require __DIR__.'/admin-helpers.php';
$password='AdminFinance!'.ad_key();$admin=ad_actor('Admin',$password);$customer=ad_actor('customer',$password);$owner=ad_actor('owner',$password);
$station=ad_station($owner);$service=clevo_market_service($db,$owner,$station,0,['name_ar'=>'غسيل مالي','name_en'=>'Report fixture','price'=>'1.25','duration'=>30,'is_station'=>true]);
$zone=new DateTimeZone('Asia/Amman');$today=(new DateTimeImmutable('now',$zone))->format('Y-m-d');
[$start,$end]=clevo_admin_period('2026-11-16','2026-11-16');
check($start==='2026-11-15 21:00:00'&&$end==='2026-11-16 21:00:00','Local report day is a UTC half-open interval');
rejects(fn()=>clevo_admin_period('2026-02-30','2026-03-01'),'Impossible report date rejected','invalid_report_date');
rejects(fn()=>clevo_admin_period('2026-12-01','2026-11-01'),'Reversed report range rejected','invalid_report_date');
rejects(fn()=>clevo_admin_financial_report($db,$today,$today,'production'),'Unsupported financial book rejected','invalid_financial_book');
$before=clevo_admin_financial_report($db,$today,$today,'cash');
$cash=ad_booking($customer,$station,$service);clevo_accept_owner_booking($db,$owner,$cash);clevo_pay_booking($db,$customer,$cash,'cash',false,ad_key());
check(clevo_admin_payment_state($db,$cash)==='unverified','Selecting cash alone never displays paid');
check(clevo_admin_financial_report($db,$today,$today,'cash')['totals']===$before['totals'],'Cash selection creates no recognized income');
clevo_complete_owner_booking($db,$owner,$cash);clevo_complete_owner_booking($db,$owner,$cash);
check(clevo_admin_payment_state($db,$cash)==='cash_attested','Owner completion is explicitly labeled a cash attestation');
$after=clevo_admin_financial_report($db,$today,$today,'cash');
check($after['totals']['recognized_bookings']-$before['totals']['recognized_bookings']===1,'Repeated completion counts one recognized booking');
check($after['totals']['recognized_gross_minor']-$before['totals']['recognized_gross_minor']===1250,'Gross uses one header per journal without multiplying joined lines');
check($after['totals']['commission_minor']-$before['totals']['commission_minor']===63,'Report uses canonical 5% commission rounded to JOD fils');
check($after['totals']['cash_commission_movement_minor']-$before['totals']['cash_commission_movement_minor']===63,'Cash commission is receivable rather than provider cash');
check($after['totals']['owner_payable_movement_minor']===$before['totals']['owner_payable_movement_minor'],'Cash payment creates no sandbox owner payable');
check(array_sum(array_column($after['daily'],'gross_minor'))===$after['totals']['recognized_gross_minor'],'Daily chart agrees with recognized gross card');
check(array_sum(array_column($after['daily'],'commission_minor'))===$after['totals']['commission_minor'],'Daily commission series agrees with card');
check(clevo_admin_financial_report($db,'2099-01-01','2099-01-02','cash')['totals']['recognized_gross_minor']===0,'Chart and cards exclude transactions outside the selected period');
check(clevo_admin_dashboard_stats($db)['cash_commission_today_minor']===$after['totals']['commission_minor'],'Dashboard statistic uses the same financial basis');
$unproven=ad_booking($customer,$station,$service);
clevo_stmt($db,"UPDATE booking SET Status='Completed',Total_Price=999999,Admin_Commission=99999,Payment_Method='visa' WHERE BookingID=?",'i',[$unproven]);
clevo_stmt($db,"INSERT INTO payments (BookingID,UserID,Method,Status,Amount) VALUES (?,?,'visa','recorded',999999)",'ii',[$unproven,$customer]);
check(clevo_admin_payment_state($db,$unproven)==='unverified','Legacy recorded row without evidence stays unverified');
check(clevo_admin_financial_report($db,$today,$today,'cash')['totals']===$after['totals'],'Unverified completed booking does not inflate cards or charts');
// The injected provider is a local test double; no network call or real payment occurs.
$provider=new class implements ClevoPaymentProvider{
    public function create(array $order,array $customer):array{return ['reference'=>'TST'.bin2hex(random_bytes(12)),'url'=>'https://secure-jordan.paytabs.com/payment/local-test'];}
    public function query(array $order):array{throw new LogicException('No external provider');}
    public function refund(array $order):array{throw new LogicException('No external provider');}
    public function verify(string $body,string $signature):array{throw new LogicException('No external provider');}
};
$sandboxBefore=clevo_admin_financial_report($db,$today,$today,'sandbox');
$online=ad_booking($customer,$station,$service);clevo_accept_owner_booking($db,$owner,$online);
$oldUrl=getenv('APP_URL');putenv('APP_URL=https://app.example.test');
try{$checkout=clevo_checkout_create($db,$customer,['purpose'=>'booking','booking_id'=>$online],ad_key(),$provider);}finally{putenv('APP_URL='.$oldUrl);}
$orderID=$checkout['payment_order']['OrderID'];$order=clevo_payment_order($db,$customer,$orderID);
check(clevo_admin_payment_state($db,$online)==='unverified','Pending checkout never displays verified payment');
$event=['order'=>$orderID,'type'=>'sale','status'=>'A','reference'=>$order['ProviderReference'],'amount'=>1250];
clevo_payment_apply_evidence($db,$event,hash('sha256',ad_key()),'authenticated_query');
check(clevo_admin_payment_state($db,$online)==='verified_sandbox','Evidence-backed sandbox receipt is labeled sandbox');
check(clevo_admin_financial_report($db,$today,$today,'sandbox')['totals']['commission_minor']===$sandboxBefore['totals']['commission_minor'],'Capture defers platform commission until completion');
clevo_complete_owner_booking($db,$owner,$online);
$sandboxEarned=clevo_admin_financial_report($db,$today,$today,'sandbox');
check($sandboxEarned['totals']['recognized_gross_minor']-$sandboxBefore['totals']['recognized_gross_minor']===1250,'Sandbox recognition appears exactly once');
check($sandboxEarned['totals']['commission_minor']-$sandboxBefore['totals']['commission_minor']===63,'Sandbox commission agrees with shared calculation');
$summary=clevo_finance_owner_summary($db,$owner);check($summary['sandbox_payable_minor']===1187,'Owner statement retains all three JOD decimal places');
$settlement=clevo_admin_command($db,$admin,'settlement_request',0,['owner_id'=>$owner,'amount'=>'1.187']);
check($settlement['state']==='blocked'&&!$settlement['funds_transferred'],'Settlement request never claims a bank transfer');
check(clevo_finance_owner_summary($db,$owner)===$summary,'Blocked settlement does not subtract owner earnings');
$refund=['order'=>$orderID,'type'=>'refund','status'=>'A','reference'=>'TSTREF'.substr(ad_key(),0,12),'parent'=>$order['ProviderReference'],'amount'=>1250];
clevo_payment_apply_evidence($db,$refund,hash('sha256',ad_key()),'authenticated_query');
$sandboxAfter=clevo_admin_financial_report($db,$today,$today,'sandbox');
check($sandboxAfter['totals']['commission_minor']===$sandboxBefore['totals']['commission_minor'],'Verified refund reverses recognized platform commission');
check($sandboxAfter['totals']['refunded_minor']-$sandboxBefore['totals']['refunded_minor']===1250,'Verified refund appears separately in reports');
check(clevo_finance_owner_summary($db,$owner)['sandbox_payable_minor']===0,'Verified refund reverses owner payable');
check(clevo_admin_payment_state($db,$online)==='refunded_sandbox','Refund label requires verified provider result');
clevo_payment_apply_evidence($db,$refund,hash('sha256',ad_key()),'authenticated_query');
check(clevo_admin_financial_report($db,$today,$today,'sandbox')['totals']===$sandboxAfter['totals'],'Refund evidence replay cannot double-reverse report');
check(clevo_admin_financial_report($db,$today,$today,'cash')['totals']===$after['totals'],'Sandbox capture, earnings and refund never affect cash report');
check(clevo_finance_report($db)['unbalanced_or_incomplete_journals']===0,'Financial journals remain balanced after admin phase workflows');
// Browser, mobile admin report and CSV export use the same period/book service.
[$jar,$csrf]=ad_login($admin,$password);$token=clevo_token_pair($db,clevo_account($db,$admin))['access_token'];
ad_expect(200,ad_http('GET','/admin/financial-reports.php?from=2099-01-01&to=2099-01-02&book=cash',null,$jar),'Empty financial periods render zero records without a PHP error');
$uri='/api/v1/admin/financial-reports?'.http_build_query(['from'=>$today,'to'=>$today,'book'=>'cash']);
$api=ad_expect(200,ad_http('GET',$uri,null,null,$token),'Live admin report API responds');
check(($api['report']['totals']??[])===$after['totals'],'API totals exactly match shared database report');
$web=ad_http('GET','/admin/financial-reports.php?'.http_build_query(['from'=>$today,'to'=>$today,'book'=>'cash']),null,$jar);
ad_expect(200,$web,'Filtered financial web report renders');check(ad_inline_check($web[2]),'Financial chart JavaScript parses');
check(str_contains($web[2],number_format($after['totals']['commission_minor']/1000,3)),'Web report shows three-decimal commission');
$csv=ad_http('POST','/admin/export.php',['from'=>$today,'to'=>$today,'book'=>'cash','csrf_token'=>$csrf],$jar);
ad_expect(200,$csv,'Authorized CSV export succeeds');check(str_contains($csv[3],'Content-Disposition: attachment;'),'Export downloads as an attachment');
$lines=preg_split('/\r?\n/',substr($csv[2],3));$header=str_getcsv($lines[0],',','"','');$values=str_getcsv($lines[1],',','"','');$export=array_combine($header,$values);
check((int)$export['commission_minor']===$after['totals']['commission_minor']&&(int)$export['recognized_gross_minor']===$after['totals']['recognized_gross_minor'],'CSV totals match chart and API without the UI history limit');
check(!str_contains($csv[2],clevo_account($db,$customer)['Email']),'Financial export contains no customer contact information');
check(str_contains(strtolower($csv[3]),'no-store'),'Sensitive export disables browser caching');
foreach(['=SUM(A1:A2)','+cmd','-2+3','@SUM(A1)','  =1',"\t=1","\r=1"] as $formula)check(str_starts_with(clevo_admin_csv_cell($formula),"'"),'CSV formula injection is neutralized');
check(clevo_admin_csv_cell('غسيل عربي')==='غسيل عربي','CSV keeps Arabic text intact');
check(clevo_admin_csv_cell(-63)==='-63','Negative commission reversals remain numeric integer fils in CSV');
check(clevo_admin_csv_cell('-63')==="'-63",'Untrusted negative-looking text remains formula-protected');
foreach(['ar','en'] as $lang){
    $print=ad_http('POST','/admin/export.php',['from'=>$today,'to'=>$today,'book'=>'cash','csrf_token'=>$csrf,'format'=>'print','lang'=>$lang],$jar);
    ad_expect(200,$print,'Authorized browser PDF print view renders '.$lang);
    check(str_contains($print[2],'lang="'.$lang.'"')&&str_contains($print[2],number_format($after['totals']['commission_minor']/1000,3)),'Print view preserves language and report totals');
    check(ad_inline_check($print[2]),'Print view JavaScript parses');
}
check((int)clevo_stmt($db,"SELECT COUNT(*) n FROM audit_logs WHERE ActorID=? AND Action LIKE 'admin.export.csv:%'",'i',[$admin])->get_result()->fetch_assoc()['n']===1,'CSV export records actor, date range and book');
ad_expect(422,ad_http('POST','/admin/export.php',['from'=>'2026-13-01','to'=>$today,'book'=>'cash','csrf_token'=>$csrf],$jar),'Export revalidates dates on the server');

// A second connection may commit while a report is read: its cards/chart must share a snapshot.
$concurrent=ad_booking($customer,$station,$service);clevo_accept_owner_booking($db,$owner,$concurrent);clevo_pay_booking($db,$customer,$concurrent,'cash',false,ad_key());
$writer=clevo_connect_database();
$db->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
try{
    $pair=clevo_admin_read_snapshot($db,function()use($db,$writer,$owner,$concurrent,$today):array{
        $first=clevo_admin_financial_report($db,$today,$today,'cash');
        clevo_complete_owner_booking($writer,$owner,$concurrent);
        return [$first,clevo_admin_financial_report($db,$today,$today,'cash')];
    });
    check($pair[0]===$pair[1],'Concurrent ledger commit cannot change an open report snapshot');
    $fresh=clevo_admin_financial_report($db,$today,$today,'cash');
    check($fresh['totals']['commission_minor']-$pair[0]['totals']['commission_minor']===63,'Next report sees the newly committed canonical commission');
    check(array_sum(array_column($fresh['daily'],'commission_minor'))===$fresh['totals']['commission_minor'],'Streamed chart remains consistent with cards after concurrent posting');
}finally{$db->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$writer->close();}
echo "Only isolated test fixtures and a local payment provider double were used.\n";
finish_tests('Admin financial report and export suite');
