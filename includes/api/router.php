<?php
declare(strict_types=1);

function clevo_api_dispatch(mysqli $db,string $path,string $method):never
{
    $match=null;$args=[];$allowed=[];
    foreach(clevo_api_routes() as $route){
        $regex=preg_quote($route['path'],'~');$regex=str_replace(['\{id\}','\{session\}'],['(?P<id>[1-9][0-9]{0,9})','(?P<session>[a-f0-9]{48})'],$regex);
        if(!preg_match('~^'.$regex.'$~D',$path,$parts))continue;
        if(isset($parts['id'])&&(int)$parts['id']>2147483647)continue;
        $allowed[]=$route['method'];if($route['method']===$method){$match=$route;$args=array_filter($parts,fn($key)=>is_string($key)&&$key!==0,ARRAY_FILTER_USE_KEY);}
    }
    if(!$match){if($allowed){header('Allow: '.implode(', ',array_unique($allowed)));throw new ClevoSecurityException('method_not_allowed',405);}throw new ClevoSecurityException('resource_not_found',404);}
    $public=$match['roles']==='public';$ctx=[];
    $authorization=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
    if(!$public||$authorization!==''){
        $ctx=clevo_authenticated_token($db,clevo_bearer_token())??[];if(!$ctx)throw new ClevoSecurityException('unauthorized',401);
        $role=clevo_role($ctx['account']['Role']);
        if(!in_array($match['roles'],['public','any'],true)&&!in_array($role,explode(',',$match['roles']),true))throw new ClevoSecurityException('forbidden',403);
        if(!empty($ctx['session']['DriverBookingID'])&&!$public&&!str_starts_with($match['handler'],'driver.')&&!in_array($match['handler'],['customer.profile','bookings.detail','bookings.status'],true))throw new ClevoSecurityException('task_scope_restricted',403);
        $user=(int)$ctx['account']['UserID'];clevo_rate_limit($db,'api:user',(string)$user,120,60);
        if(in_array($method,['POST','PATCH','PUT','DELETE'],true))clevo_rate_limit($db,'api:sensitive',(string)$user,30,60);
    }
    $input=[];
    if(in_array($match['schema'],['Avatar','StationImage'],true)){
        if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'multipart/form-data;'))throw new ClevoSecurityException('content_type_required',415);
        $field=$match['schema']==='Avatar'?'avatar':'image';
        clevo_inspect_upload($_FILES[$field]??[]);
        $input=['fingerprint'=>hash_file('sha256',$_FILES[$field]['tmp_name'])];
    }elseif($match['schema']){
        $emptyRaw=$match['schema']==='Empty'?(string)file_get_contents('php://input',false,null,0,16385):null;
        if($emptyRaw!==null&&strlen($emptyRaw)>16384)throw new ClevoSecurityException('payload_too_large',413);
        if($match['schema']==='Empty'&&in_array(trim($emptyRaw),['','[]','{}'],true)){
            // Existing read-marker clients sent [] rather than {}; no user-controlled fields exist here.
            $raw=trim($emptyRaw);
            if($raw!==''&&strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json')throw new ClevoSecurityException('content_type_required',415);
            $input=[];
        }
        else $input=clevo_read_json();
        $GLOBALS['clevo_api_errors']=[];clevo_api_validate($input,clevo_api_contracts()[$match['schema']]);
    }
    if($match['idempotent']&&!preg_match('/^[A-Za-z0-9_-]{16,128}$/D',clevo_api_key()))throw new ClevoSecurityException('idempotency_key_required',422);
    [$module,$handler]=explode('.',$match['handler'],2);
    try{
        match($module){
            'catalog'=>clevo_api_catalog($db,$handler,$args),
            'customer'=>clevo_api_customer($db,$handler,$ctx,$args,$input),
            'bookings'=>clevo_api_bookings($db,$handler,$ctx,$args,$input),
            'owner'=>clevo_api_owner($db,$handler,$ctx,$args,$input),
            'driver'=>clevo_api_driver($db,$handler,$ctx,$args,$input),
            'payments'=>clevo_api_payments($db,$handler,$ctx,$args,$input),
            'finance'=>clevo_api_finance($db,$handler,$ctx,$args,$input),
            'admin'=>clevo_api_admin($db,$handler,$ctx,$args,$input),
            default=>throw new LogicException('Unknown API module.'),
        };
    }catch(mysqli_sql_exception $e){if((int)$e->getCode()===1062)throw new ClevoSecurityException('resource_conflict',409);if(in_array((int)$e->getCode(),[1205,1213],true))throw new ClevoSecurityException('retryable_conflict',409);throw $e;}
    throw new LogicException('API handler did not respond.');
}
