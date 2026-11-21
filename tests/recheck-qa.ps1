param([ValidateSet('admin','load')][string]$Target = 'admin')
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$testRoot = Join-Path $projectRoot '.security-test'
$setupFile = Join-Path $testRoot 'testing.env'
$runtimeFile = Join-Path $testRoot 'testing-runtime.env'
# Reuse only the preceding isolated schema; no migration, initialization or production URL argument.
foreach ($file in @($setupFile,$runtimeFile)) {
    if (!(Test-Path -LiteralPath $file)) { throw 'Run the isolated QA setup first.' }
    $lines = [IO.File]::ReadAllLines($file)
    foreach ($expected in @('APP_ENV=testing','DB_HOST=127.0.0.1','DB_PORT=13379','APP_URL=http://127.0.0.1:18089')) {
        $name = $expected.Split('=')[0]
        $matching = @($lines | Where-Object { $_.StartsWith($name + '=') })
        if ($matching.Count -ne 1 -or $matching[0] -cne $expected) { throw 'Refusing non-isolated QA configuration.' }
        $ambient = [Environment]::GetEnvironmentVariable($name, 'Process')
        if ($ambient -and ($name + '=' + $ambient) -cne $expected) { throw 'Conflicting ambient QA configuration.' }
    }
    $database = @($lines | Where-Object { $_ -match '^DB_DATABASE=clevo_security_test_[a-f0-9]{12}$' })
    if ($database.Count -ne 1) { throw 'Refusing unexpected QA schema.' }
    if ($file -eq $setupFile) { $expectedDatabase = $database[0] } elseif ($database[0] -cne $expectedDatabase) { throw 'QA setup/runtime schema mismatch.' }
}
$ambientDatabase = [Environment]::GetEnvironmentVariable('DB_DATABASE', 'Process')
if ($ambientDatabase -and ('DB_DATABASE=' + $ambientDatabase) -cne $expectedDatabase) { throw 'Conflicting ambient schema.' }
$previousEnvironmentFile = $env:CLEVO_ENV_FILE
$serverProcess = $null; $databaseProcess = $null
function Test-QAPort([int]$port) {
    $client = New-Object Net.Sockets.TcpClient
    try { $client.Connect('127.0.0.1',$port); return $client.Connected } catch { return $false } finally { $client.Dispose() }
}
try {
    if (Test-QAPort 18089) { throw 'An HTTP test runner is active; do not run QA concurrently.' }
    if (!(Test-QAPort 13379)) {
        $databaseProcess = Start-Process -FilePath 'C:/xampp/mysql/bin/mysqld.exe' -ArgumentList @("--defaults-file=$testRoot/mysql/my.ini",'--bind-address=127.0.0.1','--port=13379','--console','--skip-log-bin','--innodb-buffer-pool-size=32M') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $testRoot 'qa-recheck-mysql-out.log') -RedirectStandardError (Join-Path $testRoot 'qa-recheck-mysql-err.log')
    }
    $ready = $false
    for ($i=0; $i -lt 40; $i++) {
        & php (Join-Path $PSScriptRoot 'database-ready.php') 13379
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        if ($LASTEXITCODE -eq 11) { throw 'Refusing a database outside the workspace QA datadir.' }
        Start-Sleep -Milliseconds 250
    }
    if (!$ready) { throw 'Disposable QA database is unavailable.' }
    $env:CLEVO_ENV_FILE = $runtimeFile
    $serverProcess = Start-Process -FilePath (Get-Command php).Source -ArgumentList @('-d','zend.exception_ignore_args=1','-S','127.0.0.1:18089','-t',$projectRoot,(Join-Path $PSScriptRoot 'router.php')) -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $testRoot 'qa-recheck-http-out.log') -RedirectStandardError (Join-Path $testRoot 'qa-recheck-http-err.log')
    Start-Sleep -Milliseconds 700
    if ($serverProcess.HasExited) { throw 'Isolated QA HTTP server did not start.' }
    $env:CLEVO_ENV_FILE = $setupFile
    Push-Location $projectRoot
    try {
        if ($Target -eq 'load') {
            & php tests/qa-performance.php
            $result = $LASTEXITCODE
            # Independent HTTP/1.0 client comparison, still bounded to this guarded loopback server.
            $benchFile = Join-Path $testRoot 'qa-apachebench.txt'
            $bench = Start-Process -FilePath 'C:/xampp/apache/bin/ab.exe' -ArgumentList @('-n','50','-c','10','-s','20','http://127.0.0.1:18089/api/v1/stations?per_page=25') -WindowStyle Hidden -PassThru -RedirectStandardOutput $benchFile -RedirectStandardError (Join-Path $testRoot 'qa-apachebench-stderr.log')
            # Retain the process handle: PowerShell 5 can otherwise lose ExitCode after exit.
            $null = $bench.Handle
            $bench.WaitForExit()
            $bench.Refresh()
            if ($null -eq $bench.ExitCode) { throw 'ApacheBench process exit status is unavailable.' }
            $benchText = [IO.File]::ReadAllText($benchFile)
            $benchFailed = $bench.ExitCode -ne 0 -or $benchText -notmatch 'Complete requests:\s+50' -or $benchText -notmatch 'Failed requests:\s+0(?:\r?\n)' -or $benchText -match 'Non-2xx responses:\s+[1-9]'
            Write-Output ('Independent ApacheBench 50 requests / concurrency 10, exit=' + $bench.ExitCode + ', failed=' + $benchFailed)
            if ($benchFailed) { $result = 1 }
        } else {
            & php tests/admin-browser-fixtures.php
            $result = $LASTEXITCODE
            if ($result -eq 0) {
                $previousFixture = $env:CLEVO_ADMIN_BROWSER_FIXTURE
                $env:CLEVO_ADMIN_BROWSER_FIXTURE = Join-Path $testRoot 'private/admin-browser.json'
                try {
                    & node tests/admin-browser.cjs; $result = $LASTEXITCODE
                    & node tests/qa-admin-accessibility.cjs; if ($LASTEXITCODE -ne 0) { $result = $LASTEXITCODE }
                } finally { $env:CLEVO_ADMIN_BROWSER_FIXTURE = $previousFixture }
            }
        }
        if ($result -ne 0) { throw 'Affected QA checks failed; see assertion/rule output.' }
    } finally { Pop-Location }
} finally {
    $env:CLEVO_ENV_FILE = $previousEnvironmentFile
    if ($serverProcess -and !$serverProcess.HasExited) { Stop-Process -Id $serverProcess.Id }
    if ($databaseProcess -and !$databaseProcess.HasExited) {
        & php (Join-Path $PSScriptRoot 'database-ready.php') 13379
        if ($LASTEXITCODE -eq 0) { & C:/xampp/mysql/bin/mysqladmin.exe --no-defaults --protocol=tcp --host=127.0.0.1 --port=13379 --user=root --connect-timeout=2 shutdown; $null = $databaseProcess.WaitForExit(5000) }
        if (!$databaseProcess.HasExited) { Stop-Process -Id $databaseProcess.Id }
    }
}
