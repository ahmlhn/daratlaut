<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get unified dashboard data.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id', 1);

        $data = [
            'customers' => $this->getCustomerStats($tenantId),
            'invoices' => $this->getInvoiceStats($tenantId),
            'payments' => $this->getPaymentStats($tenantId),
            'activity' => $this->getRecentActivity($tenantId),
            'chart' => $this->getChartData($tenantId),
        ];

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get customer statistics.
     */
    private function getCustomerStats(int $tenantId): array
    {
        return [
            'total' => BillingCustomer::forTenant($tenantId)->count(),
            'aktif' => BillingCustomer::forTenant($tenantId)->active()->count(),
            'suspend' => BillingCustomer::forTenant($tenantId)->suspended()->count(),
            'nonaktif' => BillingCustomer::forTenant($tenantId)->where('service_status', 'NONAKTIF')->count(),
        ];
    }

    /**
     * Get invoice statistics.
     */
    private function getInvoiceStats(int $tenantId): array
    {
        return [
            'total' => BillingInvoice::forTenant($tenantId)->count(),
            'open' => BillingInvoice::forTenant($tenantId)->where('status', 'OPEN')->count(),
            'overdue' => BillingInvoice::forTenant($tenantId)->where('status', 'OVERDUE')->count(),
            'paid' => BillingInvoice::forTenant($tenantId)->where('status', 'PAID')->count(),
            'total_outstanding' => (float) (BillingInvoice::forTenant($tenantId)
                ->unpaid()
                ->selectRaw('COALESCE(SUM(total_amount - paid_amount), 0) as balance')
                ->value('balance') ?? 0),
        ];
    }

    /**
     * Get payment statistics for current month and comparison.
     */
    private function getPaymentStats(int $tenantId): array
    {
        $currentMonth = date('Y-m');
        $lastMonth = date('Y-m', strtotime('-1 month'));

        $currentTotal = BillingPayment::forTenant($tenantId)
            ->whereRaw("DATE_FORMAT(paid_at, '%Y-%m') = ?", [$currentMonth])
            ->sum('amount');

        $lastTotal = BillingPayment::forTenant($tenantId)
            ->whereRaw("DATE_FORMAT(paid_at, '%Y-%m') = ?", [$lastMonth])
            ->sum('amount');

        $currentCount = BillingPayment::forTenant($tenantId)
            ->whereRaw("DATE_FORMAT(paid_at, '%Y-%m') = ?", [$currentMonth])
            ->count();

        $change = $lastTotal > 0 ? round((($currentTotal - $lastTotal) / $lastTotal) * 100, 1) : 0;

        return [
            'total_this_month' => (float) $currentTotal,
            'count_this_month' => $currentCount,
            'total_last_month' => (float) $lastTotal,
            'change_percent' => $change,
        ];
    }

    /**
     * Get recent activity logs.
     */
    private function getRecentActivity(int $tenantId, int $limit = 10): array
    {
        $logs = ActionLog::forTenant($tenantId)
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $logs->map(function ($log) {
            return [
                'id' => $log->id,
                'action' => $log->action_name,
                'action_color' => $log->action_color,
                'ref_type' => $log->ref_type,
                'ref_id' => $log->ref_id,
                'description' => $log->description,
                'actor' => $log->actor?->name ?? 'System',
                'created_at' => $log->created_at->toIso8601String(),
                'time_ago' => $log->created_at->diffForHumans(),
            ];
        })->toArray();
    }

    /**
     * Get chart data (monthly payments for last 6 months).
     */
    private function getChartData(int $tenantId): array
    {
        $months = [];
        $payments = [];
        
        // Get last 6 months
        for ($i = 5; $i >= 0; $i--) {
            $date = date('Y-m', strtotime("-{$i} months"));
            $months[] = date('M Y', strtotime("-{$i} months"));

            $total = BillingPayment::forTenant($tenantId)
                ->whereRaw("DATE_FORMAT(paid_at, '%Y-%m') = ?", [$date])
                ->sum('amount');

            $payments[] = (float) $total;
        }

        return [
            'labels' => $months,
            'datasets' => [
                [
                    'label' => 'Pendapatan',
                    'data' => $payments,
                    'backgroundColor' => 'rgba(20, 184, 166, 0.8)',
                    'borderColor' => 'rgb(20, 184, 166)',
                    'borderWidth' => 1,
                ],
            ],
        ];
    }

    /**
     * Get activity logs with pagination.
     */
    public function activity(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id', 1);
        $perPage = $request->input('per_page', 20);
        $refType = $request->input('ref_type');
        $action = $request->input('action');

        $query = ActionLog::forTenant($tenantId)
            ->with('actor:id,name');

        if ($refType) {
            $query->where('ref_type', $refType);
        }

        if ($action) {
            $query->where('action_name', $action);
        }

        $logs = $query->orderByDesc('created_at')
            ->paginate($perPage);

        $items = collect($logs->items())->map(function ($log) {
            return [
                'id' => $log->id,
                'action' => $log->action_name,
                'action_color' => $log->action_color,
                'ref_type' => $log->ref_type,
                'ref_id' => $log->ref_id,
                'description' => $log->description,
                'actor' => $log->actor?->name ?? 'System',
                'payload' => $log->payload_json,
                'created_at' => $log->created_at->toIso8601String(),
                'time_ago' => $log->created_at->diffForHumans(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
