<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Servora users table — full_name + phone + password_hash + role enum
        Schema::create('users', function (Blueprint $table) {
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_persian_ci';

            $table->bigIncrements('id');
            $table->string('full_name', 120);
            $table->string('phone', 11)->comment('موبایل ایران بدون کد کشور');
            $table->string('password_hash', 255)->comment('BCrypt یا Argon2');
            $table->enum('role', ['مشتری', 'کسب‌وکار', 'ادمین'])->default('مشتری');
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('phone', 'uq_users_phone');
            $table->index('role',      'idx_users_role');
            $table->index('is_active', 'idx_users_active');
        });

        // Laravel framework — password reset (kept for completeness)
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // Laravel framework — sessions
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
