<?php

namespace App\Http\Middleware;

use App\Support\SuperAdminAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?: Auth::user();
        if (!$user) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return new Response('Unauthenticated.', 401);
        }

        if (!SuperAdminAccess::hasAccess($user)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            return new Response('Forbidden', 403);
        }

        return $next($request);
    }
}
