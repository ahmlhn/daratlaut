<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinPayroll extends Model
{
    protected $table = 'noci_fin_payroll';
    
    protected $fillable = [
        'tenant_id',
        'period',
        'run_name',
        'status',
        'total_gross',
        'total_deductions',
        'total_net',
        'created_by',
        'created_by_name',
        'approved_by',
        'approved_by_name',
        'approved_at',
        'fin_tx_id',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'total_gross' => 'float',
        'total_deductions' => 'float',
        'total_net' => 'float',
    ];

    // Scopes
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'DRAFT');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'APPROVED');
    }

    public function scopePosted($query)
    {
        return $query->where('status', 'POSTED');
    }

    // Relationships
    public function items(): HasMany
    {
        return $this->hasMany(FinPayrollItem::class, 'payroll_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(NociUser::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(NociUser::class, 'approved_by');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinTx::class, 'fin_tx_id');
    }

    // Helpers
    public function calculateTotal(): float
    {
        return $this->items()->sum('net_amount');
    }

    public function isDraft(): bool
    {
        return $this->status === 'DRAFT';
    }

    public function isPosted(): bool
    {
        return $this->status === 'POSTED';
    }
}
