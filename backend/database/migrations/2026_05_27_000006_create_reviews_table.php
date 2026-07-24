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
        Schema::create('reviews', function (Blueprint $table) {
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_persian_ci';

            $table->bigIncrements('id');
            $table->unsignedBigInteger('business_id');
            $table->string('business_name', 200);
            $table->unsignedBigInteger('appointment_id');
            $table->unsignedBigInteger('service_id')->default(0);
            $table->string('service_name', 200)->default('');
            $table->char('date_shamsi', 10)->default('');
            $table->unsignedBigInteger('user_id');
            $table->string('user_name', 120);
            $table->unsignedTinyInteger('rating')->comment('1 to 5');
            $table->text('comment')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->dateTime('created_at')->useCurrent();

            $table->unique('appointment_id',           'uq_review_per_appt');
            $table->index('business_id',                'idx_reviews_business');
            $table->index('user_id',                    'idx_reviews_user');
            $table->index(['business_id', 'rating'],    'idx_reviews_rating');
            $table->index('service_id',                 'idx_reviews_service');
        });

        DB::statement('ALTER TABLE reviews ADD CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
