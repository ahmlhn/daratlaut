<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\NociUser;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create Permissions
        $permissions = [
            'manage team', // Legacy
            'view team',
            'create team',
            'edit team',
            'delete team',
            'view dashboard',
            'view installations',
            'create installations',
            'edit installations',
            'delete installations',
            'approve installations',
            'send installations recap',
            'view riwayat installations',
            'view teknisi',
            'edit teknisi',
            'send teknisi recap',
            'view maps',
            'manage maps',
            'view chat',
            'send chat',
            'edit chat',
            'delete chat',
            'view leads',
            'create leads',
            'edit leads',
            'delete leads',
            'convert leads',
            'bulk leads',
            'view customers',
            'create customers',
            'edit customers',
            'delete customers',
            'view plans',
            'create plans',
            'edit plans',
            'delete plans',
            'view invoices',
            'create invoices',
            'edit invoices',
            'delete invoices',
            'view payments',
            'create payments',
            'edit payments',
            'delete payments',
            'view reports',
            'export reports',
            'view finance',
            'create finance',
            'edit finance',
            'delete finance',
            'approve finance',
            'export finance',
            'manage finance', // Legacy/coarse
            'view olts',
            'create olts',
            'edit olts',
            'delete olts',
            'manage olt', // Legacy/coarse
            'manage isolir',
            'manage settings',
            'manage system update',
            'manage roles',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        // Create Roles and assign permissions
        // Admin
        $admin = Role::firstOrCreate(['name' => NociUser::ROLE_ADMIN]);
        $admin->syncPermissions(Permission::all());

        // Owner
        $owner = Role::firstOrCreate(['name' => NociUser::ROLE_OWNER]);
        $owner->syncPermissions(Permission::all());

        // Keuangan
        $keuangan = Role::firstOrCreate(['name' => NociUser::ROLE_KEUANGAN]);
        $keuangan->givePermissionTo([
            'view dashboard',
            'view team',
            'view finance',
            'create finance',
            'edit finance',
            'delete finance',
            'approve finance',
            'export finance',
            'manage finance',
            'view reports',
            'export reports',
        ]);

        // Teknisi
        $teknisi = Role::firstOrCreate(['name' => NociUser::ROLE_TEKNISI]);
        $teknisi->givePermissionTo([
            'view dashboard',
            'view team',
            'view installations',
            'edit installations',
            'view teknisi',
            'edit teknisi',
            'send teknisi recap',
            'view maps',
            'manage maps',
            'view olts',
            'manage olt',
        ]);

        // CS
        $cs = Role::firstOrCreate(['name' => NociUser::ROLE_CS]);
        $cs->givePermissionTo([
            'view dashboard',
            'view team',
            'create team',
            'edit team',
            'view installations',
            'create installations',
            'edit installations',
            'approve installations',
            'send installations recap',
            'view customers',
            'create customers',
            'edit customers',
            'view leads',
            'create leads',
            'edit leads',
            'convert leads',
            'bulk leads',
            'view chat',
            'send chat',
            'edit chat',
            'view maps',
            'view olts',
            'manage olt',
        ]);

        // Sales (Team role; can be used for login accounts too)
        $sales = Role::firstOrCreate(['name' => 'sales']);
        $sales->givePermissionTo([
            'view dashboard',
            'view team',
            'view installations',
            'create installations',
            'view customers',
            'view leads',
            'create leads',
        ]);

        // SVP
        $svp = Role::firstOrCreate(['name' => NociUser::ROLE_SVP]);
        $svp->givePermissionTo([
            'manage team',
            'view dashboard',
            'view team',
            'create team',
            'edit team',
            'delete team',
            'view installations',
            'approve installations',
            'view reports',
            'export reports',
            'view olts',
            'manage olt',
        ]);

        // Migrate existing users
        $users = NociUser::all();
        foreach ($users as $user) {
            // Map legacy role column to Spatie Role
            $roleName = $user->role;
            
            // Handle edge cases or mapping if necessary
            if ($roleName && Role::where('name', $roleName)->exists()) {
                $user->assignRole($roleName);
            }
        }
    }
}
