# Instalação local Cobx — PHP + MySQL (Laragon). Não requer Node/npm.
# Executar: powershell -ExecutionPolicy Bypass -File scripts\install-local.ps1

$ErrorActionPreference = "Stop"

function Invoke-MysqlFile([string]$file) {
    $prev = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    Get-Content $file -Raw | & $Mysql -u root cobx 2>&1 | Out-Null
    $ErrorActionPreference = $prev
}
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root

$MysqlDir = Get-ChildItem "C:\laragon\bin\mysql" -Directory -ErrorAction SilentlyContinue | Sort-Object Name -Descending | Select-Object -First 1
if (-not $MysqlDir) {
    Write-Host "Laragon nao encontrado em C:\laragon\bin. Instale o Laragon ou ajuste os caminhos neste script." -ForegroundColor Red
    exit 1
}
$Mysql = Join-Path $MysqlDir.FullName "bin\mysql.exe"

Write-Host "==> Base de dados cobx (schema)" -ForegroundColor Cyan
& $Mysql -u root -e "DROP DATABASE IF EXISTS cobx; CREATE DATABASE cobx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
Get-Content "$Root\database\mysql_schema.sql" -Raw | & $Mysql -u root cobx

Write-Host ""
Write-Host "Schema importado. Abra o instalador web para criar admin e empresa de teste." -ForegroundColor Green
Write-Host "  App:  http://localhost/cobx/" -ForegroundColor Yellow
Write-Host "  API:  http://localhost/cobx/api/  (webhooks e cron)" -ForegroundColor Yellow
Write-Host ""
Write-Host "Confirme Laragon: Apache + MySQL ligados." -ForegroundColor DarkGray
