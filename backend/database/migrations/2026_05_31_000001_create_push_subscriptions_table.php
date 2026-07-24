<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores browser Web Push subscriptions. One row per (user, device).
 *
 * `endpoint` is unique — the same browser keeps the same endpoint across
 * subscriptions, so updateOrCreate keeps the table from duplicating.
 * Subscriptions that return 404/410 from the push service are pruned in
 * the worker as `expired`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_persian_ci';

            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->comment('FK منطقی به users.id');
            $table->text('endpoint')->comment('URL ارایه‌دهنده push (FCM / Mozilla)');
            $table->string('endpoint_hash', 64)->unique()->comment('SHA-256 of endpoint for unique lookup');
            $table->string('p256dh', 200)->comment('کلید رمز عمومی browser');
            $table->string('auth', 50)->comment('Auth secret');
            $table->string('user_agent', 300)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('user_id', 'idx_push_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
