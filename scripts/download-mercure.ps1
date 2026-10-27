# Download the official Mercure hub binary into mercure/
param(
    [string]$Version = "v1.1.5"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$Dir = Join-Path $Root "mercure"
$Arch = [System.Runtime.InteropServices.RuntimeInformation]::OSArchitecture
$Asset = switch ($Arch) {
    "X64" { "mercure_Windows_x86_64.zip" }
    "Arm64" { "mercure_Windows_arm64.zip" }
    "X86" { "mercure_Windows_i386.zip" }
    default { throw "Unsupported arch: $Arch" }
}

$Url = "https://github.com/dunglas/mercure/releases/download/$Version/$Asset"
$Tmp = Join-Path $env:TEMP ("mercure-" + [guid]::NewGuid().ToString())
New-Item -ItemType Directory -Path $Tmp | Out-Null
try {
    $Zip = Join-Path $Tmp $Asset
    Write-Host "Downloading $Url"
    Invoke-WebRequest -Uri $Url -OutFile $Zip
    Expand-Archive -Path $Zip -DestinationPath $Tmp -Force
    $Bin = Get-ChildItem -Path $Tmp -Recurse -Filter "mercure.exe" | Select-Object -First 1
    if (-not $Bin) {
        throw "mercure.exe not found in archive"
    }
    Copy-Item -Force $Bin.FullName (Join-Path $Dir "mercure.exe")
    Write-Host "Installed $(Join-Path $Dir 'mercure.exe') ($Version)"
}
finally {
    Remove-Item -Recurse -Force $Tmp -ErrorAction SilentlyContinue
}
