<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\Installation;
use App\Support\WaGatewaySender;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    private function isBlankValue($value): bool
    {
        $v = strtolower(trim((string) $value));
        return $v === '' || $v === '-' || $v === 'null';
    }

    private function ensureRekapExpensesTable(): void
    {
        if (!Schema::hasTable('noci_rekap_expenses')) {
            Schema::create('noci_rekap_expenses', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(0);
                $table->date('rekap_date');
                $table->string('technician_name', 100);
                $table->mediumText('expenses_json')->nullable();
                $table->mediumText('team_json')->nullable();
                $table->string('created_by', 100)->nullable();
                $table->string('updated_by', 100)->nullable();
                $table->timestamp('created_at')->nullable()->useCurrent();
                $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
                $table->unique(['tenant_id', 'rekap_date', 'technician_name'], 'uniq_rekap');
                $table->index('tenant_id');
            });
            return;
        }

        if (!Schema::hasColumn('noci_rekap_expenses', 'tenant_id')) {
            Schema::table('noci_rekap_expenses', function (Blueprint $table) {
                $table->unsignedInteger('tenant_id')->default(0)->after('id');
            });
        }
        if (!Schema::hasColumn('noci_rekap_expenses', 'team_json')) {
            Schema::table('noci_rekap_expenses', function (Blueprint $table) {
                $table->mediumText('team_json')->nullable()->after('expenses_json');
            });
        }
    }

    private function ensureRekapAttachmentsTable(): void
    {
        if (!Schema::hasTable('noci_rekap_attachments')) {
            Schema::create('noci_rekap_attachments', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tenant_id')->default(0);
                $table->date('rekap_date')->nullable();
                $table->string('technician_name', 100)->nullable();
                $table->string('file_name', 255)->nullable();
                $table->string('file_path', 255)->nullable();
                $table->string('file_ext', 10)->nullable();
                $table->string('mime_type', 100)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->string('created_by', 100)->nullable();
                $table->timestamp('created_at')->nullable()->useCurrent();
                $table->index('tenant_id');
                $table->index(['tenant_id', 'rekap_date']);
            });
            return;
        }

        if (!Schema::hasColumn('noci_rekap_attachments', 'tenant_id')) {
            Schema::table('noci_rekap_attachments', function (Blueprint $table) {
                $table->unsignedInteger('tenant_id')->default(0)->after('id');
            });
        }
        if (!Schema::hasColumn('noci_rekap_attachments', 'rekap_date')) {
            Schema::table('noci_rekap_attachments', function (Blueprint $table) {
                $table->date('rekap_date')->nullable()->after('tenant_id');
            });
        }
        if (!Schema::hasColumn('noci_rekap_attachments', 'technician_name')) {
            Schema::table('noci_rekap_attachments', function (Blueprint $table) {
                $table->string('technician_name', 100)->nullable()->after('rekap_date');
            });
        }
        if (!Schema::hasColumn('noci_rekap_attachments', 'file_name')) {
            Schema::table('noci_rekap_attachments', function (Blueprint $table) {
                $table->string('file_name', 255)->nullable()->after('technician_name');
            });
        }
        if (!Schema::hasColumn('noci_rekap_attachments', 'file_path')) {
            Schema::table('noci_rekap_attachments', function (Blueprint $table) {
                $table->string('file_path', 255)->nullable()->after('file_name');
            });
        }
        if (!Schema::hasColumn('noci_rekap_attachments', 'file_ext')) {
            Schema::table('noci_rekap_attachments', function (Blueprint $table) {
                $table->string('file_ext', 10)->nullable()->after('file_path');
            });
        }
        if (!Schema::hasColumn('noci_rekap_attachments', 'mime_type')) {
            Schema::table('noci_rekap_attachments', function (Blueprint $table) {
                $table->string('mime_type', 100)->nullable()->after('file_ext');
            });
        }
        if (!Schema::hasColumn('noci_rekap_attachments', 'file_size')) {
            Schema::table('noci_rekap_attachments', function (Blueprint $table) {
                $table->unsignedBigInteger('file_size')->nullable()->after('mime_type');
            });
        }
        if (!Schema::hasColumn('noci_rekap_attachments', 'created_by')) {
            Schema::table('noci_rekap_attachments', function (Blueprint $table) {
                $table->string('created_by', 100)->nullable()->after('file_size');
            });
        }
        if (!Schema::hasColumn('noci_rekap_attachments', 'created_at')) {
            Schema::table('noci_rekap_attachments', function (Blueprint $table) {
                $table->timestamp('created_at')->nullable()->useCurrent()->after('created_by');
            });
        }
    }

    private function recapGroupsNameColumn(): ?string
    {
        if (!Schema::hasTable('noci_recap_groups')) return null;
        if (Schema::hasColumn('noci_recap_groups', 'name')) return 'name';
        if (Schema::hasColumn('noci_recap_groups', 'group_name')) return 'group_name';
        return null;
    }

    private function getRecapGroups(int $tenantId): array
    {
        if (!Schema::hasTable('noci_recap_groups')) return [];
        if (!Schema::hasColumn('noci_recap_groups', 'group_id')) return [];

        $nameCol = $this->recapGroupsNameColumn();
        if ($nameCol === null) return [];

        $query = DB::table('noci_recap_groups')->where('tenant_id', $tenantId);
        if (Schema::hasColumn('noci_recap_groups', 'is_active')) {
            $query->where('is_active', 1);
        }

        $rows = $query
            ->orderBy($nameCol)
            ->get([
                'id',
                'group_id',
                DB::raw($nameCol . ' as name'),
            ]);

        $data = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row->name ?? ''));
            $groupId = trim((string) ($row->group_id ?? ''));
            if ($name === '' || $groupId === '') continue;
            $data[] = [
                'id' => (int) ($row->id ?? 0),
                'name' => $name,
                'group_id' => $groupId,
            ];
        }
        return $data;
    }

    private function normalizeExpenseItems($rawExpenses): array
    {
        $safe = [];
        $items = is_array($rawExpenses) ? $rawExpenses : [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $name = trim((string) ($item['name'] ?? ''));
            $amountRaw = (string) ($item['amount'] ?? '0');
            $amount = (int) preg_replace('/\D/', '', $amountRaw);
            if ($name === '' && $amount <= 0) continue;
            if ($amount <= 0) continue;
            $safe[] = [
                'name' => $name !== '' ? $name : 'Pengeluaran',
                'amount' => $amount,
            ];
        }
        return $safe;
    }

    private function collectRekapTeamNames(int $tenantId, string $date, string $techName): array
    {
        if ($techName === '') return [];

        $rows = DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where('status', 'Selesai')
            ->whereNotNull('finished_at')
            ->whereDate('finished_at', $date)
            ->where($this->assignedFilter($techName))
            ->get(['technician', 'technician_2', 'technician_3', 'technician_4']);

        $seen = [];
        $list = [];
        foreach ($rows as $row) {
            foreach (['technician', 'technician_2', 'technician_3', 'technician_4'] as $key) {
                $name = trim((string) ($row->{$key} ?? ''));
                $norm = strtolower($name);
                if ($this->isBlankValue($name) || isset($seen[$norm])) continue;
                $seen[$norm] = true;
                $list[] = $name;
            }
        }
        return $list;
    }

    private function resolveRecapGroupTarget(int $tenantId, string $groupInput): ?array
    {
        $groupInput = trim($groupInput);
        if ($groupInput === '') return null;

        if (ctype_digit($groupInput) && Schema::hasTable('noci_recap_groups')) {
            $nameCol = $this->recapGroupsNameColumn();
            if ($nameCol !== null && Schema::hasColumn('noci_recap_groups', 'group_id')) {
                $query = DB::table('noci_recap_groups')
                    ->where('tenant_id', $tenantId)
                    ->where('id', (int) $groupInput);
                if (Schema::hasColumn('noci_recap_groups', 'is_active')) {
                    $query->where('is_active', 1);
                }
                $row = $query->first([
                    'id',
                    'group_id',
                    DB::raw($nameCol . ' as name'),
                ]);
                if ($row) {
                    return [
                        'id' => (int) ($row->id ?? 0),
                        'group_id' => trim((string) ($row->group_id ?? '')),
                        'name' => trim((string) ($row->name ?? '')),
                    ];
                }
            }
        }

        return [
            'id' => 0,
            'group_id' => $groupInput,
            'name' => '',
        ];
    }

    private function toAbsoluteUrl(Request $request, string $raw): string
    {
        $value = trim($raw);
        if ($value === '') return '';

        if (preg_match('/^https?:\/\//i', $value)) return $value;
        if (substr($value, 0, 2) === '//') {
            return $request->getScheme() . ':' . $value;
        }

        return rtrim($request->getSchemeAndHttpHost(), '/') . '/' . ltrim($value, '/');
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

        // Native-compatible expenses storage: one JSON row per date + technician_name.
        $this->ensureRekapExpensesTable();
        $expenses = [];
        if ($techName !== '') {
            $row = DB::table('noci_rekap_expenses')
                ->where('tenant_id', $tenantId)
                ->where('rekap_date', $date)
                ->where('technician_name', $techName)
                ->first(['expenses_json']);

            if ($row && !empty($row->expenses_json)) {
                $decoded = json_decode((string) $row->expenses_json, true);
                $expenses = $this->normalizeExpenseItems($decoded);
            }
        }

        // Get recap groups for dropdown (supports legacy/new schema variants).
        $groups = $this->getRecapGroups($tenantId);

        $expenseTotal = 0;
        foreach ($expenses as $item) {
            $expenseTotal += (int) ($item['amount'] ?? 0);
        }

        return response()->json([
            'success' => true,
            'jobs' => $jobs,
            'expenses' => $expenses,
            'groups' => $groups,
            'summary' => [
                'total_jobs' => count($jobs),
                'total_revenue' => $jobs->sum('harga'),
                'total_expenses' => $expenseTotal,
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
        $date = (string) $request->input('date', now()->toDateString());

        $validated = $request->validate([
            'expenses' => 'required|array',
            'expenses.*.name' => 'nullable|string|max:255',
            'expenses.*.amount' => 'nullable',
        ]);

        if ($techName === '') {
            return response()->json(['success' => false, 'message' => 'Teknisi tidak ditemukan'], 422);
        }

        $this->ensureRekapExpensesTable();
        $expenses = $this->normalizeExpenseItems($validated['expenses']);
        $team = $this->collectRekapTeamNames($tenantId, $date, $techName);
        $targets = !empty($team) ? $team : [$techName];

        if (count($expenses) === 0) {
            foreach ($targets as $target) {
                DB::table('noci_rekap_expenses')
                    ->where('tenant_id', $tenantId)
                    ->where('rekap_date', $date)
                    ->where('technician_name', $target)
                    ->delete();
            }

            return response()->json([
                'success' => true,
                'message' => 'Pengeluaran dihapus',
                'deleted' => true,
                'team' => $targets,
            ]);
        }

        $actor = (string) ($request->user()?->name ?? 'System');
        $expensesJson = json_encode($expenses, JSON_UNESCAPED_UNICODE);
        $teamJson = json_encode($team, JSON_UNESCAPED_UNICODE);

        foreach ($targets as $target) {
            DB::table('noci_rekap_expenses')->updateOrInsert(
                [
                    'tenant_id' => $tenantId,
                    'rekap_date' => $date,
                    'technician_name' => $target,
                ],
                [
                    'expenses_json' => $expensesJson,
                    'team_json' => $teamJson,
                    'created_by' => $actor,
                    'updated_by' => $actor,
                    'updated_at' => now(),
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Pengeluaran tersimpan',
            'data' => [
                'expenses' => $expenses,
                'team' => $team,
                'targets' => $targets,
            ],
        ]);
    }

    /**
     * Upload rekap transfer proof file.
     */
    public function uploadRekapProof(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->tenantId($request);
            if ($tenantId <= 0) {
                return response()->json(['success' => false, 'message' => 'Tenant context missing'], 403);
            }
            if ($resp = $this->requireAnyPermission($request, ['send teknisi recap', 'edit teknisi'])) {
                return $resp;
            }

            $validated = $request->validate([
                'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240',
                'date' => 'nullable|date',
                'tech_name' => 'nullable|string|max:100',
            ]);

            $this->ensureRekapAttachmentsTable();

            $file = $request->file('file');
            if (!$file) {
                return response()->json(['success' => false, 'message' => 'File tidak ditemukan'], 422);
            }

            $rekapDate = (string) ($validated['date'] ?? now()->toDateString());
            $techName = trim((string) ($validated['tech_name'] ?? $this->actorName($request)));
            if ($techName === '') {
                $techName = (string) ($request->user()?->name ?? 'Teknisi');
            }

            $safeTech = preg_replace('/[^a-z0-9]+/i', '_', strtolower($techName));
            $dateKey = preg_replace('/[^0-9]/', '', $rekapDate);
            $ext = strtolower((string) $file->getClientOriginalExtension());
            $fileName = 'rekap_bukti_' . ($safeTech ?: 'teknisi') . '_' . ($dateKey ?: date('Ymd')) . '_' . date('His') . '_' . random_int(1000, 9999) . '.' . $ext;

            $targetDir = public_path('uploads/rekap');
            if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                throw new \RuntimeException('Folder upload bukti transfer tidak bisa dibuat');
            }

            $file->move($targetDir, $fileName);
            $relativePath = 'uploads/rekap/' . $fileName;

            $payload = [];
            if (Schema::hasColumn('noci_rekap_attachments', 'tenant_id')) $payload['tenant_id'] = $tenantId;
            if (Schema::hasColumn('noci_rekap_attachments', 'rekap_date')) $payload['rekap_date'] = $rekapDate;
            if (Schema::hasColumn('noci_rekap_attachments', 'technician_name')) $payload['technician_name'] = $techName;
            if (Schema::hasColumn('noci_rekap_attachments', 'file_name')) $payload['file_name'] = $fileName;
            if (Schema::hasColumn('noci_rekap_attachments', 'file_path')) $payload['file_path'] = $relativePath;
            if (Schema::hasColumn('noci_rekap_attachments', 'file_ext')) $payload['file_ext'] = $ext;
            if (Schema::hasColumn('noci_rekap_attachments', 'mime_type')) $payload['mime_type'] = (string) $file->getClientMimeType();
            if (Schema::hasColumn('noci_rekap_attachments', 'file_size')) $payload['file_size'] = (int) $file->getSize();
            if (Schema::hasColumn('noci_rekap_attachments', 'created_by')) $payload['created_by'] = (string) ($request->user()?->name ?? '');
            if (Schema::hasColumn('noci_rekap_attachments', 'created_at')) $payload['created_at'] = now();

            if (!empty($payload)) {
                DB::table('noci_rekap_attachments')->insert($payload);
            }

            return response()->json([
                'success' => true,
                'message' => 'Bukti transfer berhasil diupload',
                'data' => [
                    'file_name' => $fileName,
                    'file_path' => $relativePath,
                    'file_url' => $this->toAbsoluteUrl($request, $relativePath),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal upload bukti transfer',
                'error' => (string) (config('app.debug') ? $e->getMessage() : 'Internal server error'),
            ], 500);
        }
    }

    /**
     * Send rekap to group
     */
    public function sendRekapToGroup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'group_id' => 'required',
            'message' => 'required|string',
            'recap_date' => 'nullable|date',
            'tech_name' => 'nullable|string|max:100',
            'media_url' => 'nullable|string|max:255',
        ]);

        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['success' => false, 'message' => 'Tenant context missing'], 403);
        }
        if ($resp = $this->requireAnyPermission($request, ['send teknisi recap'])) {
            return $resp;
        }

        $resolved = $this->resolveRecapGroupTarget($tenantId, (string) $validated['group_id']);
        if (!$resolved || $this->isBlankValue($resolved['group_id'] ?? '')) {
            return response()->json(['success' => false, 'message' => 'Group tidak ditemukan'], 404);
        }

        $targetGroup = trim((string) ($resolved['group_id'] ?? ''));
        if (!preg_match('/^\d{5,}@g\.us$/', $targetGroup)) {
            return response()->json(['success' => false, 'message' => 'Format ID grup tidak valid'], 422);
        }

        $message = trim((string) ($validated['message'] ?? ''));
        if ($message === '') {
            return response()->json(['success' => false, 'message' => 'Isi laporan tidak boleh kosong'], 422);
        }

        $mediaUrl = $this->toAbsoluteUrl($request, (string) ($validated['media_url'] ?? ''));
        if ($mediaUrl !== '') {
            $message .= "\n\nBukti Transfer: " . $mediaUrl;
        }

        $resp = app(WaGatewaySender::class)->sendGroup($tenantId, $targetGroup, $message, [
            'log_platform' => 'WA Group (Teknisi Rekap)',
        ]);
        if (($resp['status'] ?? '') !== 'sent') {
            $error = trim((string) ($resp['error'] ?? 'Gateway WA gagal mengirim pesan'));
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim laporan ke grup WhatsApp',
                'error' => $error,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Laporan terkirim ke grup WhatsApp',
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
