<?php

namespace App\Http\Controllers\Web;

use App\Support\SuperAdminAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController
{
    public function index(Request $request)
    {
        if (SuperAdminAccess::hasAccess($request->user())) {
            return redirect()->route('superadmin.index');
        }

        $role = strtolower(trim((string) ($request->user()?->role ?? session('level', ''))));
        if ($role === 'svp lapangan') $role = 'svp_lapangan';

        // Teknisi accounts should land on the Teknisi module (not the admin dashboard).
        if (in_array($role, ['teknisi', 'svp_lapangan'], true)) {
            return redirect()->route('teknisi.index');
        }

        return Inertia::render('Dashboard/Index', [
            'tenantId' => (int) $request->attributes->get('tenant_id', 0),
        ]);
    }
}
