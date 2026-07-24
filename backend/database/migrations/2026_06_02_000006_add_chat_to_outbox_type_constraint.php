<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The `notification_outbox.type` column has a CHECK constraint that lists
 * the allowed enum values. When chat notifications were added (`پیام_جدید`)
 * the constraint was updated on `notifications` but not `notification_outbox`,
 * which blocks chat SMS from being queued. This migration drops + recreates
 * the outbox constraint to include the chat value.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop and recreate — MySQL doesn't support ALTER CONSTRAINT for CHECK.
        try {
            DB::statement('ALTER TABLE notification_outbox DROP CONSTRAINT chk_outbox_type');
        } catch (\Throwable) { /* might already be gone */ }

        DB::statement("
            ALTER TABLE notification_outbox
            ADD CONSTRAINT chk_outbox_type CHECK (`type` IN (
                'رزرو_موفق',
                'لغو_نوبت',
                'یادآوری',
                'ثبت_صف',
                'ارتقا_صف',
                'تایید_کسب‌وکار',
                'رد_کسب‌وکار',
                'پیام_جدید'
            ))
        ");
    }

    public function down(): void
    {
        try {
            DB::statement('ALTER TABLE notification_outbox DROP CONSTRAINT chk_outbox_type');
        } catch (\Throwable) {}
        DB::statement("
            ALTER TABLE notification_outbox
            ADD CONSTRAINT chk_outbox_type CHECK (`type` IN (
                'رزرو_موفق','لغو_نوبت','یادآوری','ثبت_صف','ارتقا_صف','تایید_کسب‌وکار','رد_کسب‌وکار'
            ))
        ");
    }
};
