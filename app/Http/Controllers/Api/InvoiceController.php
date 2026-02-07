<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingInvoiceItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    /**
     * Display a listing of invoices.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id', 1);
        $perPage = $request->input('per_page', 20);
        $status = $request->input('status');
        $customerId = $request->input('customer_id');
        $search = $request->input('q');

        $query = BillingInvoice::forTenant($tenantId)
            ->with('customer:id,customer_code,full_name');

        if ($status) {
            $query->where('status', $status);
        }

        if ($customerId) {
            $query->where('customer_id', $customerId);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_no', 'like', "%{$search}%")
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('full_name', 'like', "%{$search}%");
                  });
            });
        }

        $invoices = $query->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $invoices->items(),
            'meta' => [
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
            ],
        ]);
    }

    /**
     * Store a newly created invoice.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id', 1);

        $validated = $request->validate([
            'customer_id' => 'required|exists:noci_billing_customers,id',
            'period_key' => 'required|regex:/^\d{4}-\d{2}$/',
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:issue_date',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'nullable|numeric|min:0',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.item_type' => 'nullable|in:PLAN,DISCOUNT,PENALTY,OTHER',
            'discount_amount' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        // Generate invoice number
        $year = date('Y');
        $lastInvoice = BillingInvoice::forTenant($tenantId)
            ->where('invoice_no', 'like', "INV-{$year}-%")
            ->orderByDesc('id')
            ->first();
        
        $sequence = 1;
        if ($lastInvoice) {
            $parts = explode('-', $lastInvoice->invoice_no);
            $sequence = (int) end($parts) + 1;
        }
        $invoiceNo = sprintf("INV-%s-%04d", $year, $sequence);

        DB::beginTransaction();
        try {
            // Calculate totals
            $subtotal = 0;
            foreach ($validated['items'] as $item) {
                $qty = $item['quantity'] ?? 1;
                $subtotal += $qty * $item['unit_price'];
            }

            $discountAmount = $validated['discount_amount'] ?? 0;
            $taxAmount = $validated['tax_amount'] ?? 0;
            $totalAmount = $subtotal - $discountAmount + $taxAmount;

            $invoice = BillingInvoice::create([
                'tenant_id' => $tenantId,
                'customer_id' => $validated['customer_id'],
                'invoice_no' => $invoiceNo,
                'period_key' => $validated['period_key'],
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'],
                'status' => 'OPEN',
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'paid_amount' => 0,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Create items
            foreach ($validated['items'] as $index => $item) {
                $qty = $item['quantity'] ?? 1;
                BillingInvoiceItem::create([
                    'tenant_id' => $tenantId,
                    'invoice_id' => $invoice->id,
                    'item_type' => $item['item_type'] ?? 'PLAN',
                    'description' => $item['description'],
                    'quantity' => $qty,
                    'unit_price' => $item['unit_price'],
                    'amount' => $qty * $item['unit_price'],
                    'sort_order' => $index,
                ]);
            }

            DB::commit();

            // Log activity
            ActionLog::record(
                $tenantId,
                auth()->id(),
                'CREATE',
                'Invoice',
                $invoice->id,
                ['invoice_no' => $invoiceNo, 'amount' => $totalAmount]
            );

            return response()->json([
                'success' => true,
                'message' => 'Invoice created successfully',
                'data' => $invoice->load(['customer:id,customer_code,full_name', 'items']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create invoice: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified invoice.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id', 1);

        $invoice = BillingInvoice::forTenant($tenantId)
            ->with(['customer', 'items', 'payments'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $invoice,
        ]);
    }

    /**
     * Update the specified invoice.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id', 1);

        $invoice = BillingInvoice::forTenant($tenantId)->findOrFail($id);

        if (in_array($invoice->status, ['PAID', 'VOID'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update paid or voided invoice',
            ], 422);
        }

        $validated = $request->validate([
            'due_date' => 'sometimes|date',
            'status' => 'sometimes|in:OPEN,PARTIAL,PAID,OVERDUE,VOID',
            'notes' => 'nullable|string',
        ]);

        $invoice->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Invoice updated successfully',
            'data' => $invoice->fresh(),
        ]);
    }

    /**
     * Remove the specified invoice.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->input('tenant_id', 1);

        $invoice = BillingInvoice::forTenant($tenantId)->findOrFail($id);

        if ($invoice->payments()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete invoice with existing payments',
            ], 422);
        }

        $invoice->items()->delete();
        $invoice->delete();

        return response()->json([
            'success' => true,
            'message' => 'Invoice deleted successfully',
        ]);
    }

    /**
     * Get invoice statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id', 1);

        $stats = [
            'total' => BillingInvoice::forTenant($tenantId)->count(),
            'open' => BillingInvoice::forTenant($tenantId)->where('status', 'OPEN')->count(),
            'overdue' => BillingInvoice::forTenant($tenantId)->where('status', 'OVERDUE')->count(),
            'paid' => BillingInvoice::forTenant($tenantId)->where('status', 'PAID')->count(),
            'total_outstanding' => BillingInvoice::forTenant($tenantId)
                ->unpaid()
                ->selectRaw('SUM(total_amount - paid_amount) as balance')
                ->value('balance') ?? 0,
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
