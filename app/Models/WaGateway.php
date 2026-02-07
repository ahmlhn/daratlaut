<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaGateway extends Model
{
    protected $table = 'noci_wa_gateways';

    // Table only has created_at, no updated_at
    const UPDATED_AT = null;
    
    protected $fillable = [
        'code',
        'name',
        'supports_personal',
        'supports_group',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'supports_personal' => 'boolean',
        'supports_group' => 'boolean',
    ];

    // Gateway codes
    const GATEWAY_BALESOTOMATIS = 'balesotomatis';
    const GATEWAY_MPWA = 'mpwa';

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Relations
    public function tenantConfigs()
    {
        return $this->hasMany(WaTenantGateway::class, 'gateway_id');
    }
}
