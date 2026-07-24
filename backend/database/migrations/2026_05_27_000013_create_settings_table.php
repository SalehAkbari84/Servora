<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Key-value site settings table — holds SMS gateway credentials,
 * font preference, site name, and any future global config.
 *
 * Values are stored as TEXT; the `type` hints at how the frontend
 * should render and validate the input.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_persian_ci';

            $table->bigIncrements('id');
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->enum('type', ['text', 'password', 'number', 'boolean', 'select'])->default('text');
            $table->string('label', 200);
            $table->string('group', 50)->default('general')->comment('general / sms / appearance / security');
            $table->text('description')->nullable();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        // Seed default settings
        $defaults = [
            // General
            ['key' => 'site_name',          'value' => 'سروورا',      'type' => 'text',     'label' => 'نام سایت',                   'group' => 'general'],
            ['key' => 'site_tagline',       'value' => 'نوبت‌دهی آنلاین', 'type' => 'text',  'label' => 'شعار سایت',                  'group' => 'general'],
            ['key' => 'support_phone',      'value' => '',             'type' => 'text',     'label' => 'شماره پشتیبانی',             'group' => 'general'],

            // SMS Gateway
            ['key' => 'sms_api_url',        'value' => '',             'type' => 'text',     'label' => 'آدرس API سامانه پیامک',     'group' => 'sms'],
            ['key' => 'sms_api_username',   'value' => '',             'type' => 'text',     'label' => 'نام کاربری پنل پیامک',       'group' => 'sms'],
            ['key' => 'sms_api_password',   'value' => 'password',     'type' => 'password', 'label' => 'رمز عبور پنل پیامک',          'group' => 'sms', 'description' => 'اگر خالی باشد، مقدار پیش‌فرض "password" ذخیره می‌شود.'],
            ['key' => 'sms_sender_number',  'value' => '',             'type' => 'text',     'label' => 'شماره فرستنده',              'group' => 'sms'],
            ['key' => 'sms_enabled',        'value' => '0',            'type' => 'boolean',  'label' => 'فعال‌سازی ارسال پیامک',       'group' => 'sms'],

            // Appearance
            ['key' => 'site_font',          'value' => 'Vazirmatn',    'type' => 'select',   'label' => 'فونت سایت',                  'group' => 'appearance', 'description' => 'Vazirmatn / IRANSans / Sahel'],
            ['key' => 'primary_color',      'value' => '#6366f1',      'type' => 'text',     'label' => 'رنگ اصلی',                   'group' => 'appearance'],

            // Security / Business rules
            ['key' => 'booking_window_days','value' => '30',           'type' => 'number',   'label' => 'بازه روزهای قابل رزرو',      'group' => 'security', 'description' => 'حداکثر روزهای آینده برای رزرو نوبت'],
            ['key' => 'queue_expiry_days',  'value' => '2',            'type' => 'number',   'label' => 'انقضای صف انتظار (روز)',     'group' => 'security'],
            ['key' => 'min_review_chars',   'value' => '0',            'type' => 'number',   'label' => 'حداقل تعداد نویسه نظر',      'group' => 'security'],
        ];

        $now = now();
        foreach ($defaults as $row) {
            DB::table('settings')->insert(array_merge($row, ['updated_at' => $now]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
