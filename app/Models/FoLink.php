<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FoLink extends Model
{
    protected $table = 'noci_fo_links';

    protected $fillable = [
        'tenant_id',
        'point_id',
        'link_type',
        'from_cable_id',
        'from_core_no',
        'to_cable_id',
        'to_core_no',
        'split_group',
        'loss_db',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'point_id' => 'integer',
        'from_cable_id' => 'integer',
        'from_core_no' => 'integer',
        'to_cable_id' => 'integer',
        'to_core_no' => 'integer',
        'loss_db' => 'float',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}

