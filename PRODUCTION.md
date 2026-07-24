# راهنمای استقرار و آماده‌سازی فروش — Servora

این فایل خلاصه‌ی سخت‌سازی‌های انجام‌شده و چک‌لیست لازم برای اجرای امن روی production یا تحویل به خریدار است.

---

## ۱) چه چیزهایی اصلاح شد (این نسخه)

**باگ‌های عملکردی / هماهنگی با دیتابیس**
- بازسازی فایل گم‌شده‌ی `database/servora_complete.sql` (بدون آن migration نصب پروسیجرها/تریگرها می‌شکست).
- کپچا: ستون `captchas.token` از `char(32)` به `char(36)` اصلاح شد (UUID استاندارد ۳۶ کاراکتر است) — `2026_05_31_000005`.
- ورود ادمین: افزودن `username` + `is_primary_admin` به ادمین. حالا از طریق **`AdminSeeder`** ساخته می‌شود.
- `setup.bat` حالا `AdminSeeder` را اجرا می‌کند؛ نصب تازه دیگر بدون ادمین (قفل‌شده) نمی‌ماند.
- سازگاری PHP 8.5: suppress هشدار deprecated در `backend/public/index.php` (که پاسخ JSON را خراب می‌کرد).

**سخت‌سازی امنیتی**
- CORS حالا env-driven است (`FRONTEND_URL` / `FRONTEND_URLS` در `.env`) — `config/cors.php`.
- افزودن `throttle:30,1` روی اندپوینت‌های عمومی auth و کپچا — `routes/api.php`.
- کوکی توکن روی HTTPS با فلگ `Secure` ست می‌شود — `frontend/src/lib/utils.ts`.

**UX**
- افزودن صفحه‌ی ۴۰۴ سفارشی (`app/not-found.tsx`) و error boundary (`app/error.tsx`).

**تأییدها**
- همه‌ی اندپوینت‌های خواندنی (عمومی/ادمین/owner/کاربر) پاسخ `200` دادند.
- پروسیجر `CreateAppointment` با schema فعلی هماهنگ است (کد نتیجه‌ی موفق).
- آپلودها روی disk `public` ذخیره می‌شوند و symlink موجود است.

---

## ۲) چک‌لیست استقرار production (Backend)

در `backend/.env`:
```env
APP_ENV=production
APP_DEBUG=false                 # حیاتی: جلوگیری از نشت stack trace
APP_URL=https://api.yourdomain.com
FRONTEND_URL=https://yourdomain.com
DB_PASSWORD=<رمز قوی و یکتا>
ADMIN_USERNAME=<دلخواه>
ADMIN_PHONE=<شماره ادمین>
ADMIN_PASSWORD=<رمز قوی — پیش‌فرض را عوض کنید>
```

سپس:
```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate          # اگر APP_KEY خالی است
php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\AdminSeeder --force
php artisan storage:link
php artisan config:cache route:cache view:cache event:cache
```
- **OPcache** را در `php.ini` فعال کنید (`opcache.enable=1`).
- دیتابیس MySQL باید در حال اجرا و کاراکترست `utf8mb4` باشد.
- برای زمان‌بند (یادآوری/صف پیامک): `php artisan schedule:work` یا یک cron روی `schedule:run`.

> اگر `.env` را تغییر دادید بعد از `config:cache`، حتماً `php artisan config:clear` بزنید.

## ۳) چک‌لیست استقرار production (Frontend)

در `frontend/.env.local`:
```env
NEXT_PUBLIC_API_URL=https://api.yourdomain.com/api
```
سپس:
```bash
npm ci
npm run build
npm run start        # به‌جای next dev
```

---

## ۴) نکات امنیتی هنگام فروش
- **`.env` را همراه پروژه نفرستید** — فقط `.env.example`. رمزها را خریدار خودش می‌گذارد.
- رمز پیش‌فرض ادمین (`Jackrichard@1384`) و رمزهای seed دمو را عوض کنید.
- برای production حتماً پشت **HTTPS** اجرا شود (فلگ Secure کوکی به آن وابسته است).
- کلیدهای سرویس پیامک (`SMS_IR_*`) و VAPID را خریدار با کلیدهای خودش پر کند.

---

## ۵) موارد اختیاری (بهبود آینده — الزامی نیست)
- جستجوی کسب‌وکار از `LIKE '%..%'` استفاده می‌کند؛ برای داده‌ی بزرگ می‌توان ایندکس **FULLTEXT** روی `businesses(name, description, address_text)` اضافه کرد.
- انتقال ذخیره‌ی توکن به کوکی `httpOnly` (نیازمند تغییر معماری auth؛ فعلاً با `SameSite=Strict` + `Secure` پوشش قابل‌قبول دارد).
