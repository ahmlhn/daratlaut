<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinCoa;
use App\Models\FinTx;
use App\Models\FinTxLine;
use App\Models\FinBranch;
use App\Models\FinApproval;
use App\Models\FinPayroll;
use App\Models\FinPayrollItem;
use App\Models\FinSettings;
use App\Models\ActionLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class FinanceController extends Controller
{
    /**
     * Dashboard summary
     */
    public function dashboard(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id ?? 1;
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
        $tenantId = $request->user()->tenant_id ?? 1;
        
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
        $tenantId = $request->user()->tenant_id ?? 1;
        
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
        $request->validate([
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:100',
            'category' => 'required|in:asset,liability,equity,revenue,expense',
            'type' => 'required|in:header,detail',
            'parent_id' => 'nullable|integer',
            'normal_balance' => 'nullable|in:debit,credit',
        ]);
        
        $tenantId = $request->user()->tenant_id ?? 1;
        
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
        
        ActionLog::record('coa', $coa->id, $request->filled('id') ? 'update' : 'create', $data);
        
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
        $tenantId = $request->user()->tenant_id ?? 1;
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
        $tenantId = $request->user()->tenant_id ?? 1;
        $perPage = $request->input('per_page', 20);
        
        $query = FinTx::forTenant($tenantId)
            ->with(['lines.coa', 'branch']);
        
        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('tx_date', [$request->start_date, $request->end_date]);
        }
        
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('ref_no', 'like', "%{$search}%")
                  ->orWhere('party_name', 'like', "%{$search}%");
            });
        }
        
        $transactions = $query->orderBy('tx_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);
        
        return response()->json([
            'status' => 'ok',
            'data' => $transactions->items(),
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
        $tenantId = $request->user()->tenant_id ?? 1;
        
        $tx = FinTx::forTenant($tenantId)
            ->with(['lines.coa', 'branch', 'approval.user'])
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
        $request->validate([
            'tx_date' => 'required|date',
            'description' => 'required|string|max:500',
            'lines' => 'required|array|min:2',
            'lines.*.coa_id' => 'required|integer',
            'lines.*.debit' => 'nullable|numeric|min:0',
            'lines.*.credit' => 'nullable|numeric|min:0',
        ]);
        
        $tenantId = $request->user()->tenant_id ?? 1;
        
        // Validate balanced
        $totalDebit = collect($request->lines)->sum('debit');
        $totalCredit = collect($request->lines)->sum('credit');
        
        if (abs($totalDebit - $totalCredit) > 0.01) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi tidak balance (Debit: ' . number_format($totalDebit, 0) . ', Credit: ' . number_format($totalCredit, 0) . ')',
            ], 422);
        }
        
        DB::beginTransaction();
        try {
            $tx = FinTx::create([
                'tenant_id' => $tenantId,
                'tx_date' => $request->tx_date,
                'ref_no' => $request->ref_no,
                'description' => $request->description,
                'status' => 'PENDING',
                'branch_id' => $request->branch_id,
                'party_name' => $request->party_name,
                'method' => $request->method,
                'bukti' => $request->bukti ?? [],
                'created_by' => auth()->id(),
                'notes' => $request->notes,
            ]);
            
            foreach ($request->lines as $line) {
                FinTxLine::create([
                    'tenant_id' => $tenantId,
                    'tx_id' => $tx->id,
                    'coa_id' => $line['coa_id'],
                    'description' => $line['description'] ?? '',
                    'debit' => $line['debit'] ?? 0,
                    'credit' => $line['credit'] ?? 0,
                ]);
            }
            
            DB::commit();
            
            ActionLog::record('finance_tx', $tx->id, 'create', $tx->toArray());
            
            return response()->json([
                'status' => 'ok',
                'message' => 'Transaksi berhasil dibuat',
                'data' => $tx->load('lines.coa'),
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
        $tenantId = $request->user()->tenant_id ?? 1;
        $tx = FinTx::forTenant($tenantId)->findOrFail($id);
        
        if ($tx->status === 'POSTED') {
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
        $totalDebit = collect($request->lines)->sum('debit');
        $totalCredit = collect($request->lines)->sum('credit');
        
        if (abs($totalDebit - $totalCredit) > 0.01) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi tidak balance',
            ], 422);
        }
        
        DB::beginTransaction();
        try {
            $tx->update([
                'tx_date' => $request->tx_date,
                'ref_no' => $request->ref_no,
                'description' => $request->description,
                'branch_id' => $request->branch_id,
                'party_name' => $request->party_name,
                'method' => $request->method,
                'bukti' => $request->bukti ?? $tx->bukti,
                'notes' => $request->notes,
            ]);
            
            // Delete old lines
            $tx->lines()->delete();
            
            // Create new lines
            foreach ($request->lines as $line) {
                FinTxLine::create([
                    'tenant_id' => $tenantId,
                    'tx_id' => $tx->id,
                    'coa_id' => $line['coa_id'],
                    'description' => $line['description'] ?? '',
                    'debit' => $line['debit'] ?? 0,
                    'credit' => $line['credit'] ?? 0,
                ]);
            }
            
            DB::commit();
            
            ActionLog::record('finance_tx', $tx->id, 'update', $tx->toArray());
            
            return response()->json([
                'status' => 'ok',
                'message' => 'Transaksi berhasil diupdate',
                'data' => $tx->load('lines.coa'),
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
        $tenantId = $request->user()->tenant_id ?? 1;
        $tx = FinTx::forTenant($tenantId)->findOrFail($id);
        
        if ($tx->status === 'POSTED') {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi yang sudah diposting tidak dapat dihapus',
            ], 422);
        }
        
        $tx->lines()->delete();
        $tx->delete();
        
        ActionLog::record('finance_tx', $id, 'delete', ['id' => $id]);
        
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
        $tenantId = $request->user()->tenant_id ?? 1;
        
        $query = FinTx::forTenant($tenantId)
            ->with(['lines.coa', 'branch', 'creator']);
        
        if ($request->filled('status')) {
            $query->where('status', $request->status);
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
        $tenantId = $request->user()->tenant_id ?? 1;
        $tx = FinTx::forTenant($tenantId)->findOrFail($id);
        
        if ($tx->status !== 'PENDING') {
            return response()->json([
                'status' => 'error',
                'message' => 'Hanya transaksi PENDING yang dapat diapprove',
            ], 422);
        }
        
        $tx->update([
            'status' => 'POSTED',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);
        
        FinApproval::create([
            'tenant_id' => $tenantId,
            'tx_id' => $tx->id,
            'action' => 'APPROVE',
            'notes' => $request->notes,
            'user_id' => auth()->id(),
        ]);
        
        ActionLog::record('finance_approval', $tx->id, 'approve', ['notes' => $request->notes]);
        
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
        $tenantId = $request->user()->tenant_id ?? 1;
        $tx = FinTx::forTenant($tenantId)->findOrFail($id);
        
        if ($tx->status !== 'PENDING') {
            return response()->json([
                'status' => 'error',
                'message' => 'Hanya transaksi PENDING yang dapat direject',
            ], 422);
        }
        
        $tx->update([
            'status' => 'REJECTED',
        ]);
        
        FinApproval::create([
            'tenant_id' => $tenantId,
            'tx_id' => $tx->id,
            'action' => 'REJECT',
            'notes' => $request->notes ?? $request->reason,
            'user_id' => auth()->id(),
        ]);
        
        ActionLog::record('finance_approval', $tx->id, 'reject', ['reason' => $request->reason]);
        
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
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);
        
        $tenantId = $request->user()->tenant_id ?? 1;
        $successCount = 0;
        $errors = [];
        
        foreach ($request->ids as $id) {
            try {
                $tx = FinTx::forTenant($tenantId)->findOrFail($id);
                
                if ($tx->status !== 'PENDING') {
                    $errors[] = "TX #{$id}: bukan PENDING";
                    continue;
                }
                
                $tx->update([
                    'status' => 'POSTED',
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                ]);
                
                FinApproval::create([
                    'tenant_id' => $tenantId,
                    'tx_id' => $tx->id,
                    'action' => 'APPROVE',
                    'notes' => $request->notes ?? 'Bulk approve',
                    'user_id' => auth()->id(),
                ]);
                
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
        $tenantId = $request->user()->tenant_id ?? 1;
        
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
        $request->validate([
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:100',
        ]);
        
        $tenantId = $request->user()->tenant_id ?? 1;
        
        $data = [
            'tenant_id' => $tenantId,
            'code' => $request->code,
            'name' => $request->name,
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
        $tenantId = $request->user()->tenant_id ?? 1;
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
        $tenantId = $request->user()->tenant_id ?? 1;
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
        $tenantId = $request->user()->tenant_id ?? 1;
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
        $request->validate([
            'coa_id' => 'required|integer',
        ]);
        
        $tenantId = $request->user()->tenant_id ?? 1;
        $startDate = $request->start_date ?? now()->startOfMonth()->format('Y-m-d');
        $endDate = $request->end_date ?? now()->format('Y-m-d');
        
        $coa = FinCoa::forTenant($tenantId)->findOrFail($request->coa_id);
        
        // Get opening balance
        $openingBalance = $this->getCoaBalance($coa, $tenantId, null, date('Y-m-d', strtotime($startDate . ' -1 day')));
        
        // Get transactions in period
        $lines = FinTxLine::forTenant($tenantId)
            ->where('coa_id', $request->coa_id)
            ->whereHas('transaction', function ($q) use ($startDate, $endDate) {
                $q->whereBetween('tx_date', [$startDate, $endDate])
                  ->where('status', 'POSTED');
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
                'description' => $line->description ?: $line->transaction->description,
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
            if ($coa->parent_id === $parentId) {
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
        
        return [
            'id' => $tx->id,
            'tx_date' => $tx->tx_date->format('Y-m-d'),
            'ref_no' => $tx->ref_no,
            'description' => $tx->description,
            'status' => $tx->status,
            'branch' => $tx->branch?->name,
            'party_name' => $tx->party_name,
            'method' => $tx->method,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'bukti' => $tx->bukti,
            'lines' => $tx->lines->map(fn($l) => [
                'id' => $l->id,
                'coa_id' => $l->coa_id,
                'coa_code' => $l->coa?->code,
                'coa_name' => $l->coa?->name,
                'description' => $l->description,
                'debit' => $l->debit,
                'credit' => $l->credit,
            ]),
            'created_at' => $tx->created_at?->format('Y-m-d H:i'),
            'created_by' => $tx->creator?->username,
            'approved_at' => $tx->approved_at?->format('Y-m-d H:i'),
            'approved_by' => $tx->approver?->username,
        ];
    }
}
