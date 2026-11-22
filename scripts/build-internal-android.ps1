param([Parameter(Mandatory=$true)][string]$Config, [Parameter(Mandatory=$true)][string]$Flutter)
$ErrorActionPreference='Stop'
$projectRoot=Split-Path -Parent $PSScriptRoot
# Public config and native signing setup must be reviewed before a distributable internal build.
& php (Join-Path $PSScriptRoot 'mobile-release-preflight.php') $Config android
if ($LASTEXITCODE -ne 0) { throw 'Internal distribution blocked by missing approved release configuration. No build/publish attempted.' }
$resolvedConfig=(Resolve-Path -LiteralPath $Config).Path
$previousId=$env:CLEVO_ANDROID_APPLICATION_ID
$previousLabel=$env:CLEVO_APP_LABEL
try {
    $env:CLEVO_ANDROID_APPLICATION_ID='com.clevo.clevo.internal'
    $env:CLEVO_APP_LABEL='CLEVO Internal'
    Push-Location (Join-Path $projectRoot 'mobile/clevo')
    try {
        & $Flutter build apk --release --no-pub "--dart-define-from-file=$resolvedConfig"
        if ($LASTEXITCODE -ne 0) { throw 'Internal release build failed. Nothing uploaded.' }
    } finally { Pop-Location }
} finally {
    $env:CLEVO_ANDROID_APPLICATION_ID=$previousId
    $env:CLEVO_APP_LABEL=$previousLabel
}
# Registers no Play track and sends no artifact to testers.
