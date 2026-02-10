<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FoPoint extends Model
{
    protected $table = 'noci_fo_points';

    protected $fillable = [
        'tenant_id',
        'name',
        'point_type',
        'latitude',
        'longitude',
        'address',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
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

