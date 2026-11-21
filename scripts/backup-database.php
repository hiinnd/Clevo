<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/security/environment.php';
$optionFile=null; $dump=null; $output=null;$exitCode=0;
try {
    clevo_load_environment(); clevo_validate_database_environment(); $conn=clevo_connect_database();
    if ((int)$conn->query("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND ENGINE<>'InnoDB'")->fetch_assoc()['n']) throw new RuntimeException('Consistent snapshot requires all InnoDB.');
    $directory=rtrim(clevo_env('PRIVATE_STORAGE_PATH'),'/\\').'/backups';
    if(!is_dir($directory)&&!mkdir($directory,0700,true)) throw new RuntimeException('Private backup directory unavailable.');
    $suffix=gmdate('Ymd_His').'_'.bin2hex(random_bytes(6));
    $dump=$directory.'/clevo_'.$suffix.'.sql';
    $optionFile=$directory.'/.client_'.$suffix.'.cnf';
    $escape=static fn(string $v):string=>'"'.str_replace(["\\","\"","\r","\n"],["\\\\","\\\"","\\r","\\n"],$v).'"';
    $text="[client]\n";
    foreach(['host'=>'DB_HOST','port'=>'DB_PORT','user'=>'DB_USERNAME','password'=>'DB_PASSWORD'] as $key=>$env) $text.=$key.'='.$escape(clevo_env($env,'')??'')."\n";
    $file=fopen($optionFile,'x'); if(!$file) throw new RuntimeException('Client config unavailable.'); chmod($optionFile,0600); fwrite($file,$text); fclose($file); unset($text);
    $output=fopen($dump,'x'); if(!$output) throw new RuntimeException('Backup output unavailable.'); chmod($dump,0600);
    $binary=clevo_env('MYSQLDUMP_BINARY','mysqldump');
    $args=[$binary,'--defaults-extra-file='.$optionFile,'--single-transaction','--quick','--skip-lock-tables','--no-tablespaces','--hex-blob','--default-character-set=utf8mb4','--tz-utc','--routines','--events','--triggers','--skip-add-drop-table','--skip-add-locks','--no-create-db','--skip-comments',clevo_env('DB_DATABASE')];
    $ca=clevo_env('DB_TLS_CA_FILE','')??'';
    if($ca!==''){
        // Client options use the protected file; use matching vendor/version binaries.
        file_put_contents($optionFile,'ssl-ca='.$escape($ca)."\nssl-verify-server-cert\n",FILE_APPEND);
    }
    $process=proc_open($args,[0=>['pipe','r'],1=>$output,2=>['pipe','w']],$pipes);
    if(!is_resource($process)) throw new RuntimeException('Dump client unavailable.');
    fclose($pipes[0]); $error=stream_get_contents($pipes[2]); fclose($pipes[2]); $code=proc_close($process); fclose($output); $output=null;
    if($code!==0 || filesize($dump)===0) throw new RuntimeException('Dump failed.');
    $manifest=['created_at_utc'=>gmdate('c'),'sha256'=>hash_file('sha256',$dump),'bytes'=>filesize($dump),'server'=>$conn->server_info,'format'=>'mysql-logical-single-transaction','contains_private_data'=>true];
    file_put_contents($dump.'.manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)); chmod($dump.'.manifest.json',0600);
    if(in_array('--json',$argv,true))echo json_encode(['file'=>basename($dump),'sha256'=>$manifest['sha256'],'bytes'=>$manifest['bytes']],JSON_THROW_ON_ERROR)."\n";
    else echo 'Backup completed: '.basename($dump)."; manifest written. Encrypt and transfer to protected backup storage.\n";
}catch(Throwable $e){fwrite(STDERR,'Backup failed ('.get_class($e)."). Partial artifact must not be restored.\n");$exitCode=1;}
finally{if(is_resource($output))fclose($output); if($optionFile!==null&&is_file($optionFile))unlink($optionFile);}
exit($exitCode);
