<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\BusinessSlot;
use App\Models\BusinessVerification;
use App\Models\Category;
use App\Models\AppNotification;
use App\Models\NotificationOutbox;
use App\Models\QueueEntry;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use App\Services\BusinessVerificationService;
use App\Services\NotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminCrudController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Generic paginator with filter engine.
     *
     * Each admin list endpoint passes `$searchCols` (columns that the free
     * text search OR-matches with LIKE) and `$filters` — a map from query
     * parameter name to a column / op tuple.
     *
     * Supported filter operations (passed via the query string):
     *   eq  | =       — equality
     *   in  | a,b,c   — IN with comma-separated values
     *   gte | >=      — created_at[gte]=2026-05-01
     *   lte | <=      — created_at[lte]=2026-05-31
     *   like          — partial match (rare; usually use `search`)
     *
     * Date params (suffix _from / _to) automatically translate to gte/lte
     * on `created_at`. Boolean params accept '1'/'0'/'true'/'false'.
     *
     * Example call:
     *   /api/admin/users?role=ادمین&is_active=1&search=احمد&from=2026-01-01
     *
     * @param array<string, array{column?: string, op?: string, cast?: string}> $filters
     */
    private function paginate($query, Request $request, array $searchCols = [], array $filters = [])
    {
        // Free text search across the configured columns
        if ($request->filled('search') && $searchCols) {
            $s = $request->search;
            $query->where(function ($q) use ($s, $searchCols) {
                foreach ($searchCols as $col) {
                    $q->orWhere($col, 'like', "%{$s}%");
                }
            });
        }

        // Per-column filters from query string. Skip empty values + unknown keys.
        foreach ($filters as $param => $conf) {
            $val = $request->query($param);
            if ($val === null || $val === '' || (is_array($val) && empty($val))) continue;

            $col  = $conf['column'] ?? $param;
            $op   = $conf['op']     ?? 'eq';
            $cast = $conf['cast']   ?? null;

            // Cast helper: bool — accepts '1' / '0' / 'true' / 'false'
            if ($cast === 'bool') $val = in_array(strtolower((string) $val), ['1','true','yes'], true) ? 1 : 0;

            switch ($op) {
                case 'in':
                    $list = is_array($val) ? $val : array_filter(explode(',', (string) $val), fn ($v) => $v !== '');
                    if ($list) $query->whereIn($col, $list);
                    break;
                case 'gte': $query->where($col, '>=', $val); break;
                case 'lte': $query->where($col, '<=', $val); break;
                case 'like': $query->where($col, 'like', "%{$val}%"); break;
                case 'eq':
                default:    $query->where($col, '=', $val); break;
            }
        }

        // Date-range shortcuts on `created_at`
        if ($request->filled('from')) $query->where('created_at', '>=', $request->query('from'));
        if ($request->filled('to'))   $query->where('created_at', '<=', $request->query('to') . ' 23:59:59');

        // Sorting: ?sort=col[,col2]&dir=asc|desc — fall back to id desc.
        $sort = $request->query('sort');
        $dir  = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        if ($sort && preg_match('/^[a-zA-Z0-9_,.]+$/', $sort)) {
            foreach (explode(',', $sort) as $col) $query->orderBy($col, $dir);
        } else {
            $query->orderByDesc('id');
        }

        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);
        return $query->paginate($perPage);
    }

    /**
     * Extract a clean Persian message from a DB SIGNAL exception, stripping
     * SQLSTATE codes, connection info, and the raw SQL statement.
     */
    private function cleanDbError(QueryException $e, string $fallback = 'خطایی در عملیات رخ داد'): string
    {
        $msg = $e->getMessage();
        // Pattern: SQLSTATE[xxxxx]: <<...>>: 1644 ACTUAL_MESSAGE (Connection: ... SQL: ...)
        if (preg_match('/^SQLSTATE\[[\w]+\]:.*?:\s*\d+\s+(.+?)(?:\s*\(Connection:.*|\s*\(SQL:.*|$)/su', $msg, $m)) {
            $clean = trim($m[1]);
            // Persian-looking text only — fall back if extraction got garbage
            if (mb_strlen($clean) > 2 && mb_strlen($clean) < 200) {
                return $clean;
            }
        }
        return $fallback;
    }

    // ── Users ─────────────────────────────────────────────────
    public function users(Request $request)
    {
        $q = User::query();
        return response()->json(['success' => true, 'data' => $this->paginate(
            $q, $request,
            ['full_name', 'phone', 'username'],
            [
                'role'      => ['column' => 'role'],
                'is_active' => ['column' => 'is_active', 'cast' => 'bool'],
            ],
        )]);
    }

    public function createUser(Request $request)
    {
        $data = $request->validate([
            'full_name' => 'required|string|max:120',
            'phone'     => 'required|string|unique:users,phone',
            'role'      => 'required|in:مشتری,کسب‌وکار,ادمین',
            'is_active' => 'boolean',
            'password'  => 'required|string|min:6',
        ]);
        $data['password_hash'] = Hash::make($data['password']);
        unset($data['password']);
        $user = User::create($data);
        return response()->json(['success' => true, 'data' => $user], 201);
    }

    public function updateUser(Request $request, int $id)
    {
        $user = User::findOrFail($id);
        $data = $request->validate([
            'full_name' => 'sometimes|string|max:120',
            'role'      => 'sometimes|in:مشتری,کسب‌وکار,ادمین',
            'is_active' => 'sometimes|boolean',
            'password'  => 'sometimes|string|min:6',
        ]);
        if (isset($data['password'])) {
            $data['password_hash'] = Hash::make($data['password']);
            unset($data['password']);
        }
        $user->update($data);
        return response()->json(['success' => true, 'data' => $user]);
    }

    public function deleteUser(int $id)
    {
        User::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    // ── Admin accounts (primary-only) ─────────────────────────
    // All the section keys a sub-admin can be granted access to. `settings`
    // and `admins` are NEVER in this list — primary-only by design.
    private const GRANTABLE_PERMISSIONS = [
        'users', 'businesses', 'categories', 'services', 'appointments',
        'queue', 'reviews', 'verifications', 'slots', 'notifications',
        'outbox', 'audit',
    ];

    /** List every admin account so the primary can manage them. */
    public function admins(Request $request)
    {
        $q = User::where('role', 'ادمین');
        return response()->json(['success' => true, 'data' => $this->paginate(
            $q, $request,
            ['username', 'full_name', 'phone'],
            [
                'is_primary_admin' => ['column' => 'is_primary_admin', 'cast' => 'bool'],
                'is_active'        => ['column' => 'is_active',        'cast' => 'bool'],
            ],
        )]);
    }

    public function createAdmin(Request $request)
    {
        $data = $request->validate([
            'username'    => 'required|string|min:3|max:50|unique:users,username',
            'full_name'   => 'required|string|max:120',
            'phone'       => 'required|string|regex:/^09\d{9}$/|unique:users,phone',
            'password'    => 'required|string|min:8',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|in:' . implode(',', self::GRANTABLE_PERMISSIONS),
            'is_active'   => 'sometimes|boolean',
        ]);

        $admin = User::create([
            'username'         => $data['username'],
            'full_name'        => $data['full_name'],
            'phone'            => $data['phone'],
            'password_hash'    => Hash::make($data['password']),
            'role'             => 'ادمین',
            'is_primary_admin' => false,    // Only the original primary stays primary
            'permissions'      => $data['permissions'] ?? [],
            'is_active'        => $data['is_active'] ?? true,
        ]);

        $admin->makeHidden(['password_hash']);
        return response()->json(['success' => true, 'data' => $admin, 'message' => 'ادمین جدید با موفقیت ایجاد شد.'], 201);
    }

    public function updateAdmin(Request $request, int $id)
    {
        $admin = User::where('role', 'ادمین')->findOrFail($id);

        // Block edits to the primary admin's role/status from anyone else.
        // Primary can still edit their own profile via /auth/profile elsewhere.
        if ($admin->is_primary_admin && $request->user()->id !== $admin->id) {
            return response()->json([
                'success' => false,
                'message' => 'حساب مدیر اصلی فقط توسط خود او قابل ویرایش است.',
            ], 403);
        }

        $data = $request->validate([
            'full_name'   => 'sometimes|string|max:120',
            'phone'       => 'sometimes|string|regex:/^09\d{9}$/|unique:users,phone,' . $id,
            'password'    => 'sometimes|string|min:8',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'string|in:' . implode(',', self::GRANTABLE_PERMISSIONS),
            'is_active'   => 'sometimes|boolean',
        ]);

        // Primary admin cannot lose primary status via this endpoint —
        // intentional safeguard so the system never ends up with zero
        // primaries. To "demote" the primary, swap by promoting another
        // admin via DB / artisan and explicitly setting is_primary_admin=0.
        if (isset($data['password'])) {
            $data['password_hash'] = Hash::make($data['password']);
            unset($data['password']);
        }

        $admin->update($data);
        $admin->makeHidden(['password_hash']);
        return response()->json(['success' => true, 'data' => $admin, 'message' => 'تغییرات ذخیره شد.']);
    }

    public function deleteAdmin(Request $request, int $id)
    {
        $admin = User::where('role', 'ادمین')->findOrFail($id);

        if ($admin->is_primary_admin) {
            return response()->json([
                'success' => false,
                'message' => 'حساب مدیر اصلی قابل حذف نیست.',
            ], 403);
        }
        if ($admin->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'نمی‌توانید حساب خودتان را حذف کنید.',
            ], 422);
        }

        $admin->delete();
        return response()->json(['success' => true, 'message' => 'ادمین حذف شد.']);
    }

    // ── Businesses ────────────────────────────────────────────
    public function businesses(Request $request)
    {
        $q = Business::query();
        return response()->json(['success' => true, 'data' => $this->paginate(
            $q, $request,
            ['name', 'owner_name', 'owner_phone', 'category_name', 'city'],
            [
                'is_verified'   => ['column' => 'is_verified',   'cast' => 'bool'],
                'is_active'     => ['column' => 'is_active',     'cast' => 'bool'],
                'gender_type'   => ['column' => 'gender_type'],
                'category_name' => ['column' => 'category_name'],
                'province_code' => ['column' => 'province_code'],
                'city'          => ['column' => 'city'],
            ],
        )]);
    }

    public function updateBusiness(Request $request, int $id)
    {
        $biz = Business::findOrFail($id);
        $data = $request->validate([
            'is_verified' => 'sometimes|boolean',
            'is_active'   => 'sometimes|boolean',
            'description' => 'sometimes|nullable|string',
            'name'        => 'sometimes|string|max:200',
            'phone'       => 'sometimes|nullable|string|max:15',
            'address_text'=> 'sometimes|nullable|string|max:500',
        ]);
        // Laravel converts '' → null via middleware; coerce back for NOT NULL columns.
        foreach (['phone', 'address_text'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] === null) $data[$k] = '';
        }
        $biz->update($data);
        return response()->json(['success' => true, 'data' => $biz]);
    }

    public function deleteBusiness(int $id)
    {
        Business::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    // ── Categories ────────────────────────────────────────────
    public function categories(Request $request)
    {
        $q = Category::query();
        return response()->json(['success' => true, 'data' => $this->paginate(
            $q, $request,
            ['name'],
            [
                'is_active' => ['column' => 'is_active', 'cast' => 'bool'],
                'parent_id' => ['column' => 'parent_id'],
            ],
        )]);
    }

    public function storeCategory(Request $request)
    {
        $cat = Category::create($request->validate([
            'name'      => 'required|string|max:100',
            'parent_id' => 'nullable|integer|exists:categories,id',
            'is_active' => 'boolean',
        ]));
        return response()->json(['success' => true, 'data' => $cat], 201);
    }

    public function updateCategory(Request $request, int $id)
    {
        $cat = Category::findOrFail($id);
        $cat->update($request->validate([
            'name'      => 'sometimes|string|max:100',
            'parent_id' => 'nullable|integer|exists:categories,id',
            'is_active' => 'sometimes|boolean',
        ]));
        return response()->json(['success' => true, 'data' => $cat]);
    }

    public function deleteCategory(int $id)
    {
        Category::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    // ── Services ──────────────────────────────────────────────
    public function services(Request $request)
    {
        $q = Service::query();
        return response()->json(['success' => true, 'data' => $this->paginate(
            $q, $request,
            ['service_name', 'business_name'],
            [
                'is_active'     => ['column' => 'is_active', 'cast' => 'bool'],
                'gender_type'   => ['column' => 'gender_type'],
                'business_id'   => ['column' => 'business_id'],
                'category_name' => ['column' => 'category_name'],
            ],
        )]);
    }

    public function updateService(Request $request, int $id)
    {
        $service = Service::findOrFail($id);
        $service->update($request->validate([
            'service_name'     => 'sometimes|string|max:200',
            'gender_type'      => 'sometimes|in:مرد,زن,هر دو',
            'duration_minutes' => 'sometimes|integer|min:5',
            'price'            => 'sometimes|numeric|min:0',
            'is_active'        => 'sometimes|boolean',
        ]));
        return response()->json(['success' => true, 'data' => $service]);
    }

    public function deleteService(int $id)
    {
        Service::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    // ── Appointments ──────────────────────────────────────────
    public function appointments(Request $request)
    {
        $q = Appointment::query();
        return response()->json(['success' => true, 'data' => $this->paginate(
            $q, $request,
            ['user_name', 'business_name', 'user_phone', 'service_name'],
            [
                'status'      => ['column' => 'status'],
                'business_id' => ['column' => 'business_id'],
                'user_id'     => ['column' => 'user_id'],
                'date_shamsi' => ['column' => 'date_shamsi'],
            ],
        )]);
    }

    public function updateAppointment(Request $request, int $id)
    {
        $appt = Appointment::findOrFail($id);
        $data = $request->validate([
            'status' => 'required|in:در انتظار,تایید شده,لغو شده,انجام شده',
        ]);
        $oldStatus = $appt->status;
        try {
            $appt->update($data);
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => $this->cleanDbError($e, 'تغییر وضعیت نوبت امکان‌پذیر نیست'),
                'code'    => 422,
            ], 422);
        }

        // Notify the user when admin changes appointment status
        if ($oldStatus !== $data['status']) {
            $this->writeAppointmentStatusNotification($appt, $data['status']);
        }

        return response()->json(['success' => true, 'data' => $appt]);
    }

    /**
     * Push an instant in-app notification to the appointment's user describing
     * the new status. Inbox is updated immediately so the user's bell picks
     * it up on the next 20s poll (or window focus).
     */
    private function writeAppointmentStatusNotification(Appointment $appt, string $newStatus): void
    {
        $map = [
            'تایید شده' => ['type' => 'رزرو_موفق', 'title' => 'نوبت شما تایید شد'],
            'لغو شده'   => ['type' => 'لغو_نوبت',  'title' => 'نوبت شما لغو شد'],
            'انجام شده' => ['type' => 'رزرو_موفق', 'title' => 'نوبت شما انجام شد'],
            'در انتظار' => ['type' => 'رزرو_موفق', 'title' => 'وضعیت نوبت شما به انتظار بازگشت'],
        ];

        if (!isset($map[$newStatus])) return;

        $this->notifications->push([
            'user_id'             => $appt->user_id,
            'type'                => $map[$newStatus]['type'],
            'title'               => $map[$newStatus]['title'],
            'body'                => sprintf(
                '%s — %s ساعت %s در %s',
                $map[$newStatus]['title'],
                $appt->date_shamsi,
                $appt->time_slot,
                $appt->business_name,
            ),
            'related_entity_type' => 'appointments',
            'related_entity_id'   => $appt->id,
        ]);
    }

    public function deleteAppointment(int $id)
    {
        Appointment::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    // ── Queue ─────────────────────────────────────────────────
    public function queue(Request $request)
    {
        $q = QueueEntry::query();
        return response()->json(['success' => true, 'data' => $this->paginate(
            $q, $request,
            ['user_name', 'business_name', 'service_name', 'user_phone'],
            [
                'status'      => ['column' => 'status'],
                'business_id' => ['column' => 'business_id'],
                'date_shamsi' => ['column' => 'date_shamsi'],
            ],
        )]);
    }

    public function deleteQueue(int $id)
    {
        QueueEntry::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    // ── Reviews ───────────────────────────────────────────────
    public function reviews(Request $request)
    {
        $q = Review::query();
        return response()->json(['success' => true, 'data' => $this->paginate(
            $q, $request,
            ['user_name', 'business_name', 'comment', 'service_name'],
            [
                'is_visible' => ['column' => 'is_visible', 'cast' => 'bool'],
                'rating'     => ['column' => 'rating'],
                'business_id'=> ['column' => 'business_id'],
                'user_id'    => ['column' => 'user_id'],
            ],
        )]);
    }

    public function updateReview(Request $request, int $id)
    {
        $review = Review::findOrFail($id);
        $data = $request->validate(['status' => 'required|in:تایید شده,رد شده']);
        $review->update(['is_visible' => $data['status'] === 'تایید شده']);
        return response()->json(['success' => true, 'data' => $review]);
    }

    public function deleteReview(int $id)
    {
        Review::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    // ── Business Verifications ────────────────────────────────
    public function verifications(Request $request)
    {
        // Eager-load the business so the admin table can display the verified
        // owner's province and city without an N+1 round-trip per row.
        $q = BusinessVerification::with(['business:id,name,province_code,province_name,city']);

        // Province / city filters live on the related `businesses` table, so
        // we have to push them down with whereHas() — the generic paginator's
        // filter engine only knows about the local table.
        if ($request->filled('province_code')) {
            $code = $request->query('province_code');
            $q->whereHas('business', fn ($b) => $b->where('province_code', $code));
        }
        if ($request->filled('city')) {
            $city = $request->query('city');
            $q->whereHas('business', fn ($b) => $b->where('city', $city));
        }

        $page = $this->paginate(
            $q, $request,
            ['business_name', 'owner_phone'],
            [
                'status'        => ['column' => 'status'],
                'business_id'   => ['column' => 'business_id'],
                'phone_verified'=> ['column' => 'phone_verified', 'cast' => 'bool'],
            ],
        );

        // Flatten the business relation into top-level fields the frontend
        // table can render directly. Drop the nested object to keep the
        // payload small.
        $page->getCollection()->transform(function ($v) {
            $v->province_code = $v->business?->province_code ?? '';
            $v->province_name = $v->business?->province_name ?? '—';
            $v->city          = $v->business?->city ?? '—';
            $v->unsetRelation('business');
            return $v;
        });

        return response()->json(['success' => true, 'data' => $page]);
    }

    public function verifyBusiness(Request $request, int $id, BusinessVerificationService $svc)
    {
        $data = $request->validate([
            'status'     => 'required|in:تایید شده,رد شده',
            'admin_note' => 'nullable|string|max:500',
        ]);
        $result = $svc->verify($id, $request->user()->id, $data['status'], $data['admin_note'] ?? null);
        $ok = $result['success'];
        return response()->json(['success' => $ok, 'message' => $result['message']], $ok ? 200 : 422);
    }

    // ── Business Slots ────────────────────────────────────────
    public function slots(Request $request)
    {
        $q = BusinessSlot::query();
        return response()->json(['success' => true, 'data' => $this->paginate(
            $q, $request,
            ['business_name'],
            [
                'is_active'   => ['column' => 'is_active', 'cast' => 'bool'],
                'business_id' => ['column' => 'business_id'],
                'day_of_week' => ['column' => 'day_of_week'],
            ],
        )]);
    }

    public function deleteSlot(int $id)
    {
        BusinessSlot::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    // ── Notifications ─────────────────────────────────────────
    public function notifications(Request $request)
    {
        $q = AppNotification::query();
        return response()->json(['success' => true, 'data' => $this->paginate(
            $q, $request,
            ['title', 'body', 'user_phone', 'type'],
            [
                'type'    => ['column' => 'type'],
                'is_read' => ['column' => 'is_read', 'cast' => 'bool'],
                'user_id' => ['column' => 'user_id'],
            ],
        )]);
    }

    // ── Outbox ────────────────────────────────────────────────
    public function outbox(Request $request)
    {
        $q = NotificationOutbox::query();
        return response()->json(['success' => true, 'data' => $this->paginate(
            $q, $request,
            ['user_phone', 'title', 'body', 'type'],
            [
                'status'  => ['column' => 'status'],
                'type'    => ['column' => 'type'],
                'user_id' => ['column' => 'user_id'],
            ],
        )]);
    }

    public function retryOutbox(int $id)
    {
        $item = NotificationOutbox::findOrFail($id);
        $item->update(['status' => 'pending', 'attempt_count' => 0, 'next_retry_at' => null, 'processed_at' => null]);
        return response()->json(['success' => true]);
    }

    /**
     * POST /api/admin/outbox/retry-all — bulk re-arm every `failed` row so
     * the next outbox worker tick re-attempts delivery. Typical use:
     * sms.ir has just come back online after an outage and the admin
     * wants every queued message to ship.
     */
    public function retryAllOutbox()
    {
        $count = NotificationOutbox::where('status', 'failed')
            ->update([
                'status'        => 'pending',
                'attempt_count' => 0,
                'next_retry_at' => null,
                'processed_at'  => null,
            ]);
        return response()->json([
            'success' => true,
            'data'    => ['requeued' => $count],
            'message' => "{$count} پیامک شکست‌خورده برای تلاش مجدد فعال شد.",
        ]);
    }

    // ── Audit Logs ────────────────────────────────────────────
    public function auditLogs(Request $request)
    {
        $q = AuditLog::query();
        return response()->json(['success' => true, 'data' => $this->paginate(
            $q, $request,
            ['entity_type', 'action', 'description'],
            [
                'entity_type'  => ['column' => 'entity_type'],
                'action'       => ['column' => 'action'],
                'performed_by' => ['column' => 'performed_by'],
            ],
        )]);
    }
}
