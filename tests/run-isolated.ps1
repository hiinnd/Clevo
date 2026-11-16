param([string]$MysqlBin = 'C:\xampp\mysql\bin', [int]$DatabasePort = 13379, [int]$HttpPort = 18089, [switch]$SkipApache, [string]$Flutter = '', [switch]$MobileOnly)
$ErrorActionPreference = 'Stop'
if ($MobileOnly -and !$Flutter) { throw 'MobileOnly requires an explicit Flutter executable.' }
$projectRoot = Split-Path -Parent $PSScriptRoot
$testRoot = Join-Path $projectRoot '.security-test'
New-Item -ItemType Directory -Path $testRoot -Force | Out-Null
$dataRoot = Join-Path $testRoot 'mysql'
$databaseStarted = $false
$serverProcess = $null
$databaseProcess = $null
function New-TestKey {
    $bytes = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    $rng.GetBytes($bytes)
    $rng.Dispose()
    return 'base64:' + [Convert]::ToBase64String($bytes)
}
try {
    if (!(Test-Path -LiteralPath (Join-Path $dataRoot 'my.ini'))) {
        & (Join-Path $MysqlBin 'mysql_install_db.exe') "--datadir=$dataRoot" "--port=$DatabasePort" --silent
        if ($LASTEXITCODE -ne 0) { throw 'Isolated database initialization failed.' }
    }
    $listener = New-Object Net.Sockets.TcpClient
    try { $listener.Connect('127.0.0.1', $DatabasePort); $running = $listener.Connected } catch { $running = $false } finally { $listener.Dispose() }
    if (!$running) {
        $databaseProcess = Start-Process -FilePath (Join-Path $MysqlBin 'mysqld.exe') -ArgumentList @("--defaults-file=$dataRoot\my.ini", '--bind-address=127.0.0.1', "--port=$DatabasePort", '--console', '--skip-log-bin', '--innodb-buffer-pool-size=32M') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $testRoot 'mysql-stdout.log') -RedirectStandardError (Join-Path $testRoot 'mysql-stderr.log')
        $databaseStarted = $true
    }
    $databaseReady = $false
    $readyDeadline = [DateTime]::UtcNow.AddSeconds(180)
    do {
        if ($databaseStarted -and $databaseProcess.HasExited) { throw 'Isolated database did not start.' }
        & php (Join-Path $PSScriptRoot 'database-ready.php') $DatabasePort
        if ($LASTEXITCODE -eq 11) { throw 'Refusing tests: database is not the dedicated workspace test datadir.' }
        if ($LASTEXITCODE -eq 0) { $databaseReady = $true; break }
        Start-Sleep -Milliseconds 250
    } while ([DateTime]::UtcNow -lt $readyDeadline)
    if (!$databaseReady) { throw 'Isolated database readiness timed out; no tests or migrations were run.' }
    $databaseName = 'clevo_security_test_' + [Guid]::NewGuid().ToString('N').Substring(0, 12)
    $privateRoot = Join-Path $testRoot 'private'
    New-Item -ItemType Directory -Path $privateRoot -Force | Out-Null
    $uploadTmp = Join-Path $privateRoot 'tmp'
    New-Item -ItemType Directory -Path $uploadTmp -Force | Out-Null
    $environmentPath = Join-Path $testRoot 'testing.env'
    $runtimeEnvironmentPath = Join-Path $testRoot 'testing-runtime.env'
    $runtimeUser = 'clevo_rt_' + [Guid]::NewGuid().ToString('N').Substring(0, 12)
    $runtimePassword = [Guid]::NewGuid().ToString('N') + [Guid]::NewGuid().ToString('N')
    $settings = @(
        'APP_ENV=testing', "APP_URL=http://127.0.0.1:$HttpPort", ('APP_KEY=' + (New-TestKey)), ('JWT_SECRET=' + (New-TestKey)),
        'JWT_ISSUER=clevo', 'JWT_AUDIENCE=clevo-mobile', 'DB_HOST=127.0.0.1', "DB_PORT=$DatabasePort", "DB_DATABASE=$databaseName", 'DB_USERNAME=root', 'DB_PASSWORD=', 'CLEVO_SYNTHETIC_FINANCE=1',
        ('PRIVATE_STORAGE_PATH=' + $privateRoot.Replace('\','/')), 'MAIL_TRANSPORT=null', 'CORS_ALLOWED_ORIGINS=https://app.example.test',
        "TEST_RUNTIME_USERNAME=$runtimeUser", "TEST_RUNTIME_PASSWORD=$runtimePassword"
    )
    [IO.File]::WriteAllLines($environmentPath, $settings, [Text.UTF8Encoding]::new($false))
    $runtimeSettings = $settings | ForEach-Object { if ($_ -eq 'DB_USERNAME=root') { "DB_USERNAME=$runtimeUser" } elseif ($_ -eq 'DB_PASSWORD=') { "DB_PASSWORD=$runtimePassword" } else { $_ } }
    [IO.File]::WriteAllLines($runtimeEnvironmentPath, $runtimeSettings, [Text.UTF8Encoding]::new($false))
    $previousEnvironmentFile = $env:CLEVO_ENV_FILE
    $env:CLEVO_ENV_FILE = $runtimeEnvironmentPath
    $serverProcess = Start-Process -FilePath (Get-Command php).Source -ArgumentList @('-d','zend.exception_ignore_args=1','-d',"upload_tmp_dir=$uploadTmp",'-d','session.upload_progress.enabled=0','-S',"127.0.0.1:$HttpPort",'-t',$projectRoot,(Join-Path $PSScriptRoot 'router.php')) -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $testRoot 'php-stdout.log') -RedirectStandardError (Join-Path $testRoot 'php-stderr.log')
    Start-Sleep -Milliseconds 700
    if ($serverProcess.HasExited) { throw 'Isolated HTTP server did not start.' }
    $env:CLEVO_ENV_FILE = $environmentPath
    Push-Location $projectRoot
    try {
        $unitExit = 0; $scanExit = 0; $contractExit = 0
        if (!$MobileOnly) {
            & php tests/security.php
            $unitExit = $LASTEXITCODE
            & php tests/scan-secrets.php
            $scanExit = $LASTEXITCODE
            & php tests/api-contract.php
            $contractExit = $LASTEXITCODE
        } else { Write-Output 'Mobile-only rerun: disposable schema/auth setup and live Dart integration; other PHP suites are not repeated.' }
        & php tests/integration.php
        $integrationExit = $LASTEXITCODE
        $apiExit = 0
        if (!$MobileOnly -and $integrationExit -eq 0) { & php tests/api.php; $apiExit = $LASTEXITCODE }
        $bookingExit = 0
        if (!$MobileOnly -and $integrationExit -eq 0) { & php tests/booking.php; $bookingExit = $LASTEXITCODE }
        $financialExit = 0
        if (!$MobileOnly -and $integrationExit -eq 0) { & php tests/payments.php; $financialExit = $LASTEXITCODE }
        $logisticsExit = 0
        if (!$MobileOnly -and $integrationExit -eq 0) { & php tests/logistics.php; $logisticsExit = $LASTEXITCODE }
        $notificationExit = 0
        if (!$MobileOnly -and $integrationExit -eq 0) { & php tests/notifications.php; $notificationExit = $LASTEXITCODE }
        $databaseExit = 0
        if (!$MobileOnly) { & php tests/database.php; $databaseExit = $LASTEXITCODE }
        $mobileExit = 0
        if ($Flutter -and $integrationExit -eq 0) {
            & php tests/mobile-fixtures.php
            $mobileExit = $LASTEXITCODE
            if ($mobileExit -eq 0) {
                $previousMobileFixture = $env:CLEVO_MOBILE_FIXTURE_FILE
                $env:CLEVO_MOBILE_FIXTURE_FILE = Join-Path $privateRoot 'mobile-fixtures.json'
                Push-Location (Join-Path $projectRoot 'mobile/clevo')
                try { & $Flutter test --no-pub --reporter expanded test/live_api_test.dart; $mobileExit = $LASTEXITCODE }
                finally { Pop-Location; $env:CLEVO_MOBILE_FIXTURE_FILE = $previousMobileFixture }
            }
        }
        $apacheExit = 0
        $apacheRoot = Join-Path (Split-Path -Parent (Split-Path -Parent $MysqlBin)) 'apache'
        $phpRoot = Split-Path -Parent (Get-Command php).Source
        if (!$MobileOnly -and !$SkipApache -and $integrationExit -eq 0 -and (Test-Path -LiteralPath (Join-Path $apacheRoot 'bin/httpd.exe')) -and (Test-Path -LiteralPath (Join-Path $phpRoot 'php8apache2_4.dll'))) {
            & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'apache-rules.ps1') -ApacheRoot $apacheRoot -PhpRoot $phpRoot
            $apacheExit = $LASTEXITCODE
        } else { Write-Output 'SKIP: isolated Apache checks not requested or Apache PHP module unavailable.' }
        if ($unitExit -ne 0 -or $scanExit -ne 0 -or $contractExit -ne 0 -or $integrationExit -ne 0 -or $apiExit -ne 0 -or $bookingExit -ne 0 -or $financialExit -ne 0 -or $notificationExit -ne 0 -or $logisticsExit -ne 0 -or $databaseExit -ne 0 -or $apacheExit -ne 0 -or $mobileExit -ne 0) { throw 'Security/API/booking/payment/database/mobile tests failed. See assertion labels above.' }
    } finally { Pop-Location }
} finally {
    $env:CLEVO_ENV_FILE = $previousEnvironmentFile
    if ($serverProcess -and !$serverProcess.HasExited) { Stop-Process -Id $serverProcess.Id }
    if ($databaseStarted -and $databaseProcess -and !$databaseProcess.HasExited) {
        # Verify the datadir again before sending shutdown; only stop our own process on failure.
        & php (Join-Path $PSScriptRoot 'database-ready.php') $DatabasePort
        if ($LASTEXITCODE -eq 0) {
            & (Join-Path $MysqlBin 'mysqladmin.exe') --no-defaults --protocol=tcp --host=127.0.0.1 "--port=$DatabasePort" --user=root --connect-timeout=2 shutdown
            $null = $databaseProcess.WaitForExit(5000)
        }
        if (!$databaseProcess.HasExited) { Stop-Process -Id $databaseProcess.Id }
    }
}
