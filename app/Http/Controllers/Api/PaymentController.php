<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    /**
     * Display a listing of payments.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id', 1);
        $perPage = $request->input('per_page', 20);
        $customerId = $request->input('customer_id');
        $invoiceId = $request->input('invoice_id');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $query = BillingPayment::forTenant($tenantId)
            ->with(['customer:id,customer_code,full_name', 'invoice:id,invoice_no']);

        if ($customerId) {
            $query->where('customer_id', $customerId);
        }

        if ($invoiceId) {
            $query->where('invoice_id', $invoiceId);
        }

        if ($dateFrom) {
            $query->whereDate('paid_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('paid_at', '<=', $dateTo);
        }

        $payments = $query->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $payments->items(),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
        ]);
    }

    /**
     * Store a newly created payment.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id', 1);

        $validated = $request->validate([
            'invoice_id' => 'required|exists:noci_billing_invoices,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|max:50',
            'payment_channel' => 'nullable|string|max:100',
            'reference_no' => 'nullable|string|max:100',
            'paid_at' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $invoice = BillingInvoice::forTenant($tenantId)->findOrFail($validated['invoice_id']);

        if (in_array($invoice->status, ['PAID', 'VOID'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot add payment to paid or voided invoice',
            ], 422);
        }

        // Generate receipt number
        $year = date('Y');
        $month = date('m');
        $lastPayment = BillingPayment::forTenant($tenantId)
            ->where('receipt_no', 'like', "RCP-{$year}{$month}-%")
            ->orderByDesc('id')
            ->first();

        $sequence = 1;
        if ($lastPayment) {
            $parts = explode('-', $lastPayment->receipt_no);
            $sequence = (int) end($parts) + 1;
        }
        $receiptNo = sprintf("RCP-%s%s-%04d", $year, $month, $sequence);

        DB::beginTransaction();
        try {
            $payment = BillingPayment::create([
                'tenant_id' => $tenantId,
                'customer_id' => $invoice->customer_id,
                'invoice_id' => $invoice->id,
                'receipt_no' => $receiptNo,
                'amount' => $validated['amount'],
                'payment_method' => $validated['payment_method'],
                'payment_channel' => $validated['payment_channel'] ?? null,
                'reference_no' => $validated['reference_no'] ?? null,
                'paid_at' => $validated['paid_at'] ?? now(),
                'notes' => $validated['notes'] ?? null,
            ]);

            // Update invoice
            $newPaidAmount = $invoice->paid_amount + $validated['amount'];
            $invoice->paid_amount = $newPaidAmount;

            if ($newPaidAmount >= $invoice->total_amount) {
                $invoice->status = 'PAID';
                $invoice->paid_at = now();
            } elseif ($newPaidAmount > 0) {
                $invoice->status = 'PARTIAL';
            }

            $invoice->save();

            DB::commit();

            // Log activity
            ActionLog::record(
                $tenantId,
                auth()->id(),
                'PAYMENT',
                'Payment',
                $payment->id,
                [
                    'receipt_no' => $receiptNo,
                    'amount' => $validated['amount'],
                    'invoice_no' => $invoice->invoice_no,
                    'customer_name' => $invoice->customer->full_name ?? null,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Payment recorded successfully',
                'data' => $payment->load(['customer:id,customer_code,full_name', 'invoice:id,invoice_no,total_amount,paid_amount,status']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to record payment: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified payment.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id', 1);

        $payment = BillingPayment::forTenant($tenantId)
            ->with(['customer', 'invoice'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $payment,
        ]);
    }

    /**
     * Update the specified payment.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id', 1);

        $payment = BillingPayment::forTenant($tenantId)->findOrFail($id);

        $validated = $request->validate([
            'payment_method' => 'sometimes|string|max:50',
            'payment_channel' => 'nullable|string|max:100',
            'reference_no' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ]);

        $payment->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Payment updated successfully',
            'data' => $payment->fresh(),
        ]);
    }

    /**
     * Remove the specified payment.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id', 1);

        $payment = BillingPayment::forTenant($tenantId)->findOrFail($id);

        DB::beginTransaction();
        try {
            $invoice = $payment->invoice;
            $amount = $payment->amount;

            $payment->delete();

            // Update invoice
            if ($invoice) {
                $invoice->paid_amount -= $amount;
                if ($invoice->paid_amount <= 0) {
                    $invoice->paid_amount = 0;
                    $invoice->status = 'OPEN';
                    $invoice->paid_at = null;
                } elseif ($invoice->paid_amount < $invoice->total_amount) {
                    $invoice->status = 'PARTIAL';
                }
                $invoice->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment deleted successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete payment: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get payment statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id', 1);
        $month = $request->input('month', date('Y-m'));

        $stats = [
            'total_this_month' => BillingPayment::forTenant($tenantId)
                ->whereRaw("DATE_FORMAT(paid_at, '%Y-%m') = ?", [$month])
                ->sum('amount'),
            'count_this_month' => BillingPayment::forTenant($tenantId)
                ->whereRaw("DATE_FORMAT(paid_at, '%Y-%m') = ?", [$month])
                ->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
