<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the single PRIMARY administrator — the only account that can reach
 * the admin panel on a fresh install (creating further admins requires an
 * existing primary admin, so this bootstraps the chicken-and-egg).
 *
 * Idempotent: if a user with the same username OR phone already exists it
 * does nothing, so it is safe to run on every `setup.bat` (even additive,
 * non-fresh installs).
 *
 * Credentials are read from env so a buyer can set their own without editing
 * code — CHANGE THESE in production:
 *   ADMIN_USERNAME (default: admin)
 *   ADMIN_PHONE    (default: 09112801486)
 *   ADMIN_PASSWORD (default: Jackrichard@1384)
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $username = env('ADMIN_USERNAME', 'admin');
        $phone    = env('ADMIN_PHONE', '09112801486');
        $password = env('ADMIN_PASSWORD', 'Jackrichard@1384');

        $exists = DB::table('users')
            ->where('username', $username)
            ->orWhere('phone', $phone)
            ->exists();

        if ($exists) {
            $this->command?->info("Admin already exists ({$username}) — skipping.");
            return;
        }

        DB::table('users')->insert([
            'full_name'        => 'مدیر سیستم',
            'username'         => $username,
            'phone'            => $phone,
            'password_hash'    => Hash::make($password),
            'role'             => 'ادمین',
            'is_primary_admin' => true,
            'is_active'        => true,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $this->command?->info("Primary admin created — username: {$username}");
    }
}
