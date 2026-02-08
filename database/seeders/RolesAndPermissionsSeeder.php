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
            'manage team',
            'manage finance',
            'manage olt',
            'view dashboard',
            'manage settings',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Create Roles and assign permissions
        // Admin
        $admin = Role::firstOrCreate(['name' => NociUser::ROLE_ADMIN]);
        $admin->givePermissionTo(Permission::all());

        // Owner
        $owner = Role::firstOrCreate(['name' => NociUser::ROLE_OWNER]);
        $owner->givePermissionTo(Permission::all());

        // Keuangan
        $keuangan = Role::firstOrCreate(['name' => NociUser::ROLE_KEUANGAN]);
        $keuangan->givePermissionTo(['manage finance', 'view dashboard']);

        // Teknisi
        $teknisi = Role::firstOrCreate(['name' => NociUser::ROLE_TEKNISI]);
        $teknisi->givePermissionTo(['manage olt']);

        // CS
        $cs = Role::firstOrCreate(['name' => NociUser::ROLE_CS]);
        $cs->givePermissionTo(['manage olt', 'view dashboard']);

        // SVP
        $svp = Role::firstOrCreate(['name' => NociUser::ROLE_SVP]);
        $svp->givePermissionTo(['manage team', 'manage olt', 'view dashboard']);

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
