<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Generic notification-template settings used by ProcessOutboxCommand
 * when delivering SMS for appointments / queue / reviews / verifications
 * (everything that lands in `notification_outbox`).
 *
 * `sms_ir_notification_template_id` — template id at sms.ir
 * `sms_ir_notification_param_name`  — placeholder name (e.g. MSG ↔ #MSG#)
 *
 * Defaults to template_id=0, which makes the worker skip the SMS send
 * (still copies to inbox) — admin configures it once and outbox SMS
 * starts flowing automatically.
 *
 * Why generic instead of per-type? sms.ir templates cost money to set up
 * and approve. One "#MSG#" template covers every notification body we
 * need to send — title is short enough to fit in a single SMS segment.
 * Admin can split into per-type templates later by adding more keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [
            [
                'key'         => 'sms_ir_notification_template_id',
                'value'       => '0',
                'type'        => 'number',
                'group'       => 'sms',
                'label'       => 'شناسه قالب پیامک اطلاع‌رسانی',
                'description' => 'قالب sms.ir برای پیامک‌های صف خروجی (نوبت‌ها، صف، احراز و ...). یک پارامتر دارد که متن کوتاه پیام است. اگر 0 باشد، پیامک ارسال نمی‌شود ولی پیام در صندوق داخلی کاربر ثبت می‌شود.',
                'order'       => 70,
            ],
            [
                'key'         => 'sms_ir_notification_param_name',
                'value'       => 'MSG',
                'type'        => 'text',
                'group'       => 'sms',
                'label'       => 'نام پارامتر متن در قالب اطلاع‌رسانی',
                'description' => 'نام placeholder در قالب — مثلاً اگر قالب شما #MSG# دارد، MSG بگذارید.',
                'order'       => 71,
            ],
        ];
        foreach ($rows as $row) {
            $exists = DB::table('settings')->where('key', $row['key'])->exists();
            if ($exists) {
                DB::table('settings')->where('key', $row['key'])->update([
                    'type'        => $row['type'],
                    'group'       => $row['group'],
                    'label'       => $row['label'],
                    'description' => $row['description'],
                    'order'       => $row['order'],
                    'updated_at'  => $now,
                ]);
            } else {
                DB::table('settings')->insert([
                    'key'         => $row['key'],
                    'value'       => $row['value'],
                    'type'        => $row['type'],
                    'group'       => $row['group'],
                    'label'       => $row['label'],
                    'description' => $row['description'],
                    'order'       => $row['order'],
                    'is_advanced' => false,
                    'updated_at'  => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'sms_ir_notification_template_id',
            'sms_ir_notification_param_name',
        ])->delete();
    }
};
