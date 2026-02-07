<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaConfig extends Model
{
    protected $table = 'noci_conf_wa';
    
    protected $fillable = [
        'tenant_id',
        'api_url',
        'api_key',
        'sender_number',
        'enabled',
        'extra_json',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'extra_json' => 'json',
    ];

    public $timestamps = false;

    // Scopes
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
