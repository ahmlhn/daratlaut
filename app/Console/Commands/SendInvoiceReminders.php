<?php

namespace App\Console\Commands;

use App\Models\ActionLog;
use App\Models\BillingInvoice;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SendInvoiceReminders extends Command
{
    protected $signature = 'billing:send-reminders 
                            {--tenant= : Tenant ID to process}
                            {--type=upcoming : Type: upcoming, due, overdue}
                            {--days=3 : Days before/after due date}
                            {--dry-run : Preview without sending}';

    protected $description = 'Send WhatsApp reminders for invoices';

    public function handle(): int
    {
        $tenantId = $this->option('tenant') ?? 1;
        $type = $this->option('type');
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');

        $this->info("Sending {$type} reminders for tenant {$tenantId}");

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No messages will be sent');
        }

        $today = Carbon::today();
        $invoices = collect();

        switch ($type) {
            case 'upcoming':
                // Invoices due in X days
                $targetDate = $today->copy()->addDays($days);
                $invoices = BillingInvoice::forTenant($tenantId)
                    ->whereIn('status', ['OPEN', 'PARTIAL'])
                    ->whereDate('due_date', $targetDate)
                    ->with('customer')
                    ->get();
                $this->info("Found {$invoices->count()} invoices due in {$days} days");
                break;

            case 'due':
                // Invoices due today
                $invoices = BillingInvoice::forTenant($tenantId)
                    ->whereIn('status', ['OPEN', 'PARTIAL'])
                    ->whereDate('due_date', $today)
                    ->with('customer')
                    ->get();
                $this->info("Found {$invoices->count()} invoices due today");
                break;

            case 'overdue':
                // Overdue invoices
                $invoices = BillingInvoice::forTenant($tenantId)
                    ->where('status', 'OVERDUE')
                    ->with('customer')
                    ->get();
                $this->info("Found {$invoices->count()} overdue invoices");
                break;
        }

        $sent = 0;
        $failed = 0;

        foreach ($invoices as $invoice) {
            $customer = $invoice->customer;
            
            if (!$customer || !$customer->phone) {
                $this->line("  Skip: {$invoice->invoice_no} - No phone number");
                continue;
            }

            $message = $this->buildMessage($invoice, $type);
            
            if ($dryRun) {
                $this->line("  Would send to {$customer->phone}: " . substr($message, 0, 50) . "...");
                $sent++;
                continue;
            }

            $success = $this->sendWhatsApp($customer->phone, $message, $tenantId);
            
            if ($success) {
                $this->info("  Sent: {$invoice->invoice_no} to {$customer->phone}");
                $sent++;
            } else {
                $this->error("  Failed: {$invoice->invoice_no} to {$customer->phone}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Summary: Sent={$sent}, Failed={$failed}");

        // Log activity
        if (!$dryRun && $sent > 0) {
            ActionLog::record(
                'BATCH_REMINDER',
                'Invoice',
                null,
                [
                    'type' => $type,
                    'messages_sent' => $sent,
                    'messages_failed' => $failed,
                ],
                $tenantId
            );
        }

        return self::SUCCESS;
    }

    protected function buildMessage(BillingInvoice $invoice, string $type): string
    {
        $customer = $invoice->customer;
        $balance = number_format($invoice->balance, 0, ',', '.');
        $dueDate = Carbon::parse($invoice->due_date)->format('d M Y');

        switch ($type) {
            case 'upcoming':
                return "Halo {$customer->full_name},\n\n"
                    . "Tagihan internet Anda akan jatuh tempo pada {$dueDate}.\n\n"
                    . "No. Invoice: {$invoice->invoice_no}\n"
                    . "Total: Rp {$balance}\n\n"
                    . "Mohon lakukan pembayaran sebelum jatuh tempo untuk menghindari pemutusan layanan.\n\n"
                    . "Terima kasih.";

            case 'due':
                return "Halo {$customer->full_name},\n\n"
                    . "Tagihan internet Anda JATUH TEMPO HARI INI.\n\n"
                    . "No. Invoice: {$invoice->invoice_no}\n"
                    . "Total: Rp {$balance}\n\n"
                    . "Segera lakukan pembayaran untuk menghindari pemutusan layanan.\n\n"
                    . "Terima kasih.";

            case 'overdue':
                return "Halo {$customer->full_name},\n\n"
                    . "⚠️ Tagihan internet Anda sudah MELEWATI JATUH TEMPO.\n\n"
                    . "No. Invoice: {$invoice->invoice_no}\n"
                    . "Jatuh Tempo: {$dueDate}\n"
                    . "Total: Rp {$balance}\n\n"
                    . "Layanan akan segera diisolir jika tidak ada pembayaran.\n"
                    . "Segera hubungi kami untuk informasi pembayaran.\n\n"
                    . "Terima kasih.";

            default:
                return "Invoice {$invoice->invoice_no}: Rp {$balance}";
        }
    }

    protected function sendWhatsApp(string $phone, string $message, int $tenantId): bool
    {
        // Normalize phone number
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($phone, '0')) {
            $phone = '62' . substr($phone, 1);
        }

        try {
            // Use native WA gateway via HTTP
            // This calls the existing PHP WA gateway router
            $response = Http::timeout(30)->post(config('app.url') . '/api_wa_send.php', [
                'tenant_id' => $tenantId,
                'target' => $phone,
                'message' => $message,
                'source' => 'billing_reminder',
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            \Log::error("WhatsApp send failed: " . $e->getMessage());
            return false;
        }
    }
}
