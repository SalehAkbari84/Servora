<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessController extends Controller
{
    /**
     * List verified and active businesses.
     * Supports filtering: category_name, gender_type, search (name/description).
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 15);
        $perPage = min(max($perPage, 5), 50);

        $query = Business::where('is_verified', true)
            ->where('is_active', true)
            ->orderByDesc('rating_avg');

        if ($request->filled('category_name')) {
            $query->where('category_name', $request->query('category_name'));
        }

        if ($request->filled('subcategory_name')) {
            $query->where('subcategory_name', $request->query('subcategory_name'));
        }

        if ($request->filled('gender_type')) {
            $query->where('gender_type', $request->query('gender_type'));
        }

        if ($request->filled('province_code')) {
            $query->where('province_code', $request->query('province_code'));
        }

        if ($request->filled('city')) {
            $query->where('city', $request->query('city'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('description', 'LIKE', "%{$search}%")
                  ->orWhere('address_text', 'LIKE', "%{$search}%");
            });
        }

        $businesses = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $businesses,
            'message' => 'لیست کسب‌وکارها.',
            'code'    => 200,
        ]);
    }

    /**
     * Show details for a single business.
     */
    public function show(int $id): JsonResponse
    {
        $business = Business::where('id', $id)
            ->where('is_verified', true)
            ->where('is_active', true)
            ->first();

        if (!$business) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'کسب‌وکار یافت نشد.',
                'code'    => 404,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $business,
            'message' => 'اطلاعات کسب‌وکار.',
            'code'    => 200,
        ]);
    }

    /**
     * List active services for a specific business.
     */
    public function services(int $id): JsonResponse
    {
        $business = Business::where('id', $id)
            ->where('is_verified', true)
            ->where('is_active', true)
            ->first();

        if (!$business) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'کسب‌وکار یافت نشد.',
                'code'    => 404,
            ], 404);
        }

        $services = $business->services()
            ->where('is_active', true)
            ->orderBy('service_name')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $services,
            'message' => 'خدمات کسب‌وکار.',
            'code'    => 200,
        ]);
    }

    /**
     * List active slots for a specific business, grouped by day_of_week.
     */
    public function slots(int $id): JsonResponse
    {
        $business = Business::where('id', $id)
            ->where('is_verified', true)
            ->where('is_active', true)
            ->first();

        if (!$business) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'کسب‌وکار یافت نشد.',
                'code'    => 404,
            ], 404);
        }

        $slots = $business->slots()
            ->where('is_active', true)
            ->orderBy('day_of_week')
            ->orderBy('time_slot')
            ->get()
            ->append('day_name'); // trigger getDayNameAttribute

        // Group by day_of_week for convenience
        $grouped = $slots->groupBy('day_of_week')->map(function ($daySlots) {
            return [
                'day_name' => $daySlots->first()->day_name,
                'slots'    => $daySlots->values(),
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $grouped,
            'message' => 'اسلات‌های کسب‌وکار.',
            'code'    => 200,
        ]);
    }

    /**
     * List visible reviews for a specific business.
     */
    public function reviews(Request $request, int $id): JsonResponse
    {
        $business = Business::where('id', $id)
            ->where('is_verified', true)
            ->where('is_active', true)
            ->first();

        if (!$business) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'کسب‌وکار یافت نشد.',
                'code'    => 404,
            ], 404);
        }

        $perPage = (int) $request->query('per_page', 10);
        $perPage = min(max($perPage, 5), 50);

        $reviews = $business->reviews()
            ->where('is_visible', true)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $reviews,
            'message' => 'نظرات کسب‌وکار.',
            'code'    => 200,
        ]);
    }
}
