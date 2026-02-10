<?php

namespace App\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class FiberController
{
    public function index(Request $request): Response
    {
        $tenantId = (int) $request->attributes->get('tenant_id', 0);

        $warning = null;
        if (!Schema::hasTable('noci_fo_cables') || !Schema::hasTable('noci_fo_points') || !Schema::hasTable('noci_fo_breaks')) {
            $warning = 'Tabel Fiber Optik belum ada. Jalankan migration: php artisan migrate';
        }

        return Inertia::render('Fiber/Index', [
            'tenantId' => $tenantId,
            'warning' => $warning,
        ]);
    }
}

