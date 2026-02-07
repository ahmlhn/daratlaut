<?php

namespace App\Http\Controllers\Web;

use Inertia\Inertia;
use Inertia\Response;

class PaymentController
{
    public function index(): Response
    {
        return Inertia::render('Payments/Index');
    }
}
