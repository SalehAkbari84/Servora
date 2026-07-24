# ─────────────────────────────────────────────────────────────
# Servora — اسکریپت اجرای فرانت‌اند
#
# سرور Next.js dev را روی http://localhost:3000 اجرا می‌کند.
# اگر node_modules نصب نباشد، ابتدا npm install اجرا می‌شود.
#
# برای توقف: Ctrl+C
# ─────────────────────────────────────────────────────────────

$ErrorActionPreference = 'Stop'
$frontendPath = Join-Path $PSScriptRoot 'frontend'

if (-not (Test-Path $frontendPath)) {
    Write-Host "❌ پوشه frontend پیدا نشد در: $frontendPath" -ForegroundColor Red
    exit 1
}

if (-not (Get-Command npm -ErrorAction SilentlyContinue)) {
    Write-Host "❌ npm در PATH نیست. Node.js 20+ نصب کنید." -ForegroundColor Red
    exit 1
}

# نصب dependencies در صورت نیاز
$nodeModules = Join-Path $frontendPath 'node_modules'
if (-not (Test-Path $nodeModules)) {
    Write-Host "📦 node_modules پیدا نشد، در حال اجرای npm install..." -ForegroundColor Yellow
    Push-Location $frontendPath
    npm install
    Pop-Location
}

Write-Host "🚀 Servora Frontend در حال راه‌اندازی..." -ForegroundColor Cyan
Write-Host "   └─ Next.js dev → http://localhost:3000" -ForegroundColor White
Write-Host ""
Write-Host "💡 برای توقف: Ctrl+C" -ForegroundColor Gray
Write-Host ""

$Host.UI.RawUI.WindowTitle = 'Servora Frontend — :3000'

Push-Location $frontendPath
try {
    npm run dev
} finally {
    Pop-Location
}
