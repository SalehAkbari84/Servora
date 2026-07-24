<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CaptchaService;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Hardened admin login — 2 factor + CAPTCHA + per-IP rate limit.
 *
 * Step 1 (POST /api/admin/login):
 *   body: { username, password, phone, captcha_token, captcha_answer }
 *   - validates username + password
 *   - the submitted `phone` MUST match the admin's recorded phone (stops
 *     a credential leak from being exchanged for SMS to an attacker number)
 *   - validates CAPTCHA
 *   - sends OTP to the admin's phone
 *   - response: { success, expires_in, cooldown_until }
 *
 * Step 2 (POST /api/admin/login/verify):
 *   body: { username, code }
 *   - looks up admin by username
 *   - verifies OTP via OtpService (purpose=login)
 *   - issues a Sanctum token scoped to 'admin' ability
 *   - response: { token, user }
 */
class AdminAuthController extends Controller
{
    public function __construct(
        private readonly OtpService     $otp,
        private readonly CaptchaService $captcha,
    ) {}

    /**
     * Step 1 — credentials + captcha → SMS sent.
     */
    public function login(Request $request)
    {
        // Per-IP brute-force lock — 5 wrong attempts → 5 min cooldown
        $key = 'admin-login:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'success' => false,
                'message' => "تعداد تلاش بیش از حد. {$seconds} ثانیه صبر کنید.",
            ], 429);
        }

        $data = $request->validate([
            'username'       => 'required|string|max:50',
            'password'       => 'required|string',
            'phone'          => 'required|string|regex:/^09\d{9}$/',
            'captcha_token'  => 'required|string',
            'captcha_answer' => 'required|string',
        ]);

        // ── Captcha first — cheap check, cuts noise from bots ─────
        if (!$this->captcha->verify($data['captcha_token'], $data['captcha_answer'])) {
            // Don't hit() the rate limiter for captcha failures — captcha
            // already self-rate-limits, and bots would otherwise lock the
            // legit admin out.
            return response()->json([
                'success' => false,
                'message' => 'کد امنیتی اشتباه است یا منقضی شده.',
            ], 422);
        }

        $user = User::where('username', $data['username'])->first();

        // Identical generic message for unknown user / bad password / wrong
        // role — prevents username enumeration.
        $genericFail = ['success' => false, 'message' => 'مشخصات ورود نادرست است'];

        if (!$user || !Hash::check($data['password'], $user->password_hash)) {
            RateLimiter::hit($key, 300);
            return response()->json($genericFail, 401);
        }
        if ($user->role !== 'ادمین' || !$user->is_active) {
            RateLimiter::hit($key, 300);
            return response()->json($genericFail, 401);
        }

        // The submitted phone MUST match the admin's registered phone.
        // Without this check, a stolen password could redirect the OTP to
        // an attacker's phone.
        if ($user->phone !== $data['phone']) {
            RateLimiter::hit($key, 300);
            return response()->json([
                'success' => false,
                'message' => 'شماره موبایل با حساب کاربری مطابقت ندارد',
            ], 401);
        }

        // ── All checks passed — fire OTP ──────────────────────────
        $otp = $this->otp->request($user->phone, 'login', $request->ip());
        if (!$otp['success']) {
            return response()->json([
                'success'  => false,
                'message'  => $otp['message'],
                'retry_in' => $otp['retry_in'] ?? null,
            ], 429);
        }

        // Clear the IP cooldown — they're legit
        RateLimiter::clear($key);

        // Pass dev-mode fields through (only present when admin has flipped
        // `sms_otp_enabled` off to skip sms.ir credit consumption during
        // development). Same toggle that controls public OTP — admin can
        // use the panel from the same dev environment without burning
        // credit on every test login.
        $payload = [
            'expires_in'     => $otp['expires_in'],
            'cooldown_until' => $otp['cooldown_until'],
            'phone_hint'     => $this->maskPhone($user->phone),
        ];
        if (!empty($otp['dev_mode'])) {
            $payload['dev_mode']    = true;
            $payload['dev_code']    = $otp['dev_code'] ?? null;
            $payload['dev_message'] = $otp['dev_message'] ?? null;
        }

        return response()->json([
            'success' => true,
            'data'    => $payload,
            'message' => !empty($otp['dev_mode'])
                ? ($otp['dev_message'] ?? 'کد تایید — حالت توسعه')
                : 'کد تایید پیامک شد',
        ]);
    }

    /**
     * Step 2 — verify OTP + issue admin token.
     */
    public function verify(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string|max:50',
            'code'     => 'required|string',
        ]);

        $user = User::where('username', $data['username'])->first();
        if (!$user || $user->role !== 'ادمین' || !$user->is_active) {
            return response()->json(['success' => false, 'message' => 'مشخصات ورود نادرست است'], 401);
        }

        // Verify the code first (this marks `verified_at` on the OTP row)
        $verifyResult = $this->otp->verify($user->phone, $data['code'], 'login');
        if (!$verifyResult['success']) {
            return response()->json([
                'success' => false,
                'message' => $verifyResult['message'],
            ], 422);
        }

        // Single-use consume
        if (!$this->otp->checkVerifiedAndConsume($user->phone, 'login')) {
            return response()->json([
                'success' => false,
                'message' => 'کد تایید منقضی شده — دوباره تلاش کنید',
            ], 422);
        }

        // Issue token — short-lived (8h) admin-scoped
        $token = $user->createToken('admin-panel', ['admin'], now()->addHours(8))->plainTextToken;

        return response()->json([
            'success' => true,
            'data'    => [
                'token' => $token,
                'user'  => [
                    'id'        => $user->id,
                    'name'      => $user->full_name,
                    'username'  => $user->username,
                    'phone'     => $user->phone,
                ],
            ],
            'message' => 'ورود موفق',
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['success' => true, 'message' => 'خروج موفق']);
    }

    public function me(Request $request)
    {
        $u = $request->user();
        return response()->json(['success' => true, 'data' => [
            'id'               => $u->id,
            'name'             => $u->full_name,
            'username'         => $u->username,
            'phone'            => $u->phone,
            'role'             => $u->role,
            'is_primary_admin' => (bool) $u->is_primary_admin,
            // Primary admins implicitly have access to everything; for them
            // we surface the full section list so the frontend can render
            // the sidebar without special-casing the flag.
            'permissions'      => $u->is_primary_admin
                ? ['users','businesses','categories','services','appointments','queue','reviews','verifications','slots','notifications','outbox','audit','admins','settings']
                : (is_array($u->permissions) ? $u->permissions : []),
        ]]);
    }

    /** Mask the middle 3 digits of a phone for display in OTP step UI. */
    private function maskPhone(string $phone): string
    {
        if (strlen($phone) !== 11) return $phone;
        return substr($phone, 0, 4) . '***' . substr($phone, -4);
    }
}
