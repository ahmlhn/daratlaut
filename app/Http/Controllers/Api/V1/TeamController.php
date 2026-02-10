<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\Installation;
use App\Models\NociUser;
use App\Models\Pop;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class TeamController extends Controller
{
    private function hasRequiredTeamSchema(): bool
    {
        // Required by this controller + Team UI list.
        return Schema::hasTable('noci_team')
            && Schema::hasColumn('noci_team', 'tenant_id')
            && Schema::hasColumn('noci_team', 'name')
            && Schema::hasColumn('noci_team', 'role')
            && Schema::hasColumn('noci_team', 'is_active');
    }

    private function requireTeamSchema(): ?JsonResponse
    {
        if ($this->hasRequiredTeamSchema()) {
            return null;
        }

        return response()->json([
            'message' => 'Team schema belum kompatibel. Jalankan `php artisan migrate` untuk menambah kolom noci_team (tenant_id/role/pop_id/can_login/user_id).',
        ], 500);
    }

    private function tenantId(Request $request): int
    {
        return (int) $request->attributes->get('tenant_id', (int) $request->input('tenant_id', 1));
    }

    private function normalizeLegacyRole(?string $role): string
    {
        $role = strtolower(trim((string) $role));
        if ($role === 'svp lapangan') return 'svp_lapangan';
        return $role;
    }

    private function hasTeamUserIdColumn(): bool
    {
        static $has = null;
        if ($has === null) {
            $has = Schema::hasColumn('noci_team', 'user_id');
        }
        return (bool) $has;
    }

    private function maybeSyncFromUsers(int $tenantId): void
    {
        if ($tenantId <= 0) return;

        // Only sync when we can safely link by user_id.
        if (!$this->hasRequiredTeamSchema()) return;
        if (!$this->hasTeamUserIdColumn()) return;
        if (!Schema::hasTable('noci_users')) return;

        $syncKey = "tenant:{$tenantId}:team:sync_from_users:v1";
        if (Cache::get($syncKey)) return;
        Cache::put($syncKey, 1, 60);

        try {
            $usersHas = [
                'tenant_id' => Schema::hasColumn('noci_users', 'tenant_id'),
                'username' => Schema::hasColumn('noci_users', 'username'),
                'name' => Schema::hasColumn('noci_users', 'name'),
                'email' => Schema::hasColumn('noci_users', 'email'),
                'phone' => Schema::hasColumn('noci_users', 'phone'),
                'role' => Schema::hasColumn('noci_users', 'role'),
                'status' => Schema::hasColumn('noci_users', 'status'),
                'default_pop' => Schema::hasColumn('noci_users', 'default_pop'),
            ];

            if (!$usersHas['tenant_id']) {
                // Without tenant_id on users, we can't safely scope; skip runtime sync.
                return;
            }

            $teamHas = [
                'email' => Schema::hasColumn('noci_team', 'email'),
                'can_login' => Schema::hasColumn('noci_team', 'can_login'),
                'pop_id' => Schema::hasColumn('noci_team', 'pop_id'),
            ];

            $popsUsable = $teamHas['pop_id'] && Schema::hasTable('noci_pops')
                && Schema::hasColumn('noci_pops', 'tenant_id')
                && Schema::hasColumn('noci_pops', 'pop_name');

            // Find users missing a linked team row.
            $missing = DB::table('noci_users as u')
                ->when($usersHas['tenant_id'], fn ($q) => $q->where('u.tenant_id', $tenantId))
                ->leftJoin('noci_team as t', function ($join) use ($tenantId) {
                    $join->on('t.user_id', '=', 'u.id')
                        ->where('t.tenant_id', '=', $tenantId);
                })
                ->whereNull('t.id')
                ->select([
                    'u.id',
                    $usersHas['name'] ? 'u.name' : DB::raw('NULL as name'),
                    $usersHas['username'] ? 'u.username' : DB::raw('NULL as username'),
                    $usersHas['phone'] ? 'u.phone' : DB::raw('NULL as phone'),
                    $usersHas['email'] ? 'u.email' : DB::raw('NULL as email'),
                    $usersHas['role'] ? 'u.role' : DB::raw('NULL as role'),
                    $usersHas['status'] ? 'u.status' : DB::raw('NULL as status'),
                    $usersHas['default_pop'] ? 'u.default_pop' : DB::raw('NULL as default_pop'),
                ])
                ->limit(500)
                ->get();

            foreach ($missing as $u) {
                $name = trim((string) ($u->name ?? ''));
                if ($name === '') $name = trim((string) ($u->username ?? ''));
                if ($name === '') continue;

                $role = $this->normalizeLegacyRole((string) ($u->role ?? 'cs'));
                if ($role === '') $role = 'cs';

                $status = strtolower(trim((string) ($u->status ?? 'active')));
                $isActive = $status !== 'inactive';

                $payload = [
                    'tenant_id' => $tenantId,
                    'user_id' => (int) $u->id,
                    'name' => $name,
                    // Legacy `noci_team.phone` is often NOT NULL; keep it non-null.
                    'phone' => $this->normalizePhone($u->phone ?? null) ?? '',
                    'role' => $role,
                    'is_active' => $isActive ? 1 : 0,
                ];

                if ($teamHas['email']) {
                    $payload['email'] = $u->email ?? null;
                }

                if ($teamHas['can_login']) {
                    $payload['can_login'] = 1;
                }

                if ($teamHas['pop_id'] && $popsUsable) {
                    $defaultPop = trim((string) ($u->default_pop ?? ''));
                    if ($defaultPop !== '') {
                        $popId = DB::table('noci_pops')
                            ->where('tenant_id', $tenantId)
                            ->where('pop_name', $defaultPop)
                            ->value('id');
                        if (!empty($popId)) {
                            $payload['pop_id'] = (int) $popId;
                        }
                    }
                }

                // Attach to existing team row by name first (avoid duplicates), otherwise insert.
                $existing = DB::table('noci_team')
                    ->where('tenant_id', $tenantId)
                    ->where('name', $name)
                    ->whereNull('user_id')
                    ->first(['id']);

                if ($existing) {
                    DB::table('noci_team')->where('id', (int) $existing->id)->update($payload);
                } else {
                    DB::table('noci_team')->insert($payload);
                }
            }
        } catch (\Throwable) {
            // Best-effort; ignore sync failures.
        }
    }

    private function userCan(Request $request, string $permission): bool
    {
        $user = $request->user();
        if (!$user) return false;

        $legacyRole = $this->normalizeLegacyRole($user->role ?? null);

        // Always allow admin/owner even if RBAC hasn't been seeded yet.
        if (in_array($legacyRole, ['admin', 'owner'], true)) return true;

        // Legacy wildcard permission for Team module.
        if (method_exists($user, 'can') && $user->can('manage team')) return true;

        return method_exists($user, 'can') && $user->can($permission);
    }

    private function requirePermission(Request $request, string $permission): ?JsonResponse
    {
        if ($this->userCan($request, $permission)) return null;
        return response()->json(['message' => 'Forbidden'], 403);
    }

    private function normalizePhone(?string $phone): ?string
    {
        $clean = preg_replace('/[^0-9]/', '', (string) $phone);
        if ($clean === '') return null;

        if (str_starts_with($clean, '08')) {
            return '62' . substr($clean, 1);
        }
        if (str_starts_with($clean, '8')) {
            return '62' . $clean;
        }
        if (str_starts_with($clean, '0')) {
            return '62' . substr($clean, 1);
        }

        return $clean;
    }

    private function popName(int $tenantId, ?int $popId): ?string
    {
        $popId = (int) ($popId ?? 0);
        if ($popId <= 0) return null;

        return Pop::forTenant($tenantId)->where('id', $popId)->value('pop_name');
    }

    private function generateUniqueUsername(string $name): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
        $base = substr($base, 0, 30);
        if ($base === '') $base = 'user';

        $candidate = $base;
        $i = 1;

        while (NociUser::where('username', $candidate)->exists()) {
            $suffix = (string) $i;
            $candidate = substr($base, 0, max(1, 30 - strlen($suffix))) . $suffix;
            $i++;
            if ($i > 9999) {
                $candidate = $base . random_int(10000, 99999);
                break;
            }
        }

        return $candidate;
    }

    private function upsertLoginUserFromTeam(
        int $tenantId,
        ?NociUser $user,
        array $data,
        ?string $password = null
    ): NociUser {
        $roleName = (string) ($data['role'] ?? '');
        $isActive = (bool) ($data['is_active'] ?? true);
        $canLogin = (bool) ($data['can_login'] ?? false);

        if (!$user) {
            $username = $this->generateUniqueUsername((string) ($data['name'] ?? 'user'));
            $user = new NociUser();
            $user->tenant_id = $tenantId;
            $user->username = $username;
        }

        $user->name = (string) ($data['name'] ?? $user->name ?? $user->username);
        $user->email = $data['email'] ?? null;
        $user->phone = $data['phone'] ?? null;
        $user->role = $roleName !== '' ? $roleName : ($user->role ?? 'cs');
        $user->default_pop = $data['default_pop'] ?? null;
        $user->status = ($canLogin && $isActive) ? 'active' : 'inactive';

        if ($password !== null && $password !== '') {
            $user->password = Hash::make($password);
        } elseif (!$user->exists) {
            throw ValidationException::withMessages([
                'password' => 'Password wajib diisi untuk akun login baru.',
            ]);
        }

        // Ensure the role exists before syncing. This does not override permissions.
        if ($roleName !== '') {
            Role::findOrCreate($roleName, 'web');
        }

        $user->save();

        if ($roleName !== '') {
            $user->syncRoles([$roleName]);
        }

        return $user;
    }

    /**
     * List team members with filters.
     */
    public function index(Request $request): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'view team')) return $resp;
        if ($resp = $this->requireTeamSchema()) return $resp;

        $tenantId = $this->tenantId($request);
        $perPage = (int) $request->input('per_page', 50);

        // Keep Team UI in sync with legacy "users-only" systems by auto-linking missing users.
        $this->maybeSyncFromUsers($tenantId);

        $select = [
            'id',
            'tenant_id',
            'name',
            'phone',
            'email',
            'role',
            'pop_id',
            'is_active',
            'can_login',
            'notes',
        ];

        if ($this->hasTeamUserIdColumn()) {
            array_splice($select, 2, 0, ['user_id']);
        }

        $query = Team::forTenant($tenantId)->select($select);

        // Role filter
        if ($role = $request->input('role')) {
            $query->where('role', $role);
        }

        // Status filter
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Search
        if ($q = $request->input('q')) {
            $query->where(function ($qb) use ($q) {
                $qb->where('name', 'like', "%{$q}%")
                   ->orWhere('phone', 'like', "%{$q}%")
                   ->orWhere('email', 'like', "%{$q}%");
            });
        }

        // Sort (whitelist to avoid SQL injection)
        $allowedSortBy = ['id', 'name', 'phone', 'email', 'role', 'pop_id', 'is_active', 'can_login'];
        $sortBy = (string) $request->input('sort_by', 'name');
        $sortDir = strtolower((string) $request->input('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        if (!in_array($sortBy, $allowedSortBy, true)) $sortBy = 'name';

        $team = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

        return response()->json([
            'data' => $team->items(),
            'meta' => [
                'current_page' => $team->currentPage(),
                'last_page' => $team->lastPage(),
                'per_page' => $team->perPage(),
                'total' => $team->total(),
            ],
        ]);
    }

    /**
     * Get team statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'view team')) return $resp;
        if ($resp = $this->requireTeamSchema()) return $resp;

        $tenantId = $this->tenantId($request);

        $this->maybeSyncFromUsers($tenantId);

        $stats = Cache::remember("tenant:{$tenantId}:team:stats", 30, function () use ($tenantId) {
            $base = Team::forTenant($tenantId);

            $total = (int) (clone $base)->count();
            $active = (int) (clone $base)->where('is_active', 1)->count();
            $inactive = $total - $active;

            $byRole = (clone $base)
                ->select('role', DB::raw('COUNT(*) as total'))
                ->groupBy('role')
                ->pluck('total', 'role')
                ->map(fn ($v) => (int) $v)
                ->toArray();

            return [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
                'by_role' => $byRole,
            ];
        });

        return response()->json($stats);
    }

    /**
     * Show single team member.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'view team')) return $resp;
        if ($resp = $this->requireTeamSchema()) return $resp;

        $tenantId = $this->tenantId($request);
        $member = Team::forTenant($tenantId)->findOrFail($id);

        return response()->json(['data' => $member]);
    }

    /**
     * Create new team member.
     */
    public function store(Request $request): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'create team')) return $resp;
        if ($resp = $this->requireTeamSchema()) return $resp;

        $tenantId = $this->tenantId($request);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:100',
            'role' => 'required|string|max:50',
            'pop_id' => 'nullable|integer|exists:noci_pops,id',
            'is_active' => 'nullable|boolean',
            'can_login' => 'nullable|boolean',
            'password' => 'nullable|string|min:4|max:255',
            'notes' => 'nullable|string',
        ]);

        // Legacy `noci_team.phone` is often NOT NULL; keep it non-null.
        $validated['phone'] = array_key_exists('phone', $validated)
            ? ($this->normalizePhone($validated['phone']) ?? '')
            : '';
        $validated['is_active'] = array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true;
        $validated['can_login'] = array_key_exists('can_login', $validated) ? (bool) $validated['can_login'] : false;

        $popId = array_key_exists('pop_id', $validated) ? (int) ($validated['pop_id'] ?? 0) : 0;
        $popId = $popId > 0 ? $popId : null;

        if ($popId !== null) {
            // Ensure POP belongs to tenant (exists rule alone doesn't check tenant_id).
            $validPop = Pop::forTenant($tenantId)->where('id', $popId)->exists();
            if (!$validPop) {
                throw ValidationException::withMessages(['pop_id' => 'POP tidak valid untuk tenant ini.']);
            }
        }

        if ($validated['can_login'] && !$this->hasTeamUserIdColumn()) {
            return response()->json([
                'message' => 'Fitur akun login membutuhkan kolom user_id di noci_team. Jalankan migration terbaru.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $userId = null;

            if ($validated['can_login']) {
                $defaultPop = $this->popName($tenantId, $popId);

                $user = $this->upsertLoginUserFromTeam($tenantId, null, [
                    'name' => $validated['name'],
                    'email' => $validated['email'] ?? null,
                    'phone' => $validated['phone'] ?? null,
                    'role' => $validated['role'],
                    'default_pop' => $defaultPop,
                    'is_active' => $validated['is_active'],
                    'can_login' => true,
                ], (string) ($validated['password'] ?? ''));

                $userId = $user->id;
            }

            $memberPayload = [
                'tenant_id' => $tenantId,
                'name' => $validated['name'],
                'phone' => (string) ($validated['phone'] ?? ''),
                'email' => $validated['email'] ?? null,
                'role' => $validated['role'],
                'pop_id' => $popId,
                'is_active' => $validated['is_active'],
                'can_login' => $validated['can_login'],
                'notes' => $validated['notes'] ?? null,
            ];

            if ($this->hasTeamUserIdColumn()) {
                $memberPayload['user_id'] = $userId;
            }

            $member = Team::create($memberPayload);

            ActionLog::record(
                tenantId: $tenantId,
                userId: $request->user()?->id ? (int) $request->user()->id : null,
                action: 'CREATE',
                refType: 'team',
                refId: $member->id,
                payload: ['name' => $validated['name'], 'role' => $validated['role'], 'user_id' => $userId]
            );

            DB::commit();
            Cache::forget("tenant:{$tenantId}:team:stats");

            return response()->json([
                'message' => 'Team member created successfully',
                'data' => $member,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Update team member.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'edit team')) return $resp;
        if ($resp = $this->requireTeamSchema()) return $resp;

        $tenantId = $this->tenantId($request);
        $member = Team::forTenant($tenantId)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:100',
            'role' => 'nullable|string|max:50',
            'pop_id' => 'nullable|integer|exists:noci_pops,id',
            'is_active' => 'nullable|boolean',
            'can_login' => 'nullable|boolean',
            'password' => 'nullable|string|min:4|max:255',
            'notes' => 'nullable|string',
        ]);

        if (array_key_exists('phone', $validated)) {
            // Legacy `noci_team.phone` is often NOT NULL; keep it non-null.
            $validated['phone'] = $this->normalizePhone($validated['phone']) ?? '';
        }

        $nextName = array_key_exists('name', $validated) ? (string) $validated['name'] : (string) $member->name;
        $nextEmail = array_key_exists('email', $validated) ? ($validated['email'] ?? null) : ($member->email ?? null);
        $nextPhone = array_key_exists('phone', $validated) ? (string) ($validated['phone'] ?? '') : (string) ($member->phone ?? '');
        $nextRole = array_key_exists('role', $validated) ? (string) $validated['role'] : (string) $member->role;
        $nextIsActive = array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : (bool) $member->is_active;
        $nextCanLogin = array_key_exists('can_login', $validated) ? (bool) $validated['can_login'] : (bool) $member->can_login;
        $password = (string) ($validated['password'] ?? '');

        $popId = array_key_exists('pop_id', $validated) ? (int) ($validated['pop_id'] ?? 0) : (int) ($member->pop_id ?? 0);
        $popId = $popId > 0 ? $popId : null;

        if ($popId !== null) {
            $validPop = Pop::forTenant($tenantId)->where('id', $popId)->exists();
            if (!$validPop) {
                throw ValidationException::withMessages(['pop_id' => 'POP tidak valid untuk tenant ini.']);
            }
        }

        if ($nextCanLogin && !$this->hasTeamUserIdColumn()) {
            return response()->json([
                'message' => 'Fitur akun login membutuhkan kolom user_id di noci_team. Jalankan migration terbaru.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $userId = $this->hasTeamUserIdColumn() ? (int) ($member->user_id ?? 0) : 0;
            $userId = $userId > 0 ? $userId : null;

            if ($nextCanLogin) {
                $defaultPop = $this->popName($tenantId, $popId);

                $user = $userId ? NociUser::find($userId) : null;

                $user = $this->upsertLoginUserFromTeam($tenantId, $user, [
                    'name' => $nextName,
                    'email' => $nextEmail,
                    'phone' => $nextPhone,
                    'role' => $nextRole,
                    'default_pop' => $defaultPop,
                    'is_active' => $nextIsActive,
                    'can_login' => true,
                ], $password !== '' ? $password : null);

                $userId = $user->id;
            } elseif ($userId) {
                // Disable login access without deleting the account.
                $user = NociUser::find($userId);
                if ($user) {
                    $user->status = 'inactive';
                    $user->remember_token = null;
                    $user->save();
                }
            }

            $memberPayload = [
                'name' => $nextName,
                'phone' => $nextPhone,
                'email' => $nextEmail,
                'role' => $nextRole,
                'pop_id' => $popId,
                'is_active' => $nextIsActive,
                'can_login' => $nextCanLogin,
                'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : ($member->notes ?? null),
            ];

            if ($this->hasTeamUserIdColumn()) {
                $memberPayload['user_id'] = $userId;
            }

            $member->update($memberPayload);

            ActionLog::record(
                tenantId: $tenantId,
                userId: $request->user()?->id ? (int) $request->user()->id : null,
                action: 'UPDATE',
                refType: 'team',
                refId: $id,
                payload: ['name' => $member->name, 'role' => $nextRole, 'user_id' => $userId]
            );

            DB::commit();
            Cache::forget("tenant:{$tenantId}:team:stats");

            return response()->json([
                'message' => 'Team member updated successfully',
                'data' => $member->fresh(),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Delete team member.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'delete team')) return $resp;
        if ($resp = $this->requireTeamSchema()) return $resp;

        $tenantId = $this->tenantId($request);
        $member = Team::forTenant($tenantId)->findOrFail($id);

        // Check if this member is assigned to any active installations
        $activeInstalls = Installation::forTenant($tenantId)
            ->active()
            ->forTechnician($member->name)
            ->count();

        if ($activeInstalls > 0) {
            return response()->json([
                'message' => 'Cannot delete team member with active installations. Deactivate instead.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $userId = $this->hasTeamUserIdColumn() ? (int) ($member->user_id ?? 0) : 0;
            $userId = $userId > 0 ? $userId : null;

            ActionLog::record(
                tenantId: $tenantId,
                userId: $request->user()?->id ? (int) $request->user()->id : null,
                action: 'DELETE',
                refType: 'team',
                refId: $id,
                payload: ['name' => $member->name, 'role' => $member->role, 'user_id' => $userId]
            );

            $member->delete();

            if ($userId) {
                $user = NociUser::find($userId);
                if ($user) {
                    // Best-effort cleanup of RBAC relations.
                    try {
                        $user->syncRoles([]);
                    } catch (\Throwable) {
                        // ignore
                    }
                    $user->remember_token = null;
                    $user->delete();
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        Cache::forget("tenant:{$tenantId}:team:stats");

        return response()->json(['message' => 'Team member deleted successfully']);
    }

    /**
     * Toggle active status.
     */
    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'edit team')) return $resp;
        if ($resp = $this->requireTeamSchema()) return $resp;

        $tenantId = $this->tenantId($request);

        $member = Team::forTenant($tenantId)->findOrFail($id);
        $member->update(['is_active' => !$member->is_active]);

        // Sync login status if linked to a user.
        if ($this->hasTeamUserIdColumn() && !empty($member->user_id)) {
            $user = NociUser::find((int) $member->user_id);
            if ($user) {
                $user->status = ($member->can_login && $member->is_active) ? 'active' : 'inactive';
                if ($user->status !== 'active') {
                    $user->remember_token = null;
                }
                $user->save();
            }
        }

        Cache::forget("tenant:{$tenantId}:team:stats");

        return response()->json([
            'message' => $member->is_active ? 'Member activated' : 'Member deactivated',
            'data' => $member->fresh(),
        ]);
    }

    /**
     * Get technicians list (active only).
     */
    public function technicians(Request $request): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'view team')) return $resp;
        if ($resp = $this->requireTeamSchema()) return $resp;

        $tenantId = $this->tenantId($request);

        $this->maybeSyncFromUsers($tenantId);

        $technicians = Team::forTenant($tenantId)
            ->active()
            ->technicians()
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'pop_id']);

        return response()->json(['data' => $technicians]);
    }

    /**
     * Get sales list (active only).
     */
    public function sales(Request $request): JsonResponse
    {
        if ($resp = $this->requirePermission($request, 'view team')) return $resp;
        if ($resp = $this->requireTeamSchema()) return $resp;

        $tenantId = $this->tenantId($request);

        $this->maybeSyncFromUsers($tenantId);

        $sales = Team::forTenant($tenantId)
            ->active()
            ->sales()
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'pop_id']);

        return response()->json(['data' => $sales]);
    }
}
