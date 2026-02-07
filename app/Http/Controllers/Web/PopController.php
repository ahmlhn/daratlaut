<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PopController extends Controller
{
    /**
     * Show POPs list page
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Pops/Index', [
            'initialFilters' => [
                'q' => $request->input('q', ''),
                'is_active' => $request->input('is_active', ''),
            ],
        ]);
    }
}
