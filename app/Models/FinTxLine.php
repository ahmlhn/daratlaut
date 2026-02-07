<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinTxLine extends Model
{
    protected $table = 'noci_fin_tx_lines';

    // Table only has created_at, no updated_at
    const UPDATED_AT = null;
    
    protected $fillable = [
        'tenant_id',
        'tx_id',
        'coa_id',
        'description',
        'debit',
        'credit',
    ];

    protected $casts = [
        'debit' => 'float',
        'credit' => 'float',
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

    public function coa(): BelongsTo
    {
        return $this->belongsTo(FinCoa::class, 'coa_id');
    }

    // Helpers
    public function isDebit(): bool
    {
        return $this->debit > 0;
    }

    public function isCredit(): bool
    {
        return $this->credit > 0;
    }

    public function getAmount(): float
    {
        return $this->debit > 0 ? $this->debit : $this->credit;
    }
}
