<?php

namespace App\Console\Commands;

use App\Jobs\SyncOltRegisteredDailyJob;
use App\Models\Olt;
use Illuminate\Console\Command;

class QueueDailyOltSync extends Command
{
    protected $signature = 'olt:queue-daily-sync
                            {--tenant= : Tenant ID tertentu}
                            {--olt= : OLT ID tertentu}
                            {--queue=olt-sync : Nama queue tujuan}';

    protected $description = 'Queue sinkronisasi ONU OLT harian (1x sehari)';

    public function handle(): int
    {
        $tenantOpt = $this->option('tenant');
        $oltOpt = $this->option('olt');
        $queue = (string) ($this->option('queue') ?: 'olt-sync');

        if ((string) config('queue.default') === 'sync') {
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
            return self::SUCCESS;
        }

        $this->info("Queue: {$queue}");
        $this->info('Dispatch sinkronisasi OLT harian...');

        $count = 0;
        foreach ($olts as $olt) {
            $tenantId = (int) ($olt->tenant_id ?? 1);
            $oltId = (int) $olt->id;

            SyncOltRegisteredDailyJob::dispatch($tenantId, $oltId)->onQueue($queue);
            $count++;

            $name = trim((string) ($olt->nama_olt ?? ''));
            $this->line(" - OLT #{$oltId}" . ($name !== '' ? " ({$name})" : '') . " [tenant {$tenantId}]");
        }

        $this->info("Total job didispatch: {$count}");
        return self::SUCCESS;
    }
}
