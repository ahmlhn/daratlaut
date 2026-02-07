<?php

namespace App\Http\Controllers;

use App\Models\LegacyUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    /**
     * Show login page.
     */
    public function showLogin(): Response
    {
        return Inertia::render('Auth/Login');
    }

    /**
     * Handle login attempt - compatible with native PHP noci_users table.
     */
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $username = trim($request->input('username'));
        $password = $request->input('password');

        $user = LegacyUser::query()
            ->where('username', $username)
            ->where('status', 'active')
            ->first();

        if (!$user) {
            return back()->withErrors([
                'username' => 'Username tidak ditemukan atau akun non-aktif.',
            ])->onlyInput('username');
        }

        $storedPassword = $user->password ?? '';
        $isValid = false;
        $needsRehash = false;

        if ($storedPassword !== '') {
            if (password_verify($password, $storedPassword)) {
                $isValid = true;
                $needsRehash = password_needs_rehash($storedPassword, PASSWORD_DEFAULT);
            } elseif (hash_equals($storedPassword, $password)) {
                $isValid = true;
                $needsRehash = true;
            }
        }

        if (!$isValid) {
            return back()->withErrors([
                'password' => 'Password salah!',
            ])->onlyInput('username');
        }

        Auth::login($user);

        $displayName = $user->username;
        if (!empty($user->name)) $displayName = $user->name;
        if (!empty($user->fullname)) $displayName = $user->fullname;

        $role = strtolower($user->role ?? 'cs');
        $isTeknisi = in_array($role, ['teknisi', 'svp lapangan'], true);

        session([
            'tenant_id' => $user->tenant_id ?? 0,
            'user_id' => $user->id,
            'level' => $user->role ?? 'cs',
            'admin_name' => $displayName,
            'admin_username' => $user->username,
            'is_teknisi' => $isTeknisi,
        ]);

        if ($isTeknisi) {
            session([
                'teknisi_logged_in' => true,
                'teknisi_id' => $user->id,
                'teknisi_name' => $user->name ?? $displayName,
                'teknisi_role' => $user->role ?? 'teknisi',
                'teknisi_pop' => $user->default_pop ?? '',
            ]);
        } else {
            session([
                'is_logged_in' => true,
                'logged_in' => true,
                'admin_id' => $user->id,
            ]);
        }

        $user->last_login = now();

        if ($needsRehash) {
            $user->password = Hash::make($password);
        }

        $user->saveQuietly();

        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    /**
     * Handle logout.
     */
    public function logout(Request $request)
    {
        Auth::logout();

        // Clear all session data
        $request->session()->flush();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    /**
     * Get authenticated user info.
     */
    public function user(Request $request)
    {
        $user = Auth::user();

        if (!$user && session('user_id')) {
            $user = LegacyUser::find(session('user_id'));
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Not authenticated',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'username' => $user->username,
                'name' => $user->name ?? $user->username,
                'role' => $user->role ?? 'cs',
                'tenant_id' => $user->tenant_id ?? 0,
            ],
        ]);
    }
}
