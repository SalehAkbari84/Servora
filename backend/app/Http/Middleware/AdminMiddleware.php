<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user || $user->role !== 'ادمین' || ! $user->is_active) {
            return response()->json(['success' => false, 'message' => 'دسترسی غیرمجاز'], 403);
        }
        return $next($request);
    }
}
