<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-admin model:
 *
 * - `is_primary_admin` — single super-admin flag. Only this user can manage
 *   other admins and access the `settings` section. There can be many users
 *   with role='ادمین' but at most one (logically) is primary.
 * - `permissions` — JSON array of section keys the admin can access
 *   (e.g. ["users", "businesses", "reviews"]). NULL means "no extra perms";
 *   only the primary admin gets implicit full access.
 *
 * After the column is added, we promote the FIRST existing admin to primary
 * so the system never ends up with zero primaries (and locked-out settings).
 *
 * Allowed permission keys (kept in sync with frontend sidebar):
 *   users, businesses, categories, services, slots, appointments, queue,
 *   reviews, verifications, notifications, outbox, audit, messages
 *
 * Never in the list: `settings` — that's primary-only by design.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'is_primary_admin')) {
                $t->boolean('is_primary_admin')->default(false)->after('role');
            }
            if (!Schema::hasColumn('users', 'permissions')) {
                $t->json('permissions')->nullable()->after('is_primary_admin');
            }
        });

        // Promote the oldest admin to primary so settings remains reachable.
        // If there's no admin yet, this is a no-op — the first one created
        // via setup.bat / artisan tinker can be flagged manually.
        $firstAdmin = DB::table('users')
            ->where('role', 'ادمین')
            ->orderBy('id')
            ->first(['id', 'is_primary_admin']);
        if ($firstAdmin && !$firstAdmin->is_primary_admin) {
            DB::table('users')
                ->where('id', $firstAdmin->id)
                ->update(['is_primary_admin' => true]);
        }
    }

    public function down(): void {
        Schema::table('users', function (Blueprint $t) {
            if (Schema::hasColumn('users', 'permissions'))      $t->dropColumn('permissions');
            if (Schema::hasColumn('users', 'is_primary_admin')) $t->dropColumn('is_primary_admin');
        });
    }
};
