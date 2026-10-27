# Start local MySQL 8 (Windows service if present, otherwise mysqld process)
$ErrorActionPreference = "Stop"

$mysqlBinCandidates = @(
    "C:\Program Files\MySQL\MySQL Server 8.4\bin",
    "C:\Program Files\MySQL\MySQL Server 8.0\bin"
)
$mysqlBin = $mysqlBinCandidates | Where-Object { Test-Path (Join-Path $_ "mysqld.exe") } | Select-Object -First 1
$iniPath = Join-Path $env:ProgramData "MySQL\MySQL Server 8.4\my.ini"
if (-not (Test-Path $iniPath)) {
    $iniPath = Join-Path $env:ProgramData "MySQL\MySQL Server 8.0\my.ini"
}

function Test-MySqlReady {
    if ($null -eq $mysqlBin) { return $false }
    & (Join-Path $mysqlBin "mysqladmin.exe") --protocol=TCP -h "127.0.0.1" -P 3306 -u root --password= ping 2>$null | Out-Null
    return ($LASTEXITCODE -eq 0)
}

if (Test-MySqlReady) {
    Write-Host "MySQL already running on 127.0.0.1:3306"
    exit 0
}

$serviceNames = @("MySQL84", "MySQL80", "MySQL", "MySQL57")
$service = $null
foreach ($name in $serviceNames) {
    $service = Get-Service -Name $name -ErrorAction SilentlyContinue
    if ($null -ne $service) { break }
}

if ($null -ne $service) {
    if ($service.Status -ne "Running") {
        Start-Service -Name $service.Name
    }
    Write-Host "MySQL started via service $($service.Name) on 127.0.0.1:3306"
    exit 0
}

if ($null -eq $mysqlBin) {
    throw "MySQL not found. Install with: winget install --id Oracle.MySQL"
}
if (-not (Test-Path $iniPath)) {
    throw "MySQL config not found at $iniPath. Initialize the server data directory first (see README)."
}

Start-Process -FilePath (Join-Path $mysqlBin "mysqld.exe") -ArgumentList "--defaults-file=`"$iniPath`"" -WindowStyle Hidden
for ($i = 0; $i -lt 30; $i++) {
    Start-Sleep -Seconds 1
    if (Test-MySqlReady) {
        Write-Host "MySQL started (mysqld process) on 127.0.0.1:3306"
        exit 0
    }
}

throw "Failed to start MySQL. Check that the data directory is initialized."
