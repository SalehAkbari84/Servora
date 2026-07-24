<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand `settings` to support a richer admin UI:
 *
 *  - `options` JSON column → for type=select we can store options + labels
 *    instead of hardcoding in the frontend
 *  - `type` ENUM extended with 'textarea' and 'color'
 *  - `order` smallint so groups can be visually arranged
 *  - `is_advanced` boolean so we can hide power-user settings behind a toggle
 *
 * Plus seeds ~25 new settings across all groups, idempotent: any key that
 * already exists is left untouched (so we never overwrite admin edits).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->json('options')->nullable()->after('type')
                  ->comment('برای type=select — لیست مقادیر و برچسب‌ها');
            $table->unsignedSmallInteger('order')->default(100)->after('group')
                  ->comment('ترتیب نمایش در گروه');
            $table->boolean('is_advanced')->default(false)->after('order')
                  ->comment('فقط در حالت نمایش پیشرفته نشان داده می‌شود');
        });

        // Widen `type` enum to add 'textarea' and 'color'
        DB::statement("ALTER TABLE settings MODIFY `type` ENUM('text','textarea','password','number','boolean','select','color') NOT NULL DEFAULT 'text'");

        // Seed defaults — idempotent
        $now = now();
        $defaults = self::seedRows();

        foreach ($defaults as $row) {
            $exists = DB::table('settings')->where('key', $row['key'])->exists();
            if ($exists) {
                // Already there — just sync the metadata so labels/groups stay aligned,
                // but DO NOT overwrite the value the admin has set.
                DB::table('settings')->where('key', $row['key'])->update([
                    'type'        => $row['type'],
                    'group'       => $row['group'],
                    'label'       => $row['label'],
                    'description' => $row['description'] ?? null,
                    'options'     => isset($row['options']) ? json_encode($row['options'], JSON_UNESCAPED_UNICODE) : null,
                    'order'       => $row['order'] ?? 100,
                    'is_advanced' => $row['is_advanced'] ?? false,
                    'updated_at'  => $now,
                ]);
            } else {
                DB::table('settings')->insert([
                    'key'         => $row['key'],
                    'value'       => $row['value'] ?? '',
                    'type'        => $row['type'],
                    'group'       => $row['group'],
                    'label'       => $row['label'],
                    'description' => $row['description'] ?? null,
                    'options'     => isset($row['options']) ? json_encode($row['options'], JSON_UNESCAPED_UNICODE) : null,
                    'order'       => $row['order'] ?? 100,
                    'is_advanced' => $row['is_advanced'] ?? false,
                    'updated_at'  => $now,
                ]);
            }
        }

        // Drop legacy SMS keys that were for a different provider — sms.ir replaces them
        DB::table('settings')->whereIn('key', [
            'sms_api_url', 'sms_api_username', 'sms_api_password', 'sms_sender_number',
        ])->delete();
    }

    public function down(): void
    {
        // Schema reversion — we intentionally don't remove seeded rows since
        // admins may have customized them.
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['options', 'order', 'is_advanced']);
        });
        DB::statement("ALTER TABLE settings MODIFY `type` ENUM('text','password','number','boolean','select') NOT NULL DEFAULT 'text'");
    }

    /**
     * Master list of seeded settings. Order matters per-group: smaller `order`
     * shows first. Groups display in this order: general → branding → business
     * → security → sms → notifications → seo → maintenance.
     */
    private static function seedRows(): array
    {
        return [
            // ── General ─────────────────────────────────────────
            ['key'=>'site_name', 'group'=>'general', 'order'=>10, 'type'=>'text',
             'label'=>'نام سایت', 'description'=>'نام برند که در سربرگ و عنوان صفحات نمایش داده می‌شود.',
             'value'=>'سروورا'],
            ['key'=>'site_tagline', 'group'=>'general', 'order'=>20, 'type'=>'text',
             'label'=>'شعار سایت', 'description'=>'یک خط زیر نام برند.',
             'value'=>'نوبت‌دهی آنلاین'],
            ['key'=>'site_description', 'group'=>'general', 'order'=>30, 'type'=>'textarea',
             'label'=>'توضیحات سایت', 'description'=>'متن کوتاه درباره سایت (برای SEO و معرفی در صفحه اصلی).',
             'value'=>'پلتفرم رزرو نوبت آنلاین برای کسب‌وکارهای خدماتی'],
            ['key'=>'support_phone', 'group'=>'general', 'order'=>40, 'type'=>'text',
             'label'=>'شماره پشتیبانی', 'description'=>'برای نمایش در فوتر سایت.'],
            ['key'=>'support_email', 'group'=>'general', 'order'=>50, 'type'=>'text',
             'label'=>'ایمیل پشتیبانی', 'description'=>'برای دریافت پیام‌ها و گزارش‌ها.'],
            ['key'=>'contact_address', 'group'=>'general', 'order'=>60, 'type'=>'textarea',
             'label'=>'آدرس دفتر', 'description'=>'آدرس دفتر مرکزی برای صفحه تماس با ما (اختیاری).'],
            ['key'=>'footer_text', 'group'=>'general', 'order'=>70, 'type'=>'textarea',
             'label'=>'متن فوتر', 'description'=>'متن دلخواه که در پایین تمام صفحات نمایش داده می‌شود.',
             'value'=>'© ۱۴۰۵ — تمام حقوق محفوظ است.'],

            // ── Branding / appearance ───────────────────────────
            ['key'=>'primary_color', 'group'=>'branding', 'order'=>10, 'type'=>'color',
             'label'=>'رنگ اصلی', 'description'=>'رنگ دکمه‌ها، لینک‌ها و موارد برجسته در سرتاسر سایت.',
             'value'=>'#6366f1'],
            ['key'=>'site_font', 'group'=>'branding', 'order'=>20, 'type'=>'select',
             'label'=>'فونت سایت', 'description'=>'فونت پیش‌فرض برای تمام متون.',
             'options'=>[
                 ['value'=>'Vazirmatn','label'=>'Vazirmatn (پیشنهادی)'],
                 ['value'=>'IRANSans','label'=>'IRANSans'],
                 ['value'=>'Sahel','label'=>'Sahel'],
             ],
             'value'=>'Vazirmatn'],
            ['key'=>'rounded_corners', 'group'=>'branding', 'order'=>30, 'type'=>'select',
             'label'=>'گردی گوشه‌ها', 'description'=>'میزان نرم بودن گوشه‌های دکمه‌ها و کارت‌ها.',
             'options'=>[
                 ['value'=>'none','label'=>'تیز'],
                 ['value'=>'small','label'=>'کم'],
                 ['value'=>'medium','label'=>'متوسط'],
                 ['value'=>'large','label'=>'زیاد (پیش‌فرض)'],
                 ['value'=>'full','label'=>'دایره‌ای'],
             ],
             'value'=>'large'],
            ['key'=>'hero_title', 'group'=>'branding', 'order'=>40, 'type'=>'text',
             'label'=>'عنوان Hero', 'description'=>'تیتر بزرگ صفحه اصلی.',
             'value'=>'بهترین خدمات شهر شما، یک کلیک فاصله دارد'],
            ['key'=>'hero_subtitle', 'group'=>'branding', 'order'=>50, 'type'=>'textarea',
             'label'=>'زیرعنوان Hero', 'description'=>'پاراگراف توضیحی زیر تیتر اصلی.',
             'value'=>'سروورا، ساده‌ترین راه برای رزرو نوبت در کسب‌وکارهای معتبر شهر شما.'],

            // ── Business rules ──────────────────────────────────
            ['key'=>'booking_window_days', 'group'=>'business', 'order'=>10, 'type'=>'number',
             'label'=>'بازه رزرو نوبت (روز)', 'description'=>'چند روز جلوتر کاربر می‌تواند نوبت بگیرد.',
             'value'=>'30'],
            ['key'=>'cancellation_window_hours', 'group'=>'business', 'order'=>20, 'type'=>'number',
             'label'=>'مهلت لغو نوبت (ساعت)', 'description'=>'کاربر تا چند ساعت قبل از نوبت می‌تواند آن را لغو کند.',
             'value'=>'2'],
            ['key'=>'queue_expiry_days', 'group'=>'business', 'order'=>30, 'type'=>'number',
             'label'=>'اعتبار صف انتظار (روز)', 'description'=>'پس از این مدت ورودی‌های صف بدون نوبت منقضی می‌شوند.',
             'value'=>'2'],
            ['key'=>'max_appointments_per_day', 'group'=>'business', 'order'=>40, 'type'=>'number',
             'label'=>'حداکثر نوبت فعال هر کاربر (روز)', 'description'=>'برای جلوگیری از سوءاستفاده — صفر یعنی نامحدود.',
             'value'=>'5'],
            ['key'=>'default_appointment_duration', 'group'=>'business', 'order'=>50, 'type'=>'number',
             'label'=>'مدت پیش‌فرض هر نوبت (دقیقه)', 'description'=>'برای کسب‌وکارهایی که زمان خدماتشان را ست نکرده‌اند.',
             'value'=>'30'],
            ['key'=>'min_review_chars', 'group'=>'business', 'order'=>60, 'type'=>'number',
             'label'=>'حداقل کاراکتر نظر', 'description'=>'صفر یعنی نظر بدون متن هم قابل ثبت است.',
             'value'=>'0'],
            ['key'=>'review_requires_appointment', 'group'=>'business', 'order'=>70, 'type'=>'boolean',
             'label'=>'نظر فقط با نوبت انجام‌شده', 'description'=>'اگر فعال باشد، فقط کاربرانی که نوبت «انجام شده» داشته‌اند می‌توانند نظر بدهند.',
             'value'=>'1'],
            ['key'=>'require_verified_business', 'group'=>'business', 'order'=>80, 'type'=>'boolean',
             'label'=>'نمایش فقط کسب‌وکارهای تایید‌شده', 'description'=>'در لیست عمومی فقط کسب‌وکارهایی که توسط ادمین تایید شده‌اند نمایش داده می‌شوند.',
             'value'=>'1'],

            // ── Security ────────────────────────────────────────
            ['key'=>'captcha_required_register', 'group'=>'security', 'order'=>10, 'type'=>'boolean',
             'label'=>'CAPTCHA در ثبت‌نام', 'description'=>'الزام به حل کد امنیتی قبل از دریافت OTP ثبت‌نام.',
             'value'=>'1'],
            ['key'=>'captcha_required_login', 'group'=>'security', 'order'=>20, 'type'=>'boolean',
             'label'=>'CAPTCHA در ورود', 'description'=>'الزام به حل کد امنیتی قبل از ارسال OTP ورود.',
             'value'=>'1'],
            ['key'=>'otp_cooldown_seconds', 'group'=>'security', 'order'=>30, 'type'=>'number',
             'label'=>'فاصله بین درخواست OTP (ثانیه)', 'description'=>'مدت زمانی که کاربر باید بین دو درخواست کد منتظر بماند.',
             'value'=>'60',
             'is_advanced'=>true],
            ['key'=>'otp_max_per_hour', 'group'=>'security', 'order'=>40, 'type'=>'number',
             'label'=>'حداکثر OTP در ساعت', 'description'=>'هر شماره موبایل در هر ساعت چند کد می‌تواند بگیرد.',
             'value'=>'5',
             'is_advanced'=>true],
            ['key'=>'password_min_length', 'group'=>'security', 'order'=>50, 'type'=>'number',
             'label'=>'حداقل طول رمز عبور', 'description'=>'کاربران جدید رمز کوتاه‌تر از این مقدار نمی‌توانند انتخاب کنند.',
             'value'=>'6'],
            ['key'=>'session_lifetime_days', 'group'=>'security', 'order'=>60, 'type'=>'number',
             'label'=>'مدت اعتبار نشست (روز)', 'description'=>'کاربر پس از این مدت دوباره باید وارد شود.',
             'value'=>'7',
             'is_advanced'=>true],

            // ── SMS ─────────────────────────────────────────────
            ['key'=>'sms_enabled', 'group'=>'sms', 'order'=>10, 'type'=>'boolean',
             'label'=>'فعال‌سازی پیامک', 'description'=>'اگر خاموش باشد، هیچ پیامکی ارسال نخواهد شد (فقط در محل ثبت می‌شود).',
             'value'=>'1'],
            ['key'=>'sms_ir_mode', 'group'=>'sms', 'order'=>20, 'type'=>'select',
             'label'=>'حالت ارسال', 'description'=>'sandbox برای آزمایش بدون کسر اعتبار، production برای ارسال واقعی.',
             'options'=>[
                 ['value'=>'sandbox','label'=>'آزمایشی (Sandbox)'],
                 ['value'=>'production','label'=>'تولید (Production)'],
             ],
             'value'=>'production'],
            ['key'=>'sms_ir_api_key', 'group'=>'sms', 'order'=>30, 'type'=>'password',
             'label'=>'کلید API', 'description'=>'X-API-KEY از پنل sms.ir — رمزنگاری شده ذخیره می‌شود.'],
            ['key'=>'sms_ir_template_id', 'group'=>'sms', 'order'=>40, 'type'=>'number',
             'label'=>'شناسه قالب verify', 'description'=>'TemplateId قالب OTP که در پنل sms.ir تعریف کرده‌اید.'],
            ['key'=>'sms_ir_otp_param_name', 'group'=>'sms', 'order'=>50, 'type'=>'text',
             'label'=>'نام placeholder', 'description'=>'نام متغیر داخل قالب بدون # (برای #OTP# مقدار OTP).',
             'value'=>'OTP'],
            ['key'=>'sms_low_credit_threshold', 'group'=>'sms', 'order'=>60, 'type'=>'number',
             'label'=>'آستانه هشدار اعتبار کم', 'description'=>'اگر موجودی اعتبار از این عدد پایین‌تر بیاید، در داشبورد هشدار قرمز نمایش داده می‌شود.',
             'value'=>'50'],

            // ── Notifications ───────────────────────────────────
            ['key'=>'notify_admin_on_new_business', 'group'=>'notifications', 'order'=>10, 'type'=>'boolean',
             'label'=>'اعلان درخواست تایید کسب‌وکار', 'description'=>'وقتی کسب‌وکار جدیدی درخواست تایید بفرستد، به ادمین اعلان داده شود.',
             'value'=>'1'],
            ['key'=>'notify_admin_on_low_credit', 'group'=>'notifications', 'order'=>20, 'type'=>'boolean',
             'label'=>'اعلان اعتبار کم پیامک', 'description'=>'وقتی موجودی sms.ir از آستانه پایین‌تر آمد، اعلان درون‌سایت ارسال شود.',
             'value'=>'1'],
            ['key'=>'notification_admin_phone', 'group'=>'notifications', 'order'=>30, 'type'=>'text',
             'label'=>'شماره پیامک ادمین', 'description'=>'شماره دریافت‌کننده پیامک‌های حساس مدیریتی (مثل هشدار اعتبار).'],

            // ── SEO ─────────────────────────────────────────────
            ['key'=>'meta_title', 'group'=>'seo', 'order'=>10, 'type'=>'text',
             'label'=>'عنوان متا', 'description'=>'عنوانی که در نتایج گوگل و تب مرورگر نمایش داده می‌شود.',
             'value'=>'سروورا — نوبت‌دهی آنلاین'],
            ['key'=>'meta_description', 'group'=>'seo', 'order'=>20, 'type'=>'textarea',
             'label'=>'توضیحات متا', 'description'=>'متن خلاصه ۱۵۰-۱۶۰ کاراکتری برای نتایج جستجو.',
             'value'=>'سروورا — پلتفرم رزرو نوبت آنلاین برای کسب‌وکارهای شهر شما.'],
            ['key'=>'meta_keywords', 'group'=>'seo', 'order'=>30, 'type'=>'text',
             'label'=>'کلمات کلیدی', 'description'=>'لیست کلمات کلیدی جدا شده با ویرگول.',
             'value'=>'نوبت‌دهی, رزرو نوبت, نوبت آنلاین'],
            ['key'=>'google_analytics_id', 'group'=>'seo', 'order'=>40, 'type'=>'text',
             'label'=>'شناسه Google Analytics', 'description'=>'مثال: G-XXXXXXXXXX',
             'is_advanced'=>true],
            ['key'=>'enable_robots_index', 'group'=>'seo', 'order'=>50, 'type'=>'boolean',
             'label'=>'اجازه ایندکس توسط موتورهای جستجو', 'description'=>'خاموش کنید اگر سایت در حالت توسعه است.',
             'value'=>'1'],

            // ── Maintenance ─────────────────────────────────────
            ['key'=>'maintenance_mode', 'group'=>'maintenance', 'order'=>10, 'type'=>'boolean',
             'label'=>'حالت تعمیر و نگهداری', 'description'=>'اگر فعال شود، تمام کاربران به جز ادمین به صفحه «در حال تعمیر» هدایت می‌شوند.',
             'value'=>'0'],
            ['key'=>'maintenance_message', 'group'=>'maintenance', 'order'=>20, 'type'=>'textarea',
             'label'=>'پیام صفحه تعمیر', 'description'=>'متن نمایش داده شده به کاربران در حالت تعمیر.',
             'value'=>'در حال به‌روزرسانی سایت هستیم. به‌زودی برمی‌گردیم.'],
        ];
    }
};
