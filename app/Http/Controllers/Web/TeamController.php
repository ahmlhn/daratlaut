<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    /**
     * Show team list page
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Team/Index', [
            'initialFilters' => [
                'q' => $request->input('q', ''),
                'role' => $request->input('role', ''),
                'is_active' => $request->input('is_active', ''),
            ],
        ]);
    }
}
