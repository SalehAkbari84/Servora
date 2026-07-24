<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_verification', function (Blueprint $table) {
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_persian_ci';

            $table->bigIncrements('id');
            $table->unsignedBigInteger('business_id');
            $table->string('business_name', 200);
            $table->unsignedBigInteger('owner_user_id')->default(0);
            $table->string('owner_phone', 11)->default('');
            $table->boolean('phone_verified')->default(false);
            $table->string('address_text', 500)->default('');
            $table->string('document_url', 500)->nullable();
            $table->string('admin_note', 1000)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->enum('status', ['در انتظار', 'تایید شده', 'رد شده'])->default('در انتظار');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('business_id', 'idx_bv_business');
            $table->index('status',      'idx_bv_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_verification');
    }
};
