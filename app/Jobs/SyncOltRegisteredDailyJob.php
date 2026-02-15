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
            $count = $service->syncAllOnusToDb();
            $service->disconnect();

            OltLog::logAction(
                $this->tenantId,
                (int) $olt->id,
                (string) ($olt->nama_olt ?? ''),
                'sync_daily',
                'done',
                [
                    'mode' => 'scheduled_daily',
                    'count' => $count,
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
}

