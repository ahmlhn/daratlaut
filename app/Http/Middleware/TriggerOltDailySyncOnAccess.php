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

        $role = strtolower(trim((string) ($user->role ?? '')));
        if ($role === 'svp lapangan') {
            $role = 'svp_lapangan';
        }

        if (!in_array($role, ['teknisi', 'owner'], true)) {
            return;
        }

        $tenantId = (int) ($user->tenant_id ?? 1);

        try {
            $this->dispatcher->dispatchForTenantOncePerDay($tenantId);
        } catch (Throwable $e) {
            // Best effort only: avoid blocking page access.
        }
    }
}

