<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Updates `users.last_seen_at` for the authenticated caller — at most once
 * per minute per user — so the chat notification flow can tell whether
 * the recipient is "at their browser" or needs an SMS fallback.
 *
 * Throttled to one write per minute via an in-process cache to avoid
 * hammering the users table on chatty endpoints (the dashboard easily
 * fires 5+ API calls per page load).
 *
 * Uses an UPDATE (not Eloquent save) so model events / observers don't
 * fire and the `updated_at` column isn't disturbed.
 */
class TouchLastSeen
{
    /** Throttle window: only update once per this many seconds per user. */
    private const THROTTLE_SECONDS = 60;

    /** Per-request in-memory cache of users we've already touched. */
    private static array $touched = [];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user) {
            $now = time();
            $last = self::$touched[$user->id] ?? 0;
            if ($now - $last >= self::THROTTLE_SECONDS) {
                self::$touched[$user->id] = $now;
                // Raw UPDATE — bypass Eloquent so we don't bump updated_at or
                // trigger any model observers. Fire-and-forget; failure here
                // (e.g. lock contention) must never break the user request.
                try {
                    User::where('id', $user->id)->update(['last_seen_at' => now()]);
                } catch (\Throwable) {
                    // ignored — best-effort heartbeat
                }
            }
        }
        return $next($request);
    }
}
