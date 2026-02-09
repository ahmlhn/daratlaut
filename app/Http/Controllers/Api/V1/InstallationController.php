<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\LegacyUser;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class InstallationController extends Controller
{
    private const STATUSES = ['Baru', 'Survey', 'Proses', 'Pending', 'Selesai', 'Req_Batal', 'Batal'];

    private function tenantId(Request $request): int
    {
        return (int) $request->attributes->get('tenant_id', 0);
    }

    private function actorName(Request $request): string
    {
        $user = $request->user();
        if ($user && !empty($user->name)) {
            return (string) $user->name;
        }

        return (string) (session('admin_name') ?: session('teknisi_name') ?: 'System');
    }

    private function actorRole(Request $request): string
    {
        $user = $request->user();
        if ($user && isset($user->role)) {
            return strtolower((string) $user->role);
        }

        return strtolower((string) (session('level') ?: session('teknisi_role') ?: 'system'));
    }

    private function isPrivileged(string $role): bool
    {
        return in_array($role, ['admin', 'cs', 'svp lapangan'], true);
    }

    private function normalizePhone(?string $raw): string
    {
        $phone = preg_replace('/\\D/', '', (string) $raw);
        if ($phone === '') {
            return '';
        }
        if (str_starts_with($phone, '0')) {
            return '62' . substr($phone, 1);
        }
        if (str_starts_with($phone, '8')) {
            return '62' . $phone;
        }
        return $phone;
    }

    private function phoneNoBales(?string $raw): string
    {
        $clean = preg_replace('/\\D/', '', (string) $raw);
        if ($clean === '') {
            return '';
        }
        if (str_starts_with($clean, '62')) {
            return substr($clean, 2);
        }
        if (str_starts_with($clean, '0')) {
            return substr($clean, 1);
        }
        return $clean;
    }

    private function isBlankSales(?string $val): bool
    {
        $v = strtolower(trim((string) $val));
        return ($v === '' || $v === '-' || $v === 'null');
    }

    private function isAssigned(object $row, string $techName): bool
    {
        if ($techName === '') {
            return false;
        }
        return in_array($techName, [
            (string) ($row->technician ?? ''),
            (string) ($row->technician_2 ?? ''),
            (string) ($row->technician_3 ?? ''),
            (string) ($row->technician_4 ?? ''),
        ], true);
    }

    private function normalizePop(int $tenantId, ?string $rawPop): ?string
    {
        $rawPop = trim((string) $rawPop);
        if ($rawPop === '') {
            return null;
        }

        $pops = DB::table('noci_pops')
            ->where('tenant_id', $tenantId)
            ->pluck('pop_name')
            ->toArray();

        $best = '';
        $bestPerc = 0.0;
        foreach ($pops as $p) {
            $p = (string) $p;
            if ($p === '') {
                continue;
            }
            $perc = 0.0;
            similar_text(strtoupper($rawPop), strtoupper($p), $perc);
            if ($perc > $bestPerc) {
                $bestPerc = $perc;
                $best = $p;
            }
        }

        return $bestPerc >= 75 ? $best : $rawPop;
    }

    private function normalizeDatetime(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            $value = str_replace('T', ' ', $value);
            if (preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}$/', $value)) {
                return $value . ':00';
            }
            return null;
        }
    }

    private function generateTicketId(int $tenantId): string
    {
        // Native: strtoupper(substr(bin2hex(random_bytes(4)), 0, 5))
        for ($i = 0; $i < 10; $i++) {
            $ticket = strtoupper(substr(bin2hex(random_bytes(4)), 0, 5));
            $exists = DB::table('noci_installations')
                ->where('tenant_id', $tenantId)
                ->where('ticket_id', $ticket)
                ->exists();
            if (!$exists) {
                return $ticket;
            }
        }

        return strtoupper(substr(bin2hex(random_bytes(8)), 0, 10));
    }

    private function buildProgressSummary(int $tenantId, ?string $pop, ?string $dateFrom, ?string $dateTo): array
    {
        $summary = [
            'priority' => 0,
            'overdue' => 0,
            'Baru' => 0,
            'Survey' => 0,
            'Proses' => 0,
            'Pending' => 0,
            'Req_Batal' => 0,
            'Batal' => 0,
            'today_done' => 0,
        ];

        $base = DB::table('noci_installations')->where('tenant_id', $tenantId);
        if (!empty($pop)) {
            $base->where('pop', $pop);
        }
        if (!empty($dateFrom)) {
            $base->whereDate('installation_date', '>=', $dateFrom);
        }
        if (!empty($dateTo)) {
            $base->whereDate('installation_date', '<=', $dateTo);
        }

        $byStatus = (clone $base)
            ->select('status', DB::raw('COUNT(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status')
            ->toArray();

        foreach ($byStatus as $st => $c) {
            if (array_key_exists($st, $summary)) {
                $summary[$st] = (int) $c;
            }
        }

        $summary['priority'] = (int) (clone $base)->where('is_priority', 1)->count();

        $today = now()->toDateString();
        $summary['overdue'] = (int) (clone $base)
            ->whereNotNull('installation_date')
            ->where('installation_date', '!=', '')
            ->whereDate('installation_date', '<', $today)
            ->whereNotIn('status', ['Selesai', 'Batal'])
            ->count();

        $todayDoneQ = DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where('status', 'Selesai')
            ->whereNotNull('finished_at')
            ->whereDate('finished_at', $today);
        if (!empty($pop)) {
            $todayDoneQ->where('pop', $pop);
        }
        $summary['today_done'] = (int) $todayDoneQ->count();

        return $summary;
    }

    private function resolveActiveWaConfig(int $tenantId): ?object
    {
        return DB::table('noci_conf_wa')
            ->where('tenant_id', $tenantId)
            ->where('is_active', 1)
            ->first();
    }

    private function resolveWaGroupId(int $tenantId, ?string $popName, ?object $waConf): ?string
    {
        if (!$waConf) {
            return null;
        }

        $groupId = trim((string) ($waConf->group_id ?? ''));
        if ($groupId === '') {
            return null;
        }

        $popName = trim((string) $popName);
        if ($popName === '') {
            return $groupId;
        }

        $popRow = DB::table('noci_pops')
            ->where('tenant_id', $tenantId)
            ->where('pop_name', $popName)
            ->first(['group_id']);

        $popGroup = trim((string) ($popRow->group_id ?? ''));
        return $popGroup !== '' ? $popGroup : $groupId;
    }

    private function logNotif(int $tenantId, string $platform, string $target, string $message, string $status, string $response): void
    {
        try {
            DB::table('noci_notif_logs')->insert([
                'tenant_id' => $tenantId,
                'platform' => $platform,
                'target' => $target,
                'message' => $message,
                'status' => $status,
                'response_log' => $response,
                'timestamp' => now(),
            ]);
        } catch (\Throwable) {
            // Ignore logging failures.
        }
    }

    private function waSendPersonal(int $tenantId, string $targetNameOrPhone, string $message, string $platform = 'WhatsApp'): array
    {
        $targetNameOrPhone = trim($targetNameOrPhone);
        $rawTarget = $targetNameOrPhone;

        if ($rawTarget !== '') {
            $userPhone = DB::table('noci_users')
                ->where('tenant_id', $tenantId)
                ->where('name', $rawTarget)
                ->value('phone');
            if (!empty($userPhone)) {
                $rawTarget = (string) $userPhone;
            }
        }

        return $this->waSendFailover($tenantId, 'personal', $rawTarget, $message, $platform);
    }

    private function waSendWithRetry(array $gateway, string $type, string $target, string $message): array
    {
        $retryMax = (int) ($gateway['retry_max'] ?? 2);
        // Force manual mode for now as per native logic implication or config
        // In native: if mode == auto, retry_max = 0. But let's stick to config.
        
        $retryDelay = (int) ($gateway['retry_delay_sec'] ?? 0);
        
        $attempts = $retryMax + 1;
        $lastResult = ['status' => 'failed', 'error' => 'Gagal kirim WA'];
        
        for ($i = 0; $i < $attempts; $i++) {
            // Determine provider
            $provider = strtolower((string) ($gateway['provider_code'] ?? ''));
            
            if ($provider === 'mpwa') {
                $lastResult = $this->waSendMpwa($gateway['tenant_id'], $type, $target, $message, "WA (MPWA Retry {$i})", (object)$gateway);
            } else {
                // Default to balesotomatis logic (using existing method but adapted)
                // We need to pass the gateway config to waSendBalesotomatis or adapt it.
                // Since waSendBalesotomatis currently fetches config from DB, we might need a version that accepts config.
                // For now, let's assume primary is always balesotomatis and we refactor that next.
                // ACTUALLY: waSendBalesotomatis below uses resolveActiveWaConfig. 
                // We should make a new method waSendBalesotomatisRaw that takes config.
                $lastResult = $this->waSendBalesotomatisRaw($gateway['tenant_id'], $type, $target, $message, "WA (Balesotomatis Retry {$i})", (object)$gateway);
            }
            
            if (($lastResult['status'] ?? '') === 'sent') {
                return $lastResult;
            }
            
            if ($i < $attempts - 1 && $retryDelay > 0) {
                sleep($retryDelay);
            }
        }
        
        return $lastResult;
    }

    private function waSendFailover(int $tenantId, string $type, string $target, string $message, string $platform = 'WA'): array
    {
        \Illuminate\Support\Facades\Log::info("DEBUG: waSendFailover hit for {$platform}");
        
        // 1. Get Active Gateways (Priority ASC)
        // Native: wa_gateway_get_active_list
        $gateways = DB::table('noci_wa_tenant_gateways')
            ->where('tenant_id', $tenantId)
            ->where('is_active', 1)
            ->orderBy('priority', 'asc')
            ->orderBy('id', 'asc') // consistent sort
            ->get()
            ->map(function ($item) {
                return (array) $item;
            })
            ->toArray();

        \Illuminate\Support\Facades\Log::info("DEBUG: Found " . count($gateways) . " active gateways in DB.");

        // If no gateways found in tenant settings, check for legacy config (native fallback)
        if (empty($gateways)) {
             $legacy = DB::table('noci_conf_wa')
                ->where('tenant_id', $tenantId)
                ->where('is_active', 1)
                ->first();
             
             if ($legacy) {
                 \Illuminate\Support\Facades\Log::info("DEBUG: Found legacy config, using correct fallback.");
                 $gateways[] = [
                    'id' => 0,
                    'tenant_id' => $tenantId,
                    'provider_code' => 'balesotomatis',
                    'label' => 'Legacy',
                    'base_url' => $legacy->base_url ?? '',
                    'group_url' => $legacy->group_url ?? '',
                    'token' => $legacy->token ?? '',
                    'sender_number' => $legacy->sender_number ?? '',
                    // native defaults:
                    'failover_mode' => 'manual',
                    'retry_max' => 2,
                    'retry_delay_sec' => 0,
                 ];
             } else {
                 \Illuminate\Support\Facades\Log::warning("DEBUG: No legacy config found either.");
             }
        }

        if (empty($gateways)) {
            \Illuminate\Support\Facades\Log::error("DEBUG: No active gateways available for tenant {$tenantId}.");
            $this->logNotif($tenantId, $platform, $target, $message, 'failed', 'Gateway WA tidak aktif');
            return ['status' => 'failed', 'error' => 'Gateway WA tidak aktif'];
        }

        $lastResult = ['status' => 'failed', 'error' => 'Gagal kirim WA'];
        
        foreach ($gateways as $gw) {
            \Illuminate\Support\Facades\Log::info("DEBUG: Attempting gateway ID " . ($gw['id'] ?? 'legacy') . " provider: " . ($gw['provider_code'] ?? 'unknown'));
            $lastResult = $this->waSendWithRetry($gw, $type, $target, $message); // Pass type/platform?
            \Illuminate\Support\Facades\Log::info("DEBUG: Gateway result: " . json_encode($lastResult));
            
            if (($lastResult['status'] ?? '') === 'sent') {
                return $lastResult;
            }
        }

        return $lastResult;
    }

    // Refactored to accept config object instead of looking it up
    private function waSendBalesotomatisRaw(int $tenantId, string $type, string $target, string $message, string $platform, object $conf): array
    {
        $token = trim((string) ($conf->token ?? ''));
        $sender = trim((string) ($conf->sender_number ?? ''));
        if ($token === '' || $sender === '') {
            $this->logNotif($tenantId, $platform, $target, $message, 'failed', 'Config WA tidak lengkap');
            return ['status' => 'failed', 'error' => 'Config WA tidak lengkap'];
        }

        $endpoint = '';
        $payload = [];

        if ($type === 'group') {
            $endpoint = trim((string) ($conf->group_url ?? ''));
            if ($endpoint === '') {
                $this->logNotif($tenantId, $platform, $target, $message, 'failed', 'Group URL kosong');
                return ['status' => 'failed', 'error' => 'Group URL kosong'];
            }
            $payload = [
                'api_key' => $token,
                'number_id' => $sender,
                'group_id' => $target,
                'message' => $message,
            ];
        } else {
            $endpoint = trim((string) ($conf->base_url ?? ''));
            if ($endpoint === '') {
                $endpoint = 'https://api.balesotomatis.id/public/v1/send_personal_message';
            }

            $phoneNo = $this->phoneNoBales($target);
            if ($phoneNo === '') {
                $this->logNotif($tenantId, $platform, $target, $message, 'failed', 'Nomor tujuan kosong');
                return ['status' => 'failed', 'error' => 'Nomor tujuan kosong'];
            }

            $payload = [
                'api_key' => $token,
                'number_id' => $sender,
                'enable_typing' => '1',
                'method_send' => 'async',
                'phone_no' => $phoneNo,
                'message' => $message,
            ];
        }

        try {
            // Use timeout from config if available (native: timeout_sec)
            $timeout = (int)($conf->timeout_sec ?? 10);
            if($timeout <= 0) $timeout = 10;

            $resp = Http::timeout($timeout)->post($endpoint, $payload);
            $body = (string) $resp->body();

            $ok = $resp->status() === 200;
            $decoded = null;
            try {
                $decoded = $resp->json();
            } catch (\Throwable) {
                $decoded = null;
            }
            if (is_array($decoded)) {
                if (array_key_exists('status', $decoded)) {
                    $ok = $ok && (bool) $decoded['status'];
                }
                if (array_key_exists('code', $decoded)) {
                    $ok = $ok && ((int) $decoded['code'] === 200);
                }
            }

            $this->logNotif($tenantId, $platform, $target, $message, $ok ? 'sent' : 'failed', $body ?: ($ok ? 'OK' : 'HTTP error'));
            return ['status' => $ok ? 'sent' : 'failed', 'raw' => $body];
        } catch (\Throwable $e) {
            $this->logNotif($tenantId, $platform, $target, $message, 'failed', $e->getMessage());
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    private function waSendMpwa(int $tenantId, string $type, string $target, string $message, string $platform, object $conf): array
    {
        $token = trim((string) ($conf->token ?? ''));
        $sender = trim((string) ($conf->sender_number ?? ''));
        $baseUrl = trim((string) ($conf->base_url ?? ''));

        if ($token === '' || $baseUrl === '') {
            $this->logNotif($tenantId, $platform, $target, $message, 'failed', 'Config MPWA tidak lengkap');
            return ['status' => 'failed', 'error' => 'Config MPWA tidak lengkap'];
        }

        $number = $target;
        if ($type !== 'group' && strpos($number, '@') === false) {
            $number = $this->phoneNo62($target); // Use phoneNo62 for MPWA native parity
        }

        if ($number === '') {
             return ['status' => 'failed', 'error' => 'Nomor tujuan kosong'];
        }

        $extra = !empty($conf->extra_json) ? (json_decode($conf->extra_json, true) ?: []) : [];
        $footer = $extra['footer'] ?? '';

        $payload = [
            'api_key' => $token,
            'sender' => $sender,
            'number' => $number,
            'message' => $message,
        ];

        if (!empty($footer)) {
            $payload['footer'] = $footer;
        }

        // Native does NOT send is_group flag for text messages
        // if ($type === 'group') {
        //    $payload['is_group'] = true;
        // }

        try {
            $resp = Http::timeout(15)->post($baseUrl, $payload);
            $body = (string) $resp->body();
            $ok = $resp->successful();

            // Log notification
            $this->logNotif($tenantId, $platform, $target, $message, $ok ? 'sent' : 'failed', $body ?: ($ok ? 'OK' : 'HTTP error'));

            return ['status' => $ok ? 'sent' : 'failed', 'raw' => $body];
        } catch (\Throwable $e) {
            $this->logNotif($tenantId, $platform, $target, $message, 'failed', $e->getMessage());
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    private function waSendGroup(int $tenantId, string $groupId, string $message, string $platform = 'WA Group'): array
    {
        return $this->waSendFailover($tenantId, 'group', $groupId, $message, $platform);
    }

    private function waSendBalesotomatis(int $tenantId, string $type, string $target, string $message, string $platform): array
    {
        return $this->waSendFailover($tenantId, $type, $target, $message, $platform);
    }

    private function phoneNo62($raw): string
    {
        $clean = preg_replace('/\D/', '', (string) $raw);
        if ($clean === '') return '';
        if (substr($clean, 0, 2) === '62') return $clean;
        if (substr($clean, 0, 1) === '0') return '62' . substr($clean, 1);
        return '62' . $clean;
    }

    private function afterSaveNotify(
        Request $request,
        int $tenantId,
        int $installationId,
        bool $isNew,
        string $oldStatus,
        string $newStatus,
        string $oldTech,
        string $newTech,
        string $customerName,
        string $customerPhone,
        string $address,
        ?string $pop,
        string $plan,
        int $price,
        string $installDate,
        ?string $finishedAt,
        array $sales,
        string $coords,
        array $changeEntries = []
    ): void {
        $role = $this->actorRole($request);
        $isPrivileged = $this->isPrivileged($role);

        $statusChanged = (!$isNew && $oldStatus !== $newStatus);
        $techChanged = (!$isNew && $oldTech !== $newTech);

        $sendGroupNew = $isNew;
        $sendGroupStatusBaruSurvey = ($statusChanged && in_array($newStatus, ['Baru', 'Survey'], true));
        $sendGroupStatusSelesai = ($statusChanged && $newStatus === 'Selesai');
        $sendPersonalAssign = ($isPrivileged && $newTech !== '' && ($isNew || $techChanged));

        $waConf = $this->resolveActiveWaConfig($tenantId);
        $targetGroup = $this->resolveWaGroupId($tenantId, $pop, $waConf);

        $baseUrl = rtrim($request->getSchemeAndHttpHost(), '/');
        $linkPage = ($newStatus === 'Selesai') ? '/installations/riwayat' : '/teknisi';
        $linkDetail = $baseUrl . $linkPage . '?task_id=' . $installationId;

        if ($sendPersonalAssign) {
            $msgTech = "*INFO TUGAS / ASSIGN*\n\n";
            $msgTech .= "Halo *{$newTech}*, Anda mendapatkan tugas.\n\n";
            $msgTech .= "Pelanggan: *{$customerName}*\n";
            $msgTech .= "WA: " . ($customerPhone ?: '-') . "\n";
            $msgTech .= "Alamat: " . ($address ?: '-') . "\n";
            $msgTech .= "POP: " . ($pop ?: '-') . "\n";
            $msgTech .= "Paket: " . ($plan ?: '-') . "\n";
            $msgTech .= "Jadwal: " . ($installDate ?: '-') . "\n\n";
            $msgTech .= "DETAIL: {$linkDetail}";

            $this->waSendPersonal($tenantId, $newTech, $msgTech, 'WA (Assign via Save)');
        }

        if ($waConf && $targetGroup) {
            $shouldSendGroup = ($sendGroupNew || $sendGroupStatusBaruSurvey || $sendGroupStatusSelesai);
            if (!$shouldSendGroup) {
                return;
            }

            if ($sendGroupStatusSelesai) {
                $waMsg = "*✅ INSTALASI SELESAI*\n" . now()->format('d M Y H:i') . "\n\n";
                $waMsg .= "Nama: {$customerName}\n";
                $waMsg .= "POP: " . ($pop ?: '-') . "\n";
                $waMsg .= "Teknisi: " . ($newTech ?: '-') . "\n";
                $waMsg .= "Jadwal: " . ($installDate ?: '-') . "\n";

                $salesParts = array_values(array_filter($sales, fn ($s) => !$this->isBlankSales($s)));
                $waMsg .= "Sales: " . (!empty($salesParts) ? implode(', ', $salesParts) : '-') . "\n";
                $waMsg .= "Biaya: Rp. " . number_format((int) $price, 0, ',', '.') . "\n";
                $waMsg .= "Selesai: " . ($finishedAt ?: now()->format('Y-m-d H:i:s')) . "\n\n";

                if (!empty($changeEntries)) {
                    $waMsg .= "Perubahan Data:\n";
                    foreach ($changeEntries as $ch) {
                        $label = $ch['field'] === 'customer_name' ? 'Nama' : ($ch['field'] === 'customer_phone' ? 'WA' : ($ch['field'] === 'address' ? 'Alamat' : $ch['field']));
                        $oldVal = preg_replace('/\\s+/', ' ', trim((string) ($ch['old'] ?? ''))) ?: '-';
                        $newVal = preg_replace('/\\s+/', ' ', trim((string) ($ch['new'] ?? ''))) ?: '-';
                        $waMsg .= "{$label}: {$oldVal} -> {$newVal}\n";
                    }
                    $waMsg .= "\n";
                }

                $waMsg .= "DETAIL: {$linkDetail}";
                $this->waSendGroup($tenantId, $targetGroup, $waMsg, 'WA Group (Selesai)');
                return;
            }

            $judul = $sendGroupNew ? 'INFO PASANG BARU' : 'UPDATE DATA';
            $tgl = now()->format('d F Y H:i');

            $waMsg = "{$judul}\n{$tgl}\n\n";
            $waMsg .= "Nama: {$customerName}\n";
            $waMsg .= "Wa: {$customerPhone}\n";
            $waMsg .= "Alamat: {$address}\n";
            $waMsg .= "Maps: " . ($coords ?: '-') . "\n";
            $waMsg .= "POP: " . ($pop ?: '-') . "\n";
            $waMsg .= "Paket: " . ($plan ?: '-') . "\n";
            $waMsg .= "Sales: " . ((string) ($sales[0] ?? '') ?: '-') . "\n";
            $waMsg .= "Teknisi: " . ($newTech ?: '-') . "\n";
            $waMsg .= "Status: " . ($newStatus ?: '-') . "\n\n";
            $waMsg .= "DETAIL: {$linkDetail}";

            $this->waSendGroup($tenantId, $targetGroup, $waMsg, 'WA Group (Save Important)');
        }
    }

    /**
     * List installations with filters
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        $page = max(1, (int) $request->input('page', 1));
        $perPage = (int) $request->input('per_page', 20);
        if ($perPage < 5) {
            $perPage = 5;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }
        $offset = ($page - 1) * $perPage;

        $search = trim((string) ($request->input('search') ?? $request->input('q') ?? ''));
        $status = trim((string) ($request->input('status') ?? ''));
        $pop = trim((string) ($request->input('pop') ?? ''));
        $tech = trim((string) ($request->input('tech') ?? $request->input('technician') ?? ''));
        $dateFrom = trim((string) ($request->input('date_from') ?? ''));
        $dateTo = trim((string) ($request->input('date_to') ?? ''));
        $priorityOnly = (int) $request->input('priority_only', 0) === 1;
        $overdueOnly = (int) $request->input('overdue_only', 0) === 1;

        $query = DB::table('noci_installations')->where('tenant_id', $tenantId);

        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function ($qb) use ($like) {
                $qb->where('ticket_id', 'like', $like)
                    ->orWhere('customer_name', 'like', $like)
                    ->orWhere('address', 'like', $like)
                    ->orWhere('technician', 'like', $like)
                    ->orWhere('technician_2', 'like', $like)
                    ->orWhere('technician_3', 'like', $like)
                    ->orWhere('technician_4', 'like', $like);
            });
        }

        if ($dateFrom !== '') {
            $query->whereDate('installation_date', '>=', $dateFrom);
        }
        if ($dateTo !== '') {
            $query->whereDate('installation_date', '<=', $dateTo);
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($pop !== '') {
            $query->where('pop', $pop);
        }
        if ($tech !== '') {
            $query->where(function ($qb) use ($tech) {
                $qb->where('technician', $tech)
                    ->orWhere('technician_2', $tech)
                    ->orWhere('technician_3', $tech)
                    ->orWhere('technician_4', $tech);
            });
        }
        if ($priorityOnly) {
            $query->where('is_priority', 1);
        }
        if ($overdueOnly) {
            $today = now()->toDateString();
            $query->whereNotNull('installation_date')
                ->where('installation_date', '!=', '')
                ->whereDate('installation_date', '<', $today)
                ->whereNotIn('status', ['Selesai', 'Batal']);
        }

        $total = (clone $query)->count();
        $totalPages = (int) ceil($total / $perPage);
        if ($totalPages < 1) {
            $totalPages = 1;
        }
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        $data = (clone $query)
            ->select([
                'id',
                'ticket_id',
                'customer_name',
                'customer_phone',
                'address',
                'pop',
                'plan_name',
                'price',
                'installation_date',
                'status',
                'technician',
                'technician_2',
                'technician_3',
                'technician_4',
                'sales_name',
                'sales_name_2',
                'sales_name_3',
                'coordinates',
                'finished_at',
                'is_priority',
                'created_at',
            ])
            ->orderByDesc('is_priority')
            ->orderByDesc('id')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        $summary = $this->buildProgressSummary(
            tenantId: $tenantId,
            pop: $pop !== '' ? $pop : null,
            dateFrom: $dateFrom !== '' ? $dateFrom : null,
            dateTo: $dateTo !== '' ? $dateTo : null
        );

        return response()->json([
            'status' => 'success',
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'summary' => $summary,
        ]);
    }

    /**
     * Riwayat pemasangan (Selesai/Batal) - filter by finished_at
     */
    public function riwayat(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        $page = max(1, (int) $request->input('page', 1));
        $perPage = (int) $request->input('per_page', 20);
        if ($perPage < 5) {
            $perPage = 5;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }
        $offset = ($page - 1) * $perPage;

        $search = trim((string) ($request->input('search') ?? $request->input('q') ?? ''));
        $status = trim((string) ($request->input('status') ?? ''));
        $dateFrom = trim((string) ($request->input('date_from') ?? ''));
        $dateTo = trim((string) ($request->input('date_to') ?? ''));
        $tech = trim((string) ($request->input('tech') ?? $request->input('technician') ?? ''));

        if ($status !== '' && !in_array($status, ['Selesai', 'Batal'], true)) {
            $status = '';
        }

        $role = $this->actorRole($request);
        $isPrivileged = $this->isPrivileged($role);
        $currentTech = (string) ($request->user()?->name ?? '');

        if (!$isPrivileged) {
            if ($currentTech === '') {
                return response()->json(['status' => 'error', 'msg' => 'Akses ditolak.'], 403);
            }
            $tech = $currentTech;
        }

        $query = DB::table('noci_installations')->where('tenant_id', $tenantId);

        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function ($qb) use ($like) {
                $qb->where('ticket_id', 'like', $like)
                    ->orWhere('customer_name', 'like', $like)
                    ->orWhere('address', 'like', $like);
            });
        }

        if ($status !== '') {
            $query->where('status', $status);
        } else {
            $query->whereIn('status', ['Selesai', 'Batal']);
        }

        if ($dateFrom !== '') {
            $query->whereNotNull('finished_at')->whereDate('finished_at', '>=', $dateFrom);
        }
        if ($dateTo !== '') {
            $query->whereNotNull('finished_at')->whereDate('finished_at', '<=', $dateTo);
        }

        if ($tech !== '') {
            $query->where(function ($qb) use ($tech) {
                $qb->where('technician', $tech)
                    ->orWhere('technician_2', $tech)
                    ->orWhere('technician_3', $tech)
                    ->orWhere('technician_4', $tech);
            });
        }

        $total = (clone $query)->count();
        $totalPages = (int) ceil($total / $perPage);
        if ($totalPages < 1) {
            $totalPages = 1;
        }
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        $data = (clone $query)
            ->select([
                'id',
                'ticket_id',
                'customer_name',
                'customer_phone',
                'address',
                'pop',
                'plan_name',
                'price',
                'installation_date',
                'finished_at',
                'status',
                'technician',
                'technician_2',
                'technician_3',
                'technician_4',
                'sales_name',
                'sales_name_2',
                'sales_name_3',
                'notes',
            ])
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ]);
    }

    /**
     * Get installation statistics
     */
    public function stats(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        return response()->json([
            'status' => 'success',
            'summary' => $this->buildProgressSummary($tenantId, null, null, null),
        ]);
    }

    /**
     * Show single installation
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        $row = DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if (!$row) {
            return response()->json(['status' => 'error', 'msg' => 'Data tidak ditemukan'], 404);
        }

        return response()->json(['status' => 'success', 'data' => $row]);
    }

    /**
     * Create new installation
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        $role = $this->actorRole($request);
        $isPrivileged = $this->isPrivileged($role);
        if (!$isPrivileged && $role !== 'teknisi') {
            return response()->json(['status' => 'error', 'msg' => 'Akses ditolak.'], 403);
        }

        $validated = $request->validate([
            'customer_name' => 'nullable|string|max:150',
            'customer_phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'pop' => 'nullable|string|max:100',
            'coordinates' => 'nullable|string|max:100',
            'plan_name' => 'nullable|string|max:100',
            'price' => 'nullable',
            'installation_date' => 'nullable|date',
            'finished_at' => 'nullable',
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'notes' => 'nullable|string',
            'notes_append' => 'nullable|string',
            'note_append' => 'nullable|string',
            'technician' => 'nullable|string|max:255',
            'technician_2' => 'nullable|string|max:100',
            'technician_3' => 'nullable|string|max:100',
            'technician_4' => 'nullable|string|max:100',
            'sales_name' => 'nullable|string|max:100',
            'sales_name_2' => 'nullable|string|max:100',
            'sales_name_3' => 'nullable|string|max:100',
            'is_priority' => 'nullable|boolean',
        ]);

        $phone = $this->normalizePhone($validated['customer_phone'] ?? '');
        $name = trim((string) ($validated['customer_name'] ?? ''));
        if ($name === '') {
            $name = $phone !== '' ? ('Pelanggan ' . $phone) : 'Pelanggan';
        }

        $pop = $this->normalizePop($tenantId, $validated['pop'] ?? null);
        $price = (int) preg_replace('/[^0-9]/', '', (string) ($validated['price'] ?? 0));
        $status = (string) ($validated['status'] ?? 'Baru');
        $installDate = $validated['installation_date'] ?? now()->addDay()->toDateString();

        $finishedAt = null;
        if (in_array($status, ['Selesai', 'Batal'], true)) {
            $finishedAt = $this->normalizeDatetime($validated['finished_at'] ?? null) ?: now()->format('Y-m-d H:i:s');
        }

        $notesPresent = $request->has('notes');
        $notes = $notesPresent ? (string) ($validated['notes'] ?? '') : '';
        if (!$notesPresent) {
            $notes = trim((string) ($validated['notes_append'] ?? $validated['note_append'] ?? ''));
        }

        $ticketId = $this->generateTicketId($tenantId);
        $now = now();

        $id = DB::table('noci_installations')->insertGetId([
            'tenant_id' => $tenantId,
            'ticket_id' => $ticketId,
            'customer_name' => $name,
            'customer_phone' => $phone,
            'address' => (string) ($validated['address'] ?? ''),
            'pop' => $pop,
            'coordinates' => (string) ($validated['coordinates'] ?? ''),
            'plan_name' => (string) ($validated['plan_name'] ?? ''),
            'price' => $price,
            'installation_date' => $installDate,
            'finished_at' => $finishedAt,
            'status' => $status,
            'notes' => $notes,
            'technician' => (string) ($validated['technician'] ?? ''),
            'technician_2' => (string) ($validated['technician_2'] ?? ''),
            'technician_3' => (string) ($validated['technician_3'] ?? ''),
            'technician_4' => (string) ($validated['technician_4'] ?? ''),
            'sales_name' => $this->isBlankSales($validated['sales_name'] ?? null) ? '' : (string) ($validated['sales_name'] ?? ''),
            'sales_name_2' => (string) ($validated['sales_name_2'] ?? ''),
            'sales_name_3' => (string) ($validated['sales_name_3'] ?? ''),
            'is_priority' => !empty($validated['is_priority']) ? 1 : 0,
            'created_at' => $now,
        ]);

        try {
            ActionLog::record($tenantId, $request->user()?->id, 'CREATE', 'installation', $id, [
                'ticket_id' => $ticketId,
                'customer_name' => $name,
            ]);
        } catch (\Throwable) {
        }

        // WA notifications (best-effort, mirrors native behavior).
        $this->afterSaveNotify(
            request: $request,
            tenantId: $tenantId,
            installationId: $id,
            isNew: true,
            oldStatus: '',
            newStatus: $status,
            oldTech: '',
            newTech: (string) ($validated['technician'] ?? ''),
            customerName: $name,
            customerPhone: $phone,
            address: (string) ($validated['address'] ?? ''),
            pop: $pop,
            plan: (string) ($validated['plan_name'] ?? ''),
            price: $price,
            installDate: $installDate,
            finishedAt: $finishedAt,
            sales: [
                (string) ($validated['sales_name'] ?? ''),
                (string) ($validated['sales_name_2'] ?? ''),
                (string) ($validated['sales_name_3'] ?? ''),
            ],
            coords: (string) ($validated['coordinates'] ?? ''),
            changeEntries: []
        );

        return response()->json(['status' => 'success', 'id' => $id], 201);
    }

    /**
     * Update installation
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        $old = DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();
        if (!$old) {
            return response()->json(['status' => 'error', 'msg' => 'Data tidak ditemukan'], 404);
        }

        $role = $this->actorRole($request);
        $isPrivileged = $this->isPrivileged($role);
        $currentTech = (string) ($request->user()?->name ?? '');

        if (!$isPrivileged) {
            $allowBaruUnassigned = strtolower((string) ($old->status ?? '')) === 'baru';
            if (!$this->isAssigned($old, $currentTech) && !$allowBaruUnassigned) {
                return response()->json(['status' => 'error', 'msg' => 'Akses ditolak.'], 403);
            }
        }

        $validated = $request->validate([
            'customer_name' => 'nullable|string|max:150',
            'customer_phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'pop' => 'nullable|string|max:100',
            'coordinates' => 'nullable|string|max:100',
            'plan_name' => 'nullable|string|max:100',
            'price' => 'nullable',
            'installation_date' => 'nullable|date',
            'finished_at' => 'nullable',
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'notes' => 'nullable|string',
            'notes_append' => 'nullable|string',
            'note_append' => 'nullable|string',
            'technician' => 'nullable|string|max:255',
            'technician_2' => 'nullable|string|max:100',
            'technician_3' => 'nullable|string|max:100',
            'technician_4' => 'nullable|string|max:100',
            'sales_name' => 'nullable|string|max:100',
            'sales_name_2' => 'nullable|string|max:100',
            'sales_name_3' => 'nullable|string|max:100',
            'is_priority' => 'nullable|boolean',
        ]);

        $phone = array_key_exists('customer_phone', $validated)
            ? $this->normalizePhone($validated['customer_phone'])
            : (string) ($old->customer_phone ?? '');

        $rawName = array_key_exists('customer_name', $validated)
            ? trim((string) ($validated['customer_name'] ?? ''))
            : trim((string) ($old->customer_name ?? ''));

        $name = $rawName !== '' ? $rawName : (($phone !== '' ? ('Pelanggan ' . $phone) : 'Pelanggan'));

        $address = array_key_exists('address', $validated)
            ? (string) ($validated['address'] ?? '')
            : (string) ($old->address ?? '');

        $pop = array_key_exists('pop', $validated)
            ? $this->normalizePop($tenantId, $validated['pop'] ?? null)
            : (string) ($old->pop ?? '');

        $coords = array_key_exists('coordinates', $validated)
            ? (string) ($validated['coordinates'] ?? '')
            : (string) ($old->coordinates ?? '');

        $plan = array_key_exists('plan_name', $validated)
            ? (string) ($validated['plan_name'] ?? '')
            : (string) ($old->plan_name ?? '');

        $price = array_key_exists('price', $validated)
            ? (int) preg_replace('/[^0-9]/', '', (string) ($validated['price'] ?? 0))
            : (int) ($old->price ?? 0);

        $status = array_key_exists('status', $validated)
            ? (string) ($validated['status'] ?? 'Baru')
            : (string) ($old->status ?? 'Baru');

        $installDate = array_key_exists('installation_date', $validated)
            ? ($validated['installation_date'] ?? now()->addDay()->toDateString())
            : (string) ($old->installation_date ?? now()->addDay()->toDateString());

        $tech = array_key_exists('technician', $validated) ? (string) ($validated['technician'] ?? '') : (string) ($old->technician ?? '');
        $tech2 = array_key_exists('technician_2', $validated) ? (string) ($validated['technician_2'] ?? '') : (string) ($old->technician_2 ?? '');
        $tech3 = array_key_exists('technician_3', $validated) ? (string) ($validated['technician_3'] ?? '') : (string) ($old->technician_3 ?? '');
        $tech4 = array_key_exists('technician_4', $validated) ? (string) ($validated['technician_4'] ?? '') : (string) ($old->technician_4 ?? '');

        if (!$isPrivileged && $currentTech !== '') {
            $keepSelf = in_array($currentTech, [$tech, $tech2, $tech3, $tech4], true);
            $allowBaruUnassigned = strtolower((string) ($old->status ?? '')) === 'baru';
            if (!$keepSelf && !$allowBaruUnassigned) {
                return response()->json(['status' => 'error', 'msg' => 'Akses ditolak.'], 403);
            }
        }

        $sales1 = array_key_exists('sales_name', $validated) ? (string) ($validated['sales_name'] ?? '') : (string) ($old->sales_name ?? '');
        $sales2 = array_key_exists('sales_name_2', $validated) ? (string) ($validated['sales_name_2'] ?? '') : (string) ($old->sales_name_2 ?? '');
        $sales3 = array_key_exists('sales_name_3', $validated) ? (string) ($validated['sales_name_3'] ?? '') : (string) ($old->sales_name_3 ?? '');

        if (!$isPrivileged) {
            if (!$this->isBlankSales((string) ($old->sales_name ?? ''))) {
                $sales1 = (string) ($old->sales_name ?? '');
            } elseif ($this->isBlankSales($sales1)) {
                $sales1 = '';
            }
        } else {
            if ($this->isBlankSales($sales1)) {
                $sales1 = '';
            }
        }

        $isPriority = array_key_exists('is_priority', $validated)
            ? (!empty($validated['is_priority']) ? 1 : 0)
            : (int) ($old->is_priority ?? 0);

        // Notes: supports notes_append / note_append without overwriting.
        $notesPresent = $request->has('notes');
        $baseNotes = (string) ($old->notes ?? '');
        if ($notesPresent) {
            $notes = (string) ($validated['notes'] ?? '');
        } else {
            $append = trim((string) ($validated['notes_append'] ?? $validated['note_append'] ?? ''));
            if ($append !== '') {
                $notes = trim($baseNotes) === '' ? $append : rtrim($baseNotes) . "\n\n" . $append;
            } else {
                $notes = $baseNotes;
            }
        }

        $finishedAt = null;
        if (in_array($status, ['Selesai', 'Batal'], true)) {
            $finishedIn = $this->normalizeDatetime($validated['finished_at'] ?? null);
            if (!empty($old->finished_at) && $finishedIn === null) {
                $finishedAt = (string) $old->finished_at;
            } else {
                $finishedAt = $finishedIn ?: now()->format('Y-m-d H:i:s');
            }
        }

        $changeEntries = [];
        if ($status === 'Selesai') {
            $oldName = trim((string) ($old->customer_name ?? ''));
            $oldPhone = trim((string) ($old->customer_phone ?? ''));
            $oldAddr = trim((string) ($old->address ?? ''));
            if ($oldName !== trim($name)) {
                $changeEntries[] = ['field' => 'customer_name', 'old' => $oldName, 'new' => trim($name)];
            }
            if ($oldPhone !== trim($phone)) {
                $changeEntries[] = ['field' => 'customer_phone', 'old' => $oldPhone, 'new' => trim($phone)];
            }
            if ($oldAddr !== trim($address)) {
                $changeEntries[] = ['field' => 'address', 'old' => $oldAddr, 'new' => trim($address)];
            }
        }

        $now = now();
        DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->update([
                'customer_name' => $name,
                'customer_phone' => $phone,
                'address' => $address,
                'pop' => $pop,
                'coordinates' => $coords,
                'plan_name' => $plan,
                'price' => $price,
                'installation_date' => $installDate,
                'finished_at' => $finishedAt,
                'status' => $status,
                'notes' => $notes,
                'technician' => $tech,
                'technician_2' => $tech2,
                'technician_3' => $tech3,
                'technician_4' => $tech4,
                'sales_name' => $this->isBlankSales($sales1) ? '' : $sales1,
                'sales_name_2' => $sales2,
                'sales_name_3' => $sales3,
                'is_priority' => $isPriority,
            ]);

        if (!empty($changeEntries)) {
            $actor = $this->actorName($request);
            $source = $isPrivileged ? 'admin' : 'teknisi';
            foreach ($changeEntries as $ch) {
                DB::table('noci_installation_changes')->insert([
                    'tenant_id' => $tenantId,
                    'installation_id' => $id,
                    'field_name' => (string) $ch['field'],
                    'old_value' => (string) $ch['old'],
                    'new_value' => (string) $ch['new'],
                    'changed_by' => $actor,
                    'changed_by_role' => $role,
                    'source' => $source,
                    'changed_at' => $now,
                ]);
            }
        }

        try {
            ActionLog::record($tenantId, $request->user()?->id, 'UPDATE', 'installation', $id, ['status' => $status]);
        } catch (\Throwable) {
        }

        // WA notifications (best-effort, mirrors native behavior).
        $this->afterSaveNotify(
            request: $request,
            tenantId: $tenantId,
            installationId: $id,
            isNew: false,
            oldStatus: (string) ($old->status ?? ''),
            newStatus: $status,
            oldTech: (string) ($old->technician ?? ''),
            newTech: $tech,
            customerName: $name,
            customerPhone: $phone,
            address: $address,
            pop: $pop,
            plan: $plan,
            price: $price,
            installDate: $installDate,
            finishedAt: $finishedAt,
            sales: [$sales1, $sales2, $sales3],
            coords: $coords,
            changeEntries: $changeEntries
        );

        return response()->json(['status' => 'success', 'id' => $id]);
    }

    /**
     * Delete installation
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        $role = $this->actorRole($request);
        if (!$this->isPrivileged($role)) {
            return response()->json(['status' => 'error', 'msg' => 'Akses ditolak.'], 403);
        }

        $row = DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first(['ticket_id']);

        if (!$row) {
            return response()->json(['status' => 'error', 'msg' => 'Data tidak ditemukan'], 404);
        }

        DB::table('noci_installations')->where('tenant_id', $tenantId)->where('id', $id)->delete();

        try {
            ActionLog::record($tenantId, $request->user()?->id, 'DELETE', 'installation', $id, [
                'ticket_id' => (string) ($row->ticket_id ?? ''),
            ]);
        } catch (\Throwable) {
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Update status only (quick action)
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        $role = $this->actorRole($request);
        $isPrivileged = $this->isPrivileged($role);
        if (!$isPrivileged && $role !== 'teknisi') {
            return response()->json(['status' => 'error', 'msg' => 'Akses ditolak.'], 403);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'notes' => 'nullable|string',
        ]);

        $row = DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first(['status', 'finished_at', 'technician', 'technician_2', 'technician_3', 'technician_4']);
        if (!$row) {
            return response()->json(['status' => 'error', 'msg' => 'Data tidak ditemukan'], 404);
        }

        if (!$isPrivileged) {
            $currentTech = $this->actorName($request);
            if ($currentTech === '') {
                return response()->json(['status' => 'error', 'msg' => 'Akses ditolak.'], 403);
            }
            $allowBaruUnassigned = strtolower((string) ($row->status ?? '')) === 'baru';
            if (!$this->isAssigned($row, $currentTech) && !$allowBaruUnassigned) {
                return response()->json(['status' => 'error', 'msg' => 'Akses ditolak.'], 403);
            }
        }

        $status = (string) $validated['status'];
        $update = [
            'status' => $status,
        ];

        if ($request->has('notes')) {
            $update['notes'] = (string) ($validated['notes'] ?? '');
        }

        if (in_array($status, ['Selesai', 'Batal'], true)) {
            if (empty($row->finished_at)) {
                $update['finished_at'] = now()->format('Y-m-d H:i:s');
            }
        } else {
            $update['finished_at'] = null;
        }

        DB::table('noci_installations')->where('tenant_id', $tenantId)->where('id', $id)->update($update);

        return response()->json(['status' => 'success']);
    }

    /**
     * Get installation history/changes
     */
    public function history(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        $exists = DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->exists();
        if (!$exists) {
            return response()->json(['status' => 'error', 'msg' => 'Data tidak ditemukan'], 404);
        }

        $rows = DB::table('noci_installation_changes')
            ->where('tenant_id', $tenantId)
            ->where('installation_id', $id)
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->get([
                'field_name',
                'old_value',
                'new_value',
                'changed_at',
                'changed_by',
                'changed_by_role',
                'source',
            ]);

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    /**
     * Get available POPs for dropdown
     */
    public function pops(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        $pops = DB::table('noci_pops')
            ->where('tenant_id', $tenantId)
            ->orderBy('pop_name')
            ->pluck('pop_name')
            ->values();

        return response()->json(['status' => 'success', 'data' => $pops]);
    }

    /**
     * Get available technicians for dropdown
     */
    public function technicians(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        $list = LegacyUser::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('role', ['teknisi', 'svp lapangan'])
            ->orderBy('name')
            ->pluck('name')
            ->values();

        return response()->json(['status' => 'success', 'data' => $list]);
    }

    /**
     * Claim a task (status -> Proses)
     */
    public function claim(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        $validated = $request->validate([
            'technician' => 'required|string|max:255',
            'installation_date' => 'nullable|date',
            'technician_2' => 'nullable|string|max:100',
            'technician_3' => 'nullable|string|max:100',
            'technician_4' => 'nullable|string|max:100',
        ]);

        $row = DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first(['technician', 'technician_2', 'technician_3', 'technician_4']);

        if (!$row) {
            return response()->json(['status' => 'error', 'msg' => 'Data tidak ditemukan'], 404);
        }

        $tech = trim((string) $validated['technician']);
        if ($tech === '') {
            return response()->json(['status' => 'error', 'msg' => 'ID/Teknisi kosong'], 422);
        }

        if (!empty($row->technician) && (string) $row->technician !== $tech) {
            return response()->json(['status' => 'error', 'msg' => 'Sudah diambil oleh ' . (string) $row->technician], 422);
        }

        $planDate = $validated['installation_date'] ?? now()->toDateString();

        DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->update([
                'technician' => $tech,
                'technician_2' => trim((string) ($validated['technician_2'] ?? '')) ?: (string) ($row->technician_2 ?? ''),
                'technician_3' => trim((string) ($validated['technician_3'] ?? '')) ?: (string) ($row->technician_3 ?? ''),
                'technician_4' => trim((string) ($validated['technician_4'] ?? '')) ?: (string) ($row->technician_4 ?? ''),
                'status' => 'Proses',
                'installation_date' => $planDate,
            ]);

        return response()->json(['status' => 'success']);
    }

    /**
     * Transfer task to another technician (append notes)
     */
    public function transfer(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        $validated = $request->validate([
            'to_tech' => 'required|string|max:255',
            'reason' => 'nullable|string|max:255',
        ]);

        $task = DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if (!$task) {
            return response()->json(['status' => 'error', 'msg' => 'Data tidak ditemukan'], 404);
        }

        $role = $this->actorRole($request);
        $isPrivileged = $this->isPrivileged($role);
        $currentTech = (string) ($request->user()?->name ?? '');
        if (!$isPrivileged && !$this->isAssigned($task, $currentTech)) {
            return response()->json(['status' => 'error', 'msg' => 'Akses ditolak.'], 403);
        }

        $toTech = trim((string) $validated['to_tech']);
        $reason = trim((string) ($validated['reason'] ?? ''));
        if ($toTech === '') {
            return response()->json(['status' => 'error', 'msg' => 'Target wajib dipilih'], 422);
        }

        $sender = $this->actorName($request);
        $logNote = "\n\n[TRANSFER] Dari: {$sender} Ke: {$toTech}\nAlasan: " . ($reason ?: '-') . "\nWaktu: " . now()->format('d/m H:i');

        $notes = rtrim((string) ($task->notes ?? ''));
        $notes = $notes === '' ? ltrim($logNote) : ($notes . $logNote);

        DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->update([
                'technician' => $toTech,
                'notes' => $notes,
            ]);

        $baseUrl = rtrim($request->getSchemeAndHttpHost(), '/');
        $link = $baseUrl . '/teknisi?task_id=' . $id;

        $msg = "*INFO TRANSFER TUGAS*\n\n";
        $msg .= "Halo *{$toTech}*, Anda menerima limpahan tugas baru.\n\n";
        $msg .= "Dari: *{$sender}*\n";
        $msg .= "Alasan: " . ($reason ?: '-') . "\n\n";
        $msg .= "Nama: " . ((string) ($task->customer_name ?? '-') ?: '-') . "\n";
        $msg .= "Alamat: " . ((string) ($task->address ?? '-') ?: '-') . "\n";
        $msg .= "Nomor Whatsapp : " . ((string) ($task->customer_phone ?? '-') ?: '-') . "\n\n";
        $msg .= "KLIK UNTUK PROSES:\n{$link}";

        // Best-effort, don't block transfer result.
        $this->waSendPersonal($tenantId, $toTech, $msg, 'WA (Transfer)');

        return response()->json(['status' => 'success']);
    }

    /**
     * Request cancel (status -> Req_Batal, append notes)
     */
    public function requestCancel(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $task = DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if (!$task) {
            return response()->json(['status' => 'error', 'msg' => 'Data tidak ditemukan'], 404);
        }

        $role = $this->actorRole($request);
        $isPrivileged = $this->isPrivileged($role);
        $currentTech = (string) ($request->user()?->name ?? '');
        if (!$isPrivileged && !$this->isAssigned($task, $currentTech)) {
            return response()->json(['status' => 'error', 'msg' => 'Akses ditolak.'], 403);
        }

        $actor = $this->actorName($request);
        $reason = trim((string) ($validated['reason'] ?? ''));
        $log = "\n\n[REQ BATAL] Oleh: {$actor} Alasan: {$reason} " . now()->format('d/m H:i');

        $notes = rtrim((string) ($task->notes ?? ''));
        $notes = $notes === '' ? ltrim($log) : ($notes . $log);

        DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->update([
                'status' => 'Req_Batal',
                'notes' => $notes,
            ]);

        $waConf = $this->resolveActiveWaConfig($tenantId);
        $targetGroup = $this->resolveWaGroupId($tenantId, (string) ($task->pop ?? ''), $waConf);
        if ($waConf && $targetGroup) {
            $msg = "*⚠️ PENGAJUAN BATAL ⚠️*\n\n";
            $msg .= "Teknisi: *{$actor}*\n";
            $msg .= "Pelanggan: " . ((string) ($task->customer_name ?? '-') ?: '-') . "\n";
            $msg .= "Alamat: " . ((string) ($task->address ?? '-') ?: '-') . "\n";
            $msg .= "Alasan: _" . ($reason ?: '-') . "_\n\n";
            $msg .= "Mohon Admin/SVP segera tinjau via Dashboard.";
            $this->waSendGroup($tenantId, $targetGroup, $msg, 'WA Group (Req Batal)');
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Decide cancel request (approve -> Batal, reject -> Pending)
     */
    public function decideCancel(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        $role = $this->actorRole($request);
        if (!$this->isPrivileged($role)) {
            return response()->json(['status' => 'error', 'msg' => 'Akses ditolak.'], 403);
        }

        $validated = $request->validate([
            'decision' => 'required|string|in:approve,reject',
            'reason' => 'nullable|string|max:255',
        ]);

        $task = DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if (!$task) {
            return response()->json(['status' => 'error', 'msg' => 'Data tidak ditemukan'], 404);
        }

        $actor = $this->actorName($request);
        $note = trim((string) ($validated['reason'] ?? ''));
        $decision = (string) $validated['decision'];

        if ($decision === 'approve') {
            $finalStatus = 'Batal';
            $log = "\n\n[SYSTEM] Pembatalan DISETUJUI oleh {$actor}.\nCatatan: {$note}";
            $finishedAt = now()->format('Y-m-d H:i:s');
        } else {
            $finalStatus = 'Pending';
            $log = "\n\n[SYSTEM] Pembatalan DITOLAK oleh {$actor}. Kembali Pending.\nAlasan: {$note}";
            $finishedAt = null;
        }

        $notes = rtrim((string) ($task->notes ?? ''));
        $notes = $notes === '' ? ltrim($log) : ($notes . $log);

        DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->update([
                'status' => $finalStatus,
                'notes' => $notes,
                'finished_at' => $finishedAt,
            ]);

        if ($decision === 'approve') {
            $waConf = $this->resolveActiveWaConfig($tenantId);
            $targetGroup = $this->resolveWaGroupId($tenantId, (string) ($task->pop ?? ''), $waConf);
            if ($waConf && $targetGroup) {
                $msg = "*INFO PEMBATALAN DISETUJUI*\n\n";
                $msg .= "Status: *BATAL (Closed)*\n";
                $msg .= "Oleh: *{$actor}*\n";
                $msg .= "Alasan ACC: _" . ($note ?: '-') . "_\n\n";
                $msg .= "Pelanggan: " . ((string) ($task->customer_name ?? '-') ?: '-') . "\n";
                $msg .= "Alamat: " . ((string) ($task->address ?? '-') ?: '-') . "\n";
                $msg .= "Teknisi: " . ((string) ($task->technician ?? '-') ?: '-') . "\n";
                $this->waSendGroup($tenantId, $targetGroup, $msg, 'WA Group (ACC Batal)');
            }
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Toggle priority (is_priority)
     */
    public function togglePriority(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }

        $role = $this->actorRole($request);
        if (!$this->isPrivileged($role)) {
            return response()->json(['status' => 'error', 'msg' => 'Akses ditolak.'], 403);
        }

        $validated = $request->validate([
            'val' => 'required|integer|in:0,1',
        ]);

        DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->update([
                'is_priority' => (int) $validated['val'],
            ]);

        return response()->json(['status' => 'success']);
    }

    /**
     * Send manual recap to POP group (statuses Baru/Survey/Proses)
     */
    public function sendPopRecap(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if ($tenantId <= 0) {
            return response()->json(['status' => 'error', 'msg' => 'Tenant context missing'], 403);
        }
        \Illuminate\Support\Facades\Log::info("DEBUG: sendPopRecap hit for tenant {$tenantId}");

        $role = $this->actorRole($request);
        if (!$this->isPrivileged($role)) {
            return response()->json(['status' => 'error', 'msg' => 'Akses ditolak.'], 403);
        }

        $validated = $request->validate([
            'pop_name' => 'required|string|max:100',
        ]);

        $targetPop = trim((string) $validated['pop_name']);
        if ($targetPop === '') {
            return response()->json(['status' => 'error', 'msg' => 'Nama POP wajib dipilih'], 422);
        }

        $waConf = $this->resolveActiveWaConfig($tenantId);
        if (!$waConf) {
            return response()->json(['status' => 'error', 'msg' => 'Config WA belum disetting'], 422);
        }

        $targetGroup = $this->resolveWaGroupId($tenantId, $targetPop, $waConf);
        if (!$targetGroup) {
            return response()->json(['status' => 'error', 'msg' => 'Group WA tidak ditemukan'], 422);
        }

        $items = DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where('pop', $targetPop)
            ->whereIn('status', ['Baru', 'Survey', 'Proses'])
            ->orderByRaw("FIELD(status, 'Baru', 'Survey', 'Proses')")
            ->orderBy('id')
            ->get();

        if ($items->count() === 0) {
            return response()->json(['status' => 'error', 'msg' => "Tidak ada data antrian (Baru/Survey/Proses) di POP {$targetPop}."], 422);
        }

        // $actor = $this->actorName($request);
        $u = $request->user();
        $actor = ($u && !empty($u->name)) ? (string) $u->name : (string) (session('admin_name') ?: session('teknisi_name') ?: 'System');
        \Illuminate\Support\Facades\Log::info("DEBUG: Actor resolved: {$actor}");
        $waktuUpdate = now()->format('d M Y H:i');

        $headerMsg = "==================\n";
        $headerMsg .= "UPDATE MANUAL (Oleh: {$actor})\n";
        $headerMsg .= "POP: " . strtoupper($targetPop) . "\n";
        $headerMsg .= "Waktu: {$waktuUpdate}\n";
        $headerMsg .= "==================";

        $headerSent = ($this->waSendGroup($tenantId, $targetGroup, $headerMsg, 'WA Recap (Header)')['status'] ?? '') === 'sent';
        if (!$headerSent) {
            sleep(2);
            $headerSent = ($this->waSendGroup($tenantId, $targetGroup, $headerMsg, 'WA Recap (Header Retry 1)')['status'] ?? '') === 'sent';
        }
        if (!$headerSent) {
            sleep(2);
            $headerSent = ($this->waSendGroup($tenantId, $targetGroup, $headerMsg, 'WA Recap (Header Retry 2)')['status'] ?? '') === 'sent';
        }
        if (!$headerSent) {
            return response()->json(['status' => 'error', 'msg' => 'Header rekap gagal dikirim. Cek konfigurasi gateway/backup.'], 422);
        }

        sleep(2);

        $baseUrl = rtrim($request->getSchemeAndHttpHost(), '/');
        $sent = 0;
        $failed = 0;

        foreach ($items as $item) {
            $statusU = strtoupper((string) ($item->status ?? ''));
            $tglStr = now()->format('d F Y');

            if ($statusU === 'BARU') {
                $judul = 'INFO PASANG BARU';
                $btn = 'AMBIL';
            } else {
                $judul = "STATUS: {$statusU}";
                $btn = 'DETAIL';
            }

            $link = $baseUrl . '/teknisi?task_id=' . (int) ($item->id ?? 0);

            $msg = "{$judul}\n{$tglStr}\n\n";
            $msg .= "Nama: " . (string) ($item->customer_name ?? '-') . "\n";
            $msg .= "Wa: " . ((string) ($item->customer_phone ?? '-') ?: '-') . "\n";
            $msg .= "Alamat: " . ((string) ($item->address ?? '-') ?: '-') . "\n";
            $msg .= "Maps: " . ((string) ($item->coordinates ?? '-') ?: '-') . "\n";
            $msg .= "Paket: " . ((string) ($item->plan_name ?? '-') ?: '-') . "\n";
            $msg .= "Sales: " . ((string) ($item->sales_name ?? '-') ?: '-') . "\n";
            $msg .= "Teknisi: " . ((string) ($item->technician ?? '-') ?: '-') . "\n\n";
            $msg .= "{$btn}: {$link}";

            $ok = ($this->waSendGroup($tenantId, $targetGroup, $msg, 'WA Recap (Item)')['status'] ?? '') === 'sent';
            if ($ok) {
                $sent++;
            } else {
                $failed++;
            }

            sleep(2);
        }

        if ($sent === 0) {
            return response()->json(['status' => 'error', 'msg' => 'Semua pesan rekap gagal dikirim. Cek Log/Outbox.'], 422);
        }

        $resp = ['status' => 'success', 'count' => $items->count(), 'sent' => $sent, 'failed' => $failed];
        if ($failed > 0) {
            $resp['msg'] = 'Sebagian pesan gagal. Cek Log/Outbox.';
        }

        return response()->json($resp);
    }
}
