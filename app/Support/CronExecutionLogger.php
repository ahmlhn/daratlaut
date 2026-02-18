<?php

namespace App\Support;

use DateTime;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CronExecutionLogger
{
    public function record(
        int $tenantId,
        string $jobKey,
        string $command,
        string $status,
        string $message = '',
        array $meta = [],
        DateTimeInterface|string|null $startedAt = null,
        DateTimeInterface|string|null $finishedAt = null
    ): void {
        if ($tenantId <= 0) {
            return;
        }

        if (!Schema::hasTable('noci_cron_logs')) {
            return;
        }

        try {
            $started = $this->normalizeDate($startedAt);
            $finished = $this->normalizeDate($finishedAt);

            $durationMs = null;
            if ($started !== null && $finished !== null) {
                $durationMs = max(0, $finished->valueOf() - $started->valueOf());
            }

            $metaJson = null;
            if (!empty($meta)) {
                $encoded = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (is_string($encoded) && $encoded !== '') {
                    $metaJson = $encoded;
                }
            }

            DB::table('noci_cron_logs')->insert([
                'tenant_id' => $tenantId,
                'job_key' => substr(trim($jobKey), 0, 50),
                'command' => substr(trim($command), 0, 120),
                'status' => substr($this->normalizeStatus($status), 0, 20),
                'message' => $message !== '' ? substr(trim($message), 0, 255) : null,
                'duration_ms' => $durationMs,
                'meta_json' => $metaJson,
                'started_at' => $started,
                'finished_at' => $finished,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable) {
            // Best effort only.
        }
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (in_array($status, ['success', 'partial', 'failed', 'skipped', 'dry_run'], true)) {
            return $status;
        }

        if (in_array($status, ['ok', 'sent'], true)) {
            return 'success';
        }
        if (in_array($status, ['fail', 'error'], true)) {
            return 'failed';
        }
        if (in_array($status, ['skip', 'ignored'], true)) {
            return 'skipped';
        }

        return 'success';
    }

    private function normalizeDate(DateTimeInterface|string|null $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance(DateTime::createFromInterface($value));
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}

