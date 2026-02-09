<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAuthenticated
{
    private function bootstrapLegacySession(Request $request): void
    {
        $user = Auth::user();
        if (!$user) return;
        if ($request->session()->has('user_id')) return;

        $displayName = $user->username ?? 'Admin';
        if (!empty($user->name)) $displayName = $user->name;
        if (!empty($user->fullname)) $displayName = $user->fullname;

        $role = strtolower(trim((string) ($user->role ?? 'cs')));
        $isTeknisi = in_array($role, ['teknisi', 'svp lapangan', 'svp_lapangan'], true);

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
            return;
        }

        $request->session()->put([
            'is_logged_in' => true,
            'logged_in' => true,
            'admin_id' => $user->id,
        ]);
    }

    /**
     * Handle an incoming request.
     * 
     * Redirect to dashboard if already logged in.
     */
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        // If auth is restored (e.g. remember cookie), backfill legacy session keys.
        if (Auth::check()) {
            $this->bootstrapLegacySession($request);
        }

        // Check if user is logged in via our session
        $isLoggedIn = Auth::check()
            || session('is_logged_in') 
            || session('logged_in') 
            || session('teknisi_logged_in')
            || session('user_id');
        
        if ($isLoggedIn) {
            return redirect('/dashboard');
        }

        return $next($request);
    }
}
