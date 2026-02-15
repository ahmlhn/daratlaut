<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\Installation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeknisiController extends Controller
{
    private function tenantId(Request $request): int
    {
        return (int) ($request->attributes->get('tenant_id') ?? 0);
    }

    private function normalizeLegacyRole(?string $role): string
    {
        $role = strtolower(trim((string) $role));
        if ($role === 'svp lapangan') return 'svp_lapangan';
        return $role;
    }

    private function userCan(Request $request, string $permission): bool
    {
        $user = $request->user();
        if (!$user) return false;

        $legacyRole = $this->normalizeLegacyRole($user->role ?? null);
        if (in_array($legacyRole, ['admin', 'owner'], true)) return true;

        return method_exists($user, 'can') && $user->can($permission);
    }

    private function userCanAny(Request $request, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->userCan($request, (string) $permission)) return true;
        }
        return false;
    }

    private function requireAnyPermission(Request $request, array $permissions): ?JsonResponse
    {
        if ($this->userCanAny($request, $permissions)) return null;
        return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
    }

    private function actorName(Request $request): string
    {
        $name = (string) ($request->input('tech_name') ?? ($request->user()?->name ?? ''));
        return trim($name);
    }

    private function assignedFilter(string $techName): \Closure
    {
        return function ($qb) use ($techName) {
            $qb->where('technician', $techName)
                ->orWhere('technician_2', $techName)
                ->orWhere('technician_3', $techName)
                ->orWhere('technician_4', $techName);
        };
    }

    private function selectAliasColumns(): array
    {
        // Keep response keys compatible with existing Teknisi Vue pages (nama/wa/alamat/koordinat/...),
        // while reading from the actual schema (customer_name/customer_phone/address/coordinates/...).
        return [
            'id',
            'ticket_id',
            'pop',
            'status',
            'created_at',
            'is_priority',
            DB::raw('customer_name as nama'),
            DB::raw('customer_phone as wa'),
            DB::raw('address as alamat'),
            DB::raw('coordinates as koordinat'),
            DB::raw('plan_name as paket'),
            DB::raw('price as harga'),
            DB::raw('installation_date as tanggal'),
            DB::raw('notes as catatan'),
            DB::raw("CONCAT_WS(', ', NULLIF(technician,''), NULLIF(technician_2,''), NULLIF(technician_3,''), NULLIF(technician_4,'')) as teknisi"),
            DB::raw('technician as teknisi_1'),
            DB::raw('technician_2 as teknisi_2'),
            DB::raw('technician_3 as teknisi_3'),
            DB::raw('technician_4 as teknisi_4'),
            DB::raw("CONCAT_WS(', ', NULLIF(sales_name,''), NULLIF(sales_name_2,''), NULLIF(sales_name_3,'')) as sales"),
            DB::raw('sales_name as sales_1'),
            DB::raw('sales_name_2 as sales_2'),
            DB::raw('sales_name_3 as sales_3'),
        ];
    }

    /**
     * Get tasks for teknisi dashboard
     */
    public function tasks(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['success' => false, 'message' => 'Tenant context missing'], 403);
        }
        if ($resp = $this->requireAnyPermission($request, ['view teknisi'])) {
            return $resp;
        }
        $techName = $this->actorName($request);
        $tab = $request->input('tab', 'all'); // all | mine
        $pop = $request->input('pop', '');
        $status = $request->input('status', '');
        $q = $request->input('q', '');

        $query = DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['Baru', 'Survey', 'Proses', 'Pending', 'Req_Batal']);

        // Filter by tab (mine = assigned to this technician)
        if ($tab === 'mine' && $techName) {
            $query->where($this->assignedFilter($techName));
        }

        // Filter by POP
        if ($pop) {
            $query->where('pop', $pop);
        }

        // Filter by status
        if ($status) {
            $query->where('status', $status);
        }

        // Search
        if ($q) {
            $query->where(function ($qb) use ($q) {
                $qb->where('ticket_id', 'like', "%{$q}%")
                    ->orWhere('customer_name', 'like', "%{$q}%")
                    ->orWhere('address', 'like', "%{$q}%")
                    ->orWhere('customer_phone', 'like', "%{$q}%");
            });
        }

        $tasks = $query
            ->orderByDesc('is_priority')
            ->orderByDesc('id')
            ->get($this->selectAliasColumns());

        // Count by status for badges
        $countAll = DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['Baru', 'Survey', 'Proses', 'Pending', 'Req_Batal'])
            ->count();

        $countMine = 0;
        if ($techName) {
            $countMine = DB::table('noci_installations')
                ->where('tenant_id', $tenantId)
                ->whereIn('status', ['Baru', 'Survey', 'Proses', 'Pending', 'Req_Batal'])
                ->where($this->assignedFilter($techName))
                ->count();
        }

        return response()->json([
            'success' => true,
            'data' => $tasks,
            'counts' => [
                'all' => $countAll,
                'mine' => $countMine,
            ],
        ]);
    }

    /**
     * Get task detail
     */
    public function taskDetail(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['success' => false, 'message' => 'Tenant context missing'], 403);
        }
        if ($resp = $this->requireAnyPermission($request, ['view teknisi'])) {
            return $resp;
        }

        $task = DB::table('noci_installations')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->first($this->selectAliasColumns());

        if (!$task) {
            return response()->json(['success' => false, 'message' => 'Task not found'], 404);
        }

        // Get logs
        $logs = DB::table('noci_installation_changes')
            ->where('installation_id', $id)
            ->where('tenant_id', $tenantId)
            ->orderBy('changed_at', 'desc')
            ->limit(20)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $task,
            'logs' => $logs,
        ]);
    }

    /**
     * Save new installation (pasang baru)
     */
    public function saveInstallation(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['success' => false, 'message' => 'Tenant context missing'], 403);
        }
        if ($resp = $this->requireAnyPermission($request, ['edit teknisi'])) {
            return $resp;
        }
        $userId = (int) ($request->user()?->id ?? 0);
        $userName = (string) ($request->user()?->name ?? 'System');

        $validated = $request->validate([
            'nama' => 'required|string|max:255',
            'wa' => 'required|string|max:50',
            'alamat' => 'required|string',
            'pop' => 'required|string|max:100',
            'paket' => 'nullable|string|max:255',
            'harga' => 'nullable|numeric',
            'sales_1' => 'nullable|string|max:100',
            'sales_2' => 'nullable|string|max:100',
            'sales_3' => 'nullable|string|max:100',
            'teknisi_1' => 'nullable|string|max:100',
            'teknisi_2' => 'nullable|string|max:100',
            'teknisi_3' => 'nullable|string|max:100',
            'teknisi_4' => 'nullable|string|max:100',
            'tanggal' => 'nullable|date',
            'koordinat' => 'nullable|string|max:100',
            'catatan' => 'nullable|string',
        ]);

        // Normalize phone
        $wa = preg_replace('/[^0-9]/', '', $validated['wa']);
        if (str_starts_with($wa, '0')) {
            $wa = '62' . substr($wa, 1);
        }
        if (str_starts_with($wa, '8')) {
            $wa = '62' . $wa;
        }

        // Build teknisi string
        $teknisiArr = array_filter([
            $validated['teknisi_1'] ?? '',
            $validated['teknisi_2'] ?? '',
            $validated['teknisi_3'] ?? '',
            $validated['teknisi_4'] ?? '',
        ]);
        $teknisiStr = implode(', ', $teknisiArr);

        // Build sales string
        $salesArr = array_filter([
            $validated['sales_1'] ?? '',
            $validated['sales_2'] ?? '',
            $validated['sales_3'] ?? '',
        ]);
        $salesStr = implode(', ', $salesArr);

        $ticketId = Installation::generateTicketId($tenantId);

        $id = DB::table('noci_installations')->insertGetId([
            'tenant_id' => $tenantId,
            'ticket_id' => $ticketId,
            'customer_name' => $validated['nama'],
            'customer_phone' => $wa,
            'address' => $validated['alamat'],
            'pop' => $validated['pop'],
            'plan_name' => $validated['paket'] ?? '',
            'price' => (int) ($validated['harga'] ?? 0),
            'sales_name' => $validated['sales_1'] ?? '',
            'sales_name_2' => $validated['sales_2'] ?? '',
            'sales_name_3' => $validated['sales_3'] ?? '',
            'technician' => $validated['teknisi_1'] ?? '',
            'technician_2' => $validated['teknisi_2'] ?? '',
            'technician_3' => $validated['teknisi_3'] ?? '',
            'technician_4' => $validated['teknisi_4'] ?? '',
            'coordinates' => $validated['koordinat'] ?? '',
            'notes' => $validated['catatan'] ?? '',
            'status' => 'Baru',
            'installation_date' => $validated['tanggal'] ?? now()->toDateString(),
            'finished_at' => null,
            'is_priority' => 0,
            'created_at' => now(),
        ]);

        // Log change
        DB::table('noci_installation_changes')->insert([
            'tenant_id' => $tenantId,
            'installation_id' => $id,
            'field_name' => 'status',
            'old_value' => '',
            'new_value' => 'Baru',
            'changed_by' => $userName,
            'changed_by_role' => 'teknisi',
            'source' => 'teknisi',
            'changed_at' => now(),
        ]);

        try {
            ActionLog::record($tenantId, $userId ?: null, 'CREATE', 'installation', $id, [
                'ticket_id' => $ticketId,
                'nama' => $validated['nama'],
                'pop' => $validated['pop'],
            ]);
        } catch (\Throwable) {
        }

        return response()->json([
            'success' => true,
            'message' => 'Pasang baru berhasil disimpan',
            'data' => ['id' => $id],
        ]);
    }

    /**
     * Update task status (Proses, Pending, Lapor Selesai, Request Batal)
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['success' => false, 'message' => 'Tenant context missing'], 403);
        }
        if ($resp = $this->requireAnyPermission($request, ['edit teknisi'])) {
            return $resp;
        }
        $userId = (int) ($request->user()?->id ?? 0);
        $userName = (string) ($request->user()?->name ?? 'System');

        $validated = $request->validate([
            'status' => 'required|string|in:Proses,Pending,Selesai,Batal,Req_Batal',
            'catatan' => 'nullable|string',
            'alasan_pending' => 'nullable|string',
            'alasan_batal' => 'nullable|string',
            // For Selesai (we store these as note append; columns may not exist in schema)
            'koordinat' => 'nullable|string',
            'onu_sn' => 'nullable|string',
            'onu_name' => 'nullable|string',
            'pppoe_user' => 'nullable|string',
            'pppoe_pass' => 'nullable|string',
            'odp' => 'nullable|string',
        ]);

        $task = DB::table('noci_installations')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$task) {
            return response()->json(['success' => false, 'message' => 'Task not found'], 404);
        }

        $oldStatus = $task->status;
        $newStatus = $validated['status'];

        $updateData = [
            'status' => $newStatus,
        ];

        // finished_at behavior (match core installation schema)
        if (in_array($newStatus, ['Selesai', 'Batal'], true)) {
            if (empty($task->finished_at)) {
                $updateData['finished_at'] = now()->format('Y-m-d H:i:s');
            }
        } else {
            $updateData['finished_at'] = null;
        }

        // Coordinate update (compat param name: koordinat)
        if (!empty($validated['koordinat'])) {
            $updateData['coordinates'] = (string) $validated['koordinat'];
        }

        // Notes append (compat param names)
        $notes = rtrim((string) ($task->notes ?? ''));
        $appendParts = [];

        if (!empty($validated['catatan'])) {
            $appendParts[] = trim((string) $validated['catatan']);
        }
        if ($newStatus === 'Pending' && !empty($validated['alasan_pending'])) {
            $appendParts[] = "[PENDING] Alasan: " . trim((string) $validated['alasan_pending']) . ' ' . now()->format('d/m H:i');
        }
        if ($newStatus === 'Req_Batal' && !empty($validated['alasan_batal'])) {
            $appendParts[] = "[REQ BATAL] Alasan: " . trim((string) $validated['alasan_batal']) . ' ' . now()->format('d/m H:i');
        }
        if ($newStatus === 'Selesai') {
            $extra = [];
            if (!empty($validated['onu_sn'])) $extra[] = 'ONU SN: ' . trim((string) $validated['onu_sn']);
            if (!empty($validated['onu_name'])) $extra[] = 'ONU Name: ' . trim((string) $validated['onu_name']);
            if (!empty($validated['pppoe_user'])) $extra[] = 'PPPoE User: ' . trim((string) $validated['pppoe_user']);
            if (!empty($validated['pppoe_pass'])) $extra[] = 'PPPoE Pass: ' . trim((string) $validated['pppoe_pass']);
            if (!empty($validated['odp'])) $extra[] = 'ODP: ' . trim((string) $validated['odp']);
            if (!empty($extra)) $appendParts[] = implode("\n", $extra);
        }

        if (!empty($appendParts)) {
            $append = "\n\n" . implode("\n", $appendParts);
            $updateData['notes'] = ($notes === '') ? ltrim($append) : ($notes . $append);
        }

        DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->update($updateData);

        // Log
        DB::table('noci_installation_changes')->insert([
            'tenant_id' => $tenantId,
            'installation_id' => $id,
            'field_name' => 'status',
            'old_value' => $oldStatus,
            'new_value' => $newStatus,
            'changed_by' => $userName,
            'changed_by_role' => 'teknisi',
            'source' => 'teknisi',
            'changed_at' => now(),
        ]);

        try {
            ActionLog::record($tenantId, $userId ?: null, 'UPDATE', 'installation', $id, [
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);
        } catch (\Throwable) {
        }

        return response()->json([
            'success' => true,
            'message' => "Status berhasil diubah ke {$newStatus}",
        ]);
    }

    /**
     * Get riwayat (history) - completed tasks
     */
    public function riwayat(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['success' => false, 'message' => 'Tenant context missing'], 403);
        }
        if ($resp = $this->requireAnyPermission($request, ['view teknisi'])) {
            return $resp;
        }
        $techName = $this->actorName($request);
        $dateFrom = (string) ($request->input('date_from', now()->startOfMonth()->toDateString()));
        $dateTo = (string) ($request->input('date_to', now()->toDateString()));
        $status = (string) ($request->input('status', ''));

        $query = DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('finished_at');

        if ($status !== '' && in_array($status, ['Selesai', 'Batal'], true)) {
            $query->where('status', $status);
        } else {
            $query->whereIn('status', ['Selesai', 'Batal']);
        }

        if ($dateFrom) {
            $query->whereDate('finished_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('finished_at', '<=', $dateTo);
        }

        // If teknisi, only show their tasks
        if ($techName) {
            $query->where($this->assignedFilter($techName));
        }

        $data = $query->orderByDesc('finished_at')->orderByDesc('id')->get($this->selectAliasColumns());

        return response()->json([
            'success' => true,
            'data' => $data,
            'count' => count($data),
        ]);
    }

    /**
     * Get rekap harian - daily summary
     */
    public function rekap(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['success' => false, 'message' => 'Tenant context missing'], 403);
        }
        if ($resp = $this->requireAnyPermission($request, ['view teknisi'])) {
            return $resp;
        }
        $techName = $this->actorName($request);
        $userId = (int) ($request->user()?->id ?? 0);
        $date = (string) ($request->input('date', now()->toDateString()));

        // Get completed jobs for date
        $query = DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where('status', 'Selesai')
            ->whereNotNull('finished_at')
            ->whereDate('finished_at', $date);

        if ($techName) {
            $query->where($this->assignedFilter($techName));
        }

        $jobs = $query->orderBy('finished_at', 'asc')->get($this->selectAliasColumns());

        // Get expenses for date
        $expQ = DB::table('noci_teknisi_expenses')
            ->where('tenant_id', $tenantId)
            ->where('expense_date', $date);
        if ($userId) {
            $expQ->where('teknisi_id', $userId);
        }
        $expenses = $expQ->get();

        // Get recap groups for dropdown
        $groups = DB::table('noci_recap_groups')
            ->where('tenant_id', $tenantId)
            ->where('is_active', 1)
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'jobs' => $jobs,
            'expenses' => $expenses,
            'groups' => $groups,
            'summary' => [
                'total_jobs' => count($jobs),
                'total_revenue' => $jobs->sum('harga'),
                'total_expenses' => $expenses->sum('amount'),
            ],
        ]);
    }

    /**
     * Save expenses for rekap
     */
    public function saveExpenses(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['success' => false, 'message' => 'Tenant context missing'], 403);
        }
        if ($resp = $this->requireAnyPermission($request, ['edit teknisi'])) {
            return $resp;
        }
        $techName = $this->actorName($request);
        $userId = $request->user()->id ?? 0;
        $date = $request->input('date', now()->toDateString());

        $validated = $request->validate([
            'expenses' => 'required|array',
            'expenses.*.name' => 'required|string|max:255',
            'expenses.*.amount' => 'required|numeric|min:0',
        ]);

        // Delete existing expenses for this date/technician
        DB::table('noci_teknisi_expenses')
            ->where('tenant_id', $tenantId)
            ->where('teknisi_id', $userId)
            ->where('expense_date', $date)
            ->delete();

        // Insert new expenses
        foreach ($validated['expenses'] as $expense) {
            DB::table('noci_teknisi_expenses')->insert([
                'tenant_id' => $tenantId,
                'teknisi_id' => $userId,
                'expense_date' => $date,
                'category' => $expense['name'] ?? 'operasional',
                'amount' => $expense['amount'],
                'description' => $expense['name'] ?? '',
                'created_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Pengeluaran tersimpan',
        ]);
    }

    /**
     * Send rekap to group
     */
    public function sendRekapToGroup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'group_id' => 'required|integer',
            'message' => 'required|string',
        ]);

        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['success' => false, 'message' => 'Tenant context missing'], 403);
        }
        if ($resp = $this->requireAnyPermission($request, ['send teknisi recap'])) {
            return $resp;
        }

        // Get group
        $group = DB::table('noci_recap_groups')
            ->where('id', $validated['group_id'])
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$group) {
            return response()->json(['success' => false, 'message' => 'Group not found'], 404);
        }

        // TODO: Integrate with WA gateway to send message
        // For now, just log and return success
        DB::table('noci_notif_logs')->insert([
            'tenant_id' => $tenantId,
            'platform' => 'WhatsApp',
            'target' => $group->group_id ?? $group->name,
            'message' => $validated['message'],
            'status' => 'PENDING',
            'timestamp' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Rekap berhasil dikirim ke grup',
        ]);
    }

    /**
     * Get POPs dropdown
     */
    public function pops(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['success' => false, 'message' => 'Tenant context missing'], 403);
        }
        if ($resp = $this->requireAnyPermission($request, ['view teknisi'])) {
            return $resp;
        }

        $pops = DB::table('noci_pops')
            ->where('tenant_id', $tenantId)
            ->orderBy('pop_name')
            ->get([
                'id',
                DB::raw('pop_name as name'),
            ]);

        return response()->json([
            'success' => true,
            'data' => $pops,
        ]);
    }

    /**
     * Get technicians dropdown
     */
    public function technicians(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['success' => false, 'message' => 'Tenant context missing'], 403);
        }
        if ($resp = $this->requireAnyPermission($request, ['view teknisi'])) {
            return $resp;
        }

        $technicians = DB::table('noci_users')
            ->where('tenant_id', $tenantId)
            ->whereIn('role', ['teknisi', 'svp lapangan', 'svp_lapangan'])
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'data' => $technicians,
        ]);
    }

    /**
     * Get sales dropdown
     */
    public function sales(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['success' => false, 'message' => 'Tenant context missing'], 403);
        }
        if ($resp = $this->requireAnyPermission($request, ['view teknisi'])) {
            return $resp;
        }

        $sales = DB::table('noci_team')
            ->where('tenant_id', $tenantId)
            ->where('is_active', 1)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'data' => $sales,
        ]);
    }
}
