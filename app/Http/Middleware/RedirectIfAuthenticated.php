<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAuthenticated
{
    /**
     * Handle an incoming request.
     * 
     * Redirect to dashboard if already logged in.
     */
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        // Check if user is logged in via our session
        $isLoggedIn = session('is_logged_in') 
            || session('logged_in') 
            || session('teknisi_logged_in')
            || session('user_id');
        
        if ($isLoggedIn) {
            return redirect('/dashboard');
        }

        return $next($request);
    }
}
