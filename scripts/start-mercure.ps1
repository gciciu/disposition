# Start the Mercure hub for local development (Windows).
$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$Dir = Join-Path $Root "mercure"
$Exe = Join-Path $Dir "mercure.exe"
$EnvFile = Join-Path $Dir ".env"
$Example = Join-Path $Dir ".env.example"

if (-not (Test-Path $Exe)) {
    Write-Host "Mercure binary missing — downloading..."
    & (Join-Path $PSScriptRoot "download-mercure.ps1")
}

if (-not (Test-Path $EnvFile)) {
    Copy-Item $Example $EnvFile
    Write-Host "Created mercure/.env from .env.example"
}

Get-Content $EnvFile | ForEach-Object {
    $line = $_.Trim()
    if (-not $line -or $line.StartsWith("#") -or -not $line.Contains("=")) {
        return
    }
    $eq = $line.IndexOf("=")
    $key = $line.Substring(0, $eq).Trim()
    $value = $line.Substring($eq + 1).Trim()
    if (
        ($value.StartsWith('"') -and $value.EndsWith('"')) -or
        ($value.StartsWith("'") -and $value.EndsWith("'"))
    ) {
        $value = $value.Substring(1, $value.Length - 2)
    }
    Set-Item -Path "Env:$key" -Value $value
}

Set-Location $Dir
Write-Host "Mercure listening on $($env:SERVER_NAME) (Ctrl+C to stop)"
& $Exe run --config Caddyfile
