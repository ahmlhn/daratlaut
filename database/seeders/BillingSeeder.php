<?php

namespace Database\Seeders;

use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingInvoiceItem;
use App\Models\BillingPayment;
use App\Models\BillingPlan;
use Illuminate\Database\Seeder;

class BillingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenantId = 1;

        // Create Plans
        $plans = [
            ['code' => 'HOME-10', 'name' => 'Home 10 Mbps', 'price' => 150000],
            ['code' => 'HOME-20', 'name' => 'Home 20 Mbps', 'price' => 250000],
            ['code' => 'HOME-50', 'name' => 'Home 50 Mbps', 'price' => 350000],
            ['code' => 'BIZ-100', 'name' => 'Business 100 Mbps', 'price' => 750000],
        ];

        foreach ($plans as $plan) {
            BillingPlan::updateOrCreate(
                ['tenant_id' => $tenantId, 'code' => $plan['code']],
                ['name' => $plan['name'], 'price' => $plan['price'], 'billing_cycle' => 'MONTHLY', 'is_active' => true]
            );
        }

        $this->command->info('Created ' . count($plans) . ' plans');

        // Create Customers
        $customers = [
            ['code' => 'CUST-001', 'name' => 'Ahmad Rizki', 'phone' => '6281234567001', 'status' => 'AKTIF', 'plan' => 'HOME-20'],
            ['code' => 'CUST-002', 'name' => 'Budi Santoso', 'phone' => '6281234567002', 'status' => 'AKTIF', 'plan' => 'HOME-50'],
            ['code' => 'CUST-003', 'name' => 'Citra Dewi', 'phone' => '6281234567003', 'status' => 'AKTIF', 'plan' => 'HOME-10'],
            ['code' => 'CUST-004', 'name' => 'Dian Permata', 'phone' => '6281234567004', 'status' => 'SUSPEND', 'plan' => 'HOME-20'],
            ['code' => 'CUST-005', 'name' => 'Eko Prasetyo', 'phone' => '6281234567005', 'status' => 'AKTIF', 'plan' => 'BIZ-100'],
            ['code' => 'CUST-006', 'name' => 'Fitri Handayani', 'phone' => '6281234567006', 'status' => 'AKTIF', 'plan' => 'HOME-20'],
            ['code' => 'CUST-007', 'name' => 'Gunawan Wijaya', 'phone' => '6281234567007', 'status' => 'NONAKTIF', 'plan' => 'HOME-10'],
            ['code' => 'CUST-008', 'name' => 'Hendra Kusuma', 'phone' => '6281234567008', 'status' => 'AKTIF', 'plan' => 'HOME-50'],
            ['code' => 'CUST-009', 'name' => 'Indah Permata', 'phone' => '6281234567009', 'status' => 'AKTIF', 'plan' => 'HOME-20'],
            ['code' => 'CUST-010', 'name' => 'Joko Widodo', 'phone' => '6281234567010', 'status' => 'SUSPEND', 'plan' => 'BIZ-100'],
        ];

        $planMap = BillingPlan::forTenant($tenantId)->pluck('id', 'code');

        foreach ($customers as $c) {
            BillingCustomer::updateOrCreate(
                ['tenant_id' => $tenantId, 'customer_code' => $c['code']],
                [
                    'full_name' => $c['name'],
                    'phone' => $c['phone'],
                    'email' => strtolower(str_replace(' ', '.', $c['name'])) . '@example.com',
                    'address' => 'Jl. Contoh No. ' . rand(1, 100) . ', Jakarta',
                    'plan_id' => $planMap[$c['plan']] ?? null,
                    'service_status' => $c['status'],
                    'billing_day' => rand(1, 28),
                    'pop_name' => 'POP-' . chr(65 + rand(0, 3)),
                    'odp_name' => 'ODP-' . rand(100, 999),
                ]
            );
        }

        $this->command->info('Created ' . count($customers) . ' customers');

        // Create Invoices for active customers
        $activeCustomers = BillingCustomer::forTenant($tenantId)
            ->where('service_status', 'AKTIF')
            ->with('plan')
            ->get();

        $invoiceCount = 0;
        $paymentCount = 0;

        foreach ($activeCustomers as $customer) {
            if (!$customer->plan) continue;

            // Create invoice for current month
            $periodKey = date('Y-m');
            $existing = BillingInvoice::forTenant($tenantId)
                ->where('customer_id', $customer->id)
                ->where('period_key', $periodKey)
                ->first();

            if ($existing) continue;

            $invoiceNo = sprintf('INV-%s-%04d', date('Y'), $invoiceCount + 1);
            $issueDate = date('Y-m-01');
            $dueDate = date('Y-m-d', strtotime('+14 days', strtotime($issueDate)));
            $price = $customer->plan->price;

            // Random status
            $statuses = ['OPEN', 'OPEN', 'PAID', 'PARTIAL'];
            $status = $statuses[array_rand($statuses)];
            $paidAmount = 0;

            if ($status === 'PAID') {
                $paidAmount = $price;
            } elseif ($status === 'PARTIAL') {
                $paidAmount = round($price * 0.5);
            }

            $invoice = BillingInvoice::create([
                'tenant_id' => $tenantId,
                'customer_id' => $customer->id,
                'invoice_no' => $invoiceNo,
                'period_key' => $periodKey,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'status' => $status,
                'subtotal' => $price,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => $price,
                'paid_amount' => $paidAmount,
                'paid_at' => $paidAmount >= $price ? now() : null,
            ]);

            BillingInvoiceItem::create([
                'tenant_id' => $tenantId,
                'invoice_id' => $invoice->id,
                'item_type' => 'PLAN',
                'description' => $customer->plan->name . ' - ' . $periodKey,
                'quantity' => 1,
                'unit_price' => $price,
                'amount' => $price,
                'sort_order' => 0,
            ]);

            $invoiceCount++;

            // Create payment if paid
            if ($paidAmount > 0) {
                $methods = ['Transfer Bank', 'QRIS', 'Cash', 'E-Wallet'];
                BillingPayment::create([
                    'tenant_id' => $tenantId,
                    'customer_id' => $customer->id,
                    'invoice_id' => $invoice->id,
                    'receipt_no' => sprintf('RCP-%s%s-%04d', date('Y'), date('m'), $paymentCount + 1),
                    'amount' => $paidAmount,
                    'payment_method' => $methods[array_rand($methods)],
                    'paid_at' => now()->subDays(rand(0, 7)),
                ]);
                $paymentCount++;
            }
        }

        $this->command->info("Created {$invoiceCount} invoices and {$paymentCount} payments");
    }
}
