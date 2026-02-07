<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lead (prospective customer) model.
 * Uses noci_customers table — only records that have a phone/WA number
 * are considered leads. Visitors without phone remain chat-only contacts.
 */
class Lead extends Model
{
    protected $table = 'noci_customers';
    
    public $timestamps = false;
    
    protected $fillable = [
        'tenant_id',
        'visit_id',
        'customer_name',
        'customer_phone',
        'customer_address',
        'notes',
        'status',
        'source',
        'lat',
        'lng',
        'last_seen',
    ];

    protected $casts = [
        'last_seen' => 'datetime',
    ];

    /**
     * Boot: global scope ensures only records with phone/WA are leads.
     * Chat visitors without phone (NULL, empty, or '-') are not leads.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('has_phone', function (Builder $query) {
            $query->whereNotNull('customer_phone')
                  ->where('customer_phone', '!=', '')
                  ->where('customer_phone', '!=', '-');
        });
    }

    // Status constants
    const STATUS_NEW = 'NEW';
    const STATUS_CONTACTED = 'CONTACTED';
    const STATUS_INTERESTED = 'INTERESTED';
    const STATUS_CONVERTED = 'CONVERTED';
    const STATUS_LOST = 'LOST';

    /**
     * Scope by tenant.
     */
    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope by status.
     */
    public function scopeStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Get status badge class.
     */
    public function getStatusColor(): string
    {
        return match ($this->status) {
            'NEW' => 'blue',
            'CONTACTED' => 'yellow',
            'INTERESTED' => 'purple',
            'CONVERTED' => 'green',
            'LOST' => 'gray',
            default => 'gray',
        };
    }

    /**
     * Get all available statuses.
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_NEW => 'Baru',
            self::STATUS_CONTACTED => 'Dihubungi',
            self::STATUS_INTERESTED => 'Tertarik',
            self::STATUS_CONVERTED => 'Jadi Pelanggan',
            self::STATUS_LOST => 'Tidak Jadi',
        ];
    }

    /**
     * Normalize phone number to 62 format.
     */
    public static function normalizePhone($phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '0')) {
            $phone = '62' . substr($phone, 1);
        }
        return $phone;
    }
}
