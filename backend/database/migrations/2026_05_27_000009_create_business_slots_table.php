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
        Schema::create('business_slots', function (Blueprint $table) {
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_persian_ci';

            $table->bigIncrements('id');
            $table->unsignedBigInteger('business_id');
            $table->string('business_name', 200);
            $table->unsignedTinyInteger('day_of_week')->comment('0=شنبه ... 6=جمعه');
            $table->char('time_slot', 5);
            $table->unsignedTinyInteger('max_capacity')->default(1);
            $table->boolean('is_active')->default(true);

            $table->unique(['business_id', 'day_of_week', 'time_slot'], 'uq_slot');
            $table->index('business_id', 'idx_bs_business');
            $table->index('day_of_week', 'idx_bs_day');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE business_slots
              ADD CONSTRAINT chk_day_of_week CHECK (day_of_week BETWEEN 0 AND 6),
              ADD CONSTRAINT chk_bs_time     CHECK (time_slot REGEXP '^([01][0-9]|2[0-3]):[0-5][0-9]$')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('business_slots');
    }
};
