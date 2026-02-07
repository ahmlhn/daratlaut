<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\MikrotikIsolirService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IsolirController extends Controller
{
    protected MikrotikIsolirService $mikrotik;

    public function __construct(MikrotikIsolirService $mikrotik)
    {
        $this->mikrotik = $mikrotik;
    }

    /**
     * Check MikroTik integration status
     */
    public function status(): JsonResponse
    {
        return response()->json([
            'enabled' => $this->mikrotik->isEnabled(),
            'message' => $this->mikrotik->isEnabled() 
                ? 'MikroTik integration active' 
                : 'MikroTik integration not configured'
        ]);
    }

    /**
     * Suspend a customer
     */
    public function suspend(Request $request, int $customerId): JsonResponse
    {
        $customer = Customer::findOrFail($customerId);
        
        if (!$customer->pppoe_username) {
            return response()->json([
                'success' => false,
                'message' => 'Customer has no PPPoE username'
            ], 400);
        }

        // Suspend via MikroTik
        $result = $this->mikrotik->suspend($customer->pppoe_username);

        if ($result['success']) {
            // Update customer status
            $customer->update(['status' => 'suspended']);
        }

        return response()->json($result);
    }

    /**
     * Unsuspend/activate a customer
     */
    public function unsuspend(Request $request, int $customerId): JsonResponse
    {
        $customer = Customer::findOrFail($customerId);
        
        if (!$customer->pppoe_username) {
            return response()->json([
                'success' => false,
                'message' => 'Customer has no PPPoE username'
            ], 400);
        }

        // Unsuspend via MikroTik
        $result = $this->mikrotik->unsuspend($customer->pppoe_username);

        if ($result['success']) {
            // Update customer status
            $customer->update(['status' => 'active']);
        }

        return response()->json($result);
    }

    /**
     * Check if customer is online
     */
    public function online(int $customerId): JsonResponse
    {
        $customer = Customer::findOrFail($customerId);
        
        if (!$customer->pppoe_username) {
            return response()->json([
                'online' => false,
                'message' => 'Customer has no PPPoE username'
            ]);
        }

        $result = $this->mikrotik->isOnline($customer->pppoe_username);
        
        return response()->json([
            'online' => $result['online'],
            'customer_id' => $customerId,
            'pppoe_username' => $customer->pppoe_username,
            'data' => $result['data'] ?? null
        ]);
    }

    /**
     * Get PPPoE secret status
     */
    public function secretStatus(int $customerId): JsonResponse
    {
        $customer = Customer::findOrFail($customerId);
        
        if (!$customer->pppoe_username) {
            return response()->json([
                'found' => false,
                'message' => 'Customer has no PPPoE username'
            ]);
        }

        $status = $this->mikrotik->getSecretStatus($customer->pppoe_username);

        return response()->json([
            'found' => $status !== null,
            'customer_id' => $customerId,
            'pppoe_username' => $customer->pppoe_username,
            'status' => $status
        ]);
    }

    /**
     * Bulk suspend customers
     */
    public function bulkSuspend(Request $request): JsonResponse
    {
        $request->validate([
            'customer_ids' => 'required|array|min:1',
            'customer_ids.*' => 'integer|exists:bil_customers,id'
        ]);

        $customers = Customer::whereIn('id', $request->customer_ids)
            ->whereNotNull('pppoe_username')
            ->get();

        $usernames = $customers->pluck('pppoe_username')->toArray();
        $result = $this->mikrotik->bulkSuspend($usernames);

        // Update suspended customers status
        if (!empty($result['success'])) {
            Customer::whereIn('pppoe_username', $result['success'])
                ->update(['status' => 'suspended']);
        }

        return response()->json([
            'total' => count($request->customer_ids),
            'success_count' => count($result['success']),
            'failed_count' => count($result['failed']),
            'details' => $result
        ]);
    }

    /**
     * Bulk unsuspend customers  
     */
    public function bulkUnsuspend(Request $request): JsonResponse
    {
        $request->validate([
            'customer_ids' => 'required|array|min:1',
            'customer_ids.*' => 'integer|exists:bil_customers,id'
        ]);

        $customers = Customer::whereIn('id', $request->customer_ids)
            ->whereNotNull('pppoe_username')
            ->get();

        $usernames = $customers->pluck('pppoe_username')->toArray();
        $result = $this->mikrotik->bulkUnsuspend($usernames);

        // Update activated customers status
        if (!empty($result['success'])) {
            Customer::whereIn('pppoe_username', $result['success'])
                ->update(['status' => 'active']);
        }

        return response()->json([
            'total' => count($request->customer_ids),
            'success_count' => count($result['success']),
            'failed_count' => count($result['failed']),
            'details' => $result
        ]);
    }
}
