<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckSession
{
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

        // Check if user is logged in via our session
        $isLoggedIn = session('is_logged_in') 
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
