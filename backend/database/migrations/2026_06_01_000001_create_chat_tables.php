<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat between a single user and a business.
 *
 * `conversations` holds one row per (user_id, business_id) pair. Counters
 * (`unread_for_user` / `unread_for_owner`) live on this row so the UI can
 * show a badge without scanning the message log.
 *
 * `messages` is the append-only message log. `sender_type` distinguishes
 * who wrote it because either side can write through the same endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_persian_ci';

            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->comment('FK منطقی به users.id');
            $table->unsignedBigInteger('business_id')->comment('FK منطقی به businesses.id');
            $table->string('business_name', 200)->default('')->comment('snapshot از businesses.name');
            $table->string('user_name', 120)->default('')->comment('snapshot از users.full_name');

            $table->dateTime('last_message_at')->nullable()->comment('برای مرتب‌سازی مکالمات');
            $table->string('last_message_preview', 200)->nullable()->comment('۸۰-۲۰۰ کاراکتر آخرین پیام');
            $table->enum('last_message_sender', ['user', 'owner'])->nullable();

            $table->unsignedInteger('unread_for_user')->default(0);
            $table->unsignedInteger('unread_for_owner')->default(0);

            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['user_id', 'business_id'], 'uq_user_business');
            $table->index(['business_id', 'last_message_at'], 'idx_biz_recent');
            $table->index(['user_id', 'last_message_at'], 'idx_user_recent');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_persian_ci';

            $table->bigIncrements('id');
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('sender_id')->comment('FK منطقی به users.id');
            $table->enum('sender_type', ['user', 'owner'])->comment('چه کسی نوشته است');
            $table->text('body');
            $table->boolean('is_read')->default(false)->comment('توسط طرف مقابل خوانده شده');
            $table->dateTime('created_at')->useCurrent();

            $table->index(['conversation_id', 'created_at'], 'idx_conv_chrono');
            $table->index(['conversation_id', 'is_read'], 'idx_conv_unread');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
