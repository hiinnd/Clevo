<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/config.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');throw new ClevoSecurityException('method_not_allowed',405);}
$from=is_string($_POST['from']??null)?$_POST['from']:'';
$to=is_string($_POST['to']??null)?$_POST['to']:'';
$book=is_string($_POST['book']??null)?$_POST['book']:'';
$format=$_POST['format']??'csv';
if(!in_array($format,['csv','print'],true))throw new ClevoSecurityException('invalid_export_format',422);
$report=clevo_admin_financial_report($conn,$from,$to,$book);
clevo_db_audit($conn,clevo_current_user_id(),'admin.export.'.$format.':'.$from.':'.$to.':'.$book,'financial_report',0);
if($format==='print'){
    header('Content-Type: text/html; charset=utf-8');
    $lang=($_POST['lang']??'ar')==='en'?'en':'ar';
    define('CLEVO_PRINT_REPORT',true);require __DIR__.'/../includes/admin-print-report.php';exit;
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="clevo-report-'.$from.'-'.$to.'-'.$book.'.csv"');
header('Cache-Control: no-store, private');
$stream=fopen('php://output','wb');fwrite($stream,"\xEF\xBB\xBF");
$write=static function(array $row)use($stream):void{fputcsv($stream,array_map('clevo_admin_csv_cell',$row),',','"','');};
$write(['row_type','date_or_period','book','currency','recognized_gross_minor','commission_minor','refund_minor','recognized_bookings','owner_payable_movement_minor','cash_commission_movement_minor']);
$t=$report['totals'];
$write(['totals',$from.' / '.$to,$book,'JOD',$t['recognized_gross_minor'],$t['commission_minor'],$t['refunded_minor'],$t['recognized_bookings'],$t['owner_payable_movement_minor'],$t['cash_commission_movement_minor']]);
foreach($report['daily'] as $day)$write(['daily',$day['date'],$book,'JOD',$day['gross_minor'],$day['commission_minor'],$day['refund_minor'],'','','']);
fclose($stream);
