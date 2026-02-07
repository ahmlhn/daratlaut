<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BillingPlan as Plan;
use App\Models\ActionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);
        $perPage = (int) $request->input('per_page', 50);
        
        $query = Plan::where('tenant_id', $tenantId);
        
        // Filter active only
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }
        
        $plans = $query->orderBy('price')->paginate($perPage);
        
        return response()->json([
            'data' => $plans->items(),
            'meta' => [
                'current_page' => $plans->currentPage(),
                'last_page' => $plans->lastPage(),
                'per_page' => $plans->perPage(),
                'total' => $plans->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'required|integer',
            'code' => 'required|string|max:50',
            'name' => 'required|string|max:100',
            'price' => 'required|numeric|min:0',
            'billing_cycle' => 'nullable|string|max:20',
            'is_active' => 'nullable|boolean',
        ]);
        
        $tenantId = $validated['tenant_id'];
        
        $plan = Plan::create([
            'tenant_id' => $tenantId,
            'code' => $validated['code'],
            'name' => $validated['name'],
            'price' => $validated['price'],
            'billing_cycle' => $validated['billing_cycle'] ?? 'monthly',
            'is_active' => $validated['is_active'] ?? true,
        ]);
        
        ActionLog::record(
            tenantId: $tenantId,
            action: 'CREATE',
            refType: 'plan',
            refId: $plan->id,
            payload: ['name' => $plan->name, 'price' => $plan->price]
        );
        
        return response()->json([
            'message' => 'Plan created successfully',
            'data' => $plan,
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);
        
        $plan = Plan::where('tenant_id', $tenantId)->findOrFail($id);
        
        return response()->json(['data' => $plan]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'required|integer',
            'code' => 'nullable|string|max:50',
            'name' => 'nullable|string|max:100',
            'price' => 'nullable|numeric|min:0',
            'billing_cycle' => 'nullable|string|max:20',
            'is_active' => 'nullable|boolean',
        ]);
        
        $tenantId = $validated['tenant_id'];
        
        $plan = Plan::where('tenant_id', $tenantId)->findOrFail($id);
        
        $plan->update(array_filter([
            'code' => $validated['code'] ?? null,
            'name' => $validated['name'] ?? null,
            'price' => $validated['price'] ?? null,
            'billing_cycle' => $validated['billing_cycle'] ?? null,
            'is_active' => $validated['is_active'] ?? null,
        ], fn($v) => $v !== null));
        
        ActionLog::record(
            tenantId: $tenantId,
            action: 'UPDATE',
            refType: 'plan',
            refId: $plan->id,
            payload: ['name' => $plan->name]
        );
        
        return response()->json([
            'message' => 'Plan updated successfully',
            'data' => $plan,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);
        
        $plan = Plan::where('tenant_id', $tenantId)->findOrFail($id);
        
        // Check if plan has customers
        if ($plan->customers()->count() > 0) {
            return response()->json(['message' => 'Cannot delete plan with active customers'], 422);
        }
        
        ActionLog::record(
            tenantId: $tenantId,
            action: 'DELETE',
            refType: 'plan',
            refId: $id,
            payload: ['name' => $plan->name]
        );
        
        $plan->delete();
        
        return response()->json(['message' => 'Plan deleted successfully']);
    }
}
