<?php
require_once __DIR__.'/../includes/config.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');throw new ClevoSecurityException('method_not_allowed',405);}
clevo_admin_web_command($conn,clevo_current_user_id(),'user_status',(int)($_POST['user_id']??0),['status'=>'active']);
header('Location: security-center.php');exit;
