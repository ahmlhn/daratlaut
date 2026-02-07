<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class OltOnu extends Model
{
    protected $table = 'noci_olt_onu';

    // Table only has updated_at, no created_at
    const CREATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'olt_id',
        'fsp',
        'onu_id',
        'sn',
        'onu_name',
        'online_duration',
        'registered_at',
        'last_detail_at',
        'last_seen_at',
        'vlan',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'olt_id' => 'integer',
        'onu_id' => 'integer',
        'vlan' => 'integer',
        'registered_at' => 'datetime',
        'last_detail_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Scopes
    public function scopeForTenant($query, int $tenantId)
    {
        static $hasTenantId = null;
        if ($hasTenantId === null) {
            $hasTenantId = Schema::hasColumn($query->getModel()->getTable(), 'tenant_id');
        }

        if (!$hasTenantId) {
            return $query;
        }

        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForOlt($query, int $oltId)
    {
        return $query->where('olt_id', $oltId);
    }

    public function scopeForFsp($query, string $fsp)
    {
        return $query->where('fsp', $fsp);
    }

    // Relationships
    public function olt(): BelongsTo
    {
        return $this->belongsTo(Olt::class, 'olt_id');
    }

    // Helpers
    // Keep legacy-friendly aliases for older Laravel code / UI.
    public function getNameAttribute(): ?string
    {
        return $this->onu_name;
    }

    public function setNameAttribute(?string $value): void
    {
        $this->onu_name = $value;
    }

    public function getInterfaceName(): string
    {
        return "gpon-onu_{$this->fsp}:{$this->onu_id}";
    }

    public function getPortInterface(): string
    {
        return "gpon-olt_{$this->fsp}";
    }
}
