<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores OTP (one-time code) state per (phone, purpose).
 *
 * Design:
 *  - `code_hash`: SHA-256 of the actual 5-digit code. We never store the
 *    raw code so a DB leak doesn't expose live verification codes.
 *  - `purpose`: 'register' | 'login' | 'reset' — same phone can have
 *    distinct codes for different flows simultaneously.
 *  - `attempts`: incremented on each verify; locks after 5 wrong tries.
 *  - `consumed_at`: set when a code is successfully verified. The row stays
 *    around (audit trail) but can't be re-used.
 *  - `verified_at` (separate from consumed): for the "register" flow we
 *    verify the OTP first, then the user fills in name/password and
 *    submits the final registration. Verification = ok to proceed,
 *    consumption = final registration submitted. We keep the row valid
 *    for 15 minutes after verification so the user has time to finish
 *    typing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_persian_ci';

            $table->bigIncrements('id');
            $table->string('phone', 11)->comment('۱۱ رقمی، با ۰۹ شروع می‌شود');
            $table->string('code_hash', 64)->comment('SHA-256 از کد ۵ رقمی');
            $table->enum('purpose', ['register', 'login', 'reset'])->comment('هدف ارسال OTP');
            $table->unsignedTinyInteger('attempts')->default(0)->comment('تعداد دفعات تلاش غلط');
            $table->dateTime('expires_at')->comment('پایان مهلت ۲ دقیقه‌ای کد');
            $table->dateTime('verified_at')->nullable()->comment('کد توسط کاربر درست وارد شد');
            $table->dateTime('consumed_at')->nullable()->comment('فرآیند نهایی (ثبت/ورود) انجام شد');
            $table->string('provider_message_id', 64)->nullable()->comment('شناسه پیامک از sms.ir');
            $table->string('ip', 45)->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['phone', 'purpose'], 'idx_otp_phone_purpose');
            $table->index('expires_at', 'idx_otp_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
