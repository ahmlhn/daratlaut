<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\BillingCustomer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        if (!Schema::hasTable('noci_billing_customers')) {
            return response()->json([
                'total' => 0,
                'active' => 0,
                'suspended' => 0,
                'inactive' => 0,
            ]);
        }

        $tenantId = (int) $request->attributes->get('tenant_id', 1);

        $row = BillingCustomer::query()
            ->where('tenant_id', $tenantId)
            ->selectRaw("\n                COUNT(*) as total,\n                SUM(CASE WHEN service_status = 'AKTIF' THEN 1 ELSE 0 END) as active,\n                SUM(CASE WHEN service_status = 'SUSPEND' THEN 1 ELSE 0 END) as suspended,\n                SUM(CASE WHEN service_status = 'NONAKTIF' THEN 1 ELSE 0 END) as inactive\n            ")
            ->first();

        return response()->json([
            'total' => (int) ($row->total ?? 0),
            'active' => (int) ($row->active ?? 0),
            'suspended' => (int) ($row->suspended ?? 0),
            'inactive' => (int) ($row->inactive ?? 0),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant_id', 1);

        $perPage = (int) $request->query('per_page', 20);
        if ($perPage < 5) {
            $perPage = 5;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }

        $q = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));
        $planId = $request->query('plan_id');
        $popName = trim((string) $request->query('pop_name', ''));

        $query = BillingCustomer::query()->where('tenant_id', $tenantId);

        if ($q !== '') {
            $like = '%' . $q . '%';
            $query->where(function ($qb) use ($like) {
                $qb->where('customer_code', 'like', $like)
                    ->orWhere('full_name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('address', 'like', $like)
                    ->orWhere('pop_name', 'like', $like)
                    ->orWhere('odp_name', 'like', $like);
            });
        }

        if ($status !== '') {
            $query->where('service_status', $status);
        }

        if ($planId !== null && $planId !== '') {
            $query->where('plan_id', (int) $planId);
        }

        if ($popName !== '') {
            $query->where('pop_name', $popName);
        }

        $customers = $query
            ->with('plan:id,name,price')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $customers->items(),
            'meta' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant_id', 1);

        $customer = BillingCustomer::query()
            ->where('tenant_id', $tenantId)
            ->with('plan:id,name,price')
            ->findOrFail($id);

        return response()->json(['data' => $customer]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant_id', 1);

        $validated = $request->validate([
            'customer_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('noci_billing_customers', 'customer_code')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'full_name' => 'required|string|max:150',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:120',
            'address' => 'nullable|string',
            'plan_id' => 'nullable|integer|exists:noci_billing_plans,id',
            'service_status' => ['nullable', Rule::in(['AKTIF', 'SUSPEND', 'NONAKTIF'])],
            'billing_day' => 'nullable|integer|min:1|max:28',
            'grace_days' => 'nullable|integer|min:0',
            'pop_name' => 'nullable|string|max:120',
            'odp_name' => 'nullable|string|max:120',
            'notes' => 'nullable|string',
        ]);

        $validated['tenant_id'] = $tenantId;
        $validated['service_status'] = $validated['service_status'] ?? 'AKTIF';
        $validated['billing_day'] = $validated['billing_day'] ?? 1;

        $customer = BillingCustomer::create($validated);

        try {
            ActionLog::record($tenantId, $request->user()?->id, 'CREATE', 'Customer', $customer->id, [
                'name' => $customer->full_name,
                'code' => $customer->customer_code,
            ]);
        } catch (\Throwable) {
        }

        return response()->json([
            'success' => true,
            'message' => 'Customer created successfully',
            'data' => $customer->load('plan:id,name,price'),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant_id', 1);

        $customer = BillingCustomer::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $validated = $request->validate([
            'customer_code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('noci_billing_customers', 'customer_code')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId))
                    ->ignore($customer->id),
            ],
            'full_name' => 'sometimes|required|string|max:150',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:120',
            'address' => 'nullable|string',
            'plan_id' => 'nullable|integer|exists:noci_billing_plans,id',
            'service_status' => ['nullable', Rule::in(['AKTIF', 'SUSPEND', 'NONAKTIF'])],
            'billing_day' => 'nullable|integer|min:1|max:28',
            'grace_days' => 'nullable|integer|min:0',
            'pop_name' => 'nullable|string|max:120',
            'odp_name' => 'nullable|string|max:120',
            'notes' => 'nullable|string',
        ]);

        $customer->update($validated);

        try {
            ActionLog::record($tenantId, $request->user()?->id, 'UPDATE', 'Customer', $customer->id, [
                'name' => $customer->full_name,
                'code' => $customer->customer_code,
            ]);
        } catch (\Throwable) {
        }

        return response()->json([
            'success' => true,
            'message' => 'Customer updated successfully',
            'data' => $customer->fresh()->load('plan:id,name,price'),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant_id', 1);

        $customer = BillingCustomer::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $customer->delete();

        try {
            ActionLog::record($tenantId, $request->user()?->id, 'DELETE', 'Customer', $id, [
                'code' => $customer->customer_code,
            ]);
        } catch (\Throwable) {
        }

        return response()->json(['success' => true]);
    }
}
