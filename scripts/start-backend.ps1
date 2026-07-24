# ─────────────────────────────────────────────────────────────
# Servora — اسکریپت اجرای بک‌اند
#
# دو پنجره PowerShell جدید باز می‌کند:
#   1) php artisan serve           → API روی http://127.0.0.1:8000
#   2) php artisan schedule:work   → پردازشگر outbox برای SMS/اعلانات (هر دقیقه)
#
# برای توقف: پنجره‌ی هرکدام را ببندید.
# ─────────────────────────────────────────────────────────────

$ErrorActionPreference = 'Stop'
$backendPath = Join-Path $PSScriptRoot 'backend'

if (-not (Test-Path $backendPath)) {
    Write-Host "❌ پوشه backend پیدا نشد در: $backendPath" -ForegroundColor Red
    exit 1
}

# آیا php در PATH هست؟
if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    Write-Host "❌ php در PATH نیست. نسخه‌ی PHP 8.2+ نصب کنید." -ForegroundColor Red
    exit 1
}

# آیا composer dependencies نصب شده؟
$autoload = Join-Path $backendPath 'vendor\autoload.php'
if (-not (Test-Path $autoload)) {
    Write-Host "📦 vendor پیدا نشد، در حال اجرای composer install..." -ForegroundColor Yellow
    Push-Location $backendPath
    composer install
    Pop-Location
}

# آیا .env وجود داره؟
$envFile = Join-Path $backendPath '.env'
if (-not (Test-Path $envFile)) {
    Write-Host "⚠️  فایل .env پیدا نشد. باید آن را با تنظیمات DB پر کنید." -ForegroundColor Yellow
    if (Test-Path (Join-Path $backendPath '.env.example')) {
        Copy-Item (Join-Path $backendPath '.env.example') $envFile
        Write-Host "✅ .env از .env.example کپی شد — لطفاً آن را ویرایش کنید." -ForegroundColor Green
    }
}

Write-Host "🚀 Servora Backend در حال راه‌اندازی..." -ForegroundColor Cyan
Write-Host "   ├─ API server      → http://127.0.0.1:8000" -ForegroundColor White
Write-Host "   └─ Outbox worker   → پردازش اعلانات/SMS هر دقیقه" -ForegroundColor White
Write-Host ""

# پنجره ۱: artisan serve
Start-Process powershell -ArgumentList @(
    '-NoExit',
    '-Command',
    "Set-Location '$backendPath'; `$Host.UI.RawUI.WindowTitle = 'Servora API — :8000'; Write-Host 'Servora API Server' -ForegroundColor Cyan; php artisan serve --host=127.0.0.1 --port=8000"
)

# تاخیر کوتاه تا اولی استارت بشه
Start-Sleep -Milliseconds 500

# پنجره ۲: scheduler (outbox worker)
Start-Process powershell -ArgumentList @(
    '-NoExit',
    '-Command',
    "Set-Location '$backendPath'; `$Host.UI.RawUI.WindowTitle = 'Servora Outbox Worker'; Write-Host 'Outbox Worker (scheduler)' -ForegroundColor Cyan; php artisan schedule:work"
)

Write-Host "✅ دو پنجره PowerShell باز شد." -ForegroundColor Green
Write-Host "   برای توقف هر سرویس، پنجره‌ی مربوطه را ببندید." -ForegroundColor Gray
