<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('zend.exception_ignore_args','1');
require_once __DIR__.'/../includes/security/environment.php';
require_once __DIR__.'/../includes/privacy.php';
clevo_load_environment();$checks=[];
try{clevo_validate_environment();$checks['required_environment']=true;}catch(Throwable){$checks['required_environment']=false;}
$checks['secure_environment']=clevo_secure_environment();
$envFile=getenv('CLEVO_ENV_FILE');$envPath=$envFile?realpath($envFile):false;
$checks['private_environment_file']=$envPath&&!clevo_path_within($envPath,realpath(dirname(__DIR__))?:dirname(__DIR__));
$host=parse_url(clevo_env('APP_URL','')??'',PHP_URL_HOST)?:'';
$checks['approved_public_domain']=$host!==''&&!preg_match('/(?:\.invalid|\.test|\.example|\.localhost|\.example\.com)$/i',$host)&&!in_array($host,['localhost','example.com'],true)&&!filter_var($host,FILTER_VALIDATE_IP);
$checks['patched_runtime_review']=PHP_VERSION_ID>=80400&&clevo_env('RUNTIME_PATCH_REVIEW_APPROVED','0')==='1';
$checks['private_health_token']=(bool)preg_match('/^[a-f0-9]{64}$/D',clevo_env('OPS_HEALTH_TOKEN','')??'');
$checks['worker_monitoring']=clevo_env('OPS_REQUIRE_WORKERS','0')==='1';
$checks['backup_monitoring']=clevo_env('OPS_REQUIRE_BACKUP','0')==='1';
$checks['online_money_disabled']=clevo_env('PAYMENT_MODE','disabled')==='disabled'&&clevo_env('PAYMENT_PROVIDER','disabled')==='disabled';
$policy=clevo_legal_metadata();
$checks['legal_policy_review']=$policy['approved']&&filter_var($policy['support_email'],FILTER_VALIDATE_EMAIL)!==false&&$policy['processing_days']>0&&$policy['processing_days']<=90&&$policy['retention_description_ar']!==''&&$policy['retention_description_en']!=='';
$checks['deletion_workflow_review']=clevo_env('ACCOUNT_DELETION_ENABLED','0')==='1'&&clevo_env('ERASURE_RETENTION_REVIEW_APPROVED','0')==='1';
$checks['outbound_setup_review']=clevo_env('OUTBOUND_DELIVERY_REVIEW_APPROVED','0')==='1'&&clevo_env('NOTIFICATION_EXTERNAL_SEND','0')==='1';
$file=clevo_env('FCM_SERVICE_ACCOUNT_FILE','')??'';$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
$checks['fcm_project_configuration']=false;
if(clevo_env('FCM_ENABLED','0')==='1'&&is_file($file)&&!clevo_path_within(realpath($file)?:$file,$root)){
    try{$account=json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$checks['fcm_project_configuration']=!empty($account['project_id'])&&hash_equals(clevo_env('FCM_EXPECTED_PROJECT_ID','')??'',$account['project_id']);}catch(Throwable){}
}
$checks['maintenance_disabled']=clevo_env('MAINTENANCE_MODE','0')==='0';
$checks['recovery_rehearsal_review']=clevo_env('RESTORE_REHEARSAL_APPROVED','0')==='1';
echo json_encode(['ready'=>!in_array(false,$checks,true),'checks'=>$checks,'note'=>'Configuration review only; verify live infrastructure, authorization, restore and delivery separately.'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
exit(in_array(false,$checks,true)?1:0);
