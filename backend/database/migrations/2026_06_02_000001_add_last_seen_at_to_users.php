<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track when each user was last active (made an authenticated request).
 *
 * The chat notification flow uses this to decide whether to fall back to
 * SMS: if the business owner hasn't been "seen" in the last 2 minutes
 * we assume they're not at their browser and need a phone nudge.
 *
 * Indexed because the chat flow reads it on every message send and we
 * want that lookup to be a near-instant primary-key-like check.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'last_seen_at')) {
                $t->timestamp('last_seen_at')->nullable()->after('is_active');
                $t->index('last_seen_at', 'idx_users_last_seen_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (Schema::hasColumn('users', 'last_seen_at')) {
                $t->dropIndex('idx_users_last_seen_at');
                $t->dropColumn('last_seen_at');
            }
        });
    }
};
