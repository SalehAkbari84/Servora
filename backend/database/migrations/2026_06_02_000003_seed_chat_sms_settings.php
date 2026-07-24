<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add admin-controllable settings for chat-notification SMS:
 *   - `sms_ir_chat_template_id` — sms.ir template id to use
 *   - `sms_ir_chat_param_name`  — name of the placeholder for the sender's
 *                                 display name (e.g. "NAME" for "#NAME#")
 *
 * Defaults to 0 / "NAME" — when template_id is 0 the chat SMS is silently
 * disabled (a log warning is emitted, but the chat itself works fine).
 * Admin must create a template at sms.ir with a {NAME} placeholder, then
 * paste its id into the settings panel for chat SMS to start flowing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            [
                'key'         => 'sms_ir_chat_template_id',
                'value'       => '0',
                'type'        => 'number',
                'group'       => 'sms',
                'label'       => 'شناسه قالب پیامک چت',
                'description' => 'شناسه قالب sms.ir برای اطلاع‌رسانی پیام جدید چت. اگر صفر باشد، پیامک ارسال نمی‌شود. قالب باید یک پارامتر داشته باشد (نام فرستنده).',
                'order'       => 80,
            ],
            [
                'key'         => 'sms_ir_chat_param_name',
                'value'       => 'NAME',
                'type'        => 'text',
                'group'       => 'sms',
                'label'       => 'نام پارامتر فرستنده در قالب چت',
                'description' => 'نام placeholder در قالب چت — مثلاً اگر قالب شما #NAME# دارد، مقدار را NAME بگذارید.',
                'order'       => 81,
            ],
        ];
        $now = now();
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
            'sms_ir_chat_template_id',
            'sms_ir_chat_param_name',
        ])->delete();
    }
};
