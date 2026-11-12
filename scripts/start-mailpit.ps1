# Start Mailpit for local email testing (Windows).
# SMTP: 127.0.0.1:1025  |  UI: http://127.0.0.1:8025
$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$Dir = Join-Path $Root "mailpit"
$Exe = Join-Path $Dir "mailpit.exe"

if (-not (Test-Path $Exe)) {
    Write-Host "Mailpit binary missing - downloading..."
    & (Join-Path $PSScriptRoot "download-mailpit.ps1")
}

Write-Host "Mailpit SMTP 127.0.0.1:1025  |  UI http://127.0.0.1:8025  (Ctrl+C to stop)"
& $Exe --smtp 127.0.0.1:1025 --listen 127.0.0.1:8025
