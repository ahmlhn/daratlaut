<?php

namespace App\Console\Commands;

use App\Jobs\SyncOltRegisteredDailyJob;
use App\Models\Olt;
use App\Support\CronExecutionLogger;
use Illuminate\Console\Command;
use Throwable;

class QueueDailyOltSync extends Command
{
    protected $signature = 'olt:queue-daily-sync
                            {--tenant= : Tenant ID tertentu}
                            {--olt= : OLT ID tertentu}
                            {--sync : Jalankan sinkronisasi langsung (tanpa queue worker)}
                            {--queue=olt-sync : Nama queue tujuan}';

    protected $description = 'Sinkronisasi ONU OLT harian (langsung atau via queue)';

    public function handle(): int
    {
        $cronLogger = app(CronExecutionLogger::class);
        $tenantOpt = $this->option('tenant');
        $oltOpt = $this->option('olt');
        $runSync = (bool) $this->option('sync');
        $queue = (string) ($this->option('queue') ?: 'olt-sync');
        $startedAt = now();

        if (!$runSync && (string) config('queue.default') === 'sync') {
            $this->warn('QUEUE_CONNECTION=sync. Job tidak benar-benar diantrikan (jalan langsung).');
            $this->warn('Set QUEUE_CONNECTION=database dan jalankan queue worker untuk mode antrian.');
        }

        $query = Olt::query()->active()->orderBy('id');

        if ($tenantOpt !== null && $tenantOpt !== '') {
            $tenantId = (int) $tenantOpt;
            if ($tenantId <= 0) {
                $this->error('Tenant ID tidak valid.');
                return self::FAILURE;
            }
            $query->forTenant($tenantId);
        }

        if ($oltOpt !== null && $oltOpt !== '') {
            $oltId = (int) $oltOpt;
            if ($oltId <= 0) {
                $this->error('OLT ID tidak valid.');
                return self::FAILURE;
            }
            $query->where('id', $oltId);
        }

        $olts = $query->get();
        if ($olts->isEmpty()) {
            $this->warn('Tidak ada OLT aktif untuk diproses.');
            if ($tenantOpt !== null && $tenantOpt !== '' && (int) $tenantOpt > 0) {
                $cronLogger->record(
                    (int) $tenantOpt,
                    'olt_daily_sync',
                    'olt:queue-daily-sync',
                    'skipped',
                    'Tidak ada OLT aktif untuk diproses.',
                    [
                        'queue' => $queue,
                        'olt_filter' => $oltOpt !== null && $oltOpt !== '' ? (int) $oltOpt : null,
                        'job_count' => 0,
                    ],
                    $startedAt,
                    now()
                );
            }
            return self::SUCCESS;
        }

        if ($runSync) {
            $this->info('Mode: sync langsung (tanpa queue worker).');
        } else {
            $this->info("Mode: dispatch queue ({$queue})");
        }
        $this->info('Proses sinkronisasi OLT harian...');

        $count = 0;
        $successCount = 0;
        $failedCount = 0;
        $tenantCounts = [];
        $tenantSuccessCounts = [];
        $tenantFailedCounts = [];
        foreach ($olts as $olt) {
            $tenantId = (int) ($olt->tenant_id ?? 1);
            $oltId = (int) $olt->id;
            $name = trim((string) ($olt->nama_olt ?? ''));

            try {
                if ($runSync) {
                    SyncOltRegisteredDailyJob::dispatchSync($tenantId, $oltId);
                } else {
                    SyncOltRegisteredDailyJob::dispatch($tenantId, $oltId)->onQueue($queue);
                }
                $successCount++;
                $tenantSuccessCounts[$tenantId] = (int) ($tenantSuccessCounts[$tenantId] ?? 0) + 1;
            } catch (Throwable $e) {
                $failedCount++;
                $tenantFailedCounts[$tenantId] = (int) ($tenantFailedCounts[$tenantId] ?? 0) + 1;
                $this->error(" ! OLT #{$oltId}" . ($name !== '' ? " ({$name})" : '') . " [tenant {$tenantId}] gagal: {$e->getMessage()}");
            }

            $this->line(" - OLT #{$oltId}" . ($name !== '' ? " ({$name})" : '') . " [tenant {$tenantId}]");
            $count++;
            $tenantCounts[$tenantId] = (int) ($tenantCounts[$tenantId] ?? 0) + 1;
        }

        if ($runSync) {
            $this->info("Total OLT diproses: {$count} | sukses: {$successCount} | gagal: {$failedCount}");
        } else {
            $this->info("Total job didispatch: {$count}");
            if ($count > 0) {
                $this->warn('Pastikan queue worker aktif agar job benar-benar dieksekusi.');
            }
        }

        foreach ($tenantCounts as $tenantId => $jobCount) {
            $tenantSuccess = (int) ($tenantSuccessCounts[$tenantId] ?? 0);
            $tenantFailed = (int) ($tenantFailedCounts[$tenantId] ?? 0);
            $status = 'success';
            if ($tenantFailed > 0 && $tenantSuccess > 0) {
                $status = 'partial';
            } elseif ($tenantFailed > 0 && $tenantSuccess === 0) {
                $status = 'failed';
            }

            $message = $runSync
                ? "Sinkronisasi OLT selesai: sukses {$tenantSuccess}, gagal {$tenantFailed}."
                : "Dispatch {$jobCount} job sinkronisasi OLT (menunggu queue worker).";
            $cronLogger->record(
                (int) $tenantId,
                'olt_daily_sync',
                'olt:queue-daily-sync',
                $status,
                $message,
                [
                    'mode' => $runSync ? 'sync' : 'queued',
                    'queue' => $queue,
                    'olt_filter' => $oltOpt !== null && $oltOpt !== '' ? (int) $oltOpt : null,
                    'job_count' => (int) $jobCount,
                    'success_count' => $tenantSuccess,
                    'failed_count' => $tenantFailed,
                ],
                $startedAt,
                now()
            );
        }

        if ($failedCount > 0 && $successCount === 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
