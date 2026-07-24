<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds province + city columns to businesses so owners can register their
 * location with structured data, and so the public listing can filter by
 * استان/شهر.
 *
 * `province_code` stores the canonical 3-letter code (THR, ABZ, …) defined
 * in frontend/src/constants/iran-locations.ts. `province_name` and `city`
 * are denormalised display strings so list pages don't need a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('province_code', 8)->nullable()->after('address_text');
            $table->string('province_name', 50)->nullable()->after('province_code');
            $table->string('city', 100)->nullable()->after('province_name');

            $table->index('province_code', 'idx_biz_province');
            $table->index('city',          'idx_biz_city');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropIndex('idx_biz_province');
            $table->dropIndex('idx_biz_city');
            $table->dropColumn(['province_code', 'province_name', 'city']);
        });
    }
};
