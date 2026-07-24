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
        Schema::create('notification_outbox', function (Blueprint $table) {
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_persian_ci';

            $table->bigIncrements('id');
            $table->string('idempotency_key', 120)->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('user_phone', 11);
            $table->string('type', 30);
            $table->string('title', 200);
            $table->text('body');
            $table->string('related_entity_type', 60)->nullable();
            $table->unsignedBigInteger('related_entity_id')->nullable();
            $table->enum('status', ['pending', 'processing', 'delivered', 'failed'])->default('pending');
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->dateTime('next_retry_at')->nullable()->comment('exponential backoff');
            $table->dateTime('processed_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('idempotency_key',          'uq_outbox_idempotency');
            $table->index(['status', 'next_retry_at'], 'idx_outbox_pending');
            $table->index('created_at',                 'idx_outbox_created');
            $table->index('user_id',                    'idx_outbox_user');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE notification_outbox
              ADD CONSTRAINT chk_outbox_type CHECK (type IN (
                'رزرو_موفق', 'لغو_نوبت', 'یادآوری',
                'ثبت_صف',    'ارتقا_صف',
                'تایید_کسب‌وکار', 'رد_کسب‌وکار'
              ))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_outbox');
    }
};
