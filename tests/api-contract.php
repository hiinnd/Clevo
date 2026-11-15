<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/../includes/api/http.php';
require_once __DIR__.'/../includes/api/contracts.php';
require_once __DIR__.'/../includes/api/routes.php';
$spec=json_decode(file_get_contents(__DIR__.'/../docs/openapi.v1.json'),true,512,JSON_THROW_ON_ERROR);
check($spec['openapi']==='3.1.1','OpenAPI version is explicit');
$ids=[];$operations=0;$references=[];
$walk=function($value)use(&$walk,&$references){if(!is_array($value))return;foreach($value as $k=>$v){if($k==='$ref')$references[]=$v;else $walk($v);}};$walk($spec);
foreach(array_unique($references)as $ref){$node=$spec;foreach(explode('/',substr($ref,2))as $part){$part=str_replace(['~1','~0'],['/','~'],$part);$node=$node[$part]??null;}check($node!==null,'Resolved schema reference '.$ref);}
foreach($spec['paths']as $path=>$methods)foreach($methods as $verb=>$operation){$operations++;check(!isset($ids[$operation['operationId']]),'Unique operation ID '.$operation['operationId']);$ids[$operation['operationId']]=true;
    foreach($operation['requestBody']['content']??[]as $media=>$body)if(isset($body['example'])){$name=basename($body['schema']['$ref']);clevo_api_validate($body['example'],$spec['components']['schemas'][$name]);check(true,'Published request example validates '.$verb.' '.$path);}
}
$registry=clevo_api_routes();
check($operations===count($registry)+11,'Every registry and preserved auth operation is documented');
foreach($registry as $route){$op=$spec['paths']['/'.$route['path']][strtolower($route['method'])]??null;check($op!==null,'Documented route '.$route['method'].' '.$route['path']);if(!$op)continue;
    check($op['x-roles']===$route['roles'],'Documented RBAC '.$route['path']);
    $parameters=array_column($op['parameters']??[],'name');check(in_array('Idempotency-Key',$parameters,true)===$route['idempotent'],'Documented idempotency '.$route['path']);
    check(isset($op['responses'][(string)$route['status']]),'Documented success status '.$route['path']);
}
$contracts=clevo_api_contracts();
rejects(fn()=>clevo_api_validate(['role'=>'admin'],$contracts['Profile']),'Profile rejects role injection','validation_failed');
rejects(fn()=>clevo_api_validate(['price'=>1.001],$contracts['ServicePatch']),'Monetary input rejects fractions below schema precision','validation_failed');
rejects(fn()=>clevo_api_validate(['latitude'=>INF,'longitude'=>0],$contracts['Location']),'GPS rejects non-finite values','validation_failed');
rejects(fn()=>clevo_api_validate(['latitude'=>91,'longitude'=>0],$contracts['Location']),'GPS rejects geographic overflow','validation_failed');
rejects(fn()=>clevo_api_validate(['full_name'=>'   '],$contracts['Profile']),'Required text rejects whitespace-only values','validation_failed');
rejects(fn()=>clevo_api_validate([], $contracts['Profile']),'PATCH requires at least one supported field','validation_failed');
rejects(fn()=>clevo_api_validate(['label'=>'Home','address'=>'Here','latitude'=>0,'longitude'=>0,'is_default'=>'1'],$contracts['Address']),'JSON booleans cannot be strings','validation_failed');
check(is_file(__DIR__.'/openapi/package-lock.json'),'OpenAPI validation tool dependency is locked');
finish_tests('OpenAPI/runtime contract suite');
