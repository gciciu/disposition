# Stop local MySQL (Windows service if present, otherwise mysqld process)
$ErrorActionPreference = "Stop"

$serviceNames = @("MySQL84", "MySQL80", "MySQL", "MySQL57")
$service = $null
foreach ($name in $serviceNames) {
    $service = Get-Service -Name $name -ErrorAction SilentlyContinue
    if ($null -ne $service) { break }
}

if ($null -ne $service -and $service.Status -eq "Running") {
    Stop-Service -Name $service.Name
    Write-Host "MySQL stopped ($($service.Name))"
    exit 0
}

$procs = Get-Process mysqld -ErrorAction SilentlyContinue
if ($null -eq $procs) {
    Write-Host "MySQL is not running"
    exit 0
}

$procs | Stop-Process -Force
Write-Host "MySQL stopped (mysqld process)"
