<?php
declare(strict_types=1);
// Liveness intentionally depends only on PHP. It is NOT database/application readiness.
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
echo '{"status":"alive"}';
