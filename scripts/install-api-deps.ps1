# Instala PHPMailer em api/vendor (necessário para envio de email SMTP).
# Executar: powershell -ExecutionPolicy Bypass -File scripts\install-api-deps.ps1

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$Api = Join-Path $Root "api"
Set-Location $Api

$phpDirs = Get-ChildItem "C:\laragon\bin\php" -Directory -ErrorAction SilentlyContinue | Sort-Object Name -Descending
$php = $null
foreach ($d in $phpDirs) {
    $exe = Join-Path $d.FullName "php.exe"
    if (Test-Path $exe) { $php = $exe; break }
}

if (-not $php) {
    Write-Host "PHP nao encontrado. Instale Laragon ou adicione php ao PATH." -ForegroundColor Red
    exit 1
}

if (-not (Test-Path "composer.phar")) {
    Write-Host "A transferir composer.phar..." -ForegroundColor Cyan
    & $php -r "copy('https://getcomposer.org/download/latest-stable/composer.phar', 'composer.phar');"
}

Write-Host "composer install em api/..." -ForegroundColor Cyan
& $php composer.phar install --no-dev --optimize-autoloader

if (Test-Path "vendor\autoload.php") {
    Write-Host "OK: api/vendor instalado (PHPMailer)." -ForegroundColor Green
} else {
    Write-Host "Falha: vendor/autoload.php nao encontrado." -ForegroundColor Red
    exit 1
}
