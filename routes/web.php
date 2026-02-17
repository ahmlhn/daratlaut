<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Web\ChatAdminApiController;
use App\Http\Controllers\Web\ChatController;
use App\Http\Controllers\Web\CustomerController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DirectApiController;
use App\Http\Controllers\Web\DirectController;
use App\Http\Controllers\Web\LogController;
use App\Http\Controllers\Web\FiberController;
use App\Http\Controllers\Web\InstallationController;
use App\Http\Controllers\Web\InvoiceController;
use App\Http\Controllers\Web\FinanceController;
use App\Http\Controllers\Web\LeadController;
use App\Http\Controllers\Web\MapsController;
use App\Http\Controllers\Web\OltController;
use App\Http\Controllers\Web\PaymentController;
use App\Http\Controllers\Web\PaymentPortalController;
use App\Http\Controllers\Web\PlanController;
use App\Http\Controllers\Web\PopController;
use App\Http\Controllers\Web\PublicRedirectController;
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\SettingsController;
use App\Http\Controllers\Web\SuperAdminTenantController;
use App\Http\Controllers\Web\SystemUpdateController;
use App\Http\Controllers\Web\TeamController;
use App\Http\Controllers\Web\TeknisiController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

Route::get('/', fn () => redirect('/dashboard'));

// CSRF refresh endpoint for stale tabs (e.g. page left open for long time).
// Frontend calls this when a request gets 419 so it can retry once with fresh token cookie.
Route::get('/csrf-token', function (Request $request) {
    $request->session()->regenerateToken();

    return response()->json([
        'status' => 'ok',
        'token' => csrf_token(),
    ], 200, [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => '0',
    ]);
})->name('csrf.token');

// Public payment portal (no auth required)
Route::get('/pay/{token}', [PaymentPortalController::class, 'show'])->name('payment.portal');

// Public redirect links:
// - Pretty URL (tenant resolved from host/subdomain): /link/{code}
// - Fallback with explicit tenant token: /link/{tenantToken}/{code}
// - Legacy compatibility path: /go/{tenantToken}/{code}
Route::get('/link/{code}', [PublicRedirectController::class, 'link'])
    ->where(['code' => '[A-Za-z0-9\-_]+'])
    ->name('public.redirect.link');

Route::get('/link/{tenantToken}/{code}', [PublicRedirectController::class, 'linkWithToken'])
    ->where(['tenantToken' => '[A-Za-z0-9\-_]+', 'code' => '[A-Za-z0-9\-_]+'])
    ->name('public.redirect.link_token');

Route::get('/go/{tenantToken}/{code}', [PublicRedirectController::class, 'go'])
    ->where(['tenantToken' => '[A-Za-z0-9\-_]+', 'code' => '[A-Za-z0-9\-_]+'])
    ->name('public.redirect.go');

// Shared-hosting fallback: serve files from storage/app/public when symlink
// public/storage is unavailable (common on restricted cPanel environments).
Route::get('/storage/{path}', function (string $path) {
    $relativePath = trim(str_replace('\\', '/', $path), '/');
    if ($relativePath === '' || str_contains($relativePath, '../')) {
        abort(404);
    }

    $basePath = realpath(storage_path('app/public'));
    if ($basePath === false) {
        abort(404);
    }

    $targetPath = realpath($basePath . DIRECTORY_SEPARATOR . $relativePath);
    if ($targetPath === false || !is_file($targetPath)) {
        abort(404);
    }

    $basePrefix = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($targetPath !== $basePath && !str_starts_with($targetPath, $basePrefix)) {
        abort(404);
    }

    return response()->file($targetPath);
})->where('path', '.*')->name('storage.fallback');

// Legacy deep-link compatibility for WA notifications: /chat/index.php?id=...
Route::get('/chat/index.php', function (Request $request) {
    $qs = $request->getQueryString();
    return redirect('/chat' . ($qs ? ('?' . $qs) : ''), 302);
})->name('chat.legacy_index');

// Legacy Direct/Chat logging endpoint used by direct/app.js (../log.php) and older pages.
Route::match(['GET', 'POST', 'OPTIONS'], '/log.php', [LogController::class, 'handle'])->name('legacy.log');

// Public Direct portal (token-based)
Route::get('/direct', [DirectController::class, 'index'])->name('direct.index');
Route::match(['GET', 'POST', 'OPTIONS'], '/direct/api.php', [DirectApiController::class, 'handle'])->name('direct.api');
Route::get('/direct/{path}', [DirectController::class, 'asset'])
    ->where('path', '.*')
    ->name('direct.asset');

// Guest routes
Route::middleware(['guest'])->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

// Legacy Chat JS compatibility:
// Some cached builds (or legacy HTML) expect /chat/{inline|app|game}.js.
// Serve the current scripts from /public/legacy-chat without creating a /public/chat directory
// (which would break the /chat route under PHP's built-in server).
Route::get('/chat/{script}.js', function (string $script) {
    $path = public_path("legacy-chat/{$script}.js");
    abort_unless(is_file($path), 404);

    return response()->file($path, [
        'Content-Type' => 'application/javascript; charset=UTF-8',
    ]);
})->where('script', '^(inline|app|game)$')->name('chat.legacy_js');

// Authenticated routes
Route::middleware(['auth', 'resolve.tenant', 'tenant.feature'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Chat Admin (integrated)
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::match(['GET', 'POST'], '/chat/admin_api.php', [ChatAdminApiController::class, 'handle'])->name('chat.admin_api');
    Route::get('/chat/uploads/{filename}', function (string $filename) {
        // Serve legacy image URLs used by native chat UI: /chat/uploads/{file}
        $safe = basename($filename);
        if ($safe === '' || str_contains($safe, '..') || str_contains($safe, '/') || str_contains($safe, '\\')) {
            abort(400);
        }

        $candidates = [
            public_path('uploads/chat/' . $safe),
            // Best-effort: serve existing native uploads if present in repo root.
            base_path('chat/uploads/' . $safe),
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) return response()->file($path);
        }

        abort(404);
    })->where('filename', '^[^/]+$')->name('chat.uploads');
    
    // Profile
    Route::get('/profile', [ProfileController::class, 'index'])->name('profile.index');
    Route::post('/profile', [ProfileController::class, 'update'])->name('profile.update');
    
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('/plans', [PlanController::class, 'index'])->name('plans.index');
    
    // Installations (Pasang Baru)
    Route::get('/installations', [InstallationController::class, 'index'])->name('installations.index');
    Route::get('/installations/riwayat', [InstallationController::class, 'riwayat'])->name('installations.riwayat');
    
    // Team
    Route::get('/team', [TeamController::class, 'index'])->name('team.index');
    
    // Settings
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::get('/settings/roles', function () {
        return \Inertia\Inertia::render('Settings/Roles/Index');
    })->middleware('manage.settings')->name('settings.roles');

    // System Update UI lives under Settings, but keep legacy /system-update path as redirect.
    Route::get('/settings/system-update', [SystemUpdateController::class, 'index'])->name('system_update.index');
    Route::get('/system-update', function (Request $request) {
        $qs = $request->getQueryString();
        return redirect('/settings/system-update' . ($qs ? ('?' . $qs) : ''), 302);
    })->name('system_update.legacy');

    // System Update (ZIP-based; no shell access required)
    Route::get('/system-update/status', [SystemUpdateController::class, 'status'])->name('system_update.status');
    Route::post('/system-update/upload', [SystemUpdateController::class, 'upload'])->name('system_update.upload');
    Route::post('/system-update/download', [SystemUpdateController::class, 'download'])->name('system_update.download');
    Route::post('/system-update/start', [SystemUpdateController::class, 'start'])->name('system_update.start');
    Route::post('/system-update/step', [SystemUpdateController::class, 'step'])->name('system_update.step');
    Route::post('/system-update/reset', [SystemUpdateController::class, 'reset'])->name('system_update.reset');
    Route::post('/system-update/github/check', [SystemUpdateController::class, 'githubCheck'])->name('system_update.github_check');
    Route::post('/system-update/github/download', [SystemUpdateController::class, 'githubDownload'])->name('system_update.github_download');
    Route::post('/system-update/github/token', [SystemUpdateController::class, 'githubSaveToken'])->name('system_update.github_token');
    Route::post('/system-update/github/token/clear', [SystemUpdateController::class, 'githubClearToken'])->name('system_update.github_token_clear');

    // OLT Management
    Route::get('/olts', [OltController::class, 'index'])->name('olts.index');

    // Fiber / Kabel FO
    Route::get('/fiber', [FiberController::class, 'index'])->name('fiber.index');
    
    // Finance (Keuangan)
    Route::get('/finance', [FinanceController::class, 'index'])->name('finance.index');
    
    // Leads — UI moved into Chat page; API routes remain in api.php
    
    // Reports
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    
    // Teknisi Module
    Route::get('/teknisi', [TeknisiController::class, 'index'])->name('teknisi.index');
    Route::get('/teknisi/riwayat', [TeknisiController::class, 'riwayat'])->name('teknisi.riwayat');
    Route::get('/teknisi/rekap', [TeknisiController::class, 'rekap'])->name('teknisi.rekap');
    
    // Maps
    Route::get('/maps', [MapsController::class, 'index'])->name('maps.index');
});

Route::middleware(['auth', 'superadmin'])
    ->prefix('superadmin')
    ->name('superadmin.')
    ->group(function () {
        Route::get('/', function () {
            return redirect()->route('superadmin.tenants.index');
        })->name('index');

        Route::get('/tenants', [SuperAdminTenantController::class, 'index'])
            ->name('tenants.index');
    });
