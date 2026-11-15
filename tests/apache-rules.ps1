param([string]$ApacheRoot='C:\xampp\apache',[string]$PhpRoot='C:\xampp\php',[int]$Port=18090)
$ErrorActionPreference='Stop'
$projectRoot=(Split-Path -Parent $PSScriptRoot).Replace('\','/')
$testRoot="$projectRoot/.security-test/apache"
New-Item -ItemType Directory -Path $testRoot -Force | Out-Null
$apache=$ApacheRoot.Replace('\','/')
$php=$PhpRoot.Replace('\','/')
$configPath="$testRoot/httpd.conf"
$configuration=@"
ServerRoot "$apache"
Listen 127.0.0.1:$Port
ServerName 127.0.0.1
PidFile "$testRoot/httpd.pid"
ErrorLog "$testRoot/error.log"
LoadModule authn_core_module modules/mod_authn_core.so
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule env_module modules/mod_env.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule log_config_module modules/mod_log_config.so
LoadModule php_module "$php/php8apache2_4.dll"
PHPIniDir "$php"
TypesConfig conf/mime.types
SetEnv CLEVO_ENV_FILE "$projectRoot/.security-test/testing-runtime.env"
DocumentRoot "$projectRoot"
<Directory />
    Require all denied
</Directory>
<Directory "$projectRoot">
    AllowOverride All
    Options FollowSymLinks
    Require all granted
</Directory>
<FilesMatch "\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>
php_admin_value upload_tmp_dir "$projectRoot/.security-test/private/tmp"
php_admin_value error_log "$testRoot/php-error.log"
php_admin_flag display_startup_errors Off
php_admin_flag display_errors Off
php_admin_flag session.upload_progress.enabled Off
LogFormat "%a %t \"%m %U %H\" %>s %b" clevo_redacted
CustomLog "$testRoot/access.log" clevo_redacted
"@
[IO.File]::WriteAllText($configPath,$configuration,[Text.UTF8Encoding]::new($false))
$executable=Join-Path $ApacheRoot 'bin/httpd.exe'
& $executable -t -f $configPath
if($LASTEXITCODE -ne 0){throw 'Isolated Apache configuration check failed.'}
$server=$null
$failures=0
$checks=0
try {
    $server=Start-Process -FilePath $executable -ArgumentList @('-X','-f',$configPath) -WindowStyle Hidden -PassThru
    Start-Sleep -Seconds 2
    if($server.HasExited){throw 'Isolated Apache did not start.'}
    $cases=@(
        @('/.env.example','GET',403),@('/.git/HEAD','GET',403),@('/.GIT/HEAD','GET',403),@('/clevo.sql','GET',403),
        @('/scripts/security-mail-worker.php','GET',403),@('/migrations/001_auth_security.sql','GET',403),
        @('/includes/security/environment.php','GET',403),@('/uploads/verification/any.pdf','GET',403),
        @('/uploads/profiles/blocked.php','GET',403),@('/api/v1/auth/login','GET',405),
        @('/api/v1/auth/login','OPTIONS',204)
    )
    foreach($case in $cases){
        $checks++
        try {$response=Invoke-WebRequest -UseBasicParsing -Uri ("http://127.0.0.1:$Port"+$case[0]) -Method $case[1];$status=[int]$response.StatusCode}
        catch {if($_.Exception.Response){$status=[int]$_.Exception.Response.StatusCode}else{$status=0}}
        if($status -ne $case[2]){$failures++;Write-Output ("FAIL: Apache "+$case[0]+" expected "+$case[2]+" received "+$status)}
    }
    Write-Output ("Isolated Apache rules: "+($checks-$failures)+"/"+$checks+" checks passed; "+$failures+" failed.")
    if($failures){throw 'Apache rules failed.'}
} finally {
    if($server -and !$server.HasExited){Stop-Process -Id $server.Id}
}
