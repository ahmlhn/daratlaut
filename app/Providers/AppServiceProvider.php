<?php

namespace App\Providers;

use App\Models\NociUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Pulse\Facades\Pulse;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('viewPulse', function (?NociUser $user): bool {
            if (!$user) {
                return false;
            }

            $legacyRole = strtolower(trim((string) ($user->role ?? '')));
            if ($legacyRole === 'svp lapangan') {
                $legacyRole = 'svp_lapangan';
            }

            if (in_array($legacyRole, ['admin', 'owner'], true)) {
                return true;
            }

            return method_exists($user, 'can')
                && ($user->can('manage settings') || $user->can('manage roles'));
        });

        Pulse::user(fn (NociUser $user): array => [
            'name' => trim((string) ($user->name ?: $user->username ?: 'User')),
            'extra' => trim((string) ($user->email ?: $user->username ?: $user->phone ?: '')),
        ]);
    }
}
