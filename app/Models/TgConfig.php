<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TgConfig extends Model
{
    protected $table = 'noci_conf_tg';
    
    protected $fillable = [
        'tenant_id',
        'bot_token',
        'chat_id',
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
