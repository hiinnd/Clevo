<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/config.php';
try{
    if(in_array('--query-provider',$argv,true)){
        $provider=clevo_payment_provider();$rows=$conn->query("SELECT * FROM payment_orders WHERE State IN ('creating','pending','unknown') ORDER BY UpdatedAt LIMIT 10")->fetch_all(MYSQLI_ASSOC);
        foreach($rows as $row){
            clevo_rate_limit($conn,'payment:provider-query',empty($row['ProviderReference'])?'cart':'reference',empty($row['ProviderReference'])?15:45,60);
            clevo_payment_reconcile_order($conn,$row,$provider);
        }
        $refunds=$conn->query("SELECT o.*,r.ProviderReference AS RefundReference FROM provider_refunds r JOIN payment_orders o ON o.OrderID=r.OrderID WHERE r.State IN ('pending','unknown') AND r.ProviderReference IS NOT NULL ORDER BY r.UpdatedAt LIMIT 5")->fetch_all(MYSQLI_ASSOC);
        foreach($refunds as $row){$row['ProviderReference']=$row['RefundReference'];clevo_rate_limit($conn,'payment:provider-query','reference',45,60);clevo_payment_reconcile_order($conn,$row,$provider);}
        $refundID=null;$reference=null;
        foreach($argv as $arg){if(preg_match('/^--refund-id=([1-9][0-9]*)$/D',$arg,$m))$refundID=(int)$m[1];if(preg_match('/^--provider-ref=(TST[A-Za-z0-9_-]{5,147})$/D',$arg,$m))$reference=$m[1];}
        if($refundID!==null||$reference!==null){
            if(!$refundID||!$reference)throw new RuntimeException('Both reconciliation identifiers required.');
            $record=clevo_stmt($conn,'SELECT o.* FROM provider_refunds r JOIN payment_orders o ON o.OrderID=r.OrderID WHERE r.RefundID=?','i',[$refundID])->get_result()->fetch_assoc();
            if(!$record)throw new RuntimeException('Unknown refund.');$record['ProviderReference']=$reference;
            // An operator-provided reference is only a query selector, never settlement evidence.
            clevo_rate_limit($conn,'payment:provider-query','reference',45,60);
            clevo_payment_reconcile_order($conn,$record,$provider);
        }
    }
    echo json_encode(clevo_finance_report($conn),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $e){fwrite(STDERR,"Payment reconciliation incomplete; inspect protected operational records. No credentials or provider payload emitted.\n");exit(1);}
