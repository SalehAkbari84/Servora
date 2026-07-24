<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Services;

use App\Models\BusinessVerification;
use Illuminate\Support\Facades\DB;
use Throwable;

class BusinessVerificationService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Verify or reject a business verification request
     * by calling the VerifyBusiness stored procedure.
     *
     * @param int         $verificationId
     * @param int         $adminId         Admin user ID performing the action
     * @param string      $newStatus       'تایید شده' or 'رد شده'
     * @param string|null $note            Optional admin note
     * @return array{success: bool, code: int, message: string}
     */
    public function verify(
        int $verificationId,
        int $adminId,
        string $newStatus,
        ?string $note = null
    ): array {
        try {
            DB::statement('SET @p_result_code = NULL, @p_result_msg = NULL');

            DB::statement(
                'CALL VerifyBusiness(?, ?, ?, ?, @p_result_code, @p_result_msg)',
                [
                    $verificationId,
                    $adminId,
                    $newStatus,
                    $note,
                ]
            );

            $result = DB::select(
                'SELECT @p_result_code AS result_code, @p_result_msg AS result_msg'
            );

            $row  = $result[0];
            $code = (int) $row->result_code;

            // Instant inbox notification on successful verify/reject
            if ($code === 0) {
                $this->pushVerifyNotification($verificationId, $newStatus, $note);
            }

            return [
                'success' => $code === 0,
                'code'    => $code,
                'message' => $row->result_msg ?? $this->defaultMessageForCode($code),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'code'    => 99,
                'message' => 'خطای داخلی سرور: ' . $e->getMessage(),
            ];
        }
    }

    /** Write a direct notification to the business owner's inbox. */
    private function pushVerifyNotification(int $verificationId, string $newStatus, ?string $note): void
    {
        $verif = BusinessVerification::find($verificationId);
        if (!$verif || !$verif->owner_user_id) return;

        $approved = $newStatus === 'تایید شده';
        $title    = $approved ? 'کسب‌وکار شما تایید شد' : 'درخواست تایید کسب‌وکار رد شد';

        $body = $approved
            ? sprintf('کسب‌وکار «%s» شما توسط مدیریت تایید و فعال شد.', $verif->business_name)
            : sprintf('درخواست تایید کسب‌وکار «%s» رد شد.', $verif->business_name);

        if ($note) {
            $body .= ' یادداشت مدیر: ' . $note;
        }

        $this->notifications->push([
            'user_id'             => $verif->owner_user_id,
            'type'                => $approved ? 'تایید_کسب‌وکار' : 'رد_کسب‌وکار',
            'title'               => $title,
            'body'                => $body,
            'related_entity_type' => 'businesses',
            'related_entity_id'   => $verif->business_id,
        ]);
    }

    // -------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------

    private function defaultMessageForCode(int $code): string
    {
        return match ($code) {
            0  => 'وضعیت تأیید کسب‌وکار با موفقیت به‌روزرسانی شد.',
            1  => 'درخواست تأیید یافت نشد.',
            2  => 'این درخواست قبلاً بررسی شده است.',
            99 => 'خطای داخلی رخ داده است.',
            default => 'خطای ناشناخته.',
        };
    }
}
