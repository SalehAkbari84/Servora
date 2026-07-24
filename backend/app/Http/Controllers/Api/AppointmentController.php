<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\AppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    public function __construct(
        private readonly AppointmentService $appointmentService
    ) {}

    /**
     * List the authenticated user's appointments (paginated).
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 15);
        $perPage = min(max($perPage, 5), 50); // clamp between 5 and 50

        $query = Appointment::where('user_id', $request->user()->id)
            ->orderByDesc('created_at');

        // Optional status filter
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $appointments = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $appointments,
            'message' => 'لیست نوبت‌های شما.',
            'code'    => 200,
        ]);
    }

    /**
     * Book a new appointment.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'business_id' => ['required', 'integer', 'exists:businesses,id'],
                'service_id'  => ['required', 'integer', 'exists:services,id'],
                'date_shamsi' => ['required', 'string', 'regex:/^\d{4}\/\d{2}\/\d{2}$/'],
                'time_slot'   => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'داده‌های ورودی نامعتبر است.',
                'code'    => 422,
                'errors'  => $e->errors(),
            ], 422);
        }

        // Per-day active-appointment cap (admin-configurable; zero disables)
        $maxPerDay = (int) \App\Models\Setting::get('max_appointments_per_day', '0');
        if ($maxPerDay > 0) {
            $active = \App\Models\Appointment::where('user_id', $request->user()->id)
                ->where('date_shamsi', $validated['date_shamsi'])
                ->whereIn('status', ['در انتظار', 'تایید شده'])
                ->count();
            if ($active >= $maxPerDay) {
                return response()->json([
                    'success' => false,
                    'data'    => null,
                    'message' => "حداکثر {$maxPerDay} نوبت فعال در روز قابل ثبت است.",
                    'code'    => 422,
                ], 422);
            }
        }

        $result = $this->appointmentService->book([
            'user_id'     => $request->user()->id,
            'business_id' => $validated['business_id'],
            'service_id'  => $validated['service_id'],
            'date_shamsi' => $validated['date_shamsi'],
            'time_slot'   => $validated['time_slot'],
        ]);

        $httpStatus = $result['success'] ? 201 : 422;

        return response()->json([
            'success' => $result['success'],
            'data'    => $result['success'] ? ['appointment_id' => $result['appointment_id']] : null,
            'message' => $result['message'],
            'code'    => $result['code'],
        ], $httpStatus);
    }

    /**
     * Cancel an appointment.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        // ── Cancellation-window gate ───────────────────────────────
        // The admin can set `cancellation_window_hours`: a user can cancel
        // up to N hours before their appointment time. Zero/empty disables
        // the check (cancel always allowed). The check is intentionally
        // skipped if we can't parse the date — fall through to the SP and
        // let it surface a meaningful error.
        $appt = \App\Models\Appointment::find($id);
        if ($appt && $appt->user_id === $request->user()->id) {
            $hours = (int) \App\Models\Setting::get('cancellation_window_hours', '0');
            if ($hours > 0) {
                try {
                    [$y, $m, $d] = explode('/', $appt->date_shamsi);
                    [$gY, $gM, $gD] = \App\Services\JalaliService::toGregorian((int)$y, (int)$m, (int)$d);
                    $apptDt = \Carbon\Carbon::createFromFormat('Y-n-j H:i', "$gY-$gM-$gD {$appt->time_slot}", config('app.timezone'));
                    if ($apptDt && now()->addHours($hours)->greaterThan($apptDt)) {
                        return response()->json([
                            'success' => false,
                            'data'    => null,
                            'message' => "لغو نوبت تنها تا {$hours} ساعت قبل از زمان نوبت ممکن است.",
                            'code'    => 422,
                        ], 422);
                    }
                } catch (\Throwable) { /* fall through */ }
            }
        }

        // Validate optional cancel_reason
        $reason = null;
        if ($request->filled('cancel_reason')) {
            $reason = (string) $request->input('cancel_reason');
        }

        $result = $this->appointmentService->cancel(
            $id,
            $request->user()->id,
            $reason
        );

        $httpStatus = $result['success'] ? 200 : 422;

        return response()->json([
            'success' => $result['success'],
            'data'    => null,
            'message' => $result['message'],
            'code'    => $result['code'],
        ], $httpStatus);
    }
}
