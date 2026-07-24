<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add an uploaded-image column to users (avatars) and businesses (logos).
 * Both are nullable strings storing a relative path under storage/app/public,
 * served via the /storage symlink.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'avatar_url')) {
                $t->string('avatar_url', 500)->nullable()->after('role');
            }
        });

        Schema::table('businesses', function (Blueprint $t) {
            if (!Schema::hasColumn('businesses', 'logo_url')) {
                $t->string('logo_url', 500)->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (Schema::hasColumn('users', 'avatar_url')) {
                $t->dropColumn('avatar_url');
            }
        });
        Schema::table('businesses', function (Blueprint $t) {
            if (Schema::hasColumn('businesses', 'logo_url')) {
                $t->dropColumn('logo_url');
            }
        });
    }
};
