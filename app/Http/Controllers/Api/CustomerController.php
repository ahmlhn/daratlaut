<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\BillingCustomer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    /**
     * Display a listing of customers.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id', 1);
        $perPage = $request->input('per_page', 20);
        $status = $request->input('status');
        $search = $request->input('q');

        $query = BillingCustomer::forTenant($tenantId)
            ->with('plan:id,name,price');

        if ($status) {
            $query->where('service_status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('customer_code', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $customers = $query->orderBy('full_name')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $customers->items(),
            'meta' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
            ],
        ]);
    }

    /**
     * Store a newly created customer.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id', 1);

        $validated = $request->validate([
            'customer_code' => [
                'required',
                'string',
                'max:60',
                Rule::unique('noci_billing_customers')->where('tenant_id', $tenantId),
            ],
            'full_name' => 'required|string|max:150',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:120',
            'address' => 'nullable|string',
            'plan_id' => 'nullable|exists:noci_billing_plans,id',
            'service_status' => 'nullable|in:AKTIF,SUSPEND,NONAKTIF',
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

        // Log activity
        ActionLog::record(
            $tenantId,
            auth()->id(),
            'CREATE',
            'Customer',
            $customer->id,
            ['name' => $customer->full_name, 'code' => $customer->customer_code]
        );

        return response()->json([
            'success' => true,
            'message' => 'Customer created successfully',
            'data' => $customer->load('plan:id,name,price'),
        ], 201);
    }

    /**
     * Display the specified customer.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id', 1);

        $customer = BillingCustomer::forTenant($tenantId)
            ->with(['plan:id,name,price', 'invoices' => function ($q) {
                $q->latest()->limit(10);
            }])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $customer,
        ]);
    }

    /**
     * Update the specified customer.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id', 1);

        $customer = BillingCustomer::forTenant($tenantId)->findOrFail($id);

        $validated = $request->validate([
            'customer_code' => [
                'sometimes',
                'string',
                'max:60',
                Rule::unique('noci_billing_customers')
                    ->where('tenant_id', $tenantId)
                    ->ignore($customer->id),
            ],
            'full_name' => 'sometimes|string|max:150',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:120',
            'address' => 'nullable|string',
            'plan_id' => 'nullable|exists:noci_billing_plans,id',
            'service_status' => 'nullable|in:AKTIF,SUSPEND,NONAKTIF',
            'billing_day' => 'nullable|integer|min:1|max:28',
            'grace_days' => 'nullable|integer|min:0',
            'pop_name' => 'nullable|string|max:120',
            'odp_name' => 'nullable|string|max:120',
            'notes' => 'nullable|string',
        ]);

        $customer->update($validated);

        // Log activity
        ActionLog::record(
            $tenantId,
            auth()->id(),
            'UPDATE',
            'Customer',
            $customer->id,
            ['name' => $customer->full_name, 'changes' => array_keys($validated)]
        );

        return response()->json([
            'success' => true,
            'message' => 'Customer updated successfully',
            'data' => $customer->fresh()->load('plan:id,name,price'),
        ]);
    }

    /**
     * Remove the specified customer.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id', 1);

        $customer = BillingCustomer::forTenant($tenantId)->findOrFail($id);

        // Check for invoices
        if ($customer->invoices()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete customer with existing invoices',
            ], 422);
        }

        $customerName = $customer->full_name;
        $customerId = $customer->id;
        $customer->delete();

        // Log activity
        ActionLog::record(
            $tenantId,
            auth()->id(),
            'DELETE',
            'Customer',
            $customerId,
            ['name' => $customerName]
        );

        return response()->json([
            'success' => true,
            'message' => 'Customer deleted successfully',
        ]);
    }

    /**
     * Get customer statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id', 1);

        $stats = [
            'total' => BillingCustomer::forTenant($tenantId)->count(),
            'aktif' => BillingCustomer::forTenant($tenantId)->active()->count(),
            'suspend' => BillingCustomer::forTenant($tenantId)->suspended()->count(),
            'nonaktif' => BillingCustomer::forTenant($tenantId)->where('service_status', 'NONAKTIF')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
