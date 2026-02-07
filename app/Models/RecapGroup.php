<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecapGroup extends Model
{
    protected $table = 'noci_recap_groups';

    protected $fillable = [
        'tenant_id',
        'name',
        'group_id',
    ];

    public $timestamps = false;

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
