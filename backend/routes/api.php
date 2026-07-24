<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\OwnerController;
use App\Http\Controllers\Api\PublicStatsController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Api\QueueController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\UserNotificationController;
use App\Http\Controllers\Api\Admin\AdminAuthController;
use App\Http\Controllers\Api\Admin\AdminCrudController;
use App\Http\Controllers\Api\Admin\AdminSettingsController;
use App\Http\Controllers\Api\Admin\AdminStatsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All API routes return JSON responses with the shape:
|   { success, data, message, code }
|
*/

// ── Admin Auth ────────────────────────────────────────────────
Route::post('/admin/login',         [AdminAuthController::class, 'login']);
Route::post('/admin/login/verify',  [AdminAuthController::class, 'verify']);

// ── Admin Protected ───────────────────────────────────────────
Route::prefix('admin')->middleware(['auth:sanctum', 'admin', 'touch_last_seen'])->group(function () {
    // Auth basics — every authenticated admin can call these
    Route::post('/logout',  [AdminAuthController::class, 'logout']);
    Route::get('/me',       [AdminAuthController::class, 'me']);
    Route::get('/stats',    [AdminStatsController::class, 'index']);

    // Users — managed under `users` permission
    Route::middleware('admin_perm:users')->group(function () {
        Route::get('/users',          [AdminCrudController::class, 'users']);
        Route::post('/users',         [AdminCrudController::class, 'createUser']);
        Route::patch('/users/{id}',   [AdminCrudController::class, 'updateUser']);
        Route::delete('/users/{id}',  [AdminCrudController::class, 'deleteUser']);
    });

    Route::middleware('admin_perm:businesses')->group(function () {
        Route::get('/businesses',         [AdminCrudController::class, 'businesses']);
        Route::patch('/businesses/{id}',  [AdminCrudController::class, 'updateBusiness']);
        Route::delete('/businesses/{id}', [AdminCrudController::class, 'deleteBusiness']);
    });

    Route::middleware('admin_perm:categories')->group(function () {
        Route::get('/categories',         [AdminCrudController::class, 'categories']);
        Route::post('/categories',        [AdminCrudController::class, 'storeCategory']);
        Route::patch('/categories/{id}',  [AdminCrudController::class, 'updateCategory']);
        Route::delete('/categories/{id}', [AdminCrudController::class, 'deleteCategory']);
    });

    Route::middleware('admin_perm:services')->group(function () {
        Route::get('/services',         [AdminCrudController::class, 'services']);
        Route::patch('/services/{id}',  [AdminCrudController::class, 'updateService']);
        Route::delete('/services/{id}', [AdminCrudController::class, 'deleteService']);
    });

    Route::middleware('admin_perm:appointments')->group(function () {
        Route::get('/appointments',         [AdminCrudController::class, 'appointments']);
        Route::patch('/appointments/{id}',  [AdminCrudController::class, 'updateAppointment']);
        Route::delete('/appointments/{id}', [AdminCrudController::class, 'deleteAppointment']);
    });

    Route::middleware('admin_perm:queue')->group(function () {
        Route::get('/queue',         [AdminCrudController::class, 'queue']);
        Route::delete('/queue/{id}', [AdminCrudController::class, 'deleteQueue']);
    });

    Route::middleware('admin_perm:reviews')->group(function () {
        Route::get('/reviews',         [AdminCrudController::class, 'reviews']);
        Route::patch('/reviews/{id}',  [AdminCrudController::class, 'updateReview']);
        Route::delete('/reviews/{id}', [AdminCrudController::class, 'deleteReview']);
    });

    Route::middleware('admin_perm:verifications')->group(function () {
        Route::get('/verifications',              [AdminCrudController::class, 'verifications']);
        Route::post('/verifications/{id}/verify', [AdminCrudController::class, 'verifyBusiness']);
    });

    Route::middleware('admin_perm:slots')->group(function () {
        Route::get('/slots',         [AdminCrudController::class, 'slots']);
        Route::delete('/slots/{id}', [AdminCrudController::class, 'deleteSlot']);
    });

    Route::middleware('admin_perm:notifications')->group(function () {
        Route::get('/notifications', [AdminCrudController::class, 'notifications']);
    });

    Route::middleware('admin_perm:outbox')->group(function () {
        Route::get('/outbox',                 [AdminCrudController::class, 'outbox']);
        Route::post('/outbox/{id}/retry',     [AdminCrudController::class, 'retryOutbox']);
        Route::post('/outbox/retry-all',      [AdminCrudController::class, 'retryAllOutbox']);
    });

    Route::middleware('admin_perm:audit')->group(function () {
        Route::get('/audit', [AdminCrudController::class, 'auditLogs']);
    });

    // ── Primary-admin only — settings + admin account management ──────
    // The `admins` section is hard-coded primary-only inside the middleware
    // (User::canAccessSection returns false even if the key is added to
    // permissions JSON). Same for `settings`.
    Route::middleware('admin_perm:admins')->group(function () {
        Route::get('/admins',          [AdminCrudController::class, 'admins']);
        Route::post('/admins',         [AdminCrudController::class, 'createAdmin']);
        Route::patch('/admins/{id}',   [AdminCrudController::class, 'updateAdmin']);
        Route::delete('/admins/{id}',  [AdminCrudController::class, 'deleteAdmin']);
    });

    Route::middleware('admin_perm:settings')->group(function () {
        Route::get('/settings',         [AdminSettingsController::class, 'index']);
        Route::put('/settings',         [AdminSettingsController::class, 'update']);
        Route::post('/settings/upload', [AdminSettingsController::class, 'upload']);
        Route::get('/sms/info',         [AdminSettingsController::class, 'smsInfo']);
        Route::post('/sms/test',        [AdminSettingsController::class, 'smsTest']);
    });
});

// ── Public routes ─────────────────────────────────────────────
// Per-IP rate limits as defence-in-depth. Separate buckets (see
// AppServiceProvider) so frequent captcha loads never exhaust the login quota.
// OtpService still enforces its own per-phone cooldown on top.
Route::middleware('throttle:captcha')->group(function () {
    Route::get('/captcha', [AuthController::class, 'captcha']);
});
Route::middleware('throttle:auth')->group(function () {
    Route::post('/auth/register',   [AuthController::class, 'register']);
    Route::post('/auth/login',      [AuthController::class, 'login']);
    Route::post('/auth/otp/request',[AuthController::class, 'requestOtp']);
    Route::post('/auth/otp/verify', [AuthController::class, 'verifyOtp']);
    Route::post('/auth/login/otp',  [AuthController::class, 'loginWithOtp']);
});

// ── Cached public endpoints ───────────────────────────────────
// Anonymous, read-only responses that are identical for every visitor —
// perfect fit for the `public_cache` middleware (ETag + Cache-Control).
//
// IMPORTANT: `/settings/public` is intentionally OUTSIDE this group. It
// carries the `maintenance_mode` flag and HTTP caching would prevent the
// admin's toggle from propagating to visitors in real-time. We send it
// with `Cache-Control: no-store` instead so every poll hits the live DB.
Route::middleware('public_cache')->group(function () {
    Route::get('/categories', function () {
        $cats = \App\Models\Category::where('is_active', true)->orderBy('name')->paginate(50);
        return response()->json(['success' => true, 'data' => $cats]);
    });

    Route::get('/stats/public',    [PublicStatsController::class, 'index']);
    Route::get('/push/vapid-key',  [PushSubscriptionController::class, 'vapidKey']);

    Route::get('/businesses',               [BusinessController::class, 'index']);
    Route::get('/businesses/{id}',          [BusinessController::class, 'show']);
    Route::get('/businesses/{id}/services', [BusinessController::class, 'services']);
    Route::get('/businesses/{id}/slots',    [BusinessController::class, 'slots']);
    Route::get('/businesses/{id}/reviews',  [BusinessController::class, 'reviews']);
});

// /settings/public — never cache. Maintenance flag must propagate fast.
Route::get('/settings/public', [AdminSettingsController::class, 'publicIndex']);

// -------------------------------------------------------
// Authenticated routes (Sanctum token required)
// -------------------------------------------------------

Route::middleware(['auth:sanctum', 'touch_last_seen'])->group(function () {

    // Auth
    Route::post('/auth/logout',  [AuthController::class, 'logout']);
    Route::get('/auth/me',       [AuthController::class, 'me']);
    Route::put('/auth/profile',  [AuthController::class, 'updateProfile']);
    Route::post('/profile/avatar',   [AuthController::class, 'uploadAvatar']);
    Route::delete('/profile/avatar', [AuthController::class, 'deleteAvatar']);

    // Reviews (user submission)
    Route::post('/reviews', [ReviewController::class, 'store']);

    // User Notifications
    Route::get('/notifications',              [UserNotificationController::class, 'index']);
    Route::patch('/notifications/{id}/read',  [UserNotificationController::class, 'markRead']);
    Route::post('/notifications/read-all',    [UserNotificationController::class, 'markAllRead']);
    Route::delete('/notifications/{id}',      [UserNotificationController::class, 'destroy']);
    Route::delete('/notifications',           [UserNotificationController::class, 'destroyAll']);

    // Web Push subscriptions
    Route::get('/push/status',          [PushSubscriptionController::class, 'status']);
    Route::post('/push/subscribe',      [PushSubscriptionController::class, 'subscribe']);
    Route::post('/push/unsubscribe',    [PushSubscriptionController::class, 'unsubscribe']);

    // Chat — customer side
    Route::get('/businesses/{id}/conversation',     [ChatController::class, 'openWithBusiness']);
    Route::get('/conversations/unread',             [ChatController::class, 'userUnreadCount']);
    Route::get('/conversations/{id}/messages',      [ChatController::class, 'listMessages']);
    Route::post('/conversations/{id}/messages',     [ChatController::class, 'sendMessage']);
    Route::patch('/conversations/{id}/read',        [ChatController::class, 'markAllRead']);

    // Appointments
    Route::get('/appointments',       [AppointmentController::class, 'index']);
    Route::post('/appointments',      [AppointmentController::class, 'store']);
    Route::delete('/appointments/{id}', [AppointmentController::class, 'destroy']);

    // Queue
    Route::get('/queue',         [QueueController::class, 'index']);
    Route::post('/queue',        [QueueController::class, 'store']);
    Route::delete('/queue/{id}', [QueueController::class, 'destroy']);

    // ── Business Owner ────────────────────────────────────────
    Route::prefix('owner')->middleware('business_owner')->group(function () {
        Route::get('/stats',                        [OwnerController::class, 'getStats']);
        Route::get('/categories',                   [OwnerController::class, 'getCategories']);

        Route::get('/business',                     [OwnerController::class, 'getBusiness']);
        Route::post('/business',                    [OwnerController::class, 'createBusiness']);
        Route::put('/business',                     [OwnerController::class, 'updateBusiness']);
        Route::post('/business/logo',               [OwnerController::class, 'uploadLogo']);
        Route::delete('/business/logo',             [OwnerController::class, 'deleteLogo']);

        Route::get('/services',                     [OwnerController::class, 'getServices']);
        Route::post('/services',                    [OwnerController::class, 'createService']);
        Route::put('/services/{id}',                [OwnerController::class, 'updateService']);
        Route::delete('/services/{id}',             [OwnerController::class, 'deleteService']);

        Route::get('/slots',                        [OwnerController::class, 'getSlots']);
        Route::post('/slots',                       [OwnerController::class, 'createSlots']);
        Route::delete('/slots/day',                 [OwnerController::class, 'deleteDaySlots']);
        Route::delete('/slots/{id}',                [OwnerController::class, 'deleteSlot']);

        Route::get('/verification',                 [OwnerController::class, 'getVerification']);
        Route::post('/verification',                [OwnerController::class, 'submitVerification']);

        Route::get('/appointments',                 [OwnerController::class, 'getAppointments']);
        Route::patch('/appointments/{id}',          [OwnerController::class, 'updateAppointmentStatus']);

        Route::get('/reviews',                      [OwnerController::class, 'getReviews']);
        Route::post('/reviews/{id}/reply',          [OwnerController::class, 'replyReview']);

        // Chat — owner side
        Route::get('/conversations',                [ChatController::class, 'listOwnerConversations']);
        Route::get('/conversations/unread',         [ChatController::class, 'ownerUnreadCount']);
    });
});
