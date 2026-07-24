<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Services\Sms;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for sms.ir REST API. Wraps two endpoints we currently use:
 *   - send/verify (template-based OTP)
 *   - credit (balance lookup, for admin dashboard later)
 *
 * Status-code mapping comes from [[reference-sms-ir]]. The caller gets
 * back a structured array with `success`, `code`, `message`, `data`. We
 * intentionally do NOT throw on application-level failures (status != 1) —
 * the caller decides whether to retry, fail, or surface to the user.
 *
 * Config precedence: DB `settings` table first (admin-editable), then
 * `.env` (deploy-time fallback). This means changing the API key in the
 * admin UI takes effect immediately without a redeploy.
 */
class SmsIrClient
{
    private const BASE_URL = 'https://api.sms.ir/v1';

    public function __construct(
        private readonly string $apiKey,
        private readonly int    $templateId,
        private readonly string $mode = 'production',
        /** Name of the OTP placeholder in the template, without #...# wrappers.
         *  Sandbox template (123456) uses `Code`; user-created templates can
         *  use any name (e.g. `OTP`, `VERIFICATIONCODE`). */
        private readonly string $otpParamName = 'OTP',
    ) {}

    /**
     * Build from current settings — admin-editable values win, with .env
     * as the fallback so the system still boots before settings are seeded.
     */
    public static function fromSettings(): self
    {
        return new self(
            apiKey:       Setting::get('sms_ir_api_key',         (string) env('SMS_IR_API_KEY', '')),
            templateId:   (int) Setting::get('sms_ir_template_id', (string) env('SMS_IR_TEMPLATE_ID', '123456')),
            mode:         Setting::get('sms_ir_mode',            (string) env('SMS_IR_MODE', 'sandbox')),
            otpParamName: Setting::get('sms_ir_otp_param_name',  (string) env('SMS_IR_OTP_PARAM_NAME', 'OTP')),
        );
    }

    /** Kept for backwards compatibility — delegates to fromSettings(). */
    public static function fromEnv(): self
    {
        return self::fromSettings();
    }

    /**
     * Send a one-time-code via the verify template. The sandbox template
     * (id 123456) has a single parameter `Code` matching the `#CODE#`
     * placeholder. Production templates may have more params; this
     * method only sends Code for now.
     *
     * @return array{success: bool, code: int, message: string, message_id?: int, cost?: float}
     */
    public function sendOtp(string $mobile, string $code): array
    {
        if ($this->apiKey === '') {
            return ['success' => false, 'code' => 0, 'message' => 'کلید SMS تنظیم نشده است'];
        }

        try {
            $http = Http::withHeaders([
                'X-API-KEY'    => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ])->timeout(15);

            // PHP on Windows often ships without a default CA bundle, causing
            // cURL 60 against api.sms.ir. We bundle Mozilla's cacert.pem and
            // hand it to Guzzle when present.
            $ca = storage_path('certs/cacert.pem');
            if (is_file($ca)) $http = $http->withOptions(['verify' => $ca]);

            $response = $http->post(self::BASE_URL . '/send/verify', [
                'mobile'     => $mobile,
                'templateId' => $this->templateId,
                'parameters' => [
                    ['name' => $this->otpParamName, 'value' => $code],
                ],
            ]);

            $body = $response->json() ?? [];
            $status = (int) ($body['status'] ?? 0);

            if ($status === 1) {
                return [
                    'success'    => true,
                    'code'       => 1,
                    'message'    => $body['message'] ?? 'موفق',
                    'message_id' => (int) ($body['data']['messageId'] ?? 0),
                    'cost'       => (float) ($body['data']['cost'] ?? 0),
                ];
            }

            Log::warning('sms_ir.send_failed', [
                'mobile' => $mobile,
                'status' => $status,
                'body'   => $body,
            ]);

            return [
                'success' => false,
                'code'    => $status,
                'message' => $body['message'] ?? $this->messageForStatus($status),
            ];
        } catch (\Throwable $e) {
            Log::error('sms_ir.exception', ['msg' => $e->getMessage()]);
            return [
                'success' => false,
                'code'    => -1,
                'message' => 'اتصال به سرویس پیامک ناموفق بود',
            ];
        }
    }

    /**
     * Send a chat-notification SMS to a business owner whose conversation
     * just received a new message but who's offline.
     *
     * Requires admin to have configured `sms_ir_chat_template_id` (an sms.ir
     * template with a `#NAME#` placeholder, or whatever name is set in
     * `sms_ir_chat_param_name`). If the template id is 0/missing we no-op
     * and log a warning rather than blow up the chat flow.
     *
     * @return array{success: bool, code: int, message: string, message_id?: int}
     */
    public function sendChatNotification(string $mobile, string $senderName): array
    {
        if ($this->apiKey === '') {
            return ['success' => false, 'code' => 0, 'message' => 'کلید SMS تنظیم نشده است'];
        }
        $templateId = (int) Setting::get('sms_ir_chat_template_id', '0');
        $paramName  = Setting::get('sms_ir_chat_param_name', 'NAME');
        if ($templateId <= 0) {
            Log::warning('sms_ir.chat.no_template', ['mobile' => $mobile]);
            return ['success' => false, 'code' => 0, 'message' => 'قالب پیامک چت تنظیم نشده'];
        }

        try {
            $http = Http::withHeaders([
                'X-API-KEY'    => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ])->timeout(15);
            $ca = storage_path('certs/cacert.pem');
            if (is_file($ca)) $http = $http->withOptions(['verify' => $ca]);

            $response = $http->post(self::BASE_URL . '/send/verify', [
                'mobile'     => $mobile,
                'templateId' => $templateId,
                'parameters' => [
                    ['name' => $paramName, 'value' => mb_substr($senderName, 0, 30)],
                ],
            ]);

            $body   = $response->json() ?? [];
            $status = (int) ($body['status'] ?? 0);
            if ($status === 1) {
                return [
                    'success'    => true,
                    'code'       => 1,
                    'message'    => $body['message'] ?? 'موفق',
                    'message_id' => (int) ($body['data']['messageId'] ?? 0),
                ];
            }
            Log::warning('sms_ir.chat.send_failed', [
                'mobile' => $mobile, 'status' => $status, 'body' => $body,
            ]);
            return ['success' => false, 'code' => $status, 'message' => $body['message'] ?? 'ناموفق'];
        } catch (\Throwable $e) {
            Log::error('sms_ir.chat.exception', ['msg' => $e->getMessage()]);
            return ['success' => false, 'code' => -1, 'message' => 'اتصال ناموفق'];
        }
    }

    /**
     * Send a generic notification SMS via the configured "notification"
     * template (used by the outbox worker for appointments / queue / etc.).
     *
     * Template must contain one placeholder whose name matches the
     * `sms_ir_notification_param_name` setting (default `MSG`). The body
     * is truncated to 80 chars so it fits in a single SMS segment.
     *
     * @return array{success: bool, code: int, message: string, message_id?: int, transient?: bool}
     *         `transient=true` means the failure looks like a temporary
     *         gateway issue (timeout, 5xx, network error) — the caller
     *         should retry later instead of giving up.
     */
    public function sendNotification(string $mobile, string $text): array
    {
        if ($this->apiKey === '') {
            return ['success' => false, 'code' => 0, 'message' => 'کلید SMS تنظیم نشده است', 'transient' => false];
        }
        $templateId = (int) Setting::get('sms_ir_notification_template_id', '0');
        $paramName  = Setting::get('sms_ir_notification_param_name', 'MSG');
        if ($templateId <= 0) {
            Log::info('sms_ir.notification.no_template', ['mobile' => $mobile]);
            return ['success' => false, 'code' => 0, 'message' => 'قالب پیامک اطلاع‌رسانی تنظیم نشده', 'transient' => false];
        }

        try {
            $http = Http::withHeaders([
                'X-API-KEY'    => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ])->timeout(15);
            $ca = storage_path('certs/cacert.pem');
            if (is_file($ca)) $http = $http->withOptions(['verify' => $ca]);

            $response = $http->post(self::BASE_URL . '/send/verify', [
                'mobile'     => $mobile,
                'templateId' => $templateId,
                'parameters' => [
                    ['name' => $paramName, 'value' => mb_substr($text, 0, 80)],
                ],
            ]);

            $body   = $response->json() ?? [];
            $status = (int) ($body['status'] ?? 0);
            if ($status === 1) {
                return [
                    'success'    => true,
                    'code'       => 1,
                    'message'    => $body['message'] ?? 'موفق',
                    'message_id' => (int) ($body['data']['messageId'] ?? 0),
                    'transient'  => false,
                ];
            }

            // 5xx from the gateway → treat as transient (retry later).
            $transient = $response->status() >= 500 || $response->status() === 0;

            Log::warning('sms_ir.notification.send_failed', [
                'mobile' => $mobile,
                'http'   => $response->status(),
                'status' => $status,
                'body'   => $body,
            ]);
            return [
                'success'   => false,
                'code'      => $status,
                'message'   => $body['message'] ?? 'ناموفق',
                'transient' => $transient,
            ];
        } catch (\Throwable $e) {
            // Network exception (timeout, DNS, TLS) — always retry later.
            Log::error('sms_ir.notification.exception', ['msg' => $e->getMessage()]);
            return ['success' => false, 'code' => -1, 'message' => 'اتصال ناموفق', 'transient' => true];
        }
    }

    /**
     * Get current credit balance. Used by the admin dashboard widget.
     *
     * @return array{success: bool, balance?: float, message?: string}
     */
    public function credit(): array
    {
        try {
            $http = Http::withHeaders(['X-API-KEY' => $this->apiKey])->timeout(10);
            $ca = storage_path('certs/cacert.pem');
            if (is_file($ca)) $http = $http->withOptions(['verify' => $ca]);
            $response = $http->get(self::BASE_URL . '/credit');

            $body = $response->json() ?? [];
            if ((int)($body['status'] ?? 0) === 1) {
                return ['success' => true, 'balance' => (float) $body['data']];
            }
            return ['success' => false, 'message' => $body['message'] ?? 'خطای ناشناخته'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'اتصال ناموفق'];
        }
    }

    /** Current run-time mode — `production` or `sandbox`. */
    public function mode(): string { return $this->mode; }

    /** Whether the client is configured (has a key). */
    public function isConfigured(): bool { return $this->apiKey !== ''; }

    /**
     * Translate sms.ir status codes into Persian messages we can surface
     * to admins. Full table lives in [[reference-sms-ir]] memory.
     */
    private function messageForStatus(int $code): string
    {
        return match (true) {
            $code === 0                   => 'درخواست با خطا مواجه شد',
            $code === 10                  => 'کلید سرویس نامعتبر است',
            $code === 11                  => 'کلید سرویس غیرفعال است',
            $code === 12                  => 'کلید محدود به IP خاصی است',
            in_array($code, [13, 14, 15]) => 'حساب sms.ir دچار مشکل است (تماس با ادمین)',
            $code === 20                  => 'تعداد درخواست بیش از حد مجاز',
            $code === 102                 => 'اعتبار پیامک کافی نیست',
            $code === 104                 => 'شماره موبایل نامعتبر است',
            $code === 113                 => 'قالب پیامک یافت نشد',
            $code === 115                 => 'این شماره در لیست سیاه است',
            default                       => "خطای سرویس پیامک (کد $code)",
        };
    }
}
