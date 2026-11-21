<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/security/environment.php';
$optionFile=null;$exitCode=0;
try {
    clevo_load_environment(); clevo_validate_database_environment();
    if(clevo_env('APP_ENV')!=='testing' || clevo_env('DB_HOST')!=='127.0.0.1' || !preg_match('/^clevo_restore_test_[a-f0-9]{12}$/D',clevo_env('DB_DATABASE','')??'')) throw new RuntimeException('Disposable test target required.');
    $conn=clevo_connect_database();
    if((int)$conn->query('SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetch_assoc()['n']!==0) throw new RuntimeException('Target must be empty.');
    $file=realpath($argv[1]??''); $root=realpath(rtrim(clevo_env('PRIVATE_STORAGE_PATH'),'/\\').'/backups');
    if(!$file||!$root||!clevo_path_within($file,$root)||!str_ends_with($file,'.sql')) throw new RuntimeException('Protected backup path required.');
    $manifest=json_decode(file_get_contents($file.'.manifest.json'),true,512,JSON_THROW_ON_ERROR);
    if(!hash_equals($manifest['sha256'],hash_file('sha256',$file))) throw new RuntimeException('Backup checksum mismatch.');
    $input=fopen($file,'rb'); if(!$input) throw new RuntimeException('Backup unavailable.');
    // Reject database switching before handing the file to the isolated client.
    while(($line=fgets($input))!==false) if(preg_match('/^\s*(?:USE\s|CREATE\s+DATABASE|DROP\s+DATABASE|SOURCE\s|CONNECT\s|\\\\[.!])/i',$line)) throw new RuntimeException('Database switching forbidden.');
    rewind($input);
    $optionFile=$root.'/.restore_'.bin2hex(random_bytes(12)).'.cnf';
    $escape=static fn(string $v):string=>'"'.str_replace(["\\","\"","\r","\n"],["\\\\","\\\"","\\r","\\n"],$v).'"';
    $text="[client]\n";
    foreach(['host'=>'DB_HOST','port'=>'DB_PORT','user'=>'DB_USERNAME','password'=>'DB_PASSWORD'] as $key=>$env)$text.=$key.'='.$escape(clevo_env($env,'')??'')."\n";
    $config=fopen($optionFile,'x'); chmod($optionFile,0600); fwrite($config,$text); fclose($config); unset($text);
    $process=proc_open([clevo_env('MYSQL_BINARY','mysql'),'--defaults-extra-file='.$optionFile,'--binary-mode=1','--default-character-set=utf8mb4',clevo_env('DB_DATABASE')],[0=>$input,1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Restore client unavailable.');
    stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]); $code=proc_close($process); fclose($input);
    if($code!==0)throw new RuntimeException('Restore client failed.');
    echo "Protected backup restored into empty disposable database. Validate schema, counts and balances before trusting recovery.\n";
}catch(Throwable $e){fwrite(STDERR,'Restore refused/failed ('.get_class($e)."). No production restore attempted.\n");$exitCode=1;}
finally{if($optionFile!==null&&is_file($optionFile))unlink($optionFile);}
exit($exitCode);
