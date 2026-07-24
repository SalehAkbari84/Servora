<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-use CAPTCHA challenges. One row per displayed challenge.
 *
 * Flow:
 *  - frontend requests /api/captcha → server creates a row, returns
 *    { token, image_svg }; the actual answer is only stored as a hash
 *  - frontend submits the user's typed answer along with `captcha_token`
 *  - server checks: row exists, not expired, not consumed, answer matches;
 *    on success marks `consumed_at` so the same captcha can't be replayed
 *
 * Expiry: 5 minutes. Anonymous, no user_id — captcha is the gate BEFORE
 * authentication.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('captchas', function (Blueprint $table) {
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_persian_ci';

            $table->char('token', 36)->primary()->comment('UUID opaque id sent to client (36-char hyphenated UUID)');
            $table->string('answer_hash', 64)->comment('SHA-256 of the lower-cased answer');
            $table->dateTime('expires_at');
            $table->dateTime('consumed_at')->nullable()->comment('وقتی کاربر درست وارد کرد');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('ip', 45)->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('captchas');
    }
};
