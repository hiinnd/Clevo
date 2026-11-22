<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('zend.exception_ignore_args','1');
require_once __DIR__.'/../../includes/security/environment.php';
try{
    clevo_load_environment();clevo_validate_database_environment();
    $root=realpath(clevo_env('PRIVATE_STORAGE_PATH'));
    if(($argv[1]??'')==='root'){echo $root;exit;}
    $data=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    if(!preg_match('/^clevo_[0-9]{8}_[0-9]{6}_[a-f0-9]{12}\.sql$/D',$data['file']??''))throw new RuntimeException('Invalid artifact reference.');
    $dump=$root.'/backups/'.$data['file'];
    if(!is_file($dump)||!is_file($dump.'.manifest.json')||!hash_equals($data['sha256']??'',hash_file('sha256',$dump)))throw new RuntimeException('Backup verification failed.');
    if(($argv[1]??'')==='path'){echo $dump;exit;}
    if(($argv[1]??'')!=='success')throw new RuntimeException('Unknown operation.');
    $directory=$root.'/ops';if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new RuntimeException('Backup monitoring unavailable.');
    $temp=$directory.'/backup.'.bin2hex(random_bytes(6)).'.tmp';
    file_put_contents($temp,json_encode(['last_success_unix'=>time(),'artifact_sha256'=>$data['sha256']],JSON_THROW_ON_ERROR),LOCK_EX);chmod($temp,0600);
    if(!rename($temp,$directory.'/backup.json'))throw new RuntimeException('Backup monitoring unavailable.');
    echo "Encrypted backup completion recorded.\n";
}catch(Throwable){fwrite(STDERR,"Backup state unavailable; no credential data emitted.\n");exit(1);}
