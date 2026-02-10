<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backfill `noci_team` rows from existing login users (`noci_users`).
     *
     * Goal: make the Laravel Team UI show existing users immediately after running migrations,
     * by creating/linking team members with `can_login=1` and `user_id = noci_users.id`.
     */
    public function up(): void
    {
        if (!Schema::hasTable('noci_team') || !Schema::hasTable('noci_users')) {
            return;
        }

        $teamHasTenantId = Schema::hasColumn('noci_team', 'tenant_id');
        $teamHasRole = Schema::hasColumn('noci_team', 'role');
        $teamHasUserId = Schema::hasColumn('noci_team', 'user_id');
        $teamHasEmail = Schema::hasColumn('noci_team', 'email');
        $teamHasCanLogin = Schema::hasColumn('noci_team', 'can_login');
        $teamHasPopId = Schema::hasColumn('noci_team', 'pop_id');

        if (!$teamHasTenantId || !$teamHasRole) {
            // Without tenant_id/role, the Team module can't safely scope/filter; skip backfill.
            return;
        }

        $usersHasTenantId = Schema::hasColumn('noci_users', 'tenant_id');
        $usersHasUsername = Schema::hasColumn('noci_users', 'username');
        $usersHasName = Schema::hasColumn('noci_users', 'name');
        $usersHasEmail = Schema::hasColumn('noci_users', 'email');
        $usersHasPhone = Schema::hasColumn('noci_users', 'phone');
        $usersHasRole = Schema::hasColumn('noci_users', 'role');
        $usersHasStatus = Schema::hasColumn('noci_users', 'status');
        $usersHasDefaultPop = Schema::hasColumn('noci_users', 'default_pop');

        $popsUsable = $teamHasPopId && Schema::hasTable('noci_pops')
            && Schema::hasColumn('noci_pops', 'tenant_id')
            && Schema::hasColumn('noci_pops', 'pop_name');

        DB::table('noci_users')
            ->orderBy('id')
            ->chunkById(200, function ($users) use (
                $teamHasUserId,
                $teamHasEmail,
                $teamHasCanLogin,
                $teamHasPopId,
                $usersHasTenantId,
                $usersHasUsername,
                $usersHasName,
                $usersHasEmail,
                $usersHasPhone,
                $usersHasRole,
                $usersHasStatus,
                $usersHasDefaultPop,
                $popsUsable
            ) {
                foreach ($users as $u) {
                    $tenantId = $usersHasTenantId ? (int) ($u->tenant_id ?? 1) : 1;
                    if ($tenantId <= 0) $tenantId = 1;

                    $name = '';
                    if ($usersHasName) $name = trim((string) ($u->name ?? ''));
                    if ($name === '' && $usersHasUsername) $name = trim((string) ($u->username ?? ''));
                    if ($name === '') continue;

                    $role = $usersHasRole ? strtolower(trim((string) ($u->role ?? ''))) : '';
                    if ($role === 'svp lapangan') $role = 'svp_lapangan';
                    if ($role === '') $role = 'cs';

                    $status = $usersHasStatus ? strtolower(trim((string) ($u->status ?? ''))) : 'active';
                    $isActive = $status !== 'inactive';

                    $payload = [
                        'tenant_id' => $tenantId,
                        'name' => $name,
                        // Legacy `noci_team.phone` is often NOT NULL; keep it non-null to avoid migration failure.
                        'phone' => $usersHasPhone ? (string) ($u->phone ?? '') : '',
                        'role' => $role,
                        'is_active' => $isActive ? 1 : 0,
                    ];

                    if ($teamHasUserId) {
                        $payload['user_id'] = (int) $u->id;
                    }

                    if ($teamHasEmail) {
                        $payload['email'] = $usersHasEmail ? ($u->email ?? null) : null;
                    }

                    if ($teamHasCanLogin) {
                        $payload['can_login'] = 1;
                    }

                    if ($teamHasPopId && $popsUsable && $usersHasDefaultPop) {
                        $popName = trim((string) ($u->default_pop ?? ''));
                        if ($popName !== '') {
                            $popId = DB::table('noci_pops')
                                ->where('tenant_id', $tenantId)
                                ->where('pop_name', $popName)
                                ->value('id');
                            if (!empty($popId)) {
                                $payload['pop_id'] = (int) $popId;
                            }
                        }
                    }

                    // Prefer linking by user_id when available.
                    if ($teamHasUserId) {
                        $existingByUser = DB::table('noci_team')
                            ->where('tenant_id', $tenantId)
                            ->where('user_id', (int) $u->id)
                            ->first(['id']);

                        if ($existingByUser) {
                            // Keep team row in sync with the login account.
                            DB::table('noci_team')->where('id', (int) $existingByUser->id)->update($payload);
                            continue;
                        }

                        // If there's an existing row with same tenant+name but no user_id, attach it.
                        $match = DB::table('noci_team')
                            ->where('tenant_id', $tenantId)
                            ->where('name', $name)
                            ->whereNull('user_id')
                            ->first(['id']);

                        if ($match) {
                            DB::table('noci_team')->where('id', (int) $match->id)->update($payload);
                            continue;
                        }
                    } else {
                        // No user_id column; best-effort de-dupe by tenant+name+role.
                        $exists = DB::table('noci_team')
                            ->where('tenant_id', $tenantId)
                            ->where('name', $name)
                            ->where('role', $role)
                            ->exists();

                        if ($exists) {
                            continue;
                        }
                    }

                    DB::table('noci_team')->insert($payload);
                }
            });
    }

    /**
     * Reverse the migrations.
     *
     * No-op: backfill is data creation/linking; rollback would be destructive and ambiguous.
     */
    public function down(): void
    {
        // Intentionally left blank.
    }
};
