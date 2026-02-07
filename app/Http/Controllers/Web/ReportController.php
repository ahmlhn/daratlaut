<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class ReportController extends Controller
{
    /**
     * Display reports page.
     */
    public function index()
    {
        return Inertia::render('Reports/Index');
    }
}
