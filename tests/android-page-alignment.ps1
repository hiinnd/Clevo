param([string]$Artifact = '')
$ErrorActionPreference='Stop'
$projectRoot=Split-Path -Parent $PSScriptRoot
if (!$Artifact) { $Artifact=Join-Path $projectRoot 'mobile/clevo/build/app/outputs/flutter-apk/app-debug.apk' }
$sdk=Join-Path $projectRoot '.mobile-tools/android-sdk'
$readelf=Join-Path $sdk 'ndk/28.2.13676358/toolchains/llvm/prebuilt/windows-x86_64/bin/llvm-readelf.exe'
$null=& (Join-Path $sdk 'build-tools/36.0.0/zipalign.exe') -c -P 16 -v 4 $Artifact
if ($LASTEXITCODE -ne 0) { throw 'APK ZIP page alignment failed.' }
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive=[IO.Compression.ZipFile]::OpenRead($Artifact)
$directory=Join-Path $projectRoot ('.security-test/elf-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $directory | Out-Null
$libraries=0
try {
    foreach ($entry in $archive.Entries | Where-Object { $_.FullName -match '^lib/(arm64-v8a|x86_64)/[^/]+\.so$' }) {
        $file=Join-Path $directory ($entry.FullName -replace '/', '_')
        [IO.Compression.ZipFileExtensions]::ExtractToFile($entry,$file,$false)
        $headers=@(& $readelf --program-headers --wide $file)
        if ($LASTEXITCODE -ne 0) { throw 'ELF headers unavailable.' }
        $loads=@($headers | Where-Object { $_ -match '^\s*LOAD\s' })
        if (!$loads.Count) { throw 'Missing ELF LOAD headers.' }
        foreach ($load in $loads) {
            $alignment=($load.Trim() -split '\s+')[-1] -replace '^0x',''
            if ([Convert]::ToInt64($alignment,16) -lt 16384) { throw 'ELF library lacks 16 KiB LOAD alignment.' }
        }
        $libraries++
    }
} finally { $archive.Dispose() }
if (!$libraries) { throw 'No 64-bit native libraries inspected.' }
@{libraries=$libraries;zip_alignment=16384;elf_min_alignment=16384;sha256=(Get-FileHash -LiteralPath $Artifact -Algorithm SHA256).Hash;runtime_16k_test='not run'} | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $projectRoot '.security-test/android-page-alignment.json') -Encoding UTF8
Write-Output ('APK ZIP alignment and ' + $libraries + ' native ELF library alignments passed. This is not a 16 KiB device test or final AAB certification.')
