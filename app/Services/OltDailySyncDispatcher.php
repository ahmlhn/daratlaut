<?php

namespace App\Services;

use App\Jobs\SyncOltRegisteredDailyJob;
use App\Models\Olt;
use Illuminate\Support\Facades\Cache;

class OltDailySyncDispatcher
{
    public function dispatchForTenantOncePerDay(int $tenantId): int
    {
        if ($tenantId <= 0) {
            $tenantId = 1;
        }

        $queue = (string) env('OLT_DAILY_SYNC_QUEUE', 'olt-sync');
        $dayKey = now()->format('Ymd');
        $markerKey = "olt:daily-sync:queued:{$tenantId}:{$dayKey}";
        $lockKey = "olt:daily-sync:queue-lock:{$tenantId}:{$dayKey}";
        $lock = Cache::lock($lockKey, 20);

        if (!$lock->get()) {
            return 0;
        }

        try {
            if (Cache::has($markerKey)) {
                return 0;
            }

            $olts = Olt::query()
                ->forTenant($tenantId)
                ->active()
                ->orderBy('id')
                ->get(['id', 'tenant_id']);

            if ($olts->isEmpty()) {
                Cache::put($markerKey, 1, now()->endOfDay()->addMinutes(10));
                return 0;
            }

            $count = 0;
            foreach ($olts as $olt) {
                $tid = (int) ($olt->tenant_id ?? $tenantId);
                $oid = (int) $olt->id;
                if ($oid <= 0) {
                    continue;
                }

                SyncOltRegisteredDailyJob::dispatch($tid, $oid)->onQueue($queue);
                $count++;
            }

            Cache::put($markerKey, $count, now()->endOfDay()->addMinutes(10));
            return $count;
        } finally {
            $lock->release();
        }
    }
}

