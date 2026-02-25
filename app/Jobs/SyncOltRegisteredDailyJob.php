<?php

namespace App\Jobs;

use App\Models\Olt;
use App\Models\OltLog;
use App\Services\OltService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SyncOltRegisteredDailyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 7200;

    public function __construct(
        public int $tenantId,
        public int $oltId
    ) {
    }

    public function handle(): void
    {
        $olt = Olt::forTenant($this->tenantId)->active()->find($this->oltId);
        if (!$olt) {
            return;
        }

        $lockKey = "olt:daily-sync:{$this->tenantId}:{$this->oltId}";
        $lock = Cache::lock($lockKey, 7500);

        if (!$lock->get()) {
            return;
        }

        $service = null;
        try {
            $service = new OltService($this->tenantId);
            $service->setSuppressActionLog(true);
            $service->connect($olt);
            $result = $service->syncAllOnusToDbScheduled();
            $service->disconnect();

            $prunedRows = 0;
            $retentionError = null;
            try {
                $prunedRows = $this->pruneRxHistoryOncePerDay();
            } catch (Throwable $e) {
                $retentionError = $e->getMessage();
            }

            OltLog::logAction(
                $this->tenantId,
                (int) $olt->id,
                (string) ($olt->nama_olt ?? ''),
                'sync_daily',
                'done',
                [
                    'mode' => 'scheduled_daily',
                    'count' => (int) ($result['synced_count'] ?? 0),
                    'fsp_count' => (int) ($result['fsp_count'] ?? 0),
                    'rx_cache_updated' => (int) ($result['rx_cache_updated'] ?? 0),
                    'rx_samples_saved' => (int) ($result['rx_samples_saved'] ?? 0),
                    'name_sync_processed' => (int) ($result['name_sync_processed'] ?? 0),
                    'name_sync_updated' => (int) ($result['name_sync_updated'] ?? 0),
                    'name_sync_skipped' => (int) ($result['name_sync_skipped'] ?? 0),
                    'name_sync_errors' => (int) ($result['name_sync_errors'] ?? 0),
                    'rx_retention_pruned' => (int) $prunedRows,
                    'rx_retention_error' => $retentionError,
                ],
                null,
                'scheduler'
            );
        } catch (Throwable $e) {
            try {
                if ($service) {
                    $service->disconnect();
                }
            } catch (Throwable $inner) {
                // ignore disconnect errors
            }

            OltLog::logAction(
                $this->tenantId,
                (int) $olt->id,
                (string) ($olt->nama_olt ?? ''),
                'sync_daily',
                'error',
                [
                    'mode' => 'scheduled_daily',
                    'message' => $e->getMessage(),
                ],
                $e->getMessage(),
                'scheduler'
            );

            throw $e;
        } finally {
            $lock->release();
        }
    }

    private function pruneRxHistoryOncePerDay(): int
    {
        if (!Schema::hasTable('noci_olt_rx_logs')) {
            return 0;
        }

        $dayKey = now()->format('Ymd');
        $guardKey = "olt:rx-history-retention:tenant:{$this->tenantId}:{$dayKey}";
        $acquired = Cache::add($guardKey, 1, now()->endOfDay());
        if (!$acquired) {
            return 0;
        }

        return (int) DB::table('noci_olt_rx_logs')
            ->where('tenant_id', $this->tenantId)
            ->where('sampled_at', '<', now()->subDays(90))
            ->delete();
    }
}
