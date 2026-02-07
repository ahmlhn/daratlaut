<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardKpiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant_id');

        return response()->json([
            'tenant_id' => $tenantId,
            'data' => [
                'total_users' => 0,
                'active_users' => 0,
                'suspend_users' => 0,
                'nonactive_users' => 0,
                'open_invoices' => 0,
                'overdue_invoices' => 0,
            ],
        ]);
    }
}
