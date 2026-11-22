<?php
declare(strict_types=1);

function clevo_api_schema(array $properties,array $required=[]):array
{
    return ['type'=>'object','properties'=>$properties,'required'=>$required,'additionalProperties'=>false];
}
function clevo_api_validate(mixed $value,array $schema,string $path='body'):void
{
    $type=$schema['type']??null;
    if(is_array($type)){$previous=$GLOBALS['clevo_api_errors']??[];foreach($type as $candidate){try{clevo_api_validate($value,array_replace($schema,['type'=>$candidate]),$path);$GLOBALS['clevo_api_errors']=$previous;return;}catch(ClevoSecurityException $e){}}$GLOBALS['clevo_api_errors']=$previous;clevo_api_invalid($path);}
    $valid=match($type){'object'=>is_array($value)&&($value===[]||!array_is_list($value)),'array'=>is_array($value)&&array_is_list($value),'string'=>is_string($value),'integer'=>is_int($value),'number'=>(is_int($value)||is_float($value))&&is_finite((float)$value),'boolean'=>is_bool($value),'null'=>$value===null,default=>true};
    if(!$valid)clevo_api_invalid($path);
    if(array_key_exists('const',$schema)&&$value!==$schema['const'])clevo_api_invalid($path);
    if(isset($schema['enum'])&&!in_array($value,$schema['enum'],true))clevo_api_invalid($path);
    if(is_string($value)){
        if(mb_strlen(trim($value))<($schema['minLength']??0)||mb_strlen($value)>($schema['maxLength']??PHP_INT_MAX)||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$value))clevo_api_invalid($path);
        if(isset($schema['pattern'])&&!preg_match('~'.$schema['pattern'].'~uD',$value))clevo_api_invalid($path);
        if(($schema['format']??'')==='email'&&!filter_var($value,FILTER_VALIDATE_EMAIL))clevo_api_invalid($path);
    }
    if(is_int($value)||is_float($value))if($value<($schema['minimum']??-INF)||$value>($schema['maximum']??INF))clevo_api_invalid($path);
    if((is_int($value)||is_float($value))&&isset($schema['multipleOf'])&&abs($value/$schema['multipleOf']-round($value/$schema['multipleOf']))>0.00001)clevo_api_invalid($path);
    if($type==='object'){
        foreach($schema['required']??[] as $key)if(!array_key_exists($key,$value))clevo_api_invalid($path.'.'.$key);
        foreach($value as $key=>$child){if(!isset($schema['properties'][$key])){if(!($schema['additionalProperties']??true))clevo_api_invalid($path.'.'.$key);continue;}clevo_api_validate($child,$schema['properties'][$key],$path.'.'.$key);}
        if(count($value)<($schema['minProperties']??0))clevo_api_invalid($path);
    }
    if($type==='array'){
        if(count($value)<($schema['minItems']??0)||count($value)>($schema['maxItems']??100))clevo_api_invalid($path);
        if(($schema['uniqueItems']??false)&&count(array_unique(array_map(fn($v)=>json_encode($v),$value)))!==count($value))clevo_api_invalid($path);
        foreach($value as $key=>$child)clevo_api_validate($child,$schema['items']??[],$path.'.'.$key);
    }
}
function clevo_api_invalid(string $path):never
{
    $GLOBALS['clevo_api_errors']=[$path=>'invalid_or_missing'];throw new ClevoSecurityException('validation_failed',422);
}
function clevo_api_key():string {return (string)($_SERVER['HTTP_IDEMPOTENCY_KEY']??'');}
function clevo_api_query(string $key,?string $default=null):?string
{
    if(!array_key_exists($key,$_GET))return $default;
    if(!is_string($_GET[$key])||strlen($_GET[$key])>200)clevo_api_invalid('query.'.$key);
    return $_GET[$key];
}
function clevo_api_integer(string $key,int $default,int $min=1,int $max=2147483647):int
{
    $s=clevo_api_query($key,(string)$default);
    if(!preg_match('/^(0|[1-9][0-9]*)$/D',$s)||strlen($s)>10||(int)$s<$min||(int)$s>$max)clevo_api_invalid('query.'.$key);
    return (int)$s;
}
function clevo_api_filter_number(string $key,float $min,float $max):?float
{
    $s=clevo_api_query($key);if($s===null)return null;
    if(!is_numeric($s)||!is_finite((float)$s)||(float)$s<$min||(float)$s>$max)clevo_api_invalid('query.'.$key);
    return (float)$s;
}
function clevo_api_enum(string $key,array $values,?string $default=null):?string
{
    $value=clevo_api_query($key,$default);if($value!==null&&!in_array($value,$values,true))clevo_api_invalid('query.'.$key);return $value;
}
function clevo_api_list(mysqli $db,string $select,string $from,string $where,string $types,array $params,array $sorts,string $defaultSort,string $id):array
{
    $page=clevo_api_integer('page',1,1,10000);$size=clevo_api_integer('per_page',25,1,100);
    $sort=clevo_api_enum('sort',array_keys($sorts),$defaultSort);$direction=clevo_api_enum('direction',['asc','desc'],'desc');
    $count=clevo_stmt($db,"SELECT COUNT(*) Total $from WHERE $where",$types,$params)->get_result()->fetch_assoc();
    $offset=($page-1)*$size;
    $rows=clevo_stmt($db,"SELECT $select $from WHERE $where ORDER BY ".$sorts[$sort]." $direction, $id $direction LIMIT ? OFFSET ?",$types.'ii',array_merge($params,[$size,$offset]))->get_result()->fetch_all(MYSQLI_ASSOC);
    $GLOBALS['clevo_api_meta']['pagination']=['page'=>$page,'per_page'=>$size,'total'=>(int)$count['Total'],'total_pages'=>(int)ceil((int)$count['Total']/$size)];
    return $rows;
}
function clevo_api_result(array $data=[],int $status=200,string $message=''):never {clevo_json_response('success',$data,$message,$status);}
function clevo_api_require_row(?array $row):array {if(!$row)throw new ClevoSecurityException('not_found',404);return $row;}
function clevo_api_mutate(mysqli $db,array $ctx,string $scope,array $input,callable $work):array
{
    // Stable object-key order makes equivalent JSON objects independent of client serialization order.
    $sort=function(array $body)use(&$sort):array{if(!array_is_list($body))ksort($body);foreach($body as &$v)if(is_array($v))$v=$sort($v);return $body;};
    return clevo_db_idempotent($db,(int)$ctx['account']['UserID'],$scope,clevo_api_key(),$sort($input),$work);
}
function clevo_api_booking(mysqli $db,array $ctx,int $id):array {return clevo_api_require_row(clevo_booking_visible($db,$ctx,$id));}
function clevo_api_image(?string $image,string $name='',bool $station=false):string
{
    $image=trim((string)$image);
    if(str_starts_with($image,'private:'))return clevo_media_url($image);
    if(preg_match('~^https?://~i',$image))return $image;
    if($station){$path=clevoStationImageUrl($image);return preg_match('~^https?://~i',$path)?$path:clevo_public_url(preg_replace('~^(?:\.\./)+~','',ltrim($path,'/')));}
    foreach([$image,'uploads/profiles/'.$image,'assets/Images/'.$image] as $path)if(preg_match('~^(uploads|assets)/[A-Za-z0-9_./-]+$~D',$path)&&!str_contains($path,'..')&&is_file(dirname(__DIR__,2).'/'.$path))return clevo_public_url($path);
    return 'https://ui-avatars.com/api/?name='.rawurlencode($name).'&background=0ea5e9&color=fff';
}
function clevo_api_verification(mysqli $db,int $user):never
{
    $r=clevo_api_require_row(clevo_stmt($db,'SELECT VerificationFile FROM user WHERE UserID=?','i',[$user])->get_result()->fetch_assoc());$ref=(string)$r['VerificationFile'];
    if(preg_match('/^private:verification:([a-f0-9]{48}\.(?:jpg|png|gif|webp|pdf))$/D',$ref,$m))$file=rtrim(clevo_env('PRIVATE_STORAGE_PATH'),'/\\').'/verification/'.$m[1];
    elseif($ref!=='')$file=dirname(__DIR__,2).'/uploads/verification/'.basename($ref);
    else throw new ClevoSecurityException('not_found',404);
    if(!is_file($file)||is_link($file))throw new ClevoSecurityException('not_found',404);
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file);if(!in_array($mime,['image/jpeg','image/png','image/gif','image/webp','application/pdf'],true))throw new ClevoSecurityException('not_found',404);
    header('Content-Type: '.$mime);header('Content-Length: '.filesize($file));header('Content-Disposition: attachment; filename="verification.'.pathinfo($file,PATHINFO_EXTENSION).'"');header('Content-Security-Policy: sandbox');header('X-Request-ID: '.$GLOBALS['clevo_request_id']);readfile($file);exit;
}
function clevo_api_driver_job(mysqli $db,array $ctx,int $id,bool $history=false):array
{
    $a=$ctx['account'];$bound=(int)($ctx['session']['DriverBookingID']??0);
    if($bound&&$bound!==$id)throw new ClevoSecurityException('not_found',404);
    $where=$history?'':" AND b.Status IN ('Accepted','Arrived','In Progress','Pickup','Washing','Returning')";
    return clevo_api_require_row(clevo_stmt($db,"SELECT b.* FROM booking b JOIN stations s ON s.StationID=b.StationID WHERE b.BookingID=? AND b.DriverID=? AND b.StationID=? AND s.admin_status='approved' $where",'iii',[$id,(int)$a['UserID'],(int)$a['StationID']])->get_result()->fetch_assoc());
}
