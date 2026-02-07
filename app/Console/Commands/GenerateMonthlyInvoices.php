<?php

namespace App\Console\Commands;

use App\Models\ActionLog;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingInvoiceItem;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateMonthlyInvoices extends Command
{
    protected $signature = 'billing:generate-invoices 
                            {--tenant= : Tenant ID to process}
                            {--period= : Period key (YYYY-MM), defaults to current month}
                            {--dry-run : Preview without creating invoices}';

    protected $description = 'Generate monthly invoices for active customers';

    public function handle(): int
    {
        $tenantId = $this->option('tenant') ?? 1;
        $periodKey = $this->option('period') ?? Carbon::now()->format('Y-m');
        $dryRun = $this->option('dry-run');

        $this->info("Generating invoices for tenant {$tenantId}, period {$periodKey}");
        
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No invoices will be created');
        }

        // Get active customers with plans
        $customers = BillingCustomer::forTenant($tenantId)
            ->where('service_status', 'AKTIF')
            ->whereNotNull('plan_id')
            ->with('plan')
            ->get();

        $this->info("Found {$customers->count()} active customers");

        $created = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($customers as $customer) {
            try {
                // Check if invoice already exists for this period
                $exists = BillingInvoice::forTenant($tenantId)
                    ->where('customer_id', $customer->id)
                    ->where('period_key', $periodKey)
                    ->exists();

                if ($exists) {
                    $this->line("  Skip: {$customer->full_name} - Invoice already exists");
                    $skipped++;
                    continue;
                }

                if (!$customer->plan) {
                    $this->line("  Skip: {$customer->full_name} - No plan assigned");
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $this->line("  Would create: {$customer->full_name} - {$customer->plan->name} @ Rp " . number_format($customer->plan->price));
                    $created++;
                    continue;
                }

                // Create invoice
                $invoice = $this->createInvoice($customer, $tenantId, $periodKey);
                $this->info("  Created: INV {$invoice->invoice_no} for {$customer->full_name}");
                $created++;

            } catch (\Exception $e) {
                $this->error("  Error: {$customer->full_name} - {$e->getMessage()}");
                $errors++;
            }
        }

        $this->newLine();
        $this->info("Summary: Created={$created}, Skipped={$skipped}, Errors={$errors}");

        // Log activity
        if (!$dryRun && $created > 0) {
            ActionLog::record(
                'BATCH_CREATE',
                'Invoice',
                null,
                [
                    'period_key' => $periodKey,
                    'invoices_created' => $created,
                    'invoices_skipped' => $skipped,
                ],
                $tenantId
            );
        }

        return self::SUCCESS;
    }

    protected function createInvoice(BillingCustomer $customer, int $tenantId, string $periodKey): BillingInvoice
    {
        return DB::transaction(function () use ($customer, $tenantId, $periodKey) {
            $plan = $customer->plan;
            $now = Carbon::now();
            
            // Calculate dates
            $billingDay = $customer->billing_day ?: 1;
            $issueDate = Carbon::createFromFormat('Y-m', $periodKey)->day($billingDay);
            $graceDays = $customer->grace_days ?: 7;
            $dueDate = $issueDate->copy()->addDays($graceDays);

            // Generate invoice number
            $lastInvoice = BillingInvoice::forTenant($tenantId)
                ->whereYear('created_at', $now->year)
                ->orderByDesc('id')
                ->first();
            
            $sequence = $lastInvoice ? ((int) substr($lastInvoice->invoice_no, -4)) + 1 : 1;
            $invoiceNo = sprintf('INV-%d-%04d', $now->year, $sequence);

            // Create invoice
            $invoice = BillingInvoice::create([
                'tenant_id' => $tenantId,
                'customer_id' => $customer->id,
                'invoice_no' => $invoiceNo,
                'period_key' => $periodKey,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'status' => 'OPEN',
                'subtotal' => $plan->price,
                'discount_amount' => 0,
                'penalty_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => $plan->price,
                'paid_amount' => 0,
            ]);

            // Create invoice item
            BillingInvoiceItem::create([
                'invoice_id' => $invoice->id,
                'item_type' => 'PLAN',
                'description' => "Langganan {$plan->name} - {$periodKey}",
                'quantity' => 1,
                'unit_price' => $plan->price,
                'amount' => $plan->price,
            ]);

            // Update customer next invoice date
            $customer->update([
                'next_invoice_date' => $issueDate->copy()->addMonth(),
            ]);

            return $invoice;
        });
    }
}
