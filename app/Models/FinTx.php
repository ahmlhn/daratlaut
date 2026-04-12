<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinTx extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_POSTED = 'posted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_POSTED,
        self::STATUS_REJECTED,
    ];

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
        'posted_at' => 'datetime',
    ];

    // Scopes
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopePosted($query)
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
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
        return strtolower((string) $this->status) === self::STATUS_PENDING;
    }

    public function isPosted(): bool
    {
        return strtolower((string) $this->status) === self::STATUS_POSTED;
    }

    // Status badge color
    public function getStatusColor(): string
    {
        return match (strtolower((string) $this->status)) {
            self::STATUS_DRAFT => 'red',
            self::STATUS_PENDING => 'yellow',
            self::STATUS_POSTED => 'green',
            self::STATUS_REJECTED => 'gray',
            default => 'gray',
        };
    }
}
