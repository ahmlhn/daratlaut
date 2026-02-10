<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FoPort extends Model
{
    protected $table = 'noci_fo_ports';

    protected $fillable = [
        'tenant_id',
        'point_id',
        'port_type',
        'port_label',
        'olt_id',
        'cable_id',
        'core_no',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'point_id' => 'integer',
        'olt_id' => 'integer',
        'cable_id' => 'integer',
        'core_no' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}

