<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QueueEntry;
use App\Services\QueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class QueueController extends Controller
{
    public function __construct(
        private readonly QueueService $queueService
    ) {}

    /**
     * List the authenticated user's queue entries (paginated).
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 15);
        $perPage = min(max($perPage, 5), 50);

        $query = QueueEntry::where('user_id', $request->user()->id)
            ->orderByDesc('created_at');

        // Optional status filter
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $entries = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $entries,
            'message' => 'لیست صف‌های شما.',
            'code'    => 200,
        ]);
    }

    /**
     * Add the authenticated user to a queue slot.
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

        $result = $this->queueService->add([
            'user_id'     => $request->user()->id,
            'business_id' => $validated['business_id'],
            'service_id'  => $validated['service_id'],
            'date_shamsi' => $validated['date_shamsi'],
            'time_slot'   => $validated['time_slot'],
        ]);

        $httpStatus = $result['success'] ? 201 : 422;

        return response()->json([
            'success' => $result['success'],
            'data'    => $result['success']
                ? ['queue_id' => $result['queue_id'], 'position' => $result['position']]
                : null,
            'message' => $result['message'],
            'code'    => $result['code'],
        ], $httpStatus);
    }

    /**
     * Remove the authenticated user from a queue (only while still waiting).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $entry = QueueEntry::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->where('status', 'در انتظار')
            ->first();

        if (!$entry) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'مورد یافت نشد یا قابل حذف نیست.',
                'code'    => 404,
            ], 404);
        }

        $entry->delete();

        return response()->json([
            'success' => true,
            'data'    => null,
            'message' => 'از لیست انتظار خارج شدید.',
            'code'    => 200,
        ]);
    }
}
