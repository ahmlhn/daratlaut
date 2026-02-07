<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class PlanController extends Controller
{
    public function index()
    {
        return Inertia::render('Plans/Index', [
            'tenantId' => session('tenant_id', 1),
        ]);
    }
}
