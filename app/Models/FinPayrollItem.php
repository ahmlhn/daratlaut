<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinPayrollItem extends Model
{
    protected $table = 'noci_fin_payroll_items';

    // Table only has created_at, no updated_at
    const UPDATED_AT = null;
    
    protected $fillable = [
        'tenant_id',
        'run_id',
        'employee_id',
        'employee_name',
        'base_salary',
        'overtime',
        'allowance',
        'deduction',
        'fee_install',
        'fee_sales',
        'fee',
        'total',
        'notes',
    ];

    protected $casts = [
        'base_salary' => 'float',
        'overtime' => 'float',
        'allowance' => 'float',
        'deduction' => 'float',
        'fee_install' => 'float',
        'fee_sales' => 'float',
        'fee' => 'float',
        'total' => 'float',
    ];

    // Scopes
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    // Relationships
    public function payroll(): BelongsTo
    {
        return $this->belongsTo(FinPayroll::class, 'run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'employee_id');
    }

    // Helpers
    public function calculateNet(): float
    {
        return ($this->base_salary ?? 0) 
            + ($this->overtime ?? 0) 
            + ($this->teknisi_fee ?? 0) 
            + ($this->sales_fee ?? 0) 
            - ($this->deductions ?? 0);
    }
}
