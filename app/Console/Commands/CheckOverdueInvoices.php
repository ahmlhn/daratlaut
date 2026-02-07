<?php

namespace App\Console\Commands;

use App\Models\ActionLog;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckOverdueInvoices extends Command
{
    protected $signature = 'billing:check-overdue 
                            {--tenant= : Tenant ID to process}
                            {--auto-suspend : Automatically suspend customers with overdue invoices}
                            {--dry-run : Preview without making changes}';

    protected $description = 'Check for overdue invoices and optionally suspend customers';

    public function handle(): int
    {
        $tenantId = $this->option('tenant') ?? 1;
        $autoSuspend = $this->option('auto-suspend');
        $dryRun = $this->option('dry-run');

        $this->info("Checking overdue invoices for tenant {$tenantId}");

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $today = Carbon::today();

        // Find open invoices past due date
        $overdueInvoices = BillingInvoice::forTenant($tenantId)
            ->where('status', 'OPEN')
            ->where('due_date', '<', $today)
            ->with('customer')
            ->get();

        $this->info("Found {$overdueInvoices->count()} overdue invoices");

        $updated = 0;
        $suspended = 0;

        foreach ($overdueInvoices as $invoice) {
            $customer = $invoice->customer;
            $daysOverdue = $today->diffInDays($invoice->due_date);
            
            $this->line("  {$invoice->invoice_no} - {$customer->full_name} - {$daysOverdue} days overdue");

            if (!$dryRun) {
                // Update invoice status to OVERDUE
                $invoice->update(['status' => 'OVERDUE']);
                $updated++;

                // Auto-suspend if enabled and customer is still active
                if ($autoSuspend && $customer->service_status === 'AKTIF') {
                    $customer->update(['service_status' => 'SUSPEND']);
                    $this->warn("    -> Customer suspended");
                    $suspended++;

                    // Log suspension
                    ActionLog::record(
                        'AUTO_SUSPEND',
                        'Customer',
                        $customer->id,
                        [
                            'invoice_id' => $invoice->id,
                            'invoice_no' => $invoice->invoice_no,
                            'days_overdue' => $daysOverdue,
                            'reason' => 'Overdue invoice',
                        ],
                        $tenantId
                    );
                }
            }
        }

        $this->newLine();
        $this->info("Summary: Updated={$updated}, Suspended={$suspended}");

        // Log batch action
        if (!$dryRun && $updated > 0) {
            ActionLog::record(
                'BATCH_OVERDUE_CHECK',
                'Invoice',
                null,
                [
                    'invoices_marked_overdue' => $updated,
                    'customers_suspended' => $suspended,
                    'checked_at' => $today->toDateTimeString(),
                ],
                $tenantId
            );
        }

        return self::SUCCESS;
    }
}
