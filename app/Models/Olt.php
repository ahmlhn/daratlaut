<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Olt extends Model
{
    protected $table = 'noci_olts';

    // Table only has created_at, no updated_at
    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'nama_olt',
        'host',
        'port',
        'username',
        'password',
        'tcont_default',
        'vlan_default',
        'onu_type_default',
        'service_port_id_default',
        'fsp_cache',
        'fsp_cache_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'fsp_cache' => 'array',
        'fsp_cache_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    // Scopes
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Relationships
    public function onus(): HasMany
    {
        return $this->hasMany(OltOnu::class, 'olt_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(OltLog::class, 'olt_id');
    }

    // Helpers
    public function isFspCacheValid(): bool
    {
        if (!$this->fsp_cache_at) {
            return false;
        }
        // Cache valid for 24 hours
        return $this->fsp_cache_at->diffInSeconds(now()) < 86400;
    }

    public function updateFspCache(array $fspList): void
    {
        $this->update([
            'fsp_cache' => $fspList,
            'fsp_cache_at' => now(),
        ]);
    }

    public function clearCache(): void
    {
        $this->update([
            'fsp_cache' => null,
            'fsp_cache_at' => null,
        ]);
        $this->onus()->delete();
    }
}
