<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
if(clevo_env('APP_ENV')!=='testing'||!preg_match('/^clevo_security_test_[a-f0-9]{12}$/D',clevo_env('DB_DATABASE','')??''))exit(2);
$db=clevo_connect_database();if(strcasecmp(realpath($db->query('SELECT @@datadir d')->fetch_assoc()['d'])?:'',realpath(__DIR__.'/../.security-test/mysql')?:'!')!==0)exit(2);
$job=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
while(microtime(true)<$job['start'])usleep(1000);
try{
    if($job['mode']==='webhook'){
        $event=clevo_payment_provider()->verify($job['body'],$job['signature']);
        $result=clevo_payment_apply_evidence($db,$event,hash('sha256',$job['body']),'signed_webhook');
    }elseif($job['mode']==='complete'){
        $result=clevo_complete_owner_booking($db,(int)$job['owner'],(int)$job['booking']);
    }else{
        $provider=new ClevoPayTabsSandbox(clevo_env('PAYTABS_SERVER_KEY'),(int)clevo_env('PAYTABS_PROFILE_ID'),(int)clevo_env('PAYTABS_MERCHANT_ID'),static function($path,$payload):array{
            $fp=fopen(__DIR__.'/../.security-test/payment-create-count','ab');flock($fp,LOCK_EX);fwrite($fp,"attempt\n");flock($fp,LOCK_UN);fclose($fp);usleep(200000);
            return ['tran_ref'=>'TST'.substr($payload['cart_id'],0,30),'redirect_url'=>'https://secure-jordan.paytabs.com/payment/page/'.$payload['cart_id']];
        });
        $reply=clevo_checkout_create($db,$job['user'],$job['input'],$job['key'],$provider);$result=['order_id'=>$reply['payment_order']['OrderID'],'state'=>$reply['payment_order']['State']];
    }
    echo json_encode(['ok'=>true,'result'=>$result]);
}catch(Throwable $e){echo json_encode(['ok'=>false,'reason'=>$e instanceof ClevoSecurityException?$e->reason:'financial_conflict']);}
