<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Soft maintenance mode driven by the `maintenance_mode` setting.
 *
 * When ON, all public traffic gets a 503 with the configured Persian message
 * — except:
 *   - admin routes (the admin panel keeps working so they can toggle off)
 *   - the auth/login/me endpoints (so admin can authenticate to toggle off)
 *   - the settings endpoints themselves
 *   - the captcha endpoint (admin login uses captcha)
 */
class MaintenanceMode
{
    /** Path prefixes that bypass maintenance — admin always works. */
    private const ALLOW_PREFIXES = [
        'api/admin/',
        'api/auth/login',
        'api/auth/me',
        'api/auth/logout',
        'api/captcha',
        'api/settings/public',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->isOn() || $this->isAllowed($request)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => Setting::get('maintenance_message', 'سایت در حال به‌روزرسانی است.'),
            'code'    => 503,
            'maintenance' => true,
        ], 503);
    }

    private function isOn(): bool
    {
        return Setting::get('maintenance_mode', '0') === '1';
    }

    private function isAllowed(Request $request): bool
    {
        $path = $request->path();
        foreach (self::ALLOW_PREFIXES as $p) {
            if (str_starts_with($path, $p)) return true;
        }
        return false;
    }
}
