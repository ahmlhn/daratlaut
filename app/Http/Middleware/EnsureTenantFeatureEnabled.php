<?php

namespace App\Http\Middleware;

use App\Support\SuperAdminAccess;
use App\Support\TenantFeatureCatalog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantFeatureEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?: Auth::user();
        if ($user && SuperAdminAccess::hasAccess($user)) {
            return $next($request);
        }

        $featureKey = TenantFeatureCatalog::featureForPath($request->path());
        if (!$featureKey) {
            return $next($request);
        }

        $tenantId = (int) ($request->attributes->get('tenant_id') ?: ($user->tenant_id ?? 0));
        if ($tenantId <= 0) {
            return $next($request);
        }

        if (TenantFeatureCatalog::isEnabled($tenantId, $featureKey)) {
            return $next($request);
        }

        $message = 'Fitur ini dinonaktifkan oleh superadmin untuk tenant Anda.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'feature' => $featureKey,
            ], 403);
        }

        return new Response($message, 403);
    }
}
