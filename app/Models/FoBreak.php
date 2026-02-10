<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FoBreak extends Model
{
    protected $table = 'noci_fo_breaks';

    protected $fillable = [
        'tenant_id',
        'cable_id',
        'point_id',
        'status',
        'severity',
        'reported_at',
        'fixed_at',
        'latitude',
        'longitude',
        'description',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'cable_id' => 'integer',
        'point_id' => 'integer',
        'reported_at' => 'datetime',
        'fixed_at' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}

