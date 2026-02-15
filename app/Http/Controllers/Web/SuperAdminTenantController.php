<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\TenantFeatureCatalog;
use Inertia\Inertia;
use Inertia\Response;

class SuperAdminTenantController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('SuperAdmin/Tenants', [
            'featureCatalog' => TenantFeatureCatalog::catalog(),
        ]);
    }
}
