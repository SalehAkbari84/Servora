<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsBusinessOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || $user->role !== 'کسب‌وکار') {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'دسترسی فقط برای صاحبان کسب‌وکار مجاز است.',
                'code'    => 403,
            ], 403);
        }

        return $next($request);
    }
}
