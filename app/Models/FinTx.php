<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinTx extends Model
{
    protected $table = 'noci_fin_tx';
    
    protected $fillable = [
        'tenant_id',
        'tx_no',
        'tx_date',
        'ref_no',
        'description',
        'status',
        'branch_id',
        'method',
        'total_debit',
        'total_credit',
        'created_by',
        'created_by_name',
        'created_role',
        'approved_by',
        'approved_by_name',
        'approved_at',
        'posted_at',
        'source',
    ];

    protected $casts = [
        'tx_date' => 'date',
        'approved_at' => 'datetime',
        'bukti' => 'array',
    ];

    // Scopes
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'PENDING');
    }

    public function scopePosted($query)
    {
        return $query->where('status', 'POSTED');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'DRAFT');
    }

    public function scopeInPeriod($query, string $startDate, string $endDate)
    {
        return $query->whereBetween('tx_date', [$startDate, $endDate]);
    }

    // Relationships
    public function lines(): HasMany
    {
        return $this->hasMany(FinTxLine::class, 'tx_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(FinBranch::class, 'branch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(NociUser::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(NociUser::class, 'approved_by');
    }

    public function approval(): HasMany
    {
        return $this->hasMany(FinApproval::class, 'tx_id');
    }

    // Helpers
    public function getTotalDebit(): float
    {
        return $this->lines()->sum('debit');
    }

    public function getTotalCredit(): float
    {
        return $this->lines()->sum('credit');
    }

    public function isBalanced(): bool
    {
        return abs($this->getTotalDebit() - $this->getTotalCredit()) < 0.01;
    }

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    public function isPosted(): bool
    {
        return $this->status === 'POSTED';
    }

    // Status badge color
    public function getStatusColor(): string
    {
        return match($this->status) {
            'DRAFT' => 'red',
            'PENDING' => 'yellow',
            'POSTED' => 'green',
            'REJECTED' => 'gray',
            default => 'gray',
        };
    }
}
