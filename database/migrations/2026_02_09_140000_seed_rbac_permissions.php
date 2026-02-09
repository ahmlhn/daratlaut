<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        // Ensure the Role UI always has a complete baseline list, even if seeder isn't executed on deploy.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            // Dashboard
            'view dashboard',

            // Installations (Pasang Baru)
            'view installations',
            'create installations',
            'edit installations',
            'delete installations',
            'approve installations',
            'send installations recap',
            'view riwayat installations',

            // Teknisi
            'view teknisi',
            'edit teknisi',
            'send teknisi recap',

            // Maps
            'view maps',
            'manage maps',

            // Chat
            'view chat',
            'send chat',
            'edit chat',
            'delete chat',

            // Leads
            'view leads',
            'create leads',
            'edit leads',
            'delete leads',
            'convert leads',
            'bulk leads',

            // Customers
            'view customers',
            'create customers',
            'edit customers',
            'delete customers',

            // Plans
            'view plans',
            'create plans',
            'edit plans',
            'delete plans',

            // Billing: Invoices & Payments
            'view invoices',
            'create invoices',
            'edit invoices',
            'delete invoices',
            'view payments',
            'create payments',
            'edit payments',
            'delete payments',

            // Reports
            'view reports',
            'export reports',

            // Finance (Keuangan)
            'view finance',
            'create finance',
            'edit finance',
            'delete finance',
            'approve finance',
            'export finance',
            'manage finance', // Legacy/coarse

            // OLT
            'view olts',
            'create olts',
            'edit olts',
            'delete olts',
            'manage olt', // Legacy/coarse

            // Team
            'view team',
            'create team',
            'edit team',
            'delete team',
            'manage team', // Legacy/coarse

            // System / Settings
            'manage settings',
            'manage system update',
            'manage roles',

            // Isolir
            'manage isolir',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // No-op: do not remove permissions automatically.
    }
};

