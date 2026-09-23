# Restaura frontend React (shadcn) + ficheiros recuperados do transcript
$ErrorActionPreference = "Stop"
$Root = "C:\laragon\www\cobx"
$Template = "C:\laragon\www\corretores"

$copyFiles = @(
    "package.json", "package-lock.json", "vite.config.ts", "vitest.config.ts",
    "tsconfig.json", "tsconfig.app.json", "tsconfig.node.json",
    "tailwind.config.ts", "postcss.config.js", "eslint.config.js", "components.json",
    "index.html"
)
foreach ($f in $copyFiles) {
    $src = Join-Path $Template $f
    if (Test-Path $src) {
        Copy-Item $src (Join-Path $Root $f) -Force
        Write-Host "  $f"
    }
}

$copyDirs = @(
    @{ From = "src\components\ui"; To = "src\components\ui" },
    @{ From = "src\hooks\use-toast.ts"; To = "src\hooks\use-toast.ts" },
    @{ From = "src\hooks\use-mobile.tsx"; To = "src\hooks\use-mobile.tsx" },
    @{ From = "src\lib\utils.ts"; To = "src\lib\utils.ts" },
    @{ From = "src\index.css"; To = "src\index.css" },
    @{ From = "src\main.tsx"; To = "src\main.tsx" },
    @{ From = "src\App.css"; To = "src\App.css" }
)
foreach ($d in $copyDirs) {
    $src = Join-Path $Template $d.From
    $dst = Join-Path $Root $d.To
    if (Test-Path $src) {
        $parent = Split-Path $dst -Parent
        if (-not (Test-Path $parent)) { New-Item -ItemType Directory -Path $parent -Force | Out-Null }
        if ((Get-Item $src).PSIsContainer) {
            if (Test-Path $dst) { Remove-Item $dst -Recurse -Force }
            Copy-Item $src $dst -Recurse -Force
        } else {
            Copy-Item $src $dst -Force
        }
        Write-Host "  $($d.To)"
    }
}

Write-Host "Template copiado. Configure vite.config.ts e App.tsx manualmente se necessario." -ForegroundColor Green
