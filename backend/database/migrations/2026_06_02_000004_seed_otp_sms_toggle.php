<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `sms_otp_enabled` — master switch for OTP SMS during register/login.
 *
 * When OFF (dev mode):
 *   - OtpService still generates + stores the code (so verify() works)
 *   - But it does NOT call sms.ir, so no credit is consumed
 *   - The code is echoed back in the API response under `dev_code` so the
 *     frontend can display it. The login/register flow proceeds normally.
 *
 * When ON (production):
 *   - Standard flow — SMS is sent, no code is leaked in the response.
 *
 * Default is ON. Admin turns it OFF in the SMS settings panel while
 * developing, to avoid burning sms.ir credit on every test login.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $row = [
            'key'         => 'sms_otp_enabled',
            'value'       => '1',
            'type'        => 'boolean',
            'group'       => 'sms',
            'label'       => 'ارسال پیامک ورود/ثبت‌نام',
            'description' => 'وقتی خاموش است سیستم OTP کار می‌کند ولی پیامک ارسال نمی‌شود — کد در پاسخ API برمی‌گردد و در صفحه نمایش داده می‌شود (مخصوص حالت توسعه — اعتبار سرویس پیامک مصرف نمی‌شود).',
            'order'       => 5,  // very early in the SMS group — high visibility
        ];

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

    public function down(): void
    {
        DB::table('settings')->where('key', 'sms_otp_enabled')->delete();
    }
};
