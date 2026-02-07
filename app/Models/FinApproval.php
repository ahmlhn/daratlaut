<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinApproval extends Model
{
    protected $table = 'noci_fin_approvals';

    // Table has no created_at/updated_at
    public $timestamps = false;
    
    protected $fillable = [
        'tenant_id',
        'tx_id',
        'status',
        'note',
        'requested_by',
        'requested_role',
        'requested_at',
        'approved_by',
        'approved_at',
    ];

    // Scopes
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    // Relationships
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinTx::class, 'tx_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(NociUser::class, 'user_id');
    }
}
