<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('zend.exception_ignore_args','1');
$checks=[];
try{
    if(!is_file($argv[1]??''))throw new RuntimeException('Public config file required.');
    $data=json_decode((string)file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($data))throw new RuntimeException('Public config required.');
    $checks['public_keys_only']=!array_diff(array_keys($data),['CLEVO_API_URL','CLEVO_FIREBASE','CLEVO_MAPS']);
    $uri=parse_url($data['CLEVO_API_URL']??'');$host=$uri['host']??'';
    $checks['distribution_api_url']=($uri['scheme']??'')==='https'&&$host!==''&&!isset($uri['user'])&&!isset($uri['pass'])&&!isset($uri['query'])&&!isset($uri['fragment'])&&str_ends_with($uri['path']??'','/api/v1/')&&!preg_match('/(?:\.invalid|\.test|\.example|\.localhost|\.example\.com)$/i',$host)&&!in_array($host,['localhost','example.com'],true)&&!filter_var($host,FILTER_VALIDATE_IP);
    $checks['firebase_enabled']=($data['CLEVO_FIREBASE']??false)===true;
    $checks['maps_enabled']=($data['CLEVO_MAPS']??false)===true;
    $checks['domain_and_identifier_review']=getenv('CLEVO_RELEASE_CONFIGURATION_APPROVED')==='1';
    $checks['android_private_signing']=is_file(__DIR__.'/../mobile/clevo/android/key.properties');
    $checks['android_firebase_config']=is_file(__DIR__.'/../mobile/clevo/android/app/google-services.json');
    if(($argv[2]??'all')!=='android')$checks['ios_firebase_config']=is_file(__DIR__.'/../mobile/clevo/ios/Runner/GoogleService-Info.plist');
}catch(Throwable){$checks['public_configuration']=false;}
echo json_encode(['ready'=>!in_array(false,$checks,true),'checks'=>$checks,'note'=>'No credentials or config values printed. Native signing/device/store qualification still required.'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
exit(in_array(false,$checks,true)?1:0);
