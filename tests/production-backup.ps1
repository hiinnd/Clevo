param([Parameter(Mandatory=$true)][string]$Restic)
$ErrorActionPreference='Stop'
$projectRoot=Split-Path -Parent $PSScriptRoot
$testRoot=(Resolve-Path -LiteralPath (Join-Path $projectRoot '.security-test')).Path
$backupRoot=Join-Path $testRoot 'private/backups'
$manifest=Get-ChildItem -LiteralPath $backupRoot -File -Filter '*.sql.manifest.json' | Sort-Object LastWriteTimeUtc -Descending | Select-Object -First 1
if (!$manifest) { throw 'Run disposable database backup/restore tests first.' }
$dump=$manifest.FullName.Substring(0,$manifest.FullName.Length-'.manifest.json'.Length)
$metadata=Get-Content -LiteralPath $manifest.FullName -Raw | ConvertFrom-Json
if ((Get-FileHash -LiteralPath $dump -Algorithm SHA256).Hash -ne $metadata.sha256) { throw 'Synthetic backup integrity failed.' }
$root=Join-Path $testRoot ('restic-test-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $root | Out-Null
$passwordFile=Join-Path $root 'password.txt'
[IO.File]::WriteAllText($passwordFile,([Guid]::NewGuid().ToString('N')+[Guid]::NewGuid().ToString('N')))
$originalRepo=$env:RESTIC_REPOSITORY
$originalPassword=$env:RESTIC_PASSWORD_FILE
$checks=0
try {
    $env:RESTIC_REPOSITORY=Join-Path $root 'repository'
    $env:RESTIC_PASSWORD_FILE=$passwordFile
    $null=& $Restic init --quiet
    if ($LASTEXITCODE -ne 0) { throw 'Local encrypted repository initialization failed.' }; $checks++
    $null=& $Restic backup --quiet --tag disposable-test -- $dump $manifest.FullName
    if ($LASTEXITCODE -ne 0) { throw 'Local encrypted backup failed.' }; $checks++
    $null=& $Restic check --read-data --quiet
    if ($LASTEXITCODE -ne 0) { throw 'Encrypted repository full-data integrity check failed.' }; $checks++
    $restore=Join-Path $root 'restored'
    $null=& $Restic restore latest --target $restore --quiet
    if ($LASTEXITCODE -ne 0) { throw 'Encrypted snapshot restore failed.' }; $checks++
    $restored=Get-ChildItem -LiteralPath $restore -File -Recurse | Where-Object { $_.Name -eq [IO.Path]::GetFileName($dump) }
    if (@($restored).Count -ne 1 -or (Get-FileHash -LiteralPath $restored.FullName -Algorithm SHA256).Hash -ne $metadata.sha256) { throw 'Restored SQL hash mismatch.' }; $checks++
    # Incorrect password must not permit reading encrypted customer data.
    $badFile=Join-Path $root 'incorrect.txt'
    [IO.File]::WriteAllText($badFile,[Guid]::NewGuid().ToString('N'))
    $env:RESTIC_PASSWORD_FILE=$badFile
    $previousErrorPreference=$ErrorActionPreference
    $ErrorActionPreference='Continue'
    $null=& $Restic snapshots --quiet 2>&1
    $incorrectExit=$LASTEXITCODE
    $ErrorActionPreference=$previousErrorPreference
    if ($incorrectExit -eq 0) { throw 'Encrypted repository accepted incorrect password.' }; $checks++
    @{checks=$checks;failed=0;scope='local encrypted disposable SQL snapshot roundtrip; no remote storage';restic=(& $Restic version | Select-Object -First 1);time_utc=[DateTime]::UtcNow.ToString('o')} | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $testRoot 'production-backup-result.json') -Encoding UTF8
    Write-Output ('Encrypted local backup and restore: ' + $checks + '/' + $checks + ' checks passed. No production or remote repository used.')
} finally {
    $env:RESTIC_REPOSITORY=$originalRepo
    $env:RESTIC_PASSWORD_FILE=$originalPassword
}
# Protected synthetic recovery artifacts stay under ignored .security-test; no recursive deletion.
