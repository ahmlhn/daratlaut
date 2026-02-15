<?php

namespace App\Console\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait ResolvesActiveTenants
{
    protected function resolveActiveTenantIds(?string $tenantOption = null): array
    {
        $fromOption = $this->parseTenantOption($tenantOption);
        if (!empty($fromOption)) {
            return $fromOption;
        }

        $tenantIds = [];

        if (Schema::hasTable('tenants')) {
            $query = DB::table('tenants');
            if (Schema::hasColumn('tenants', 'status')) {
                $query->where('status', 'active');
            }
            $tenantIds = $query->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        if (empty($tenantIds) && Schema::hasTable('noci_installations')) {
            $tenantIds = DB::table('noci_installations')
                ->whereNotNull('tenant_id')
                ->where('tenant_id', '>', 0)
                ->distinct()
                ->pluck('tenant_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $tenantIds = array_values(array_unique(array_filter($tenantIds, fn ($v) => (int) $v > 0)));
        sort($tenantIds);
        return $tenantIds;
    }

    protected function parseTenantOption(?string $tenantOption): array
    {
        $tenantOption = trim((string) $tenantOption);
        if ($tenantOption === '') return [];

        $parts = array_filter(array_map('trim', explode(',', $tenantOption)), fn ($v) => $v !== '');
        if (empty($parts)) return [];

        $ids = [];
        foreach ($parts as $part) {
            $id = (int) $part;
            if ($id > 0) $ids[] = $id;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }
}

