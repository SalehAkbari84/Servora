<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_persian_ci';

            $table->bigIncrements('id');
            $table->unsignedBigInteger('business_id');
            $table->string('business_name', 200);
            $table->string('service_name', 200);
            $table->string('category_name', 100)->default('');
            $table->enum('gender_type', ['مرد', 'زن', 'هر دو'])->default('هر دو');
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->decimal('price', 12, 0)->default(0)->comment('قیمت به ریال');
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->useCurrent();

            $table->index(['business_id', 'is_active'],                'idx_svc_biz_active');
            $table->index(['business_id', 'gender_type', 'is_active'], 'idx_svc_biz_gender');
            $table->index('category_name',                              'idx_services_category');
            $table->fullText('service_name', 'ft_services_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
