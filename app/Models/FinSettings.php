<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class FinSettings extends Model
{
    protected $table = 'noci_fin_settings';
    
    protected $fillable = [
        'tenant_id',
        'teknisi_fee_install',
        'sales_fee_install',
        'expense_categories',
        'default_branch_mode',
        'max_upload_mb',
        'auto_compress_upload',
        'retention_days',
        'payroll_deduction_coa',
        'install_rev_debit_coa',
        'install_rev_credit_coa',
        'teknisi_expense_debit_coa',
        'teknisi_expense_credit_coa',
        'tx_attachment_required',
        'teknisi_fee_debit_coa',
        'teknisi_fee_credit_coa',
        'sales_fee_debit_coa',
        'sales_fee_credit_coa',
    ];

    protected $casts = [
        'teknisi_fee_install' => 'float',
        'sales_fee_install' => 'float',
        'expense_categories' => 'array',
        'max_upload_mb' => 'integer',
        'auto_compress_upload' => 'boolean',
        'retention_days' => 'integer',
        'install_rev_debit_coa' => 'integer',
        'install_rev_credit_coa' => 'integer',
        'teknisi_expense_debit_coa' => 'integer',
        'teknisi_expense_credit_coa' => 'integer',
        'tx_attachment_required' => 'boolean',
        'teknisi_fee_debit_coa' => 'integer',
        'teknisi_fee_credit_coa' => 'integer',
        'sales_fee_debit_coa' => 'integer',
        'sales_fee_credit_coa' => 'integer',
    ];

    // Scopes
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    // Static helpers
    public static function getValue(int $tenantId, string $key, $default = null)
    {
        $key = trim($key);
        if ($key === '') {
            return $default;
        }

        $table = (new static)->getTable();
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $key)) {
            return $default;
        }

        $setting = static::forTenant($tenantId)->first();
        return $setting?->{$key} ?? $default;
    }

    public static function setValue(int $tenantId, string $key, $value): void
    {
        $key = trim($key);
        if ($key === '') {
            return;
        }

        $table = (new static)->getTable();
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $key)) {
            return;
        }

        static::updateOrCreate(['tenant_id' => $tenantId], [$key => $value]);
    }

    public static function getAll(int $tenantId): array
    {
        $row = static::forTenant($tenantId)->first();
        return $row ? $row->toArray() : [];
    }
}
