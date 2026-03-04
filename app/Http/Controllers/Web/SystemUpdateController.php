<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\SystemUpdateService;
use App\Support\SystemUpdateToggle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SystemUpdateController extends Controller
{
    private function normalizeRole(?string $role): string
    {
        $role = strtolower(trim((string) $role));
        if ($role === 'svp lapangan') {
            return 'svp_lapangan';
        }

        return $role;
    }

    private function assertEnabled(): void
    {
        abort_unless(SystemUpdateToggle::enabled(), 404);
    }

    private function assertAllowed(Request $request): void
    {
        $user = $request->user();
        $allowed = config('system_update.allow_roles', ['owner', 'admin']);
        if (!is_array($allowed)) $allowed = ['owner', 'admin'];
        $allowed = array_map(fn ($r) => $this->normalizeRole((string) $r), $allowed);

        $role = $this->normalizeRole((string) ($user?->role ?? session('level', '')));
        $allowedLegacy = $role !== '' && in_array($role, $allowed, true);
        $allowedPermission = $user
            && method_exists($user, 'can')
            && (
                $user->can('manage system update')
                || $user->can('manage settings')
                || $user->can('manage roles')
            );

        abort_unless($allowedLegacy || $allowedPermission, 403);
    }

    private function jsonError(SystemUpdateService $svc, string $context, \Throwable $e): JsonResponse
    {
        // Preserve expected HTTP errors (abort/validation) behavior.
        if ($e instanceof HttpException) {
            throw $e;
        }
        if ($e instanceof ValidationException) {
            throw $e;
        }

        $svc->logError($context, $e);
        report($e);

        $msg = trim((string) $e->getMessage());
        if ($msg === '') $msg = 'Server Error';

        return response()->json(['status' => 'error', 'message' => $msg], 422);
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

        try {
            return response()->json(['status' => 'success', 'data' => $svc->status()]);
        } catch (\Throwable $e) {
            return $this->jsonError($svc, 'status', $e);
        }
    }

    public function upload(Request $request, SystemUpdateService $svc): JsonResponse
    {
        $this->assertEnabled();
        $this->assertAllowed($request);

        try {
            $maxMb = max(1, (int) config('system_update.max_package_mb', 300));

            $validated = $request->validate([
                'package' => ['required', 'file', 'mimes:zip', 'max:' . ($maxMb * 1024)],
            ]);

            /** @var \Illuminate\Http\UploadedFile $file */
            $file = $validated['package'];
            $state = $svc->upload($file);

            return response()->json(['status' => 'success', 'data' => $state]);
        } catch (\Throwable $e) {
            return $this->jsonError($svc, 'upload', $e);
        }
    }

    public function download(Request $request, SystemUpdateService $svc): JsonResponse
    {
        $this->assertEnabled();
        $this->assertAllowed($request);

        try {
            $state = $svc->downloadConfiguredPackage();
            return response()->json(['status' => 'success', 'data' => $state]);
        } catch (\Throwable $e) {
            return $this->jsonError($svc, 'download', $e);
        }
    }

    public function start(Request $request, SystemUpdateService $svc): JsonResponse
    {
        $this->assertEnabled();
        $this->assertAllowed($request);

        try {
            $state = $svc->start();
            return response()->json(['status' => 'success', 'data' => $state]);
        } catch (\Throwable $e) {
            return $this->jsonError($svc, 'start', $e);
        }
    }

    public function step(Request $request, SystemUpdateService $svc): JsonResponse
    {
        $this->assertEnabled();
        $this->assertAllowed($request);

        try {
            $state = $svc->step();
            return response()->json(['status' => 'success', 'data' => $state]);
        } catch (\Throwable $e) {
            return $this->jsonError($svc, 'step', $e);
        }
    }

    public function reset(Request $request, SystemUpdateService $svc): JsonResponse
    {
        $this->assertEnabled();
        $this->assertAllowed($request);

        try {
            $state = $svc->reset();
            return response()->json(['status' => 'success', 'data' => $state]);
        } catch (\Throwable $e) {
            return $this->jsonError($svc, 'reset', $e);
        }
    }

    public function githubCheck(Request $request, SystemUpdateService $svc): JsonResponse
    {
        $this->assertEnabled();
        $this->assertAllowed($request);

        try {
            return response()->json(['status' => 'success', 'data' => $svc->githubCheckBuiltLatest()]);
        } catch (\Throwable $e) {
            return $this->jsonError($svc, 'github_check', $e);
        }
    }

    public function githubDownload(Request $request, SystemUpdateService $svc): JsonResponse
    {
        $this->assertEnabled();
        $this->assertAllowed($request);

        try {
            $state = $svc->githubDownloadBuiltLatest();
            return response()->json(['status' => 'success', 'data' => $state]);
        } catch (\Throwable $e) {
            return $this->jsonError($svc, 'github_download', $e);
        }
    }

    public function githubSaveToken(Request $request, SystemUpdateService $svc): JsonResponse
    {
        $this->assertEnabled();
        $this->assertAllowed($request);

        try {
            $validated = $request->validate([
                'token' => ['required', 'string', 'min:10'],
            ]);

            $state = $svc->githubSaveToken((string) $validated['token']);
            return response()->json(['status' => 'success', 'data' => $state]);
        } catch (\Throwable $e) {
            return $this->jsonError($svc, 'github_token_save', $e);
        }
    }

    public function githubClearToken(Request $request, SystemUpdateService $svc): JsonResponse
    {
        $this->assertEnabled();
        $this->assertAllowed($request);

        try {
            $state = $svc->githubClearToken();
            return response()->json(['status' => 'success', 'data' => $state]);
        } catch (\Throwable $e) {
            return $this->jsonError($svc, 'github_token_clear', $e);
        }
    }
}
