<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinSettings extends Model
{
    protected $table = 'noci_fin_settings';
    
    protected $fillable = [
        'tenant_id',
        'setting_key',
        'setting_value',
    ];

    // Scopes
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    // Static helpers
    public static function getValue(int $tenantId, string $key, $default = null)
    {
        $setting = static::forTenant($tenantId)->where('setting_key', $key)->first();
        return $setting ? $setting->setting_value : $default;
    }

    public static function setValue(int $tenantId, string $key, $value): void
    {
        static::updateOrCreate(
            ['tenant_id' => $tenantId, 'setting_key' => $key],
            ['setting_value' => $value]
        );
    }

    public static function getAll(int $tenantId): array
    {
        return static::forTenant($tenantId)
            ->pluck('setting_value', 'setting_key')
            ->toArray();
    }
}
