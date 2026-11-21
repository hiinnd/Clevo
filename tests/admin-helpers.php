<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/../includes/admin-services.php';
require_once __DIR__.'/../includes/admin-queries.php';
if(clevo_env('APP_ENV')!=='testing'||clevo_env('DB_HOST')!=='127.0.0.1'||!preg_match('/^clevo_security_test_[a-f0-9]{12}$/D',clevo_env('DB_DATABASE','')??''))exit(2);
$db=clevo_connect_database();
if(strcasecmp(realpath($db->query('SELECT @@datadir d')->fetch_assoc()['d'])?:'',realpath(__DIR__.'/../.security-test/mysql')?:'!')!==0)exit(2);
$base=rtrim(clevo_env('APP_URL'),'/');$httpCalls=0;
function ad_key():string{return bin2hex(random_bytes(24));}
function ad_actor(string $role,string $password,bool $verified=true):int{
    global $db;$suffix=bin2hex(random_bytes(6));
    clevo_stmt($db,"INSERT INTO user (UserName,Email,PhoneNumber,Password,Role,AccountStatus,EmailVerifiedAt) VALUES (?,?,?,?,?,'active',?)",'ssssss',['AdminPhase '.$role,$suffix.'@admin.example.test','077'.str_pad((string)random_int(0,9999999),7,'0',STR_PAD_LEFT),password_hash($password,PASSWORD_DEFAULT),$role,$verified?gmdate('Y-m-d H:i:s'):null]);return (int)$db->insert_id;
}
function ad_http(string $method,string $path,?array $body=null,?string $jar=null,?string $token=null,array $extraHeaders=[]):array{
    global $base,$db,$httpCalls;
    if(++$httpCalls%15===0)$db->query('DELETE FROM security_rate_limits');
    $headers=$extraHeaders;$responseHeaders='';$h=curl_init($base.$path);
    if($token)$headers[]='Authorization: Bearer '.$token;
    if($body!==null){
        if(str_starts_with($path,'/api/')){$headers[]='Content-Type: application/json';$headers[]='Idempotency-Key: '.ad_key();$payload=json_encode($body?:new stdClass());}
        else $payload=http_build_query($body);
        curl_setopt($h,CURLOPT_POSTFIELDS,$payload);
    }
    curl_setopt_array($h,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$headers]);
    if($jar)curl_setopt_array($h,[CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar]);
    curl_setopt($h,CURLOPT_HEADERFUNCTION,static function($h,$line)use(&$responseHeaders){$responseHeaders.=$line;return strlen($line);});
    $raw=(string)curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
    return [$status,json_decode($raw,true),$raw,$responseHeaders];
}
function ad_expect(int $code,array $response,string $label):array{check($response[0]===$code,$label.' (HTTP '.$response[0].')');return $response[1]['data']??[];}
function ad_login(int $id,string $password):array{
    global $db;$jar=__DIR__.'/../.security-test/admin-'.ad_key().'.cookies';
    $page=ad_http('GET','/general/login.php',null,$jar);preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$page[2],$m);
    ad_expect(302,ad_http('POST','/general/login.php',['login_action'=>1,'login_input'=>clevo_account($db,$id)['Email'],'password'=>$password,'csrf_token'=>$m[1]??''],$jar),'Web actor logs in');
    $page=ad_http('GET','/general/contact.php',null,$jar);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$page[2],$m);
    if(!$m){$page=ad_http('GET','/admin/management.php',null,$jar);preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$page[2],$m);}
    return [$jar,$m[1]??''];
}
function ad_submit(string $view,string $action,int $id,array $input,string $jar,string $csrf,?string $key=null):array{
    return ad_http('POST','/admin/management.php?view='.$view,['action'=>$action,'id'=>$id,'csrf_token'=>$csrf,'_key'=>$key??ad_key()]+$input,$jar);
}
function ad_station(int $owner,string $status='approved'):int{
    global $db;clevo_stmt($db,"INSERT INTO stations (UserID,Name,Status,admin_status,BookingCapacity,HomeCapacity,OpeningTime,ClosingTime) VALUES (?,'Admin integration','Active',?,1000,1000,'00:00','00:00')",'is',[$owner,$status]);return (int)$db->insert_id;
}
function ad_booking(int $customer,int $station,int $service):int{
    global $db;static $slot=400;$slot++;
    return (int)clevo_create_booking($db,$customer,$station,['service_ids'=>[$service],'service_location'=>'station','booking_date'=>(new DateTimeImmutable('+'.$slot.' days'))->format('Y-m-d'),'booking_time'=>'10:00'],ad_key())['booking_id'];
}
function ad_document():string{
    $dir=rtrim(clevo_env('PRIVATE_STORAGE_PATH'),'/\\').'/verification';if(!is_dir($dir))mkdir($dir,0700,true);
    $name=ad_key().'.pdf';file_put_contents($dir.'/'.$name,"%PDF-1.4\n%%EOF\n");return 'private:verification:'.$name;
}
function ad_inline_check(string $html):bool{
    $process=proc_open(['node',__DIR__.'/check-inline-js.js'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));fwrite($pipes[0],$html);fclose($pipes[0]);stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return proc_close($process)===0;
}
