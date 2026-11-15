<?php
declare(strict_types=1);

interface ClevoPaymentProvider
{
    public function create(array $order,array $customer):array;
    public function query(array $order):array;
    public function refund(array $order):array;
    public function verify(string $body,string $signature):array;
}

final class ClevoPayTabsSandbox implements ClevoPaymentProvider
{
    private const ORIGIN='https://secure-jordan.paytabs.com';
    public function __construct(private string $key,private int $profile,private int $merchant,private ?Closure $transport=null)
    {
        if(clevo_production()||clevo_env('PAYMENT_MODE','disabled')!=='sandbox')throw new ClevoSecurityException('payment_production_not_approved',503);
        if(strlen($key)<20||$profile<1||$merchant<1)throw new ClevoSecurityException('payment_gateway_not_configured',503);
    }
    private function request(string $path,array $payload):array
    {
        if($this->transport)return ($this->transport)($path,$payload);
        $buffer='';$h=curl_init(self::ORIGIN.$path);
        curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['authorization: '.$this->key,'Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function($h,string $chunk)use(&$buffer):int{if(strlen($buffer)+strlen($chunk)>65536)return 0;$buffer.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
        if($ok===false||$status<200||$status>=300)throw new ClevoSecurityException('payment_provider_outcome_unknown',503);
        try{$data=json_decode($buffer,true,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(Throwable $e){throw new ClevoSecurityException('payment_provider_outcome_unknown',503);}
        if(!is_array($data))throw new ClevoSecurityException('payment_provider_outcome_unknown',503);return $data;
    }
    public function create(array $order,array $customer):array
    {
        $url=clevo_public_url('api/v1/webhooks/paytabs');
        if(parse_url($url,PHP_URL_SCHEME)!=='https')throw new ClevoSecurityException('payment_callback_https_required',503);
        $response=$this->request('/payment/request',['profile_id'=>$this->profile,'tran_type'=>'sale','tran_class'=>'ecom','cart_id'=>$order['OrderID'],'cart_description'=>'CLEVO '.$order['Purpose'],'cart_currency'=>'JOD','cart_amount'=>clevo_finance_decimal((int)$order['AmountMinor']),'callback'=>$url,'return'=>clevo_public_url('api/v1/return.php'),'customer_details'=>['name'=>$customer['UserName'],'email'=>$customer['Email'],'phone'=>$customer['PhoneNumber'],'country'=>'JO']]);
        $ref=$response['tran_ref']??'';$checkout=$response['redirect_url']??'';$p=is_string($checkout)?parse_url($checkout):false;
        if(!is_string($ref)||!preg_match('/^TST[A-Za-z0-9_-]{5,147}$/D',$ref)||!is_array($p)||($p['scheme']??'')!=='https'||($p['host']??'')!=='secure-jordan.paytabs.com'||isset($p['user'])||isset($p['pass'])||isset($p['port'])||isset($p['fragment'])||!str_starts_with($p['path']??'','/payment/'))throw new ClevoSecurityException('payment_provider_outcome_unknown',503);
        return ['reference'=>$ref,'url'=>$checkout];
    }
    public function query(array $order):array
    {
        $params=['profile_id'=>$this->profile];$params[empty($order['ProviderReference'])?'cart_id':'tran_ref']=$order['ProviderReference']?:$order['OrderID'];
        $response=$this->request('/payment/query',$params);
        // A cart query can return several transactions: never guess which capture is ours.
        if(array_is_list($response)){
            if(count($response)!==1||!is_array($response[0]))throw new ClevoSecurityException('payment_reconciliation_required',409);
            $response=$response[0];
        }
        return $this->normalize($response);
    }
    public function refund(array $order):array
    {
        $response=$this->request('/payment/request',['profile_id'=>$this->profile,'tran_type'=>'refund','tran_class'=>'ecom','tran_ref'=>$order['ProviderReference'],'cart_id'=>$order['OrderID'],'cart_currency'=>'JOD','cart_amount'=>clevo_finance_decimal((int)$order['AmountMinor']),'cart_description'=>'CLEVO approved full refund']);
        return $this->normalize($response);
    }
    public function verify(string $body,string $signature):array
    {
        if(!preg_match('/^[a-fA-F0-9]{64}$/D',$signature)||!hash_equals(hash_hmac('sha256',$body,$this->key),strtolower($signature)))throw new ClevoSecurityException('invalid_webhook_signature',401);
        try{$data=json_decode($body,true,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(Throwable $e){throw new ClevoSecurityException('invalid_webhook_payload',422);}
        return $this->normalize($data);
    }
    private function normalize(mixed $data):array
    {
        if(!is_array($data)||!is_array($data['payment_result']??null))throw new ClevoSecurityException('invalid_provider_evidence',422);
        if((string)($data['profile_id']??$data['profileId']??'')!==(string)$this->profile||(string)($data['merchant_id']??$data['merchantId']??'')!==(string)$this->merchant)throw new ClevoSecurityException('payment_merchant_mismatch',422);
        $ref=$data['tran_ref']??'';$cart=$data['cart_id']??'';$type=strtolower((string)($data['tran_type']??''));$status=$data['payment_result']['response_status']??'';
        if($type===''&&$status!=='A')$type='unknown';
        if(!is_string($ref)||!preg_match('/^TST[A-Za-z0-9_-]{5,147}$/D',$ref)||!is_string($cart)||!preg_match('/^[a-f0-9]{48}$/D',$cart)||!in_array($type,['sale','refund','unknown'],true)||!in_array($status,['A','H','P','V','E','D','X','C'],true))throw new ClevoSecurityException('invalid_provider_evidence',422);
        if(($data['cart_currency']??'')!=='JOD'||($data['tran_currency']??'JOD')!=='JOD')throw new ClevoSecurityException('payment_currency_mismatch',422);
        $minor=clevo_finance_minor($data['cart_amount']??null);
        if($status==='A'&&clevo_finance_minor($data['tran_total']??null)!==$minor)throw new ClevoSecurityException('payment_amount_mismatch',422);
        return ['reference'=>$ref,'order'=>$cart,'type'=>$type,'status'=>$status,'amount'=>$minor,'parent'=>$data['previous_tran_ref']??null];
    }
}
function clevo_payment_provider():ClevoPaymentProvider
{
    if(clevo_env('PAYMENT_PROVIDER','disabled')!=='paytabs'||clevo_env('PAYTABS_TEST_PROFILE_CONFIRMED')!=='1')throw new ClevoSecurityException('payment_gateway_not_configured',503);
    return new ClevoPayTabsSandbox(clevo_env('PAYTABS_SERVER_KEY','')??'',(int)clevo_env('PAYTABS_PROFILE_ID','0'),(int)clevo_env('PAYTABS_MERCHANT_ID','0'));
}
