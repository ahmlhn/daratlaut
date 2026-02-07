<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pop extends Model
{
    protected $table = 'noci_pops';

    // Table has no created_at/updated_at
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'pop_name',
        'wa_number',
        'group_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Scopes
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Relationships
    public function team(): HasMany
    {
        return $this->hasMany(Team::class, 'pop_id');
    }

    public function installations(): HasMany
    {
        return $this->hasMany(Installation::class, 'pop', 'pop_name');
    }
}
