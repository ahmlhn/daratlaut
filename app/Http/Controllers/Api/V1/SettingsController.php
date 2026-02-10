<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Pop;
use App\Models\RecapGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class SettingsController extends Controller
{
    private function tenantId(Request $request): int
    {
        return (int) ($request->user()?->tenant_id ?? $request->input('tenant_id', 1));
    }

    /**
     * Get all settings at once (initial load)
     */
    public function index(Request $request): JsonResponse
    {
        $tid = $this->tenantId($request);

        $waConfig = DB::table('noci_conf_wa')->where('tenant_id', $tid)->first();
        $tgConfig = DB::table('noci_conf_tg')->where('tenant_id', $tid)->first();
        $templates = DB::table('noci_msg_templates')->where('tenant_id', $tid)->get();

        $gwPrimary = DB::table('noci_wa_tenant_gateways')
            ->where('tenant_id', $tid)->where('provider_code', 'balesotomatis')->first();
        $gwBackup = DB::table('noci_wa_tenant_gateways')
            ->where('tenant_id', $tid)->where('provider_code', 'mpwa')->first();

        if ($gwPrimary && $gwPrimary->extra_json) {
            $gwPrimary->extra = json_decode($gwPrimary->extra_json, true) ?: [];
        }
        if ($gwBackup && $gwBackup->extra_json) {
            $gwBackup->extra = json_decode($gwBackup->extra_json, true) ?: [];
        }

        $failoverMode = $gwPrimary->failover_mode ?? ($gwBackup->failover_mode ?? 'manual');
        $pops = Pop::forTenant($tid)->orderBy('pop_name')->get();
        $recapGroups = $this->getRecapGroupsData($tid);
        $feeSettings = $this->getFeeSettingsData($tid);
        $mapsConfig = $this->getMapsConfigData($tid);
        $publicUrl = $this->getPublicUrl($tid);
        $gatewayStatus = $this->getGatewayStatusData($tid);

        return response()->json([
            'wa_config' => $waConfig,
            'tg_config' => $tgConfig,
            'templates' => $templates,
            'wa_gateways' => ['primary' => $gwPrimary, 'backup' => $gwBackup],
            'wa_failover_mode' => $failoverMode,
            'pops' => $pops,
            'recap_groups' => $recapGroups,
            'fee_settings' => $feeSettings,
            'maps_config' => $mapsConfig,
            'public_url' => $publicUrl,
            'gateway_status' => $gatewayStatus,
        ]);
    }

    // ========== WhatsApp Primary Config ==========

    public function getWaConfig(Request $request): JsonResponse
    {
        $tid = $this->tenantId($request);
        $waConf = DB::table('noci_conf_wa')->where('tenant_id', $tid)->first();
        $gwPrimary = DB::table('noci_wa_tenant_gateways')
            ->where('tenant_id', $tid)->where('provider_code', 'balesotomatis')->first();

        return response()->json(['data' => [
            'base_url' => $waConf->base_url ?? '',
            'group_url' => $waConf->group_url ?? '',
            'token' => $waConf->token ?? '',
            'sender_number' => $waConf->sender_number ?? '',
            'target_number' => $waConf->target_number ?? '',
            'group_id' => $waConf->group_id ?? '',
            'recap_group_id' => $waConf->recap_group_id ?? '',
            'is_active' => (int) ($waConf->is_active ?? 0),
            'failover_mode' => $gwPrimary->failover_mode ?? 'manual',
        ]]);
    }

    public function saveWaConfig(Request $request): JsonResponse
    {
        $tid = $this->tenantId($request);
        $baseUrl = trim($request->input('base_url', ''));
        $groupUrl = trim($request->input('group_url', ''));
        $token = trim($request->input('token', ''));
        $sender = trim($request->input('sender_number', ''));
        $target = trim($request->input('target_number', ''));
        $groupId = trim($request->input('group_id', ''));
        $recapGroupId = trim($request->input('recap_group_id', ''));
        $active = (int) $request->input('is_active', 0);
        $failoverMode = strtolower(trim($request->input('failover_mode', 'manual')));
        if ($failoverMode !== 'auto') $failoverMode = 'manual';

        $waData = [
            'base_url' => $baseUrl, 'group_url' => $groupUrl, 'token' => $token,
            'sender_number' => $sender, 'target_number' => $target, 'group_id' => $groupId,
            'recap_group_id' => $recapGroupId, 'is_active' => $active,
        ];

        if (DB::table('noci_conf_wa')->where('tenant_id', $tid)->exists()) {
            DB::table('noci_conf_wa')->where('tenant_id', $tid)->update($waData);
        } else {
            DB::table('noci_conf_wa')->insert(array_merge($waData, ['tenant_id' => $tid]));
        }

        // Sync gateway
        $gwData = [
            'label' => 'Balesotomatis', 'base_url' => $baseUrl, 'group_url' => $groupUrl,
            'token' => $token, 'sender_number' => $sender, 'group_id' => $groupId,
            'is_active' => $active, 'priority' => 1, 'failover_mode' => $failoverMode,
        ];
        $gwExists = DB::table('noci_wa_tenant_gateways')
            ->where('tenant_id', $tid)->where('provider_code', 'balesotomatis')->exists();
        if ($gwExists) {
            DB::table('noci_wa_tenant_gateways')
                ->where('tenant_id', $tid)->where('provider_code', 'balesotomatis')->update($gwData);
        } else {
            DB::table('noci_wa_tenant_gateways')->insert(array_merge($gwData, [
                'tenant_id' => $tid, 'provider_code' => 'balesotomatis',
            ]));
        }

        return response()->json(['status' => 'success', 'message' => 'WhatsApp config saved']);
    }

    // ========== WhatsApp Backup (MPWA) ==========

    public function getBackupConfig(Request $request): JsonResponse
    {
        $tid = $this->tenantId($request);
        $gw = DB::table('noci_wa_tenant_gateways')
            ->where('tenant_id', $tid)->where('provider_code', 'mpwa')->first();
        $extra = ($gw && $gw->extra_json) ? (json_decode($gw->extra_json, true) ?: []) : [];

        return response()->json(['data' => [
            'base_url' => $gw->base_url ?? '',
            'token' => $gw->token ?? '',
            'sender_number' => $gw->sender_number ?? '',
            'target_number' => $extra['target_number'] ?? '',
            'group_id' => $gw->group_id ?? '',
            'footer' => $extra['footer'] ?? '',
            'is_active' => (int) ($gw->is_active ?? 0),
        ]]);
    }

    public function saveBackupConfig(Request $request): JsonResponse
    {
        $tid = $this->tenantId($request);
        $baseUrl = trim($request->input('base_url', ''));
        $token = trim($request->input('token', ''));
        $sender = trim($request->input('sender_number', ''));
        $target = trim($request->input('target_number', ''));
        $groupId = trim($request->input('group_id', ''));
        $footer = trim($request->input('footer', ''));
        $active = (int) $request->input('is_active', 0);
        $failoverMode = strtolower(trim($request->input('failover_mode', 'manual')));
        if ($failoverMode !== 'auto') $failoverMode = 'manual';

        $extra = json_encode(['footer' => $footer, 'target_number' => $target]);
        $gwData = [
            'label' => 'MPWA', 'base_url' => $baseUrl, 'group_url' => $baseUrl,
            'token' => $token, 'sender_number' => $sender, 'group_id' => $groupId,
            'is_active' => $active, 'priority' => 2, 'failover_mode' => $failoverMode,
            'extra_json' => $extra,
        ];

        $gwExists = DB::table('noci_wa_tenant_gateways')
            ->where('tenant_id', $tid)->where('provider_code', 'mpwa')->exists();
        if ($gwExists) {
            DB::table('noci_wa_tenant_gateways')
                ->where('tenant_id', $tid)->where('provider_code', 'mpwa')->update($gwData);
        } else {
            DB::table('noci_wa_tenant_gateways')->insert(array_merge($gwData, [
                'tenant_id' => $tid, 'provider_code' => 'mpwa',
            ]));
        }

        return response()->json(['status' => 'success', 'message' => 'MPWA config saved']);
    }

    // ========== Telegram Config ==========

    public function getTgConfig(Request $request): JsonResponse
    {
        $tid = $this->tenantId($request);
        $config = DB::table('noci_conf_tg')->where('tenant_id', $tid)->first();
        return response()->json(['data' => [
            'bot_token' => $config->bot_token ?? '',
            'chat_id' => $config->chat_id ?? '',
        ]]);
    }

    public function saveTgConfig(Request $request): JsonResponse
    {
        $tid = $this->tenantId($request);
        $botToken = trim($request->input('bot_token', ''));
        $chatId = trim($request->input('chat_id', ''));

        $data = ['bot_token' => $botToken, 'chat_id' => $chatId];
        if (DB::table('noci_conf_tg')->where('tenant_id', $tid)->exists()) {
            DB::table('noci_conf_tg')->where('tenant_id', $tid)->update($data);
        } else {
            DB::table('noci_conf_tg')->insert(array_merge($data, ['tenant_id' => $tid]));
        }

        return response()->json(['status' => 'success', 'message' => 'Telegram config saved']);
    }

    // ========== Message Templates ==========

    public function getTemplates(Request $request): JsonResponse
    {
        $tid = $this->tenantId($request);
        $templates = DB::table('noci_msg_templates')->where('tenant_id', $tid)->orderBy('code')->get();
        return response()->json(['data' => $templates]);
    }

    public function saveTemplate(Request $request): JsonResponse
    {
        $tid = $this->tenantId($request);
        $code = trim($request->input('code', ''));
        $message = trim($request->input('message', ''));
        if (empty($code)) return response()->json(['status' => 'error', 'message' => 'Code required'], 422);

        if (DB::table('noci_msg_templates')->where('tenant_id', $tid)->where('code', $code)->exists()) {
            DB::table('noci_msg_templates')->where('tenant_id', $tid)->where('code', $code)->update(['message' => $message]);
        } else {
            DB::table('noci_msg_templates')->insert(['tenant_id' => $tid, 'code' => $code, 'message' => $message]);
        }
        return response()->json(['status' => 'success', 'message' => 'Template saved']);
    }

    public function deleteTemplate(Request $request, int $id): JsonResponse
    {
        $tid = $this->tenantId($request);
        DB::table('noci_msg_templates')->where('id', $id)->where('tenant_id', $tid)->delete();
        return response()->json(['status' => 'success']);
    }

    // ========== POP Management ==========

    public function getPops(Request $request): JsonResponse
    {
        $tid = $this->tenantId($request);
        return response()->json(['data' => Pop::forTenant($tid)->orderBy('pop_name')->get()]);
    }

    public function savePop(Request $request): JsonResponse
    {
        $tid = $this->tenantId($request);
        $id = (int) $request->input('id', 0);
        $popName = trim($request->input('pop_name', ''));
        $waNumber = preg_replace('/[^0-9]/', '', trim($request->input('wa_number', '')));
        $groupId = trim($request->input('group_id', ''));

        if (empty($popName)) return response()->json(['status' => 'error', 'message' => 'Nama POP wajib'], 422);
        if (substr($waNumber, 0, 1) === '0') $waNumber = '62' . substr($waNumber, 1);

        $dup = Pop::forTenant($tid)->where('pop_name', $popName);
        if ($id > 0) $dup->where('id', '!=', $id);
        if ($dup->exists()) return response()->json(['status' => 'error', 'message' => 'Nama POP sudah ada'], 422);

        if ($id > 0) {
            Pop::forTenant($tid)->where('id', $id)->update(['pop_name' => $popName, 'wa_number' => $waNumber, 'group_id' => $groupId]);
        } else {
            Pop::create(['tenant_id' => $tid, 'pop_name' => $popName, 'wa_number' => $waNumber, 'group_id' => $groupId]);
        }
        return response()->json(['status' => 'success']);
    }

    public function deletePop(Request $request, int $id): JsonResponse
    {
        Pop::forTenant($this->tenantId($request))->where('id', $id)->delete();
        return response()->json(['status' => 'success']);
    }

    // ========== Recap Group Management ==========

    private function getRecapGroupsData(int $tid): array
    {
        try {
            $t = DB::select("SHOW TABLES LIKE 'noci_recap_groups'");
            if (empty($t)) return [];
            return RecapGroup::forTenant($tid)->orderBy('name')->get()->toArray();
        } catch (\Exception $e) { return []; }
    }

    public function getRecapGroups(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->getRecapGroupsData($this->tenantId($request))]);
    }

    public function saveRecapGroup(Request $request): JsonResponse
    {
        $tid = $this->tenantId($request);
        $id = (int) $request->input('id', 0);
        $name = trim($request->input('name', ''));
        $groupId = trim($request->input('group_id', ''));
        if (empty($name) || empty($groupId)) return response()->json(['status' => 'error', 'message' => 'Nama dan Group ID wajib'], 422);

        $dup = RecapGroup::forTenant($tid)->where('name', $name);
        if ($id > 0) $dup->where('id', '!=', $id);
        if ($dup->exists()) return response()->json(['status' => 'error', 'message' => 'Nama sudah ada'], 422);

        if ($id > 0) {
            RecapGroup::forTenant($tid)->where('id', $id)->update(['name' => $name, 'group_id' => $groupId]);
        } else {
            RecapGroup::create(['tenant_id' => $tid, 'name' => $name, 'group_id' => $groupId]);
        }
        return response()->json(['status' => 'success']);
    }

    public function deleteRecapGroup(Request $request, int $id): JsonResponse
    {
        RecapGroup::forTenant($this->tenantId($request))->where('id', $id)->delete();
        return response()->json(['status' => 'success']);
    }

    // ========== Fee Settings ==========

    private function getFeeSettingsData(int $tid): array
    {
        try {
            $row = DB::table('noci_fin_settings')->where('tenant_id', $tid)->first();
            if (!$row) return ['teknisi_fee_install' => 0, 'sales_fee_install' => 0, 'expense_categories' => []];
            $cats = !empty($row->expense_categories) ? (json_decode($row->expense_categories, true) ?: []) : [];
            return [
                'teknisi_fee_install' => (float) ($row->teknisi_fee_install ?? 0),
                'sales_fee_install' => (float) ($row->sales_fee_install ?? 0),
                'expense_categories' => $cats,
            ];
        } catch (\Exception $e) {
            return ['teknisi_fee_install' => 0, 'sales_fee_install' => 0, 'expense_categories' => []];
        }
    }

    public function getFeeSettings(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->getFeeSettingsData($this->tenantId($request))]);
    }

    public function saveFeeSettings(Request $request): JsonResponse
    {
        $tid = $this->tenantId($request);
        try {
            $cats = $request->input('expense_categories', []);
            DB::table('noci_fin_settings')->where('tenant_id', $tid)->update([
                'teknisi_fee_install' => (float) $request->input('teknisi_fee_install', 0),
                'sales_fee_install' => (float) $request->input('sales_fee_install', 0),
                'expense_categories' => is_string($cats) ? $cats : json_encode($cats),
            ]);
            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // ========== Maps Settings (Google Maps API Key) ==========

    private function getMapsConfigData(int $tid): array
    {
        try {
            if (!Schema::hasTable('noci_conf_maps')) {
                return ['google_maps_api_key' => ''];
            }

            $row = DB::table('noci_conf_maps')->where('tenant_id', $tid)->first();
            return [
                'google_maps_api_key' => $row->google_maps_api_key ?? '',
            ];
        } catch (\Throwable) {
            return ['google_maps_api_key' => ''];
        }
    }

    public function getMapsConfig(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->getMapsConfigData($this->tenantId($request))]);
    }

    public function saveMapsConfig(Request $request): JsonResponse
    {
        $tid = $this->tenantId($request);

        if (!Schema::hasTable('noci_conf_maps')) {
            return response()->json(['status' => 'error', 'message' => 'Table noci_conf_maps not found. Run migration.'], 500);
        }

        $apiKey = trim((string) $request->input('google_maps_api_key', ''));
        if (strlen($apiKey) > 255) {
            return response()->json(['status' => 'error', 'message' => 'API key terlalu panjang'], 422);
        }

        $now = now();
        $data = [
            'google_maps_api_key' => $apiKey !== '' ? $apiKey : null,
            'updated_at' => $now,
        ];

        if (DB::table('noci_conf_maps')->where('tenant_id', $tid)->exists()) {
            DB::table('noci_conf_maps')->where('tenant_id', $tid)->update($data);
        } else {
            DB::table('noci_conf_maps')->insert(array_merge($data, [
                'tenant_id' => $tid,
                'created_at' => $now,
            ]));
        }

        return response()->json(['status' => 'success']);
    }

    // ========== Gateway Status ==========

    private function getGatewayStatusData(int $tid): array
    {
        $waLast = DB::table('noci_notif_logs')->where('tenant_id', $tid)
            ->where(fn ($q) => $q->where('platform', 'like', '%WA%')->orWhere('platform', 'like', '%whatsapp%'))
            ->orderByDesc('timestamp')->first();
        $tgLast = DB::table('noci_notif_logs')->where('tenant_id', $tid)
            ->where(fn ($q) => $q->where('platform', 'like', '%TG%')->orWhere('platform', 'like', '%telegram%'))
            ->orderByDesc('timestamp')->first();
        return [
            'wa' => ['last_status' => $waLast->status ?? null, 'last_time' => $waLast->timestamp ?? null],
            'tg' => ['last_status' => $tgLast->status ?? null, 'last_time' => $tgLast->timestamp ?? null],
        ];
    }

    public function getGatewayStatus(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->getGatewayStatusData($this->tenantId($request))]);
    }

    // ========== Public URL ==========

    private function getPublicUrl(int $tid): string
    {
        try {
            $tenant = DB::table('tenants')->where('id', $tid)->first();
            if ($tenant && !empty($tenant->public_token)) {
                return rtrim(config('app.url', ''), '/') . '/direct?t=' . $tenant->public_token;
            }
        } catch (\Exception $e) {}
        return '';
    }

    public function getPublicUrlEndpoint(Request $request): JsonResponse
    {
        return response()->json(['data' => ['url' => $this->getPublicUrl($this->tenantId($request))]]);
    }

    // ========== Test Endpoints ==========

    public function testWa(Request $request): JsonResponse
    {
        $url = trim($request->input('url', ''));
        $token = trim($request->input('token', ''));
        $sender = trim($request->input('sender', ''));
        $target = trim($request->input('target', ''));
        $isGroup = (bool) $request->input('is_group', false);
        $msg = $isGroup ? 'Tes Group WA OK!' : 'Tes Personal WA OK!';

        if (empty($url) || empty($token)) return response()->json(['status' => 'error', 'message' => 'URL dan Token wajib'], 422);

        $payload = ['api_key' => $token, 'number_id' => $sender, 'message' => $msg];
        if ($isGroup) {
            $payload['group_id'] = $target;
        } else {
            $payload['method_send'] = 'async';
            $payload['enable_typing'] = '1';
            $phone = preg_replace('/[^0-9]/', '', $target);
            if (substr($phone, 0, 2) === '62') $phone = substr($phone, 2);
            elseif (substr($phone, 0, 1) === '0') $phone = substr($phone, 1);
            $payload['phone_no'] = $phone;
            $payload['country_code'] = '62';
        }

        try {
            $resp = Http::timeout(15)->post($url, $payload);
            $ok = $resp->successful();
            $json = json_decode($resp->body(), true);
            if ($json && isset($json['code']) && (int) $json['code'] !== 200) $ok = false;

            $this->logNotif($this->tenantId($request), $isGroup ? 'WA Group' : 'WA Personal', $target, $msg, $ok ? 'success' : 'failed', $resp->body());
            return response()->json(['status' => $ok ? 'success' : 'failed', 'message' => $resp->body()]);
        } catch (\Exception $e) {
            $this->logNotif($this->tenantId($request), 'WA', $target, $msg, 'failed', $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function testMpwa(Request $request): JsonResponse
    {
        $url = trim($request->input('url', ''));
        $token = trim($request->input('token', ''));
        $sender = trim($request->input('sender', ''));
        $target = trim($request->input('target', ''));
        $footer = trim($request->input('footer', ''));
        $isGroup = (bool) $request->input('is_group', false);
        $msg = $isGroup ? 'Tes Group WA Backup OK!' : 'Tes Personal WA Backup OK!';

        if (empty($url) || empty($token)) return response()->json(['status' => 'error', 'message' => 'URL dan Token wajib'], 422);

        $payload = ['api_key' => $token, 'sender' => $sender, 'number' => $target, 'message' => $msg];
        if ($footer) $payload['footer'] = $footer;
        if ($isGroup) $payload['is_group'] = true;

        try {
            $resp = Http::timeout(15)->post($url, $payload);
            $this->logNotif($this->tenantId($request), $isGroup ? 'WA Backup Group' : 'WA Backup', $target, $msg, $resp->successful() ? 'success' : 'failed', $resp->body());
            return response()->json(['status' => $resp->successful() ? 'success' : 'failed', 'message' => $resp->body()]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function testTg(Request $request): JsonResponse
    {
        $botToken = trim($request->input('bot_token', ''));
        $chatId = trim($request->input('chat_id', ''));
        if (empty($botToken) || empty($chatId)) return response()->json(['status' => 'error', 'message' => 'Bot Token dan Chat ID wajib'], 422);

        try {
            $resp = Http::timeout(10)->get("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $chatId, 'text' => 'Test Telegram OK!',
            ]);
            $this->logNotif($this->tenantId($request), 'TG', $chatId, 'Test TG', $resp->successful() ? 'success' : 'failed', $resp->body());
            return response()->json(['status' => $resp->successful() ? 'success' : 'failed', 'message' => $resp->body()]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // ========== Notification Logs ==========

    public function getNotifLogs(Request $request): JsonResponse
    {
        $tid = $this->tenantId($request);
        $search = $request->input('search', '');
        $status = strtolower(trim($request->input('status', '')));
        $range = strtolower(trim($request->input('range', '')));

        $query = DB::table('noci_notif_logs')->where('tenant_id', $tid);
        if ($search) $query->where(fn ($q) => $q->where('target', 'like', "%{$search}%")->orWhere('message', 'like', "%{$search}%"));
        if ($status === 'success') $query->whereIn('status', ['success', 'sent', 'ok']);
        elseif ($status === 'failed') $query->whereIn('status', ['failed', 'error', 'fail']);
        if ($range === '24h') $query->where('timestamp', '>=', now()->subDay());
        elseif ($range === '7d') $query->where('timestamp', '>=', now()->subDays(7));
        elseif ($range === '30d') $query->where('timestamp', '>=', now()->subDays(30));

        $logs = $query->orderByDesc('timestamp')->limit(50)->get()->map(function ($log) {
            $s = strtolower(trim($log->status ?? ''));
            $log->normalized_status = in_array($s, ['success', 'sent', 'ok']) ? 'success' : (in_array($s, ['failed', 'error', 'fail']) ? 'failed' : $s);
            return $log;
        });

        $stats = DB::table('noci_notif_logs')->where('tenant_id', $tid)
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status IN ('success','sent','ok') THEN 1 ELSE 0 END) as sent, SUM(CASE WHEN status IN ('failed','error','fail') THEN 1 ELSE 0 END) as failed")
            ->first();

        return response()->json([
            'data' => $logs,
            'stats' => ['total' => (int) ($stats->total ?? 0), 'sent' => (int) ($stats->sent ?? 0), 'failed' => (int) ($stats->failed ?? 0)],
        ]);
    }

    public function getNotifStats(Request $request): JsonResponse
    {
        $tid = $this->tenantId($request);
        $stats = DB::table('noci_notif_logs')->where('tenant_id', $tid)
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status IN ('success','sent','ok') THEN 1 ELSE 0 END) as sent, SUM(CASE WHEN status IN ('failed','error','fail') THEN 1 ELSE 0 END) as failed")
            ->first();
        return response()->json(['total' => (int) ($stats->total ?? 0), 'sent' => (int) ($stats->sent ?? 0), 'failed' => (int) ($stats->failed ?? 0)]);
    }

    // ========== Install Variables ==========

    public function getInstallVariables(Request $request): JsonResponse
    {
        try {
            $cols = DB::select('DESCRIBE noci_installations');
            return response()->json(['data' => array_map(fn ($c) => $c->Field, $cols)]);
        } catch (\Exception $e) { return response()->json(['data' => []]); }
    }

    // ========== Legacy gateway endpoints ==========

    public function getGateways(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => DB::table('noci_wa_gateways')->where('is_active', 1)->get()]);
        } catch (\Exception $e) { return response()->json(['data' => []]); }
    }

    public function getTenantGateways(Request $request): JsonResponse
    {
        $tid = $this->tenantId($request);
        $gws = DB::table('noci_wa_tenant_gateways')->where('tenant_id', $tid)->orderBy('priority')->get()
            ->map(fn ($gw) => tap($gw, fn ($g) => $g->extra = $g->extra_json ? (json_decode($g->extra_json, true) ?: []) : []));
        return response()->json(['data' => $gws]);
    }

    // ========== Helpers ==========

    private function logNotif(int $tid, string $platform, string $target, string $msg, string $status, string $response): void
    {
        try {
            DB::table('noci_notif_logs')->insert([
                'tenant_id' => $tid, 'platform' => $platform, 'target' => $target,
                'message' => substr($msg, 0, 100), 'status' => $status,
                'response_log' => substr($response, 0, 255), 'timestamp' => now(),
            ]);
        } catch (\Exception $e) {}
    }
}
