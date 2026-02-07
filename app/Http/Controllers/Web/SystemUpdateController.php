<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\SystemUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SystemUpdateController extends Controller
{
    private function assertEnabled(): void
    {
        abort_unless((bool) config('system_update.enabled', false), 404);
    }

    private function assertAllowed(Request $request): void
    {
        $allowed = config('system_update.allow_roles', ['owner', 'admin']);
        if (!is_array($allowed)) $allowed = ['owner', 'admin'];
        $allowed = array_map(fn ($r) => strtolower(trim((string) $r)), $allowed);

        $role = strtolower(trim((string) ($request->user()?->role ?? session('level', ''))));
        abort_unless($role !== '' && in_array($role, $allowed, true), 403);
    }

    public function index(Request $request): Response
    {
        $this->assertEnabled();
        $this->assertAllowed($request);

        return Inertia::render('Settings/SystemUpdate');
    }

    public function status(Request $request, SystemUpdateService $svc): JsonResponse
    {
        $this->assertEnabled();
        $this->assertAllowed($request);

        return response()->json(['status' => 'success', 'data' => $svc->status()]);
    }

    public function upload(Request $request, SystemUpdateService $svc): JsonResponse
    {
        $this->assertEnabled();
        $this->assertAllowed($request);

        $maxMb = max(1, (int) config('system_update.max_package_mb', 300));

        $validated = $request->validate([
            'package' => ['required', 'file', 'mimes:zip', 'max:' . ($maxMb * 1024)],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['package'];
        $state = $svc->upload($file);

        return response()->json(['status' => 'success', 'data' => $state]);
    }

    public function download(Request $request, SystemUpdateService $svc): JsonResponse
    {
        $this->assertEnabled();
        $this->assertAllowed($request);

        $state = $svc->downloadConfiguredPackage();
        return response()->json(['status' => 'success', 'data' => $state]);
    }

    public function start(Request $request, SystemUpdateService $svc): JsonResponse
    {
        $this->assertEnabled();
        $this->assertAllowed($request);

        $state = $svc->start();
        return response()->json(['status' => 'success', 'data' => $state]);
    }

    public function step(Request $request, SystemUpdateService $svc): JsonResponse
    {
        $this->assertEnabled();
        $this->assertAllowed($request);

        $state = $svc->step();
        return response()->json(['status' => 'success', 'data' => $state]);
    }

    public function reset(Request $request, SystemUpdateService $svc): JsonResponse
    {
        $this->assertEnabled();
        $this->assertAllowed($request);

        $state = $svc->reset();
        return response()->json(['status' => 'success', 'data' => $state]);
    }

    public function githubCheck(Request $request, SystemUpdateService $svc): JsonResponse
    {
        $this->assertEnabled();
        $this->assertAllowed($request);

        return response()->json(['status' => 'success', 'data' => $svc->githubCheckLatest()]);
    }

    public function githubDownload(Request $request, SystemUpdateService $svc): JsonResponse
    {
        $this->assertEnabled();
        $this->assertAllowed($request);

        $state = $svc->githubDownloadLatest();
        return response()->json(['status' => 'success', 'data' => $state]);
    }

    public function githubSaveToken(Request $request, SystemUpdateService $svc): JsonResponse
    {
        $this->assertEnabled();
        $this->assertAllowed($request);

        $validated = $request->validate([
            'token' => ['required', 'string', 'min:10'],
        ]);

        $state = $svc->githubSaveToken((string) $validated['token']);
        return response()->json(['status' => 'success', 'data' => $state]);
    }

    public function githubClearToken(Request $request, SystemUpdateService $svc): JsonResponse
    {
        $this->assertEnabled();
        $this->assertAllowed($request);

        $state = $svc->githubClearToken();
        return response()->json(['status' => 'success', 'data' => $state]);
    }
}
