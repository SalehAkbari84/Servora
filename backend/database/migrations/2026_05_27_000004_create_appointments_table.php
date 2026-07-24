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
        Schema::create('appointments', function (Blueprint $table) {
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_persian_ci';

            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('user_name', 120);
            $table->string('user_phone', 11);
            $table->unsignedBigInteger('business_id');
            $table->string('business_name', 200);
            $table->unsignedBigInteger('service_id');
            $table->string('service_name', 200);
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->decimal('price', 12, 0)->default(0);
            $table->char('date_shamsi', 10)->comment('YYYY/MM/DD');
            $table->char('time_slot',    5)->comment('HH:MM');
            $table->enum('status', ['در انتظار', 'تایید شده', 'لغو شده', 'انجام شده'])->default('در انتظار');
            $table->string('cancel_reason', 500)->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            // slot_lock_key added as generated STORED column below
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('date_shamsi',                                                   'idx_appt_date');
            $table->index('status',                                                         'idx_appt_status');
            $table->index(['business_id', 'date_shamsi'],                                   'idx_appt_biz_active');
            $table->index(['business_id', 'status', 'date_shamsi'],                         'idx_appt_status_date');
            $table->index(['user_id',     'date_shamsi'],                                   'idx_appt_user_date');
            $table->index(['business_id', 'date_shamsi', 'time_slot', 'status'],            'idx_appt_slot_check');
        });

        // Generated STORED column + UNIQUE + CHECK constraints (raw SQL — Schema builder can't express these)
        DB::statement(<<<'SQL'
            ALTER TABLE appointments
              ADD COLUMN slot_lock_key VARCHAR(55)
                GENERATED ALWAYS AS (
                  CASE WHEN status = 'لغو شده'
                       THEN NULL
                       ELSE CONCAT(CAST(business_id AS CHAR), '|', date_shamsi, '|', time_slot)
                  END
                ) STORED
                COMMENT 'NULL=لغوشده → ریبوک مجاز',
              ADD UNIQUE KEY uq_slot_active (slot_lock_key),
              ADD CONSTRAINT chk_appt_date CHECK (date_shamsi REGEXP '^1[3-5][0-9]{2}/(0[1-9]|1[0-2])/(0[1-9]|[12][0-9]|3[01])$'),
              ADD CONSTRAINT chk_appt_time CHECK (time_slot   REGEXP '^([01][0-9]|2[0-3]):[0-5][0-9]$')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
