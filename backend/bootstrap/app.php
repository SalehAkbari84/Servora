<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Maintenance gate runs on every API request and short-circuits with 503
        // when the `maintenance_mode` setting is on (admin routes are bypassed
        // so the panel can toggle it back off).
        $middleware->api(prepend: [\App\Http\Middleware\MaintenanceMode::class]);

        // Compression runs last (append) so it sees the fully-built response
        // and can pick the strongest encoding the client accepts.
        $middleware->api(append: [\App\Http\Middleware\CompressResponse::class]);

        $middleware->alias([
            'admin'          => \App\Http\Middleware\AdminMiddleware::class,
            'admin_perm'     => \App\Http\Middleware\AdminPermission::class,
            'business_owner' => \App\Http\Middleware\IsBusinessOwner::class,
            'public_cache'   => \App\Http\Middleware\PublicCacheHeaders::class,
            'touch_last_seen'=> \App\Http\Middleware\TouchLastSeen::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
