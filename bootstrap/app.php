<?php

use App\Http\Middleware\CheckSession;
use App\Http\Middleware\EnsureTenantFeatureEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectTrailingSlash;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\RequireManageSettings;
use App\Http\Middleware\RequireSuperAdmin;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\TriggerOltDailySyncOnAccess;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'resolve.tenant' => ResolveTenant::class,
            'auth' => CheckSession::class,
            'guest' => RedirectIfAuthenticated::class,
            'manage.settings' => RequireManageSettings::class,
            'superadmin' => RequireSuperAdmin::class,
            'tenant.feature' => EnsureTenantFeatureEnabled::class,
            'olt.daily.sync' => TriggerOltDailySyncOnAccess::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        $middleware->web(prepend: [
            // Keep legacy URLs working (e.g. /chat/ and /direct/).
            RedirectTrailingSlash::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // API routes need session access (legacy auth + tenant context).
        // Without this, /api/v1/* requests won't see the logged-in session and will redirect to /login (HTML),
        // causing the SPA fetch JSON parsing to fail.
        $middleware->api(prepend: [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
        ]);

        // Legacy chat scripts post FormData without CSRF tokens (native behavior).
        $middleware->validateCsrfTokens(except: [
            'chat/admin_api.php',
            'direct/api.php',
            'log.php',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
