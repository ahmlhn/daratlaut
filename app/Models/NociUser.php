<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

use Spatie\Permission\Traits\HasRoles;

class NociUser extends Authenticatable
{
    use HasApiTokens, Notifiable, HasRoles;

    protected $guard_name = 'web';

    protected $table = 'noci_users';

    // Table only has created_at, no updated_at
    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'username',
        'name',
        'email',
        'phone',
        'password',
        'role',
        'default_pop',
        'status',
        'last_login',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'last_login' => 'datetime',
    ];

    // Role constants
    const ROLE_ADMIN = 'admin';
    const ROLE_TEKNISI = 'teknisi';
    const ROLE_CS = 'cs';
    const ROLE_OWNER = 'owner';
    const ROLE_KEUANGAN = 'keuangan';
    const ROLE_SVP = 'svp_lapangan';

    private static function normalizeLegacyRoleValue(?string $role): string
    {
        $role = strtolower(trim((string) $role));
        if ($role === 'svp lapangan') return self::ROLE_SVP;
        return $role;
    }

    // Scopes
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    // Role checks
    public function isAdmin(): bool
    {
        return self::normalizeLegacyRoleValue($this->role) === self::ROLE_ADMIN;
    }

    public function isTeknisi(): bool
    {
        return self::normalizeLegacyRoleValue($this->role) === self::ROLE_TEKNISI;
    }

    public function isCs(): bool
    {
        return self::normalizeLegacyRoleValue($this->role) === self::ROLE_CS;
    }

    public function isOwner(): bool
    {
        return self::normalizeLegacyRoleValue($this->role) === self::ROLE_OWNER;
    }

    public function isKeuangan(): bool
    {
        return self::normalizeLegacyRoleValue($this->role) === self::ROLE_KEUANGAN;
    }

    public function isSvp(): bool
    {
        return self::normalizeLegacyRoleValue($this->role) === self::ROLE_SVP;
    }

    public function hasLegacyRole(string|array $roles): bool
    {
        $current = self::normalizeLegacyRoleValue($this->role);

        if (is_string($roles)) {
            return $current === self::normalizeLegacyRoleValue($roles);
        }

        $normalized = array_map(fn ($r) => self::normalizeLegacyRoleValue(is_string($r) ? $r : ''), $roles);
        return in_array($current, $normalized, true);
    }

    public function canManageTeam(): bool
    {
        return $this->hasLegacyRole([self::ROLE_ADMIN, self::ROLE_OWNER]);
    }

    public function canManageFinance(): bool
    {
        return $this->hasLegacyRole([self::ROLE_ADMIN, self::ROLE_OWNER, self::ROLE_KEUANGAN]);
    }

    public function canManageOlt(): bool
    {
        return $this->hasLegacyRole([self::ROLE_ADMIN, self::ROLE_OWNER, self::ROLE_CS, self::ROLE_TEKNISI]);
    }

    // Relationships
    public function installations(): HasMany
    {
        return $this->hasMany(Installation::class, 'created_by');
    }

    // Update last login
    public function recordLogin(): void
    {
        $this->update(['last_login' => now()]);
    }
}
