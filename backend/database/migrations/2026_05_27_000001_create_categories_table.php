<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->charset   = 'utf8mb4';
            $table->collation = 'utf8mb4_persian_ci';

            $table->integerIncrements('id');
            $table->string('name', 100);
            $table->unsignedInteger('parent_id')->nullable();
            $table->boolean('is_active')->default(true);

            $table->index('parent_id', 'idx_categories_parent');
            $table->index('name',      'idx_categories_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
