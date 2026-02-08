<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

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

    private static ?array $columnMap = null;

    private static function columns(): array
    {
        if (self::$columnMap !== null) {
            return self::$columnMap;
        }

        $table = (new static())->getTable();
        if (!Schema::hasTable($table)) {
            self::$columnMap = [];
            return self::$columnMap;
        }

        try {
            $cols = Schema::getColumnListing($table);
            self::$columnMap = array_fill_keys($cols, true);
        } catch (Throwable $e) {
            self::$columnMap = [];
        }

        return self::$columnMap;
    }

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
    ): ?int {
        $cols = self::columns();
        if (empty($cols)) {
            return null;
        }

        $table = (new static())->getTable();
        $now = now();

        $status = substr(trim((string) $status), 0, 20);
        $action = substr(trim((string) $action), 0, 50);
        $actor = $actor !== null ? substr(trim((string) $actor), 0, 100) : null;
        $oltName = $oltName !== null ? substr(trim((string) $oltName), 0, 50) : null;

        $data = [];

        if (isset($cols['tenant_id'])) $data['tenant_id'] = $tenantId;
        if (isset($cols['olt_id'])) $data['olt_id'] = $oltId;
        if (isset($cols['olt_name'])) $data['olt_name'] = $oltName;
        if (isset($cols['action'])) $data['action'] = $action;

        if (isset($cols['status'])) {
            $data['status'] = $status;
        } elseif (isset($cols['success'])) {
            $s = strtolower($status);
            $data['success'] = !in_array($s, ['error', 'failed', 'fail'], true);
        }

        $summaryJson = null;
        if ($summary !== null) {
            $summaryJson = json_encode($summary, JSON_UNESCAPED_UNICODE);
        }
        if ($summaryJson !== null) {
            if (isset($cols['summary_json'])) {
                $data['summary_json'] = $summaryJson;
            } elseif (isset($cols['command'])) {
                // Legacy schema fallback: store structured summary in `command` when native columns don't exist.
                $data['command'] = $summaryJson;
            }
        }

        if ($logText !== null) {
            if (isset($cols['log_text'])) {
                $data['log_text'] = $logText;
            } elseif (isset($cols['response'])) {
                $data['response'] = $logText;
            }
        }

        if (isset($cols['actor'])) $data['actor'] = $actor;

        if (isset($cols['created_at'])) $data['created_at'] = $now;
        if (isset($cols['updated_at'])) $data['updated_at'] = $now;

        try {
            if (isset($cols['id'])) {
                return (int) DB::table($table)->insertGetId($data);
            }
            DB::table($table)->insert($data);
            return null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
