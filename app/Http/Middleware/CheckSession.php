<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

class CheckSession
{
    private function normalizeRole(?string $role): string
    {
        $role = strtolower(trim((string) $role));
        if ($role === 'svp lapangan') return 'svp_lapangan';
        return $role;
    }

    private function syncSpatieRoleFromLegacy(mixed $user): void
    {
        if (!$user) return;

        // Only for HasRoles models (NociUser).
        if (!method_exists($user, 'getRoleNames') || !method_exists($user, 'syncRoles')) {
            return;
        }

        $legacyRole = $this->normalizeRole($user->role ?? null);
        if ($legacyRole === '') {
            return;
        }

        // Avoid hard failures on early DBs that haven't run RBAC migrations yet.
        try {
            if (!Schema::hasTable('roles') || !Schema::hasTable('model_has_roles')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        try {
            $current = $user->getRoleNames()
                ->map(fn ($r) => $this->normalizeRole((string) $r))
                ->filter()
                ->values();

            if ($current->count() === 1 && $current->first() === $legacyRole) {
                return;
            }

            Role::findOrCreate($legacyRole, 'web');

            // Single source of truth: `noci_users.role` legacy column.
            $user->syncRoles([$legacyRole]);
        } catch (\Throwable) {
            // Best-effort; ignore RBAC sync failures.
        }
    }

    private function syncLegacySession(Request $request): void
    {
        $user = Auth::user();
        if (!$user) {
            return;
        }

        $displayName = $user->username ?? 'Admin';
        if (!empty($user->name)) $displayName = $user->name;
        if (!empty($user->fullname)) $displayName = $user->fullname;

        $role = $this->normalizeRole($user->role ?? 'cs');
        $isTeknisi = in_array($role, ['teknisi', 'svp_lapangan'], true);

        $request->session()->put([
            'tenant_id' => $user->tenant_id ?? 0,
            'user_id' => $user->id,
            'level' => $user->role ?? 'cs',
            'admin_name' => $displayName,
            'admin_username' => $user->username ?? '',
            'is_teknisi' => $isTeknisi,
        ]);

        if ($isTeknisi) {
            $request->session()->put([
                'teknisi_logged_in' => true,
                'teknisi_id' => $user->id,
                'teknisi_name' => $user->name ?? $displayName,
                'teknisi_role' => $user->role ?? 'teknisi',
                'teknisi_pop' => $user->default_pop ?? '',
            ]);

            // Avoid stale admin-session flags when a user role changes.
            $request->session()->forget(['is_logged_in', 'logged_in', 'admin_id']);
            return;
        }

        // Avoid stale teknisi-session flags when a user role changes.
        $request->session()->forget(['teknisi_logged_in', 'teknisi_id', 'teknisi_name', 'teknisi_role', 'teknisi_pop']);

        $request->session()->put([
            'is_logged_in' => true,
            'logged_in' => true,
            'admin_id' => $user->id,
        ]);
    }

    /**
     * Handle an incoming request.
     * 
     * Check if user is logged in via session (compatible with native PHP login).
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::check() && $userId = session('user_id')) {
            Auth::loginUsingId($userId);
        }

        // If Auth is already restored (e.g. remember cookie), backfill legacy session keys.
        if (Auth::check()) {
            $user = Auth::user();
            $this->syncSpatieRoleFromLegacy($user);
            $this->syncLegacySession($request);
        }

        // Check if user is logged in via our session
        $isLoggedIn = Auth::check()
            || session('is_logged_in') 
            || session('logged_in') 
            || session('teknisi_logged_in')
            || session('user_id');
        
        if (!$isLoggedIn) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }
            
            return redirect()->route('login');
        }

        return $next($request);
    }
}
