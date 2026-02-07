<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OltLog extends Model
{
    protected $table = 'noci_olt_logs';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'created_at',
        'olt_id',
        'olt_name',
        'action',
        'actor',
        'status',
        'summary_json',
        'log_text',
    ];

    protected $casts = [
        'created_at' => 'datetime',
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

    // Relationships
    public function olt(): BelongsTo
    {
        return $this->belongsTo(Olt::class, 'olt_id');
    }

    // Static helper to log an action (best-effort; ignores schema drift by staying close to native table).
    public static function logAction(
        int $tenantId,
        ?int $oltId,
        ?string $oltName,
        string $action,
        string $status,
        ?array $summary = null,
        ?string $logText = null,
        ?string $actor = null
    ): self {
        return static::create([
            'tenant_id' => $tenantId,
            'olt_id' => $oltId,
            'olt_name' => $oltName,
            'action' => $action,
            'status' => $status,
            'summary_json' => $summary ? json_encode($summary, JSON_UNESCAPED_UNICODE) : null,
            'log_text' => $logText,
            'actor' => $actor,
        ]);
    }
}
