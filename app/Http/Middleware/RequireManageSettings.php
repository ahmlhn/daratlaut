<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireManageSettings
{
    private function normalizeLegacyRole(?string $role): string
    {
        $role = strtolower(trim((string) $role));
        if ($role === 'svp lapangan') return 'svp_lapangan';
        return $role;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?: Auth::user();
        if (!$user) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return new Response('Unauthenticated.', 401);
        }

        $legacyRole = $this->normalizeLegacyRole($user->role ?? null);

        // Primary rule: admin/owner can manage settings even if RBAC tables are not seeded yet.
        $allowedLegacy = in_array($legacyRole, ['admin', 'owner'], true);

        // Secondary rule: allow anyone with the permission (Spatie).
        $allowedPermission = method_exists($user, 'can') && $user->can('manage settings');

        if (!$allowedLegacy && !$allowedPermission) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            return new Response('Forbidden', 403);
        }

        return $next($request);
    }
}

