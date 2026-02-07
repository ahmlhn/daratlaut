<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\OltLog;
use App\Services\OltService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class OltController extends Controller
{
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
                return [
                    'id' => $olt->id,
                    'nama_olt' => $olt->nama_olt,
                    'host' => $olt->host,
                    'port' => $olt->port,
                    'is_active' => $olt->is_active ?? true,
                    'fsp_count' => count($olt->fsp_cache ?? []),
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
            'host' => 'required|string|max:100',
            'port' => 'integer|min:1|max:65535',
            'username' => 'required|string|max:100',
            'password' => 'required|string|max:100',
            'tcont_default' => 'nullable|string|max:50',
            'vlan_default' => 'nullable|integer',
        ]);

        $tenantId = $request->user()->tenant_id ?? 1;
        
        $olt = Olt::create([
            'tenant_id' => $tenantId,
            'nama_olt' => $request->nama_olt,
            'host' => $request->host,
            'port' => $request->port ?? 23,
            'username' => $request->username,
            'password' => $request->password,
            'tcont_default' => $request->tcont_default ?? 'pppoe',
            'vlan_default' => $request->vlan_default ?? 200,
            'is_active' => true,
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
            'host' => 'string|max:100',
            'port' => 'integer|min:1|max:65535',
            'username' => 'string|max:100',
            'password' => 'nullable|string|max:100',
            'tcont_default' => 'nullable|string|max:50',
            'vlan_default' => 'nullable|integer',
            'is_active' => 'boolean',
        ]);

        $tenantId = $request->user()->tenant_id ?? 1;
        $olt = Olt::forTenant($tenantId)->findOrFail($id);
        
        $data = $request->only(['nama_olt', 'host', 'port', 'username', 'tcont_default', 'vlan_default', 'is_active']);
        
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
            OltOnu::where('olt_id', $id)->delete();
        }
        
        // Delete logs
        if (Schema::hasTable('noci_olt_logs')) {
            OltLog::where('olt_id', $id)->delete();
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
        
        // Check cache first
        if ($olt->isFspCacheValid()) {
            return response()->json([
                'status' => 'ok',
                'data' => $olt->fsp_cache ?? [],
                'cached' => true,
            ]);
        }
        
        try {
            $service = new OltService($tenantId);
            $service->connect($olt);
            $fspList = $service->listFsp();
            $olt->updateFspCache($fspList);
            $service->disconnect();
            
            return response()->json([
                'status' => 'ok',
                'data' => $fspList,
                'cached' => false,
            ]);
        } catch (RuntimeException $e) {
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
     * Get all registered ONUs from cache
     */
    public function loadRegisteredCache(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $olt = Olt::forTenant($tenantId)->findOrFail($id);
        
        $query = OltOnu::where('olt_id', $id);
        
        if ($request->filled('fsp')) {
            $query->where('fsp', $request->fsp);
        }
        
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sn', 'like', "%{$search}%");
            });
        }
        
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        $items = $query->orderBy('fsp')
            ->orderBy('onu_id')
            ->get();
        
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
        
        $items = OltOnu::where('olt_id', $id)
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                      ->orWhere('sn', 'like', "%{$q}%");
            })
            ->orderBy('name')
            ->limit(50)
            ->get();
        
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
            $dbOnu = OltOnu::where('olt_id', $id)
                ->where('fsp', $request->fsp)
                ->where('onu_id', $request->onu_id)
                ->first();
            
            if ($dbOnu) {
                $detail['db_name'] = $dbOnu->name;
                $detail['db_status'] = $dbOnu->status;
                $detail['synced_at'] = $dbOnu->synced_at?->format('Y-m-d H:i');
            }
            
            return response()->json([
                'status' => 'ok',
                'data' => $detail,
            ]);
        } catch (RuntimeException $e) {
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
        ]);
        
        $tenantId = $request->user()->tenant_id ?? 1;
        $olt = Olt::forTenant($tenantId)->findOrFail($id);
        
        try {
            $service = new OltService($tenantId);
            $service->connect($olt);
            $result = $service->registerOnu(
                $request->fsp,
                $request->sn,
                $request->name,
                $request->onu_id
            );
            $service->writeConfig();
            $service->disconnect();
            
            return response()->json([
                'status' => 'ok',
                'message' => 'ONU berhasil diregistrasi',
                'data' => $result,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
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
        
        try {
            $service = new OltService($tenantId);
            $service->connect($olt);
            $service->updateOnuName($request->fsp, $request->onu_id, $request->name);
            $service->writeConfig();
            $service->disconnect();
            
            return response()->json([
                'status' => 'ok',
                'message' => 'Nama ONU berhasil diupdate',
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
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
        
        try {
            $service = new OltService($tenantId);
            $service->connect($olt);
            $service->deleteOnu($request->fsp, $request->onu_id);
            $service->writeConfig();
            $service->disconnect();
            
            return response()->json([
                'status' => 'ok',
                'message' => 'ONU berhasil dihapus',
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
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
        
        try {
            $service = new OltService($tenantId);
            $service->connect($olt);
            $service->restartOnu($request->fsp, $request->onu_id);
            $service->disconnect();
            
            return response()->json([
                'status' => 'ok',
                'message' => 'ONU sedang di-restart',
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
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
        
        $logs = OltLog::where('olt_id', $id)
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();
        
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
