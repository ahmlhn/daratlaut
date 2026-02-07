<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MapsController extends Controller
{
    /**
     * Maps teknisi - live tracking
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Maps/Index', [
            'initialView' => [
                'lat' => $request->input('lat', -6.2088),
                'lng' => $request->input('lng', 106.8456),
                'zoom' => $request->input('zoom', 12),
            ],
        ]);
    }
}
