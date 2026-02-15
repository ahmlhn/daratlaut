<?php

namespace App\Http\Middleware;

use App\Support\SuperAdminAccess;
use App\Support\TenantFeatureCatalog;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        // Make sure SPA clients auto-refresh when Vite assets change (e.g. after `npm run build`),
        // so they don't keep stale chunk URLs in memory/cache.
        $manifest = public_path('build/manifest.json');
        if (is_file($manifest)) {
            return (string) filemtime($manifest);
        }

        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $tenantFeatures = [];

        if ($user) {
            $tenantId = (int) ($user->tenant_id ?? 0);
            $tenantFeatures = $tenantId > 0
                ? TenantFeatureCatalog::stateForTenant($tenantId)
                : TenantFeatureCatalog::defaultState();
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username ?? null,
                    'role' => $user->role ?? null,
                    'roles' => method_exists($user, 'getRoleNames') ? $user->getRoleNames() : [],
                    'permissions' => method_exists($user, 'getAllPermissions') ? $user->getAllPermissions()->pluck('name')->values() : [],
                    'is_superadmin' => SuperAdminAccess::hasAccess($user),
                ] : null,
            ],
            'tenantFeatures' => $tenantFeatures,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
