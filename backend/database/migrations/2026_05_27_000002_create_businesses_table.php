<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_persian_ci';

            $table->bigIncrements('id');
            $table->unsignedBigInteger('owner_user_id')->comment('FK منطقی به users.id');
            $table->string('owner_name', 120)->comment('snapshot از users.full_name');
            $table->string('owner_phone', 11)->comment('snapshot از users.phone');
            $table->string('name', 200);
            $table->unsignedInteger('category_id')->nullable();
            $table->string('category_name', 100)->default('');
            $table->unsignedInteger('subcategory_id')->nullable();
            $table->string('subcategory_name', 100)->default('');
            $table->enum('gender_type', ['مرد', 'زن', 'هر دو'])->default('هر دو');
            $table->text('description')->nullable();
            $table->string('address_text', 500)->default('');
            $table->string('phone', 15)->default('');
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_active')->default(true);
            $table->decimal('rating_avg',   3, 2)->default(0.00)->comment('توسط trigger');
            $table->decimal('rating_sum',  10, 2)->default(0.00)->comment('مجموع امتیاز visible reviews');
            $table->unsignedInteger('rating_count')->default(0)->comment('تعداد visible reviews');
            $table->unsignedInteger('total_reviews')->default(0)->comment('کل reviews');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index(['is_verified', 'is_active', 'category_name',    'rating_avg'], 'idx_biz_search_cat');
            $table->index(['is_verified', 'is_active', 'subcategory_name', 'rating_avg'], 'idx_biz_search_subcat');
            $table->index(['is_verified', 'is_active', 'gender_type',      'rating_avg'], 'idx_biz_search_gender');
            $table->index(['owner_user_id', 'is_active'], 'idx_biz_owner_active');
            $table->fullText('name', 'ft_businesses_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
