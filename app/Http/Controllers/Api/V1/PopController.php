<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Pop;
use App\Models\ActionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PopController extends Controller
{
    /**
     * List POPs with filters
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);
        $perPage = (int) $request->input('per_page', 50);

        $query = Pop::forTenant($tenantId)
            ->select([
                'id',
                'tenant_id',
                'name',
                'address',
                'coordinates',
                'description',
                'is_active',
            ]);

        // Status filter
        if ($request->has('is_active')) {
            $isActive = $request->boolean('is_active');
            $query->where('is_active', $isActive);
        }

        // Search
        if ($q = $request->input('q')) {
            $query->where(function ($qb) use ($q) {
                $qb->where('name', 'like', "%{$q}%")
                   ->orWhere('address', 'like', "%{$q}%")
                   ->orWhere('description', 'like', "%{$q}%");
            });
        }

        // Sort
        $sortBy = $request->input('sort_by', 'name');
        $sortDir = $request->input('sort_dir', 'asc');
        $query->orderBy($sortBy, $sortDir);

        if ($request->boolean('all')) {
            $pops = $query->get();
            return response()->json(['data' => $pops]);
        }

        $pops = $query->paginate($perPage);

        return response()->json([
            'data' => $pops->items(),
            'meta' => [
                'current_page' => $pops->currentPage(),
                'last_page' => $pops->lastPage(),
                'per_page' => $pops->perPage(),
                'total' => $pops->total(),
            ],
        ]);
    }

    /**
     * Get POP stats
     */
    public function stats(Request $request): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);

        $stats = Cache::remember("tenant:{$tenantId}:pops:stats", 30, function () use ($tenantId) {
            return Pop::forTenant($tenantId)
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive
                ")
                ->first();
        });

        return response()->json([
            'total' => (int) $stats->total,
            'active' => (int) $stats->active,
            'inactive' => (int) $stats->inactive,
        ]);
    }

    /**
     * Show single POP
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);

        $pop = Pop::forTenant($tenantId)
            ->withCount(['installations', 'team'])
            ->findOrFail($id);

        return response()->json(['data' => $pop]);
    }

    /**
     * Create new POP
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);

        $validated = $request->validate([
            'tenant_id' => 'required|integer',
            'name' => 'required|string|max:100',
            'address' => 'nullable|string',
            'coordinates' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $pop = Pop::create([
            ...$validated,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        // Log action
        ActionLog::record(
            tenantId: $tenantId,
            action: 'CREATE',
            refType: 'pop',
            refId: $pop->id,
            payload: ['name' => $validated['name']]
        );

        return response()->json([
            'message' => 'POP created successfully',
            'data' => $pop,
        ], 201);
    }

    /**
     * Update POP
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);

        $pop = Pop::forTenant($tenantId)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'address' => 'nullable|string',
            'coordinates' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $pop->update($validated);

        // Log action
        ActionLog::record(
            tenantId: $tenantId,
            action: 'UPDATE',
            refType: 'pop',
            refId: $id,
            payload: ['name' => $pop->name]
        );

        return response()->json([
            'message' => 'POP updated successfully',
            'data' => $pop->fresh(),
        ]);
    }

    /**
     * Delete POP
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);

        $pop = Pop::forTenant($tenantId)
            ->withCount(['installations', 'team'])
            ->findOrFail($id);

        // Check if POP has associated data
        if ($pop->installations_count > 0) {
            return response()->json([
                'message' => 'Cannot delete POP with associated installations'
            ], 422);
        }

        if ($pop->team_count > 0) {
            return response()->json([
                'message' => 'Cannot delete POP with associated team members'
            ], 422);
        }

        // Log before delete
        ActionLog::record(
            tenantId: $tenantId,
            action: 'DELETE',
            refType: 'pop',
            refId: $id,
            payload: ['name' => $pop->name]
        );

        $pop->delete();

        return response()->json(['message' => 'POP deleted successfully']);
    }

    /**
     * Get active POPs for dropdown
     */
    public function dropdown(Request $request): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant_id', (int) $request->input('tenant_id', 1));

        $pops = Pop::forTenant($tenantId)
            ->active()
            ->orderBy('pop_name')
            ->get([
                'id',
                \Illuminate\Support\Facades\DB::raw('pop_name as name'),
            ]);

        return response()->json(['data' => $pops]);
    }
}
