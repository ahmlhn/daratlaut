<?php

namespace App\Http\Controllers\Web;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController
{
    public function index(Request $request): Response
    {
        return Inertia::render('Dashboard/Index', [
            'tenantId' => (int) $request->attributes->get('tenant_id', 0),
        ]);
    }
}
