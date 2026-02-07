<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionLog extends Model
{
    protected $table = 'noci_billing_action_logs';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'actor_user_id',
        'action_name',
        'ref_type',
        'ref_id',
        'payload_json',
        'created_at',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Get the user who performed the action.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * Scope: filter by tenant
     */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Log an action.
     */
    public static function record(
        int $tenantId,
        ?int $userId,
        string $action,
        string $refType,
        ?int $refId = null,
        array $payload = []
    ): self {
        return static::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $userId,
            'action_name' => $action,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'payload_json' => $payload ?: null,
            'created_at' => now(),
        ]);
    }

    /**
     * Get action badge color.
     */
    public function getActionColorAttribute(): string
    {
        return match ($this->action_name) {
            'CREATE' => 'green',
            'UPDATE' => 'blue',
            'DELETE' => 'red',
            'PAYMENT' => 'teal',
            'SUSPEND' => 'yellow',
            'ACTIVATE' => 'emerald',
            default => 'gray',
        };
    }

    /**
     * Get human-readable action description.
     */
    public function getDescriptionAttribute(): string
    {
        $payload = $this->payload_json ?? [];
        $name = $payload['name'] ?? $payload['customer_name'] ?? $payload['invoice_no'] ?? '';

        return match ($this->action_name) {
            'CREATE' => "Created {$this->ref_type}" . ($name ? ": {$name}" : ''),
            'UPDATE' => "Updated {$this->ref_type}" . ($name ? ": {$name}" : ''),
            'DELETE' => "Deleted {$this->ref_type}" . ($name ? ": {$name}" : ''),
            'PAYMENT' => "Payment received" . ($name ? " for {$name}" : ''),
            'SUSPEND' => "Suspended customer" . ($name ? ": {$name}" : ''),
            'ACTIVATE' => "Activated customer" . ($name ? ": {$name}" : ''),
            default => "{$this->action_name} {$this->ref_type}",
        };
    }
}
