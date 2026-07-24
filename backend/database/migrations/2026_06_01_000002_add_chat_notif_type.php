<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Allow `پیام_جدید` as a valid notification type so the chat system
 * can ping recipients via the in-app inbox.
 *
 * The original constraint was defined inside servora.sql as:
 *   CHECK (type IN ('رزرو_موفق','لغو_نوبت','یادآوری','ثبت_صف','ارتقا_صف',
 *                   'تایید_کسب‌وکار','رد_کسب‌وکار'))
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notifications DROP CHECK chk_notif_type');
        DB::statement("
            ALTER TABLE notifications ADD CONSTRAINT chk_notif_type CHECK (
                type IN (
                    'رزرو_موفق', 'لغو_نوبت', 'یادآوری',
                    'ثبت_صف',    'ارتقا_صف',
                    'تایید_کسب‌وکار', 'رد_کسب‌وکار',
                    'پیام_جدید'
                )
            )
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notifications DROP CHECK chk_notif_type');
        DB::statement("
            ALTER TABLE notifications ADD CONSTRAINT chk_notif_type CHECK (
                type IN (
                    'رزرو_موفق', 'لغو_نوبت', 'یادآوری',
                    'ثبت_صف',    'ارتقا_صف',
                    'تایید_کسب‌وکار', 'رد_کسب‌وکار'
                )
            )
        ");
    }
};
