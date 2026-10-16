param(
    [Parameter(Mandatory = $true)]
    [string]$DatabaseHost,

    [Parameter(Mandatory = $true)]
    [int]$DatabasePort,

    [string]$DatabaseName = 'railway'
)

$phpExecutable = 'C:\xampp\php\php.exe'
$importer = Join-Path $PSScriptRoot 'import_railway.php'

if (-not (Test-Path -LiteralPath $phpExecutable)) {
    Write-Error "XAMPP PHP was not found at $phpExecutable"
    exit 1
}

$securePassword = Read-Host 'Railway MySQL password' -AsSecureString
$credential = [System.Management.Automation.PSCredential]::new('root', $securePassword)
$env:RAILWAY_DB_PASSWORD = $credential.GetNetworkCredential().Password

try {
    & $phpExecutable $importer $DatabaseHost $DatabasePort $DatabaseName
    exit $LASTEXITCODE
}
finally {
    Remove-Item Env:\RAILWAY_DB_PASSWORD -ErrorAction SilentlyContinue
    $credential = $null
    $securePassword = $null
}
