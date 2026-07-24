<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PublicStatsController extends Controller
{
    /** GET /api/stats/public — homepage counters.
     *
     * Stats are tolerated to be a minute stale, so we cache the result for
     * 60 s and serve every visitor from memory. This converts a 3-COUNT
     * round-trip (~30–80 ms) into a single APCu/file hit (<1 ms).
     */
    public function index(): JsonResponse
    {
        $data = Cache::remember('public_stats', 60, function () {
            // One round-trip with conditional aggregation — three COUNTs in
            // a single query plan instead of three separate ones.
            $row = DB::selectOne("
                SELECT
                    (SELECT COUNT(*) FROM businesses
                       WHERE is_verified = 1 AND is_active = 1) AS businesses_count,
                    (SELECT COUNT(*) FROM services
                       WHERE is_active = 1) AS services_count,
                    (SELECT COUNT(*) FROM appointments
                       WHERE created_at >= ?) AS this_week_appointments
            ", [now()->subDays(7)->toDateTimeString()]);

            return [
                'businesses_count'       => (int) $row->businesses_count,
                'services_count'         => (int) $row->services_count,
                'this_week_appointments' => (int) $row->this_week_appointments,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => 'آمار عمومی سایت.',
            'code'    => 200,
        ]);
    }
}
