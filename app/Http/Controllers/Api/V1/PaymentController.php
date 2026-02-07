<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BillingPayment as Payment;
use App\Models\BillingInvoice as Invoice;
use App\Models\ActionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class PaymentController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);
        $perPage = (int) $request->input('per_page', 15);
        
        $query = Payment::where('tenant_id', $tenantId)
            ->with([
                'invoice:id,invoice_no,total_amount,paid_amount,status',
                'customer:id,full_name',
            ])
            ->select([
                'id',
                'tenant_id',
                'invoice_id',
                'customer_id',
                'amount',
                'paid_at',
                'payment_method',
                'reference_no',
                'notes',
            ]);
        
        // Search
        if ($q = $request->input('q')) {
            $query->where(function ($qb) use ($q) {
                $qb->where('reference_no', 'like', "%{$q}%")
                   ->orWhereHas('invoice', fn($i) => $i->where('invoice_no', 'like', "%{$q}%"))
                   ->orWhereHas('customer', fn($c) => $c->where('full_name', 'like', "%{$q}%"));
            });
        }
        
        // Date range
        if ($from = $request->input('date_from')) {
            $query->whereDate('paid_at', '>=', $from);
        }
        if ($to = $request->input('date_to')) {
            $query->whereDate('paid_at', '<=', $to);
        }
        
        $payments = $query->orderByDesc('paid_at')->paginate($perPage);
        
        // Transform to add payment_date alias for frontend
        $items = collect($payments->items())->map(function ($p) {
            $arr = $p->toArray();
            $arr['payment_date'] = $p->paid_at;
            return $arr;
        });
        
        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);
        
        // This month's stats
        $stats = Cache::remember("tenant:{$tenantId}:payments:stats", 30, function () use ($tenantId) {
            return Payment::where('tenant_id', $tenantId)
                ->whereYear('paid_at', now()->year)
                ->whereMonth('paid_at', now()->month)
                ->selectRaw('SUM(amount) as total_amount, COUNT(*) as count')
                ->first();
        });
        
        return response()->json([
            'data' => [
                'total_amount' => (float) ($stats->total_amount ?? 0),
                'count' => (int) ($stats->count ?? 0),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'required|integer',
            'invoice_id' => 'required|integer|exists:noci_billing_invoices,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'payment_method' => 'required|string|max:50',
            'reference_no' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ]);
        
        $tenantId = $validated['tenant_id'];
        
        DB::beginTransaction();
        try {
            $invoice = Invoice::where('tenant_id', $tenantId)->findOrFail($validated['invoice_id']);
            
            // Check if not overpaying
            $remaining = $invoice->total_amount - $invoice->paid_amount;
            if ($validated['amount'] > $remaining) {
                return response()->json(['message' => 'Amount exceeds remaining balance'], 422);
            }
            
            $payment = Payment::create([
                'tenant_id' => $tenantId,
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'amount' => $validated['amount'],
                'paid_at' => $validated['payment_date'],
                'payment_method' => $validated['payment_method'],
                'reference_no' => $validated['reference_no'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => null, // TODO: get from auth
            ]);
            
            // Update invoice paid amount and status
            $newPaid = $invoice->paid_amount + $validated['amount'];
            $newStatus = $newPaid >= $invoice->total_amount ? 'PAID' : 'PARTIAL';
            
            $invoice->update([
                'paid_amount' => $newPaid,
                'status' => $newStatus,
            ]);
            
            // Log action
            ActionLog::record(
                tenantId: $tenantId,
                action: 'PAYMENT',
                refType: 'payment',
                refId: $payment->id,
                payload: [
                    'invoice_id' => $invoice->id,
                    'invoice_no' => $invoice->invoice_no,
                    'amount' => $validated['amount'],
                    'method' => $validated['payment_method'],
                ]
            );
            
            DB::commit();
            
            return response()->json([
                'message' => 'Payment recorded successfully',
                'data' => $payment->load(['invoice', 'customer']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to record payment: ' . $e->getMessage()], 500);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);
        
        $payment = Payment::where('tenant_id', $tenantId)->findOrFail($id);
        
        DB::beginTransaction();
        try {
            // Revert invoice paid amount
            $invoice = Invoice::find($payment->invoice_id);
            if ($invoice) {
                $newPaid = max(0, $invoice->paid_amount - $payment->amount);
                $newStatus = $newPaid <= 0 ? 'OPEN' : ($newPaid >= $invoice->total_amount ? 'PAID' : 'PARTIAL');
                
                $invoice->update([
                    'paid_amount' => $newPaid,
                    'status' => $newStatus,
                ]);
            }
            
            // Log before delete
            ActionLog::record(
                tenantId: $tenantId,
                action: 'DELETE',
                refType: 'payment',
                refId: $id,
                payload: ['amount' => $payment->amount, 'invoice_id' => $payment->invoice_id]
            );
            
            $payment->delete();
            
            DB::commit();
            
            return response()->json(['message' => 'Payment deleted successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to delete payment: ' . $e->getMessage()], 500);
        }
    }
}
