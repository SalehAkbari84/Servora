<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-notification deep-link URL.
 *
 * Why: chat notifications need to point to different routes depending on
 * the recipient's role (owner → /owner/messages?conv=ID, customer →
 * /businesses/<bid>). Computing that from entity-type + role in the
 * frontend is duplicate logic; storing the resolved URL at write-time
 * is simpler and lets us extend to new notification types without
 * touching the bell component.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $t) {
            if (!Schema::hasColumn('notifications', 'url')) {
                $t->string('url', 255)->nullable()->after('body');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $t) {
            if (Schema::hasColumn('notifications', 'url')) {
                $t->dropColumn('url');
            }
        });
    }
};
