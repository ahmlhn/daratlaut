<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Native PHP app paths commonly work with or without a trailing slash (directory style).
 * Laravel routes are strict, so `/chat/` would 404 while `/chat` works.
 *
 * Redirect GET/HEAD requests that end with a trailing slash to the canonical URL.
 */
class RedirectTrailingSlash
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('get') || $request->isMethod('head')) {
            $path = $request->getPathInfo(); // includes leading "/" and trailing "/"
            if ($path !== '/' && str_ends_with($path, '/')) {
                $trimmed = rtrim($path, '/');
                $qs = $request->getQueryString();
                return redirect($trimmed . ($qs ? ('?' . $qs) : ''), 301);
            }
        }

        return $next($request);
    }
}

