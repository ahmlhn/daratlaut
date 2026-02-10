<?php

namespace App\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class FiberController
{
    public function index(Request $request): Response
    {
        $tenantId = (int) $request->attributes->get('tenant_id', 0);

        $warning = null;
        $requiredTables = [
            'noci_fo_cables',
            'noci_fo_points',
            'noci_fo_breaks',
            'noci_fo_ports',
            'noci_fo_links',
            'noci_conf_maps',
        ];
        $missing = array_values(array_filter($requiredTables, fn ($t) => !Schema::hasTable($t)));
        if (count($missing) > 0) {
            $warning = 'Tabel belum lengkap (' . implode(', ', $missing) . '). Jalankan migration: php artisan migrate';
        }

        $googleMapsApiKey = '';
        try {
            if (Schema::hasTable('noci_conf_maps')) {
                $tid = $tenantId > 0 ? $tenantId : 1;
                $row = DB::table('noci_conf_maps')->where('tenant_id', $tid)->first();
                $googleMapsApiKey = $row->google_maps_api_key ?? '';
            }
        } catch (\Throwable) {
            $googleMapsApiKey = '';
        }

        return Inertia::render('Fiber/Index', [
            'tenantId' => $tenantId,
            'warning' => $warning,
            'googleMapsApiKey' => $googleMapsApiKey,
        ]);
    }
}
