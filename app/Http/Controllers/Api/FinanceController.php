<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\FinApproval;
use App\Models\FinBranch;
use App\Models\FinCoa;
use App\Models\FinTx;
use App\Models\FinTxLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\SuperAdminAccess;

class FinanceController extends Controller
{
    /**
     * Dashboard summary
     */
    public function dashboard(Request $request): JsonResponse
    {
        if ($forbidden = $this->authorizeFinance($request, ['view finance'])) {
            return $forbidden;
        }

        $tenantId = $this->tenantId($request);
        $today = now()->format('Y-m-d');
        $startOfMonth = now()->startOfMonth()->format('Y-m-d');
        $endOfMonth = now()->endOfMonth()->format('Y-m-d');
        
        // Total balance (assets - liabilities)
        $totalBalance = $this->calculateBalance($tenantId);
        
        // Pending approvals
        $pendingApprovals = FinTx::forTenant($tenantId)->pending()->count();
        
        // Posted today
        $postedToday = FinTx::forTenant($tenantId)
            ->posted()
            ->whereDate('approved_at', $today)
            ->count();
        
        // Revenue this month
        $revenueThisMonth = $this->calculateRevenue($tenantId, $startOfMonth, $endOfMonth);
        
        // Expense this month
        $expenseThisMonth = $this->calculateExpense($tenantId, $startOfMonth, $endOfMonth);
        
        // Recent transactions
        $recentTx = FinTx::forTenant($tenantId)
            ->with(['lines.coa', 'branch'])
            ->orderBy('tx_date', 'desc')
            ->limit(10)
            ->get()
            ->map(fn($tx) => $this->formatTransaction($tx));
        
        return response()->json([
            'status' => 'ok',
            'data' => [
                'total_balance' => $totalBalance,
                'pending_approvals' => $pendingApprovals,
                'posted_today' => $postedToday,
                'revenue_this_month' => $revenueThisMonth,
                'expense_this_month' => $expenseThisMonth,
                'profit_this_month' => $revenueThisMonth - $expenseThisMonth,
                'recent_transactions' => $recentTx,
            ],
        ]);
    }

    // ==================== COA ====================

    /**
     * List all COA (tree structure)
     */
    public function listCoa(Request $request): JsonResponse
    {
        if ($forbidden = $this->authorizeFinance($request, ['view finance'])) {
            return $forbidden;
        }

        $tenantId = $this->tenantId($request);
        
        $coas = FinCoa::forTenant($tenantId)
            ->orderBy('code')
            ->get();
        
        // Build tree
        $tree = $this->buildCoaTree($coas);
        
        return response()->json([
            'status' => 'ok',
            'data' => $tree,
            'flat' => $coas,
        ]);
    }

    /**
     * Get COA dropdown (active details only)
     */
    public function coaDropdown(Request $request): JsonResponse
    {
        if ($forbidden = $this->authorizeFinance($request, ['view finance'])) {
            return $forbidden;
        }

        $tenantId = $this->tenantId($request);
        
        $coas = FinCoa::forTenant($tenantId)
            ->active()
            ->details()
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'category', 'normal_balance']);
        
        return response()->json([
            'status' => 'ok',
            'data' => $coas,
        ]);
    }

    /**
     * Create/Update COA
     */
    public function saveCoa(Request $request): JsonResponse
    {
        $requiredPermission = $request->filled('id') ? 'edit finance' : 'create finance';
        if ($forbidden = $this->authorizeFinance($request, [$requiredPermission])) {
            return $forbidden;
        }

        $request->validate([
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:100',
            'category' => 'required|in:asset,liability,equity,revenue,expense,other',
            'type' => 'required|in:header,detail',
            'parent_id' => 'nullable|integer',
            'normal_balance' => 'nullable|in:debit,credit',
        ]);
        
        $tenantId = $this->tenantId($request);
        
        $data = [
            'tenant_id' => $tenantId,
            'code' => $request->code,
            'name' => $request->name,
            'category' => $request->category,
            'type' => $request->type,
            'parent_id' => $request->parent_id,
            'normal_balance' => $request->normal_balance ?? ($request->category === 'asset' || $request->category === 'expense' ? 'debit' : 'credit'),
            'is_active' => $request->is_active ?? true,
            'description' => $request->description,
        ];
        
        if ($request->filled('id')) {
            $coa = FinCoa::forTenant($tenantId)->findOrFail($request->id);
            $coa->update($data);
            $message = 'COA berhasil diupdate';
        } else {
            $coa = FinCoa::create($data);
            $message = 'COA berhasil ditambahkan';
        }
        
        ActionLog::record(
            $tenantId,
            auth()->id(),
            $request->filled('id') ? 'UPDATE' : 'CREATE',
            'finance_coa',
            $coa->id,
            $data
        );
        
        return response()->json([
            'status' => 'ok',
            'message' => $message,
            'data' => $coa,
        ]);
    }

    /**
     * Delete COA
     */
    public function deleteCoa(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->authorizeFinance($request, ['delete finance'])) {
            return $forbidden;
        }

        $tenantId = $this->tenantId($request);
        $coa = FinCoa::forTenant($tenantId)->findOrFail($id);
        
        // Check if has children
        if ($coa->children()->count() > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Tidak dapat menghapus COA yang memiliki sub-akun',
            ], 422);
        }
        
        // Check if has transactions
        if ($coa->transactionLines()->count() > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Tidak dapat menghapus COA yang sudah memiliki transaksi',
            ], 422);
        }
        
        $coa->delete();
        
        return response()->json([
            'status' => 'ok',
            'message' => 'COA berhasil dihapus',
        ]);
    }

    // ==================== Transactions ====================

    /**
     * List transactions with filters
     */
    public function listTransactions(Request $request): JsonResponse
    {
        if ($forbidden = $this->authorizeFinance($request, ['view finance'])) {
            return $forbidden;
        }

        $tenantId = $this->tenantId($request);
        $perPage = max(1, (int) $request->input('per_page', 20));
        
        $query = FinTx::forTenant($tenantId)
            ->with(['lines.coa', 'branch', 'creator', 'approver']);
        
        // Filters
        if ($request->filled('status')) {
            $status = $this->normalizeDbStatus($request->status);
            if (!$status) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Status transaksi tidak valid',
                ], 422);
            }

            $query->where('status', $status);
        }
        
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('tx_date', [$request->start_date, $request->end_date]);
        }
        
        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('ref_no', 'like', "%{$search}%")
                  ->orWhereHas('lines', function ($lineQuery) use ($search) {
                      $lineQuery->where('line_desc', 'like', "%{$search}%")
                          ->orWhere('party_name', 'like', "%{$search}%");
                  });
            });
        }
        
        $transactions = $query->orderBy('tx_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        $formatted = collect($transactions->items())
            ->map(fn($tx) => $this->formatTransaction($tx))
            ->values()
            ->all();
        
        return response()->json([
            'status' => 'ok',
            'data' => $formatted,
            'pagination' => [
                'total' => $transactions->total(),
                'per_page' => $transactions->perPage(),
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
            ],
        ]);
    }

    /**
     * Get transaction detail
     */
    public function getTransaction(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->authorizeFinance($request, ['view finance'])) {
            return $forbidden;
        }

        $tenantId = $this->tenantId($request);
        
        $tx = FinTx::forTenant($tenantId)
            ->with(['lines.coa', 'branch', 'approval.user', 'creator', 'approver'])
            ->findOrFail($id);
        
        return response()->json([
            'status' => 'ok',
            'data' => $this->formatTransaction($tx),
        ]);
    }

    /**
     * Create transaction
     */
    public function createTransaction(Request $request): JsonResponse
    {
        if ($forbidden = $this->authorizeFinance($request, ['create finance'])) {
            return $forbidden;
        }

        $request->validate([
            'tx_date' => 'required|date',
            'description' => 'required|string|max:500',
            'lines' => 'required|array|min:2',
            'lines.*.coa_id' => 'required|integer',
            'lines.*.debit' => 'nullable|numeric|min:0',
            'lines.*.credit' => 'nullable|numeric|min:0',
        ]);
        
        $tenantId = $this->tenantId($request);
        $user = $request->user();
        $partyName = trim((string) $request->input('party_name', ''));
        
        // Validate balanced
        $totalDebit = (float) collect($request->lines)->sum('debit');
        $totalCredit = (float) collect($request->lines)->sum('credit');
        
        if (abs($totalDebit - $totalCredit) > 0.01) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi tidak balance (Debit: ' . number_format($totalDebit, 0) . ', Credit: ' . number_format($totalCredit, 0) . ')',
            ], 422);
        }

        $coaIds = collect($request->lines)
            ->pluck('coa_id')
            ->map(fn($coaId) => (int) $coaId)
            ->unique()
            ->values();

        $coaCodes = FinCoa::forTenant($tenantId)
            ->whereIn('id', $coaIds)
            ->pluck('code', 'id');

        if ($coaCodes->count() !== $coaIds->count()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Sebagian akun COA tidak valid untuk tenant ini',
            ], 422);
        }
        
        DB::beginTransaction();
        try {
            $tx = FinTx::create([
                'tenant_id' => $tenantId,
                'tx_date' => $request->tx_date,
                'ref_no' => $request->ref_no,
                'description' => $request->description,
                'status' => FinTx::STATUS_PENDING,
                'branch_id' => $request->branch_id,
                'method' => $request->method,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'created_by' => $user?->id,
                'created_by_name' => $user?->name ?? $user?->username,
                'created_role' => $user?->role,
            ]);
            
            foreach ($request->lines as $line) {
                $coaId = (int) ($line['coa_id'] ?? 0);
                $linePartyName = trim((string) ($line['party_name'] ?? $partyName));
                FinTxLine::create([
                    'tenant_id' => $tenantId,
                    'tx_id' => $tx->id,
                    'coa_id' => $coaId,
                    'coa_code' => $coaCodes[$coaId] ?? null,
                    'line_desc' => trim((string) ($line['description'] ?? '')),
                    'debit' => (float) ($line['debit'] ?? 0),
                    'credit' => (float) ($line['credit'] ?? 0),
                    'party_name' => $linePartyName !== '' ? $linePartyName : null,
                ]);
            }

            FinApproval::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'tx_id' => $tx->id,
                ],
                [
                    'status' => 'pending',
                    'requested_by' => $user?->id,
                    'requested_role' => $user?->role,
                    'requested_at' => now(),
                    'approved_by' => null,
                    'approved_at' => null,
                    'note' => $request->notes,
                ]
            );
            
            DB::commit();
            
            ActionLog::record(
                $tenantId,
                $user?->id,
                'CREATE',
                'finance_tx',
                $tx->id,
                [
                    'ref_no' => $tx->ref_no,
                    'tx_date' => $tx->tx_date?->format('Y-m-d'),
                    'total_debit' => $totalDebit,
                    'total_credit' => $totalCredit,
                ]
            );

            $tx->load(['lines.coa', 'branch', 'creator', 'approver']);
            
            return response()->json([
                'status' => 'ok',
                'message' => 'Transaksi berhasil dibuat',
                'data' => $this->formatTransaction($tx),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal membuat transaksi: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update transaction
     */
    public function updateTransaction(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->authorizeFinance($request, ['edit finance'])) {
            return $forbidden;
        }

        $tenantId = $this->tenantId($request);
        $tx = FinTx::forTenant($tenantId)->findOrFail($id);
        $user = $request->user();
        $partyName = trim((string) $request->input('party_name', ''));
        
        if ($tx->isPosted()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi yang sudah diposting tidak dapat diedit',
            ], 422);
        }
        
        $request->validate([
            'tx_date' => 'required|date',
            'description' => 'required|string|max:500',
            'lines' => 'required|array|min:2',
        ]);
        
        // Validate balanced
        $totalDebit = (float) collect($request->lines)->sum('debit');
        $totalCredit = (float) collect($request->lines)->sum('credit');
        
        if (abs($totalDebit - $totalCredit) > 0.01) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi tidak balance',
            ], 422);
        }

        $coaIds = collect($request->lines)
            ->pluck('coa_id')
            ->map(fn($coaId) => (int) $coaId)
            ->unique()
            ->values();

        $coaCodes = FinCoa::forTenant($tenantId)
            ->whereIn('id', $coaIds)
            ->pluck('code', 'id');

        if ($coaCodes->count() !== $coaIds->count()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Sebagian akun COA tidak valid untuk tenant ini',
            ], 422);
        }
        
        DB::beginTransaction();
        try {
            $tx->update([
                'tx_date' => $request->tx_date,
                'ref_no' => $request->ref_no,
                'description' => $request->description,
                'branch_id' => $request->branch_id,
                'method' => $request->method,
                'status' => FinTx::STATUS_PENDING,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'approved_by' => null,
                'approved_at' => null,
                'posted_at' => null,
            ]);
            
            // Delete old lines
            $tx->lines()->delete();
            
            // Create new lines
            foreach ($request->lines as $line) {
                $coaId = (int) ($line['coa_id'] ?? 0);
                $linePartyName = trim((string) ($line['party_name'] ?? $partyName));
                FinTxLine::create([
                    'tenant_id' => $tenantId,
                    'tx_id' => $tx->id,
                    'coa_id' => $coaId,
                    'coa_code' => $coaCodes[$coaId] ?? null,
                    'line_desc' => trim((string) ($line['description'] ?? '')),
                    'debit' => (float) ($line['debit'] ?? 0),
                    'credit' => (float) ($line['credit'] ?? 0),
                    'party_name' => $linePartyName !== '' ? $linePartyName : null,
                ]);
            }

            FinApproval::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'tx_id' => $tx->id,
                ],
                [
                    'status' => 'pending',
                    'requested_by' => $user?->id ?? $tx->created_by,
                    'requested_role' => $user?->role ?? $tx->created_role,
                    'requested_at' => now(),
                    'approved_by' => null,
                    'approved_at' => null,
                    'note' => $request->notes,
                ]
            );
            
            DB::commit();
            
            ActionLog::record(
                $tenantId,
                $user?->id,
                'UPDATE',
                'finance_tx',
                $tx->id,
                [
                    'ref_no' => $tx->ref_no,
                    'tx_date' => $tx->tx_date?->format('Y-m-d'),
                    'total_debit' => $totalDebit,
                    'total_credit' => $totalCredit,
                ]
            );

            $tx->load(['lines.coa', 'branch', 'creator', 'approver']);
            
            return response()->json([
                'status' => 'ok',
                'message' => 'Transaksi berhasil diupdate',
                'data' => $this->formatTransaction($tx),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal update transaksi: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete transaction
     */
    public function deleteTransaction(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->authorizeFinance($request, ['delete finance'])) {
            return $forbidden;
        }

        $tenantId = $this->tenantId($request);
        $user = $request->user();
        $tx = FinTx::forTenant($tenantId)->findOrFail($id);
        
        if ($tx->isPosted()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi yang sudah diposting tidak dapat dihapus',
            ], 422);
        }
        
        $tx->lines()->delete();
        FinApproval::forTenant($tenantId)->where('tx_id', $tx->id)->delete();
        $tx->delete();
        
        ActionLog::record(
            $tenantId,
            $user?->id,
            'DELETE',
            'finance_tx',
            $id,
            ['id' => $id]
        );
        
        return response()->json([
            'status' => 'ok',
            'message' => 'Transaksi berhasil dihapus',
        ]);
    }

    // ==================== Approvals ====================

    /**
     * List pending approvals
     */
    public function listApprovals(Request $request): JsonResponse
    {
        if ($forbidden = $this->authorizeFinance($request, ['approve finance'])) {
            return $forbidden;
        }

        $tenantId = $this->tenantId($request);
        
        $query = FinTx::forTenant($tenantId)
            ->with(['lines.coa', 'branch', 'creator', 'approver']);
        
        if ($request->filled('status')) {
            $status = $this->normalizeDbStatus($request->status);
            if (!$status) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Status transaksi tidak valid',
                ], 422);
            }

            $query->where('status', $status);
        } else {
            $query->pending();
        }
        
        $transactions = $query->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($tx) => $this->formatTransaction($tx));
        
        return response()->json([
            'status' => 'ok',
            'data' => $transactions,
        ]);
    }

    /**
     * Approve transaction
     */
    public function approveTransaction(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->authorizeFinance($request, ['approve finance'])) {
            return $forbidden;
        }

        $tenantId = $this->tenantId($request);
        $user = $request->user();
        $tx = FinTx::forTenant($tenantId)->findOrFail($id);
        
        if (!$tx->isPending()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Hanya transaksi PENDING yang dapat diapprove',
            ], 422);
        }
        
        $tx->update([
            'status' => FinTx::STATUS_POSTED,
            'approved_by' => $user?->id,
            'approved_at' => now(),
            'posted_at' => now(),
        ]);
        
        FinApproval::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'tx_id' => $tx->id,
            ],
            [
                'status' => 'approved',
                'requested_by' => $tx->created_by,
                'requested_role' => $tx->created_role,
                'requested_at' => $tx->created_at,
                'approved_by' => $user?->id,
                'approved_at' => now(),
                'note' => $request->notes,
            ]
        );
        
        ActionLog::record(
            $tenantId,
            $user?->id,
            'APPROVE',
            'finance_tx',
            $tx->id,
            ['notes' => $request->notes]
        );
        
        return response()->json([
            'status' => 'ok',
            'message' => 'Transaksi berhasil diapprove',
        ]);
    }

    /**
     * Reject transaction
     */
    public function rejectTransaction(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->authorizeFinance($request, ['approve finance'])) {
            return $forbidden;
        }

        $tenantId = $this->tenantId($request);
        $user = $request->user();
        $tx = FinTx::forTenant($tenantId)->findOrFail($id);
        
        if (!$tx->isPending()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Hanya transaksi PENDING yang dapat direject',
            ], 422);
        }
        
        $tx->update([
            'status' => FinTx::STATUS_REJECTED,
            'approved_by' => $user?->id,
            'approved_at' => now(),
            'posted_at' => null,
        ]);
        
        FinApproval::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'tx_id' => $tx->id,
            ],
            [
                'status' => 'rejected',
                'requested_by' => $tx->created_by,
                'requested_role' => $tx->created_role,
                'requested_at' => $tx->created_at,
                'approved_by' => $user?->id,
                'approved_at' => now(),
                'note' => $request->notes ?? $request->reason,
            ]
        );
        
        ActionLog::record(
            $tenantId,
            $user?->id,
            'REJECT',
            'finance_tx',
            $tx->id,
            ['reason' => $request->reason]
        );
        
        return response()->json([
            'status' => 'ok',
            'message' => 'Transaksi berhasil direject',
        ]);
    }

    /**
     * Bulk approve transactions
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        if ($forbidden = $this->authorizeFinance($request, ['approve finance'])) {
            return $forbidden;
        }

        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);
        
        $tenantId = $this->tenantId($request);
        $user = $request->user();
        $successCount = 0;
        $errors = [];
        
        foreach (collect($request->ids)->unique()->all() as $id) {
            try {
                $tx = FinTx::forTenant($tenantId)->findOrFail($id);
                
                if (!$tx->isPending()) {
                    $errors[] = "TX #{$id}: bukan PENDING";
                    continue;
                }
                
                $tx->update([
                    'status' => FinTx::STATUS_POSTED,
                    'approved_by' => $user?->id,
                    'approved_at' => now(),
                    'posted_at' => now(),
                ]);
                
                FinApproval::updateOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'tx_id' => $tx->id,
                    ],
                    [
                        'status' => 'approved',
                        'requested_by' => $tx->created_by,
                        'requested_role' => $tx->created_role,
                        'requested_at' => $tx->created_at,
                        'approved_by' => $user?->id,
                        'approved_at' => now(),
                        'note' => $request->notes ?? 'Bulk approve',
                    ]
                );
                
                $successCount++;
            } catch (\Exception $e) {
                $errors[] = "TX #{$id}: " . $e->getMessage();
            }
        }
        
        return response()->json([
            'status' => 'ok',
            'message' => "{$successCount} transaksi berhasil diapprove",
            'success_count' => $successCount,
            'errors' => $errors,
        ]);
    }

    // ==================== Branches ====================

    /**
     * List branches
     */
    public function listBranches(Request $request): JsonResponse
    {
        if ($forbidden = $this->authorizeFinance($request, ['view finance'])) {
            return $forbidden;
        }

        $tenantId = $this->tenantId($request);
        
        $branches = FinBranch::forTenant($tenantId)
            ->orderBy('name')
            ->get();
        
        return response()->json([
            'status' => 'ok',
            'data' => $branches,
        ]);
    }

    /**
     * Save branch
     */
    public function saveBranch(Request $request): JsonResponse
    {
        $requiredPermission = $request->filled('id') ? 'edit finance' : 'create finance';
        if ($forbidden = $this->authorizeFinance($request, [$requiredPermission])) {
            return $forbidden;
        }

        $request->validate([
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:80',
            'mode' => 'nullable|in:standalone,consolidated',
        ]);
        
        $tenantId = $this->tenantId($request);
        
        $data = [
            'tenant_id' => $tenantId,
            'code' => $request->code,
            'name' => $request->name,
            'mode' => $request->mode ?? 'standalone',
            'is_active' => $request->is_active ?? true,
        ];
        
        if ($request->filled('id')) {
            $branch = FinBranch::forTenant($tenantId)->findOrFail($request->id);
            $branch->update($data);
        } else {
            $branch = FinBranch::create($data);
        }
        
        return response()->json([
            'status' => 'ok',
            'message' => 'Cabang berhasil disimpan',
            'data' => $branch,
        ]);
    }

    // ==================== Reports ====================

    /**
     * Trial balance report
     */
    public function trialBalance(Request $request): JsonResponse
    {
        if ($forbidden = $this->authorizeFinance($request, ['view finance', 'view reports'])) {
            return $forbidden;
        }

        $tenantId = $this->tenantId($request);
        $endDate = $request->end_date ?? now()->format('Y-m-d');
        
        $coas = FinCoa::forTenant($tenantId)
            ->active()
            ->details()
            ->orderBy('code')
            ->get();
        
        $report = [];
        $totalDebit = 0;
        $totalCredit = 0;
        
        foreach ($coas as $coa) {
            $balance = $this->getCoaBalance($coa, $tenantId, null, $endDate);
            
            if ($balance == 0) continue;
            
            $debit = $coa->isDebit() ? abs($balance) : 0;
            $credit = !$coa->isDebit() ? abs($balance) : 0;
            
            // Adjust for negative balances
            if ($balance < 0) {
                $debit = !$coa->isDebit() ? abs($balance) : 0;
                $credit = $coa->isDebit() ? abs($balance) : 0;
            }
            
            $report[] = [
                'code' => $coa->code,
                'name' => $coa->name,
                'category' => $coa->category,
                'debit' => $debit,
                'credit' => $credit,
            ];
            
            $totalDebit += $debit;
            $totalCredit += $credit;
        }
        
        return response()->json([
            'status' => 'ok',
            'data' => [
                'report' => $report,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'is_balanced' => abs($totalDebit - $totalCredit) < 0.01,
            ],
        ]);
    }

    /**
     * Income statement (Laba Rugi)
     */
    public function incomeStatement(Request $request): JsonResponse
    {
        if ($forbidden = $this->authorizeFinance($request, ['view finance', 'view reports'])) {
            return $forbidden;
        }

        $tenantId = $this->tenantId($request);
        $startDate = $request->start_date ?? now()->startOfMonth()->format('Y-m-d');
        $endDate = $request->end_date ?? now()->format('Y-m-d');
        
        $revenue = $this->calculateRevenue($tenantId, $startDate, $endDate);
        $expense = $this->calculateExpense($tenantId, $startDate, $endDate);
        
        // Get detailed breakdown
        $revenueDetails = $this->getCategoryBreakdown($tenantId, 'revenue', $startDate, $endDate);
        $expenseDetails = $this->getCategoryBreakdown($tenantId, 'expense', $startDate, $endDate);
        
        return response()->json([
            'status' => 'ok',
            'data' => [
                'period' => ['start' => $startDate, 'end' => $endDate],
                'revenue' => [
                    'total' => $revenue,
                    'details' => $revenueDetails,
                ],
                'expense' => [
                    'total' => $expense,
                    'details' => $expenseDetails,
                ],
                'net_income' => $revenue - $expense,
            ],
        ]);
    }

    /**
     * Balance sheet (Posisi Keuangan)
     */
    public function balanceSheet(Request $request): JsonResponse
    {
        if ($forbidden = $this->authorizeFinance($request, ['view finance', 'view reports'])) {
            return $forbidden;
        }

        $tenantId = $this->tenantId($request);
        $endDate = $request->end_date ?? now()->format('Y-m-d');
        
        $assets = $this->getCategoryBreakdown($tenantId, 'asset', null, $endDate);
        $liabilities = $this->getCategoryBreakdown($tenantId, 'liability', null, $endDate);
        $equity = $this->getCategoryBreakdown($tenantId, 'equity', null, $endDate);
        
        $totalAssets = collect($assets)->sum('balance');
        $totalLiabilities = collect($liabilities)->sum('balance');
        $totalEquity = collect($equity)->sum('balance');
        
        // Calculate retained earnings
        $retainedEarnings = $totalAssets - $totalLiabilities - $totalEquity;
        
        return response()->json([
            'status' => 'ok',
            'data' => [
                'date' => $endDate,
                'assets' => [
                    'total' => $totalAssets,
                    'details' => $assets,
                ],
                'liabilities' => [
                    'total' => $totalLiabilities,
                    'details' => $liabilities,
                ],
                'equity' => [
                    'total' => $totalEquity + $retainedEarnings,
                    'details' => $equity,
                    'retained_earnings' => $retainedEarnings,
                ],
            ],
        ]);
    }

    /**
     * Account ledger
     */
    public function accountLedger(Request $request): JsonResponse
    {
        if ($forbidden = $this->authorizeFinance($request, ['view finance', 'view reports'])) {
            return $forbidden;
        }

        $request->validate([
            'coa_id' => 'required|integer',
        ]);
        
        $tenantId = $this->tenantId($request);
        $startDate = $request->start_date ?? now()->startOfMonth()->format('Y-m-d');
        $endDate = $request->end_date ?? now()->format('Y-m-d');
        
        $coa = FinCoa::forTenant($tenantId)->findOrFail($request->coa_id);
        
        // Get opening balance
        $openingBalance = $this->getCoaBalance($coa, $tenantId, null, date('Y-m-d', strtotime($startDate . ' -1 day')));
        
        // Get transactions in period
        $lines = FinTxLine::forTenant($tenantId)
            ->where('coa_id', $request->coa_id)
            ->whereHas('transaction', function ($q) use ($startDate, $endDate) {
                $q->whereBetween('tx_date', [$startDate, $endDate])->posted();
            })
            ->with('transaction')
            ->orderBy('id')
            ->get();
        
        $runningBalance = $openingBalance;
        $ledger = [];
        
        foreach ($lines as $line) {
            if ($coa->isDebit()) {
                $runningBalance += ($line->debit - $line->credit);
            } else {
                $runningBalance += ($line->credit - $line->debit);
            }
            
            $ledger[] = [
                'date' => $line->transaction->tx_date->format('Y-m-d'),
                'ref_no' => $line->transaction->ref_no,
                'description' => $line->line_desc ?: $line->transaction->description,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'balance' => $runningBalance,
            ];
        }
        
        return response()->json([
            'status' => 'ok',
            'data' => [
                'coa' => $coa,
                'period' => ['start' => $startDate, 'end' => $endDate],
                'opening_balance' => $openingBalance,
                'closing_balance' => $runningBalance,
                'ledger' => $ledger,
            ],
        ]);
    }

    // ==================== Helpers ====================

    private function calculateBalance(int $tenantId): float
    {
        $assets = FinTxLine::forTenant($tenantId)
            ->whereHas('coa', fn($q) => $q->where('category', 'asset'))
            ->whereHas('transaction', fn($q) => $q->posted())
            ->selectRaw('SUM(debit) - SUM(credit) as balance')
            ->value('balance') ?? 0;
        
        $liabilities = FinTxLine::forTenant($tenantId)
            ->whereHas('coa', fn($q) => $q->where('category', 'liability'))
            ->whereHas('transaction', fn($q) => $q->posted())
            ->selectRaw('SUM(credit) - SUM(debit) as balance')
            ->value('balance') ?? 0;
        
        return $assets - $liabilities;
    }

    private function calculateRevenue(int $tenantId, string $startDate, string $endDate): float
    {
        return FinTxLine::forTenant($tenantId)
            ->whereHas('coa', fn($q) => $q->where('category', 'revenue'))
            ->whereHas('transaction', fn($q) => $q->posted()->whereBetween('tx_date', [$startDate, $endDate]))
            ->selectRaw('SUM(credit) - SUM(debit) as total')
            ->value('total') ?? 0;
    }

    private function calculateExpense(int $tenantId, string $startDate, string $endDate): float
    {
        return FinTxLine::forTenant($tenantId)
            ->whereHas('coa', fn($q) => $q->where('category', 'expense'))
            ->whereHas('transaction', fn($q) => $q->posted()->whereBetween('tx_date', [$startDate, $endDate]))
            ->selectRaw('SUM(debit) - SUM(credit) as total')
            ->value('total') ?? 0;
    }

    private function getCoaBalance(FinCoa $coa, int $tenantId, ?string $startDate, string $endDate): float
    {
        $query = FinTxLine::forTenant($tenantId)
            ->where('coa_id', $coa->id)
            ->whereHas('transaction', function ($q) use ($startDate, $endDate) {
                $q->posted()->where('tx_date', '<=', $endDate);
                if ($startDate) {
                    $q->where('tx_date', '>=', $startDate);
                }
            });
        
        $debit = $query->sum('debit');
        $credit = $query->sum('credit');
        
        return $coa->isDebit() ? ($debit - $credit) : ($credit - $debit);
    }

    private function getCategoryBreakdown(int $tenantId, string $category, ?string $startDate, string $endDate): array
    {
        $coas = FinCoa::forTenant($tenantId)
            ->active()
            ->details()
            ->where('category', $category)
            ->orderBy('code')
            ->get();
        
        $breakdown = [];
        foreach ($coas as $coa) {
            $balance = $this->getCoaBalance($coa, $tenantId, $startDate, $endDate);
            if ($balance != 0) {
                $breakdown[] = [
                    'code' => $coa->code,
                    'name' => $coa->name,
                    'balance' => abs($balance),
                ];
            }
        }
        
        return $breakdown;
    }

    private function buildCoaTree($coas, $parentId = null): array
    {
        $tree = [];
        foreach ($coas as $coa) {
            if ($coa->parent_id == $parentId) {
                $node = $coa->toArray();
                $node['children'] = $this->buildCoaTree($coas, $coa->id);
                $tree[] = $node;
            }
        }
        return $tree;
    }

    private function formatTransaction($tx): array
    {
        $totalDebit = $tx->lines->sum('debit');
        $totalCredit = $tx->lines->sum('credit');
        $partyName = $tx->lines->pluck('party_name')->filter()->first();
        
        return [
            'id' => $tx->id,
            'tx_date' => $tx->tx_date->format('Y-m-d'),
            'ref_no' => $tx->ref_no,
            'description' => $tx->description,
            'status' => strtoupper((string) $tx->status),
            'branch_id' => $tx->branch_id,
            'branch' => $tx->branch?->name,
            'party_name' => $partyName,
            'method' => $tx->method,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'lines' => $tx->lines->map(fn($l) => [
                'id' => $l->id,
                'coa_id' => $l->coa_id,
                'coa_code' => $l->coa_code ?: $l->coa?->code,
                'coa_name' => $l->coa?->name,
                'description' => $l->line_desc,
                'debit' => $l->debit,
                'credit' => $l->credit,
                'party_name' => $l->party_name,
            ]),
            'created_at' => $tx->created_at?->format('Y-m-d H:i'),
            'created_by' => $tx->creator?->username ?? $tx->creator?->name ?? $tx->created_by_name,
            'approved_at' => $tx->approved_at?->format('Y-m-d H:i'),
            'approved_by' => $tx->approver?->username ?? $tx->approver?->name ?? $tx->approved_by_name,
        ];
    }

    private function tenantId(Request $request): int
    {
        return (int) ($request->user()?->tenant_id ?? 1);
    }

    private function authorizeFinance(Request $request, array $permissions): ?JsonResponse
    {
        $user = $request->user() ?: auth()->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (SuperAdminAccess::hasAccess($user)) {
            return null;
        }

        $legacyRole = $this->normalizeRole($user->role ?? null);
        if (in_array($legacyRole, ['admin', 'owner'], true)) {
            return null;
        }

        $required = collect($permissions)
            ->push('manage finance')
            ->map(fn($perm) => trim((string) $perm))
            ->filter()
            ->unique()
            ->values();

        if (method_exists($user, 'can')) {
            foreach ($required as $permission) {
                if ($user->can($permission)) {
                    return null;
                }
            }
        }

        return response()->json(['message' => 'Forbidden'], 403);
    }

    private function normalizeRole(?string $role): string
    {
        $normalized = strtolower(trim((string) $role));
        return $normalized === 'svp lapangan' ? 'svp_lapangan' : $normalized;
    }

    private function normalizeDbStatus(?string $status): ?string
    {
        $value = strtolower(trim((string) $status));
        if ($value === '') {
            return null;
        }

        return in_array($value, FinTx::STATUSES, true) ? $value : null;
    }
}
