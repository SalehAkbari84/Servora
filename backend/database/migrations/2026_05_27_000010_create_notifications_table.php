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
        Schema::create('notifications', function (Blueprint $table) {
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_persian_ci';

            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('user_phone', 11);
            $table->string('type', 30);
            $table->string('title', 200);
            $table->text('body');
            $table->string('related_entity_type', 60)->nullable();
            $table->unsignedBigInteger('related_entity_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->dateTime('created_at')->useCurrent();

            $table->index(['user_id', 'created_at'],            'idx_notif_user_date');
            $table->index(['user_id', 'is_read', 'created_at'], 'idx_notif_user_unread');
            $table->index('type',                                'idx_notif_type');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE notifications
              ADD CONSTRAINT chk_notif_type CHECK (type IN (
                'رزرو_موفق', 'لغو_نوبت', 'یادآوری',
                'ثبت_صف',    'ارتقا_صف',
                'تایید_کسب‌وکار', 'رد_کسب‌وکار'
              ))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
