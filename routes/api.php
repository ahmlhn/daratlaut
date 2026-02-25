<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\OltController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\FiberController;
use App\Http\Controllers\Api\V1\InstallationController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\IsolirController;
use App\Http\Controllers\Api\V1\MapsController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentGatewayController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\PopController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\SuperAdminTenantController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\TeknisiController;
use Illuminate\Support\Facades\Route;

// Protected API routes (session + tenant)
Route::middleware(['auth', 'resolve.tenant', 'tenant.feature'])->prefix('v1')->name('api.v1.')->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/activity', [DashboardController::class, 'activity']);

    // Customers
    Route::get('/customers/stats', [CustomerController::class, 'stats']);
    Route::apiResource('/customers', CustomerController::class);

    // Invoices
    Route::get('/invoices/stats', [InvoiceController::class, 'stats']);
    Route::apiResource('/invoices', InvoiceController::class);

    // Payments
    Route::get('/payments/stats', [PaymentController::class, 'stats']);
    Route::apiResource('/payments', PaymentController::class);

    // Plans
    Route::apiResource('/plans', PlanController::class);

    // Leads
    Route::get('/leads/stats', [LeadController::class, 'stats']);
    Route::get('/leads/statuses', [LeadController::class, 'statuses']);
    Route::post('/leads/{id}/convert', [LeadController::class, 'convert']);
    Route::post('/leads/bulk-status', [LeadController::class, 'bulkUpdateStatus']);
    Route::apiResource('/leads', LeadController::class);

    // Installations
    Route::get('/installations/stats', [InstallationController::class, 'stats']);
    Route::get('/installations/riwayat', [InstallationController::class, 'riwayat']);
    Route::get('/installations/pops', [InstallationController::class, 'pops']);
    Route::get('/installations/technicians', [InstallationController::class, 'technicians']);
    Route::get('/installations/{id}/history', [InstallationController::class, 'history']);
    Route::post('/installations/{id}/claim', [InstallationController::class, 'claim']);
    Route::post('/installations/{id}/transfer', [InstallationController::class, 'transfer']);
    Route::post('/installations/{id}/request-cancel', [InstallationController::class, 'requestCancel']);
    Route::post('/installations/{id}/decide-cancel', [InstallationController::class, 'decideCancel']);
    Route::post('/installations/{id}/toggle-priority', [InstallationController::class, 'togglePriority']);
    Route::post('/installations/send-pop-recap', [InstallationController::class, 'sendPopRecap']);
    Route::post('/installations/{id}/status', [InstallationController::class, 'updateStatus']);
    Route::apiResource('/installations', InstallationController::class);

    // Chat Admin (integrated)
    Route::get('/chat/contacts', [ChatController::class, 'contacts']);
    Route::get('/chat/messages', [ChatController::class, 'messages']);
    Route::post('/chat/send', [ChatController::class, 'send']);
    Route::get('/chat/media/{id}', [ChatController::class, 'media']);
    Route::post('/chat/message/{id}/delete', [ChatController::class, 'deleteMessage']);
    Route::post('/chat/message/{id}/edit', [ChatController::class, 'editMessage']);
    Route::post('/chat/customer/update', [ChatController::class, 'updateCustomer']);
    Route::post('/chat/customer/note', [ChatController::class, 'saveNote']);
    Route::post('/chat/session/end', [ChatController::class, 'endSession']);
    Route::post('/chat/session/reopen', [ChatController::class, 'reopenSession']);
    Route::post('/chat/session/delete', [ChatController::class, 'deleteSession']);
    Route::get('/chat/templates', [ChatController::class, 'templates']);
    Route::post('/chat/templates', [ChatController::class, 'saveTemplate']);
    Route::delete('/chat/templates/{id}', [ChatController::class, 'deleteTemplate']);
    Route::get('/chat/settings', [ChatController::class, 'getSettings']);
    Route::post('/chat/settings', [ChatController::class, 'saveSettings']);

    // Teknisi Module
    Route::get('/teknisi/tasks', [TeknisiController::class, 'tasks']);
    Route::get('/teknisi/tasks/{id}', [TeknisiController::class, 'taskDetail']);
    Route::post('/teknisi/tasks', [TeknisiController::class, 'saveInstallation']);
    Route::post('/teknisi/tasks/{id}/status', [TeknisiController::class, 'updateStatus']);
    Route::get('/teknisi/riwayat', [TeknisiController::class, 'riwayat']);
    Route::get('/teknisi/rekap', [TeknisiController::class, 'rekap']);
    Route::post('/teknisi/expenses', [TeknisiController::class, 'saveExpenses']);
    Route::post('/teknisi/rekap/send', [TeknisiController::class, 'sendRekapToGroup']);
    Route::post('/teknisi/rekap/upload-proof', [TeknisiController::class, 'uploadRekapProof']);
    Route::get('/teknisi/pops', [TeknisiController::class, 'pops']);
    Route::get('/teknisi/technicians', [TeknisiController::class, 'technicians']);
    Route::get('/teknisi/sales', [TeknisiController::class, 'sales']);

    // Maps
    Route::get('/maps/locations', [MapsController::class, 'locations']);
    Route::get('/maps/history/{techId}', [MapsController::class, 'history']);
    Route::post('/maps/location', [MapsController::class, 'updateLocation']);
    Route::get('/maps/installations', [MapsController::class, 'installations']);

    // Fiber / Kabel FO
    Route::get('/fiber/summary', [FiberController::class, 'summary']);
    Route::get('/fiber/map-data', [FiberController::class, 'mapData']);

    Route::get('/fiber/cables', [FiberController::class, 'listCables']);
    Route::post('/fiber/cables', [FiberController::class, 'storeCable']);
    Route::put('/fiber/cables/{id}', [FiberController::class, 'updateCable']);
    Route::delete('/fiber/cables/{id}', [FiberController::class, 'deleteCable']);
    Route::get('/fiber/cables/{id}/core-occupancy', [FiberController::class, 'coreOccupancy']);
    Route::put('/fiber/cables/{id}/core-reservations', [FiberController::class, 'updateCoreReservations']);

    Route::get('/fiber/points', [FiberController::class, 'listPoints']);
    Route::post('/fiber/points', [FiberController::class, 'storePoint']);
    Route::put('/fiber/points/{id}', [FiberController::class, 'updatePoint']);
    Route::delete('/fiber/points/{id}', [FiberController::class, 'deletePoint']);

    Route::get('/fiber/breaks', [FiberController::class, 'listBreaks']);
    Route::post('/fiber/breaks', [FiberController::class, 'storeBreak']);
    Route::put('/fiber/breaks/{id}', [FiberController::class, 'updateBreak']);
    Route::post('/fiber/breaks/{id}/fix', [FiberController::class, 'fixBreak']);
    Route::post('/fiber/breaks/{id}/verify', [FiberController::class, 'verifyBreak']);
    Route::delete('/fiber/breaks/{id}', [FiberController::class, 'deleteBreak']);

    Route::get('/fiber/ports', [FiberController::class, 'listPorts']);
    Route::post('/fiber/ports', [FiberController::class, 'storePort']);
    Route::put('/fiber/ports/{id}', [FiberController::class, 'updatePort']);
    Route::delete('/fiber/ports/{id}', [FiberController::class, 'deletePort']);

    Route::get('/fiber/links', [FiberController::class, 'listLinks']);
    Route::post('/fiber/links', [FiberController::class, 'storeLink']);
    Route::put('/fiber/links/{id}', [FiberController::class, 'updateLink']);
    Route::delete('/fiber/links/{id}', [FiberController::class, 'deleteLink']);

    Route::get('/fiber/trace/{portId}', [FiberController::class, 'trace']);

    // Team
    Route::get('/team/stats', [TeamController::class, 'stats']);
    Route::get('/team/technicians', [TeamController::class, 'technicians']);
    Route::get('/team/sales', [TeamController::class, 'sales']);
    Route::post('/team/{id}/toggle-status', [TeamController::class, 'toggleStatus']);
    Route::apiResource('/team', TeamController::class);

    // POPs (dropdown only — CRUD managed via Settings)
    Route::get('/pops/dropdown', [PopController::class, 'dropdown']);

    // Settings
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::get('/settings/wa', [SettingsController::class, 'getWaConfig']);
    Route::post('/settings/wa', [SettingsController::class, 'saveWaConfig']);
    Route::get('/settings/wa-backup', [SettingsController::class, 'getBackupConfig']);
    Route::post('/settings/wa-backup', [SettingsController::class, 'saveBackupConfig']);
    Route::get('/settings/tg', [SettingsController::class, 'getTgConfig']);
    Route::post('/settings/tg', [SettingsController::class, 'saveTgConfig']);
    Route::get('/settings/templates', [SettingsController::class, 'getTemplates']);
    Route::post('/settings/templates', [SettingsController::class, 'saveTemplate']);
    Route::delete('/settings/templates/{id}', [SettingsController::class, 'deleteTemplate']);
    Route::get('/settings/gateways', [SettingsController::class, 'getGateways']);
    Route::get('/settings/tenant-gateways', [SettingsController::class, 'getTenantGateways']);
    Route::get('/settings/pops', [SettingsController::class, 'getPops']);
    Route::post('/settings/pops', [SettingsController::class, 'savePop']);
    Route::delete('/settings/recap-groups/{id}', [SettingsController::class, 'deleteRecapGroup']);
    Route::get('/settings/fee-settings', [SettingsController::class, 'getFeeSettings']);
    Route::post('/settings/fee-settings', [SettingsController::class, 'saveFeeSettings']);
    Route::get('/settings/maps', [SettingsController::class, 'getMapsConfig']);
    Route::post('/settings/maps', [SettingsController::class, 'saveMapsConfig']);
    Route::get('/settings/cron', [SettingsController::class, 'getCronSettings']);
    Route::post('/settings/cron', [SettingsController::class, 'saveCronSettings']);
    Route::get('/settings/cron/logs', [SettingsController::class, 'getCronLogs']);
    Route::get('/settings/gateway-status', [SettingsController::class, 'getGatewayStatus']);
    Route::get('/settings/public-url', [SettingsController::class, 'getPublicUrlEndpoint']);
    Route::get('/settings/redirect-links', [SettingsController::class, 'getRedirectLinks']);
    Route::post('/settings/redirect-links', [SettingsController::class, 'saveRedirectLink']);
    Route::delete('/settings/redirect-links/{id}', [SettingsController::class, 'deleteRedirectLink']);
    Route::get('/settings/redirect-events', [SettingsController::class, 'getRedirectEvents']);
    Route::get('/settings/install-variables', [SettingsController::class, 'getInstallVariables']);
    Route::post('/settings/test-wa', [SettingsController::class, 'testWa']);
    Route::post('/settings/test-mpwa', [SettingsController::class, 'testMpwa']);
    Route::post('/settings/test-tg', [SettingsController::class, 'testTg']);
    Route::get('/settings/notif-logs', [SettingsController::class, 'getNotifLogs']);
    Route::get('/settings/notif-stats', [SettingsController::class, 'getNotifStats']);

    // OLT Management
    Route::get('/olts/stats', [OltController::class, 'stats']);
    Route::get('/olts/dropdown', [OltController::class, 'dropdown']);
    Route::post('/olts/{id}/test-connection', [OltController::class, 'testConnection']);
    Route::get('/olts/{id}/fsp', [OltController::class, 'listFsp']);
    Route::get('/olts/{id}/scan-uncfg', [OltController::class, 'scanUnconfigured']);
    Route::get('/olts/{id}/registered', [OltController::class, 'loadRegisteredFsp']);
    Route::post('/olts/{id}/registered-all', [OltController::class, 'loadRegisteredAllBaseinfo']);
    Route::get('/olts/{id}/cache', [OltController::class, 'loadRegisteredCache']);
    Route::get('/olts/{id}/search', [OltController::class, 'searchCache']);
    Route::post('/olts/{id}/find-onu-by-sn', [OltController::class, 'findOnuBySn']);
    Route::get('/olts/{id}/onu-detail', [OltController::class, 'getOnuDetail']);
    Route::get('/olts/{id}/onu-rx-history', [OltController::class, 'getOnuRxHistory']);
    Route::post('/olts/{id}/register-onu', [OltController::class, 'registerOnu']);
    Route::post('/olts/{id}/update-onu-name', [OltController::class, 'updateOnuName']);
    Route::post('/olts/{id}/delete-onu', [OltController::class, 'deleteOnu']);
    Route::post('/olts/{id}/restart-onu', [OltController::class, 'restartOnu']);
    Route::post('/olts/{id}/write-config', [OltController::class, 'writeConfig']);
    Route::post('/olts/{id}/auto-register', [OltController::class, 'autoRegister']);
    Route::post('/olts/{id}/sync-onu-names', [OltController::class, 'syncOnuNames']);
    Route::post('/olts/{id}/sync-all', [OltController::class, 'syncAll']);
    Route::get('/olts/{id}/logs', [OltController::class, 'logs']);
    Route::apiResource('/olts', OltController::class);

    // Finance / Keuangan
    Route::get('/finance/dashboard', [FinanceController::class, 'dashboard']);
    Route::get('/finance/coa', [FinanceController::class, 'listCoa']);
    Route::get('/finance/coa/dropdown', [FinanceController::class, 'coaDropdown']);
    Route::post('/finance/coa', [FinanceController::class, 'saveCoa']);
    Route::delete('/finance/coa/{id}', [FinanceController::class, 'deleteCoa']);
    Route::get('/finance/branches', [FinanceController::class, 'listBranches']);
    Route::post('/finance/branches', [FinanceController::class, 'saveBranch']);
    Route::get('/finance/transactions', [FinanceController::class, 'listTransactions']);
    Route::get('/finance/transactions/{id}', [FinanceController::class, 'getTransaction']);
    Route::post('/finance/transactions', [FinanceController::class, 'createTransaction']);
    Route::put('/finance/transactions/{id}', [FinanceController::class, 'updateTransaction']);
    Route::delete('/finance/transactions/{id}', [FinanceController::class, 'deleteTransaction']);
    Route::get('/finance/approvals', [FinanceController::class, 'listApprovals']);
    Route::post('/finance/transactions/{id}/approve', [FinanceController::class, 'approveTransaction']);
    Route::post('/finance/transactions/{id}/reject', [FinanceController::class, 'rejectTransaction']);
    Route::post('/finance/transactions/bulk-approve', [FinanceController::class, 'bulkApprove']);
    Route::get('/finance/reports/trial-balance', [FinanceController::class, 'trialBalance']);
    Route::get('/finance/reports/income-statement', [FinanceController::class, 'incomeStatement']);
    Route::get('/finance/reports/balance-sheet', [FinanceController::class, 'balanceSheet']);
    Route::get('/finance/reports/account-ledger', [FinanceController::class, 'accountLedger']);

    // Payment Gateway (Midtrans)
    Route::post('/invoices/{id}/pay', [PaymentGatewayController::class, 'createPaymentLink']);
    Route::post('/invoices/{id}/qris', [PaymentGatewayController::class, 'createQris']);
    Route::get('/payment/status/{orderId}', [PaymentGatewayController::class, 'checkStatus']);

    // MikroTik Isolir
    Route::get('/isolir/status', [IsolirController::class, 'status']);
    Route::post('/customers/{id}/suspend', [IsolirController::class, 'suspend']);
    Route::post('/customers/{id}/unsuspend', [IsolirController::class, 'unsuspend']);
    Route::get('/customers/{id}/online', [IsolirController::class, 'online']);
    Route::get('/customers/{id}/pppoe-status', [IsolirController::class, 'secretStatus']);
    Route::post('/isolir/bulk-suspend', [IsolirController::class, 'bulkSuspend']);
    Route::post('/isolir/bulk-unsuspend', [IsolirController::class, 'bulkUnsuspend']);

    // Reports & Analytics
    Route::get('/reports/summary', [ReportController::class, 'summary']);
    Route::get('/reports/revenue-chart', [ReportController::class, 'revenueChart']);
    Route::get('/reports/customer-growth', [ReportController::class, 'customerGrowth']);
    Route::get('/reports/installation-stats', [ReportController::class, 'installationStats']);
    Route::get('/reports/payment-methods', [ReportController::class, 'paymentMethods']);
    Route::get('/reports/plan-popularity', [ReportController::class, 'planPopularity']);
    Route::get('/reports/top-customers', [ReportController::class, 'topCustomers']);
    Route::get('/reports/export', [ReportController::class, 'exportCsv']);


    // Role Management
    // - Read-only endpoints are available for authenticated users (e.g. Team module role dropdown).
    // - Mutations and permissions listing are restricted to Settings managers.
    Route::get('/roles', [\App\Http\Controllers\Api\V1\RoleController::class, 'index']);
    Route::get('/roles/{id}', [\App\Http\Controllers\Api\V1\RoleController::class, 'show']);

    Route::middleware(['manage.settings'])->group(function () {
        Route::post('/roles', [\App\Http\Controllers\Api\V1\RoleController::class, 'store']);
        Route::put('/roles/{id}', [\App\Http\Controllers\Api\V1\RoleController::class, 'update']);
        Route::delete('/roles/{id}', [\App\Http\Controllers\Api\V1\RoleController::class, 'destroy']);
        Route::get('/permissions', [\App\Http\Controllers\Api\V1\RoleController::class, 'permissions']);
    });

});

// Superadmin tenant management (not tenant-scoped)
Route::middleware(['auth', 'superadmin'])
    ->prefix('v1/superadmin')
    ->group(function () {
        Route::get('/tenants', [SuperAdminTenantController::class, 'index']);
        Route::put('/tenants/{tenantId}', [SuperAdminTenantController::class, 'updateTenant']);
        Route::put('/tenants/{tenantId}/features', [SuperAdminTenantController::class, 'saveFeatures']);
    });

// Midtrans webhook (no auth)
Route::post('/midtrans/notification', [PaymentGatewayController::class, 'notification']);
Route::get('/payment/finish', [PaymentGatewayController::class, 'finish']);
