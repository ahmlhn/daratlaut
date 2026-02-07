<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Team extends Model
{
    protected $table = 'noci_team';

    // Table has no created_at/updated_at
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'name',
        'phone',
        'email',
        'is_active',
        'can_login',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'can_login' => 'boolean',
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

    public function scopeTechnicians($query)
    {
        return $query->where('role', 'teknisi');
    }

    public function scopeSales($query)
    {
        return $query->where('role', 'sales');
    }

    // Relationships
    public function pop(): BelongsTo
    {
        return $this->belongsTo(Pop::class, 'pop_id');
    }
}
