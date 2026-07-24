<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds two professional settings groups:
 *
 *   branding   — site logo (navbar) and favicon (browser tab)
 *   seo        — Search Console verification token, GTM id, robots
 *                directives, Open Graph image, Twitter handle, canonical
 *                base URL, sitemap toggle
 *
 * All values are admin-editable from /admin/settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now  = now();
        $rows = [
            // ── BRANDING ───────────────────────────────────────────
            [
                'key'         => 'site_logo_url',
                'value'       => '',
                'type'        => 'text',
                'group'       => 'branding',
                'label'       => 'لوگوی سایت (URL)',
                'description' => 'مسیر فایل لوگو (PNG/SVG/WebP). در navbar سایت اصلی نمایش داده می‌شود. خالی = لوگوی پیش‌فرض (آیکن تقویم).',
                'order'       => 5,
            ],
            [
                'key'         => 'site_favicon_url',
                'value'       => '',
                'type'        => 'text',
                'group'       => 'branding',
                'label'       => 'آیکن تب مرورگر (Favicon)',
                'description' => 'مسیر فایل favicon (ICO/PNG/SVG). در تب مرورگر و bookmark ها نمایش داده می‌شود. اندازه پیشنهادی: 32×32.',
                'order'       => 6,
            ],

            // ── SEO PRO ───────────────────────────────────────────
            [
                'key'         => 'google_search_console_id',
                'value'       => '',
                'type'        => 'text',
                'group'       => 'seo',
                'label'       => 'Google Search Console — کد تایید',
                'description' => 'مقدار meta tag google-site-verification (فقط همان رشته — بدون <meta>).',
                'order'       => 20,
            ],
            [
                'key'         => 'bing_webmaster_id',
                'value'       => '',
                'type'        => 'text',
                'group'       => 'seo',
                'label'       => 'Bing Webmaster Tools — کد تایید',
                'description' => 'مقدار meta tag msvalidate.01 برای ثبت سایت در Bing.',
                'order'       => 21,
            ],
            [
                'key'         => 'google_tag_manager_id',
                'value'       => '',
                'type'        => 'text',
                'group'       => 'seo',
                'label'       => 'Google Tag Manager — Container ID',
                'description' => 'شناسه container (مثال GTM-XXXXX). در صورت تنظیم، اسکریپت GTM به‌جای Google Analytics مستقیم لود می‌شود.',
                'order'       => 22,
            ],
            [
                'key'         => 'meta_robots',
                'value'       => 'index, follow',
                'type'        => 'select',
                'group'       => 'seo',
                'label'       => 'دستور robots meta',
                'description' => 'تعیین نحوه ایندکس کردن صفحات توسط موتورهای جستجو.',
                'options'     => [
                    ['value' => 'index, follow',     'label' => 'index, follow (پیش‌فرض)'],
                    ['value' => 'index, nofollow',   'label' => 'index, nofollow'],
                    ['value' => 'noindex, follow',   'label' => 'noindex, follow'],
                    ['value' => 'noindex, nofollow', 'label' => 'noindex, nofollow'],
                ],
                'order'       => 23,
            ],
            [
                'key'         => 'og_image_url',
                'value'       => '',
                'type'        => 'text',
                'group'       => 'seo',
                'label'       => 'تصویر Open Graph (اشتراک‌گذاری)',
                'description' => 'تصویری که هنگام به اشتراک گذاشتن سایت در شبکه‌های اجتماعی نمایش داده می‌شود. اندازه پیشنهادی: 1200×630.',
                'order'       => 24,
            ],
            [
                'key'         => 'twitter_handle',
                'value'       => '',
                'type'        => 'text',
                'group'       => 'seo',
                'label'       => 'حساب Twitter/X (با @)',
                'description' => 'برای پروتکل twitter:site در card ها (مثال: @servora).',
                'order'       => 25,
            ],
            [
                'key'         => 'canonical_base_url',
                'value'       => '',
                'type'        => 'text',
                'group'       => 'seo',
                'label'       => 'آدرس پایه canonical',
                'description' => 'دامنه‌ی اصلی سایت برای link rel="canonical" (مثال: https://servora.ir). خالی = استفاده از Request host.',
                'order'       => 26,
            ],
            [
                'key'         => 'enable_sitemap',
                'value'       => '1',
                'type'        => 'boolean',
                'group'       => 'seo',
                'label'       => 'فعال‌سازی sitemap.xml',
                'description' => 'تولید خودکار sitemap.xml در ریشه‌ی سایت برای موتورهای جستجو.',
                'order'       => 27,
            ],
            [
                'key'         => 'enable_structured_data',
                'value'       => '1',
                'type'        => 'boolean',
                'group'       => 'seo',
                'label'       => 'داده‌های ساختاریافته (JSON-LD)',
                'description' => 'افزودن schema.org LocalBusiness به صفحات کسب‌وکارها — بهبود نمایش در نتایج Google.',
                'order'       => 28,
            ],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('settings')->where('key', $row['key'])->exists();
            $base = [
                'type'        => $row['type'],
                'group'       => $row['group'],
                'label'       => $row['label'],
                'description' => $row['description'] ?? null,
                'options'     => isset($row['options']) ? json_encode($row['options'], JSON_UNESCAPED_UNICODE) : null,
                'order'       => $row['order'] ?? 100,
                'updated_at'  => $now,
            ];
            if ($exists) {
                DB::table('settings')->where('key', $row['key'])->update($base);
            } else {
                DB::table('settings')->insert(array_merge($base, [
                    'key'         => $row['key'],
                    'value'       => $row['value'] ?? '',
                    'is_advanced' => false,
                ]));
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'site_logo_url', 'site_favicon_url',
            'google_search_console_id', 'bing_webmaster_id', 'google_tag_manager_id',
            'meta_robots', 'og_image_url', 'twitter_handle', 'canonical_base_url',
            'enable_sitemap', 'enable_structured_data',
        ])->delete();
    }
};
