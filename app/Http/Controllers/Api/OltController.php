<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\OltLog;
use App\Services\OltService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class OltController extends Controller
{
    private function onuCacheSnColumn(): string
    {
        static $snCol = null;
        if ($snCol !== null) {
            return $snCol;
        }

        $table = (new OltOnu())->getTable();
        if (Schema::hasColumn($table, 'sn')) {
            $snCol = 'sn';
        } elseif (Schema::hasColumn($table, 'serial_number')) {
            $snCol = 'serial_number';
        } else {
            // Fall back; queries may still fail, but this avoids null handling everywhere.
            $snCol = 'sn';
        }

        return $snCol;
    }

    private function formatOnuCacheRow(OltOnu $onu): array
    {
        $fsp = (string) ($onu->fsp ?? '');
        $onuId = (int) ($onu->onu_id ?? 0);
        $sn = (string) ($onu->sn ?? $onu->getAttribute('serial_number') ?? '');
        $name = (string) ($onu->onu_name ?? $onu->getAttribute('name') ?? '');
        $onlineDuration = (string) ($onu->online_duration ?? '');
        $vlan = (int) ($onu->vlan ?? 0);

        $status = 'offline';
        $dbStatus = $onu->getAttribute('status');
        if (is_string($dbStatus) && $dbStatus !== '') {
            $status = $dbStatus;
        } else {
            $state = $onu->getAttribute('state');
            if (is_string($state) && $state !== '') {
                $state = strtolower($state);
                if (in_array($state, ['ready', 'working', 'online'], true)) {
                    $status = 'online';
                }
            }
        }

        $rx = null;
        if ($onu->getAttribute('rx') !== null && $onu->getAttribute('rx') !== '') {
            $rx = $onu->getAttribute('rx');
        } elseif ($onu->getAttribute('rx_power') !== null && $onu->getAttribute('rx_power') !== '') {
            $rx = (float) $onu->getAttribute('rx_power');
        }

        return [
            'fsp' => $fsp,
            'onu_id' => $onuId,
            'interface' => ($fsp !== '' && $onuId > 0) ? "gpon-onu_{$fsp}:{$onuId}" : '',
            'fsp_onu' => ($fsp !== '' && $onuId > 0) ? "{$fsp}:{$onuId}" : '',
            'sn' => $sn,
            'state' => '',
            'status' => $status,
            'rx' => $rx,
            'name' => $name,
            'online_duration' => $onlineDuration,
            'vlan' => $vlan,
        ];
    }

    private function actorName(Request $request): ?string
    {
        try {
            $u = $request->user();
            if (!$u) return null;
            return $u->name ?? $u->username ?? $u->email ?? null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Get all OLT devices with stats
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        
        $query = Olt::forTenant($tenantId)->orderBy('nama_olt');

        // Some legacy deployments may not have ONU cache table yet.
        if (Schema::hasTable('noci_olt_onu')) {
            $query->withCount('onus');
        }

        $olts = $query->get()
            ->map(function ($olt) {
                $fspCache = $olt->fsp_cache;
                return [
                    'id' => $olt->id,
                    'nama_olt' => $olt->nama_olt,
                    'host' => $olt->host,
                    'port' => $olt->port,
                    'vlan_default' => $olt->vlan_default,
                    'tcont_default' => $olt->tcont_default,
                    'onu_type_default' => $olt->onu_type_default,
                    'service_port_id_default' => $olt->service_port_id_default,
                    'is_active' => $olt->is_active ?? true,
                    'fsp_count' => is_array($fspCache) ? count($fspCache) : 0,
                    'onu_count' => (int) ($olt->onus_count ?? 0),
                    'last_sync' => $olt->fsp_cache_at?->format('Y-m-d H:i'),
                ];
            });
        
        return response()->json([
            'status' => 'ok',
            'data' => $olts,
        ]);
    }

    /**
     * Get OLT stats
     */
    public function stats(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        
        $totalOlts = Olt::forTenant($tenantId)->count();

        // Legacy schema compatibility: older `noci_olts` may not have `is_active`.
        $activeOlts = $totalOlts;
        if (Schema::hasColumn('noci_olts', 'is_active')) {
            $activeOlts = Olt::forTenant($tenantId)->where('is_active', true)->count();
        }

        // Legacy schema compatibility: OLT ONU cache schema differs between native and Laravel.
        $totalOnus = 0;
        $onlineOnus = 0;
        if (Schema::hasTable('noci_olt_onu')) {
            $totalOnus = OltOnu::where('tenant_id', $tenantId)->count();

            if (Schema::hasColumn('noci_olt_onu', 'status')) {
                $onlineOnus = OltOnu::where('tenant_id', $tenantId)->where('status', 'online')->count();
            } elseif (Schema::hasColumn('noci_olt_onu', 'state')) {
                $onlineOnus = OltOnu::where('tenant_id', $tenantId)
                    ->whereIn('state', ['ready', 'working', 'online'])
                    ->count();
            }
        }
        
        return response()->json([
            'status' => 'ok',
            'data' => [
                'total_olts' => $totalOlts,
                'active_olts' => $activeOlts,
                'total_onus' => $totalOnus,
                'online_onus' => $onlineOnus,
            ],
        ]);
    }

    /**
     * Get single OLT detail
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        
        $query = Olt::forTenant($tenantId);
        if (Schema::hasTable('noci_olt_onu')) {
            $query->withCount('onus');
        }

        $olt = $query->findOrFail($id);
        
        return response()->json([
            'status' => 'ok',
            'data' => [
                'id' => $olt->id,
                'nama_olt' => $olt->nama_olt,
                'host' => $olt->host,
                'port' => $olt->port,
                'username' => $olt->username,
                'tcont_default' => $olt->tcont_default,
                'vlan_default' => $olt->vlan_default,
                'onu_type_default' => $olt->onu_type_default,
                'service_port_id_default' => $olt->service_port_id_default,
                'is_active' => $olt->is_active ?? true,
                'fsp_cache' => $olt->fsp_cache ?? [],
                'fsp_cache_at' => $olt->fsp_cache_at?->format('Y-m-d H:i'),
                'onu_count' => (int) ($olt->onus_count ?? 0),
            ],
        ]);
    }

    /**
     * Create new OLT profile
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'nama_olt' => 'required|string|max:100',
            'host' => 'required|string|max:100|regex:/^[A-Za-z0-9.-]+$/',
            'port' => 'integer|min:1|max:65535',
            'username' => 'required|string|max:100',
            'password' => 'required|string|max:100',
            'tcont_default' => 'nullable|string|max:50',
            'vlan_default' => 'nullable|integer',
            'onu_type_default' => 'nullable|string|max:50',
            'service_port_id_default' => 'nullable|integer',
        ]);

        $tenantId = $request->user()->tenant_id ?? 1;

        $tcontDefault = preg_replace('/[^A-Za-z0-9_.-]/', '', trim((string) $request->input('tcont_default', '')));
        if ($tcontDefault === '') $tcontDefault = OltService::DEFAULT_TCONT_PROFILE;

        $vlanDefault = (int) ($request->input('vlan_default') ?? OltService::DEFAULT_VLAN);
        if ($vlanDefault < 1 || $vlanDefault > 4094) $vlanDefault = OltService::DEFAULT_VLAN;

        $onuTypeDefault = preg_replace('/[^A-Za-z0-9_.-]/', '', trim((string) $request->input('onu_type_default', '')));
        if ($onuTypeDefault === '') $onuTypeDefault = OltService::DEFAULT_ONU_TYPE;

        $spidDefault = (int) ($request->input('service_port_id_default') ?? OltService::DEFAULT_SERVICE_PORT_ID);
        if ($spidDefault < 1 || $spidDefault > 65535) $spidDefault = OltService::DEFAULT_SERVICE_PORT_ID;
        
        $olt = Olt::create([
            'tenant_id' => $tenantId,
            'nama_olt' => $request->nama_olt,
            'host' => $request->host,
            'port' => $request->port ?? 23,
            'username' => $request->username,
            'password' => $request->password,
            'tcont_default' => $tcontDefault,
            'vlan_default' => $vlanDefault,
            'onu_type_default' => $onuTypeDefault,
            'service_port_id_default' => $spidDefault,
        ]);

        return response()->json([
            'status' => 'ok',
            'message' => 'OLT berhasil ditambahkan',
            'data' => $olt,
        ], 201);
    }

    /**
     * Update OLT profile
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'nama_olt' => 'string|max:100',
            'host' => 'string|max:100|regex:/^[A-Za-z0-9.-]+$/',
            'port' => 'integer|min:1|max:65535',
            'username' => 'string|max:100',
            'password' => 'nullable|string|max:100',
            'tcont_default' => 'nullable|string|max:50',
            'vlan_default' => 'nullable|integer',
            'onu_type_default' => 'nullable|string|max:50',
            'service_port_id_default' => 'nullable|integer',
            'is_active' => 'boolean',
        ]);

        $tenantId = $request->user()->tenant_id ?? 1;
        $olt = Olt::forTenant($tenantId)->findOrFail($id);
        
        $data = $request->only(['nama_olt', 'host', 'port', 'username']);

        if ($request->has('tcont_default')) {
            $tcontDefault = preg_replace('/[^A-Za-z0-9_.-]/', '', trim((string) $request->input('tcont_default', '')));
            if ($tcontDefault === '') $tcontDefault = OltService::DEFAULT_TCONT_PROFILE;
            $data['tcont_default'] = $tcontDefault;
        }

        if ($request->has('vlan_default')) {
            $vlanDefault = (int) ($request->input('vlan_default') ?? OltService::DEFAULT_VLAN);
            if ($vlanDefault < 1 || $vlanDefault > 4094) $vlanDefault = OltService::DEFAULT_VLAN;
            $data['vlan_default'] = $vlanDefault;
        }

        if ($request->has('onu_type_default')) {
            $onuTypeDefault = preg_replace('/[^A-Za-z0-9_.-]/', '', trim((string) $request->input('onu_type_default', '')));
            if ($onuTypeDefault === '') $onuTypeDefault = OltService::DEFAULT_ONU_TYPE;
            $data['onu_type_default'] = $onuTypeDefault;
        }

        if ($request->has('service_port_id_default')) {
            $spidDefault = (int) ($request->input('service_port_id_default') ?? OltService::DEFAULT_SERVICE_PORT_ID);
            if ($spidDefault < 1 || $spidDefault > 65535) $spidDefault = OltService::DEFAULT_SERVICE_PORT_ID;
            $data['service_port_id_default'] = $spidDefault;
        }

        if (Schema::hasColumn('noci_olts', 'is_active') && $request->has('is_active')) {
            $data['is_active'] = (bool) $request->is_active;
        }
        
        // Only update password if provided
        if ($request->filled('password')) {
            $data['password'] = $request->password;
        }
        
        $olt->update($data);

        return response()->json([
            'status' => 'ok',
            'message' => 'OLT berhasil diupdate',
            'data' => $olt,
        ]);
    }

    /**
     * Delete OLT profile
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $olt = Olt::forTenant($tenantId)->findOrFail($id);
        
        // Delete related ONUs
        if (Schema::hasTable('noci_olt_onu')) {
            OltOnu::forTenant($tenantId)->where('olt_id', $id)->delete();
        }
        
        // Delete logs
        if (Schema::hasTable('noci_olt_logs')) {
            OltLog::forTenant($tenantId)->where('olt_id', $id)->delete();
        }
        
        $olt->delete();

        return response()->json([
            'status' => 'ok',
            'message' => 'OLT berhasil dihapus',
        ]);
    }

    /**
     * Test connection to OLT
     */
    public function testConnection(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $olt = Olt::forTenant($tenantId)->findOrFail($id);
        
        try {
            $service = new OltService($tenantId);
            $service->connect($olt);
            $service->disconnect();
            
            return response()->json([
                'status' => 'ok',
                'message' => 'Koneksi berhasil',
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get FSP list from OLT
     */
    public function listFsp(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $olt = Olt::forTenant($tenantId)->findOrFail($id);
        
        // Check cache first (fresh)
        $cacheList = is_array($olt->fsp_cache) ? $olt->fsp_cache : [];
        if ($olt->isFspCacheValid() && !empty($cacheList)) {
            return response()->json([
                'status' => 'ok',
                'data' => $cacheList,
                'cached' => true,
                'stale' => false,
            ]);
        }
        
        try {
            $service = new OltService($tenantId);
            $service->connect($olt);
            $fspList = $service->listFsp();
            if (!empty($fspList)) {
                $olt->updateFspCache($fspList);
            }
            $service->disconnect();
            
            return response()->json([
                'status' => 'ok',
                'data' => $fspList,
                'cached' => false,
            ]);
        } catch (RuntimeException $e) {
            // Fallback 1: stale cache (if any)
            if (!empty($cacheList)) {
                return response()->json([
                    'status' => 'ok',
                    'data' => $cacheList,
                    'cached' => true,
                    'stale' => true,
                    'message' => 'Telnet gagal, menggunakan cache FSP lama.',
                ]);
            }

            // Fallback 2: derive FSP list from ONU cache table
            if (Schema::hasTable('noci_olt_onu')) {
                $derived = OltOnu::forTenant($tenantId)
                    ->where('olt_id', $id)
                    ->whereNotNull('fsp')
                    ->distinct()
                    ->pluck('fsp')
                    ->filter(fn ($v) => is_string($v) && $v !== '')
                    ->values()
                    ->all();

                if (!empty($derived)) {
                    usort($derived, function (string $a, string $b) {
                        $pa = array_map('intval', explode('/', $a));
                        $pb = array_map('intval', explode('/', $b));
                        if (($pa[0] ?? 0) !== ($pb[0] ?? 0)) return ($pa[0] ?? 0) <=> ($pb[0] ?? 0);
                        if (($pa[1] ?? 0) !== ($pb[1] ?? 0)) return ($pa[1] ?? 0) <=> ($pb[1] ?? 0);
                        return ($pa[2] ?? 0) <=> ($pb[2] ?? 0);
                    });

                    return response()->json([
                        'status' => 'ok',
                        'data' => $derived,
                        'cached' => true,
                        'stale' => true,
                        'message' => 'Telnet gagal, menggunakan FSP dari cache ONU.',
                    ]);
                }
            }

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Scan unconfigured ONUs
     */
    public function scanUnconfigured(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $olt = Olt::forTenant($tenantId)->findOrFail($id);
        
        try {
            $service = new OltService($tenantId);
            $service->connect($olt);
            $items = $service->scanUnconfigured();
            $service->disconnect();
            
            return response()->json([
                'status' => 'ok',
                'data' => $items,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get registered ONUs for FSP
     */
    public function loadRegisteredFsp(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'fsp' => 'required|string|regex:/^\d+\/\d+\/\d+$/',
        ]);
        
        $tenantId = $request->user()->tenant_id ?? 1;
        $olt = Olt::forTenant($tenantId)->findOrFail($id);
        $fsp = $request->fsp;
        
        try {
            $service = new OltService($tenantId);
            $service->connect($olt);
            $items = $service->loadRegisteredFsp($fsp);
            $service->disconnect();

            // Merge cache data (native parity): fill name/online_duration/vlan from DB cache.
            if (Schema::hasTable('noci_olt_onu')) {
                $snCol = $this->onuCacheSnColumn();
                $snList = [];
                foreach ($items as $it) {
                    $sn = (string) ($it['sn'] ?? '');
                    if ($sn !== '') {
                        $snList[$sn] = true;
                    }
                }

                $sns = array_keys($snList);
                if (!empty($sns)) {
                    $rows = OltOnu::forTenant($tenantId)
                        ->where('olt_id', $id)
                        ->whereIn($snCol, $sns)
                        ->get();

                    $map = [];
                    foreach ($rows as $row) {
                        $sn = (string) ($row->sn ?? $row->getAttribute('serial_number') ?? '');
                        if ($sn === '') continue;
                        $map[$sn] = [
                            'onu_name' => (string) ($row->onu_name ?? ''),
                            'online_duration' => (string) ($row->online_duration ?? ''),
                            'vlan' => (int) ($row->vlan ?? 0),
                        ];
                    }

                    if (!empty($map)) {
                        foreach ($items as &$it) {
                            $sn = (string) ($it['sn'] ?? '');
                            if ($sn === '' || !isset($map[$sn])) continue;
                            $cached = $map[$sn];
                            if (((string) ($it['name'] ?? '')) === '' && $cached['onu_name'] !== '') {
                                $it['name'] = $cached['onu_name'];
                            }
                            if (((string) ($it['online_duration'] ?? '')) === '' && $cached['online_duration'] !== '') {
                                $it['online_duration'] = $cached['online_duration'];
                            }
                            if (empty($it['vlan']) && !empty($cached['vlan'])) {
                                $it['vlan'] = (int) $cached['vlan'];
                            }
                        }
                        unset($it);
                    }
                }
            }
            
            return response()->json([
                'status' => 'ok',
                'data' => $items,
            ]);
        } catch (RuntimeException $e) {
            // Telnet may be blocked on hosting; fall back to DB cache (offline-only).
            if (Schema::hasTable('noci_olt_onu')) {
                $items = OltOnu::forTenant($tenantId)
                    ->where('olt_id', $id)
                    ->where('fsp', $fsp)
                    ->whereNotNull('onu_id')
                    ->orderBy('onu_id')
                    ->get()
                    ->map(fn (OltOnu $onu) => $this->formatOnuCacheRow($onu))
                    ->values()
                    ->all();

                if (!empty($items)) {
                    return response()->json([
                        'status' => 'ok',
                        'data' => $items,
                        'cached' => true,
                        'message' => 'Telnet gagal, menggunakan cache ONU.',
                    ]);
                }
            }

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    private function mergeOnuDbCacheFields(int $tenantId, int $oltId, array $items): array
    {
        if (empty($items) || !Schema::hasTable('noci_olt_onu')) {
            return $items;
        }

        $snCol = $this->onuCacheSnColumn();
        $snList = [];
        foreach ($items as $it) {
            $sn = (string) ($it['sn'] ?? '');
            if ($sn !== '') {
                $snList[$sn] = true;
            }
        }
        $sns = array_keys($snList);
        if (empty($sns)) {
            return $items;
        }

        try {
            $rows = OltOnu::forTenant($tenantId)
                ->where('olt_id', $oltId)
                ->whereIn($snCol, $sns)
                ->get();
        } catch (Throwable $e) {
            return $items;
        }

        $map = [];
        foreach ($rows as $row) {
            $sn = (string) ($row->sn ?? $row->getAttribute('serial_number') ?? '');
            if ($sn === '') continue;
            $map[$sn] = [
                'onu_name' => (string) ($row->onu_name ?? $row->getAttribute('name') ?? ''),
                'online_duration' => (string) ($row->online_duration ?? ''),
                'vlan' => (int) ($row->vlan ?? 0),
            ];
        }

        if (empty($map)) {
            return $items;
        }

        foreach ($items as &$it) {
            $sn = (string) ($it['sn'] ?? '');
            if ($sn === '' || !isset($map[$sn])) continue;
            $cached = $map[$sn];
            if (((string) ($it['name'] ?? '')) === '' && $cached['onu_name'] !== '') {
                $it['name'] = $cached['onu_name'];
            }
            if (((string) ($it['online_duration'] ?? '')) === '' && $cached['online_duration'] !== '') {
                $it['online_duration'] = $cached['online_duration'];
            }
            if (empty($it['vlan']) && !empty($cached['vlan'])) {
                $it['vlan'] = (int) $cached['vlan'];
            }
        }
        unset($it);

        return $items;
    }

    /**
     * Load registered ONUs (baseinfo) for multiple FSPs in one telnet session (native parity).
     */
    public function loadRegisteredAllBaseinfo(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'fsp_list' => 'required|array|min:1',
            'fsp_list.*' => 'string|regex:/^\\d+\\/\\d+\\/\\d+$/',
        ]);

        $tenantId = $request->user()->tenant_id ?? 1;
        $olt = Olt::forTenant($tenantId)->findOrFail($id);

        $fspList = array_values(array_unique(array_map('strval', $request->input('fsp_list', []))));
        $items = [];
        $failed = [];

        $service = null;
        try {
            $service = new OltService($tenantId);
            $service->connect($olt);

            foreach ($fspList as $fsp) {
                try {
                    $list = $service->loadRegisteredFsp($fsp);
                    if (!empty($list)) {
                        $items = array_merge($items, $list);
                    }
                } catch (RuntimeException $e) {
                    $failed[] = ['fsp' => $fsp, 'error' => $e->getMessage()];
                }
            }
            $service->disconnect();
        } catch (RuntimeException $e) {
            try {
                if ($service) $service->disconnect();
            } catch (Throwable $e2) {
                // ignore
            }

            // Hosting-safe fallback: return DB cache when telnet fails.
            if (Schema::hasTable('noci_olt_onu')) {
                $cached = OltOnu::forTenant($tenantId)
                    ->where('olt_id', $id)
                    ->whereIn('fsp', $fspList)
                    ->whereNotNull('onu_id')
                    ->orderBy('fsp')
                    ->orderBy('onu_id')
                    ->get()
                    ->map(fn (OltOnu $onu) => $this->formatOnuCacheRow($onu))
                    ->values()
                    ->all();

                if (!empty($cached)) {
                    return response()->json([
                        'status' => 'ok',
                        'data' => $cached,
                        'count' => count($cached),
                        'fsp_count' => count($fspList),
                        'failed' => $failed,
                        'cached' => true,
                        'message' => 'Telnet gagal, menggunakan cache ONU.',
                    ]);
                }
            }

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }

        $items = $this->mergeOnuDbCacheFields($tenantId, $id, $items);

        return response()->json([
            'status' => 'ok',
            'data' => $items,
            'count' => count($items),
            'fsp_count' => count($fspList),
            'failed' => $failed,
        ]);
    }

    /**
     * Find ONU by SN (telnet)
     */
    public function findOnuBySn(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'sn' => 'required|string|min:8|max:20',
        ]);

        $tenantId = $request->user()->tenant_id ?? 1;
        $olt = Olt::forTenant($tenantId)->findOrFail($id);
        $sn = preg_replace('/[^A-Za-z0-9]/', '', (string) $request->sn);
        if ($sn === '') {
            return response()->json(['status' => 'ok', 'data' => []]);
        }

        try {
            $service = new OltService($tenantId);
            $service->connect($olt);
            $found = $service->findOnuBySn($sn);
            $service->disconnect();

            return response()->json([
                'status' => 'ok',
                'data' => $found ?: [],
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Deep sync registered ONU detail in chunks (native parity).
     */
    public function syncOnuNames(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'fsp' => 'required|string|regex:/^\\d+\\/\\d+\\/\\d+$/',
            'offset' => 'nullable|integer|min:0',
            'limit' => 'nullable|integer|min:1|max:50',
            'only_missing' => 'nullable|boolean',
        ]);

        $tenantId = $request->user()->tenant_id ?? 1;
        $olt = Olt::forTenant($tenantId)->findOrFail($id);
        $fsp = (string) $request->fsp;
        $offset = (int) ($request->input('offset') ?? 0);
        $limit = (int) ($request->input('limit') ?? 20);
        $onlyMissing = $request->boolean('only_missing', true);

        $service = null;
        try {
            $service = new OltService($tenantId);
            $service->connect($olt);
            $res = $service->syncOnuNamesChunk($fsp, $offset, $limit, $onlyMissing);
            $service->disconnect();

            return response()->json(array_merge(['status' => 'ok'], $res));
        } catch (RuntimeException $e) {
            try {
                if ($service) $service->disconnect();
            } catch (Throwable $e2) {
                // ignore
            }

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get all registered ONUs from cache
     */
    public function loadRegisteredCache(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $olt = Olt::forTenant($tenantId)->findOrFail($id);

        if (!Schema::hasTable('noci_olt_onu')) {
            return response()->json([
                'status' => 'ok',
                'data' => [],
            ]);
        }

        $snCol = $this->onuCacheSnColumn();
        $query = OltOnu::forTenant($tenantId)->where('olt_id', $id);
        
        if ($request->filled('fsp')) {
            $query->where('fsp', $request->fsp);
        }
        
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $snCol = $this->onuCacheSnColumn();
                $q->where($snCol, 'like', "%{$search}%");

                if (Schema::hasColumn((new OltOnu())->getTable(), 'onu_name')) {
                    $q->orWhere('onu_name', 'like', "%{$search}%");
                } elseif (Schema::hasColumn((new OltOnu())->getTable(), 'name')) {
                    $q->orWhere('name', 'like', "%{$search}%");
                }
            });
        }
        
        if ($request->filled('status') && Schema::hasColumn((new OltOnu())->getTable(), 'status')) {
            $query->where('status', $request->status);
        }
        
        $items = $query->orderBy('fsp')
            ->orderBy('onu_id')
            ->get()
            ->map(fn (OltOnu $onu) => $this->formatOnuCacheRow($onu))
            ->values()
            ->all();
        
        return response()->json([
            'status' => 'ok',
            'data' => $items,
        ]);
    }

    /**
     * Search ONUs in cache
     */
    public function searchCache(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:2',
        ]);
        
        $tenantId = $request->user()->tenant_id ?? 1;
        $q = $request->q;

        if (!Schema::hasTable('noci_olt_onu')) {
            return response()->json([
                'status' => 'ok',
                'data' => [],
            ]);
        }

        $snCol = $this->onuCacheSnColumn();

        $items = OltOnu::forTenant($tenantId)
            ->where('olt_id', $id)
            ->where(function ($query) use ($q, $snCol) {
                $query->where($snCol, 'like', "%{$q}%");

                if (Schema::hasColumn((new OltOnu())->getTable(), 'onu_name')) {
                    $query->orWhere('onu_name', 'like', "%{$q}%");
                } elseif (Schema::hasColumn((new OltOnu())->getTable(), 'name')) {
                    $query->orWhere('name', 'like', "%{$q}%");
                }
            })
            ->orderBy('updated_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn (OltOnu $onu) => $this->formatOnuCacheRow($onu))
            ->values()
            ->all();
        
        return response()->json([
            'status' => 'ok',
            'data' => $items,
        ]);
    }

    /**
     * Get ONU detail
     */
    public function getOnuDetail(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'fsp' => 'required|string|regex:/^\d+\/\d+\/\d+$/',
            'onu_id' => 'required|integer|min:1|max:128',
        ]);
        
        $tenantId = $request->user()->tenant_id ?? 1;
        $olt = Olt::forTenant($tenantId)->findOrFail($id);
        
        try {
            $service = new OltService($tenantId);
            $service->connect($olt);
            $detail = $service->getOnuDetail($request->fsp, $request->onu_id);
            $service->disconnect();
            
            // Also get from DB cache
            $dbOnu = Schema::hasTable('noci_olt_onu')
                ? OltOnu::forTenant($tenantId)
                    ->where('olt_id', $id)
                    ->where('fsp', $request->fsp)
                    ->where('onu_id', $request->onu_id)
                    ->first()
                : null;

            if ($dbOnu) {
                // Keep response compatible with existing UI keys.
                if (((string) ($detail['name'] ?? '')) === '' && (string) ($dbOnu->onu_name ?? '') !== '') {
                    $detail['name'] = $dbOnu->onu_name;
                }
                if (((string) ($detail['sn'] ?? '')) === '' && (string) ($dbOnu->sn ?? '') !== '') {
                    $detail['sn'] = $dbOnu->sn;
                }
                if (((string) ($detail['online_duration'] ?? '')) === '' && (string) ($dbOnu->online_duration ?? '') !== '') {
                    $detail['online_duration'] = $dbOnu->online_duration;
                }
                if (!isset($detail['vlan']) && $dbOnu->vlan !== null) {
                    $detail['vlan'] = (int) $dbOnu->vlan;
                }
                $detail['cached_at'] = $dbOnu->updated_at?->format('Y-m-d H:i');
            }
            
            return response()->json([
                'status' => 'ok',
                'data' => $detail,
            ]);
        } catch (RuntimeException $e) {
            // Fallback to cache when telnet fails.
            if (Schema::hasTable('noci_olt_onu')) {
                $dbOnu = OltOnu::forTenant($tenantId)
                    ->where('olt_id', $id)
                    ->where('fsp', $request->fsp)
                    ->where('onu_id', $request->onu_id)
                    ->first();

                if ($dbOnu) {
                    return response()->json([
                        'status' => 'ok',
                        'data' => [
                            'sn' => (string) ($dbOnu->sn ?? $dbOnu->getAttribute('serial_number') ?? ''),
                            'name' => (string) ($dbOnu->onu_name ?? ''),
                            'status' => 'offline',
                            'online_duration' => (string) ($dbOnu->online_duration ?? ''),
                            'vlan' => (int) ($dbOnu->vlan ?? 0),
                            'cached_at' => $dbOnu->updated_at?->format('Y-m-d H:i'),
                            'cached' => true,
                        ],
                        'message' => 'Telnet gagal, menggunakan cache ONU.',
                    ]);
                }
            }

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Register ONU
     */
    public function registerOnu(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'fsp' => 'required|string|regex:/^\d+\/\d+\/\d+$/',
            'sn' => 'required|string|regex:/^[A-Za-z]{4}[A-Fa-f0-9]{8}$/',
            'name' => 'required|string|max:50',
            'onu_id' => 'nullable|integer|min:1|max:128',
            'save_config' => 'nullable|boolean',
        ]);
        
        $tenantId = $request->user()->tenant_id ?? 1;
        $olt = Olt::forTenant($tenantId)->findOrFail($id);
        $saveConfig = $request->boolean('save_config', false);
        $actor = $this->actorName($request);
        
        $service = null;
        $logExcerpt = '';
        $logId = null;
        try {
            $service = new OltService($tenantId);
            $service->setSuppressActionLog(true);
            $service->connect($olt);
            $service->startTrace();
            $result = $service->registerOnu(
                $request->fsp,
                $request->sn,
                $request->name,
                $request->onu_id
            );

            // Native parity: don't auto write-config unless explicitly requested.
            if ($saveConfig) {
                $service->writeConfig();
            }

            $logExcerpt = $service->getTraceText(20000);
            $service->disconnect();

            $summary = [
                'count' => 1,
                'success' => 1,
                'error' => 0,
                'fsp' => (string) ($result['fsp'] ?? $request->fsp),
                'onu_id' => (int) ($result['onu_id'] ?? ($request->onu_id ?? 0)),
                'sn' => (string) ($result['sn'] ?? $request->sn),
                'onu_name' => (string) ($result['name'] ?? $request->name),
                'save_config' => $saveConfig,
            ];
            $logId = OltLog::logAction(
                $tenantId,
                $olt->id,
                $olt->nama_olt,
                'register',
                'done',
                $summary,
                $logExcerpt,
                $actor
            );

            return response()->json([
                'status' => 'ok',
                'message' => $saveConfig ? 'ONU berhasil diregistrasi dan config disimpan (write).' : 'ONU berhasil diregistrasi.',
                'data' => $result,
                'log_id' => $logId,
                'log_excerpt' => $logExcerpt,
            ]);
        } catch (RuntimeException $e) {
            try {
                if ($service) {
                    $logExcerpt = $service->getTraceText(20000);
                    $service->disconnect();
                }
            } catch (Throwable $e2) {
                // ignore
            }

            $summary = [
                'count' => 1,
                'success' => 0,
                'error' => 1,
                'fsp' => (string) $request->fsp,
                'onu_id' => (int) ($request->onu_id ?? 0),
                'sn' => (string) $request->sn,
                'onu_name' => (string) $request->name,
                'save_config' => $saveConfig,
                'message' => $e->getMessage(),
            ];
            $logId = OltLog::logAction(
                $tenantId,
                $olt->id,
                $olt->nama_olt,
                'register',
                'error',
                $summary,
                $logExcerpt !== '' ? $logExcerpt : $e->getMessage(),
                $actor
            );

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'log_id' => $logId,
                'log_excerpt' => $logExcerpt,
            ], 422);
        }
    }

    /**
     * Update ONU name
     */
    public function updateOnuName(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'fsp' => 'required|string|regex:/^\d+\/\d+\/\d+$/',
            'onu_id' => 'required|integer|min:1|max:128',
            'name' => 'required|string|max:50',
        ]);
        
        $tenantId = $request->user()->tenant_id ?? 1;
        $olt = Olt::forTenant($tenantId)->findOrFail($id);
        $actor = $this->actorName($request);
        
        $service = null;
        $logExcerpt = '';
        $logId = null;
        try {
            $service = new OltService($tenantId);
            $service->setSuppressActionLog(true);
            $service->connect($olt);
            $service->startTrace();
            $service->updateOnuName($request->fsp, $request->onu_id, $request->name);
            $logExcerpt = $service->getTraceText(20000);
            $service->disconnect();

            $summary = [
                'fsp' => (string) $request->fsp,
                'onu_id' => (int) $request->onu_id,
                'onu_name' => (string) $request->name,
                'status' => 'success',
            ];
            $logId = OltLog::logAction(
                $tenantId,
                $olt->id,
                $olt->nama_olt,
                'update_onu_detail',
                'done',
                $summary,
                $logExcerpt,
                $actor
            );
            
            return response()->json([
                'status' => 'ok',
                'message' => 'Nama ONU berhasil diupdate',
                'log_id' => $logId,
                'log_excerpt' => $logExcerpt,
            ]);
        } catch (RuntimeException $e) {
            try {
                if ($service) {
                    $logExcerpt = $service->getTraceText(20000);
                    $service->disconnect();
                }
            } catch (Throwable $e2) {
                // ignore
            }

            $summary = [
                'fsp' => (string) $request->fsp,
                'onu_id' => (int) $request->onu_id,
                'onu_name' => (string) $request->name,
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
            $logId = OltLog::logAction(
                $tenantId,
                $olt->id,
                $olt->nama_olt,
                'update_onu_detail',
                'error',
                $summary,
                $logExcerpt !== '' ? $logExcerpt : $e->getMessage(),
                $actor
            );

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'log_id' => $logId,
                'log_excerpt' => $logExcerpt,
            ], 422);
        }
    }

    /**
     * Delete ONU
     */
    public function deleteOnu(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'fsp' => 'required|string|regex:/^\d+\/\d+\/\d+$/',
            'onu_id' => 'required|integer|min:1|max:128',
        ]);
        
        $tenantId = $request->user()->tenant_id ?? 1;
        $olt = Olt::forTenant($tenantId)->findOrFail($id);
        $actor = $this->actorName($request);
        
        $service = null;
        $logExcerpt = '';
        $logId = null;
        try {
            $service = new OltService($tenantId);
            $service->setSuppressActionLog(true);
            $service->connect($olt);
            $service->startTrace();
            $service->deleteOnu($request->fsp, $request->onu_id);
            $logExcerpt = $service->getTraceText(20000);
            $service->disconnect();

            $summary = [
                'fsp' => (string) $request->fsp,
                'onu_id' => (int) $request->onu_id,
                'status' => 'success',
            ];
            $logId = OltLog::logAction(
                $tenantId,
                $olt->id,
                $olt->nama_olt,
                'delete_onu',
                'done',
                $summary,
                $logExcerpt,
                $actor
            );
            
            return response()->json([
                'status' => 'ok',
                'message' => 'ONU berhasil dihapus',
                'log_id' => $logId,
                'log_excerpt' => $logExcerpt,
            ]);
        } catch (RuntimeException $e) {
            try {
                if ($service) {
                    $logExcerpt = $service->getTraceText(20000);
                    $service->disconnect();
                }
            } catch (Throwable $e2) {
                // ignore
            }

            $summary = [
                'fsp' => (string) $request->fsp,
                'onu_id' => (int) $request->onu_id,
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
            $logId = OltLog::logAction(
                $tenantId,
                $olt->id,
                $olt->nama_olt,
                'delete_onu',
                'error',
                $summary,
                $logExcerpt !== '' ? $logExcerpt : $e->getMessage(),
                $actor
            );

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'log_id' => $logId,
                'log_excerpt' => $logExcerpt,
            ], 422);
        }
    }

    /**
     * Restart ONU
     */
    public function restartOnu(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'fsp' => 'required|string|regex:/^\d+\/\d+\/\d+$/',
            'onu_id' => 'required|integer|min:1|max:128',
        ]);
        
        $tenantId = $request->user()->tenant_id ?? 1;
        $olt = Olt::forTenant($tenantId)->findOrFail($id);
        $actor = $this->actorName($request);
        
        $service = null;
        $logExcerpt = '';
        $logId = null;
        try {
            $service = new OltService($tenantId);
            $service->setSuppressActionLog(true);
            $service->connect($olt);
            $service->startTrace();
            $service->restartOnu($request->fsp, $request->onu_id);
            $logExcerpt = $service->getTraceText(20000);
            $service->disconnect();

            $summary = [
                'fsp' => (string) $request->fsp,
                'onu_id' => (int) $request->onu_id,
                'status' => 'success',
            ];
            $logId = OltLog::logAction(
                $tenantId,
                $olt->id,
                $olt->nama_olt,
                'restart_onu',
                'done',
                $summary,
                $logExcerpt,
                $actor
            );
            
            return response()->json([
                'status' => 'ok',
                'message' => 'ONU sedang di-restart',
                'log_id' => $logId,
                'log_excerpt' => $logExcerpt,
            ]);
        } catch (RuntimeException $e) {
            try {
                if ($service) {
                    $logExcerpt = $service->getTraceText(20000);
                    $service->disconnect();
                }
            } catch (Throwable $e2) {
                // ignore
            }

            $summary = [
                'fsp' => (string) $request->fsp,
                'onu_id' => (int) $request->onu_id,
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
            $logId = OltLog::logAction(
                $tenantId,
                $olt->id,
                $olt->nama_olt,
                'restart_onu',
                'error',
                $summary,
                $logExcerpt !== '' ? $logExcerpt : $e->getMessage(),
                $actor
            );

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'log_id' => $logId,
                'log_excerpt' => $logExcerpt,
            ], 422);
        }
    }

    /**
     * Write config to memory ("write")
     */
    public function writeConfig(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $olt = Olt::forTenant($tenantId)->findOrFail($id);
        $actor = $this->actorName($request);

        $service = null;
        $logExcerpt = '';
        $logId = null;
        try {
            $service = new OltService($tenantId);
            $service->setSuppressActionLog(true);
            $service->connect($olt);
            $service->startTrace();
            $out = $service->sendCommand('write', 60);
            $hasErr = preg_match(OltService::ERROR_RE, $out) && !preg_match(OltService::OK_RE, $out);
            $logExcerpt = $service->getTraceText(20000);
            $service->disconnect();

            $summary = [
                'count' => 1,
                'success' => $hasErr ? 0 : 1,
                'error' => $hasErr ? 1 : 0,
            ];
            $logId = OltLog::logAction(
                $tenantId,
                $olt->id,
                $olt->nama_olt,
                'write',
                $hasErr ? 'error' : 'done',
                $summary,
                $logExcerpt,
                $actor
            );

            if ($hasErr) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Write gagal. Cek log.',
                    'log_id' => $logId,
                    'log_excerpt' => $logExcerpt,
                ], 422);
            }

            return response()->json([
                'status' => 'ok',
                'message' => 'Config berhasil disimpan (write).',
                'log_id' => $logId,
                'log_excerpt' => $logExcerpt,
            ]);
        } catch (RuntimeException $e) {
            try {
                if ($service) {
                    $logExcerpt = $service->getTraceText(20000);
                    $service->disconnect();
                }
            } catch (Throwable $e2) {
                // ignore
            }

            $summary = [
                'count' => 1,
                'success' => 0,
                'error' => 1,
                'message' => $e->getMessage(),
            ];
            $logId = OltLog::logAction(
                $tenantId,
                $olt->id,
                $olt->nama_olt,
                'write',
                'error',
                $summary,
                $logExcerpt !== '' ? $logExcerpt : $e->getMessage(),
                $actor
            );

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'log_id' => $logId,
                'log_excerpt' => $logExcerpt,
            ], 422);
        }
    }

    /**
     * Auto-register all unconfigured ONUs (native parity)
     */
    public function autoRegister(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $role = strtolower(trim((string) ($request->user()->role ?? '')));
        $isTeknisi = in_array($role, ['teknisi', 'svp lapangan', 'svp_lapangan'], true);

        if ($isTeknisi) {
            return response()->json([
                'status' => 'error',
                'message' => 'Teknisi hanya bisa registrasi manual.',
            ], 403);
        }

        $request->validate([
            'name_prefix' => 'nullable|string|max:16',
            'save_config' => 'nullable|boolean',
        ]);

        $namePrefix = trim((string) ($request->input('name_prefix') ?? ''));
        if ($namePrefix !== '') {
            // Keep it CLI-safe and short.
            $namePrefix = preg_replace('/[^A-Za-z0-9_-]/', '', $namePrefix);
            $namePrefix = substr($namePrefix, 0, 16);
        }

        // Native parity: default is NOT to run write-config; users can click "Simpan Config" separately.
        $saveConfig = $request->boolean('save_config', false);
        $olt = Olt::forTenant($tenantId)->findOrFail($id);
        $actor = $this->actorName($request);

        $service = null;
        $logExcerpt = '';
        $logId = null;
        try {
            $service = new OltService($tenantId);
            $service->setSuppressActionLog(true);
            $service->connect($olt);
            $service->startTrace();

            $uncfg = $service->scanUnconfigured();
            if (empty($uncfg)) {
                $service->disconnect();
                return response()->json([
                    'status' => 'ok',
                    'message' => 'Tidak ada ONU unregistered.',
                    'summary' => [
                        'count' => 0,
                        'success' => 0,
                        'error' => 0,
                    ],
                    'results' => [],
                ]);
            }

            $results = [];
            $succ = 0;
            $err = 0;
            foreach ($uncfg as $it) {
                $fsp = (string) ($it['fsp'] ?? '');
                $sn = (string) ($it['sn'] ?? '');
                if ($fsp === '' || $sn === '') continue;

                $onuName = $namePrefix !== '' ? "{$namePrefix}-{$sn}" : "ONU-{$sn}";
                $onuName = substr($onuName, 0, 32);

                try {
                    $res = $service->registerOnu($fsp, $sn, $onuName, null);
                    $succ++;
                    $results[] = [
                        'fsp' => $fsp,
                        'sn' => $sn,
                        'onu_id' => $res['onu_id'] ?? null,
                        'name' => $res['name'] ?? $onuName,
                        'status' => 'success',
                        'message' => 'Provisioned',
                    ];
                } catch (RuntimeException $e) {
                    $err++;
                    $results[] = [
                        'fsp' => $fsp,
                        'sn' => $sn,
                        'status' => 'error',
                        'message' => $e->getMessage(),
                    ];
                }
            }

            if ($saveConfig && $succ > 0) {
                $service->writeConfig();
            }

            $logExcerpt = $service->getTraceText(20000);
            $service->disconnect();

            $summary = [
                'count' => $succ + $err,
                'success' => $succ,
                'error' => $err,
            ];
            $logId = OltLog::logAction(
                $tenantId,
                $olt->id,
                $olt->nama_olt,
                'register_auto',
                'done',
                $summary,
                $logExcerpt,
                $actor
            );

            return response()->json([
                'status' => 'ok',
                'message' => "Auto register selesai. Success {$succ}, error {$err}.",
                'summary' => $summary,
                'results' => $results,
                'log_id' => $logId,
                'log_excerpt' => $logExcerpt,
            ]);
        } catch (RuntimeException $e) {
            try {
                if ($service) {
                    $logExcerpt = $service->getTraceText(20000);
                    $service->disconnect();
                }
            } catch (Throwable $e2) {
                // ignore
            }

            $summary = [
                'count' => 0,
                'success' => 0,
                'error' => 1,
                'message' => $e->getMessage(),
            ];
            $logId = OltLog::logAction(
                $tenantId,
                $olt->id,
                $olt->nama_olt,
                'register_auto',
                'error',
                $summary,
                $logExcerpt !== '' ? $logExcerpt : $e->getMessage(),
                $actor
            );

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'log_id' => $logId,
                'log_excerpt' => $logExcerpt,
            ], 422);
        }
    }

    /**
     * Sync all ONUs to database
     */
    public function syncAll(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $olt = Olt::forTenant($tenantId)->findOrFail($id);
        
        try {
            $service = new OltService($tenantId);
            $service->connect($olt);
            $count = $service->syncAllOnusToDb();
            $service->disconnect();
            
            return response()->json([
                'status' => 'ok',
                'message' => "Berhasil sync {$count} ONU",
                'count' => $count,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get OLT logs
     */
    public function logs(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id ?? 1;

        $table = (new OltLog())->getTable();
        if (!Schema::hasTable($table)) {
            return response()->json(['status' => 'ok', 'data' => []]);
        }

        $cols = [];
        try {
            $cols = Schema::getColumnListing($table);
        } catch (Throwable $e) {
            $cols = [];
        }
        $colSet = array_fill_keys($cols, true);

        $orderCol = isset($colSet['created_at']) ? 'created_at' : (isset($colSet['id']) ? 'id' : null);
        $query = DB::table($table)
            ->where('tenant_id', $tenantId)
            ->where('olt_id', $id);

        if ($orderCol) {
            $query->orderBy($orderCol, 'desc');
        }

        $rows = $query->limit(100)->get();

        $logs = [];
        foreach ($rows as $row) {
            $logText = '';
            if (isset($colSet['log_text'])) {
                $logText = (string) ($row->log_text ?? '');
            } elseif (isset($colSet['response'])) {
                $logText = (string) ($row->response ?? '');
            }

            $status = '';
            if (isset($colSet['status'])) {
                $status = (string) ($row->status ?? '');
            } elseif (isset($colSet['success'])) {
                $status = ((int) ($row->success ?? 1)) === 1 ? 'done' : 'error';
            }

            $summaryJson = null;
            if (isset($colSet['summary_json'])) {
                $summaryJson = $row->summary_json ?? null;
            } elseif (isset($colSet['command'])) {
                $summaryJson = $row->command ?? null;
            }

            $logs[] = [
                'id' => $row->id ?? null,
                'tenant_id' => $row->tenant_id ?? null,
                'created_at' => $row->created_at ?? null,
                'olt_id' => $row->olt_id ?? null,
                'olt_name' => $row->olt_name ?? null,
                'action' => $row->action ?? null,
                'actor' => $row->actor ?? null,
                'status' => $status,
                'summary_json' => $summaryJson,
                'log_text' => $logText,
            ];
        }
        
        return response()->json([
            'status' => 'ok',
            'data' => $logs,
        ]);
    }

    /**
     * Dropdown for OLT selection
     */
    public function dropdown(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        
        $query = Olt::forTenant($tenantId)->orderBy('nama_olt');
        if (Schema::hasColumn('noci_olts', 'is_active')) {
            $query->where('is_active', true);
        }

        $olts = $query->get(['id', 'nama_olt', 'host']);
        
        return response()->json([
            'status' => 'ok',
            'data' => $olts,
        ]);
    }
}
