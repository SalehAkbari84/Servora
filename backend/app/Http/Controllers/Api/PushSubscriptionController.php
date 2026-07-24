<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    /**
     * Public VAPID key — embedded in the SW subscribe call.
     * The private key never leaves the server.
     */
    public function vapidKey()
    {
        return response()->json([
            'success' => true,
            'data' => ['key' => config('webpush.vapid.public_key', env('VAPID_PUBLIC_KEY'))],
        ]);
    }

    /**
     * Register / refresh a Web Push subscription for the authenticated user.
     * The browser produces the same endpoint each time, so we upsert by endpoint_hash.
     */
    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint'       => 'required|string|max:2000',
            'keys.p256dh'    => 'required|string|max:200',
            'keys.auth'      => 'required|string|max:50',
        ]);

        $sub = PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $data['endpoint'])],
            [
                'user_id'    => $request->user()->id,
                'endpoint'   => $data['endpoint'],
                'p256dh'     => $data['keys']['p256dh'],
                'auth'       => $data['keys']['auth'],
                'user_agent' => substr((string) $request->header('User-Agent'), 0, 300),
            ],
        );

        return response()->json(['success' => true, 'data' => ['id' => $sub->id]]);
    }

    /**
     * Unsubscribe — called when user disables notifications in-app or the
     * browser invalidates the subscription.
     */
    public function unsubscribe(Request $request)
    {
        $data = $request->validate(['endpoint' => 'required|string|max:2000']);

        PushSubscription::where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Did the current user enable push on this browser? Used by the bell UI
     * to render the right CTA (enable / already-on).
     */
    public function status(Request $request)
    {
        $count = PushSubscription::where('user_id', $request->user()->id)->count();
        return response()->json(['success' => true, 'data' => ['enabled_devices' => $count]]);
    }
}
