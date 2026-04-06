<?php

namespace App\Console\Commands;

use App\Models\Olt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RebindOltHistory extends Command
{
    protected $signature = 'olt:rebind-history
                            {tenant : Tenant ID}
                            {from : OLT ID lama}
                            {to : OLT ID baru}
                            {--execute : Terapkan perubahan ke database}
                            {--force : Izinkan merge walau target sudah punya histori/log}';

    protected $description = 'Audit dan pindahkan histori/log OLT dari ID lama ke ID baru setelah OLT dihapus lalu ditambahkan ulang.';

    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant');
        $fromId = (int) $this->argument('from');
        $toId = (int) $this->argument('to');
        $execute = (bool) $this->option('execute');
        $force = (bool) $this->option('force');

        if ($tenantId <= 0 || $fromId <= 0 || $toId <= 0) {
            $this->error('Tenant ID dan OLT ID harus berupa integer positif.');
            return self::FAILURE;
        }

        if ($fromId === $toId) {
            $this->error('OLT ID lama dan baru tidak boleh sama.');
            return self::FAILURE;
        }

        $target = Olt::query()->forTenant($tenantId)->find($toId);
        if (!$target) {
            $this->error("OLT target #{$toId} tidak ditemukan pada tenant #{$tenantId}.");
            return self::FAILURE;
        }

        $audit = $this->buildAudit($tenantId, $fromId, $toId);

        $this->info("Tenant #{$tenantId}");
        $this->line("Source OLT ID : {$fromId}");
        $this->line("Target OLT ID : {$toId} ({$target->nama_olt})");
        $this->newLine();

        $this->table(
            ['Metric', 'Source', 'Target'],
            [
                ['RX rows', $audit['rx']['source_count'], $audit['rx']['target_count']],
                ['RX range', $audit['rx']['source_range'], $audit['rx']['target_range']],
                ['RX distinct SN', $audit['rx']['source_distinct_sn'], $audit['rx']['target_distinct_sn']],
                ['RX overlap SN', $audit['rx']['overlap_distinct_sn'], '-'],
                ['OLT logs', $audit['logs']['source_count'], $audit['logs']['target_count']],
                ['Pending queue jobs', $audit['queue']['pending_source_jobs'], $audit['queue']['pending_target_jobs']],
                ['Failed queue jobs', $audit['queue']['failed_source_jobs'], $audit['queue']['failed_target_jobs']],
            ]
        );

        if (!$execute) {
            $this->warn('DRY RUN aktif. Tidak ada perubahan database.');
            if ($audit['queue']['pending_source_jobs'] > 0 || $audit['queue']['failed_source_jobs'] > 0) {
                $this->warn('Masih ada job queue yang menunjuk ke OLT ID lama. Job tersebut tidak diubah oleh command ini.');
            }
            $this->line('Gunakan --execute untuk menerapkan perubahan.');
            return self::SUCCESS;
        }

        if (($audit['rx']['target_count'] > 0 || $audit['logs']['target_count'] > 0) && !$force) {
            $this->error('Target sudah memiliki histori/log. Jalankan ulang dengan --force setelah review dry-run.');
            return self::FAILURE;
        }

        $changes = DB::transaction(function () use ($tenantId, $fromId, $toId) {
            $rxUpdated = 0;
            if (Schema::hasTable('noci_olt_rx_logs')) {
                $rxUpdated = DB::table('noci_olt_rx_logs')
                    ->where('tenant_id', $tenantId)
                    ->where('olt_id', $fromId)
                    ->update(['olt_id' => $toId]);
            }

            $logUpdated = 0;
            if (Schema::hasTable('noci_olt_logs')) {
                $logUpdated = DB::table('noci_olt_logs')
                    ->where('tenant_id', $tenantId)
                    ->where('olt_id', $fromId)
                    ->update(['olt_id' => $toId]);
            }

            return [
                'rx_updated' => $rxUpdated,
                'log_updated' => $logUpdated,
            ];
        });

        $this->info('Rebind selesai.');
        $this->table(
            ['Updated table', 'Rows'],
            [
                ['noci_olt_rx_logs', $changes['rx_updated']],
                ['noci_olt_logs', $changes['log_updated']],
            ]
        );

        if ($audit['queue']['pending_source_jobs'] > 0 || $audit['queue']['failed_source_jobs'] > 0) {
            $this->warn('Masih ada job queue/failed job yang menunjuk ke OLT ID lama. Bersihkan atau review secara terpisah.');
        }

        return self::SUCCESS;
    }

    private function buildAudit(int $tenantId, int $fromId, int $toId): array
    {
        $sourceSn = $this->distinctSn($tenantId, $fromId);
        $targetSn = $this->distinctSn($tenantId, $toId);

        return [
            'rx' => [
                'source_count' => $this->tableExists('noci_olt_rx_logs')
                    ? DB::table('noci_olt_rx_logs')->where('tenant_id', $tenantId)->where('olt_id', $fromId)->count()
                    : 0,
                'target_count' => $this->tableExists('noci_olt_rx_logs')
                    ? DB::table('noci_olt_rx_logs')->where('tenant_id', $tenantId)->where('olt_id', $toId)->count()
                    : 0,
                'source_range' => $this->formatRange('noci_olt_rx_logs', $tenantId, $fromId, 'sampled_at'),
                'target_range' => $this->formatRange('noci_olt_rx_logs', $tenantId, $toId, 'sampled_at'),
                'source_distinct_sn' => count($sourceSn),
                'target_distinct_sn' => count($targetSn),
                'overlap_distinct_sn' => count(array_intersect($sourceSn, $targetSn)),
            ],
            'logs' => [
                'source_count' => $this->tableExists('noci_olt_logs')
                    ? DB::table('noci_olt_logs')->where('tenant_id', $tenantId)->where('olt_id', $fromId)->count()
                    : 0,
                'target_count' => $this->tableExists('noci_olt_logs')
                    ? DB::table('noci_olt_logs')->where('tenant_id', $tenantId)->where('olt_id', $toId)->count()
                    : 0,
            ],
            'queue' => [
                'pending_source_jobs' => $this->countQueuedJobs('jobs', $tenantId, $fromId),
                'pending_target_jobs' => $this->countQueuedJobs('jobs', $tenantId, $toId),
                'failed_source_jobs' => $this->countQueuedJobs('failed_jobs', $tenantId, $fromId),
                'failed_target_jobs' => $this->countQueuedJobs('failed_jobs', $tenantId, $toId),
            ],
        ];
    }

    private function distinctSn(int $tenantId, int $oltId): array
    {
        if (!$this->tableExists('noci_olt_rx_logs')) {
            return [];
        }

        return DB::table('noci_olt_rx_logs')
            ->where('tenant_id', $tenantId)
            ->where('olt_id', $oltId)
            ->whereNotNull('sn')
            ->distinct()
            ->pluck('sn')
            ->all();
    }

    private function formatRange(string $table, int $tenantId, int $oltId, string $column): string
    {
        if (!$this->tableExists($table)) {
            return '-';
        }

        $min = DB::table($table)
            ->where('tenant_id', $tenantId)
            ->where('olt_id', $oltId)
            ->min($column);

        $max = DB::table($table)
            ->where('tenant_id', $tenantId)
            ->where('olt_id', $oltId)
            ->max($column);

        if (!$min && !$max) {
            return '-';
        }

        return "{$min} .. {$max}";
    }

    private function countQueuedJobs(string $table, int $tenantId, int $oltId): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }

        $count = 0;
        foreach (DB::table($table)->select('payload')->orderBy('id')->cursor() as $row) {
            $payload = json_decode((string) ($row->payload ?? ''), true);
            $command = (string) ($payload['data']['command'] ?? '');
            if ($command === '') {
                continue;
            }

            if (str_contains($command, 'tenantId";i:' . $tenantId) && str_contains($command, 'oltId";i:' . $oltId)) {
                $count++;
            }
        }

        return $count;
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];

        if (!array_key_exists($table, $cache)) {
            $cache[$table] = Schema::hasTable($table);
        }

        return $cache[$table];
    }
}
