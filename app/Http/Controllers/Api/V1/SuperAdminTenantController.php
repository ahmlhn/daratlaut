<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\TenantFeatureCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class SuperAdminTenantController extends Controller
{
    public function index(): JsonResponse
    {
        if (!Schema::hasTable('tenants')) {
            return response()->json([
                'message' => 'Tabel tenants tidak ditemukan.',
                'data' => [],
                'feature_catalog' => TenantFeatureCatalog::catalog(),
            ], 500);
        }

        $tenants = DB::table('tenants')
            ->orderBy('id')
            ->get(['id', 'slug', 'name', 'public_token', 'status', 'created_at']);

        $featureStates = TenantFeatureCatalog::statesForTenants($tenants->pluck('id')->all());

        $data = $tenants->map(static function ($tenant) use ($featureStates) {
            $tenantId = (int) ($tenant->id ?? 0);

            return [
                'id' => $tenantId,
                'slug' => (string) ($tenant->slug ?? ''),
                'name' => (string) ($tenant->name ?? ''),
                'public_token' => (string) ($tenant->public_token ?? ''),
                'status' => strtolower((string) ($tenant->status ?? 'active')),
                'created_at' => $tenant->created_at,
                'features' => $featureStates[$tenantId] ?? TenantFeatureCatalog::defaultState(),
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'feature_catalog' => TenantFeatureCatalog::catalog(),
        ]);
    }

    public function updateTenant(Request $request, int $tenantId): JsonResponse
    {
        if (!Schema::hasTable('tenants')) {
            return response()->json(['message' => 'Tabel tenants tidak ditemukan.'], 500);
        }

        $tenantExists = DB::table('tenants')->where('id', $tenantId)->exists();
        if (!$tenantExists) {
            return response()->json(['message' => 'Tenant tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'status' => ['required', 'string', Rule::in(['active', 'inactive'])],
        ]);

        DB::table('tenants')
            ->where('id', $tenantId)
            ->update([
                'name' => trim((string) $validated['name']),
                'status' => strtolower(trim((string) $validated['status'])),
            ]);

        $tenant = DB::table('tenants')
            ->where('id', $tenantId)
            ->first(['id', 'slug', 'name', 'public_token', 'status', 'created_at']);

        return response()->json([
            'message' => 'Tenant berhasil diperbarui.',
            'data' => [
                'id' => (int) ($tenant->id ?? $tenantId),
                'slug' => (string) ($tenant->slug ?? ''),
                'name' => (string) ($tenant->name ?? ''),
                'public_token' => (string) ($tenant->public_token ?? ''),
                'status' => strtolower((string) ($tenant->status ?? 'active')),
                'created_at' => $tenant->created_at ?? null,
            ],
        ]);
    }

    public function saveFeatures(Request $request, int $tenantId): JsonResponse
    {
        if (!Schema::hasTable('tenants')) {
            return response()->json(['message' => 'Tabel tenants tidak ditemukan.'], 500);
        }

        if (!Schema::hasTable('tenant_feature_toggles')) {
            return response()->json([
                'message' => 'Tabel tenant_feature_toggles tidak ditemukan. Jalankan migration terbaru.',
            ], 500);
        }

        $tenantExists = DB::table('tenants')->where('id', $tenantId)->exists();
        if (!$tenantExists) {
            return response()->json(['message' => 'Tenant tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'features' => ['required', 'array'],
        ]);

        $features = [];
        $allowed = array_flip(TenantFeatureCatalog::keys());

        foreach (($validated['features'] ?? []) as $key => $enabled) {
            $featureKey = (string) $key;
            if (!isset($allowed[$featureKey])) continue;
            $features[$featureKey] = (bool) $enabled;
        }

        if (empty($features)) {
            return response()->json([
                'message' => 'Tidak ada fitur valid yang dikirim.',
            ], 422);
        }

        TenantFeatureCatalog::saveTenantState(
            $tenantId,
            $features,
            (int) ($request->user()?->id ?? 0) ?: null
        );

        return response()->json([
            'message' => 'Konfigurasi fitur tenant berhasil disimpan.',
            'data' => [
                'tenant_id' => $tenantId,
                'features' => TenantFeatureCatalog::stateForTenant($tenantId),
            ],
        ]);
    }
}
