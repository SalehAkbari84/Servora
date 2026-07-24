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
        Schema::create('queue', function (Blueprint $table) {
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_persian_ci';

            $table->bigIncrements('id');
            $table->unsignedBigInteger('business_id');
            $table->string('business_name', 200);
            $table->unsignedBigInteger('user_id');
            $table->string('user_name', 120);
            $table->string('user_phone', 11);
            $table->unsignedBigInteger('service_id');
            $table->string('service_name', 200);
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->decimal('price', 12, 0)->default(0);
            $table->char('date_shamsi', 10);
            $table->char('time_slot',    5);
            $table->enum('status', ['در انتظار', 'اطلاع داده شده', 'پذیرفته شده', 'منقضی شده'])->default('در انتظار');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['business_id', 'date_shamsi', 'time_slot', 'user_id'], 'uq_queue_user_slot');
            $table->index('user_id',                                                       'idx_queue_user');
            $table->index('status',                                                         'idx_queue_status');
            $table->index(['business_id', 'status', 'created_at'],                          'idx_queue_biz_status');
            $table->index(['business_id', 'date_shamsi', 'time_slot', 'status', 'created_at'], 'idx_queue_fifo');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE queue
              ADD CONSTRAINT chk_queue_date CHECK (date_shamsi REGEXP '^1[3-5][0-9]{2}/(0[1-9]|1[0-2])/(0[1-9]|[12][0-9]|3[01])$'),
              ADD CONSTRAINT chk_queue_time CHECK (time_slot   REGEXP '^([01][0-9]|2[0-3]):[0-5][0-9]$')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('queue');
    }
};
