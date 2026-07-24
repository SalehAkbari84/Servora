<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Sms\SmsIrClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AdminSettingsController extends Controller
{
    /**
     * GET /api/admin/settings — return all settings grouped by group.
     * For type=password rows, the actual ciphertext is masked with Setting::MASK
     * so it never reaches the browser.
     */
    public function index(): JsonResponse
    {
        $items = Setting::orderBy('group')->orderBy('order')->orderBy('id')->get();

        $grouped = [];
        foreach ($items as $s) {
            $value = $s->value;
            // Mask password values — never send ciphertext (or worse, plaintext) to the UI
            if ($s->type === 'password' && $value !== null && $value !== '') {
                $value = Setting::MASK;
            }

            $grouped[$s->group][] = [
                'key'         => $s->key,
                'value'       => $value,
                'type'        => $s->type,
                'label'       => $s->label,
                'description' => $s->description,
                'options'     => $s->options ? json_decode($s->options, true) : null,
                'order'       => (int) $s->order,
                'is_advanced' => (bool) $s->is_advanced,
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => $grouped,
            'message' => 'تنظیمات سایت.',
            'code'    => 200,
        ]);
    }

    /**
     * PUT /api/admin/settings — bulk-update settings from frontend.
     * Body: { items: [ {key, value}, ... ] }
     *
     * For type=password rows:
     *   - If the submitted value equals Setting::MASK (i.e. admin didn't touch
     *     the masked field), keep the existing ciphertext.
     *   - Otherwise, encrypt the new plaintext before persisting.
     *   - An empty password defaults to 'password' (then encrypted) so the
     *     SMS gateway credential never blows up on empty input.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items'         => 'required|array',
            'items.*.key'   => 'required|string|exists:settings,key',
            'items.*.value' => 'nullable|string',
        ]);

        foreach ($data['items'] as $row) {
            $setting = Setting::where('key', $row['key'])->first();
            if (!$setting) continue;

            $value = $row['value'] ?? '';

            if ($setting->type === 'password') {
                // 1) Mask sent back unchanged → keep existing ciphertext
                if ($value === Setting::MASK) {
                    continue;
                }
                // 2) Empty password — fall back to the literal 'password' so SMS still works
                if (trim($value) === '') {
                    $value = 'password';
                }
                // 3) Encrypt before persisting
                $value = Setting::encryptPassword($value);
            }

            $setting->update(['value' => $value]);
        }

        return response()->json([
            'success' => true,
            'data'    => null,
            'message' => 'تنظیمات ذخیره شد.',
            'code'    => 200,
        ]);
    }

    /**
     * GET /api/admin/sms/info — return live SMS provider status:
     *  - credit balance (live API call)
     *  - currently-resolved mode + template id
     *  - whether the client is configured (key present)
     * Lets the admin SMS panel show "وضعیت سرویس پیامک" without exposing the API key.
     */
    public function smsInfo(SmsIrClient $client): JsonResponse
    {
        $configured = $client->isConfigured();
        $mode       = $client->mode();
        $template   = (int) Setting::get('sms_ir_template_id', '0');

        $balance = null;
        $balanceError = null;
        if ($configured) {
            $r = $client->credit();
            if ($r['success']) $balance = $r['balance'];
            else               $balanceError = $r['message'] ?? null;
        }

        // Outbox health: how many SMS notifications are queued, retrying,
        // and how many have given up. Surfaces gateway outages without
        // requiring the admin to grep through logs.
        $outboxPending  = \App\Models\NotificationOutbox::where('status', 'pending')->count();
        $outboxRetrying = \App\Models\NotificationOutbox::where('status', 'pending')
            ->where('attempt_count', '>', 0)
            ->count();
        $outboxFailed   = \App\Models\NotificationOutbox::where('status', 'failed')->count();
        $oldestPending  = \App\Models\NotificationOutbox::where('status', 'pending')
            ->orderBy('created_at')
            ->value('created_at');

        return response()->json([
            'success' => true,
            'data'    => [
                'configured'    => $configured,
                'mode'          => $mode,
                'template_id'   => $template,
                'credit'        => $balance,
                'credit_error'  => $balanceError,
                'outbox'        => [
                    'pending'        => $outboxPending,
                    'retrying'       => $outboxRetrying,
                    'failed'         => $outboxFailed,
                    'oldest_pending' => $oldestPending?->toIso8601String(),
                ],
            ],
        ]);
    }

    /**
     * POST /api/admin/sms/test — send a test OTP to a phone using the CURRENT
     * settings. Returns the sms.ir response verbatim so the admin sees the real
     * status code (especially useful when debugging template-not-found etc).
     */
    /**
     * POST /api/admin/settings/upload — upload a brand asset and store its
     * resolved public URL into the matching settings row.
     *
     * Body (multipart): file, target=site_logo_url | site_favicon_url | og_image_url
     * Response: { value: "<storage_url>" }
     *
     * The file is saved under storage/app/public/brand/ and the URL is
     * computed via the public disk's url() helper so it remains valid even
     * if APP_URL changes.
     */
    public function upload(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'file'   => ['required', 'file', 'mimes:jpg,jpeg,png,webp,svg,ico', 'max:1024'],
                'target' => ['required', 'in:site_logo_url,site_favicon_url,og_image_url'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'فایل معتبر نیست (JPG/PNG/WebP/SVG/ICO، حداکثر ۱ مگابایت).',
                'errors'  => $e->errors(),
            ], 422);
        }

        $row = Setting::where('key', $request->input('target'))->first();
        if (!$row) {
            return response()->json(['success' => false, 'message' => 'تنظیمات هدف یافت نشد.'], 404);
        }

        // Delete previous asset to keep storage tidy (we own these paths).
        if ($row->value) {
            $prev = preg_replace('#^.*/storage/#', '', $row->value);
            if ($prev && Storage::disk('public')->exists($prev)) {
                Storage::disk('public')->delete($prev);
            }
        }

        $path = $request->file('file')->store('brand', 'public');
        $url  = Storage::disk('public')->url($path);

        $row->update(['value' => $url]);

        return response()->json([
            'success' => true,
            'data'    => ['value' => $url],
            'message' => 'فایل با موفقیت بارگذاری شد.',
        ]);
    }

    public function smsTest(Request $request, SmsIrClient $client): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'required|string|regex:/^09\d{9}$/',
        ]);

        // Generate a throwaway code — only used to verify the API call works
        $code = (string) random_int(10000, 99999);
        $result = $client->sendOtp($data['phone'], $code);

        return response()->json([
            'success' => $result['success'],
            'data'    => $result,
            'message' => $result['success']
                ? "پیامک تستی به {$data['phone']} ارسال شد (کد: {$code})"
                : ('ارسال ناموفق: ' . ($result['message'] ?? 'unknown')),
        ], $result['success'] ? 200 : 422);
    }

    /**
     * GET /api/settings/public — public-safe settings (site name, font, tagline).
     * No auth required. Excludes any *_password / *_username / *_api_* keys.
     */
    public function publicIndex(): JsonResponse
    {
        $publicKeys = [
            // Brand
            'site_name', 'site_tagline', 'site_description', 'site_font',
            'primary_color', 'rounded_corners',
            'hero_title', 'hero_subtitle',
            // Contact / footer
            'support_phone', 'support_email', 'contact_address', 'footer_text',
            // Branding assets (uploaded via admin)
            'site_logo_url', 'site_favicon_url',
            // SEO
            'meta_title', 'meta_description', 'meta_keywords',
            'google_analytics_id', 'enable_robots_index',
            'google_search_console_id', 'bing_webmaster_id',
            'google_tag_manager_id',     'meta_robots',
            'og_image_url',              'twitter_handle',
            'canonical_base_url',        'enable_sitemap',
            'enable_structured_data',
            // Auth UI hints
            'captcha_required_login', 'captcha_required_register',
            'password_min_length',
            // Maintenance (clients show banner)
            'maintenance_mode', 'maintenance_message',
            // Performance — frontend uses these to tune polling cadence + lazy load
            'stats_poll_seconds', 'chat_poll_seconds', 'list_page_size',
            'enable_lazy_images', 'upload_max_mb',
        ];
        $rows = Setting::whereIn('key', $publicKeys)->get(['key', 'value', 'type']);

        $map = [];
        foreach ($rows as $r) {
            // Cast booleans for the frontend's convenience
            if ($r->type === 'boolean') {
                $map[$r->key] = $r->value === '1';
            } else {
                $map[$r->key] = $r->value;
            }
        }

        // No-store is critical: the `maintenance_mode` flag travels through
        // this payload. If a CDN or browser cached it, the admin's toggle
        // wouldn't reach visitors until the cache expired (up to 60 s with
        // our public_cache middleware). For an emergency feature, that's
        // unacceptable — so we explicitly disable any caching layer.
        return response()->json([
            'success' => true,
            'data'    => $map,
            'code'    => 200,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate')
          ->header('Pragma',        'no-cache');
    }
}
