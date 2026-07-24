<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Services;

use App\Models\Appointment;
use Illuminate\Support\Facades\DB;
use Throwable;

class AppointmentService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Book an appointment by calling the CreateAppointment stored procedure.
     *
     * @param array $data  Must contain: user_id, business_id, service_id, date_shamsi, time_slot
     * @return array{success: bool, code: int, message: string, appointment_id: int|null}
     */
    public function book(array $data): array
    {
        try {
            $dayOfWeek = JalaliService::dayOfWeek($data['date_shamsi']);

            DB::statement('SET @p_result_code = NULL, @p_result_msg = NULL, @p_appointment_id = NULL');

            DB::statement(
                'CALL CreateAppointment(?, ?, ?, ?, ?, ?, @p_result_code, @p_result_msg, @p_appointment_id)',
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
                'SELECT @p_result_code AS result_code, @p_result_msg AS result_msg, @p_appointment_id AS appointment_id'
            );

            $row  = $result[0];
            $code = (int) $row->result_code;
            $apptId = $code === 0 ? (int) $row->appointment_id : null;

            // Instant inbox notification on successful booking
            if ($code === 0 && $apptId) {
                $appt = Appointment::find($apptId);
                if ($appt) {
                    $this->notifications->push([
                        'user_id'             => $data['user_id'],
                        'type'                => 'رزرو_موفق',
                        'title'               => 'نوبت شما ثبت شد',
                        'body'                => sprintf(
                            'نوبت شما در %s برای تاریخ %s ساعت %s ثبت شد.',
                            $appt->business_name,
                            $appt->date_shamsi,
                            $appt->time_slot,
                        ),
                        'related_entity_type' => 'appointments',
                        'related_entity_id'   => $apptId,
                    ]);
                }
            }

            return [
                'success'        => $code === 0,
                'code'           => $code,
                'message'        => $row->result_msg ?? $this->defaultMessageForCode($code),
                'appointment_id' => $apptId,
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'success'        => false,
                'code'           => 99,
                'message'        => 'فرمت تاریخ شمسی نامعتبر است: ' . $e->getMessage(),
                'appointment_id' => null,
            ];
        } catch (Throwable $e) {
            return [
                'success'        => false,
                'code'           => 99,
                'message'        => 'خطای داخلی سرور: ' . $e->getMessage(),
                'appointment_id' => null,
            ];
        }
    }

    /**
     * Cancel an appointment by calling the CancelAppointment stored procedure.
     *
     * @param int         $appointmentId
     * @param int         $cancelledBy    User ID performing the cancellation
     * @param string|null $reason
     * @return array{success: bool, code: int, message: string}
     */
    public function cancel(int $appointmentId, int $cancelledBy, ?string $reason = null): array
    {
        try {
            DB::statement('SET @p_result_code = NULL, @p_result_msg = NULL');

            DB::statement(
                'CALL CancelAppointment(?, ?, ?, @p_result_code, @p_result_msg)',
                [
                    $appointmentId,
                    $cancelledBy,
                    $reason,
                ]
            );

            $result = DB::select(
                'SELECT @p_result_code AS result_code, @p_result_msg AS result_msg'
            );

            $row  = $result[0];
            $code = (int) $row->result_code;

            // Instant inbox notification on successful cancellation
            if ($code === 0) {
                $appt = Appointment::find($appointmentId);
                if ($appt) {
                    $this->notifications->push([
                        'user_id'             => $appt->user_id,
                        'type'                => 'لغو_نوبت',
                        'title'               => 'نوبت شما لغو شد',
                        'body'                => sprintf(
                            'نوبت %s — %s ساعت %s لغو شد.',
                            $appt->business_name,
                            $appt->date_shamsi,
                            $appt->time_slot,
                        ),
                        'related_entity_type' => 'appointments',
                        'related_entity_id'   => $appointmentId,
                    ]);
                }
            }

            return [
                'success' => $code === 0,
                'code'    => $code,
                'message' => $row->result_msg ?? $this->defaultCancelMessageForCode($code),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'code'    => 99,
                'message' => 'خطای داخلی سرور: ' . $e->getMessage(),
            ];
        }
    }

    // -------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------

    private function defaultMessageForCode(int $code): string
    {
        return match ($code) {
            0  => 'نوبت با موفقیت ثبت شد.',
            1  => 'کاربر غیرفعال است.',
            2  => 'کسب‌وکار یافت نشد.',
            3  => 'کسب‌وکار غیرفعال است.',
            4  => 'کسب‌وکار تأیید نشده است.',
            5  => 'خدمت یافت نشد یا غیرفعال است.',
            6  => 'این اسلات قبلاً رزرو شده است.',
            7  => 'اسلات انتخابی در برنامه کسب‌وکار وجود ندارد.',
            99 => 'خطای داخلی رخ داده است.',
            default => 'خطای ناشناخته.',
        };
    }

    private function defaultCancelMessageForCode(int $code): string
    {
        return match ($code) {
            0  => 'نوبت با موفقیت لغو شد.',
            1  => 'نوبت یافت نشد.',
            2  => 'این نوبت قابل لغو نیست.',
            99 => 'خطای داخلی رخ داده است.',
            default => 'خطای ناشناخته.',
        };
    }
}
