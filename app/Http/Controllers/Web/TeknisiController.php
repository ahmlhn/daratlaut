<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeknisiController extends Controller
{
    private function normalizeLegacyRole(?string $role): string
    {
        $role = strtolower(trim((string) $role));
        if ($role === 'svp lapangan') return 'svp_lapangan';
        return $role;
    }

    private function userCan(Request $request, string $permission): bool
    {
        $user = $request->user();
        if (!$user) return false;

        $legacyRole = $this->normalizeLegacyRole($user->role ?? null);
        if (in_array($legacyRole, ['admin', 'owner'], true)) return true;

        return method_exists($user, 'can') && $user->can($permission);
    }

    private function ensureViewPermission(Request $request): void
    {
        abort_unless($this->userCan($request, 'view teknisi'), 403);
    }

    /**
     * Teknisi dashboard - list tasks
     */
    public function index(Request $request): Response
    {
        $this->ensureViewPermission($request);

        $techName = session('admin_name', session('teknisi_name', 'Teknisi'));
        $techPop = session('teknisi_pop', '');
        $techRole = strtolower(session('level', session('teknisi_role', 'teknisi')));

        return Inertia::render('Teknisi/Index', [
            'techName' => $techName,
            'techPop' => $techPop,
            'techRole' => $techRole,
            'initialFilters' => [
                'tab' => $request->input('tab', 'all'),
                'pop' => $request->input('pop', ''),
                'status' => $request->input('status', ''),
                'q' => $request->input('q', ''),
            ],
        ]);
    }

    /**
     * Teknisi riwayat - work history
     */
    public function riwayat(Request $request): Response
    {
        $this->ensureViewPermission($request);

        $techName = session('admin_name', session('teknisi_name', 'Teknisi'));
        $techRole = strtolower(session('level', session('teknisi_role', 'teknisi')));

        return Inertia::render('Teknisi/Riwayat', [
            'techName' => $techName,
            'techRole' => $techRole,
            'initialFilters' => [
                'date_from' => $request->input('date_from', ''),
                'date_to' => $request->input('date_to', ''),
                'status' => $request->input('status', 'Selesai'),
            ],
        ]);
    }

    /**
     * Teknisi rekap - daily summary
     */
    public function rekap(Request $request): Response
    {
        $this->ensureViewPermission($request);

        $techName = session('admin_name', session('teknisi_name', 'Teknisi'));
        $techPop = session('teknisi_pop', '');
        $techRole = strtolower(session('level', session('teknisi_role', 'teknisi')));

        return Inertia::render('Teknisi/Rekap', [
            'techName' => $techName,
            'techPop' => $techPop,
            'techRole' => $techRole,
            'initialDate' => $request->input('date', date('Y-m-d')),
        ]);
    }
}
