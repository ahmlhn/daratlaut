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
        'core_no',
        'status',
        'severity',
        'reported_at',
        'repair_started_at',
        'fixed_at',
        'verified_at',
        'verified_by_name',
        'technician_name',
        'repair_photos',
        'repair_materials',
        'closure_point_id',
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
        'core_no' => 'integer',
        'reported_at' => 'datetime',
        'repair_started_at' => 'datetime',
        'fixed_at' => 'datetime',
        'verified_at' => 'datetime',
        'repair_photos' => 'array',
        'repair_materials' => 'array',
        'closure_point_id' => 'integer',
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
