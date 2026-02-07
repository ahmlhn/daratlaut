<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\Installation;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Get overall analytics summary.
     */
    public function summary(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id', 1);
        $startDate = $request->get('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));
        
        // Customer stats
        $totalCustomers = BillingCustomer::forTenant($tenantId)->count();
        $activeCustomers = BillingCustomer::forTenant($tenantId)->active()->count();
        $suspendedCustomers = BillingCustomer::forTenant($tenantId)->suspended()->count();
        
        // Revenue stats
        $totalRevenue = BillingPayment::forTenant($tenantId)
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->sum('amount');
        
        $paidInvoices = BillingInvoice::forTenant($tenantId)
            ->where('status', 'PAID')
            ->whereBetween('paid_at', [$startDate, $endDate])
            ->count();
        
        $openInvoices = BillingInvoice::forTenant($tenantId)
            ->where('status', 'OPEN')
            ->count();
        
        $overdueInvoices = BillingInvoice::forTenant($tenantId)
            ->where('status', 'OPEN')
            ->where('due_date', '<', now())
            ->count();
        
        // Installation stats
        $pendingInstallations = Installation::forTenant($tenantId)
            ->where('status', 'Pending')
            ->count();
        
        $completedInstallations = Installation::forTenant($tenantId)
            ->where('status', 'Selesai')
            ->whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->count();
        
        // Lead stats
        $newLeads = Lead::forTenant($tenantId)
            ->whereBetween('last_seen', [$startDate, $endDate . ' 23:59:59'])
            ->count();
        
        $convertedLeads = Lead::forTenant($tenantId)
            ->where('status', 'CONVERTED')
            ->whereBetween('last_seen', [$startDate, $endDate . ' 23:59:59'])
            ->count();
        
        return response()->json([
            'status' => 'ok',
            'data' => [
                'period' => ['start' => $startDate, 'end' => $endDate],
                'customers' => [
                    'total' => $totalCustomers,
                    'active' => $activeCustomers,
                    'suspended' => $suspendedCustomers,
                ],
                'revenue' => [
                    'total' => $totalRevenue,
                    'paid_invoices' => $paidInvoices,
                    'open_invoices' => $openInvoices,
                    'overdue_invoices' => $overdueInvoices,
                ],
                'installations' => [
                    'pending' => $pendingInstallations,
                    'completed' => $completedInstallations,
                ],
                'leads' => [
                    'new' => $newLeads,
                    'converted' => $convertedLeads,
                ],
            ],
        ]);
    }

    /**
     * Get revenue chart data (monthly).
     */
    public function revenueChart(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id', 1);
        $months = $request->get('months', 12);
        
        $data = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $monthKey = $date->format('Y-m');
            
            $revenue = BillingPayment::forTenant($tenantId)
                ->whereYear('payment_date', $date->year)
                ->whereMonth('payment_date', $date->month)
                ->sum('amount');
            
            $data[] = [
                'month' => $date->format('M Y'),
                'month_key' => $monthKey,
                'revenue' => $revenue,
            ];
        }
        
        return response()->json([
            'status' => 'ok',
            'data' => $data,
        ]);
    }

    /**
     * Get customer growth chart data.
     */
    public function customerGrowth(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id', 1);
        $months = $request->get('months', 12);
        
        $data = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            
            $newCustomers = BillingCustomer::forTenant($tenantId)
                ->whereYear('created_at', $date->year)
                ->whereMonth('created_at', $date->month)
                ->count();
            
            $data[] = [
                'month' => $date->format('M Y'),
                'count' => $newCustomers,
            ];
        }
        
        return response()->json([
            'status' => 'ok',
            'data' => $data,
        ]);
    }

    /**
     * Get installation stats by status.
     */
    public function installationStats(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id', 1);
        $startDate = $request->get('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));
        
        $byStatus = Installation::forTenant($tenantId)
            ->whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
        
        $byPop = Installation::forTenant($tenantId)
            ->whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->selectRaw('pop, count(*) as count')
            ->groupBy('pop')
            ->orderByDesc('count')
            ->limit(10)
            ->get();
        
        return response()->json([
            'status' => 'ok',
            'data' => [
                'by_status' => $byStatus,
                'by_pop' => $byPop,
            ],
        ]);
    }

    /**
     * Get payment method breakdown.
     */
    public function paymentMethods(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id', 1);
        $startDate = $request->get('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));
        
        $data = BillingPayment::forTenant($tenantId)
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->selectRaw('payment_method, count(*) as count, sum(amount) as total')
            ->groupBy('payment_method')
            ->get();
        
        return response()->json([
            'status' => 'ok',
            'data' => $data,
        ]);
    }

    /**
     * Get plan popularity.
     */
    public function planPopularity(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id', 1);
        
        $data = BillingCustomer::forTenant($tenantId)
            ->join('billing_plans', 'billing_customers.plan_id', '=', 'billing_plans.id')
            ->selectRaw('billing_plans.name, count(*) as count')
            ->groupBy('billing_plans.id', 'billing_plans.name')
            ->orderByDesc('count')
            ->get();
        
        return response()->json([
            'status' => 'ok',
            'data' => $data,
        ]);
    }

    /**
     * Get top customers by revenue.
     */
    public function topCustomers(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id', 1);
        $limit = $request->get('limit', 10);
        
        $data = BillingPayment::forTenant($tenantId)
            ->join('billing_customers', 'billing_payments.customer_id', '=', 'billing_customers.id')
            ->selectRaw('billing_customers.id, billing_customers.name, sum(billing_payments.amount) as total_paid')
            ->groupBy('billing_customers.id', 'billing_customers.name')
            ->orderByDesc('total_paid')
            ->limit($limit)
            ->get();
        
        return response()->json([
            'status' => 'ok',
            'data' => $data,
        ]);
    }

    /**
     * Export report as CSV.
     */
    public function exportCsv(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id', 1);
        $type = $request->get('type', 'payments');
        $startDate = $request->get('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));
        
        $filename = "{$type}_{$startDate}_{$endDate}.csv";
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];
        
        $callback = function() use ($tenantId, $type, $startDate, $endDate) {
            $file = fopen('php://output', 'w');
            
            switch ($type) {
                case 'payments':
                    fputcsv($file, ['Date', 'Invoice', 'Customer', 'Amount', 'Method']);
                    $data = BillingPayment::forTenant($tenantId)
                        ->with(['invoice.customer'])
                        ->whereBetween('payment_date', [$startDate, $endDate])
                        ->get();
                    foreach ($data as $row) {
                        fputcsv($file, [
                            $row->payment_date,
                            $row->invoice->invoice_number ?? '-',
                            $row->invoice->customer->name ?? '-',
                            $row->amount,
                            $row->payment_method,
                        ]);
                    }
                    break;
                    
                case 'customers':
                    fputcsv($file, ['ID', 'Name', 'Email', 'Phone', 'Status', 'Plan', 'Created']);
                    $data = BillingCustomer::forTenant($tenantId)
                        ->with('plan')
                        ->get();
                    foreach ($data as $row) {
                        fputcsv($file, [
                            $row->id,
                            $row->name,
                            $row->email,
                            $row->phone,
                            $row->status,
                            $row->plan->name ?? '-',
                            $row->created_at,
                        ]);
                    }
                    break;
                    
                case 'invoices':
                    fputcsv($file, ['Invoice #', 'Customer', 'Issue Date', 'Due Date', 'Total', 'Status']);
                    $data = BillingInvoice::forTenant($tenantId)
                        ->with('customer')
                        ->whereBetween('issue_date', [$startDate, $endDate])
                        ->get();
                    foreach ($data as $row) {
                        fputcsv($file, [
                            $row->invoice_number,
                            $row->customer->name ?? '-',
                            $row->issue_date,
                            $row->due_date,
                            $row->total,
                            $row->status,
                        ]);
                    }
                    break;
            }
            
            fclose($file);
        };
        
        return response()->stream($callback, 200, $headers);
    }
}
