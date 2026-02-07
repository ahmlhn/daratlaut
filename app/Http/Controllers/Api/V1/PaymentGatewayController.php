<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use App\Services\MidtransService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentGatewayController extends Controller
{
    protected MidtransService $midtrans;

    public function __construct(MidtransService $midtrans)
    {
        $this->midtrans = $midtrans;
    }

    /**
     * Create payment link for invoice
     */
    public function createPaymentLink(Request $request, int $invoiceId): JsonResponse
    {
        $tenantId = $request->input('tenant_id', 1);
        
        $invoice = BillingInvoice::forTenant($tenantId)
            ->with('customer')
            ->find($invoiceId);

        if (!$invoice) {
            return response()->json(['error' => 'Invoice not found'], 404);
        }

        if ($invoice->status === 'PAID') {
            return response()->json(['error' => 'Invoice already paid'], 400);
        }

        if ($invoice->balance <= 0) {
            return response()->json(['error' => 'No balance to pay'], 400);
        }

        $result = $this->midtrans->createSnapToken($invoice);

        if ($result && $result['success']) {
            return response()->json([
                'success' => true,
                'payment_url' => $result['payment_url'],
                'order_id' => $result['order_id'],
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => $result['error'] ?? 'Failed to create payment link',
        ], 500);
    }

    /**
     * Create QRIS payment for invoice
     */
    public function createQris(Request $request, int $invoiceId): JsonResponse
    {
        $tenantId = $request->input('tenant_id', 1);
        
        $invoice = BillingInvoice::forTenant($tenantId)
            ->with('customer')
            ->find($invoiceId);

        if (!$invoice) {
            return response()->json(['error' => 'Invoice not found'], 404);
        }

        if ($invoice->status === 'PAID') {
            return response()->json(['error' => 'Invoice already paid'], 400);
        }

        $result = $this->midtrans->createQrisPayment($invoice);

        if ($result && $result['success']) {
            return response()->json([
                'success' => true,
                'qr_string' => $result['qr_string'],
                'order_id' => $result['order_id'],
                'expiry_time' => $result['expiry_time'],
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => $result['error'] ?? 'Failed to create QRIS',
        ], 500);
    }

    /**
     * Handle Midtrans notification callback
     */
    public function notification(Request $request): JsonResponse
    {
        $payload = $request->all();
        
        \Log::info('Midtrans Notification received', $payload);

        $success = $this->midtrans->handleNotification($payload);

        return response()->json(['status' => $success ? 'ok' : 'error']);
    }

    /**
     * Check payment status
     */
    public function checkStatus(Request $request, string $orderId): JsonResponse
    {
        $status = $this->midtrans->getStatus($orderId);

        if ($status) {
            return response()->json($status);
        }

        return response()->json(['error' => 'Failed to get status'], 500);
    }

    /**
     * Payment finish redirect handler
     */
    public function finish(Request $request)
    {
        $invoiceId = $request->query('invoice_id');
        $orderId = $request->query('order_id');
        $transactionStatus = $request->query('transaction_status');

        // Redirect to frontend with status
        $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
        
        return redirect($frontendUrl . '/invoices?payment_status=' . $transactionStatus . '&invoice_id=' . $invoiceId);
    }
}
