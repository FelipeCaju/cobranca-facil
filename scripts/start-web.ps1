# Sobe o Cobx no browser — Apache (Laragon) ou PHP embutido na porta 8080.
# Executar: powershell -ExecutionPolicy Bypass -File scripts\start-web.ps1

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root

function Test-PortListening([int]$Port) {
    $conn = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
    return $null -ne $conn
}

$PhpDir = Get-ChildItem "C:\laragon\bin\php" -Directory -ErrorAction SilentlyContinue | Sort-Object Name -Descending | Select-Object -First 1
if (-not $PhpDir) {
    Write-Host "PHP do Laragon nao encontrado em C:\laragon\bin\php" -ForegroundColor Red
    exit 1
}
$Php = Join-Path $PhpDir.FullName "php.exe"

$apacheOk = Test-PortListening 80
$port8080 = Test-PortListening 8080

Write-Host ""
Write-Host "=== Cobx — como abrir no browser ===" -ForegroundColor Cyan
Write-Host ""

if ($apacheOk) {
    Write-Host "  Apache (Laragon) esta activo na porta 80:" -ForegroundColor Green
    Write-Host "    http://localhost/cobx/" -ForegroundColor Yellow
    Write-Host "    http://localhost/cobx/login" -ForegroundColor Yellow
    Write-Host ""
}

if (-not $apacheOk) {
    Write-Host "  Apache NAO esta a correr (porta 80 fechada)." -ForegroundColor Red
    Write-Host "  No Laragon: clique em Start All (Apache + MySQL)." -ForegroundColor DarkYellow
    Write-Host ""
}

if ($port8080) {
    Write-Host "  Porta 8080 ja em uso — servidor PHP pode ja estar activo:" -ForegroundColor Green
    Write-Host "    http://localhost:8080/" -ForegroundColor Yellow
    Write-Host ""
    exit 0
}

if (-not $apacheOk) {
    Write-Host "  A iniciar servidor PHP na porta 8080 (alternativa sem Apache)..." -ForegroundColor Cyan
    Write-Host "  Abra: http://localhost:8080/" -ForegroundColor Yellow
    Write-Host "  Pare com Ctrl+C nesta janela." -ForegroundColor DarkGray
    Write-Host ""
    $env:APP_URL = "http://localhost:8080"
    & $Php -S 127.0.0.1:8080 "$Root\router-dev.php"
    exit $LASTEXITCODE
}

Write-Host "  Para usar a porta 8080 (como o Vite antigo), execute de novo com:" -ForegroundColor DarkGray
Write-Host "    `$env:COBX_FORCE_8080='1'; powershell -File scripts\start-web.ps1" -ForegroundColor DarkGray
Write-Host ""

if ($env:COBX_FORCE_8080 -eq '1') {
    $env:APP_URL = "http://localhost:8080"
    & $Php -S 127.0.0.1:8080 "$Root\router-dev.php"
}
