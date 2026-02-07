<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BillingInvoice as Invoice;
use App\Models\BillingInvoiceItem as InvoiceItem;
use App\Models\ActionLog;
use App\Http\Controllers\Web\PaymentPortalController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class InvoiceController
{
    /**
     * Get invoice statistics
     */
    public function stats(Request $request): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);

        $stats = Cache::remember("tenant:{$tenantId}:invoices:stats", 30, function () use ($tenantId) {
            return Invoice::query()
                ->where('tenant_id', $tenantId)
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'OPEN' THEN 1 ELSE 0 END) as open,
                    SUM(CASE WHEN status = 'OVERDUE' THEN 1 ELSE 0 END) as overdue,
                    SUM(CASE WHEN status = 'PAID' THEN 1 ELSE 0 END) as paid,
                    SUM(CASE WHEN status IN ('OPEN', 'OVERDUE') THEN total_amount - paid_amount ELSE 0 END) as unpaid_amount,
                    SUM(CASE WHEN status = 'PAID' THEN total_amount ELSE 0 END) as paid_amount
                ")
                ->first();
        });

        return response()->json([
            'total' => (int) ($stats->total ?? 0),
            'open' => (int) ($stats->open ?? 0),
            'overdue' => (int) ($stats->overdue ?? 0),
            'paid' => (int) ($stats->paid ?? 0),
            'unpaid_amount' => (float) ($stats->unpaid_amount ?? 0),
            'paid_amount' => (float) ($stats->paid_amount ?? 0),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);
        $perPage = (int) $request->input('per_page', 15);
        
        $query = Invoice::where('tenant_id', $tenantId)
            ->with(['customer:id,full_name'])
            ->select([
                'id',
                'tenant_id',
                'customer_id',
                'invoice_no',
                'issue_date',
                'due_date',
                'total_amount',
                'paid_amount',
                'status',
                'created_at',
            ]);
        
        // Search by invoice_no or customer name
        if ($q = $request->input('q')) {
            $query->where(function ($qb) use ($q) {
                $qb->where('invoice_no', 'like', "%{$q}%")
                   ->orWhereHas('customer', fn($c) => $c->where('full_name', 'like', "%{$q}%"));
            });
        }
        
        // Filter by status
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        
        $invoices = $query->orderByDesc('created_at')->paginate($perPage);

        $includePaymentUrl = $request->boolean('include_payment_url');
        $invoicesData = $includePaymentUrl
            ? collect($invoices->items())->map(function ($invoice) {
                $data = $invoice->toArray();
                $data['payment_url'] = PaymentPortalController::getPaymentUrl($invoice);
                return $data;
            })
            : $invoices->items();
        
        return response()->json([
            'data' => $invoicesData,
            'meta' => [
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);
        
        $invoice = Invoice::where('tenant_id', $tenantId)
            ->with([
                'customer:id,full_name',
                'items:id,invoice_id,item_type,description,quantity,unit_price,amount',
                'payments:id,invoice_id,amount,paid_at,payment_method,reference_no',
            ])
            ->findOrFail($id);
        
        // Add payment URL
        $data = $invoice->toArray();
        $data['payment_url'] = PaymentPortalController::getPaymentUrl($invoice);
        
        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'required|integer',
            'customer_id' => 'required|integer|exists:noci_billing_customers,id',
            'period_key' => 'required|string|max:20',
            'issue_date' => 'required|date',
            'due_date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.item_type' => 'nullable|string|max:50',
            'discount_amount' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);
        
        $tenantId = $validated['tenant_id'];
        
        DB::beginTransaction();
        try {
            // Calculate totals
            $subtotal = collect($validated['items'])->sum(fn($i) => $i['quantity'] * $i['unit_price']);
            $discount = $validated['discount_amount'] ?? 0;
            $tax = $validated['tax_amount'] ?? 0;
            $total = $subtotal - $discount + $tax;
            
            // Generate invoice number
            $lastInv = Invoice::where('tenant_id', $tenantId)
                ->whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month)
                ->orderByDesc('id')
                ->first();
            $seq = $lastInv ? ((int) substr($lastInv->invoice_no, -4)) + 1 : 1;
            $invoiceNo = 'INV-' . now()->format('Ym') . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
            
            $invoice = Invoice::create([
                'tenant_id' => $tenantId,
                'customer_id' => $validated['customer_id'],
                'invoice_no' => $invoiceNo,
                'period_key' => $validated['period_key'],
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'],
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'tax_amount' => $tax,
                'total_amount' => $total,
                'paid_amount' => 0,
                'status' => 'OPEN',
                'notes' => $validated['notes'] ?? null,
            ]);
            
            // Create items
            foreach ($validated['items'] as $item) {
                InvoiceItem::create([
                    'tenant_id' => $tenantId,
                    'invoice_id' => $invoice->id,
                    'item_type' => $item['item_type'] ?? 'OTHER',
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'amount' => $item['quantity'] * $item['unit_price'],
                ]);
            }
            
            // Log action
            ActionLog::record(
                tenantId: $tenantId,
                action: 'CREATE',
                refType: 'invoice',
                refId: $invoice->id,
                payload: ['invoice_no' => $invoiceNo, 'total' => $total]
            );
            
            DB::commit();
            
            return response()->json([
                'message' => 'Invoice created successfully',
                'data' => $invoice->load('items'),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create invoice: ' . $e->getMessage()], 500);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);
        
        $invoice = Invoice::where('tenant_id', $tenantId)->findOrFail($id);
        
        // Can't delete if has payments
        if ($invoice->paid_amount > 0) {
            return response()->json(['message' => 'Cannot delete invoice with payments'], 422);
        }
        
        DB::beginTransaction();
        try {
            // Delete items
            InvoiceItem::where('invoice_id', $id)->delete();
            
            // Log before delete
            ActionLog::record(
                tenantId: $tenantId,
                action: 'DELETE',
                refType: 'invoice',
                refId: $id,
                payload: ['invoice_no' => $invoice->invoice_no]
            );
            
            $invoice->delete();
            
            DB::commit();
            
            return response()->json(['message' => 'Invoice deleted successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to delete invoice: ' . $e->getMessage()], 500);
        }
    }

    public function generateMonthly(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Generate monthly invoices not implemented yet',
        ], 501);
    }

    public function void(Request $request, int $invoice): JsonResponse
    {
        $tenantId = (int) $request->input('tenant_id', 1);
        
        $inv = Invoice::where('tenant_id', $tenantId)->findOrFail($invoice);
        $inv->update(['status' => 'VOID']);
        
        ActionLog::record(
            tenantId: $tenantId,
            action: 'UPDATE',
            refType: 'invoice',
            refId: $invoice,
            payload: ['status' => 'VOID']
        );
        
        return response()->json(['message' => 'Invoice voided successfully', 'data' => $inv]);
    }
}
