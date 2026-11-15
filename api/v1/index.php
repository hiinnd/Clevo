<?php
declare(strict_types=1);
define('CLEVO_API',true);
define('CLEVO_API_V1',true);
$GLOBALS['clevo_request_id']=bin2hex(random_bytes(16));
require_once __DIR__.'/../../includes/config.php';
if(!clevo_stmt($conn,"SELECT Version FROM security_schema_migrations WHERE Version='004_mobile_api'")->get_result()->fetch_assoc()) throw new RuntimeException('API migration required.');
require_once __DIR__.'/../../includes/marketplace.php';
foreach(['http','contracts','routes','catalog','bookings','customer','owner','driver','payments','admin','router'] as $module) require_once __DIR__.'/../../includes/api/'.$module.'.php';
try {
require __DIR__.'/auth.php';
if(in_array($route,['auth/logout','auth/logout-all'],true)){
    if($method!=='POST'){header('Allow: POST');throw new ClevoSecurityException('method_not_allowed',405);}
    $identity=clevo_authenticated_token($conn,clevo_bearer_token());if(!$identity)throw new ClevoSecurityException('unauthorized',401);
    if($route==='auth/logout-all'&&!empty($identity['session']['DriverBookingID']))throw new ClevoSecurityException('task_scope_restricted',403);
    clevo_db_atomic($conn,function()use($conn,$route,$identity){if($route==='auth/logout-all')clevo_revoke_user($conn,(int)$identity['account']['UserID']);else clevo_revoke_session($conn,$identity['session']['SessionID']);});
    clevo_api_result([],200,'logged_out');
}
clevo_api_dispatch($conn,$route,$method);
} catch(ClevoSecurityException $error) { throw $error; }
catch(Throwable $error) {
    error_log(json_encode(['event'=>'api.request_failed','request_id'=>$GLOBALS['clevo_request_id'],'error_class'=>get_class($error)]));
    clevo_json_response('error',[],'internal_error',500);
}
