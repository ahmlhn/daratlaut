<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public Direct portal (legacy `/direct/?t=public_token`) migrated to Laravel.
 *
 * We keep the same HTML/CSS/JS as the native folder by rendering a Blade view and
 * serving legacy assets under `/direct/*` via Laravel routes (no public/ direct dir).
 */
class DirectController extends Controller
{
    public function index(Request $request)
    {
        $token = trim((string) $request->query('t', ''));
        if ($token === '') {
            return response('<h2>Link tenant tidak valid.</h2>', 404);
        }

        $tenant = DB::table('tenants')
            ->where('public_token', $token)
            ->where('status', 'active')
            ->first(['id', 'name']);

        if (!$tenant) {
            return response('<h2>Link tenant tidak valid.</h2>', 404);
        }

        return response()
            ->view('direct.index', [
                'token' => $token,
                'tenant_name' => (string) ($tenant->name ?? 'ISP'),
            ], 200)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Serve legacy assets for /direct/* (app.js, logo, audio, css) from public/legacy-direct.
     */
    public function asset(string $path)
    {
        $base = realpath(public_path('legacy-direct'));
        if (!$base) abort(404);

        // Normalize separators and prevent traversal.
        $rel = str_replace(['\\', '//'], ['/', '/'], $path);
        if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/')) {
            abort(404);
        }

        $full = realpath($base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));
        if (!$full || !str_starts_with($full, $base . DIRECTORY_SEPARATOR) || !is_file($full)) {
            abort(404);
        }

        return response()->file($full);
    }
}

