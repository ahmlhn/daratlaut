<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\SuperAdminAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Inertia\Inertia;

class FinanceController extends Controller
{
    /**
     * Display finance page.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return new Response('Unauthenticated.', 401);
        }

        if (!SuperAdminAccess::hasAccess($user)) {
            $role = strtolower(trim((string) ($user->role ?? '')));
            if ($role === 'svp lapangan') {
                $role = 'svp_lapangan';
            }

            $allowedLegacy = in_array($role, ['admin', 'owner'], true);
            $allowedPermission = method_exists($user, 'can')
                && ($user->can('view finance') || $user->can('manage finance'));

            if (!$allowedLegacy && !$allowedPermission) {
                return new Response('Forbidden', 403);
            }
        }

        return Inertia::render('Finance/Index');
    }
}
