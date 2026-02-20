<?php

namespace App\Http\Controllers\Web;

use App\Support\WaGatewaySender;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy-compatible Direct API.
 *
 * Native client expects:
 *  - POST /direct/api.php?t=... with FormData { action, visit_id, ... }
 *  - GET  /direct/api.php?t=...&action=get_messages&visit_id=...
 *
 * Actions supported (parity with dashboard/direct/api.php):
 *  - check_status
 *  - start_session
 *  - get_messages
 *  - send (text or multipart image)
 */
class DirectApiController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        if ($request->isMethod('options')) {
            return response()->json([], 204, $this->corsHeaders($request));
        }

        // Direct uses Asia/Jakarta timestamps and time comparisons (scheduled mode).
        date_default_timezone_set('Asia/Jakarta');

        $token = trim((string) ($request->query('t') ?? $request->input('t') ?? ''));
        if ($token === '') {
            return $this->json(['status' => 'error', 'msg' => 'Token tenant kosong'], $request);
        }

        $tenant = DB::table('tenants')
            ->where('public_token', $token)
            ->where('status', 'active')
            ->first(['id', 'name']);

        $tenantId = (int) ($tenant->id ?? 0);
        if ($tenantId <= 0) {
            return $this->json(['status' => 'error', 'msg' => 'Tenant tidak valid'], $request);
        }

        $action = (string) ($request->input('action') ?? $request->query('action') ?? '');

        try {
            if ($action === 'check_status') {
                return $this->checkStatus($request, $tenantId);
            }
            if ($action === 'start_session') {
                return $this->startSession($request, $tenantId);
            }
            if ($action === 'get_messages') {
                return $this->getMessages($request, $tenantId);
            }
            if ($action === 'send') {
                return $this->sendMessage($request, $tenantId);
            }

            return $this->json(['status' => 'error', 'msg' => 'Invalid Action'], $request, 400);
        } catch (\Throwable $e) {
            return $this->json(['status' => 'error', 'msg' => $e->getMessage()], $request, 500);
        }
    }

    private function checkStatus(Request $request, int $tenantId): JsonResponse
    {
        $welMsg = $this->resolveWelcomeMessage($tenantId);

        $set = null;
        try {
            $set = DB::table('noci_chat_settings')
                ->where('tenant_id', $tenantId)
                ->where('id', 1)
                ->first();
        } catch (\Throwable) {
            $set = null;
        }

        if (!$set) {
            return $this->json(['status' => 'online', 'welcome_msg' => $welMsg], $request);
        }

        $status = 'online';
        $mode = (string) ($set->mode ?? 'manual_on');
        if ($mode === 'manual_off') {
            $status = 'offline';
        } elseif ($mode === 'scheduled') {
            $now = date('H:i:s');
            $start = (string) ($set->start_hour ?? '08:00:00');
            $end = (string) ($set->end_hour ?? '17:00:00');
            if ($now < $start || $now > $end) $status = 'offline';
        }

        $waLink = '';
        if ($status === 'offline') {
            $waNum = (string) ($set->wa_number ?? '');
            $rawMsg = (string) ($set->wa_message ?? '');
            $visitId = trim((string) ($request->input('visit_id') ?? ''));
            if ($visitId !== '') {
                $rawMsg .= "\n\n(ID Pelanggan: {$visitId})";
            }
            $waLink = "https://wa.me/{$waNum}?text=" . urlencode($rawMsg);
        }

        return $this->json([
            'status' => $status,
            'wa_link' => $waLink,
            'welcome_msg' => $welMsg,
        ], $request);
    }

    private function resolveWelcomeMessage(int $tenantId, string $customerName = ''): string
    {
        $welcome = 'Halo kak, ada yang bisa kami bantu?';

        try {
            $tpl = DB::table('noci_msg_templates')
                ->where('tenant_id', $tenantId)
                ->where('code', 'web_welcome')
                ->value('message');
            if (is_string($tpl) && trim($tpl) !== '') {
                $welcome = $tpl;
            }
        } catch (\Throwable) {
        }

        if ($customerName !== '') {
            $welcome = str_replace(['{name}', '{customer_name}'], $customerName, $welcome);
        }

        return $welcome;
    }

    private function persistWelcomeMessageIfNeeded(int $tenantId, string $visitId, string $customerName = ''): void
    {
        if ($visitId === '') return;

        try {
            $chatQ = DB::table('noci_chat')->where('visit_id', $visitId);
            if ($this->hasColumn('noci_chat', 'tenant_id')) $chatQ->where('tenant_id', $tenantId);
            if ($chatQ->exists()) return;

            $ins = [
                'visit_id' => $visitId,
                'sender' => 'admin',
                'message' => $this->resolveWelcomeMessage($tenantId, $customerName),
                'type' => 'text',
                'is_read' => 1,
                'created_at' => DB::raw('NOW()'),
            ];
            if ($this->hasColumn('noci_chat', 'tenant_id')) $ins['tenant_id'] = $tenantId;
            if ($this->hasColumn('noci_chat', 'updated_at')) $ins['updated_at'] = DB::raw('NOW()');
            if ($this->hasColumn('noci_chat', 'msg_status')) $ins['msg_status'] = 'active';
            DB::table('noci_chat')->insert($ins);
        } catch (\Throwable) {
        }
    }

    private function startSession(Request $request, int $tenantId): JsonResponse
    {
        $visitId = trim((string) ($request->input('visit_id') ?? ''));
        if ($visitId === '') {
            return $this->json(['status' => 'error', 'msg' => 'visit_id kosong'], $request, 422);
        }

        $name = trim((string) ($request->input('name') ?? ''));
        if ($name === '') {
            $name = 'Pelanggan ' . substr($visitId, -4);
        }

        $ip = (string) ($request->server('REMOTE_ADDR') ?? $request->ip() ?? '0.0.0.0');
        $loc = $this->detectIsp($ip);

        // Upsert customer row (native behavior).
        $hasTenant = $this->hasColumn('noci_customers', 'tenant_id');
        $custQ = DB::table('noci_customers')->where('visit_id', $visitId);
        if ($hasTenant) $custQ->where('tenant_id', $tenantId);
        $exists = $custQ->exists();

        if ($exists) {
            $update = ['customer_name' => $name];
            if ($this->hasColumn('noci_customers', 'ip_address')) $update['ip_address'] = $ip;
            if ($this->hasColumn('noci_customers', 'location_info')) $update['location_info'] = $loc;
            if ($this->hasColumn('noci_customers', 'last_seen')) $update['last_seen'] = DB::raw('NOW()');
            if ($this->hasColumn('noci_customers', 'visit_count')) $update['visit_count'] = DB::raw('visit_count+1');
            // Preserve `Selesai` until customer really sends a new message.
            // This keeps sendMessage() able to trigger WA notification on reopen.
            $custQ->update($update);
        } else {
            $insert = [
                'visit_id' => $visitId,
                'customer_name' => $name,
            ];
            if ($hasTenant) $insert['tenant_id'] = $tenantId;
            if ($this->hasColumn('noci_customers', 'customer_phone')) $insert['customer_phone'] = '-';
            if ($this->hasColumn('noci_customers', 'customer_address')) $insert['customer_address'] = '-';
            if ($this->hasColumn('noci_customers', 'ip_address')) $insert['ip_address'] = $ip;
            if ($this->hasColumn('noci_customers', 'location_info')) $insert['location_info'] = $loc;
            if ($this->hasColumn('noci_customers', 'last_seen')) $insert['last_seen'] = DB::raw('NOW()');
            if ($this->hasColumn('noci_customers', 'visit_count')) $insert['visit_count'] = 1;
            if ($this->hasColumn('noci_customers', 'status')) $insert['status'] = 'Baru';
            DB::table('noci_customers')->insert($insert);
        }

        return $this->json([
            'status' => 'success',
            'welcome_msg' => $this->resolveWelcomeMessage($tenantId, $name),
        ], $request);
    }

    private function getMessages(Request $request, int $tenantId): JsonResponse
    {
        $visitId = trim((string) ($request->query('visit_id') ?? ''));
        if ($visitId === '') {
            return $this->json(['status' => 'success', 'data' => [], 'session_status' => 'Baru', 'server_time' => ''], $request);
        }

        // Update last_seen.
        if ($this->hasColumn('noci_customers', 'last_seen')) {
            try {
                $q = DB::table('noci_customers')->where('visit_id', $visitId);
                if ($this->hasColumn('noci_customers', 'tenant_id')) $q->where('tenant_id', $tenantId);
                $q->update(['last_seen' => DB::raw('NOW()')]);
            } catch (\Throwable) {
            }
        }

        $currStatus = 'Baru';
        try {
            $qStat = DB::table('noci_customers')->where('visit_id', $visitId);
            if ($this->hasColumn('noci_customers', 'tenant_id')) $qStat->where('tenant_id', $tenantId);
            $currStatus = (string) ($qStat->value('status') ?? 'Baru');
        } catch (\Throwable) {
            $currStatus = 'Baru';
        }

        $lastSync = trim((string) ($request->query('last_sync') ?? ''));
        $hasUpdatedAt = $this->hasColumn('noci_chat', 'updated_at');

        $q = DB::table('noci_chat')->where('visit_id', $visitId)->orderBy('id');
        if ($this->hasColumn('noci_chat', 'tenant_id')) $q->where('tenant_id', $tenantId);

        $isDelta = false;
        if ($lastSync !== '') {
            // Delta sync: only fetch rows changed since last_sync (lighter than full history).
            // Use updated_at when available so edits/deletes are synced too.
            $syncCol = $hasUpdatedAt ? 'updated_at' : 'created_at';
            $q->where($syncCol, '>', $lastSync);
            $isDelta = true;
        }

        $select = ['id', 'sender', 'message', 'type', 'is_edited', 'created_at'];
        if ($hasUpdatedAt) $select[] = 'updated_at';
        $rows = $q->get($select);

        $uploadDir = public_path('uploads/chat');
        $hasLocalUploads = is_dir($uploadDir);

        $data = [];
        $serverTime = '';
        foreach ($rows as $r) {
            $type = (string) ($r->type ?? 'text');
            $msg = (string) ($r->message ?? '');

            // Track the newest sync cursor (DB timestamp) without an extra NOW() query.
            $ts = $hasUpdatedAt ? ((string) ($r->updated_at ?? '')) : ((string) ($r->created_at ?? ''));
            if ($ts !== '' && ($serverTime === '' || $ts > $serverTime)) {
                $serverTime = $ts;
            }

            if ($hasLocalUploads && $type === 'image') {
                $p = $uploadDir . DIRECTORY_SEPARATOR . basename($msg);
                if (!is_file($p)) {
                    $type = 'text';
                    $msg = '⚠️ Gambar tidak ditemukan';
                }
            }
            $data[] = [
                'id' => (int) ($r->id ?? 0),
                'sender' => (string) ($r->sender ?? ''),
                'message' => $msg,
                'type' => $type,
                'is_edited' => (int) ($r->is_edited ?? 0),
                'time' => !empty($r->created_at) ? date('H:i', strtotime((string) $r->created_at)) : '',
            ];
        }

        if ($serverTime === '' && $lastSync !== '') {
            // No changes: keep existing cursor so polling stays cheap.
            $serverTime = $lastSync;
        }

        return $this->json([
            'status' => 'success',
            'data' => $data,
            'session_status' => $currStatus,
            'server_time' => $serverTime,
            'is_delta' => $isDelta,
        ], $request);
    }

    private function sendMessage(Request $request, int $tenantId): JsonResponse
    {
        $visitId = trim((string) ($request->input('visit_id') ?? ''));
        if ($visitId === '') {
            return $this->json(['status' => 'error', 'msg' => 'visit_id kosong'], $request, 422);
        }

        // Ensure customer exists (native behavior).
        $custQ = DB::table('noci_customers')->where('visit_id', $visitId);
        if ($this->hasColumn('noci_customers', 'tenant_id')) $custQ->where('tenant_id', $tenantId);
        if (!$custQ->exists()) {
            $ip = (string) ($request->server('REMOTE_ADDR') ?? $request->ip() ?? '0.0.0.0');
            $insert = [
                'visit_id' => $visitId,
                'customer_name' => 'Pelanggan (Auto)',
                'last_seen' => DB::raw('NOW()'),
                'status' => 'Baru',
            ];
            if ($this->hasColumn('noci_customers', 'tenant_id')) $insert['tenant_id'] = $tenantId;
            if ($this->hasColumn('noci_customers', 'ip_address')) $insert['ip_address'] = $ip;
            DB::table('noci_customers')->insert($insert);
        }

        $prevStatus = 'Baru';
        try {
            $qPrev = DB::table('noci_customers')->where('visit_id', $visitId);
            if ($this->hasColumn('noci_customers', 'tenant_id')) $qPrev->where('tenant_id', $tenantId);
            $prevStatus = (string) ($qPrev->value('status') ?? 'Baru');
        } catch (\Throwable) {
            $prevStatus = 'Baru';
        }

        $sender = 'user';
        $message = '';
        $type = 'text';
        $displayMsg = '';

        if ($request->hasFile('image') && $request->file('image')?->isValid()) {
            $file = $request->file('image');
            $ext = strtolower((string) $file->getClientOriginalExtension());
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            if (!in_array($ext, $allowed, true)) {
                return $this->json(['status' => 'error', 'msg' => 'Format gambar tidak valid'], $request, 422);
            }
            if ($file->getSize() > 5_000_000) {
                return $this->json(['status' => 'error', 'msg' => 'Ukuran gambar maksimal 5MB'], $request, 422);
            }

            $dir = public_path('uploads/chat');
            if (!is_dir($dir)) @mkdir($dir, 0755, true);

            $newName = time() . '_' . random_int(1000, 9999) . '.' . $ext;
            $file->move($dir, $newName);

            $message = $newName;
            $type = 'image';
            $displayMsg = '[Foto]';
        } else {
            $raw = trim((string) ($request->input('message') ?? ''));
            $message = $raw;
            $displayMsg = $raw;
        }

        if ($message === '') {
            return $this->json(['status' => 'error', 'msg' => 'Pesan kosong'], $request, 422);
        }

        $customerName = '';
        try {
            $qName = DB::table('noci_customers')->where('visit_id', $visitId);
            if ($this->hasColumn('noci_customers', 'tenant_id')) $qName->where('tenant_id', $tenantId);
            $customerName = (string) ($qName->value('customer_name') ?? '');
        } catch (\Throwable) {
            $customerName = '';
        }

        // Welcome is display-only before first customer message.
        // Persist it only when user sends the first message.
        $this->persistWelcomeMessageIfNeeded($tenantId, $visitId, $customerName);

        $ins = [
            'visit_id' => $visitId,
            'sender' => $sender,
            'message' => $message,
            'type' => $type,
            'is_read' => 0,
            'created_at' => DB::raw('NOW()'),
        ];
        if ($this->hasColumn('noci_chat', 'tenant_id')) $ins['tenant_id'] = $tenantId;
        if ($this->hasColumn('noci_chat', 'updated_at')) $ins['updated_at'] = DB::raw('NOW()');
        if ($this->hasColumn('noci_chat', 'msg_status')) $ins['msg_status'] = 'active';

        DB::table('noci_chat')->insert($ins);

        // Update customer status to Menunggu unless already Proses.
        try {
            $qUp = DB::table('noci_customers')->where('visit_id', $visitId);
            if ($this->hasColumn('noci_customers', 'tenant_id')) $qUp->where('tenant_id', $tenantId);
            $update = ['status' => 'Menunggu'];
            if ($this->hasColumn('noci_customers', 'last_seen')) $update['last_seen'] = DB::raw('NOW()');
            if ($this->hasColumn('noci_customers', 'status')) {
                $qUp->where('status', '!=', 'Proses')->update($update);
            } else {
                $qUp->update($update);
            }
        } catch (\Throwable) {
        }

        if ($type === 'text') {
            $this->autoDetectInfo($tenantId, $displayMsg, $visitId);
        }

        $qCount = DB::table('noci_chat')
            ->where('visit_id', $visitId)
            ->where('sender', 'user');
        if ($this->hasColumn('noci_chat', 'tenant_id')) $qCount->where('tenant_id', $tenantId);
        $countUserMsgs = (int) $qCount->count('id');

        $shouldNotify = ($prevStatus === 'Selesai') || ($countUserMsgs === 1);
        if ($shouldNotify) {
            $cust = DB::table('noci_customers')->where('visit_id', $visitId);
            if ($this->hasColumn('noci_customers', 'tenant_id')) $cust->where('tenant_id', $tenantId);
            $dCust = $cust->first();

            $waText = "*CHAT BARU DARI WEBSITE*\nNama: {customer_name}\nPesan: {message}\nLink: {link}";
            try {
                $tpl = DB::table('noci_msg_templates')
                    ->where('tenant_id', $tenantId)
                    ->where('code', 'wa_chat')
                    ->value('message');
                if (is_string($tpl) && trim($tpl) !== '') $waText = $tpl;
            } catch (\Throwable) {
            }

            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $adminHost = 'my.daratlaut.com';
            $adminLink = $protocol . "://{$adminHost}/chat/index.php?id={$visitId}";

            $name = (string) ($dCust->customer_name ?? 'User');
            $phone = (string) ($dCust->customer_phone ?? '-');
            $addr = (string) ($dCust->customer_address ?? '-');

            $repl = [
                '{visit_id}' => $visitId,
                '{link}' => $adminLink,
                '{message}' => $displayMsg,
                '{name}' => $name,
                '{customer_name}' => $name,
                '{phone}' => $phone,
                '{address}' => $addr,
                '{time}' => date('H:i'),
            ];
            foreach ($repl as $k => $v) {
                $waText = str_replace($k, (string) $v, $waText);
            }

            $this->safeSendWa($tenantId, $waText);
        }

        return $this->json(['status' => 'success'], $request);
    }

    private function autoDetectInfo(int $tenantId, string $msg, string $visitId): void
    {
        try {
            $qOld = DB::table('noci_customers')
                ->where('visit_id', $visitId)
                ->when($this->hasColumn('noci_customers', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                ->first(['customer_name', 'customer_phone', 'customer_address']);

            $oldName = (string) (($qOld->customer_name ?? '') ?: '');
            $oldPhone = (string) (($qOld->customer_phone ?? '') ?: '');
            $oldAddr = (string) (($qOld->customer_address ?? '') ?: '');

            $detectedPhone = null;
            $detectedName = null;
            $detectedAddr = null;

            if (preg_match('/(?:^|\\s|\\b)(?:\\+?62|0)8[0-9\\-\\s\\.]{8,18}(?:\\b|$)/', $msg, $m)) {
                $rawHP = preg_replace('/[^0-9]/', '', (string) ($m[0] ?? ''));
                if (strlen($rawHP) >= 9 && strlen($rawHP) <= 15) {
                    if (substr($rawHP, 0, 1) === '0') $detectedPhone = '62' . substr($rawHP, 1);
                    elseif (substr($rawHP, 0, 2) === '62') $detectedPhone = $rawHP;
                    else $detectedPhone = '62' . $rawHP;
                }
            }

            $namePattern = '/(?:^|\\n|\\.|,)\\s*(?:nama|nm|user|an\\.?|a\\/n|atas\\s*nama)\\s*(?:lengkap|panggilan|saya|asli|cust|customer|pelanggan)?\\s*(?:[:=]|\\s+)(?!(?:saya|aku|kamu|dia|mereka|kita|anda|adalah|itu|ini|yang|mau|ingin|tolong)\\b)([\\w\\s\\.\\\']{3,30})(?:\\n|$|\\.|,)/iu';
            if (preg_match($namePattern, $msg, $m)) {
                $temp = trim((string) ($m[1] ?? ''));
                $black = ['mau','ingin','tolong','admin','kak','halo','saya','ada','butuh','perlu','mohon','bisa','sudah'];
                $first = strtolower((string) (explode(' ', $temp)[0] ?? ''));
                if (!in_array($first, $black, true) && strlen($temp) > 2) $detectedName = $temp;
            }

            $addrPattern = '/(?:al?a?mat|lokasi|posisi|domisili|hunian|t?empat\\s*tinggal|rumah|rmh|kost|kediaman|ber?a?lamat(?:\\s*di)?|tinggal\\s*di)\\s*[:=]?\\s*([\\w\\s,.\\-\\/0-9]+)(?:\\n|$)/iu';
            if (preg_match($addrPattern, $msg, $m)) {
                $detectedAddr = trim((string) ($m[1] ?? ''));
            } elseif (preg_match('/(?:^|\\s)(?:jln|jl\\.?|jalan|dusun|dsn?\\.?|kp\\.?|kampung|gang|gg\\.?|blok|perum|komplek|rt\\/rw|kel\\.?|kec\\.?|kab\\.?|kota)\\s+([\\w\\s,.\\-\\/0-9]+)/iu', $msg, $m)) {
                $detectedAddr = trim((string) ($m[0] ?? ''));
            }

            $updates = [];
            if ($detectedPhone && ($oldPhone === '' || $oldPhone === '-')) $updates['customer_phone'] = $detectedPhone;
            if ($detectedName) {
                if ($oldName === '' || stripos($oldName, 'Pelanggan') !== false || $oldName === 'Tanpa Nama') {
                    $updates['customer_name'] = $detectedName;
                }
            }
            if ($detectedAddr) {
                if ($oldAddr === '' || $oldAddr === '-') $updates['customer_address'] = $detectedAddr;
            }

            if (!empty($updates)) {
                DB::table('noci_customers')
                    ->where('visit_id', $visitId)
                    ->when($this->hasColumn('noci_customers', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                    ->update($updates);
            }
        } catch (\Throwable) {
        }
    }

    private function safeSendWa(int $tenantId, string $message): bool
    {
        try {
            $conf = DB::table('noci_conf_wa')
                ->where('tenant_id', $tenantId)
                ->first();

            if (!$conf || (int) ($conf->is_active ?? 0) !== 1) return false;

            $target = trim((string) ($conf->target_number ?? ''));
            if ($target === '') return false;

            // Preferred path: modern sender (HTTP + DB facade), no mysqli dependency.
            $activeProviders = [];
            if ($this->hasTable('noci_wa_tenant_gateways') && $this->hasColumn('noci_wa_tenant_gateways', 'provider_code')) {
                $qProv = DB::table('noci_wa_tenant_gateways')
                    ->where('tenant_id', $tenantId);
                if ($this->hasColumn('noci_wa_tenant_gateways', 'is_active')) {
                    $qProv->where('is_active', 1);
                }
                if ($this->hasColumn('noci_wa_tenant_gateways', 'priority')) {
                    $qProv->orderBy('priority');
                }
                $activeProviders = $qProv
                    ->pluck('provider_code')
                    ->map(fn ($v) => strtolower(trim((string) $v)))
                    ->filter(fn ($v) => $v !== '')
                    ->unique()
                    ->values()
                    ->all();
            }

            // Keep explicit order for compatibility: MPWA first, then Balesotomatis.
            foreach (['mpwa', 'balesotomatis'] as $provider) {
                if (!empty($activeProviders) && !in_array($provider, $activeProviders, true)) {
                    continue;
                }

                $resp = app(WaGatewaySender::class)->sendPersonal($tenantId, $target, $message, [
                    'log_platform' => 'WA Chat',
                    'force_provider' => $provider,
                    'force_failover' => true,
                ]);
                if (($resp['status'] ?? '') === 'sent') {
                    return true;
                }
            }

            // Legacy fallback for tenants still using old gateway helper.
            if (!function_exists('mysqli_connect')) return false;

            $root = dirname(base_path());
            $gatewayPath = $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'wa_gateway.php';
            if (!is_file($gatewayPath)) return false;
            require_once $gatewayPath;

            if (!function_exists('wa_gateway_send_personal')) return false;

            $host = (string) (config('database.connections.mysql.host') ?? '127.0.0.1');
            $port = (int) (config('database.connections.mysql.port') ?? 3306);
            $db = (string) (config('database.connections.mysql.database') ?? '');
            $user = (string) (config('database.connections.mysql.username') ?? '');
            $pass = (string) (config('database.connections.mysql.password') ?? '');

            // Use MySQLi persistent connection to reduce churn on shared hosting.
            $mysqliHost = (str_starts_with($host, 'p:') ? $host : ('p:' . $host));
            $conn = @mysqli_connect($mysqliHost, $user, $pass, $db, $port);
            if (!$conn) {
                // Fallback to non-persistent connection if persistent socket is unavailable.
                $conn = @mysqli_connect($host, $user, $pass, $db, $port);
            }
            if (!$conn) return false;
            @mysqli_set_charset($conn, 'utf8mb4');
            @mysqli_query($conn, "SET time_zone = '+07:00'");

            $resp = wa_gateway_send_personal($conn, $tenantId, $target, $message, ['log_platform' => 'WA Chat']);
            @mysqli_close($conn);

            return (($resp['status'] ?? '') === 'sent');
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private function detectIsp(string $ip): string
    {
        if ($ip === '127.0.0.1' || $ip === '::1') return 'Localhost';

        $whitelist = [
            '103.173.138.153' => 'SMC (Bangkunat)',
            '103.173.138.157' => 'SMC (Bangkunat)',
            '103.173.138.167' => 'SMC (Tanjung Setia)',
            '103.173.138.183' => 'SMC (Tanjung Setia)',
        ];
        if (isset($whitelist[$ip])) return $whitelist[$ip];

        $ctx = stream_context_create(['http' => ['timeout' => 2]]);
        $json = @file_get_contents("https://ip-api.com/json/{$ip}?fields=status,isp,city", false, $ctx);
        if ($json) {
            $data = json_decode($json, true);
            if (is_array($data) && ($data['status'] ?? '') === 'success') {
                $isp = (string) ($data['isp'] ?? 'Unknown');
                $city = (string) ($data['city'] ?? '-');
                return "{$isp} ({$city})";
            }
        }

        return 'Unknown IP';
    }

    private function hasColumn(string $table, string $col): bool
    {
        try {
            return Schema::hasColumn($table, $col);
        } catch (\Throwable) {
            return false;
        }
    }

    private function json(array $payload, Request $request, int $status = 200): JsonResponse
    {
        return response()->json(
            $payload,
            $status,
            $this->corsHeaders($request),
            JSON_UNESCAPED_UNICODE
        );
    }

    private function corsHeaders(Request $request): array
    {
        $origin = (string) ($request->headers->get('Origin') ?? '');

        $allowed = [
            'https://isolir.daratlaut.com',
            'https://my.daratlaut.com',
            'https://daratlaut.com',
            // Non-SSL variants (requested).
            'http://isolir.daratlaut.com',
            'http://my.daratlaut.com',
            'http://daratlaut.com',
        ];

        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
        ];

        if ($origin !== '' && in_array($origin, $allowed, true)) {
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Vary'] = 'Origin';
            $headers['Access-Control-Allow-Methods'] = 'GET, POST, OPTIONS';
            $headers['Access-Control-Allow-Headers'] = 'Content-Type, Authorization';
        }

        return $headers;
    }
}
