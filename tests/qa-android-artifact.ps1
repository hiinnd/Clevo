param([string]$Sdk = '', [string]$Java = '')
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
if (!$Sdk) { $Sdk = Join-Path $projectRoot '.mobile-tools/android-sdk' }
if (!$Java) { $Java = Join-Path $projectRoot '.mobile-tools/java/jdk-21.0.13+9' }
$env:JAVA_HOME = $Java
$artifact = Join-Path $projectRoot 'mobile/clevo/build/app/outputs/flutter-apk/app-debug.apk'
$tools = Join-Path $Sdk 'build-tools/36.0.0'
$checks = 0
function Assert-QA([bool]$condition, [string]$label) { $script:checks++; if (!$condition) { throw $label } }
Assert-QA (Test-Path -LiteralPath $artifact) 'Android debug artifact is missing.'
$badging = @(& (Join-Path $tools 'aapt.exe') dump badging $artifact)
Assert-QA ($LASTEXITCODE -eq 0) 'APK metadata cannot be read.'
$badgingText = $badging -join "`n"
Assert-QA ($badgingText -match "package: name='com.clevo.clevo'") 'Unexpected application identity.'
Assert-QA ($badgingText -match "sdkVersion:'24'") 'Unexpected minimum Android SDK.'
Assert-QA ($badgingText -match "targetSdkVersion:'36'") 'Unexpected target Android SDK.'
$manifest = @(& (Join-Path $tools 'aapt.exe') dump xmltree $artifact AndroidManifest.xml) -join "`n"
Assert-QA ($LASTEXITCODE -eq 0) 'Merged manifest cannot be read.'
Assert-QA ($manifest -match 'allowBackup[^\r\n]*0x0') 'Device backup must be disabled.'
Assert-QA ($manifest -match 'usesCleartextTraffic[^\r\n]*0x0') 'Global cleartext must be disabled.'
Assert-QA ($manifest -match 'android.permission.INTERNET') 'Internet permission is required.'
Assert-QA ($manifest -notmatch 'android.permission.ACCESS_BACKGROUND_LOCATION') 'Background location is not approved.'
Assert-QA ($manifest -notmatch 'android.permission.FOREGROUND_SERVICE_LOCATION') 'Location foreground service is not approved.'
$signature = @(& (Join-Path $tools 'apksigner.bat') verify --verbose $artifact 2>&1) -join "`n"
Assert-QA ($LASTEXITCODE -eq 0 -and $signature -match 'Verified using v2 scheme.*true') 'APK v2 signature verification failed.'
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [IO.Compression.ZipFile]::OpenRead($artifact)
try {
    $names = @($archive.Entries | ForEach-Object { $_.FullName })
    $abis = @($names | Where-Object { $_ -match '^lib/([^/]+)/' } | ForEach-Object { ($_ -split '/')[1] } | Sort-Object -Unique)
    Assert-QA ($abis.Count -eq 1 -and $abis[0] -eq 'arm64-v8a') 'APK engine/native ABI disagreement.'
    Assert-QA ($names -contains 'lib/arm64-v8a/libflutter.so') 'Flutter engine is absent.'
    $secretFiles = @($names | Where-Object { $_ -match '(?i)(^|/)(\.env(?:\.[^/]*)?|key\.properties|Secrets\.xcconfig|[^/]*service[-_]?account[^/]*\.json|mobile-fixtures\.json|admin-browser\.json)$' })
    Assert-QA ($secretFiles.Count -eq 0) 'Private configuration/test fixtures must not be bundled.'
} finally { $archive.Dispose() }
$xmlFiles = @(Get-ChildItem (Join-Path $projectRoot 'mobile/clevo/android/app/src') -File -Recurse -Filter '*.xml')
$xmlFiles += @(Get-Item (Join-Path $projectRoot 'mobile/clevo/ios/Runner/Info.plist'), (Join-Path $projectRoot 'mobile/clevo/ios/Runner/Runner.entitlements'))
foreach ($file in $xmlFiles) {
    $reader = $null
    try {
        $settings = New-Object System.Xml.XmlReaderSettings
        $settings.DtdProcessing = [Xml.DtdProcessing]::Ignore
        $settings.XmlResolver = $null
        $reader = [Xml.XmlReader]::Create($file.FullName, $settings)
        while ($reader.Read()) { }
    } finally { if ($reader) { $reader.Dispose() } }
}
Assert-QA ($xmlFiles.Count -gt 0) 'Native XML metadata was not inspected.'
$result = @{ checks = $checks; failed = 0; xml_files = $xmlFiles.Count; sha256 = (Get-FileHash -LiteralPath $artifact -Algorithm SHA256).Hash; bytes = (Get-Item -LiteralPath $artifact).Length; abi = $abis; native_device_tests = 'not run'; signing = 'debug only'; default_api = 'unconfigured; not a deployment build' }
$result | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $projectRoot '.security-test/qa-android-artifact.json') -Encoding UTF8
Write-Output ('Android artifact checks: ' + $checks + '/' + $checks + ' passed; native XML files=' + $xmlFiles.Count + '. No device integration test is implied.')
