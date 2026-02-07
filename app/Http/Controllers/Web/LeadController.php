<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class LeadController extends Controller
{
    /**
     * Display leads page.
     */
    public function index()
    {
        return Inertia::render('Leads/Index');
    }
}
