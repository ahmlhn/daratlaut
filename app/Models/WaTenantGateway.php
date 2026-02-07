<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaTenantGateway extends Model
{
    protected $table = 'noci_wa_tenant_gateways';
    
    protected $fillable = [
        'tenant_id',
        'gateway_id',
        'api_key',
        'sender_number',
        'priority',
        'is_active',
        'extra_json',
    ];

    protected $casts = [
        'priority' => 'integer',
        'is_active' => 'boolean',
        'extra_json' => 'json',
    ];

    public $timestamps = true;

    // Scopes
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByPriority($query)
    {
        return $query->orderBy('priority', 'asc');
    }

    // Relations
    public function gateway()
    {
        return $this->belongsTo(WaGateway::class, 'gateway_id');
    }
}
