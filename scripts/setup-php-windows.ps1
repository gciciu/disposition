# Configure PHP 8.4 on Windows for this project:
# - enable required extensions (openssl, pdo_pgsql, ...)
# - set OPENSSL_CONF so RSA key generation works
# - generate Lexik JWT keypair under backend/config/jwt
$ErrorActionPreference = "Stop"

$requiredExtensions = @(
    "curl",
    "fileinfo",
    "gd",
    "intl",
    "mbstring",
    "openssl",
    "pdo_pgsql",
    "pgsql",
    "sodium",
    "zip"
)

function Find-PhpHome {
    $phpCmd = Get-Command php -ErrorAction SilentlyContinue
    if ($null -ne $phpCmd) {
        return Split-Path -Parent $phpCmd.Source
    }

    $wingetPhp = Join-Path ${env:ProgramFiles} "PHP\8.4"
    if (Test-Path (Join-Path $wingetPhp "php.exe")) {
        return $wingetPhp
    }

    throw "PHP not found. Install with: winget install --id PHP.PHP.8.4"
}

$phpHome = Find-PhpHome
$phpExe = Join-Path $phpHome "php.exe"
$systemIni = Join-Path $phpHome "php.ini"
$opensslCnf = Join-Path $phpHome "extras\ssl\openssl.cnf"
$userIniDir = Join-Path $env:LOCALAPPDATA "PHP\8.4"
$userIni = Join-Path $userIniDir "php.ini"
$repoRoot = Split-Path -Parent $PSScriptRoot
$backendDir = Join-Path $repoRoot "backend"
$jwtDir = Join-Path $backendDir "config\jwt"
$genScript = Join-Path $PSScriptRoot "generate-jwt-keys.php"

if (-not (Test-Path $systemIni)) {
    $distIni = Join-Path $phpHome "php.ini-development"
    if (-not (Test-Path $distIni)) {
        throw "Neither php.ini nor php.ini-development found in $phpHome"
    }
    Copy-Item -LiteralPath $distIni -Destination $systemIni -ErrorAction SilentlyContinue
}

New-Item -ItemType Directory -Force -Path $userIniDir | Out-Null
$iniSource = if (Test-Path $systemIni) { $systemIni } else { Join-Path $phpHome "php.ini-development" }
# Always rebuild from the vendor php.ini so extension toggles stay predictable.
Copy-Item -LiteralPath $iniSource -Destination $userIni -Force

$content = Get-Content -LiteralPath $userIni -Raw
$extDir = ((Join-Path $phpHome "ext") -replace "\\", "/")
if ([regex]::IsMatch($content, '(?m)^;?\s*extension_dir\s*=')) {
    $content = [regex]::Replace($content, '(?m)^;?\s*extension_dir\s*=\s*.*$', "extension_dir = `"$extDir`"", 1)
} else {
    $content = "extension_dir = `"$extDir`"`r`n" + $content
}

foreach ($ext in $requiredExtensions) {
    $commented = "(?m)^;\s*extension\s*=\s*$ext\s*$"
    $enabled = "(?m)^extension\s*=\s*$ext\s*$"
    if ([regex]::IsMatch($content, $commented)) {
        $content = [regex]::Replace($content, $commented, "extension=$ext")
    } elseif (-not [regex]::IsMatch($content, $enabled)) {
        $content += "`r`nextension=$ext`r`n"
    }
}

# Delivery proof photos from mobile cameras often exceed PHP defaults (2M).
foreach ($pair in @(
    @{ Pattern = '(?m)^;?\s*upload_max_filesize\s*=\s*.*$'; Value = 'upload_max_filesize = 20M' },
    @{ Pattern = '(?m)^;?\s*post_max_size\s*=\s*.*$'; Value = 'post_max_size = 25M' }
)) {
    if ([regex]::IsMatch($content, $pair.Pattern)) {
        $content = [regex]::Replace($content, $pair.Pattern, $pair.Value, 1)
    } else {
        $content += "`r`n$($pair.Value)`r`n"
    }
}

Set-Content -LiteralPath $userIni -Value $content -NoNewline -Encoding ascii

[System.Environment]::SetEnvironmentVariable("PHPRC", $userIniDir, "User")
$env:PHPRC = $userIniDir

if (Test-Path $opensslCnf) {
    [System.Environment]::SetEnvironmentVariable("OPENSSL_CONF", $opensslCnf, "User")
    $env:OPENSSL_CONF = $opensslCnf
}

# Also patch the system php.ini when possible, so tools that ignore PHPRC still work.
try {
    $systemContent = Get-Content -LiteralPath $systemIni -Raw -ErrorAction Stop
    $systemContent = [regex]::Replace($systemContent, '(?m)^;?\s*extension_dir\s*=\s*.*$', "extension_dir = `"$extDir`"", 1)
    foreach ($ext in $requiredExtensions) {
        $commented = "(?m)^;\s*extension\s*=\s*$ext\s*$"
        $enabled = "(?m)^extension\s*=\s*$ext\s*$"
        if ([regex]::IsMatch($systemContent, $commented)) {
            $systemContent = [regex]::Replace($systemContent, $commented, "extension=$ext")
        } elseif (-not [regex]::IsMatch($systemContent, $enabled)) {
            $systemContent += "`r`nextension=$ext`r`n"
        }
    }
    Set-Content -LiteralPath $systemIni -Value $systemContent -NoNewline -Encoding ascii -ErrorAction Stop
    Write-Host "System php.ini updated: $systemIni"
} catch {
    Write-Host "System php.ini not writable (run elevated to patch it): $systemIni"
}

Write-Host "PHP home:     $phpHome"
Write-Host "php.ini:      $userIni"
Write-Host "PHPRC:        $env:PHPRC"
if ($env:OPENSSL_CONF) {
    Write-Host "OPENSSL_CONF: $env:OPENSSL_CONF"
}

$modules = & $phpExe -m
foreach ($ext in $requiredExtensions) {
    if ($modules -notcontains $ext) {
        throw "PHP extension '$ext' is not loaded. Check $userIni"
    }
}

$opensslOk = & $phpExe -r "echo defined('OPENSSL_KEYTYPE_RSA') ? '1' : '0';"
if ($opensslOk -ne "1") {
    throw "OPENSSL_KEYTYPE_RSA is still undefined"
}
Write-Host "OpenSSL:      OK"

New-Item -ItemType Directory -Force -Path $jwtDir | Out-Null
$privatePem = Join-Path $jwtDir "private.pem"
$publicPem = Join-Path $jwtDir "public.pem"

if ((Test-Path $privatePem) -and (Test-Path $publicPem)) {
    Write-Host "JWT keys:     already present in $jwtDir"
    Write-Host "Done. Open a new terminal so PHPRC/OPENSSL_CONF are picked up."
    exit 0
}

if (-not (Test-Path (Join-Path $backendDir "vendor"))) {
    throw "backend/vendor missing. Run: cd backend; composer install"
}

Push-Location $backendDir
try {
    & $phpExe bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path $privatePem) -or -not (Test-Path $publicPem)) {
        Write-Host "Lexik keygen failed; using scripts/generate-jwt-keys.php"
        & $phpExe $genScript
        if ($LASTEXITCODE -ne 0 -or -not (Test-Path $privatePem) -or -not (Test-Path $publicPem)) {
            throw "Failed to generate JWT keys"
        }
    }
} finally {
    Pop-Location
}

Write-Host "JWT keys:     $jwtDir"
Write-Host "Done. Open a new terminal so PHPRC/OPENSSL_CONF are picked up."
