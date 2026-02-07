<?php

namespace App\Services;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\ActionLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MidtransService
{
    protected string $serverKey;
    protected string $clientKey;
    protected string $baseUrl;
    protected bool $isProduction;

    public function __construct()
    {
        $this->isProduction = config('services.midtrans.is_production', false);
        $this->serverKey = config('services.midtrans.server_key', '');
        $this->clientKey = config('services.midtrans.client_key', '');
        $this->baseUrl = $this->isProduction 
            ? 'https://api.midtrans.com' 
            : 'https://api.sandbox.midtrans.com';
    }

    /**
     * Create Snap payment token for invoice
     */
    public function createSnapToken(BillingInvoice $invoice): ?array
    {
        $customer = $invoice->customer;
        
        if (!$customer) {
            Log::error('Midtrans: Customer not found for invoice ' . $invoice->id);
            return null;
        }

        $orderId = 'INV-' . $invoice->id . '-' . time();
        $amount = (int) $invoice->balance;

        $payload = [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => $amount,
            ],
            'customer_details' => [
                'first_name' => $customer->full_name,
                'email' => $customer->email ?? 'customer@example.com',
                'phone' => $customer->phone ?? '',
            ],
            'item_details' => [
                [
                    'id' => 'INV-' . $invoice->invoice_no,
                    'price' => $amount,
                    'quantity' => 1,
                    'name' => 'Invoice ' . $invoice->invoice_no . ' - ' . $invoice->period_key,
                ]
            ],
            'callbacks' => [
                'finish' => config('app.url') . '/payment/finish?invoice_id=' . $invoice->id,
            ],
            'expiry' => [
                'unit' => 'days',
                'duration' => 1,
            ],
        ];

        try {
            $response = Http::withBasicAuth($this->serverKey, '')
                ->timeout(30)
                ->post($this->baseUrl . '/v1/payment-links', $payload);

            if ($response->successful()) {
                $data = $response->json();
                
                // Store order_id mapping
                $invoice->update([
                    'notes' => json_encode([
                        'midtrans_order_id' => $orderId,
                        'payment_link' => $data['payment_url'] ?? null,
                    ])
                ]);

                return [
                    'success' => true,
                    'order_id' => $orderId,
                    'payment_url' => $data['payment_url'] ?? null,
                    'token' => $data['token'] ?? null,
                ];
            }

            Log::error('Midtrans Snap Error: ' . $response->body());
            return ['success' => false, 'error' => $response->json()['error_messages'] ?? 'Unknown error'];

        } catch (\Exception $e) {
            Log::error('Midtrans Exception: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create QRIS payment
     */
    public function createQrisPayment(BillingInvoice $invoice): ?array
    {
        $customer = $invoice->customer;
        $orderId = 'QRIS-' . $invoice->id . '-' . time();
        $amount = (int) $invoice->balance;

        $payload = [
            'payment_type' => 'qris',
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => $amount,
            ],
            'qris' => [
                'acquirer' => 'gopay', // or 'airpay shopee'
            ],
        ];

        try {
            $response = Http::withBasicAuth($this->serverKey, '')
                ->timeout(30)
                ->post($this->baseUrl . '/v2/charge', $payload);

            if ($response->successful()) {
                $data = $response->json();
                
                return [
                    'success' => true,
                    'order_id' => $orderId,
                    'qr_string' => $data['actions'][0]['url'] ?? null,
                    'transaction_id' => $data['transaction_id'] ?? null,
                    'expiry_time' => $data['expiry_time'] ?? null,
                ];
            }

            return ['success' => false, 'error' => $response->json()['status_message'] ?? 'Unknown error'];

        } catch (\Exception $e) {
            Log::error('Midtrans QRIS Exception: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Handle notification callback from Midtrans
     */
    public function handleNotification(array $payload): bool
    {
        $orderId = $payload['order_id'] ?? null;
        $transactionStatus = $payload['transaction_status'] ?? null;
        $fraudStatus = $payload['fraud_status'] ?? 'accept';
        $grossAmount = $payload['gross_amount'] ?? 0;

        if (!$orderId) {
            Log::error('Midtrans Notification: Missing order_id');
            return false;
        }

        // Extract invoice ID from order_id (format: INV-{id}-{timestamp} or QRIS-{id}-{timestamp})
        preg_match('/(?:INV|QRIS)-(\d+)-/', $orderId, $matches);
        $invoiceId = $matches[1] ?? null;

        if (!$invoiceId) {
            Log::error('Midtrans Notification: Cannot extract invoice ID from ' . $orderId);
            return false;
        }

        $invoice = BillingInvoice::find($invoiceId);
        if (!$invoice) {
            Log::error('Midtrans Notification: Invoice not found ' . $invoiceId);
            return false;
        }

        // Verify signature
        $signatureKey = hash('sha512', 
            $orderId . 
            $payload['status_code'] . 
            $grossAmount . 
            $this->serverKey
        );

        if ($signatureKey !== ($payload['signature_key'] ?? '')) {
            Log::error('Midtrans Notification: Invalid signature for ' . $orderId);
            return false;
        }

        // Process based on status
        if ($transactionStatus === 'capture' || $transactionStatus === 'settlement') {
            if ($fraudStatus === 'accept') {
                return $this->processPayment($invoice, $payload);
            }
        } elseif (in_array($transactionStatus, ['deny', 'cancel', 'expire'])) {
            Log::info('Midtrans: Payment ' . $transactionStatus . ' for ' . $orderId);
        }

        return true;
    }

    /**
     * Process successful payment
     */
    protected function processPayment(BillingInvoice $invoice, array $payload): bool
    {
        $amount = (float) $payload['gross_amount'];
        $paymentType = $payload['payment_type'] ?? 'unknown';

        // Create payment record
        $payment = BillingPayment::create([
            'tenant_id' => $invoice->tenant_id,
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'receipt_no' => 'MT-' . ($payload['transaction_id'] ?? time()),
            'amount' => $amount,
            'payment_method' => strtoupper($paymentType),
            'payment_channel' => 'MIDTRANS',
            'reference_no' => $payload['transaction_id'] ?? null,
            'paid_at' => now(),
            'notes' => json_encode($payload),
        ]);

        // Update invoice
        $newPaidAmount = (float) $invoice->paid_amount + $amount;
        $totalAmount = (float) $invoice->total_amount;

        $newStatus = 'PARTIAL';
        if ($newPaidAmount >= $totalAmount) {
            $newStatus = 'PAID';
        }

        $invoice->update([
            'paid_amount' => $newPaidAmount,
            'status' => $newStatus,
            'paid_at' => $newStatus === 'PAID' ? now() : null,
        ]);

        // Reactivate customer if suspended
        $customer = $invoice->customer;
        if ($customer && $customer->service_status === 'SUSPEND' && $newStatus === 'PAID') {
            $customer->update(['service_status' => 'AKTIF']);
            
            ActionLog::record(
                'AUTO_REACTIVATE',
                'Customer',
                $customer->id,
                [
                    'invoice_id' => $invoice->id,
                    'payment_id' => $payment->id,
                    'reason' => 'Payment received via Midtrans',
                ],
                $invoice->tenant_id
            );
        }

        ActionLog::record(
            'PAYMENT_RECEIVED',
            'Payment',
            $payment->id,
            [
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'method' => $paymentType,
                'channel' => 'MIDTRANS',
            ],
            $invoice->tenant_id
        );

        return true;
    }

    /**
     * Get transaction status
     */
    public function getStatus(string $orderId): ?array
    {
        try {
            $response = Http::withBasicAuth($this->serverKey, '')
                ->timeout(30)
                ->get($this->baseUrl . '/v2/' . $orderId . '/status');

            return $response->json();
        } catch (\Exception $e) {
            Log::error('Midtrans Status Exception: ' . $e->getMessage());
            return null;
        }
    }
}
