<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_persian_ci';

            $table->bigIncrements('id');
            $table->string('entity_type', 60);
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 30)->comment('ثبت/ویرایش/حذف/تایید/رد/لغو/ارتقا_صف/منقضی');
            $table->text('description');
            $table->unsignedBigInteger('performed_by')->nullable()->comment('NULL = سیستم/trigger');
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id'], 'idx_audit_entity');
            $table->index('action',                      'idx_audit_action');
            $table->index('created_at',                  'idx_audit_created');
            $table->index('performed_by',                'idx_audit_who');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
