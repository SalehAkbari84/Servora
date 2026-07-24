<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate an admin route to a single section permission. Pass the section key
 * as a parameter:  ->middleware('admin_perm:settings')
 *
 * Rules:
 *   - Caller must be an authenticated active admin (otherwise 403)
 *   - Primary admin always passes
 *   - For non-primary, the section must be in their `permissions` JSON
 *   - `settings` and `admins` are NEVER grantable — primary-only
 *
 * The `admin` middleware (role check) runs FIRST in the stack, so by the
 * time we get here `$request->user()->role === 'ادمین'` is guaranteed.
 */
class AdminPermission
{
    public function handle(Request $request, Closure $next, string $section): Response
    {
        $user = $request->user();
        if (!$user || !$user->canAccessSection($section)) {
            return response()->json([
                'success' => false,
                'message' => $user?->is_primary_admin
                    ? 'دسترسی غیرمجاز'
                    : "شما به بخش «{$section}» دسترسی ندارید. از مدیر اصلی بخواهید سطح دسترسی شما را بروزرسانی کند.",
            ], 403);
        }
        return $next($request);
    }
}
