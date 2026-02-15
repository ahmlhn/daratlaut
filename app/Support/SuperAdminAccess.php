<?php

namespace App\Support;

final class SuperAdminAccess
{
    private static function normalizeRole(?string $role): string
    {
        $role = strtolower(trim((string) $role));
        if ($role === 'svp lapangan') return 'svp_lapangan';
        return $role;
    }

    public static function hasAccess(mixed $user): bool
    {
        if (!$user) return false;

        $legacyRole = self::normalizeRole($user->role ?? null);
        $allowedLegacy = in_array($legacyRole, ['superadmin', 'owner'], true);

        $allowedPermission =
            method_exists($user, 'can')
            && ($user->can('manage tenants') || $user->can('manage tenant features'));

        return $allowedLegacy || $allowedPermission;
    }
}
