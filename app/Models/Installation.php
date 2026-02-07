<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Installation extends Model
{
    protected $table = 'noci_installations';

    // noci_installations only has created_at (no updated_at)
    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'ticket_id',
        'customer_name',
        'customer_phone',
        'address',
        'pop',
        'coordinates',
        'plan_name',
        'price',
        'installation_date',
        'finished_at',
        'status',
        'notes',
        'technician',
        'technician_2',
        'technician_3',
        'technician_4',
        'sales_name',
        'sales_name_2',
        'sales_name_3',
        'is_priority',
    ];

    protected $casts = [
        'installation_date' => 'date',
        'finished_at' => 'datetime',
        'price' => 'decimal:0',
        'is_priority' => 'boolean',
    ];

    // Status constants
    const STATUS_BARU = 'Baru';
    const STATUS_SURVEY = 'Survey';
    const STATUS_PROSES = 'Proses';
    const STATUS_SELESAI = 'Selesai';
    const STATUS_BATAL = 'Batal';
    const STATUS_PENDING = 'Pending';
    const STATUS_REQ_BATAL = 'Req_Batal';

    // Scopes
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [self::STATUS_SELESAI, self::STATUS_BATAL]);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_SELESAI);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_BATAL);
    }

    public function scopeForPop($query, string $pop)
    {
        return $query->where('pop', $pop);
    }

    public function scopeForTechnician($query, string $name)
    {
        return $query->where(function ($q) use ($name) {
            $q->where('technician', $name)
              ->orWhere('technician_2', $name)
              ->orWhere('technician_3', $name)
              ->orWhere('technician_4', $name);
        });
    }

    public function scopeToday($query)
    {
        return $query->whereDate('installation_date', today());
    }

    public function scopeThisMonth($query)
    {
        return $query->whereMonth('installation_date', now()->month)
                     ->whereYear('installation_date', now()->year);
    }

    // Relationships
    public function changes(): HasMany
    {
        return $this->hasMany(InstallationChange::class, 'installation_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(NociUser::class, 'created_by');
    }

    // Helpers
    public function getTechniciansAttribute(): array
    {
        return array_filter([
            $this->technician,
            $this->technician_2,
            $this->technician_3,
            $this->technician_4,
        ]);
    }

    public function getSalesAttribute(): array
    {
        return array_filter([
            $this->sales_name,
            $this->sales_name_2,
            $this->sales_name_3,
        ]);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_SELESAI;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_BATAL;
    }

    public function isActive(): bool
    {
        return !$this->isCompleted() && !$this->isCancelled();
    }

    // Generate ticket ID
    public static function generateTicketId(int $tenantId): string
    {
        // Match native: strtoupper(substr(bin2hex(random_bytes(4)), 0, 5))
        for ($i = 0; $i < 10; $i++) {
            $ticket = strtoupper(substr(bin2hex(random_bytes(4)), 0, 5));
            $exists = static::where('tenant_id', $tenantId)->where('ticket_id', $ticket)->exists();
            if (!$exists) {
                return $ticket;
            }
        }

        // Ultra-rare collision fallback.
        return strtoupper(substr(bin2hex(random_bytes(8)), 0, 10));
    }
}
