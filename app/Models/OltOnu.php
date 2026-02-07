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
        'onu_id' => 'integer',
        'rx_power' => 'decimal:2',
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

    public function scopeOnline($query)
    {
        return $query->whereIn('state', ['ready', 'working']);
    }

    public function scopeOffline($query)
    {
        return $query->whereNotIn('state', ['ready', 'working']);
    }

    // Relationships
    public function olt(): BelongsTo
    {
        return $this->belongsTo(Olt::class, 'olt_id');
    }

    // Helpers
    public function isOnline(): bool
    {
        return in_array($this->state, ['ready', 'working']);
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
