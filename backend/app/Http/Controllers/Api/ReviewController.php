<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReviewController extends Controller
{
    /**
     * Submit a review for a completed appointment.
     */
    public function store(Request $request): JsonResponse
    {
        // Admin-configurable minimum comment length
        $minChars = (int) \App\Models\Setting::get('min_review_chars', '0');

        try {
            $data = $request->validate([
                'appointment_id' => ['required', 'integer', 'exists:appointments,id'],
                'rating'         => ['required', 'integer', 'min:1', 'max:5'],
                'comment'        => $minChars > 0
                    ? ['required', 'string', 'min:' . $minChars, 'max:1000']
                    : ['nullable', 'string', 'max:1000'],
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

        $user = $request->user();
        $appt = Appointment::find($data['appointment_id']);

        if (!$appt) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'نوبت یافت نشد.',
                'code'    => 404,
            ], 404);
        }

        if ($appt->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'این نوبت متعلق به شما نیست.',
                'code'    => 403,
            ], 403);
        }

        if ($appt->status !== 'انجام شده') {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'فقط برای نوبت‌های انجام شده می‌توان نظر ثبت کرد.',
                'code'    => 422,
            ], 422);
        }

        if (Review::where('appointment_id', $appt->id)->exists()) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'برای این نوبت قبلاً نظر ثبت شده است.',
                'code'    => 409,
            ], 409);
        }

        $review = Review::create([
            'business_id'    => $appt->business_id,
            'business_name'  => $appt->business_name,
            'appointment_id' => $appt->id,
            'service_id'     => $appt->service_id,
            'service_name'   => $appt->service_name,
            'date_shamsi'    => $appt->date_shamsi,
            'user_id'        => $user->id,
            'user_name'      => $user->full_name,
            'rating'         => $data['rating'],
            'comment'        => $data['comment'] ?? null,
            'is_visible'     => true,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $review,
            'message' => 'نظر شما با موفقیت ثبت شد.',
            'code'    => 201,
        ], 201);
    }
}
