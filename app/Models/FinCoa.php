<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinCoa extends Model
{
    protected $table = 'noci_fin_coa';
    
    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'category',
        'type',
        'parent_id',
        'is_active',
        'normal_balance',
        'description',
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

    public function scopeHeaders($query)
    {
        return $query->where('type', 'header');
    }

    public function scopeDetails($query)
    {
        return $query->where('type', 'detail');
    }

    // Relationships
    public function parent(): BelongsTo
    {
        return $this->belongsTo(FinCoa::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(FinCoa::class, 'parent_id');
    }

    public function transactionLines(): HasMany
    {
        return $this->hasMany(FinTxLine::class, 'coa_id');
    }

    // Helpers
    public function isHeader(): bool
    {
        return $this->type === 'header';
    }

    public function isDebit(): bool
    {
        return $this->normal_balance === 'debit';
    }

    // Get category label
    public function getCategoryLabel(): string
    {
        $labels = [
            'asset' => 'Aset',
            'liability' => 'Kewajiban',
            'equity' => 'Ekuitas',
            'revenue' => 'Pendapatan',
            'expense' => 'Beban',
        ];
        return $labels[$this->category] ?? $this->category;
    }
}
