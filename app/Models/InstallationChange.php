<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallationChange extends Model
{
    protected $table = 'noci_installation_changes';

    // Table uses changed_at, not created_at/updated_at
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'installation_id',
        'field_name',
        'old_value',
        'new_value',
        'changed_by',
        'changed_by_role',
        'source',
    ];

    // Scopes
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    // Relationships
    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class, 'installation_id');
    }

    // Static helper to log a change
    public static function logChange(
        int $installationId,
        string $field,
        $oldValue,
        $newValue,
        ?string $changedBy = null,
        ?string $role = null,
        ?string $source = null
    ): ?self {
        // Don't log if values are the same
        if ($oldValue === $newValue) {
            return null;
        }

        $installation = Installation::find($installationId);
        if (!$installation) {
            return null;
        }

        return static::create([
            'tenant_id' => $installation->tenant_id,
            'installation_id' => $installationId,
            'field_name' => $field,
            'old_value' => is_array($oldValue) ? json_encode($oldValue) : $oldValue,
            'new_value' => is_array($newValue) ? json_encode($newValue) : $newValue,
            'changed_by' => $changedBy,
            'changed_by_role' => $role,
            'source' => $source,
        ]);
    }
}
