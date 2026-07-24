<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

class QueueService
{
    /**
     * Add a user to the queue by calling the AddToQueue stored procedure.
     *
     * @param array $data  Must contain: user_id, business_id, service_id, date_shamsi, time_slot
     * @return array{success: bool, code: int, message: string, queue_id: int|null, position: int|null}
     */
    public function add(array $data): array
    {
        try {
            $dayOfWeek = JalaliService::dayOfWeek($data['date_shamsi']);

            DB::statement('SET @p_result_code = NULL, @p_result_msg = NULL, @p_queue_id = NULL, @p_position = NULL');

            DB::statement(
                'CALL AddToQueue(?, ?, ?, ?, ?, ?, @p_result_code, @p_result_msg, @p_queue_id, @p_position)',
                [
                    $data['user_id'],
                    $data['business_id'],
                    $data['service_id'],
                    $data['date_shamsi'],
                    $data['time_slot'],
                    $dayOfWeek,
                ]
            );

            $result = DB::select(
                'SELECT @p_result_code AS result_code, @p_result_msg AS result_msg, @p_queue_id AS queue_id, @p_position AS position'
            );

            $row  = $result[0];
            $code = (int) $row->result_code;

            return [
                'success'  => $code === 0,
                'code'     => $code,
                'message'  => $row->result_msg ?? $this->defaultMessageForCode($code),
                'queue_id' => $code === 0 ? (int) $row->queue_id : null,
                'position' => $code === 0 ? (int) $row->position : null,
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'success'  => false,
                'code'     => 99,
                'message'  => 'فرمت تاریخ شمسی نامعتبر است: ' . $e->getMessage(),
                'queue_id' => null,
                'position' => null,
            ];
        } catch (Throwable $e) {
            return [
                'success'  => false,
                'code'     => 99,
                'message'  => 'خطای داخلی سرور: ' . $e->getMessage(),
                'queue_id' => null,
                'position' => null,
            ];
        }
    }

    // -------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------

    private function defaultMessageForCode(int $code): string
    {
        return match ($code) {
            0 => 'با موفقیت به صف اضافه شدید.',
            1 => 'کاربر غیرفعال است.',
            2 => 'کسب‌وکار یافت نشد.',
            3 => 'کسب‌وکار غیرفعال است.',
            4 => 'کسب‌وکار تأیید نشده است.',
            5 => 'خدمت یافت نشد یا غیرفعال است.',
            6 => 'شما قبلاً در این صف ثبت‌نام کرده‌اید.',
            7 => 'اسلات قبلاً به نوبت ارتقا یافته است.',
            8 => 'اسلات انتخابی در برنامه کسب‌وکار وجود ندارد.',
            99 => 'خطای داخلی رخ داده است.',
            default => 'خطای ناشناخته.',
        };
    }
}
