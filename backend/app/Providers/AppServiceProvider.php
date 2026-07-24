<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Providers;

use App\Services\Sms\SmsIrClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // SMS client — per-resolve so a settings change in admin UI takes
        // effect on the next request without restarting PHP.
        $this->app->bind(SmsIrClient::class, fn () => SmsIrClient::fromSettings());
    }

    public function boot(): void
    {
        // Named rate limiters, each with its OWN per-IP bucket (a bare
        // `throttle:30,1` shares a single bucket across every route on the
        // domain, so captcha loads + login POSTs drain the same counter and
        // the captcha starts returning 429). Split them:
        //   captcha — cheap GET, refreshed often, so a generous ceiling.
        //   auth    — sensitive login/register/OTP POSTs, tighter.
        RateLimiter::for('captcha', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));
        RateLimiter::for('auth',    fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
    }
}
