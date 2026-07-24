<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\Request;

class UserNotificationController extends Controller
{
    /** GET /notifications — list for the authenticated user.
     *
     *  Includes chat messages (`type = 'پیام_جدید'`) so the bell badge
     *  surfaces them too. Each notification carries a `url` field that
     *  the bell uses to deep-link (chat → /owner/messages?conv=… for
     *  owners, /businesses/{id} for customers; appointments → their own
     *  page, etc.). */
    public function index(Request $request)
    {
        $user = $request->user();
        $notifs = AppNotification::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->take(50)
            ->get();

        $unread = $notifs->where('is_read', false)->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'items'  => $notifs,
                'unread' => $unread,
            ],
        ]);
    }

    /** PATCH /notifications/{id}/read */
    public function markRead(Request $request, int $id)
    {
        $notif = AppNotification::where('user_id', $request->user()->id)
            ->findOrFail($id);
        $notif->update(['is_read' => true]);
        return response()->json(['success' => true]);
    }

    /** POST /notifications/read-all */
    public function markAllRead(Request $request)
    {
        AppNotification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);
        return response()->json(['success' => true]);
    }

    /** DELETE /notifications/{id} — delete a single notification */
    public function destroy(Request $request, int $id)
    {
        $notif = AppNotification::where('user_id', $request->user()->id)
            ->findOrFail($id);
        $notif->delete();
        return response()->json(['success' => true]);
    }

    /** DELETE /notifications — clear all notifications for the user */
    public function destroyAll(Request $request)
    {
        $deleted = AppNotification::where('user_id', $request->user()->id)->delete();
        return response()->json(['success' => true, 'data' => ['deleted' => $deleted]]);
    }
}
