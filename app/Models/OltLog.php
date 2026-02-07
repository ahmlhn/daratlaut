<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OltLog extends Model
{
    protected $table = 'noci_olt_logs';

    protected $fillable = [
        'tenant_id',
        'olt_id',
        'action',
        'command',
        'response',
        'actor',
        'success',
    ];

    protected $casts = [
        'success' => 'boolean',
    ];

    // Scopes
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForOlt($query, int $oltId)
    {
        return $query->where('olt_id', $oltId);
    }

    public function scopeSuccessful($query)
    {
        return $query->where('success', true);
    }

    public function scopeFailed($query)
    {
        return $query->where('success', false);
    }

    // Relationships
    public function olt(): BelongsTo
    {
        return $this->belongsTo(Olt::class, 'olt_id');
    }

    // Static helper to log an action
    public static function logAction(
        int $oltId,
        string $action,
        ?string $command = null,
        ?string $response = null,
        ?string $actor = null,
        bool $success = true
    ): self {
        $olt = Olt::find($oltId);
        
        return static::create([
            'tenant_id' => $olt?->tenant_id ?? 1,
            'olt_id' => $oltId,
            'action' => $action,
            'command' => $command,
            'response' => $response,
            'actor' => $actor,
            'success' => $success,
        ]);
    }
}
