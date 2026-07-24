<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\BusinessSlot;
use App\Models\BusinessVerification;
use App\Models\Category;
use App\Models\Review;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class OwnerController extends Controller
{
    // ── Helpers ───────────────────────────────────────────────

    private function ownerBusiness(Request $request): ?Business
    {
        return Business::where('owner_user_id', $request->user()->id)->first();
    }

    /**
     * Server-side defense-in-depth for the address field. Mirrors the frontend
     * `streetAddress` rule so a tampered request can't bypass the check.
     *
     * Returns a Persian error message, or null when the address is clean.
     */
    private function validateStreetAddress(?string $address, ?string $province, ?string $city): ?string
    {
        if (!$address) return null;
        $a = trim($address);
        if ($a === '') return null;

        // Prefix guard: reject "استان X" / "شهر Y" at the start.
        if (preg_match('/^\s*(استان|شهر)\s+/u', $a)) {
            return 'آدرس باید فقط شامل خیابان/کوچه/پلاک باشد — عبارات «استان» و «شهر» را حذف کنید';
        }

        $padded = ' ' . preg_replace('/\s+/u', ' ', $a) . ' ';
        $boundary = '/[\s,،.;:\-]/u';

        $containsWord = function (string $needle) use ($padded, $boundary): bool {
            if ($needle === '') return false;
            $escaped = preg_quote($needle, '/');
            return (bool) preg_match("/(?:^|{$boundary})${escaped}(?:{$boundary}|$)/u", $padded);
        };

        if ($province && $containsWord($province)) {
            return "آدرس نباید دوباره شامل نام استان («{$province}») باشد";
        }
        if ($city && $containsWord($city)) {
            return "آدرس نباید دوباره شامل نام شهر («{$city}») باشد";
        }
        return null;
    }

    private function ok($data, string $msg = '', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'message' => $msg, 'code' => $status], $status);
    }

    private function fail(string $msg, int $status = 422): JsonResponse
    {
        return response()->json(['success' => false, 'data' => null, 'message' => $msg, 'code' => $status], $status);
    }

    // ── Business ──────────────────────────────────────────────

    public function getBusiness(Request $request): JsonResponse
    {
        $biz = $this->ownerBusiness($request);

        if (!$biz) {
            return response()->json(['success' => true, 'data' => null, 'message' => 'کسب‌وکاری ثبت نشده است.', 'code' => 200]);
        }

        $latestVerification = BusinessVerification::where('business_id', $biz->id)
            ->orderByDesc('id')
            ->first();

        return $this->ok([
            'business'     => $biz,
            'verification' => $latestVerification,
        ]);
    }

    public function createBusiness(Request $request): JsonResponse
    {
        if ($this->ownerBusiness($request)) {
            return $this->fail('شما قبلاً یک کسب‌وکار ثبت کرده‌اید.');
        }

        $user = $request->user();

        $data = $request->validate([
            'name'             => 'required|string|max:200',
            'category_id'      => 'nullable|integer|exists:categories,id',
            'category_name'    => 'required|string|max:100',
            'subcategory_name' => 'nullable|string|max:100',
            'gender_type'      => ['required', Rule::in(['مرد', 'زن', 'هر دو'])],
            'description'      => 'nullable|string|max:2000',
            'address_text'     => 'required|string|max:500',
            'province_code'    => 'required|string|max:8',
            'province_name'    => 'required|string|max:50',
            'city'             => 'required|string|max:100',
            'phone'            => 'required|string|max:15',
        ]);

        // Coerce nulls (from ConvertEmptyStringsToNull middleware) to '' for
        // the NOT NULL VARCHAR columns.
        foreach (['category_name', 'subcategory_name', 'address_text', 'phone',
                  'province_code', 'province_name', 'city'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] === null) {
                $data[$k] = '';
            }
        }

        if ($err = $this->validateStreetAddress($data['address_text'] ?? null, $data['province_name'] ?? null, $data['city'] ?? null)) {
            return $this->fail($err);
        }

        $biz = Business::create([
            ...$data,
            'subcategory_name' => $data['subcategory_name'] ?? '',
            'owner_user_id'    => $user->id,
            'owner_name'       => $user->full_name,
            'owner_phone'      => $user->phone,
            'is_verified'      => false,
            'is_active'        => false,
            'rating_avg'       => 0,
            'rating_sum'       => 0,
            'rating_count'     => 0,
            'total_reviews'    => 0,
        ]);

        // Auto-enqueue a pending verification request so the admin sees this
        // business in the verification queue immediately, without requiring
        // the owner to perform a separate "request verification" step.
        BusinessVerification::create([
            'business_id'    => $biz->id,
            'business_name'  => $biz->name,
            'owner_user_id'  => $user->id,
            'owner_phone'    => $user->phone,
            'phone_verified' => false,
            'address_text'   => $data['address_text'],
            'document_url'   => null,
            'status'         => 'در انتظار',
        ]);

        return $this->ok($biz, 'کسب‌وکار با موفقیت ثبت شد و در صف بررسی قرار گرفت.', 201);
    }

    public function updateBusiness(Request $request): JsonResponse
    {
        $biz = $this->ownerBusiness($request);

        if (!$biz) {
            return $this->fail('کسب‌وکاری یافت نشد.', 404);
        }

        $data = $request->validate([
            'name'             => 'sometimes|string|max:200',
            'category_id'      => 'nullable|integer|exists:categories,id',
            'category_name'    => 'sometimes|string|max:100',
            'subcategory_name' => 'nullable|string|max:100',
            'gender_type'      => ['sometimes', Rule::in(['مرد', 'زن', 'هر دو'])],
            'description'      => 'nullable|string|max:2000',
            'address_text'     => 'sometimes|string|max:500',
            'province_code'    => 'sometimes|string|max:8',
            'province_name'    => 'sometimes|string|max:50',
            'city'             => 'sometimes|string|max:100',
            'phone'            => 'sometimes|string|max:15',
        ]);

        // Laravel's ConvertEmptyStringsToNull middleware turns '' into null
        // on incoming requests. The businesses table has several NOT NULL
        // VARCHAR columns with default '' — coerce nulls back to '' so the
        // UPDATE doesn't fail with a 1048 constraint violation.
        foreach (['category_name', 'subcategory_name', 'address_text', 'phone',
                  'province_code', 'province_name', 'city'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] === null) {
                $data[$k] = '';
            }
        }

        // Defense-in-depth: reject addresses that re-include the chosen
        // province or city. Falls back to the current value on the row when
        // the request didn't include that field.
        $provinceCheck = $data['province_name'] ?? $biz->province_name;
        $cityCheck     = $data['city']          ?? $biz->city;
        $addressCheck  = $data['address_text']  ?? null;
        if ($addressCheck !== null) {
            if ($err = $this->validateStreetAddress($addressCheck, $provinceCheck, $cityCheck)) {
                return $this->fail($err);
            }
        }

        $biz->update($data);

        return $this->ok($biz, 'اطلاعات کسب‌وکار به‌روزرسانی شد.');
    }

    /**
     * POST /api/owner/business/logo — multipart upload of the business logo
     * image. Replaces any existing logo (old file is deleted from disk).
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $biz = $this->ownerBusiness($request);
        if (!$biz) return $this->fail('کسب‌وکاری یافت نشد.', 404);

        $maxMb = (int) Setting::get('upload_max_mb', '2');
        $maxKb = max(1, $maxMb) * 1024;
        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', "max:{$maxKb}"],
        ]);

        if ($biz->logo_url) {
            Storage::disk('public')->delete($biz->logo_url);
        }

        $path = $request->file('logo')->store('logos', 'public');
        $biz->logo_url = $path;
        $biz->save();

        return $this->ok($biz, 'لوگوی کسب‌وکار به‌روزرسانی شد.');
    }

    /**
     * DELETE /api/owner/business/logo — remove logo, fall back to first-letter placeholder.
     */
    public function deleteLogo(Request $request): JsonResponse
    {
        $biz = $this->ownerBusiness($request);
        if (!$biz) return $this->fail('کسب‌وکاری یافت نشد.', 404);

        if ($biz->logo_url) {
            Storage::disk('public')->delete($biz->logo_url);
            $biz->logo_url = null;
            $biz->save();
        }
        return $this->ok($biz, 'لوگو حذف شد.');
    }

    // ── Services ──────────────────────────────────────────────

    public function getServices(Request $request): JsonResponse
    {
        $biz = $this->ownerBusiness($request);
        if (!$biz) return $this->fail('کسب‌وکاری یافت نشد.', 404);

        $services = Service::where('business_id', $biz->id)->orderBy('service_name')->get();
        return $this->ok($services);
    }

    public function createService(Request $request): JsonResponse
    {
        $biz = $this->ownerBusiness($request);
        if (!$biz) return $this->fail('کسب‌وکاری یافت نشد.', 404);

        $data = $request->validate([
            'service_name'     => 'required|string|max:200',
            'gender_type'      => ['required', Rule::in(['مرد', 'زن', 'هر دو'])],
            'duration_minutes' => 'required|integer|min:5|max:480',
            'price'            => 'required|numeric|min:0',
            'is_active'        => 'sometimes|boolean',
        ]);

        $service = Service::create([
            ...$data,
            'business_id'   => $biz->id,
            'business_name' => $biz->name,
            'category_name' => $biz->category_name,
            'is_active'     => $data['is_active'] ?? true,
        ]);

        return $this->ok($service, 'خدمت با موفقیت اضافه شد.', 201);
    }

    public function updateService(Request $request, int $serviceId): JsonResponse
    {
        $biz = $this->ownerBusiness($request);
        if (!$biz) return $this->fail('کسب‌وکاری یافت نشد.', 404);

        $service = Service::where('id', $serviceId)->where('business_id', $biz->id)->first();
        if (!$service) return $this->fail('خدمت یافت نشد.', 404);

        $data = $request->validate([
            'service_name'     => 'sometimes|string|max:200',
            'gender_type'      => ['sometimes', Rule::in(['مرد', 'زن', 'هر دو'])],
            'duration_minutes' => 'sometimes|integer|min:5|max:480',
            'price'            => 'sometimes|numeric|min:0',
            'is_active'        => 'sometimes|boolean',
        ]);

        $service->update($data);
        return $this->ok($service, 'خدمت به‌روزرسانی شد.');
    }

    public function deleteService(Request $request, int $serviceId): JsonResponse
    {
        $biz = $this->ownerBusiness($request);
        if (!$biz) return $this->fail('کسب‌وکاری یافت نشد.', 404);

        $service = Service::where('id', $serviceId)->where('business_id', $biz->id)->first();
        if (!$service) return $this->fail('خدمت یافت نشد.', 404);

        $service->delete();
        return $this->ok(null, 'خدمت حذف شد.');
    }

    // ── Slots ─────────────────────────────────────────────────

    public function getSlots(Request $request): JsonResponse
    {
        $biz = $this->ownerBusiness($request);
        if (!$biz) return $this->fail('کسب‌وکاری یافت نشد.', 404);

        $slots = BusinessSlot::where('business_id', $biz->id)
            ->orderBy('day_of_week')
            ->orderBy('time_slot')
            ->get()
            ->append('day_name');

        return $this->ok($slots);
    }

    public function createSlots(Request $request): JsonResponse
    {
        $biz = $this->ownerBusiness($request);
        if (!$biz) return $this->fail('کسب‌وکاری یافت نشد.', 404);

        $data = $request->validate([
            'day_of_week'  => 'required|integer|min:0|max:6',
            'time_slots'   => 'required|array|min:1',
            'time_slots.*' => 'required|string|regex:/^\d{2}:\d{2}$/',
            'max_capacity' => 'required|integer|min:1|max:50',
        ]);

        // Dedup input + build bulk payload. The table has UNIQUE (business_id,
        // day_of_week, time_slot) — `insertOrIgnore` is one round-trip and
        // skips slots that already exist (no per-row exists() probe).
        $uniqueTimes = array_values(array_unique($data['time_slots']));
        $rows = array_map(fn ($t) => [
            'business_id'   => $biz->id,
            'business_name' => $biz->name,
            'day_of_week'   => $data['day_of_week'],
            'time_slot'     => $t,
            'max_capacity'  => $data['max_capacity'],
            'is_active'     => true,
        ], $uniqueTimes);

        $inserted = BusinessSlot::insertOrIgnore($rows);

        // Return the freshly-existing rows for the day so the UI can refresh
        // without an extra GET.
        $created = BusinessSlot::where('business_id', $biz->id)
            ->where('day_of_week', $data['day_of_week'])
            ->whereIn('time_slot', $uniqueTimes)
            ->get();

        return $this->ok($created, $inserted . ' اسلات اضافه شد.', 201);
    }

    public function deleteSlot(Request $request, int $slotId): JsonResponse
    {
        $biz = $this->ownerBusiness($request);
        if (!$biz) return $this->fail('کسب‌وکاری یافت نشد.', 404);

        $slot = BusinessSlot::where('id', $slotId)->where('business_id', $biz->id)->first();
        if (!$slot) return $this->fail('اسلات یافت نشد.', 404);

        $slot->delete();
        return $this->ok(null, 'اسلات حذف شد.');
    }

    public function deleteDaySlots(Request $request): JsonResponse
    {
        $biz = $this->ownerBusiness($request);
        if (!$biz) return $this->fail('کسب‌وکاری یافت نشد.', 404);

        $day = $request->validate(['day_of_week' => 'required|integer|min:0|max:6'])['day_of_week'];

        BusinessSlot::where('business_id', $biz->id)->where('day_of_week', $day)->delete();
        return $this->ok(null, 'اسلات‌های روز حذف شدند.');
    }

    // ── Verification ──────────────────────────────────────────

    public function submitVerification(Request $request): JsonResponse
    {
        $biz = $this->ownerBusiness($request);
        if (!$biz) return $this->fail('ابتدا کسب‌وکار خود را ثبت کنید.', 404);

        $pending = BusinessVerification::where('business_id', $biz->id)
            ->where('status', 'در انتظار')
            ->exists();

        if ($pending) {
            return $this->fail('درخواست تایید قبلاً ارسال شده و در حال بررسی است.');
        }

        if ($biz->is_verified) {
            return $this->fail('کسب‌وکار شما قبلاً تایید شده است.');
        }

        $data = $request->validate([
            'address_text'  => 'required|string|max:500',
            'document_url'  => 'nullable|string|max:500',
        ]);

        $verification = BusinessVerification::create([
            'business_id'   => $biz->id,
            'business_name' => $biz->name,
            'owner_user_id' => $request->user()->id,
            'owner_phone'   => $request->user()->phone,
            'phone_verified'=> false,
            'address_text'  => $data['address_text'],
            'document_url'  => $data['document_url'] ?? null,
            'status'        => 'در انتظار',
        ]);

        return $this->ok($verification, 'درخواست تایید با موفقیت ارسال شد.', 201);
    }

    public function getVerification(Request $request): JsonResponse
    {
        $biz = $this->ownerBusiness($request);
        if (!$biz) return $this->ok(null, 'کسب‌وکاری یافت نشد.');

        $verifications = BusinessVerification::where('business_id', $biz->id)
            ->orderByDesc('id')
            ->get();

        return $this->ok($verifications);
    }

    // ── Appointments ──────────────────────────────────────────

    public function getAppointments(Request $request): JsonResponse
    {
        $biz = $this->ownerBusiness($request);
        if (!$biz) return $this->fail('کسب‌وکاری یافت نشد.', 404);

        $perPage = min((int) $request->query('per_page', 20), 50);

        $query = Appointment::where('business_id', $biz->id)
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('date')) {
            $query->where('date_shamsi', $request->input('date'));
        }

        return $this->ok($query->paginate($perPage));
    }

    /**
     * PATCH /api/owner/appointments/{id} — owner can confirm/complete/cancel
     * appointments belonging to their business.
     *
     * State-transition rules live in `trg_appointments_before_update` (DB
     * trigger). We don't duplicate them here — illegal transitions surface
     * as a SIGNAL'd QueryException which we translate to a Persian 422.
     */
    public function updateAppointmentStatus(Request $request, int $id): JsonResponse
    {
        $biz = $this->ownerBusiness($request);
        if (!$biz) return $this->fail('کسب‌وکاری یافت نشد.', 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(['تایید شده', 'انجام شده', 'لغو شده'])],
        ]);

        $appt = Appointment::where('id', $id)
            ->where('business_id', $biz->id)
            ->first();
        if (!$appt) return $this->fail('نوبت یافت نشد.', 404);

        $updates = ['status' => $data['status']];
        if ($data['status'] === 'لغو شده') {
            $updates['cancelled_by'] = $request->user()->id;
            $updates['cancelled_at'] = now();
        }

        try {
            $appt->update($updates);
        } catch (QueryException $e) {
            return $this->fail($this->cleanDbError($e, 'تغییر وضعیت نوبت امکان‌پذیر نیست'), 422);
        }

        return $this->ok($appt, 'وضعیت نوبت به‌روزرسانی شد.');
    }

    /**
     * Pull a clean Persian message out of a DB SIGNAL exception. Used when
     * a trigger rejects a write — the SQLSTATE/SQL prefix is noise.
     */
    private function cleanDbError(QueryException $e, string $fallback): string
    {
        $msg = $e->getMessage();
        if (preg_match('/^SQLSTATE\[[\w]+\]:.*?:\s*\d+\s+(.+?)(?:\s*\(Connection:.*|\s*\(SQL:.*|$)/su', $msg, $m)) {
            $clean = trim($m[1]);
            if (mb_strlen($clean) > 2 && mb_strlen($clean) < 200) return $clean;
        }
        return $fallback;
    }

    public function getStats(Request $request): JsonResponse
    {
        $biz = $this->ownerBusiness($request);
        if (!$biz) return $this->ok(['has_business' => false]);

        $bid = $biz->id;

        // Counter block — all KPI cards collapsed into one round-trip.
        $row = DB::selectOne("
            SELECT
                (SELECT COUNT(*) FROM appointments WHERE business_id = ?)                                          AS total_appts,
                (SELECT COUNT(*) FROM appointments WHERE business_id = ? AND status = 'در انتظار')                  AS pending_appts,
                (SELECT COUNT(*) FROM appointments WHERE business_id = ? AND status = 'تایید شده')                  AS confirmed_appts,
                (SELECT COUNT(*) FROM appointments WHERE business_id = ? AND status = 'انجام شده')                  AS done_appts,
                (SELECT COUNT(*) FROM appointments WHERE business_id = ? AND status = 'لغو شده')                    AS cancelled_appts,
                (SELECT COUNT(*) FROM services     WHERE business_id = ?)                                          AS total_services,
                (SELECT COUNT(*) FROM business_slots WHERE business_id = ?)                                        AS total_slots,
                (SELECT COUNT(*) FROM appointments WHERE business_id = ? AND DATE(created_at) = CURDATE())         AS appts_today,
                (SELECT COUNT(*) FROM appointments WHERE business_id = ? AND DATE(created_at) = CURDATE() - INTERVAL 1 DAY) AS appts_yesterday,
                (SELECT COUNT(*) FROM appointments WHERE business_id = ? AND created_at >= CURDATE() - INTERVAL 7 DAY)  AS appts_week,
                (SELECT COUNT(*) FROM appointments WHERE business_id = ? AND created_at >= CURDATE() - INTERVAL 30 DAY) AS appts_month,
                (SELECT COALESCE(SUM(price),0) FROM appointments WHERE business_id = ? AND status IN ('تایید شده','انجام شده')) AS revenue_total,
                (SELECT COALESCE(SUM(price),0) FROM appointments WHERE business_id = ? AND status IN ('تایید شده','انجام شده') AND created_at >= CURDATE() - INTERVAL 7 DAY) AS revenue_week,
                (SELECT COALESCE(SUM(price),0) FROM appointments WHERE business_id = ? AND status IN ('تایید شده','انجام شده') AND created_at >= CURDATE() - INTERVAL 30 DAY) AS revenue_month,
                (SELECT COUNT(DISTINCT user_id) FROM appointments WHERE business_id = ?)                            AS unique_customers,
                (SELECT COUNT(*) FROM reviews WHERE business_id = ?)                                                AS total_reviews
        ", array_fill(0, 16, $bid));

        // 14-day time series (daily appointment count) for the line chart.
        // Left-pad the missing days with 0 so the X axis is continuous.
        $rawSeries = DB::table('appointments')
            ->where('business_id', $bid)
            ->where('created_at', '>=', now()->subDays(13)->startOfDay())
            ->selectRaw('DATE(created_at) AS day, COUNT(*) AS cnt, SUM(CASE WHEN status IN (\'تایید شده\',\'انجام شده\') THEN price ELSE 0 END) AS revenue')
            ->groupBy('day')->orderBy('day')->get()->keyBy('day');

        $series = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = now()->subDays($i)->toDateString();
            $r = $rawSeries[$d] ?? null;
            $series[] = [
                'date'    => $d,
                'count'   => (int) ($r->cnt ?? 0),
                'revenue' => (int) ($r->revenue ?? 0),
            ];
        }

        // Status donut data
        $statusBreakdown = [
            ['label' => 'در انتظار',  'value' => (int) $row->pending_appts,   'color' => '#f59e0b'],
            ['label' => 'تایید شده', 'value' => (int) $row->confirmed_appts, 'color' => '#3b82f6'],
            ['label' => 'انجام شده', 'value' => (int) $row->done_appts,      'color' => '#10b981'],
            ['label' => 'لغو شده',    'value' => (int) $row->cancelled_appts, 'color' => '#ef4444'],
        ];

        // Top 5 services by booking count
        $topServices = DB::table('appointments')
            ->where('business_id', $bid)
            ->selectRaw('service_name, COUNT(*) AS cnt, SUM(CASE WHEN status IN (\'تایید شده\',\'انجام شده\') THEN price ELSE 0 END) AS revenue')
            ->groupBy('service_name')
            ->orderByDesc('cnt')->limit(5)->get();

        // Busy hours — booking count by hour-of-day, for a 24-bar histogram
        $hourly = DB::table('appointments')
            ->where('business_id', $bid)
            ->selectRaw("SUBSTRING(time_slot, 1, 2) AS hour, COUNT(*) AS cnt")
            ->groupBy('hour')->get()->keyBy('hour');
        $busyHours = [];
        for ($h = 0; $h < 24; $h++) {
            $key = str_pad((string) $h, 2, '0', STR_PAD_LEFT);
            $busyHours[] = ['hour' => $h, 'count' => (int) ($hourly[$key]->cnt ?? 0)];
        }

        // Recent activity — last 6 appointments with status
        $recentActivity = DB::table('appointments')
            ->where('business_id', $bid)
            ->orderByDesc('id')->limit(6)
            ->get(['id', 'user_name', 'service_name', 'date_shamsi', 'time_slot', 'status', 'created_at']);

        return $this->ok([
            'has_business'      => true,
            'business'          => $biz,
            // Core counters
            'total_appts'       => (int) $row->total_appts,
            'pending_appts'     => (int) $row->pending_appts,
            'confirmed_appts'   => (int) $row->confirmed_appts,
            'done_appts'        => (int) $row->done_appts,
            'cancelled_appts'   => (int) $row->cancelled_appts,
            'total_services'    => (int) $row->total_services,
            'total_slots'       => (int) $row->total_slots,
            // Time-windowed
            'appts_today'       => (int) $row->appts_today,
            'appts_yesterday'   => (int) $row->appts_yesterday,
            'appts_week'        => (int) $row->appts_week,
            'appts_month'       => (int) $row->appts_month,
            // Revenue
            'revenue_total'     => (int) $row->revenue_total,
            'revenue_week'      => (int) $row->revenue_week,
            'revenue_month'     => (int) $row->revenue_month,
            // Engagement
            'unique_customers'  => (int) $row->unique_customers,
            'total_reviews'     => (int) $row->total_reviews,
            // Charts
            'series_14d'        => $series,
            'status_breakdown'  => $statusBreakdown,
            'top_services'      => $topServices,
            'busy_hours'        => $busyHours,
            'recent_activity'   => $recentActivity,
        ]);
    }

    // ── Categories (for dropdowns) ────────────────────────────

    public function getCategories(): JsonResponse
    {
        $cats = Category::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        return $this->ok($cats);
    }

    // ── Reviews ───────────────────────────────────────────────

    /** GET /api/owner/reviews — paginated list of reviews on owner's business. */
    public function getReviews(Request $request): JsonResponse
    {
        $biz = $this->ownerBusiness($request);
        if (!$biz) return $this->fail('کسب‌وکاری یافت نشد.', 404);

        $perPage = min((int) $request->query('per_page', 20), 50);
        $reviews = Review::where('business_id', $biz->id)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return $this->ok($reviews);
    }

    /**
     * POST /api/owner/reviews/{id}/reply — owner posts (or replaces) their
     * reply on a review. Empty body removes an existing reply.
     */
    public function replyReview(Request $request, int $id): JsonResponse
    {
        $biz = $this->ownerBusiness($request);
        if (!$biz) return $this->fail('کسب‌وکاری یافت نشد.', 404);

        $review = Review::where('id', $id)
            ->where('business_id', $biz->id)
            ->first();
        if (!$review) return $this->fail('نظر یافت نشد.', 404);

        $data = $request->validate([
            'reply' => 'nullable|string|max:1000',
        ]);

        $reply = trim($data['reply'] ?? '');

        $review->update([
            'owner_reply'    => $reply !== '' ? $reply : null,
            'owner_reply_at' => $reply !== '' ? now() : null,
        ]);

        return $this->ok($review, $reply === '' ? 'پاسخ حذف شد.' : 'پاسخ شما ثبت شد.');
    }
}
