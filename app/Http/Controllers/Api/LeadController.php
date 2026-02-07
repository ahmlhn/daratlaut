<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LeadController extends Controller
{
    private const LEADS_CACHE_TTL_SECONDS = 30;

    private function tenantVersionKey(int $tenantId): string
    {
        return 'leads:v1:version:' . $tenantId;
    }

    private function tenantCacheVersion(int $tenantId): int
    {
        $key = $this->tenantVersionKey($tenantId);
        try {
            $v = Cache::get($key);
            if (is_numeric($v)) return max(1, (int) $v);
            Cache::forever($key, 1);
        } catch (\Throwable) {
        }
        return 1;
    }

    private function bumpTenantCacheVersion(int $tenantId): void
    {
        $key = $this->tenantVersionKey($tenantId);
        try {
            if (Cache::has($key)) {
                Cache::increment($key);
            } else {
                Cache::forever($key, 2);
            }
        } catch (\Throwable) {
        }
    }

    private function listCacheKey(Request $request, int $tenantId, int $version, int $page, int $perPage): string
    {
        $fingerprint = [
            'tenant' => $tenantId,
            'version' => $version,
            'page' => $page,
            'per_page' => $perPage,
            'search' => (string) $request->get('search', ''),
            'status' => (string) $request->get('status', ''),
            'source' => (string) $request->get('source', ''),
            'start_date' => (string) $request->get('start_date', ''),
            'end_date' => (string) $request->get('end_date', ''),
        ];

        return 'leads:v1:list:' . md5(json_encode($fingerprint, JSON_UNESCAPED_UNICODE));
    }

    private function statsCacheKey(int $tenantId, int $version): string
    {
        return 'leads:v1:stats:' . $tenantId . ':' . $version . ':' . now()->format('Y-m');
    }

    private function statusesCacheKey(): string
    {
        return 'leads:v1:statuses';
    }

    /**
     * Get leads list with pagination and filters.
     */
    public function index(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id', 1);
        $perPage = max(5, min(100, (int) $request->get('per_page', 20)));
        $page = max(1, (int) $request->get('page', 1));

        $version = $this->tenantCacheVersion((int) $tenantId);
        $cacheKey = $this->listCacheKey($request, (int) $tenantId, $version, $page, $perPage);

        try {
            $payload = Cache::remember($cacheKey, now()->addSeconds(self::LEADS_CACHE_TTL_SECONDS), function () use ($request, $tenantId, $page, $perPage) {
                $query = Lead::forTenant($tenantId);

                // Search filter
                if ($search = $request->get('search')) {
                    $query->where(function ($q) use ($search) {
                        $q->where('customer_name', 'like', "%{$search}%")
                            ->orWhere('customer_phone', 'like', "%{$search}%")
                            ->orWhere('customer_address', 'like', "%{$search}%");
                    });
                }

                // Status filter
                if ($status = $request->get('status')) {
                    $query->where('status', $status);
                }

                // Source filter
                if ($source = $request->get('source')) {
                    $query->where('source', $source);
                }

                // Date range filter
                if ($startDate = $request->get('start_date')) {
                    $query->where('last_seen', '>=', $startDate);
                }
                if ($endDate = $request->get('end_date')) {
                    $query->where('last_seen', '<=', $endDate . ' 23:59:59');
                }

                $leads = $query->orderBy('last_seen', 'desc')->paginate($perPage, ['*'], 'page', $page);

                return [
                    'status' => 'ok',
                    'data' => $leads->items(),
                    'pagination' => [
                        'total' => $leads->total(),
                        'per_page' => $leads->perPage(),
                        'current_page' => $leads->currentPage(),
                        'last_page' => $leads->lastPage(),
                    ],
                ];
            });
            return response()->json($payload);
        } catch (\Throwable) {
            // Fallback to non-cache path if cache backend fails.
        }

        $query = Lead::forTenant($tenantId);

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%")
                    ->orWhere('customer_address', 'like', "%{$search}%");
            });
        }
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }
        if ($source = $request->get('source')) {
            $query->where('source', $source);
        }
        if ($startDate = $request->get('start_date')) {
            $query->where('last_seen', '>=', $startDate);
        }
        if ($endDate = $request->get('end_date')) {
            $query->where('last_seen', '<=', $endDate . ' 23:59:59');
        }

        $leads = $query->orderBy('last_seen', 'desc')->paginate($perPage, ['*'], 'page', $page);
        return response()->json([
            'status' => 'ok',
            'data' => $leads->items(),
            'pagination' => [
                'total' => $leads->total(),
                'per_page' => $leads->perPage(),
                'current_page' => $leads->currentPage(),
                'last_page' => $leads->lastPage(),
            ],
        ]);
    }

    /**
     * Get leads stats.
     */
    public function stats(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id', 1);
        $version = $this->tenantCacheVersion((int) $tenantId);
        $cacheKey = $this->statsCacheKey((int) $tenantId, $version);

        try {
            $payload = Cache::remember($cacheKey, now()->addSeconds(self::LEADS_CACHE_TTL_SECONDS), function () use ($tenantId) {
                $total = Lead::forTenant($tenantId)->count();
                $byStatus = Lead::forTenant($tenantId)
                    ->selectRaw('status, count(*) as count')
                    ->groupBy('status')
                    ->pluck('count', 'status')
                    ->toArray();

                $thisMonth = Lead::forTenant($tenantId)
                    ->whereMonth('last_seen', now()->month)
                    ->whereYear('last_seen', now()->year)
                    ->count();

                return [
                    'status' => 'ok',
                    'data' => [
                        'total' => $total,
                        'by_status' => $byStatus,
                        'this_month' => $thisMonth,
                        'new' => $byStatus['NEW'] ?? 0,
                        'contacted' => $byStatus['CONTACTED'] ?? 0,
                        'interested' => $byStatus['INTERESTED'] ?? 0,
                        'converted' => $byStatus['CONVERTED'] ?? 0,
                        'lost' => $byStatus['LOST'] ?? 0,
                    ],
                ];
            });
            return response()->json($payload);
        } catch (\Throwable) {
        }

        $total = Lead::forTenant($tenantId)->count();
        $byStatus = Lead::forTenant($tenantId)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $thisMonth = Lead::forTenant($tenantId)
            ->whereMonth('last_seen', now()->month)
            ->whereYear('last_seen', now()->year)
            ->count();

        return response()->json([
            'status' => 'ok',
            'data' => [
                'total' => $total,
                'by_status' => $byStatus,
                'this_month' => $thisMonth,
                'new' => $byStatus['NEW'] ?? 0,
                'contacted' => $byStatus['CONTACTED'] ?? 0,
                'interested' => $byStatus['INTERESTED'] ?? 0,
                'converted' => $byStatus['CONVERTED'] ?? 0,
                'lost' => $byStatus['LOST'] ?? 0,
            ],
        ]);
    }

    /**
     * Get single lead.
     */
    public function show(Request $request, $id)
    {
        $tenantId = $request->attributes->get('tenant_id', 1);
        $lead = Lead::forTenant($tenantId)->findOrFail($id);
        
        return response()->json([
            'status' => 'ok',
            'data' => $lead,
        ]);
    }

    /**
     * Create new lead.
     */
    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id', 1);
        
        $validated = $request->validate([
            'customer_name' => 'required|string|max:255',
            'customer_phone' => 'required|string|max:50',
            'customer_address' => 'nullable|string',
            'notes' => 'nullable|string',
            'status' => 'nullable|string|max:50',
            'source' => 'nullable|string|max:100',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
        ]);
        
        $lead = new Lead();
        $lead->tenant_id = $tenantId;
        $lead->visit_id = 'MANUAL-' . time() . rand(100, 999);
        $lead->customer_name = $validated['customer_name'];
        $lead->customer_phone = Lead::normalizePhone($validated['customer_phone']);
        $lead->customer_address = $validated['customer_address'] ?? '';
        $lead->notes = $validated['notes'] ?? '';
        $lead->status = $validated['status'] ?? Lead::STATUS_NEW;
        $lead->source = $validated['source'] ?? 'manual';
        $lead->lat = $validated['lat'] ?? null;
        $lead->lng = $validated['lng'] ?? null;
        $lead->last_seen = now();
        $lead->save();
        $this->bumpTenantCacheVersion((int) $tenantId);
        
        return response()->json([
            'status' => 'ok',
            'message' => 'Lead berhasil ditambahkan',
            'data' => $lead,
        ]);
    }

    /**
     * Update lead.
     */
    public function update(Request $request, $id)
    {
        $tenantId = $request->attributes->get('tenant_id', 1);
        $lead = Lead::forTenant($tenantId)->findOrFail($id);
        
        $validated = $request->validate([
            'customer_name' => 'sometimes|string|max:255',
            'customer_phone' => 'sometimes|string|max:50',
            'customer_address' => 'nullable|string',
            'notes' => 'nullable|string',
            'status' => 'nullable|string|max:50',
            'source' => 'nullable|string|max:100',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
        ]);
        
        if (isset($validated['customer_name'])) {
            $lead->customer_name = $validated['customer_name'];
        }
        if (isset($validated['customer_phone'])) {
            $lead->customer_phone = Lead::normalizePhone($validated['customer_phone']);
        }
        if (array_key_exists('customer_address', $validated)) {
            $lead->customer_address = $validated['customer_address'] ?? '';
        }
        if (array_key_exists('notes', $validated)) {
            $lead->notes = $validated['notes'] ?? '';
        }
        if (isset($validated['status'])) {
            $lead->status = $validated['status'];
        }
        if (isset($validated['source'])) {
            $lead->source = $validated['source'];
        }
        if (isset($validated['lat'])) {
            $lead->lat = $validated['lat'];
        }
        if (isset($validated['lng'])) {
            $lead->lng = $validated['lng'];
        }
        
        $lead->save();
        $this->bumpTenantCacheVersion((int) $tenantId);
        
        return response()->json([
            'status' => 'ok',
            'message' => 'Lead berhasil diupdate',
            'data' => $lead,
        ]);
    }

    /**
     * Delete lead.
     */
    public function destroy(Request $request, $id)
    {
        $tenantId = $request->attributes->get('tenant_id', 1);
        $lead = Lead::forTenant($tenantId)->findOrFail($id);
        
        $lead->delete();
        $this->bumpTenantCacheVersion((int) $tenantId);
        
        return response()->json([
            'status' => 'ok',
            'message' => 'Lead berhasil dihapus',
        ]);
    }

    /**
     * Convert lead to installation (create pasang baru).
     */
    public function convert(Request $request, $id)
    {
        $tenantId = $request->attributes->get('tenant_id', 1);
        $lead = Lead::forTenant($tenantId)->findOrFail($id);
        
        // Mark as converted
        $lead->status = Lead::STATUS_CONVERTED;
        $lead->save();
        $this->bumpTenantCacheVersion((int) $tenantId);
        
        // Create installation record
        $installation = \App\Models\Installation::create([
            'tenant_id' => $tenantId,
            'nama_pelanggan' => $lead->customer_name,
            'no_hp' => $lead->customer_phone,
            'alamat' => $lead->customer_address,
            'catatan' => $lead->notes,
            'status' => 'Pending',
            'created_at' => now(),
        ]);
        
        return response()->json([
            'status' => 'ok',
            'message' => 'Lead berhasil dikonversi ke pasang baru',
            'installation_id' => $installation->id,
        ]);
    }

    /**
     * Get available statuses.
     */
    public function statuses()
    {
        try {
            $payload = Cache::remember($this->statusesCacheKey(), now()->addHours(12), function () {
                return [
                    'status' => 'ok',
                    'data' => Lead::getStatuses(),
                ];
            });
            return response()->json($payload);
        } catch (\Throwable) {
            return response()->json([
                'status' => 'ok',
                'data' => Lead::getStatuses(),
            ]);
        }
    }

    /**
     * Bulk update status.
     */
    public function bulkUpdateStatus(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id', 1);
        
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'status' => 'required|string',
        ]);
        
        $updated = Lead::forTenant($tenantId)
            ->whereIn('id', $validated['ids'])
            ->update(['status' => $validated['status']]);
        $this->bumpTenantCacheVersion((int) $tenantId);
        
        return response()->json([
            'status' => 'ok',
            'message' => "{$updated} leads berhasil diupdate",
            'count' => $updated,
        ]);
    }
}
