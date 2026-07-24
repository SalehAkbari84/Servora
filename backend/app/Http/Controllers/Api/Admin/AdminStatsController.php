<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Review;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminStatsController extends Controller
{
    public function index(): JsonResponse
    {
        $today          = Carbon::today();
        $yesterday      = Carbon::yesterday();
        $sevenDaysAgo   = Carbon::today()->subDays(7);
        $fourteenDays   = Carbon::today()->subDays(14);

        // ── Counters + deltas in ONE round-trip ─────────────────
        // 11 separate count() calls collapsed into a single SELECT with
        // subqueries. On localhost MySQL this drops ~6-8ms from the
        // dashboard request.
        $row = DB::selectOne("
            SELECT
                (SELECT COUNT(*) FROM users)                                                AS users,
                (SELECT COUNT(*) FROM businesses)                                           AS businesses,
                (SELECT COUNT(*) FROM appointments)                                         AS appointments,
                (SELECT COUNT(*) FROM queue WHERE status = 'در انتظار')                     AS queue,
                (SELECT COUNT(*) FROM reviews)                                              AS reviews,
                (SELECT COUNT(*) FROM business_verification WHERE status = 'در انتظار')     AS pending_verifs,
                (SELECT COUNT(*) FROM appointments WHERE DATE(created_at) = ?)              AS appt_today,
                (SELECT COUNT(*) FROM appointments WHERE DATE(created_at) = ?)              AS appt_yesterday,
                (SELECT COUNT(*) FROM users WHERE DATE(created_at) = ?)                     AS new_users_today,
                (SELECT COUNT(*) FROM users WHERE created_at >= ?)                          AS new_users_week,
                (SELECT COALESCE(SUM(price), 0) FROM appointments
                    WHERE created_at >= ?
                      AND status IN ('تایید شده', 'انجام شده'))                             AS revenue_week
        ", [
            $today->toDateString(), $yesterday->toDateString(),
            $today->toDateString(), $sevenDaysAgo, $sevenDaysAgo,
        ]);

        $users            = (int) $row->users;
        $businesses       = (int) $row->businesses;
        $appointments     = (int) $row->appointments;
        $queue            = (int) $row->queue;
        $reviews          = (int) $row->reviews;
        $pendingVerifs    = (int) $row->pending_verifs;
        $apptToday        = (int) $row->appt_today;
        $apptYesterday    = (int) $row->appt_yesterday;
        $newUsersToday    = (int) $row->new_users_today;
        $newUsersWeek     = (int) $row->new_users_week;
        $revenueWeek      = (int) $row->revenue_week;

        // ── Time series: last 14 days of appointments ───────────
        $apptByDay = Appointment::where('created_at', '>=', $fourteenDays)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as count')
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('count', 'day')
            ->toArray();

        $series14d = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = Carbon::today()->subDays($i);
            $key = $d->format('Y-m-d');
            $series14d[] = [
                'date'  => $key,
                'count' => (int)($apptByDay[$key] ?? 0),
            ];
        }

        // ── Appointment status breakdown ────────────────────────
        $statusBreakdown = Appointment::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // ── Top categories by business count ────────────────────
        $topCategories = Business::selectRaw('category_name, COUNT(*) as count')
            ->where('category_name', '!=', '')
            ->groupBy('category_name')
            ->orderByDesc('count')
            ->limit(6)
            ->get(['category_name', 'count'])
            ->map(fn($r) => ['name' => $r->category_name, 'count' => (int)$r->count]);

        // ── Recent activity (last 8 audit logs) ─────────────────
        $recentActivity = AuditLog::orderByDesc('created_at')
            ->limit(8)
            ->get(['action', 'description', 'entity_type', 'entity_id', 'created_at'])
            ->map(fn($r) => [
                'action'      => $r->action,
                'description' => $r->description,
                'entity_type' => $r->entity_type,
                'entity_id'   => $r->entity_id,
                'created_at'  => $r->created_at?->toISOString(),
            ]);

        // ── Rating distribution (1-5 stars) ─────────────────────
        $ratingDist = Review::where('is_visible', true)
            ->selectRaw('rating, COUNT(*) as count')
            ->groupBy('rating')
            ->pluck('count', 'rating')
            ->toArray();
        $ratingBuckets = [];
        for ($i = 5; $i >= 1; $i--) {
            $ratingBuckets[] = ['rating' => $i, 'count' => (int)($ratingDist[$i] ?? 0)];
        }

        return response()->json([
            'success' => true,
            'data' => [
                // counters
                'users'                 => $users,
                'businesses'            => $businesses,
                'appointments'          => $appointments,
                'queue'                 => $queue,
                'reviews'               => $reviews,
                'pending_verifications' => $pendingVerifs,

                // deltas
                'appointments_today'      => $apptToday,
                'appointments_yesterday'  => $apptYesterday,
                'new_users_today'         => $newUsersToday,
                'new_users_week'          => $newUsersWeek,
                'revenue_week'            => (int)$revenueWeek,

                // series
                'series_14d'        => $series14d,
                'status_breakdown'  => $statusBreakdown,
                'top_categories'    => $topCategories,
                'rating_buckets'    => $ratingBuckets,
                'recent_activity'   => $recentActivity,
            ],
        ]);
    }
}
