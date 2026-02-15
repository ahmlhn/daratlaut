<?php

namespace App\Http\Middleware;

use App\Services\OltDailySyncDispatcher;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TriggerOltDailySyncOnAccess
{
    public function __construct(private OltDailySyncDispatcher $dispatcher)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->dispatchIfAllowed($request);
        return $next($request);
    }

    private function dispatchIfAllowed(Request $request): void
    {
        $enabled = filter_var((string) env('OLT_DAILY_SYNC_ON_ACCESS', 'true'), FILTER_VALIDATE_BOOL);
        if (!$enabled) {
            return;
        }

        $user = $request->user();
        if (!$user) {
            return;
        }

        if (!$this->userCanAccessOlt($user)) {
            return;
        }

        $tenantId = (int) ($user->tenant_id ?? 1);

        try {
            $this->dispatcher->dispatchForTenantOncePerDay($tenantId);
        } catch (Throwable $e) {
            // Best effort only: avoid blocking page access.
        }
    }

    private function userCanAccessOlt(mixed $user): bool
    {
        // RBAC-first: follow configured permission settings.
        try {
            if (method_exists($user, 'can')) {
                $permissions = [
                    'view olts',
                    'manage olt',
                    'create olts',
                    'edit olts',
                    'delete olts',
                ];

                foreach ($permissions as $permission) {
                    if ($user->can($permission)) {
                        return true;
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore and continue to legacy fallback
        }

        // Legacy fallback for deployments that still rely on hardcoded role mapping.
        try {
            if (method_exists($user, 'canManageOlt')) {
                return (bool) $user->canManageOlt();
            }
        } catch (Throwable $e) {
            // ignore
        }

        return false;
    }
}
