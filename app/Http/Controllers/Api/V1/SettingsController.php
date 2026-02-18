<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Pop;
use App\Models\RecapGroup;
use App\Support\PublicRedirectLink;
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
        $cronSettings = $this->getCronSettingsData($tid);
        $publicUrl = $this->getPublicUrl($tid, $request);
        $gatewayStatus = $this->getGatewayStatusData($tid);
        $redirectLinks = $this->getRedirectLinksData($tid, $request);

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
            'cron_settings' => $cronSettings,
            'cron_logs' => $this->getCronLogsData($tid),
            'cron_log_stats' => $this->getCronLogStatsData($tid, '7d'),
            'public_url' => $publicUrl,
            'gateway_status' => $gatewayStatus,
            'redirect_links' => $redirectLinks,
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
            'is_active' => $active, 'priority' => 1, 'failover_mode' => $failoverMode,
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

        // Kebijakan single gateway: aktifkan MPWA sebagai satu-satunya provider WA tenant.
        if (Schema::hasTable('noci_wa_tenant_gateways')) {
            $disablePayload = [];
            if (Schema::hasColumn('noci_wa_tenant_gateways', 'is_active')) {
                $disablePayload['is_active'] = 0;
            }
            if (Schema::hasColumn('noci_wa_tenant_gateways', 'updated_at')) {
                $disablePayload['updated_at'] = now();
            }
            if (!empty($disablePayload) && Schema::hasColumn('noci_wa_tenant_gateways', 'provider_code')) {
                DB::table('noci_wa_tenant_gateways')
                    ->where('tenant_id', $tid)
                    ->where('provider_code', '!=', 'mpwa')
                    ->update($disablePayload);
            }

            if (Schema::hasColumn('noci_wa_tenant_gateways', 'priority') && Schema::hasColumn('noci_wa_tenant_gateways', 'provider_code')) {
                DB::table('noci_wa_tenant_gateways')
                    ->where('tenant_id', $tid)
                    ->where('provider_code', 'mpwa')
                    ->update(['priority' => 1]);
            }
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

    // ========== Cron Scheduler Settings ==========

    private function getCronSettingsData(int $tid): array
    {
        $defaults = [
            'nightly_enabled' => false,
            'nightly_time' => '21:30',
            'reminders_enabled' => false,
            'reminders_time' => '07:00',
            'reminder_base_url' => '',
            'olt_enabled' => false,
            'olt_time' => '02:15',
        ];

        if (!Schema::hasTable('noci_cron_settings')) {
            $basePath = base_path();
            $artisanPath = base_path('artisan');
            $quotedBase = '"' . str_replace('"', '\"', $basePath) . '"';

            return array_merge($defaults, [
                'project_path' => $basePath,
                'artisan_path' => $artisanPath,
                'cron_line_linux' => '* * * * * cd ' . $quotedBase . ' && php artisan schedule:run >> /dev/null 2>&1',
                // cPanel wrappers can mis-parse absolute artisan path; use project dir + artisan command.
                'cron_line_cpanel' => '* * * * * cd ' . $quotedBase . ' && php artisan schedule:run >/dev/null 2>&1',
                'windows_task_command' => 'php "' . $artisanPath . '" schedule:run',
            ]);
        }

        $row = DB::table('noci_cron_settings')->where('tenant_id', $tid)->first();
        $hasOltEnabled = Schema::hasColumn('noci_cron_settings', 'olt_enabled');
        $hasOltTime = Schema::hasColumn('noci_cron_settings', 'olt_time');

        $basePath = base_path();
        $artisanPath = base_path('artisan');
        $quotedBase = '"' . str_replace('"', '\"', $basePath) . '"';

        return [
            'nightly_enabled' => (bool) ($row->nightly_enabled ?? $defaults['nightly_enabled']),
            'nightly_time' => $this->normalizeClockValue((string) ($row->nightly_time ?? ''), $defaults['nightly_time']),
            'reminders_enabled' => (bool) ($row->reminders_enabled ?? $defaults['reminders_enabled']),
            'reminders_time' => $this->normalizeClockValue((string) ($row->reminders_time ?? ''), $defaults['reminders_time']),
            'reminder_base_url' => trim((string) ($row->reminder_base_url ?? $defaults['reminder_base_url'])),
            'olt_enabled' => $hasOltEnabled ? (bool) ($row->olt_enabled ?? $defaults['olt_enabled']) : $defaults['olt_enabled'],
            'olt_time' => $hasOltTime
                ? $this->normalizeClockValue((string) ($row->olt_time ?? ''), $defaults['olt_time'])
                : $defaults['olt_time'],
            'project_path' => $basePath,
            'artisan_path' => $artisanPath,
            'cron_line_linux' => '* * * * * cd ' . $quotedBase . ' && php artisan schedule:run >> /dev/null 2>&1',
            'cron_line_cpanel' => '* * * * * cd ' . $quotedBase . ' && php artisan schedule:run >/dev/null 2>&1',
            'windows_task_command' => 'php "' . $artisanPath . '" schedule:run',
        ];
    }

    public function getCronSettings(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->getCronSettingsData($this->tenantId($request))]);
    }

    public function saveCronSettings(Request $request): JsonResponse
    {
        $tid = $this->tenantId($request);

        if (!Schema::hasTable('noci_cron_settings')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Tabel noci_cron_settings tidak ditemukan. Jalankan migration terbaru terlebih dahulu.',
            ], 500);
        }

        $validated = $request->validate([
            'nightly_enabled' => ['nullable', 'boolean'],
            'nightly_time' => ['nullable', 'string', 'max:5'],
            'reminders_enabled' => ['nullable', 'boolean'],
            'reminders_time' => ['nullable', 'string', 'max:5'],
            'reminder_base_url' => ['nullable', 'string', 'max:255'],
            'olt_enabled' => ['nullable', 'boolean'],
            'olt_time' => ['nullable', 'string', 'max:5'],
        ]);

        $nightlyEnabled = (int) ((bool) ($validated['nightly_enabled'] ?? false));
        $remindersEnabled = (int) ((bool) ($validated['reminders_enabled'] ?? false));
        $oltEnabled = (int) ((bool) ($validated['olt_enabled'] ?? false));

        $nightlyTime = $this->normalizeClockValue((string) ($validated['nightly_time'] ?? ''), '21:30');
        $remindersTime = $this->normalizeClockValue((string) ($validated['reminders_time'] ?? ''), '07:00');
        $oltTime = $this->normalizeClockValue((string) ($validated['olt_time'] ?? ''), '02:15');

        $baseUrl = trim((string) ($validated['reminder_base_url'] ?? ''));
        if ($baseUrl !== '') {
            $baseUrl = rtrim($baseUrl, '/');
            if (!preg_match('#^https?://#i', $baseUrl)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Reminder Base URL harus diawali http:// atau https://',
                ], 422);
            }
        }

        $hasOltEnabled = Schema::hasColumn('noci_cron_settings', 'olt_enabled');
        $hasOltTime = Schema::hasColumn('noci_cron_settings', 'olt_time');

        $payload = [
            'nightly_enabled' => $nightlyEnabled,
            'nightly_time' => $nightlyTime,
            'reminders_enabled' => $remindersEnabled,
            'reminders_time' => $remindersTime,
            'reminder_base_url' => $baseUrl,
            'updated_at' => now(),
        ];
        if ($hasOltEnabled) {
            $payload['olt_enabled'] = $oltEnabled;
        }
        if ($hasOltTime) {
            $payload['olt_time'] = $oltTime;
        }

        $exists = DB::table('noci_cron_settings')->where('tenant_id', $tid)->exists();
        if ($exists) {
            DB::table('noci_cron_settings')->where('tenant_id', $tid)->update($payload);
        } else {
            DB::table('noci_cron_settings')->insert(array_merge($payload, [
                'tenant_id' => $tid,
                'created_at' => now(),
            ]));
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Pengaturan cron berhasil disimpan.',
            'data' => $this->getCronSettingsData($tid),
        ]);
    }

    private function applyCronLogRangeFilter($query, string $range): void
    {
        $range = strtolower(trim($range));
        if ($range === '') {
            return;
        }

        $column = Schema::hasColumn('noci_cron_logs', 'started_at') ? 'started_at' : 'created_at';
        if ($range === '24h') {
            $query->where($column, '>=', now()->subDay());
        } elseif ($range === '7d') {
            $query->where($column, '>=', now()->subDays(7));
        } elseif ($range === '30d') {
            $query->where($column, '>=', now()->subDays(30));
        }
    }

    private function getCronLogsData(int $tid, array $filters = []): array
    {
        if (!Schema::hasTable('noci_cron_logs')) {
            return [];
        }

        $job = strtolower(trim((string) ($filters['job'] ?? '')));
        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        $range = strtolower(trim((string) ($filters['range'] ?? '7d')));
        $limit = (int) ($filters['limit'] ?? 50);
        if ($limit < 1) $limit = 1;
        if ($limit > 200) $limit = 200;

        $validJobs = ['nightly_closing', 'ops_reminders', 'olt_daily_sync'];
        $validStatuses = ['success', 'partial', 'failed', 'skipped', 'dry_run'];

        $query = DB::table('noci_cron_logs')->where('tenant_id', $tid);
        if (in_array($job, $validJobs, true)) {
            $query->where('job_key', $job);
        }
        if (in_array($status, $validStatuses, true)) {
            $query->where('status', $status);
        }

        $this->applyCronLogRangeFilter($query, $range);

        return $query
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $meta = [];
                if (!empty($row->meta_json)) {
                    $decoded = json_decode((string) $row->meta_json, true);
                    if (is_array($decoded)) {
                        $meta = $decoded;
                    }
                }

                return [
                    'id' => (int) ($row->id ?? 0),
                    'tenant_id' => (int) ($row->tenant_id ?? 0),
                    'job_key' => (string) ($row->job_key ?? ''),
                    'command' => (string) ($row->command ?? ''),
                    'status' => strtolower(trim((string) ($row->status ?? ''))),
                    'message' => (string) ($row->message ?? ''),
                    'duration_ms' => isset($row->duration_ms) ? (int) $row->duration_ms : null,
                    'started_at' => $row->started_at ?? null,
                    'finished_at' => $row->finished_at ?? null,
                    'created_at' => $row->created_at ?? null,
                    'meta' => $meta,
                ];
            })
            ->values()
            ->all();
    }

    private function getCronLogStatsData(int $tid, string $range = '7d', string $job = ''): array
    {
        $stats = [
            'total' => 0,
            'success' => 0,
            'partial' => 0,
            'failed' => 0,
            'skipped' => 0,
            'dry_run' => 0,
        ];

        if (!Schema::hasTable('noci_cron_logs')) {
            return $stats;
        }

        $job = strtolower(trim($job));
        $validJobs = ['nightly_closing', 'ops_reminders', 'olt_daily_sync'];

        $query = DB::table('noci_cron_logs')->where('tenant_id', $tid);
        if (in_array($job, $validJobs, true)) {
            $query->where('job_key', $job);
        }

        $this->applyCronLogRangeFilter($query, $range);

        $rows = $query
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $key = strtolower(trim((string) ($row->status ?? '')));
            if (array_key_exists($key, $stats)) {
                $stats[$key] = (int) ($row->total ?? 0);
            }
        }

        $stats['total'] = $stats['success'] + $stats['partial'] + $stats['failed'] + $stats['skipped'] + $stats['dry_run'];
        return $stats;
    }

    public function getCronLogs(Request $request): JsonResponse
    {
        $tid = $this->tenantId($request);
        $job = strtolower(trim((string) $request->query('job', '')));
        $status = strtolower(trim((string) $request->query('status', '')));
        $range = strtolower(trim((string) $request->query('range', '7d')));
        $limit = (int) $request->query('limit', 50);

        return response()->json([
            'data' => $this->getCronLogsData($tid, [
                'job' => $job,
                'status' => $status,
                'range' => $range,
                'limit' => $limit,
            ]),
            'stats' => $this->getCronLogStatsData($tid, $range, $job),
        ]);
    }

    private function normalizeClockValue(string $value, string $fallback): string
    {
        $value = trim($value);
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value)) {
            return $value;
        }

        return $fallback;
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

    private function getPublicUrl(int $tid, ?Request $request = null): string
    {
        try {
            $tenant = DB::table('tenants')->where('id', $tid)->first();
            if ($tenant && !empty($tenant->public_token)) {
                return $this->resolvePublicBaseUrl($request) . '/direct?t=' . $tenant->public_token;
            }
        } catch (\Exception $e) {}
        return '';
    }

    public function getPublicUrlEndpoint(Request $request): JsonResponse
    {
        return response()->json(['data' => ['url' => $this->getPublicUrl($this->tenantId($request), $request)]]);
    }

    private function resolvePublicBaseUrl(?Request $request = null): string
    {
        $baseUrl = trim((string) config('direct.public_base_url', ''));
        if ($baseUrl === '' && $request !== null) {
            $baseUrl = trim((string) ($request->getSchemeAndHttpHost() ?? ''));
        }
        if ($baseUrl === '') {
            $baseUrl = trim((string) config('app.url', ''));
        }
        if ($baseUrl !== '' && !preg_match('#^https?://#i', $baseUrl)) {
            $baseUrl = 'https://' . ltrim($baseUrl, '/');
        }

        return rtrim($baseUrl, '/');
    }

    // ========== Public Redirect Links ==========

    private function getTenantPublicToken(int $tid): string
    {
        try {
            if (!Schema::hasTable('tenants')) {
                return '';
            }
            return (string) (DB::table('tenants')->where('id', $tid)->value('public_token') ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    private function buildRedirectShareUrl(?Request $request, string $publicToken, string $code): string
    {
        $code = PublicRedirectLink::normalizeCode($code);
        if ($code === '') {
            return '';
        }

        $base = $this->resolvePublicBaseUrl($request);
        if ($base === '') {
            return '';
        }

        // Pretty URL format requested by product: /link/{code}
        return $base . '/link/' . rawurlencode($code);
    }

    private function mapRedirectLinkRow(object $row, string $publicToken, ?Request $request = null): array
    {
        $code = PublicRedirectLink::normalizeCode((string) ($row->code ?? ''));
        return [
            'id' => (int) ($row->id ?? 0),
            'tenant_id' => (int) ($row->tenant_id ?? 0),
            'code' => $code,
            'type' => (string) ($row->type ?? PublicRedirectLink::TYPE_WHATSAPP),
            'wa_number' => (string) ($row->wa_number ?? ''),
            'wa_message' => (string) ($row->wa_message ?? ''),
            'target_url' => (string) ($row->target_url ?? ''),
            'is_active' => (int) ($row->is_active ?? 0),
            'click_count' => (int) ($row->click_count ?? 0),
            'redirect_success_count' => (int) ($row->redirect_success_count ?? 0),
            'last_clicked_at' => $row->last_clicked_at ?? null,
            'last_redirect_success_at' => $row->last_redirect_success_at ?? null,
            'expires_at' => $row->expires_at ?? null,
            'created_at' => $row->created_at ?? null,
            'updated_at' => $row->updated_at ?? null,
            'share_url' => $this->buildRedirectShareUrl($request, $publicToken, $code),
            'target_preview' => PublicRedirectLink::buildTargetUrl(
                (string) ($row->type ?? ''),
                (string) ($row->wa_number ?? ''),
                (string) ($row->wa_message ?? ''),
                (string) ($row->target_url ?? '')
            ),
        ];
    }

    private function getRedirectLinksData(int $tid, ?Request $request = null): array
    {
        if (!Schema::hasTable('noci_public_redirect_links')) {
            return [];
        }

        $publicToken = $this->getTenantPublicToken($tid);
        return DB::table('noci_public_redirect_links')
            ->where('tenant_id', $tid)
            ->orderByDesc('id')
            ->get()
            ->map(fn ($row) => $this->mapRedirectLinkRow($row, $publicToken, $request))
            ->values()
            ->all();
    }

    public function getRedirectLinks(Request $request): JsonResponse
    {
        $tid = $this->tenantId($request);
        return response()->json([
            'data' => $this->getRedirectLinksData($tid, $request),
            'public_token' => $this->getTenantPublicToken($tid),
            'share_url_format' => '/link/{code}',
        ]);
    }

    public function saveRedirectLink(Request $request): JsonResponse
    {
        if (!Schema::hasTable('noci_public_redirect_links')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Tabel noci_public_redirect_links tidak ditemukan. Jalankan migration terbaru.',
            ], 500);
        }

        $tid = $this->tenantId($request);
        $validated = $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', 'in:whatsapp,custom'],
            'wa_number' => ['nullable', 'string', 'max:50'],
            'wa_message' => ['nullable', 'string'],
            'target_url' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $id = (int) ($validated['id'] ?? 0);
        $code = PublicRedirectLink::normalizeCode((string) ($validated['code'] ?? ''));
        $type = strtolower(trim((string) ($validated['type'] ?? PublicRedirectLink::TYPE_WHATSAPP)));
        $existingLink = null;
        if ($id > 0) {
            $existingLink = DB::table('noci_public_redirect_links')
                ->where('tenant_id', $tid)
                ->where('id', $id)
                ->first();
            if (!$existingLink) {
                return response()->json(['status' => 'error', 'message' => 'Link tidak ditemukan.'], 404);
            }
        }

        $isActive = array_key_exists('is_active', $validated)
            ? (int) ((bool) $validated['is_active'])
            : (int) ($existingLink->is_active ?? 1);
        $expiresAt = array_key_exists('expires_at', $validated)
            ? $validated['expires_at']
            : ($existingLink->expires_at ?? null);

        if ($code === '') {
            return response()->json(['status' => 'error', 'message' => 'Kode link tidak valid.'], 422);
        }

        $waNumber = null;
        $waMessage = null;
        $targetUrl = null;

        if ($type === PublicRedirectLink::TYPE_WHATSAPP) {
            $waNumber = PublicRedirectLink::normalizePhone((string) ($validated['wa_number'] ?? ''));
            $waMessage = trim((string) ($validated['wa_message'] ?? ''));
            if ($waNumber === '') {
                return response()->json(['status' => 'error', 'message' => 'Nomor WhatsApp wajib diisi.'], 422);
            }
        } else {
            $targetUrl = PublicRedirectLink::normalizeCustomUrl((string) ($validated['target_url'] ?? ''));
            if ($targetUrl === '') {
                return response()->json(['status' => 'error', 'message' => 'Target URL tidak valid. Gunakan http:// atau https://'], 422);
            }
        }

        $dup = DB::table('noci_public_redirect_links as l')
            ->leftJoin('tenants as t', 't.id', '=', 'l.tenant_id')
            ->where('l.code', $code)
            ->when($id > 0, fn ($q) => $q->where('l.id', '!=', $id))
            ->select('l.id', 'l.tenant_id', 't.name as tenant_name')
            ->first();
        if ($dup) {
            $dupTenantId = (int) ($dup->tenant_id ?? 0);
            $dupTenantName = trim((string) ($dup->tenant_name ?? ''));
            if ($dupTenantId > 0 && $dupTenantId !== $tid) {
                $tenantLabel = $dupTenantName !== '' ? $dupTenantName : ('ID ' . $dupTenantId);
                return response()->json([
                    'status' => 'error',
                    'message' => "Kode link sudah dipakai tenant lain ({$tenantLabel}). Gunakan kode lain.",
                ], 422);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Kode link sudah digunakan. Gunakan kode lain.',
            ], 422);
        }

        $actorId = (int) ($request->user()?->id ?? 0);
        $payload = [
            'code' => $code,
            'type' => $type,
            'wa_number' => $waNumber,
            'wa_message' => $waMessage === '' ? null : $waMessage,
            'target_url' => $targetUrl,
            'is_active' => $isActive,
            'expires_at' => $expiresAt,
            'updated_at' => now(),
            'updated_by' => $actorId > 0 ? $actorId : null,
        ];

        if ($id > 0) {
            DB::table('noci_public_redirect_links')
                ->where('tenant_id', $tid)
                ->where('id', $id)
                ->update($payload);
            $linkId = $id;
        } else {
            $payload['tenant_id'] = $tid;
            $payload['click_count'] = 0;
            $payload['redirect_success_count'] = 0;
            $payload['created_at'] = now();
            $payload['created_by'] = $actorId > 0 ? $actorId : null;
            $linkId = (int) DB::table('noci_public_redirect_links')->insertGetId($payload);
        }

        $row = DB::table('noci_public_redirect_links')
            ->where('tenant_id', $tid)
            ->where('id', $linkId)
            ->first();

        $publicToken = $this->getTenantPublicToken($tid);
        return response()->json([
            'status' => 'success',
            'message' => $id > 0 ? 'Link redirect berhasil diperbarui.' : 'Link redirect berhasil dibuat.',
            'data' => $row ? $this->mapRedirectLinkRow($row, $publicToken, $request) : null,
        ]);
    }

    public function deleteRedirectLink(Request $request, int $id): JsonResponse
    {
        if (!Schema::hasTable('noci_public_redirect_links')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Tabel noci_public_redirect_links tidak ditemukan.',
            ], 500);
        }

        $tid = $this->tenantId($request);
        $deleted = DB::table('noci_public_redirect_links')
            ->where('tenant_id', $tid)
            ->where('id', $id)
            ->delete();

        if (!$deleted) {
            return response()->json(['status' => 'error', 'message' => 'Link tidak ditemukan.'], 404);
        }

        return response()->json(['status' => 'success']);
    }

    private function applyRedirectEventRangeFilter($query, string $range): void
    {
        if ($range === '24h') {
            $query->where('created_at', '>=', now()->subDay());
        } elseif ($range === '7d') {
            $query->where('created_at', '>=', now()->subDays(7));
        } elseif ($range === '30d') {
            $query->where('created_at', '>=', now()->subDays(30));
        }
    }

    public function getRedirectEvents(Request $request): JsonResponse
    {
        if (!Schema::hasTable('noci_public_redirect_events')) {
            return response()->json([
                'data' => [],
                'stats' => ['click' => 0, 'redirect_success' => 0, 'redirect_failed' => 0],
            ]);
        }

        $tid = $this->tenantId($request);
        $linkId = (int) $request->query('link_id', 0);
        $code = PublicRedirectLink::normalizeCode((string) $request->query('code', ''));
        $eventType = strtolower(trim((string) $request->query('event_type', '')));
        $range = strtolower(trim((string) $request->query('range', '')));
        $limit = (int) $request->query('limit', 100);
        if ($limit < 1) $limit = 1;
        if ($limit > 500) $limit = 500;

        $baseQuery = DB::table('noci_public_redirect_events')->where('tenant_id', $tid);
        if ($linkId > 0) {
            $baseQuery->where('redirect_link_id', $linkId);
        }
        if ($code !== '') {
            $baseQuery->where('code', $code);
        }
        $this->applyRedirectEventRangeFilter($baseQuery, $range);

        $logsQuery = clone $baseQuery;
        if (in_array($eventType, ['click', 'redirect_success', 'redirect_failed'], true)) {
            $logsQuery->where('event_type', $eventType);
        }

        $logs = $logsQuery->orderByDesc('id')->limit($limit)->get()->map(function ($row) {
            return [
                'id' => (int) ($row->id ?? 0),
                'tenant_id' => (int) ($row->tenant_id ?? 0),
                'redirect_link_id' => is_null($row->redirect_link_id ?? null) ? null : (int) $row->redirect_link_id,
                'code' => (string) ($row->code ?? ''),
                'event_type' => (string) ($row->event_type ?? ''),
                'target_url' => (string) ($row->target_url ?? ''),
                'http_status' => is_null($row->http_status ?? null) ? null : (int) $row->http_status,
                'error_message' => (string) ($row->error_message ?? ''),
                'ip_address' => (string) ($row->ip_address ?? ''),
                'user_agent' => (string) ($row->user_agent ?? ''),
                'referer' => (string) ($row->referer ?? ''),
                'created_at' => $row->created_at ?? null,
            ];
        });

        $statsRows = (clone $baseQuery)
            ->selectRaw('event_type, COUNT(*) as total')
            ->groupBy('event_type')
            ->get();
        $stats = ['click' => 0, 'redirect_success' => 0, 'redirect_failed' => 0];
        foreach ($statsRows as $row) {
            $k = (string) ($row->event_type ?? '');
            if (array_key_exists($k, $stats)) {
                $stats[$k] = (int) ($row->total ?? 0);
            }
        }

        return response()->json([
            'data' => $logs,
            'stats' => $stats,
        ]);
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
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('target', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%")
                    ->orWhere('platform', 'like', "%{$search}%")
                    ->orWhere('response_log', 'like', "%{$search}%");
            });
        }
        if ($status === 'success') $query->whereIn('status', ['success', 'sent', 'ok']);
        elseif ($status === 'failed') $query->whereIn('status', ['failed', 'error', 'fail']);
        elseif ($status === 'skipped') $query->whereIn('status', ['skipped', 'skip', 'ignored']);
        if ($range === '24h') $query->where('timestamp', '>=', now()->subDay());
        elseif ($range === '7d') $query->where('timestamp', '>=', now()->subDays(7));
        elseif ($range === '30d') $query->where('timestamp', '>=', now()->subDays(30));

        $logs = $query->orderByDesc('timestamp')->limit(50)->get()->map(function ($log) {
            $s = strtolower(trim($log->status ?? ''));
            $log->normalized_status = in_array($s, ['success', 'sent', 'ok'])
                ? 'success'
                : (in_array($s, ['failed', 'error', 'fail'])
                    ? 'failed'
                    : (in_array($s, ['skipped', 'skip', 'ignored']) ? 'skipped' : $s));
            return $log;
        });

        $stats = DB::table('noci_notif_logs')->where('tenant_id', $tid)
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status IN ('success','sent','ok') THEN 1 ELSE 0 END) as sent, SUM(CASE WHEN status IN ('failed','error','fail') THEN 1 ELSE 0 END) as failed, SUM(CASE WHEN status IN ('skipped','skip','ignored') THEN 1 ELSE 0 END) as skipped")
            ->first();

        return response()->json([
            'data' => $logs,
            'stats' => [
                'total' => (int) ($stats->total ?? 0),
                'sent' => (int) ($stats->sent ?? 0),
                'failed' => (int) ($stats->failed ?? 0),
                'skipped' => (int) ($stats->skipped ?? 0),
            ],
        ]);
    }

    public function getNotifStats(Request $request): JsonResponse
    {
        $tid = $this->tenantId($request);
        $stats = DB::table('noci_notif_logs')->where('tenant_id', $tid)
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status IN ('success','sent','ok') THEN 1 ELSE 0 END) as sent, SUM(CASE WHEN status IN ('failed','error','fail') THEN 1 ELSE 0 END) as failed, SUM(CASE WHEN status IN ('skipped','skip','ignored') THEN 1 ELSE 0 END) as skipped")
            ->first();
        return response()->json([
            'total' => (int) ($stats->total ?? 0),
            'sent' => (int) ($stats->sent ?? 0),
            'failed' => (int) ($stats->failed ?? 0),
            'skipped' => (int) ($stats->skipped ?? 0),
        ]);
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
