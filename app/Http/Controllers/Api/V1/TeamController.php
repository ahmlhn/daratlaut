<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\ActionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    /**
     * List team members with filters
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);
        $perPage = (int) $request->input('per_page', 50);

        $query = Team::forTenant($tenantId)
            ->select([
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
            ]);

        // Role filter
        if ($role = $request->input('role')) {
            $query->where('role', $role);
        }

        // Status filter
        if ($request->has('is_active')) {
            $isActive = $request->boolean('is_active');
            $query->where('is_active', $isActive);
        }

        // Search
        if ($q = $request->input('q')) {
            $query->where(function ($qb) use ($q) {
                $qb->where('name', 'like', "%{$q}%")
                   ->orWhere('phone', 'like', "%{$q}%")
                   ->orWhere('email', 'like', "%{$q}%");
            });
        }

        // Sort
        $sortBy = $request->input('sort_by', 'name');
        $sortDir = $request->input('sort_dir', 'asc');
        $query->orderBy($sortBy, $sortDir);

        $team = $query->paginate($perPage);

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
     * Get team statistics
     */
    public function stats(Request $request): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);

        $stats = Cache::remember("tenant:{$tenantId}:team:stats", 30, function () use ($tenantId) {
            return Team::forTenant($tenantId)
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
                    SUM(CASE WHEN role = 'teknisi' THEN 1 ELSE 0 END) as teknisi,
                    SUM(CASE WHEN role = 'sales' THEN 1 ELSE 0 END) as sales,
                    SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admin,
                    SUM(CASE WHEN role = 'cs' THEN 1 ELSE 0 END) as cs,
                    SUM(CASE WHEN role = 'owner' THEN 1 ELSE 0 END) as owner
                ")
                ->first();
        });

        return response()->json([
            'total' => (int) $stats->total,
            'active' => (int) $stats->active,
            'inactive' => (int) $stats->inactive,
            'by_role' => [
                'teknisi' => (int) $stats->teknisi,
                'sales' => (int) $stats->sales,
                'admin' => (int) $stats->admin,
                'cs' => (int) $stats->cs,
                'owner' => (int) $stats->owner,
            ],
        ]);
    }

    /**
     * Show single team member
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);

        $member = Team::forTenant($tenantId)->findOrFail($id);

        return response()->json(['data' => $member]);
    }

    /**
     * Create new team member
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);

        $validated = $request->validate([
            'tenant_id' => 'required|integer',
            'name' => 'required|string|max:100',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:100',
            'role' => ['required', Rule::in(['teknisi', 'sales', 'admin', 'cs', 'owner', 'keuangan'])],
            'pop_id' => 'nullable|integer|exists:noci_pops,id',
            'is_active' => 'nullable|boolean',
            'can_login' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);

        // Normalize phone
        if (!empty($validated['phone'])) {
            $validated['phone'] = $this->normalizePhone($validated['phone']);
        }

        $member = Team::create([
            ...$validated,
            'is_active' => $validated['is_active'] ?? true,
            'can_login' => $validated['can_login'] ?? false,
        ]);

        // Log action
        ActionLog::record(
            tenantId: $tenantId,
            action: 'CREATE',
            refType: 'team',
            refId: $member->id,
            payload: ['name' => $validated['name'], 'role' => $validated['role']]
        );

        return response()->json([
            'message' => 'Team member created successfully',
            'data' => $member,
        ], 201);
    }

    /**
     * Update team member
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);

        $member = Team::forTenant($tenantId)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:100',
            'role' => ['nullable', Rule::in(['teknisi', 'sales', 'admin', 'cs', 'owner', 'keuangan'])],
            'pop_id' => 'nullable|integer|exists:noci_pops,id',
            'is_active' => 'nullable|boolean',
            'can_login' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);

        // Normalize phone
        if (!empty($validated['phone'])) {
            $validated['phone'] = $this->normalizePhone($validated['phone']);
        }

        $member->update($validated);

        // Log action
        ActionLog::record(
            tenantId: $tenantId,
            action: 'UPDATE',
            refType: 'team',
            refId: $id,
            payload: ['name' => $member->name]
        );

        return response()->json([
            'message' => 'Team member updated successfully',
            'data' => $member->fresh(),
        ]);
    }

    /**
     * Delete team member
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);

        $member = Team::forTenant($tenantId)->findOrFail($id);

        // Check if this member is assigned to any active installations
        $activeInstalls = \App\Models\Installation::forTenant($tenantId)
            ->active()
            ->forTechnician($member->name)
            ->count();

        if ($activeInstalls > 0) {
            return response()->json([
                'message' => 'Cannot delete team member with active installations. Deactivate instead.'
            ], 422);
        }

        // Log before delete
        ActionLog::record(
            tenantId: $tenantId,
            action: 'DELETE',
            refType: 'team',
            refId: $id,
            payload: ['name' => $member->name]
        );

        $member->delete();

        return response()->json(['message' => 'Team member deleted successfully']);
    }

    /**
     * Toggle active status
     */
    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);

        $member = Team::forTenant($tenantId)->findOrFail($id);
        $member->update(['is_active' => !$member->is_active]);

        return response()->json([
            'message' => $member->is_active ? 'Member activated' : 'Member deactivated',
            'data' => $member->fresh(),
        ]);
    }

    /**
     * Get technicians list (active only)
     */
    public function technicians(Request $request): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);

        $technicians = Team::forTenant($tenantId)
            ->active()
            ->technicians()
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'pop_id']);

        return response()->json(['data' => $technicians]);
    }

    /**
     * Get sales list (active only)
     */
    public function sales(Request $request): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);

        $sales = Team::forTenant($tenantId)
            ->active()
            ->sales()
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'pop_id']);

        return response()->json(['data' => $sales]);
    }

    /**
     * Normalize phone number to 62 prefix
     */
    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (str_starts_with($phone, '08')) {
            $phone = '62' . substr($phone, 1);
        } elseif (str_starts_with($phone, '8')) {
            $phone = '62' . $phone;
        } elseif (str_starts_with($phone, '+62')) {
            $phone = substr($phone, 1);
        }

        return $phone;
    }
}
