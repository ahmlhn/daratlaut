<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class FinanceController extends Controller
{
    /**
     * Display finance page.
     */
    public function index()
    {
        return Inertia::render('Finance/Index');
    }
}
