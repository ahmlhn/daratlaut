<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        $tenantId = null;
        $user = $request->user();

        if ($user && isset($user->tenant_id)) {
            $tenantId = (int) $user->tenant_id;
        }

        if (!$tenantId) {
            $headerTenant = (int) $request->header('X-Tenant-Id');
            if ($headerTenant > 0) {
                $tenantId = $headerTenant;
            }
        }

        if (!$tenantId) {
            $queryTenant = (int) $request->query('tenant_id');
            if ($queryTenant > 0) {
                $tenantId = $queryTenant;
            }
        }

        // Local fallback to simplify early development before auth is wired.
        if (!$tenantId && app()->environment('local')) {
            $fallbackTenant = (int) env('APP_FALLBACK_TENANT_ID', 1);
            if ($fallbackTenant > 0) {
                $tenantId = $fallbackTenant;
            }
        }

        if (!$tenantId) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Tenant context missing'], 403);
            }
            return new Response('Tenant context missing', 403);
        }

        // Store tenant_id in request attributes for controllers/services.
        $request->attributes->set('tenant_id', $tenantId);

        return $next($request);
    }
}
