<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FoCable extends Model
{
    protected $table = 'noci_fo_cables';

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'cable_type',
        'core_count',
        'map_color',
        'from_point_id',
        'to_point_id',
        'path',
        'length_m',
        'reserved_cores',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'core_count' => 'integer',
        'from_point_id' => 'integer',
        'to_point_id' => 'integer',
        'path' => 'array',
        'length_m' => 'integer',
        'reserved_cores' => 'array',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
