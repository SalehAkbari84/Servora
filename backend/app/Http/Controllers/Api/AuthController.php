<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Services\CaptchaService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly OtpService     $otp,
        private readonly CaptchaService $captcha,
    ) {}

    /**
     * GET /api/captcha — anonymous. Returns:
     *   { token, svg }
     * Frontend renders the SVG and asks the user to type the displayed text.
     */
    public function captcha(Request $request): JsonResponse
    {
        $data = $this->captcha->generate($request->ip());
        return response()->json([
            'success' => true,
            'data'    => $data,
            'code'    => 200,
        ]);
    }

    /**
     * Send a verification code to the given phone.
     *
     * For `register`: phone must NOT exist + captcha must pass.
     * For `login`: phone MUST exist + password MUST match (so a stranger
     *   can't trigger SMS spam to a number they don't own) + captcha.
     *
     * Cooldown + hourly cap enforced inside OtpService.
     */
    public function requestOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'           => ['required', 'string'],
            'purpose'         => ['required', 'in:register,login'],
            'captcha_token'   => ['nullable', 'string'],
            'captcha_answer'  => ['nullable', 'string'],
            // Only required for purpose=login
            'password'        => ['nullable', 'string'],
        ]);

        // ── Captcha gate — toggleable per-purpose from admin settings ────
        $captchaRequired = $data['purpose'] === 'register'
            ? Setting::get('captcha_required_register', '1') === '1'
            : Setting::get('captcha_required_login', '1')    === '1';

        if ($captchaRequired) {
            if (empty($data['captcha_token']) || empty($data['captcha_answer'])
                || !$this->captcha->verify($data['captcha_token'], $data['captcha_answer'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'کد امنیتی اشتباه است یا منقضی شده.',
                    'code'    => 422,
                ], 422);
            }
        }

        $phone = $data['phone'];
        $purpose = $data['purpose'];

        if ($purpose === 'register') {
            if (User::where('phone', $phone)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'این شماره قبلاً ثبت‌نام کرده است. به جای ثبت‌نام، وارد شوید.',
                    'code'    => 409,
                ], 409);
            }
        } else { // login
            $user = User::where('phone', $phone)->first();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'شماره تلفن یا رمز عبور اشتباه است.',
                    'code'    => 401,
                ], 401);
            }
            if (!$user->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'حساب کاربری شما غیرفعال است.',
                    'code'    => 403,
                ], 403);
            }
            // Password is required for login — verify BEFORE sending SMS
            if (empty($data['password']) || !Hash::check($data['password'], $user->password_hash)) {
                return response()->json([
                    'success' => false,
                    'message' => 'شماره تلفن یا رمز عبور اشتباه است.',
                    'code'    => 401,
                ], 401);
            }
        }

        $result = $this->otp->request($phone, $purpose, $request->ip());

        if (!$result['success']) {
            return response()->json([
                'success'  => false,
                'message'  => $result['message'],
                'retry_in' => $result['retry_in'] ?? null,
                'code'     => 429,
            ], 429);
        }

        // Pass dev-mode fields through (only present when admin has
        // disabled OTP SMS to skip credit consumption during development).
        $payload = [
            'expires_in'     => $result['expires_in'],
            'cooldown_until' => $result['cooldown_until'],
        ];
        if (!empty($result['dev_mode'])) {
            $payload['dev_mode']    = true;
            $payload['dev_code']    = $result['dev_code'] ?? null;
            $payload['dev_message'] = $result['dev_message'] ?? null;
        }

        return response()->json([
            'success' => true,
            'data'    => $payload,
            'message' => !empty($result['dev_mode'])
                ? ($result['dev_message'] ?? 'کد تایید — حالت توسعه')
                : 'کد تایید ارسال شد',
            'code'    => 200,
        ]);
    }

    /**
     * Verify a code without finalizing a flow. The frontend calls this
     * during registration (after which the user fills in name/password)
     * and during login (after which the token is issued via /auth/login/otp).
     *
     * Marks `verified_at` but NOT `consumed_at` — consumption happens
     * when register/login finalizes.
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'   => ['required', 'string'],
            'code'    => ['required', 'string'],
            'purpose' => ['required', 'in:register,login'],
        ]);

        $result = $this->otp->verify($data['phone'], $data['code'], $data['purpose']);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'code'    => 422,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'کد تایید درست است',
            'code'    => 200,
        ]);
    }

    /**
     * Issue a token after a successful OTP login verification.
     *
     * Caller must have called /auth/otp/verify with `purpose=login`
     * recently — the consumption check inside OtpService enforces this.
     */
    public function loginWithOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string'],
        ]);

        $phone = $data['phone'];

        if (!$this->otp->checkVerifiedAndConsume($phone, 'login')) {
            return response()->json([
                'success' => false,
                'message' => 'لطفاً ابتدا کد تایید را وارد کنید.',
                'code'    => 422,
            ], 422);
        }

        $user = User::where('phone', $phone)->first();
        if (!$user || !$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'حساب یافت نشد یا غیرفعال است.',
                'code'    => 404,
            ], 404);
        }

        $user->tokens()->delete();
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'data'    => [
                'user'  => $this->formatUser($user),
                'token' => $token,
            ],
            'message' => 'ورود با موفقیت انجام شد.',
            'code'    => 200,
        ]);
    }

    /**
     * Register a new user. Requires a verified OTP for `phone` within the
     * post-verify window (default 15 min). Captcha was already verified
     * by /auth/otp/request before the SMS went out — no need to re-check here.
     */
    public function register(Request $request): JsonResponse
    {
        $minPw = max(4, (int) Setting::get('password_min_length', '6'));

        try {
            $validated = $request->validate([
                'full_name' => ['required', 'string', 'max:100'],
                'phone'     => ['required', 'string', 'regex:/^\d{11}$/', 'unique:users,phone'],
                'password'  => ['required', 'string', 'min:' . $minPw],
                'role'      => ['sometimes', 'string', 'in:مشتری,کسب‌وکار'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'داده‌های ورودی نامعتبر است.',
                'code'    => 422,
                'errors'  => $e->errors(),
            ], 422);
        }

        // Enforce OTP — phone must have a verified, unconsumed OTP for `register`
        if (!$this->otp->checkVerifiedAndConsume($validated['phone'], 'register')) {
            return response()->json([
                'success' => false,
                'message' => 'ابتدا شماره موبایل خود را با کد پیامکی تایید کنید.',
                'code'    => 422,
            ], 422);
        }

        $user = User::create([
            'full_name'     => $validated['full_name'],
            'phone'         => $validated['phone'],
            'password_hash' => Hash::make($validated['password']),
            'role'          => $validated['role'] ?? 'مشتری',
            'is_active'     => true,
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'data'    => [
                'user'  => $this->formatUser($user),
                'token' => $token,
            ],
            'message' => 'ثبت‌نام با موفقیت انجام شد.',
            'code'    => 201,
        ], 201);
    }

    /**
     * Login an existing user.
     */
    public function login(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'phone'    => ['required', 'string', 'regex:/^\d{11}$/'],
                'password' => ['required', 'string'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'داده‌های ورودی نامعتبر است.',
                'code'    => 422,
                'errors'  => $e->errors(),
            ], 422);
        }

        $user = User::where('phone', $validated['phone'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password_hash)) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'شماره تلفن یا رمز عبور اشتباه است.',
                'code'    => 401,
            ], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'حساب کاربری شما غیرفعال شده است.',
                'code'    => 403,
            ], 403);
        }

        // Revoke old tokens and issue a fresh one
        $user->tokens()->delete();
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'data'    => [
                'user'  => $this->formatUser($user),
                'token' => $token,
            ],
            'message' => 'ورود با موفقیت انجام شد.',
            'code'    => 200,
        ]);
    }

    /**
     * Logout the authenticated user.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'data'    => null,
            'message' => 'با موفقیت خارج شدید.',
            'code'    => 200,
        ]);
    }

    /**
     * Return the currently authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->formatUser($request->user()),
            'message' => 'اطلاعات کاربر.',
            'code'    => 200,
        ]);
    }

    /**
     * Update the authenticated user's profile (name and/or password).
     */
    public function updateProfile(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'full_name' => ['sometimes', 'string', 'max:100'],
                'password'  => ['sometimes', 'string', 'min:6'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'داده‌های ورودی نامعتبر است.',
                'code'    => 422,
                'errors'  => $e->errors(),
            ], 422);
        }

        $user = $request->user();

        if (isset($data['password'])) {
            $user->password_hash = Hash::make($data['password']);
            unset($data['password']);
        }

        if (isset($data['full_name'])) {
            $user->full_name = $data['full_name'];
        }

        $user->save();

        return response()->json([
            'success' => true,
            'data'    => $this->formatUser($user),
            'message' => 'پروفایل با موفقیت به‌روزرسانی شد.',
            'code'    => 200,
        ]);
    }

    /**
     * POST /api/profile/avatar — multipart upload of the user's avatar image.
     * Replaces any existing avatar (old file is deleted from disk).
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        // Cap is admin-controllable via the `upload_max_mb` setting; default 2MB.
        $maxMb = (int) Setting::get('upload_max_mb', '2');
        $maxKb = max(1, $maxMb) * 1024;

        try {
            $request->validate([
                'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', "max:{$maxKb}"],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false, 'data' => null,
                'message' => "فایل تصویر معتبر نیست (jpg/png/webp, حداکثر {$maxMb} مگابایت).",
                'code'    => 422, 'errors' => $e->errors(),
            ], 422);
        }

        $user = $request->user();

        // Remove the previous avatar file if any
        if ($user->avatar_url) {
            Storage::disk('public')->delete($user->avatar_url);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->avatar_url = $path;
        $user->save();

        return response()->json([
            'success' => true,
            'data'    => $this->formatUser($user),
            'message' => 'تصویر پروفایل به‌روزرسانی شد.',
            'code'    => 200,
        ]);
    }

    /**
     * DELETE /api/profile/avatar — remove avatar, fall back to first-letter placeholder.
     */
    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->avatar_url) {
            Storage::disk('public')->delete($user->avatar_url);
            $user->avatar_url = null;
            $user->save();
        }
        return response()->json([
            'success' => true,
            'data'    => $this->formatUser($user),
            'message' => 'تصویر پروفایل حذف شد.',
            'code'    => 200,
        ]);
    }

    // -------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------

    private function formatUser(User $user): array
    {
        return [
            'id'         => $user->id,
            'full_name'  => $user->full_name,
            'phone'      => $user->phone,
            'role'       => $user->role,
            'avatar_url' => $user->avatar_url
                ? Storage::disk('public')->url($user->avatar_url)
                : null,
            'is_active'  => $user->is_active,
            'created_at' => $user->created_at?->toISOString(),
        ];
    }
}
