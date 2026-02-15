<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class TenantFeatureCatalog
{
    private const FEATURES = [
        ['key' => 'dashboard', 'name' => 'Dashboard', 'description' => 'Ringkasan statistik utama tenant.'],
        ['key' => 'chat', 'name' => 'Chat Admin', 'description' => 'Chat admin dan automasi percakapan.'],
        ['key' => 'installations', 'name' => 'Pasang Baru', 'description' => 'Workflow instalasi dan riwayat pasang baru.'],
        ['key' => 'team', 'name' => 'Tim', 'description' => 'Manajemen teknisi, sales, dan anggota tim.'],
        ['key' => 'teknisi', 'name' => 'Modul Teknisi', 'description' => 'Tugas teknisi, riwayat, dan rekap harian.'],
        ['key' => 'maps', 'name' => 'Maps Teknisi', 'description' => 'Tracking lokasi dan peta operasional.'],
        ['key' => 'fiber', 'name' => 'Kabel FO', 'description' => 'Pemetaan kabel fiber, titik, dan insiden putus.'],
        ['key' => 'olts', 'name' => 'OLT', 'description' => 'Manajemen OLT/ONU dan provisioning jaringan.'],
        ['key' => 'customers', 'name' => 'Pelanggan', 'description' => 'Master data pelanggan.'],
        ['key' => 'plans', 'name' => 'Paket Layanan', 'description' => 'Manajemen paket dan produk layanan.'],
        ['key' => 'invoices', 'name' => 'Invoice', 'description' => 'Pembuatan dan pemantauan tagihan.'],
        ['key' => 'payments', 'name' => 'Pembayaran', 'description' => 'Pencatatan pembayaran dan transaksi.'],
        ['key' => 'settings', 'name' => 'Pengaturan', 'description' => 'Konfigurasi sistem tenant dan role.'],
        ['key' => 'finance', 'name' => 'Keuangan', 'description' => 'Modul akuntansi, approval, dan laporan keuangan.'],
        ['key' => 'reports', 'name' => 'Laporan', 'description' => 'Laporan performa, pendapatan, dan ekspor data.'],
    ];

    private const WEB_PATH_PREFIX_MAP = [
        '/dashboard' => 'dashboard',
        '/chat' => 'chat',
        '/installations' => 'installations',
        '/team' => 'team',
        '/teknisi' => 'teknisi',
        '/maps' => 'maps',
        '/fiber' => 'fiber',
        '/olts' => 'olts',
        '/customers' => 'customers',
        '/plans' => 'plans',
        '/invoices' => 'invoices',
        '/payments' => 'payments',
        '/settings' => 'settings',
        '/system-update' => 'settings',
        '/finance' => 'finance',
        '/reports' => 'reports',
    ];

    private const API_PATH_PREFIX_MAP = [
        '/api/v1/dashboard' => 'dashboard',
        '/api/v1/chat' => 'chat',
        '/api/v1/installations' => 'installations',
        '/api/v1/team' => 'team',
        '/api/v1/teknisi' => 'teknisi',
        '/api/v1/maps' => 'maps',
        '/api/v1/fiber' => 'fiber',
        '/api/v1/olts' => 'olts',
        '/api/v1/customers' => 'customers',
        '/api/v1/isolir' => 'customers',
        '/api/v1/plans' => 'plans',
        '/api/v1/invoices' => 'invoices',
        '/api/v1/payments' => 'payments',
        '/api/v1/settings' => 'settings',
        '/api/v1/roles' => 'settings',
        '/api/v1/permissions' => 'settings',
        '/api/v1/finance' => 'finance',
        '/api/v1/reports' => 'reports',
    ];

    private static ?bool $tableExists = null;

    /**
     * In-memory cache keyed by tenant ID for current request lifecycle.
     *
     * @var array<int, array<string, bool>>
     */
    private static array $tenantStateCache = [];

    public static function catalog(): array
    {
        return self::FEATURES;
    }

    public static function keys(): array
    {
        return array_map(static fn (array $f) => (string) $f['key'], self::FEATURES);
    }

    public static function defaultState(): array
    {
        $map = [];
        foreach (self::keys() as $key) {
            $map[$key] = true;
        }
        return $map;
    }

    public static function stateForTenant(int $tenantId): array
    {
        if ($tenantId <= 0) {
            return self::defaultState();
        }

        if (isset(self::$tenantStateCache[$tenantId])) {
            return self::$tenantStateCache[$tenantId];
        }

        $state = self::defaultState();

        if (!self::hasFeatureTable()) {
            self::$tenantStateCache[$tenantId] = $state;
            return $state;
        }

        $rows = DB::table('tenant_feature_toggles')
            ->where('tenant_id', $tenantId)
            ->get(['feature_key', 'is_enabled']);

        if ($rows->isNotEmpty()) {
            $allowedKeys = array_flip(self::keys());
            foreach ($rows as $row) {
                $key = (string) ($row->feature_key ?? '');
                if (!isset($allowedKeys[$key])) continue;
                $state[$key] = (bool) ($row->is_enabled ?? false);
            }
        }

        self::$tenantStateCache[$tenantId] = $state;
        return $state;
    }

    /**
     * @param array<int, int|string> $tenantIds
     * @return array<int, array<string, bool>>
     */
    public static function statesForTenants(array $tenantIds): array
    {
        $normalized = collect($tenantIds)
            ->map(static fn ($id) => (int) $id)
            ->filter(static fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $output = [];
        foreach ($normalized as $tenantId) {
            $output[$tenantId] = self::defaultState();
        }

        if (empty($normalized) || !self::hasFeatureTable()) {
            foreach ($normalized as $tenantId) {
                self::$tenantStateCache[$tenantId] = $output[$tenantId];
            }
            return $output;
        }

        $rows = DB::table('tenant_feature_toggles')
            ->whereIn('tenant_id', $normalized)
            ->get(['tenant_id', 'feature_key', 'is_enabled']);

        $allowedKeys = array_flip(self::keys());

        foreach ($rows as $row) {
            $tenantId = (int) ($row->tenant_id ?? 0);
            $key = (string) ($row->feature_key ?? '');
            if ($tenantId <= 0 || !isset($output[$tenantId]) || !isset($allowedKeys[$key])) continue;
            $output[$tenantId][$key] = (bool) ($row->is_enabled ?? false);
        }

        foreach ($output as $tenantId => $state) {
            self::$tenantStateCache[$tenantId] = $state;
        }

        return $output;
    }

    /**
     * @param array<string, mixed> $states
     */
    public static function saveTenantState(int $tenantId, array $states, ?int $updatedBy = null): void
    {
        if ($tenantId <= 0 || !self::hasFeatureTable()) return;

        $allowedKeys = array_flip(self::keys());
        $normalized = [];

        foreach ($states as $key => $enabled) {
            $featureKey = (string) $key;
            if (!isset($allowedKeys[$featureKey])) continue;
            $normalized[$featureKey] = (bool) $enabled;
        }

        // Guarantee every feature key has a persisted state for deterministic reads.
        foreach (self::keys() as $featureKey) {
            if (!array_key_exists($featureKey, $normalized)) {
                $normalized[$featureKey] = true;
            }
        }

        $now = now();
        $rows = [];
        foreach ($normalized as $featureKey => $enabled) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'feature_key' => $featureKey,
                'is_enabled' => $enabled ? 1 : 0,
                'updated_by' => $updatedBy,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('tenant_feature_toggles')->upsert(
            $rows,
            ['tenant_id', 'feature_key'],
            ['is_enabled', 'updated_by', 'updated_at']
        );

        self::$tenantStateCache[$tenantId] = self::stateFromRows($rows);
    }

    public static function isEnabled(int $tenantId, string $featureKey): bool
    {
        $featureKey = trim($featureKey);
        if ($featureKey === '') return true;

        $state = self::stateForTenant($tenantId);
        return (bool) ($state[$featureKey] ?? true);
    }

    public static function featureForPath(string $path): ?string
    {
        $normalized = '/' . ltrim(trim((string) $path), '/');

        // Superadmin pages and APIs are always excluded from tenant feature checks.
        if (self::pathMatches($normalized, '/superadmin') || self::pathMatches($normalized, '/api/v1/superadmin')) {
            return null;
        }

        $map = str_starts_with($normalized, '/api/v1/')
            ? self::sortedPathMap(self::API_PATH_PREFIX_MAP)
            : self::sortedPathMap(self::WEB_PATH_PREFIX_MAP);

        foreach ($map as $prefix => $featureKey) {
            if (self::pathMatches($normalized, $prefix)) {
                return $featureKey;
            }
        }

        return null;
    }

    private static function hasFeatureTable(): bool
    {
        if (is_bool(self::$tableExists)) {
            return self::$tableExists;
        }

        try {
            self::$tableExists = Schema::hasTable('tenant_feature_toggles');
        } catch (\Throwable) {
            self::$tableExists = false;
        }

        return self::$tableExists;
    }

    /**
     * @param array<string, string> $pathMap
     * @return array<string, string>
     */
    private static function sortedPathMap(array $pathMap): array
    {
        uksort($pathMap, static function (string $a, string $b): int {
            return strlen($b) <=> strlen($a);
        });

        return $pathMap;
    }

    private static function pathMatches(string $path, string $prefix): bool
    {
        if ($path === $prefix) return true;
        return str_starts_with($path, rtrim($prefix, '/') . '/');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, bool>
     */
    private static function stateFromRows(array $rows): array
    {
        $state = self::defaultState();
        $allowedKeys = array_flip(self::keys());

        foreach ($rows as $row) {
            $key = (string) ($row['feature_key'] ?? '');
            if (!isset($allowedKeys[$key])) continue;
            $state[$key] = (bool) ($row['is_enabled'] ?? false);
        }

        return $state;
    }
}
