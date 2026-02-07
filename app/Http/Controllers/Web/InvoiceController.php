<?php

namespace App\Http\Controllers\Web;

use Inertia\Inertia;
use Inertia\Response;

class InvoiceController
{
    public function index(): Response
    {
        return Inertia::render('Invoices/Index');
    }
}
