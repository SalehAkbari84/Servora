<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Services;

use App\Models\OtpCode;
use App\Models\Setting;
use App\Services\Sms\SmsIrClient;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OTP lifecycle manager.
 *
 *   request(phone, purpose) → generate code, store hash, send via sms.ir,
 *                              enforce per-phone cooldown + per-hour cap
 *   verify(phone, code, purpose) → check hash + attempts + expiry,
 *                                   mark `verified_at` on success
 *   consume(phone, purpose) → mark `consumed_at` after the final flow
 *                              (registration submit / login token issue)
 *                              completes. After consumption no replay.
 *
 * Tuning knobs at the top — adjust freely; defaults match Iranian app norms.
 */
class OtpService
{
    /** Defaults — admin can override via `otp_cooldown_seconds` setting. */
    private const COOLDOWN_SECONDS = 60;

    /** Defaults — admin can override via `otp_max_per_hour` setting. */
    private const HOURLY_CAP = 5;

    /** validity window — code stops working after this many seconds */
    private const TTL_SECONDS = 120;

    /** verified_at + this window = how long the user has to complete the flow */
    private const POST_VERIFY_WINDOW_MIN = 15;

    /** wrong tries before a code is locked */
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly SmsIrClient $sms) {}

    /**
     * Generate + send a fresh OTP. Returns:
     *   ['success' => true,  'cooldown_until' => unix, 'message_id' => int]
     *   ['success' => false, 'message' => 'Persian error', 'retry_in' => seconds]
     */
    public function request(string $phone, string $purpose, ?string $ip = null): array
    {
        $phone = $this->normalizePhone($phone);
        if (!$this->isValidIranianMobile($phone)) {
            return ['success' => false, 'message' => 'شماره موبایل نامعتبر است'];
        }

        // Admin-overridable thresholds — clamped to sane bounds so a fat-fingered
        // setting can't disable the protection or DOS legitimate users.
        $cooldown = max(10, min(600,
            (int) \App\Models\Setting::get('otp_cooldown_seconds', (string) self::COOLDOWN_SECONDS)
        ));
        $hourCap = max(1, min(50,
            (int) \App\Models\Setting::get('otp_max_per_hour', (string) self::HOURLY_CAP)
        ));

        // ── Cooldown: same phone+purpose ──────────────────────────
        $recent = OtpCode::where('phone', $phone)
            ->where('purpose', $purpose)
            ->where('created_at', '>=', now()->subSeconds($cooldown))
            ->orderByDesc('id')
            ->first();
        if ($recent) {
            $elapsed = max(0, time() - $recent->created_at->timestamp);
            $retryIn = max(1, $cooldown - $elapsed);
            return [
                'success'  => false,
                'message'  => sprintf('برای ارسال مجدد %d ثانیه صبر کنید', $retryIn),
                'retry_in' => $retryIn,
            ];
        }

        // ── Hourly cap: across all purposes for this phone ─────────
        $hourly = OtpCode::where('phone', $phone)
            ->where('created_at', '>=', now()->subHour())
            ->count();
        if ($hourly >= $hourCap) {
            return [
                'success' => false,
                'message' => 'تعداد درخواست بیش از حد مجاز. یک ساعت دیگر تلاش کنید.',
            ];
        }

        // ── Generate + persist BEFORE sending so a slow SMS API can't
        //    let two parallel requests squeeze through the cooldown.
        $code = $this->generateCode();
        $otp = OtpCode::create([
            'phone'      => $phone,
            'code_hash'  => hash('sha256', $code),
            'purpose'    => $purpose,
            'attempts'   => 0,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
            'ip'         => $ip,
        ]);
        $cooldownForResponse = $cooldown;

        // ── Master switch: SMS for OTP enabled? ─────────────────────
        // Admin can flip this OFF during development to avoid burning
        // sms.ir credit on every test login. The OTP system still
        // functions — the code is just returned in the response instead
        // of being SMS'd, and the UI prints it on screen so the user can
        // copy it into the verify field.
        $smsEnabled = Setting::get('sms_otp_enabled', '1') === '1';

        if (!$smsEnabled) {
            Log::info('otp.sms_disabled', ['phone' => $phone, 'purpose' => $purpose]);
            return [
                'success'        => true,
                'cooldown_until' => now()->addSeconds($cooldownForResponse)->timestamp,
                'expires_in'     => self::TTL_SECONDS,
                // dev_* fields signal the frontend to display the code inline.
                // Production payloads never carry these.
                'dev_mode'       => true,
                'dev_code'       => $code,
                'dev_message'    => 'حالت توسعه — پیامک ارسال نشد. کد را از همین صفحه کپی کنید.',
            ];
        }

        // ── Send via sms.ir ──────────────────────────────────────────
        $sendResult = $this->sms->sendOtp($phone, $code);

        if (!$sendResult['success']) {
            // Roll back: delete the unsent row so cooldown doesn't trap the user
            $otp->delete();
            return [
                'success' => false,
                'message' => $sendResult['message'] ?? 'ارسال پیامک ناموفق بود',
            ];
        }

        if (!empty($sendResult['message_id'])) {
            $otp->update(['provider_message_id' => (string) $sendResult['message_id']]);
        }

        return [
            'success'        => true,
            'cooldown_until' => now()->addSeconds($cooldownForResponse)->timestamp,
            'expires_in'     => self::TTL_SECONDS,
        ];
    }

    /**
     * Verify a user-supplied code. On success marks `verified_at`.
     * On failure increments attempts; after MAX_ATTEMPTS the code is invalid.
     */
    public function verify(string $phone, string $code, string $purpose): array
    {
        $phone = $this->normalizePhone($phone);
        $code  = $this->normalizeDigits($code);

        $otp = OtpCode::where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->first();

        if (!$otp) {
            return ['success' => false, 'message' => 'کدی برای این شماره ثبت نشده است'];
        }

        if ($otp->expires_at->isPast()) {
            return ['success' => false, 'message' => 'کد منقضی شده — کد جدید درخواست کنید'];
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            return ['success' => false, 'message' => 'تعداد تلاش زیاد. کد جدید درخواست کنید.'];
        }

        if (!hash_equals($otp->code_hash, hash('sha256', $code))) {
            $otp->increment('attempts');
            $remaining = self::MAX_ATTEMPTS - $otp->attempts;
            return [
                'success' => false,
                'message' => $remaining > 0
                    ? sprintf('کد اشتباه است (%d تلاش باقی مانده)', $remaining)
                    : 'تعداد تلاش به پایان رسید — کد جدید درخواست کنید',
            ];
        }

        // Success — mark verified. Code remains usable for POST_VERIFY_WINDOW_MIN
        // to allow the user to complete the registration/login flow.
        $otp->update(['verified_at' => now()]);
        return ['success' => true, 'otp_id' => $otp->id];
    }

    /**
     * Confirm the user successfully passed OTP verification recently.
     * Used by register() to gate account creation: phone must have a
     * verified, unconsumed OTP within the post-verify window.
     */
    public function checkVerifiedAndConsume(string $phone, string $purpose): bool
    {
        $phone = $this->normalizePhone($phone);

        return DB::transaction(function () use ($phone, $purpose) {
            $otp = OtpCode::where('phone', $phone)
                ->where('purpose', $purpose)
                ->whereNotNull('verified_at')
                ->whereNull('consumed_at')
                ->where('verified_at', '>=', now()->subMinutes(self::POST_VERIFY_WINDOW_MIN))
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (!$otp) return false;

            $otp->update(['consumed_at' => now()]);
            return true;
        });
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    /** Strip whitespace, convert Persian/Arabic digits, keep only the canonical 09... form. */
    private function normalizePhone(string $raw): string
    {
        $s = $this->normalizeDigits(trim($raw));
        $s = preg_replace('/\s+|[-()]/', '', $s);
        // +98... or 0098... or 98... → 0...
        $s = preg_replace('/^\+?98/', '0', $s);
        return $s;
    }

    private function normalizeDigits(string $s): string
    {
        return strtr($s, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    private function isValidIranianMobile(string $phone): bool
    {
        return (bool) preg_match('/^09\d{9}$/', $phone);
    }

    /**
     * 5-digit numeric code with no leading zero (so it always displays as 5 chars
     * after the user types it — easier UX in OTP inputs).
     */
    private function generateCode(): string
    {
        return (string) random_int(10000, 99999);
    }
}
