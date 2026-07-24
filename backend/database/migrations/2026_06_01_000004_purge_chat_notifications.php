<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Delete every historical `پیام_جدید` notification from the inbox.
 *
 * Chat used to push a row into `notifications` for every message, but
 * those polluted the user's bell with dozens of tap-throughs. We now keep
 * chat fully isolated inside the dedicated /messages section, with
 * unread counters living on the `conversations` row.
 *
 * This is a data-purge — schema is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $deleted = DB::table('notifications')->where('type', 'پیام_جدید')->delete();
        // dev visibility — also touch the audit log so the cleanup is traceable
        DB::table('audit_log')->insert([
            'entity_type' => 'notifications',
            'entity_id'   => 0,
            'action'      => 'پاکسازی',
            'description' => "حذف {$deleted} اعلان چت قدیمی از inbox (جای جدید: /messages)",
            'created_at'  => now(),
        ]);
    }

    public function down(): void
    {
        // Reversal not possible — the rows are gone.
    }
};
