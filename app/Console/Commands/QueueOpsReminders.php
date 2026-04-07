<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveTenants;
use App\Jobs\RunOpsRemindersJob;
use App\Support\CronExecutionLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class QueueOpsReminders extends Command
{
    use ResolvesActiveTenants;

    protected $signature = 'ops:queue-reminders
                            {--tenant= : Tenant ID (single/comma separated)}
                            {--base-url= : Base URL dashboard legacy}
                            {--sleep=10 : Delay detik antar pesan saat job diproses}
                            {--date= : Override tanggal acuan (YYYY-MM-DD)}
                            {--dry-run : Simulasi tanpa kirim WA}
                            {--connection= : Nama queue connection tujuan}
                            {--queue=ops-cron : Nama queue tujuan}';

    protected $description = 'Dispatch ops:send-reminders ke queue worker';

    public function handle(): int
    {
        $startedAt = now();
        $cronLogger = app(CronExecutionLogger::class);
        $queue = trim((string) ($this->option('queue') ?: env('OPS_CRON_QUEUE', 'ops-cron')));
        if ($queue === '') {
            $queue = 'ops-cron';
        }

        $queueConnection = trim((string) ($this->option('connection') ?: env('OPS_CRON_QUEUE_CONNECTION', 'database')));
        if ($queueConnection === '') {
            $queueConnection = 'database';
        }

        if (!$this->validateQueueConnection($queueConnection)) {
            return self::FAILURE;
        }

        $tenantIds = $this->resolveActiveTenantIds((string) ($this->option('tenant') ?? ''));
        if (empty($tenantIds)) {
            $this->warn('Tidak ada tenant aktif yang bisa diproses.');
            return self::SUCCESS;
        }

        $baseUrl = trim((string) ($this->option('base-url') ?? ''));
        $sleepSec = max(0, (int) $this->option('sleep'));
        $date = trim((string) ($this->option('date') ?? ''));
        $dryRun = (bool) $this->option('dry-run');
        $successCount = 0;
        $failedCount = 0;

        foreach ($tenantIds as $tenantId) {
            try {
                RunOpsRemindersJob::dispatch(
                    (int) $tenantId,
                    $baseUrl,
                    $sleepSec,
                    $date !== '' ? $date : null,
                    $dryRun
                )->onConnection($queueConnection)->onQueue($queue);
                $successCount++;

                $cronLogger->record(
                    (int) $tenantId,
                    'ops_reminders',
                    'ops:queue-reminders',
                    'queued',
                    'Dispatch reminder harian ke queue worker.',
                    [
                        'mode' => 'queued',
                        'connection' => $queueConnection,
                        'queue' => $queue,
                        'sleep' => $sleepSec,
                        'date' => $date !== '' ? $date : null,
                        'dry_run' => $dryRun,
                    ],
                    $startedAt,
                    now()
                );

                $this->line(" - Tenant #{$tenantId}: queued");
            } catch (Throwable $e) {
                $failedCount++;
                $this->error(" - Tenant #{$tenantId}: gagal dispatch ({$e->getMessage()})");
                $cronLogger->record(
                    (int) $tenantId,
                    'ops_reminders',
                    'ops:queue-reminders',
                    'failed',
                    'Gagal dispatch reminder harian: ' . substr($e->getMessage(), 0, 160),
                    [
                        'mode' => 'queued',
                        'connection' => $queueConnection,
                        'queue' => $queue,
                    ],
                    $startedAt,
                    now()
                );
            }
        }

        $this->info("Dispatch ops reminders selesai: queued={$successCount}, failed={$failedCount}");
        return $failedCount > 0 && $successCount === 0 ? self::FAILURE : self::SUCCESS;
    }

    private function validateQueueConnection(string $queueConnection): bool
    {
        $connections = (array) config('queue.connections', []);
        if (!isset($connections[$queueConnection])) {
            $this->error("Queue connection '{$queueConnection}' tidak ditemukan di config/queue.php.");
            return false;
        }

        $driver = (string) ($connections[$queueConnection]['driver'] ?? '');
        if ($driver === 'sync') {
            $this->error("Queue connection '{$queueConnection}' memakai driver sync.");
            return false;
        }

        if ($driver === 'database') {
            $jobsTable = (string) ($connections[$queueConnection]['table'] ?? 'jobs');
            $dbConnection = $connections[$queueConnection]['connection'] ?? null;
            $hasJobsTable = $dbConnection
                ? Schema::connection((string) $dbConnection)->hasTable($jobsTable)
                : Schema::hasTable($jobsTable);
            if (!$hasJobsTable) {
                $this->error("Queue table '{$jobsTable}' untuk connection '{$queueConnection}' tidak ditemukan.");
                return false;
            }
        }

        return true;
    }
}
