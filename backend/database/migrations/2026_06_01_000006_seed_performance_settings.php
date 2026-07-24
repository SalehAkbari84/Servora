<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the new "performance" settings group used by the response-caching
 * middleware and the upload validators. All rows are idempotent — they
 * are only inserted when their key doesn't already exist, so admin
 * customizations survive re-running migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [
            // Public-endpoint HTTP cache TTL (seconds). 0 = disabled.
            [
                'key'         => 'cache_public_seconds',
                'value'       => '60',
                'type'        => 'number',
                'group'       => 'performance',
                'label'       => 'مدت کش اطلاعات عمومی (ثانیه)',
                'description' => 'پاسخ‌های API عمومی (لیست کسب‌وکارها، دسته‌بندی‌ها، آمار سایت) برای این مدت در کش کاربر می‌مانند. 0 یعنی غیرفعال.',
                'order'       => 10,
            ],
            // Maximum upload size (megabytes) — applied by the avatar/logo endpoints
            [
                'key'         => 'upload_max_mb',
                'value'       => '2',
                'type'        => 'number',
                'group'       => 'performance',
                'label'       => 'حداکثر حجم آپلود (مگابایت)',
                'description' => 'حداکثر حجم تصویر پروفایل و لوگوی کسب‌وکار. 2 مگابایت توصیه می‌شود.',
                'order'       => 20,
            ],
            // Stats endpoint poll interval (seconds, frontend reads this)
            [
                'key'         => 'stats_poll_seconds',
                'value'       => '30',
                'type'        => 'number',
                'group'       => 'performance',
                'label'       => 'بازخوانی آمار داشبورد (ثانیه)',
                'description' => 'هر چند ثانیه آمار داشبورد ادمین و صاحب کسب‌وکار به‌روزرسانی می‌شود.',
                'order'       => 30,
            ],
            // Chat polling interval (seconds)
            [
                'key'         => 'chat_poll_seconds',
                'value'       => '3',
                'type'        => 'number',
                'group'       => 'performance',
                'label'       => 'بازخوانی چت (ثانیه)',
                'description' => 'فاصله بین بررسی پیام‌های جدید در صفحه چت باز.',
                'order'       => 40,
            ],
            // Listing page size (default per_page)
            [
                'key'         => 'list_page_size',
                'value'       => '15',
                'type'        => 'number',
                'group'       => 'performance',
                'label'       => 'تعداد ردیف در هر صفحه',
                'description' => 'تعداد پیش‌فرض ردیف‌های نمایش‌داده‌شده در جدول‌های مدیریت.',
                'order'       => 50,
            ],
            // Toggle: enable lazy-loading of images / heavy components
            [
                'key'         => 'enable_lazy_images',
                'value'       => '1',
                'type'        => 'boolean',
                'group'       => 'performance',
                'label'       => 'بارگذاری تنبل تصاویر',
                'description' => 'فعال‌سازی loading="lazy" روی تصاویر خارج از viewport (پیشنهاد می‌شود فعال بماند).',
                'order'       => 60,
            ],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('settings')->where('key', $row['key'])->exists();
            if ($exists) {
                // Sync metadata (label / description / group) but keep admin's value
                DB::table('settings')->where('key', $row['key'])->update([
                    'type'        => $row['type'],
                    'group'       => $row['group'],
                    'label'       => $row['label'],
                    'description' => $row['description'] ?? null,
                    'order'       => $row['order'] ?? 100,
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
                    'order'       => $row['order'] ?? 100,
                    'is_advanced' => false,
                    'updated_at'  => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'cache_public_seconds',
            'upload_max_mb',
            'stats_poll_seconds',
            'chat_poll_seconds',
            'list_page_size',
            'enable_lazy_images',
        ])->delete();
    }
};
