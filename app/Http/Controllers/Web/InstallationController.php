<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InstallationController extends Controller
{
    /**
     * Show installations list page
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Installations/Index', [
            'initialFilters' => [
                'q' => $request->input('q', ''),
                'status' => $request->input('status', ''),
                'pop' => $request->input('pop', ''),
                'technician' => $request->input('technician', ''),
                'date_from' => $request->input('date_from', ''),
                'date_to' => $request->input('date_to', ''),
            ],
        ]);
    }

    /**
     * Show installation history page
     */
    public function riwayat(Request $request): Response
    {
        return Inertia::render('Installations/Riwayat', [
            'initialFilters' => [
                'q' => $request->input('q', ''),
                'status' => $request->input('status', ''),
                'date_from' => $request->input('date_from', ''),
                'date_to' => $request->input('date_to', ''),
            ],
        ]);
    }
}
