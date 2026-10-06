# Stop local PostgreSQL 17
$ErrorActionPreference = "Stop"

$pgBin = "C:\Program Files\PostgreSQL\17\bin"
$dataDir = Join-Path $env:LOCALAPPDATA "PostgreSQL\17\data"

& "$pgBin\pg_isready.exe" -h 127.0.0.1 -p 5432 2>$null | Out-Null
if ($LASTEXITCODE -ne 0) {
    Write-Host "PostgreSQL is not running"
    exit 0
}

& "$pgBin\pg_ctl.exe" -D $dataDir stop -m fast
Write-Host "PostgreSQL stopped"
