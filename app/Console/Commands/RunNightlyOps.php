<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveTenants;
use App\Support\WaGatewaySender;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class RunNightlyOps extends Command
{
    use ResolvesActiveTenants;

    protected $signature = 'ops:nightly-closing
                            {--tenant= : Tenant ID (single/comma separated)}
                            {--force : Paksa kirim ulang walau lock harian ada}
                            {--dry-run : Simulasi tanpa kirim WA/delete file}
                            {--sleep=2 : Delay detik antar pesan}
                            {--date= : Override tanggal acuan (YYYY-MM-DD)}';

    protected $description = 'Paritas cron_night.php: closing report + cleanup attachment keuangan';

    private array $hasTableCache = [];
    private array $hasColumnCache = [];
    private WaGatewaySender $waSender;

    public function handle(): int
    {
        $this->waSender = app(WaGatewaySender::class);

        $tenantIds = $this->resolveActiveTenantIds((string) ($this->option('tenant') ?? ''));
        if (empty($tenantIds)) {
            $this->warn('Tidak ada tenant aktif yang bisa diproses.');
            return self::SUCCESS;
        }

        $sleepSec = max(0, (int) $this->option('sleep'));
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');
        $today = $this->resolveAnchorDate((string) ($this->option('date') ?? ''));
        if (!$today) {
            $this->error('Format --date tidak valid. Gunakan YYYY-MM-DD.');
            return self::FAILURE;
        }
        $tomorrow = $today->addDay();

        $this->info('== OPS NIGHTLY CLOSING ==');
        $this->line('Tanggal acuan: ' . $today->format('Y-m-d') . ' (Asia/Jakarta)');
        if ($dryRun) {
            $this->warn('DRY RUN aktif: tidak kirim WA dan tidak hapus file.');
        }

        foreach ($tenantIds as $tenantId) {
            $this->newLine();
            $this->line("=== TENANT #{$tenantId} ===");

            if (!$force && $this->alreadyLockedToday($tenantId, $today->format('Y-m-d'))) {
                $this->warn('Skip: lock harian sudah ada (gunakan --force untuk kirim ulang).');
                continue;
            }

            if (!$dryRun) {
                $this->writeLock($tenantId, $today->format('Y-m-d'));
            }

            $report = $this->buildReportData($tenantId, $today, $tomorrow);
            if (empty($report['pop_groups'])) {
                $this->warn('Tidak ada POP dengan group_id aktif untuk tenant ini.');
            }

            $sentCount = 0;
            foreach ($report['report_data'] as $popName => $data) {
                if (empty($data['has_activity'])) {
                    continue;
                }

                $groupId = trim((string) ($report['pop_groups'][$popName] ?? ''));
                if ($groupId === '') {
                    $this->line("- {$popName}: skip (group_id kosong)");
                    continue;
                }

                $message = $this->buildClosingMessage($popName, $data, $today);
                if ($dryRun) {
                    $this->line("- {$popName}: dry-run, pesan siap dikirim ke {$groupId}");
                    $sentCount++;
                    continue;
                }

                $resp = $this->waSender->sendGroup($tenantId, $groupId, $message, [
                    'log_platform' => 'WA Group (Night)',
                ]);

                if (($resp['status'] ?? '') === 'sent') {
                    $this->line("- {$popName}: terkirim");
                    $sentCount++;
                } else {
                    $errorMsg = (string) ($resp['error'] ?? 'unknown');
                    $this->error("- {$popName}: gagal ({$errorMsg})");
                }

                if ($sleepSec > 0) {
                    sleep($sleepSec);
                }
            }

            $this->line("Total POP terkirim: {$sentCount}");

            $cleanup = $this->cleanupFinanceAttachments($tenantId, $today, $dryRun);
            if (($cleanup['status'] ?? '') === 'success') {
                $this->line(
                    'Cleanup: retention=' . $cleanup['retention_days'] . ' hari, '
                    . 'rows=' . $cleanup['deleted_rows'] . ', '
                    . 'files=' . $cleanup['deleted_files'] . ', '
                    . 'missing=' . $cleanup['missing_files'] . ', '
                    . 'failed=' . $cleanup['failed_files']
                );
            } else {
                $this->warn('Cleanup skip: ' . ($cleanup['message'] ?? 'N/A'));
            }
        }

        $this->newLine();
        $this->info('Selesai menjalankan ops:nightly-closing.');
        return self::SUCCESS;
    }

    private function resolveAnchorDate(string $date): ?CarbonImmutable
    {
        $tz = 'Asia/Jakarta';
        $date = trim($date);
        if ($date === '') {
            return CarbonImmutable::now($tz)->startOfDay();
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $date, $tz)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildReportData(int $tenantId, CarbonImmutable $today, CarbonImmutable $tomorrow): array
    {
        $result = [
            'pop_groups' => [],
            'report_data' => [],
        ];

        if (!$this->hasTable('noci_pops')) {
            return $result;
        }

        $pops = DB::table('noci_pops')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('group_id')
            ->where('group_id', '!=', '')
            ->get(['pop_name', 'group_id']);

        foreach ($pops as $row) {
            $key = strtoupper(trim((string) $row->pop_name));
            if ($key === '') continue;
            $result['pop_groups'][$key] = trim((string) $row->group_id);
            $result['report_data'][$key] = [
                'pending' => [],
                'logistik_besok' => 0,
                'queue_total' => 0,
                'score_done' => 0,
                'score_input' => 0,
                'has_activity' => false,
            ];
        }

        if (empty($result['report_data']) || !$this->hasTable('noci_installations')) {
            return $result;
        }

        $todayDate = $today->format('Y-m-d');
        $tomorrowDate = $tomorrow->format('Y-m-d');

        $pendingRows = DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['Proses', 'Survey'])
            ->whereNotNull('installation_date')
            ->where('installation_date', '!=', '')
            ->whereDate('installation_date', '<=', $todayDate)
            ->get(['customer_name', 'status', 'technician', 'pop']);

        foreach ($pendingRows as $row) {
            $popKey = strtoupper(trim((string) $row->pop));
            if (!isset($result['report_data'][$popKey])) continue;
            $result['report_data'][$popKey]['pending'][] = [
                'customer_name' => (string) ($row->customer_name ?? ''),
                'status' => (string) ($row->status ?? ''),
                'technician' => (string) ($row->technician ?? ''),
            ];
            $result['report_data'][$popKey]['has_activity'] = true;
        }

        $queueRows = DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where('status', 'Baru')
            ->selectRaw('pop, COUNT(*) as jumlah')
            ->groupBy('pop')
            ->get();

        foreach ($queueRows as $row) {
            $popKey = strtoupper(trim((string) $row->pop));
            if (!isset($result['report_data'][$popKey])) continue;
            $qty = (int) ($row->jumlah ?? 0);
            $result['report_data'][$popKey]['queue_total'] = $qty;
            if ($qty > 0) {
                $result['report_data'][$popKey]['has_activity'] = true;
            }
        }

        $tomorrowRows = DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where('status', 'Baru')
            ->whereNotNull('installation_date')
            ->where('installation_date', '!=', '')
            ->whereDate('installation_date', '=', $tomorrowDate)
            ->selectRaw('pop, COUNT(*) as jumlah')
            ->groupBy('pop')
            ->get();

        foreach ($tomorrowRows as $row) {
            $popKey = strtoupper(trim((string) $row->pop));
            if (!isset($result['report_data'][$popKey])) continue;
            $qty = (int) ($row->jumlah ?? 0);
            $result['report_data'][$popKey]['logistik_besok'] = $qty;
            if ($qty > 0) {
                $result['report_data'][$popKey]['has_activity'] = true;
            }
        }

        $scoreRows = DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($todayDate) {
                $q->whereDate('finished_at', '=', $todayDate)
                    ->orWhereDate('created_at', '=', $todayDate);
            })
            ->selectRaw(
                "pop,
                SUM(CASE WHEN status='Selesai' AND DATE(finished_at)=? THEN 1 ELSE 0 END) as done,
                SUM(CASE WHEN status='Baru' AND DATE(created_at)=? THEN 1 ELSE 0 END) as input",
                [$todayDate, $todayDate]
            )
            ->groupBy('pop')
            ->get();

        foreach ($scoreRows as $row) {
            $popKey = strtoupper(trim((string) $row->pop));
            if (!isset($result['report_data'][$popKey])) continue;
            $result['report_data'][$popKey]['score_done'] = (int) ($row->done ?? 0);
            $result['report_data'][$popKey]['score_input'] = (int) ($row->input ?? 0);
            $result['report_data'][$popKey]['has_activity'] = true;
        }

        return $result;
    }

    private function buildClosingMessage(string $popName, array $data, CarbonImmutable $today): string
    {
        $msg = "*CLOSING REPORT - {$popName}*\n";
        $msg .= $today->format('d F Y') . "\n\n";

        $msg .= "*KINERJA HARI INI*\n";
        $msg .= "- Terpasang : *" . (int) ($data['score_done'] ?? 0) . "*\n";
        $msg .= "- Input Baru : *" . (int) ($data['score_input'] ?? 0) . "*\n";

        $queueTotal = (int) ($data['queue_total'] ?? 0);
        if ($queueTotal > 0) {
            $msg .= "\n*STATUS ANTRIAN PASANG BARU*\n";
            $msg .= "- Total Menunggu : *{$queueTotal} Calon Pelanggan*\n";
            $msg .= "_Segera follow-up & atur jadwal pasangnya ya!_\n";
        }

        $tomorrowQty = (int) ($data['logistik_besok'] ?? 0);
        if ($tomorrowQty > 0) {
            $msg .= "\n*PERSIAPAN BESOK*\n";
            $msg .= "Harap siapkan perangkat untuk:\n";
            $msg .= "- *{$tomorrowQty} Pasang Baru*\n";
        }

        $pendingItems = is_array($data['pending'] ?? null) ? $data['pending'] : [];
        if (!empty($pendingItems)) {
            $msg .= "\n*STATUS GANTUNG (BELUM SELESAI)*\n";
            foreach ($pendingItems as $item) {
                $cust = substr((string) ($item['customer_name'] ?? ''), 0, 18);
                $status = (string) ($item['status'] ?? '-');
                $tech = (string) ($item['technician'] ?? '-');
                $msg .= "- {$cust} ({$status}) - {$tech}\n";
            }
            $msg .= "_Mohon segera update status._\n";
        }

        $msg .= "\n_*Selamat istirahat, Semoga sehat selalu*_";
        return $msg;
    }

    private function cleanupFinanceAttachments(int $tenantId, CarbonImmutable $today, bool $dryRun): array
    {
        $result = [
            'status' => 'skip',
            'message' => '',
            'retention_days' => 365,
            'cutoff' => '',
            'deleted_rows' => 0,
            'deleted_files' => 0,
            'missing_files' => 0,
            'failed_files' => 0,
        ];

        if (!$this->hasTable('noci_fin_settings')) {
            $result['message'] = 'Tabel noci_fin_settings belum ada.';
            return $result;
        }

        if (!$this->hasTable('noci_fin_attachments')) {
            $result['message'] = 'Tabel noci_fin_attachments belum ada.';
            return $result;
        }

        if (!$this->hasColumn('noci_fin_attachments', 'created_at')) {
            $result['message'] = 'Kolom created_at tidak ada di noci_fin_attachments.';
            return $result;
        }

        $retentionDays = 365;
        try {
            if ($this->hasColumn('noci_fin_settings', 'retention_days')) {
                $query = DB::table('noci_fin_settings')->where('tenant_id', $tenantId);
                if ($this->hasColumn('noci_fin_settings', 'id')) {
                    $query->where('id', 1);
                }
                $row = $query->first();
                if ($row && isset($row->retention_days)) {
                    $retentionDays = (int) $row->retention_days;
                }
            } elseif ($this->hasColumn('noci_fin_settings', 'setting_key') && $this->hasColumn('noci_fin_settings', 'setting_value')) {
                $value = DB::table('noci_fin_settings')
                    ->where('tenant_id', $tenantId)
                    ->where('setting_key', 'retention_days')
                    ->value('setting_value');
                if ($value !== null && $value !== '') {
                    $retentionDays = (int) $value;
                }
            }
        } catch (\Throwable) {
            $retentionDays = 365;
        }

        if ($retentionDays < 7) $retentionDays = 7;
        if ($retentionDays > 3650) $retentionDays = 3650;
        $result['retention_days'] = $retentionDays;

        $cutoff = $today->endOfDay()->subDays($retentionDays)->format('Y-m-d H:i:s');
        $result['cutoff'] = $cutoff;

        $rows = DB::table('noci_fin_attachments')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '<', $cutoff)
            ->get(['id', 'file_path']);

        foreach ($rows as $row) {
            $filePath = trim((string) ($row->file_path ?? ''));
            $fullPath = $this->resolveAttachmentPath($filePath);

            if ($fullPath !== null && file_exists($fullPath)) {
                if ($dryRun) {
                    $result['deleted_files']++;
                } else {
                    if (@unlink($fullPath)) {
                        $result['deleted_files']++;
                    } else {
                        $result['failed_files']++;
                    }
                }
            } else {
                $result['missing_files']++;
            }

            if ($dryRun) {
                $result['deleted_rows']++;
            } else {
                DB::table('noci_fin_attachments')
                    ->where('tenant_id', $tenantId)
                    ->where('id', (int) ($row->id ?? 0))
                    ->delete();
                $result['deleted_rows']++;
            }
        }

        $result['status'] = 'success';
        $result['message'] = 'Cleanup selesai.';
        return $result;
    }

    private function resolveAttachmentPath(string $relativePath): ?string
    {
        $relativePath = trim($relativePath);
        if ($relativePath === '') return null;
        if (preg_match('/^https?:\/\//i', $relativePath)) return null;

        $normalized = ltrim(str_replace(['\\', '//'], ['/', '/'], $relativePath), '/');

        $candidates = [
            base_path($normalized),
            base_path('public/' . $normalized),
            dirname(base_path()) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized),
            dirname(base_path()) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized),
        ];

        foreach ($candidates as $path) {
            if (File::exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function alreadyLockedToday(int $tenantId, string $today): bool
    {
        $path = $this->lockPath($tenantId);
        if (!File::exists($path)) {
            return false;
        }
        $last = trim((string) File::get($path));
        return $last === $today;
    }

    private function writeLock(int $tenantId, string $today): void
    {
        $dir = dirname($this->lockPath($tenantId));
        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }
        File::put($this->lockPath($tenantId), $today);
    }

    private function lockPath(int $tenantId): string
    {
        return storage_path('app/cron/last_run_night_' . $tenantId . '.txt');
    }

    private function hasTable(string $table): bool
    {
        if (array_key_exists($table, $this->hasTableCache)) {
            return $this->hasTableCache[$table];
        }
        try {
            $exists = Schema::hasTable($table);
        } catch (\Throwable) {
            $exists = false;
        }
        $this->hasTableCache[$table] = $exists;
        return $exists;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table . ':' . $column;
        if (array_key_exists($key, $this->hasColumnCache)) {
            return $this->hasColumnCache[$key];
        }
        if (!$this->hasTable($table)) {
            $this->hasColumnCache[$key] = false;
            return false;
        }
        try {
            $exists = Schema::hasColumn($table, $column);
        } catch (\Throwable) {
            $exists = false;
        }
        $this->hasColumnCache[$key] = $exists;
        return $exists;
    }
}
