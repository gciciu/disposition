# Download the Mailpit binary into mailpit/
param(
    [string]$Version = "v1.33.0"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$Dir = Join-Path $Root "mailpit"
$Arch = [System.Runtime.InteropServices.RuntimeInformation]::OSArchitecture
$Asset = switch ($Arch) {
    "X64" { "mailpit-windows-amd64.zip" }
    "Arm64" { "mailpit-windows-arm64.zip" }
    default { throw "Unsupported arch: $Arch" }
}

if (-not (Test-Path $Dir)) {
    New-Item -ItemType Directory -Path $Dir | Out-Null
}

$Url = "https://github.com/axllent/mailpit/releases/download/$Version/$Asset"
$Tmp = Join-Path $env:TEMP ("mailpit-" + [guid]::NewGuid().ToString())
New-Item -ItemType Directory -Path $Tmp | Out-Null
try {
    $Zip = Join-Path $Tmp $Asset
    Write-Host "Downloading $Url"
    Invoke-WebRequest -Uri $Url -OutFile $Zip
    Expand-Archive -Path $Zip -DestinationPath $Tmp -Force
    $Bin = Get-ChildItem -Path $Tmp -Recurse -Filter "mailpit.exe" | Select-Object -First 1
    if (-not $Bin) {
        throw "mailpit.exe not found in archive"
    }
    Copy-Item -Force $Bin.FullName (Join-Path $Dir "mailpit.exe")
    Write-Host "Installed $(Join-Path $Dir 'mailpit.exe') ($Version)"
}
finally {
    Remove-Item -Recurse -Force $Tmp -ErrorAction SilentlyContinue
}
