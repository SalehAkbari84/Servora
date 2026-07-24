<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add `username` column used exclusively by the admin login flow.
 *
 *   - Customers/business owners log in via phone + password + OTP
 *   - Admins log in via username + password + phone + OTP + captcha
 *
 * The column is nullable (only admins need it) and unique when present.
 * Existing admin accounts get a default username so the migration is safe
 * to run on a populated database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->after('id')
                  ->comment('فقط برای ادمین — برای ورود به پنل مدیریت');
        });

        // Unique only when set — using a partial-style approach via raw SQL
        // (MySQL doesn't have true partial unique, but NULL values are
        // treated as distinct by UNIQUE so it works).
        DB::statement('ALTER TABLE users ADD UNIQUE KEY uq_username (username)');

        // Backfill admins so they can immediately log in with username
        DB::table('users')
            ->where('role', 'ادمین')
            ->whereNull('username')
            ->orderBy('id')
            ->get(['id', 'phone'])
            ->each(function ($u, $i) {
                // Derive a username from the phone, falling back to "admin{i}"
                $candidate = preg_match('/^09(\d{9})$/', $u->phone, $m)
                    ? 'admin'  // First admin gets "admin", others get suffix
                    : 'admin';
                if ($i > 0) $candidate .= ($i + 1);

                DB::table('users')->where('id', $u->id)->update(['username' => $candidate]);
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('uq_username');
            $table->dropColumn('username');
        });
    }
};
