<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('zend.exception_ignore_args','1');
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try{
    require_once __DIR__.'/../includes/operations.php';clevo_load_environment();
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
    if(clevo_secure_environment()&&!clevo_https()){http_response_code(400);exit;}
    $authorization=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
    if(!preg_match('/^Bearer ([A-Za-z0-9+\/_=:-]{32,256})$/D',$authorization,$m)||!hash_equals(clevo_secret('OPS_HEALTH_TOKEN'),$m[1])){http_response_code(401);exit;}
    $report=clevo_operations_health();http_response_code($report['status']==='ready'?200:503);
    if(defined('CLEVO_METRICS')){header('Content-Type: text/plain; version=0.0.4; charset=utf-8');echo clevo_operations_prometheus($report);}
    else{header('Content-Type: application/json; charset=utf-8');echo json_encode(['status'=>$report['status']],JSON_THROW_ON_ERROR);}
}catch(Throwable){http_response_code(503);header('Content-Type: application/json; charset=utf-8');echo '{"status":"unavailable"}';}
