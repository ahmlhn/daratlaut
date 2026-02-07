<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingCustomer extends Model
{
    protected $table = 'noci_billing_customers';

    protected $fillable = [
        'tenant_id',
        'legacy_customer_id',
        'customer_code',
        'full_name',
        'phone',
        'email',
        'address',
        'plan_id',
        'profile_name',
        'nas_name',
        'pop_name',
        'odp_name',
        'reseller_name',
        'service_status',
        'billing_day',
        'grace_days',
        'next_invoice_date',
        'started_at',
        'ended_at',
        'notes',
    ];

    protected $casts = [
        'billing_day' => 'integer',
        'grace_days' => 'integer',
        'next_invoice_date' => 'date',
        'started_at' => 'date',
        'ended_at' => 'date',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BillingPlan::class, 'plan_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(BillingInvoice::class, 'customer_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillingPayment::class, 'customer_id');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('service_status', 'AKTIF');
    }

    public function scopeSuspended(Builder $query): Builder
    {
        return $query->where('service_status', 'SUSPEND');
    }
}
