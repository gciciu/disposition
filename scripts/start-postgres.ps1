# Start local PostgreSQL 17 (cluster in %LOCALAPPDATA%\PostgreSQL\17\data)
$ErrorActionPreference = "Stop"

$pgBin = "C:\Program Files\PostgreSQL\17\bin"
$dataDir = Join-Path $env:LOCALAPPDATA "PostgreSQL\17\data"
$logFile = Join-Path $env:LOCALAPPDATA "PostgreSQL\17\postgres.log"

if (-not (Test-Path (Join-Path $pgBin "pg_ctl.exe"))) {
    throw "PostgreSQL 17 not found at $pgBin"
}

if (-not (Test-Path (Join-Path $dataDir "PG_VERSION"))) {
    throw "Data directory missing: $dataDir. Initialize the cluster first."
}

& "$pgBin\pg_isready.exe" -h 127.0.0.1 -p 5432 2>$null | Out-Null
if ($LASTEXITCODE -eq 0) {
    Write-Host "PostgreSQL already running on 127.0.0.1:5432"
    exit 0
}

New-Item -ItemType Directory -Force -Path (Split-Path $logFile) | Out-Null
& "$pgBin\pg_ctl.exe" -D $dataDir -l $logFile start
if ($LASTEXITCODE -ne 0) {
    # pg_ctl may report failure while the server is actually up (WDAC / wait quirks)
    Start-Sleep -Seconds 1
    & "$pgBin\pg_isready.exe" -h 127.0.0.1 -p 5432 2>$null | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to start PostgreSQL. See $logFile"
    }
}

Write-Host "PostgreSQL started on 127.0.0.1:5432"
Write-Host "  db=delivery  user=delivery  password=delivery"
