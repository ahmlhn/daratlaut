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
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\SettingsController;
use App\Http\Controllers\Web\SystemUpdateController;
use App\Http\Controllers\Web\TeamController;
use App\Http\Controllers\Web\TeknisiController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

Route::get('/', fn () => redirect('/dashboard'));

// Public payment portal (no auth required)
Route::get('/pay/{token}', [PaymentPortalController::class, 'show'])->name('payment.portal');

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
Route::middleware(['auth', 'resolve.tenant'])->group(function () {
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

    // System Update (ZIP-based; no shell access required)
    Route::get('/system-update', [SystemUpdateController::class, 'index'])->name('system_update.index');
    Route::get('/system-update/status', [SystemUpdateController::class, 'status'])->name('system_update.status');
    Route::post('/system-update/upload', [SystemUpdateController::class, 'upload'])->name('system_update.upload');
    Route::post('/system-update/download', [SystemUpdateController::class, 'download'])->name('system_update.download');
    Route::post('/system-update/start', [SystemUpdateController::class, 'start'])->name('system_update.start');
    Route::post('/system-update/step', [SystemUpdateController::class, 'step'])->name('system_update.step');
    Route::post('/system-update/reset', [SystemUpdateController::class, 'reset'])->name('system_update.reset');
    
    // OLT Management
    Route::get('/olts', [OltController::class, 'index'])->name('olts.index');
    
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
