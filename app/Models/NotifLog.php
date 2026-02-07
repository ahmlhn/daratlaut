<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotifLog extends Model
{
    protected $table = 'noci_notif_logs';
    
    protected $fillable = [
        'tenant_id',
        'channel',
        'target',
        'message',
        'status',
        'gateway_code',
        'response',
        'error',
    ];

    protected $casts = [
        'response' => 'json',
    ];

    public $timestamps = true;
    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;

    // Status constants
    const STATUS_SENT = 'sent';
    const STATUS_FAILED = 'failed';
    const STATUS_PENDING = 'pending';

    // Scopes
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeSent($query)
    {
        return $query->where('status', self::STATUS_SENT);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    // Helpers
    public static function log(
        int $tenantId,
        string $channel,
        string $target,
        string $message,
        string $status,
        ?string $gatewayCode = null,
        $response = null,
        ?string $error = null
    ): self {
        return self::create([
            'tenant_id' => $tenantId,
            'channel' => $channel,
            'target' => $target,
            'message' => $message,
            'status' => $status,
            'gateway_code' => $gatewayCode,
            'response' => $response,
            'error' => $error,
        ]);
    }
}
