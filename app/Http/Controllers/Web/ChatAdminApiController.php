<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\V1\ChatController as ChatApiController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Legacy-compatible endpoint for the native chat frontend (chat/app.js + chat/game.js).
 *
 * The goal is 1:1 payload compatibility with `chat/admin_api.php` so we can keep
 * the original UI/JS intact while serving it from the Laravel admin panel.
 */
class ChatAdminApiController extends Controller
{
    private static array $ensuredTenants = [];
    private static array $tableCache = [];
    private static array $columnCache = [];

    private function tenantId(Request $request): int
    {
        return (int) ($request->attributes->get('tenant_id') ?? 0);
    }

    private function normalizePhone(string $raw): string
    {
        $p = preg_replace('/\D/', '', $raw);
        if ($p === '') return '';
        if (str_starts_with($p, '0')) $p = '62' . substr($p, 1);
        if (str_starts_with($p, '8')) $p = '62' . $p;
        return $p;
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, self::$tableCache)) {
            return (bool) self::$tableCache[$table];
        }
        try {
            self::$tableCache[$table] = Schema::hasTable($table);
        } catch (\Throwable) {
            self::$tableCache[$table] = false;
        }
        return (bool) self::$tableCache[$table];
    }

    private function columnExists(string $table, string $column): bool
    {
        $key = $table . ':' . $column;
        if (array_key_exists($key, self::$columnCache)) {
            return (bool) self::$columnCache[$key];
        }
        if (!$this->tableExists($table)) {
            self::$columnCache[$key] = false;
            return false;
        }
        try {
            self::$columnCache[$key] = Schema::hasColumn($table, $column);
        } catch (\Throwable) {
            self::$columnCache[$key] = false;
        }
        return (bool) self::$columnCache[$key];
    }

    private function friendlyEvent(string $raw): string
    {
        $cleanKey = $raw;
        if (preg_match('/^\[([A-Z_]+)\]/', $raw, $m)) {
            $cleanKey = $m[1];
        }
        $map = [
            'view_halaman' => 'Kunjungan Web',
            'mulai_chat' => 'Chat Masuk',
            'buka_form' => 'Buka Form',
            'klik_wa' => 'Klik WhatsApp',
            'klik_wa_offline' => 'Klik WA (Offline)',
            'claim' => 'Ambil Tugas',
            'pending' => 'Tunda Tugas',
            'cancel_req' => 'Ajuan Batal',
            'cancel_approve' => 'Batal Disetujui',
            'cancel_reject' => 'Batal Ditolak',
            'transfer' => 'Transfer Tugas',
            'finish' => 'Tugas Selesai',
            'resume' => 'Lanjut Proses',
            'priority_on' => 'Prioritas Aktif',
            'priority_off' => 'Prioritas Nonaktif',
            'logout' => 'Logout',
            'CLAIM' => 'Ambil Tugas',
            'TRANSFER' => 'Transfer Tugas',
            'REQ_BATAL' => 'Ajuan Batal',
            'ACC_BATAL' => 'Batal Disetujui',
            'TOLAK_BATAL' => 'Batal Ditolak',
            'UPDATE' => 'Update Status',
            'NEW_TASK' => 'Tugas Baru',
            'CREATE_TASK' => 'Buat Tugas',
            'DELETE' => 'Hapus Data',
            'VIEW_DETAIL' => 'Buka Detail',
            'PRIORITY' => 'Ubah Prioritas',
            'login' => 'Login Admin',
        ];
        if (isset($map[$cleanKey])) return $map[$cleanKey];
        return ucwords(str_replace(['_', '-'], ' ', strtolower($cleanKey)));
    }

    private function badgeClassForLog(string $rawAction): string
    {
        $k = strtolower($rawAction);
        if (str_contains($k, 'view')) return 'text-blue-600 bg-blue-100 dark:bg-blue-500/10 dark:text-blue-400';
        if (str_contains($k, 'chat') || str_contains($k, 'wa')) return 'text-green-600 bg-green-100 dark:bg-green-500/10 dark:text-green-400';
        if (str_contains($k, 'batal') || str_contains($k, 'delete')) return 'text-red-600 bg-red-100 dark:bg-red-500/10 dark:text-red-400';
        if (str_contains($k, 'claim') || str_contains($k, 'ambil')) return 'text-indigo-600 bg-indigo-100 dark:bg-indigo-500/10 dark:text-indigo-400';
        return 'text-slate-500 bg-slate-100 dark:bg-slate-800';
    }

    private function periodWhereSql(string $column, string $period): string
    {
        $safe = str_replace('`', '', $column);
        switch ($period) {
            case 'yesterday':
                return "DATE(`{$safe}`) = CURDATE() - INTERVAL 1 DAY";
            case '7days':
                return "`{$safe}` >= DATE(NOW() - INTERVAL 7 DAY)";
            case '30days':
                return "`{$safe}` >= DATE(NOW() - INTERVAL 30 DAY)";
            case 'today':
            default:
                return "DATE(`{$safe}`) = CURDATE()";
        }
    }

    private function nowDb(): string
    {
        try {
            $row = DB::selectOne('SELECT NOW() as now_time');
            if ($row && isset($row->now_time)) return (string) $row->now_time;
        } catch (\Throwable) {
        }
        return now()->format('Y-m-d H:i:s');
    }

    private function getCustomerOverview(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        $period = (string) $request->query('period', 'today');
        if (!in_array($period, ['today', 'yesterday', '7days', '30days'], true)) {
            $period = 'today';
        }

        $response = [
            'status' => 'success',
            'period' => $period,
            'server_time' => $this->nowDb(),
            'online_users' => 0,
            'unique_visits' => 0,
            'total_leads' => 0,
            'total_hits' => 0,
            'chart_labels' => [],
            'chart_series_pelanggan' => [],
            'logs_pelanggan' => [],
        ];

        $hasLogs = $this->tableExists('noci_logs');
        $hasCustomers = $this->tableExists('noci_customers');
        $isDailyChart = in_array($period, ['7days', '30days'], true);

        $labels = [];
        $templateData = [];
        $mapIndex = [];
        if ($isDailyChart) {
            $days = $period === '30days' ? 30 : 7;
            for ($i = $days - 1; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-{$i} days"));
                $labels[] = date('d M', strtotime($date));
                $mapIndex[$date] = count($labels) - 1;
                $templateData[] = 0;
            }
        } else {
            for ($i = 0; $i < 24; $i++) {
                $labels[] = str_pad((string) $i, 2, '0', STR_PAD_LEFT) . ':00';
                $mapIndex[$i] = $i;
                $templateData[] = 0;
            }
        }
        $response['chart_labels'] = $labels;

        if ($hasLogs && $this->columnExists('noci_logs', 'timestamp') && $this->columnExists('noci_logs', 'visit_id')) {
            $logHasTenant = $this->columnExists('noci_logs', 'tenant_id');
            $logHasEventAction = $this->columnExists('noci_logs', 'event_action');
            $logHasPlatform = $this->columnExists('noci_logs', 'platform');
            $logHasIpAddress = $this->columnExists('noci_logs', 'ip_address');
            $logPeriodSql = $this->periodWhereSql('timestamp', $period);

            try {
                $qOnline = DB::table('noci_logs')
                    ->selectRaw('COUNT(DISTINCT visit_id) as total')
                    ->when($logHasTenant, fn ($q) => $q->where('tenant_id', $tenantId))
                    ->whereRaw('`timestamp` > (NOW() - INTERVAL 2 MINUTE)');
                $response['online_users'] = (int) ($qOnline->value('total') ?? 0);
            } catch (\Throwable) {
            }

            try {
                $qUnique = DB::table('noci_logs')
                    ->selectRaw('COUNT(DISTINCT visit_id) as total')
                    ->when($logHasTenant, fn ($q) => $q->where('tenant_id', $tenantId))
                    ->whereRaw($logPeriodSql);
                $response['unique_visits'] = (int) ($qUnique->value('total') ?? 0);
            } catch (\Throwable) {
            }

            if ($logHasEventAction) {
                try {
                    $qHits = DB::table('noci_logs')
                        ->selectRaw('COUNT(*) as total')
                        ->when($logHasTenant, fn ($q) => $q->where('tenant_id', $tenantId))
                        ->where('event_action', 'view_halaman')
                        ->whereRaw($logPeriodSql);
                    $response['total_hits'] = (int) ($qHits->value('total') ?? 0);
                } catch (\Throwable) {
                }
            }

            if ($logHasEventAction) {
                $timeExpr = $isDailyChart ? "DATE(`timestamp`)" : "HOUR(`timestamp`)";
                $seriesData = $templateData;

                try {
                    $qChart = DB::table('noci_logs')
                        ->when($logHasTenant, fn ($q) => $q->where('tenant_id', $tenantId))
                        ->where('event_action', 'view_halaman')
                        ->whereRaw($logPeriodSql);

                    if ($logHasPlatform) {
                        $rows = $qChart
                            ->selectRaw("{$timeExpr} as time_bucket, platform, COUNT(*) as total")
                            ->groupByRaw('platform, time_bucket')
                            ->get();
                    } else {
                        $rows = $qChart
                            ->selectRaw("{$timeExpr} as time_bucket, COUNT(*) as total")
                            ->groupByRaw('time_bucket')
                            ->get();
                    }

                    foreach ($rows as $row) {
                        $platform = $logHasPlatform ? (string) ($row->platform ?? 'web') : 'web';
                        $isTeknisi = str_contains(strtolower($platform), 'teknisi') || str_contains(strtolower($platform), 'dashboard');
                        if ($isTeknisi) continue;

                        $timeKey = $isDailyChart ? (string) ($row->time_bucket ?? '') : (int) ($row->time_bucket ?? 0);
                        if (array_key_exists($timeKey, $mapIndex)) {
                            $idx = $mapIndex[$timeKey];
                            $seriesData[$idx] += (int) ($row->total ?? 0);
                        }
                    }
                } catch (\Throwable) {
                }

                $response['chart_series_pelanggan'] = [
                    ['name' => 'Kunjungan Halaman Isolir', 'data' => $seriesData],
                ];
            }

            try {
                $logSelect = ['visit_id', 'timestamp'];
                if ($logHasEventAction) $logSelect[] = 'event_action';
                if ($logHasPlatform) {
                    $logSelect[] = 'platform';
                } else {
                    $logSelect[] = DB::raw("'web' as platform");
                }
                if ($logHasIpAddress) {
                    $logSelect[] = 'ip_address';
                } else {
                    $logSelect[] = DB::raw("'' as ip_address");
                }

                $rows = DB::table('noci_logs')
                    ->select($logSelect)
                    ->when($logHasTenant, fn ($q) => $q->where('tenant_id', $tenantId))
                    ->whereRaw($logPeriodSql)
                    ->orderByDesc('timestamp')
                    ->limit(30)
                    ->get();

                $logs = [];
                foreach ($rows as $row) {
                    $rawAction = (string) ($row->event_action ?? '-');
                    $platform = (string) ($row->platform ?? 'web');
                    $isTeknisi = str_contains(strtolower($platform), 'teknisi') || str_contains(strtolower($platform), 'dashboard');
                    if ($isTeknisi) continue;

                    $timeObj = strtotime((string) ($row->timestamp ?? ''));
                    $time = $timeObj ? date('H:i', $timeObj) : '-';
                    $actor = str_starts_with($rawAction, '[')
                        ? (string) ($row->visit_id ?? '-')
                        : ('User (' . substr((string) ($row->visit_id ?? '0000'), -4) . ')');

                    $logs[] = [
                        'time' => $time,
                        'ip' => $actor,
                        'device' => (string) ($row->ip_address ?? '-'),
                        'status' => $this->friendlyEvent($rawAction),
                        'badge_class' => $this->badgeClassForLog($rawAction),
                    ];
                    if (count($logs) >= 15) break;
                }
                $response['logs_pelanggan'] = $logs;
            } catch (\Throwable) {
            }
        }

        $hasChat = $this->tableExists('noci_chat');
        if (
            $hasChat
            && $this->columnExists('noci_chat', 'visit_id')
            && $this->columnExists('noci_chat', 'sender')
            && $this->columnExists('noci_chat', 'created_at')
        ) {
            $chatHasTenant = $this->columnExists('noci_chat', 'tenant_id');
            $chatHasMsgStatus = $this->columnExists('noci_chat', 'msg_status');

            try {
                $firstUserMessage = DB::table('noci_chat')
                    ->selectRaw('visit_id, MIN(created_at) as first_user_message_at')
                    ->when($chatHasTenant, fn ($q) => $q->where('tenant_id', $tenantId))
                    ->where('sender', 'user')
                    ->when($chatHasMsgStatus, function ($q) {
                        $q->where(function ($qq) {
                            $qq->where('msg_status', 'active')
                                ->orWhereNull('msg_status');
                        });
                    })
                    ->groupBy('visit_id');

                $response['total_leads'] = (int) DB::query()
                    ->fromSub($firstUserMessage, 'lead_first_msg')
                    ->whereRaw($this->periodWhereSql('first_user_message_at', $period))
                    ->count();
            } catch (\Throwable) {
            }
        } elseif ($hasCustomers) {
            // Fallback for legacy schema without noci_chat.
            $custHasTenant = $this->columnExists('noci_customers', 'tenant_id');
            $custHasLastSeen = $this->columnExists('noci_customers', 'last_seen');
            try {
                $qLeads = DB::table('noci_customers')
                    ->when($custHasTenant, fn ($q) => $q->where('tenant_id', $tenantId));
                if ($custHasLastSeen) {
                    $qLeads->whereRaw($this->periodWhereSql('last_seen', $period));
                }
                $response['total_leads'] = (int) $qLeads->count();
            } catch (\Throwable) {
            }
        }

        return response()->json($response);
    }

    private function ensureLegacyTables(int $tenantId): void
    {
        // This endpoint is hit frequently (polling). Avoid INFORMATION_SCHEMA checks on every request.
        if (isset(self::$ensuredTenants[$tenantId])) return;
        self::$ensuredTenants[$tenantId] = true;

        // Best-effort: if DB is unavailable, just skip.
        try {
            if (!Schema::hasTable('noci_quick_replies')) {
                Schema::create('noci_quick_replies', function ($t) {
                    $t->increments('id');
                    $t->integer('tenant_id')->index();
                    $t->string('label', 50)->nullable();
                    $t->text('message')->nullable();
                });
            }

            if (!Schema::hasTable('noci_game_scores')) {
                Schema::create('noci_game_scores', function ($t) {
                    $t->increments('id');
                    $t->integer('tenant_id')->index();
                    $t->string('username', 50);
                    $t->integer('score')->default(0);
                    $t->timestamp('updated_at')->nullable();
                    $t->unique(['tenant_id', 'username'], 'unique_user');
                });
            } else {
                // Existing table from native schema may lack tenant_id — add it.
                if (!Schema::hasColumn('noci_game_scores', 'tenant_id')) {
                    try {
                        Schema::table('noci_game_scores', function ($t) {
                            $t->integer('tenant_id')->default(1)->after('id');
                            $t->index('tenant_id');
                        });
                    } catch (\Throwable) {}
                }
            }

            if (!Schema::hasTable('noci_chat_settings')) {
                Schema::create('noci_chat_settings', function ($t) {
                    $t->integer('tenant_id');
                    $t->integer('id')->default(1);
                    $t->enum('mode', ['manual_on', 'manual_off', 'scheduled'])->default('manual_on');
                    $t->string('wa_number', 20)->default('');
                    $t->text('wa_message')->nullable();
                    $t->time('start_hour')->default('08:00:00');
                    $t->time('end_hour')->default('17:00:00');
                    $t->primary(['tenant_id', 'id']);
                });
            }

            // Seed default settings row if missing (native behavior).
            $exists = DB::table('noci_chat_settings')
                ->where('tenant_id', $tenantId)
                ->where('id', 1)
                ->exists();
            if (!$exists) {
                DB::table('noci_chat_settings')->insert([
                    'tenant_id' => $tenantId,
                    'id' => 1,
                    'mode' => 'manual_on',
                    'wa_number' => '628123456789',
                    'wa_message' => 'Halo Admin...',
                    'start_hour' => '08:00:00',
                    'end_hour' => '17:00:00',
                ]);
            }
        } catch (\Throwable) {
            // ignore
        }
    }

    public function handle(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        $this->ensureLegacyTables($tenantId);

        $action = (string) ($request->input('action') ?? $request->query('action') ?? '');

        /** @var ChatApiController $api */
        $api = app(ChatApiController::class);

        // Passthrough actions we already implemented in the REST controller.
        if ($action === 'get_contacts') return $api->contacts($request);
        if ($action === 'get_customer_overview') return $this->getCustomerOverview($request);
        if ($action === 'get_messages') {
            $vid = trim((string) $request->query('visit_id', ''));
            if ($vid === '') return response()->json([]);
            return $api->messages($request);
        }
        if ($action === 'send') return $api->send($request);
        if ($action === 'delete_message') {
            $id = (int) $request->input('id', 0);
            return $api->deleteMessage($request, $id);
        }
        if ($action === 'edit_message') {
            $id = (int) $request->input('id', 0);
            return $api->editMessage($request, $id);
        }
        if ($action === 'update_customer') return $api->updateCustomer($request);
        if ($action === 'save_note') return $api->saveNote($request);
        if ($action === 'end_session') return $api->endSession($request);
        if ($action === 'reopen_session') return $api->reopenSession($request);
        if ($action === 'delete_session') return $api->deleteSession($request);

        // Templates (quick replies)
        if ($action === 'get_templates') {
            $rows = DB::table('noci_quick_replies')
                ->where('tenant_id', $tenantId)
                ->orderByDesc('id')
                ->get(['id', 'label', 'message', 'tenant_id']);
            return response()->json($rows);
        }
        if ($action === 'save_template') {
            $label = trim((string) $request->input('label', ''));
            $msg = trim((string) $request->input('message', ''));
            DB::table('noci_quick_replies')->insert([
                'tenant_id' => $tenantId,
                'label' => $label,
                'message' => $msg,
            ]);
            return response()->json(['status' => 'success']);
        }
        if ($action === 'delete_template') {
            $id = (int) $request->input('id', 0);
            DB::table('noci_quick_replies')
                ->where('tenant_id', $tenantId)
                ->where('id', $id)
                ->delete();
            return response()->json(['status' => 'success']);
        }

        // Settings (return plain object, not wrapper)
        if ($action === 'get_settings') {
            $row = DB::table('noci_chat_settings')
                ->where('tenant_id', $tenantId)
                ->where('id', 1)
                ->first();
            return response()->json($row ? (array) $row : null);
        }
        if ($action === 'save_settings') {
            $mode = (string) $request->input('mode', 'manual_on');
            $wa = $this->normalizePhone((string) $request->input('wa_number', ''));
            $msg = (string) $request->input('wa_message', '');
            $start = (string) $request->input('start_hour', '08:00');
            $end = (string) $request->input('end_hour', '17:00');

            DB::table('noci_chat_settings')
                ->where('tenant_id', $tenantId)
                ->where('id', 1)
                ->update([
                    'mode' => $mode,
                    'wa_number' => $wa,
                    'wa_message' => $msg,
                    'start_hour' => $start,
                    'end_hour' => $end,
                ]);

            return response()->json(['status' => 'success']);
        }

        // Admin profile (payload parity with native)
        if ($action === 'get_admin_profile') {
            $username = (string) (session('admin_username') ?: 'admin');

            $u = DB::table('noci_users')
                ->when(Schema::hasColumn('noci_users', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('username', $username)
                ->first();
            if (!$u) {
                $u = DB::table('noci_team')
                    ->when(Schema::hasColumn('noci_team', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                    ->where('username', $username)
                    ->first();
            }
            if (!$u) return response()->json(['status' => 'error', 'msg' => 'User not found']);

            $arr = (array) $u;
            unset($arr['password']);
            return response()->json(['status' => 'success', 'data' => $arr]);
        }

        if ($action === 'update_admin_profile') {
            $oldUser = (string) (session('admin_username') ?: 'admin');
            $newName = trim((string) $request->input('name', ''));
            $newUser = trim((string) $request->input('username', ''));
            $newPass = (string) $request->input('password', '');

            if ($newName === '' || $newUser === '') {
                return response()->json(['status' => 'error', 'msg' => 'Invalid payload'], 422);
            }

            $update = ['fullname' => $newName, 'username' => $newUser];
            if ($newPass !== '') {
                $update['password'] = password_hash($newPass, PASSWORD_DEFAULT);
            }

            $updated = DB::table('noci_users')
                ->when(Schema::hasColumn('noci_users', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('username', $oldUser)
                ->update($update);

            if (!$updated) {
                DB::table('noci_team')
                    ->when(Schema::hasColumn('noci_team', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                    ->where('username', $oldUser)
                    ->update($update);
            }

            session(['admin_name' => $newName, 'admin_username' => $newUser]);
            return response()->json(['status' => 'success']);
        }

        // Game: save score + my score + leaderboard (payload parity)
        if ($action === 'get_my_high_score') {
            $username = (string) (session('admin_username') ?: session('username') ?: 'admin');
            $q = DB::table('noci_game_scores')->where('username', $username);
            if (Schema::hasColumn('noci_game_scores', 'tenant_id')) {
                $q->where('tenant_id', $tenantId);
            }
            $row = $q->first(['score']);
            return response()->json(['status' => 'success', 'score' => (int) ($row->score ?? 0)]);
        }

        if ($action === 'save_game_score') {
            $username = (string) (session('admin_username') ?: session('username') ?: 'admin');
            $score = (int) $request->input('score', 0);
            if ($score < 0) $score = 0;

            $hasTenantCol = Schema::hasColumn('noci_game_scores', 'tenant_id');

            // Upsert: keep best score only (native behavior).
            $q = DB::table('noci_game_scores')->where('username', $username);
            if ($hasTenantCol) $q->where('tenant_id', $tenantId);
            $existing = $q->first(['score']);

            if (!$existing) {
                $insert = [
                    'username' => $username,
                    'score' => $score,
                    'updated_at' => now(),
                ];
                if ($hasTenantCol) $insert['tenant_id'] = $tenantId;
                DB::table('noci_game_scores')->insert($insert);
            } else {
                $best = max((int) $existing->score, $score);
                $upd = DB::table('noci_game_scores')->where('username', $username);
                if ($hasTenantCol) $upd->where('tenant_id', $tenantId);
                $upd->update(['score' => $best, 'updated_at' => now()]);
            }

            return response()->json(['status' => 'success']);
        }

        if ($action === 'get_leaderboard') {
            $currentUser = (string) (session('admin_username') ?: session('username') ?: '');
            $q = DB::table('noci_game_scores');
            if (Schema::hasColumn('noci_game_scores', 'tenant_id')) {
                $q->where('tenant_id', $tenantId);
            }
            $rows = $q->orderByDesc('score')
                ->orderBy('updated_at')
                ->limit(5)
                ->get(['username', 'score', 'updated_at']);

            $rank = 0;
            $out = [];
            foreach ($rows as $r) {
                $rank++;
                $out[] = [
                    'rank' => $rank,
                    'name' => (string) ($r->username ?? 'Unknown'),
                    'username' => (string) ($r->username ?? ''),
                    'score' => (int) ($r->score ?? 0),
                    'is_me' => Str::lower((string) ($r->username ?? '')) === Str::lower($currentUser),
                ];
            }

            return response()->json(['status' => 'success', 'data' => $out]);
        }

        return response()->json(['status' => 'error', 'msg' => 'Unknown action'], 400);
    }
}
