<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function index(Request $request): Response
    {
        // Used to cache-bust legacy chat scripts without disabling browser cache.
        $legacyChatVersion = 0;
        foreach (['legacy-chat/inline.js', 'legacy-chat/app.js', 'legacy-chat/game.js'] as $rel) {
            $path = public_path($rel);
            if (is_file($path)) {
                $legacyChatVersion = max($legacyChatVersion, (int) @filemtime($path));
            }
        }
        if ($legacyChatVersion <= 0) $legacyChatVersion = time();

        return Inertia::render('Chat/Index', [
            'layoutOptions' => [
                'fullBleed' => true,
            ],
            'legacyChatVersion' => $legacyChatVersion,
            'initialFilters' => [
                'q' => $request->input('q', ''),
                'filter' => $request->input('filter', 'all'),
                'visit_id' => $request->input('visit_id', ''),
            ],
        ]);
    }
}
