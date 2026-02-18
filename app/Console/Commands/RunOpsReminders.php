<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesActiveTenants;
use App\Support\CronExecutionLogger;
use App\Support\WaGatewaySender;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RunOpsReminders extends Command
{
    use ResolvesActiveTenants;

    protected $signature = 'ops:send-reminders
                            {--tenant= : Tenant ID (single/comma separated)}
                            {--base-url= : Base URL dashboard legacy (contoh: https://my.daratlaut.com)}
                            {--dry-run : Simulasi tanpa kirim WA}
                            {--sleep=10 : Delay detik antar pesan}
                            {--date= : Override tanggal acuan (YYYY-MM-DD)}';

    protected $description = 'Paritas cron_reminders.php: reminder POP + reminder teknisi + rekap kinerja H-1';

    private array $hasTableCache = [];
    private array $hasColumnCache = [];
    private WaGatewaySender $waSender;
    private CronExecutionLogger $cronLogger;

    public function handle(): int
    {
        $this->waSender = app(WaGatewaySender::class);
        $this->cronLogger = app(CronExecutionLogger::class);

        $tenantIds = $this->resolveActiveTenantIds((string) ($this->option('tenant') ?? ''));
        if (empty($tenantIds)) {
            $this->warn('Tidak ada tenant aktif yang bisa diproses.');
            return self::SUCCESS;
        }

        $sleepSec = max(0, (int) $this->option('sleep'));
        $dryRun = (bool) $this->option('dry-run');
        $today = $this->resolveAnchorDate((string) ($this->option('date') ?? ''));
        if (!$today) {
            $this->error('Format --date tidak valid. Gunakan YYYY-MM-DD.');
            return self::FAILURE;
        }
        $yesterday = $today->subDay();

        $baseUrl = trim((string) ($this->option('base-url') ?? ''));
        if ($baseUrl === '') {
            $baseUrl = trim((string) env('OPS_REMINDER_BASE_URL', ''));
        }
        if ($baseUrl === '') {
            $baseUrl = rtrim((string) config('app.url', ''), '/');
        }
        $baseUrl = rtrim($baseUrl, '/');

        $this->info('== OPS REMINDERS ==');
        $this->line('Tanggal acuan: ' . $today->format('Y-m-d') . ' (Asia/Jakarta)');
        $this->line('Base URL: ' . ($baseUrl !== '' ? $baseUrl : '(kosong)'));
        if ($dryRun) {
            $this->warn('DRY RUN aktif: tidak ada WA yang benar-benar dikirim.');
        }

        foreach ($tenantIds as $tenantId) {
            $this->newLine();
            $this->line("=== TENANT #{$tenantId} ===");
            $tenantStartedAt = now();
            try {
                $summary = $this->runForTenant($tenantId, $today, $yesterday, $baseUrl, $sleepSec, $dryRun);
                $totals = $summary['totals'] ?? ['eligible' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

                $status = 'success';
                $message = 'Reminder harian selesai.';
                if ($dryRun) {
                    $status = 'dry_run';
                    $message = 'Simulasi reminder harian.';
                } elseif ((int) ($totals['eligible'] ?? 0) <= 0) {
                    $status = 'skipped';
                    $message = 'Tidak ada kandidat reminder.';
                } elseif ((int) ($totals['sent'] ?? 0) <= 0 && (int) ($totals['failed'] ?? 0) > 0) {
                    $status = 'failed';
                    $message = 'Semua kandidat reminder gagal.';
                } elseif ((int) ($totals['failed'] ?? 0) > 0) {
                    $status = 'partial';
                    $message = 'Sebagian kandidat reminder gagal.';
                }

                $this->cronLogger->record(
                    $tenantId,
                    'ops_reminders',
                    'ops:send-reminders',
                    $status,
                    $message,
                    [
                        'date' => $today->format('Y-m-d'),
                        'totals' => $totals,
                        'sections' => [
                            'pop' => $summary['pop'] ?? [],
                            'tech' => $summary['tech'] ?? [],
                            'recap' => $summary['recap'] ?? [],
                        ],
                    ],
                    $tenantStartedAt,
                    now()
                );
            } catch (\Throwable $e) {
                $this->error('Tenant error: ' . $e->getMessage());
                $this->cronLogger->record(
                    $tenantId,
                    'ops_reminders',
                    'ops:send-reminders',
                    'failed',
                    'Exception: ' . substr($e->getMessage(), 0, 180),
                    [
                        'date' => $today->format('Y-m-d'),
                    ],
                    $tenantStartedAt,
                    now()
                );
            }
        }

        $this->newLine();
        $this->info('Selesai menjalankan ops:send-reminders.');
        return self::SUCCESS;
    }

    private function runForTenant(
        int $tenantId,
        CarbonImmutable $today,
        CarbonImmutable $yesterday,
        string $baseUrl,
        int $sleepSec,
        bool $dryRun
    ): array {
        if (!$this->hasTable('noci_installations')) {
            $this->warn('Skip tenant: tabel noci_installations tidak ada.');
            return [
                'pop' => ['eligible' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0],
                'tech' => ['eligible' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0],
                'recap' => ['eligible' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 1],
                'totals' => ['eligible' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 1],
            ];
        }

        $defaultGroupId = $this->defaultGroupId($tenantId);
        $popRows = $this->fetchPopRows($tenantId);
        $techRows = $this->fetchTechRows($tenantId);
        $stats = $this->fetchKpiStats($tenantId, $yesterday->format('Y-m-d'));
        $topTech = $this->fetchTopTech($tenantId, $yesterday->format('Y-m-d'));

        $this->line('Data POP: ' . count($popRows) . ', teknisi: ' . count($techRows));

        $popSummary = $this->sendPopUpdates($tenantId, $popRows, $defaultGroupId, $today, $baseUrl, $sleepSec, $dryRun);
        $techSummary = $this->sendTechnicianReminders($tenantId, $techRows, $today, $sleepSec, $dryRun);
        $recapSummary = $this->sendYesterdayRecap($tenantId, $defaultGroupId, $stats, $topTech, $yesterday, $dryRun);

        return [
            'pop' => $popSummary,
            'tech' => $techSummary,
            'recap' => $recapSummary,
            'totals' => [
                'eligible' => (int) ($popSummary['eligible'] ?? 0) + (int) ($techSummary['eligible'] ?? 0) + (int) ($recapSummary['eligible'] ?? 0),
                'sent' => (int) ($popSummary['sent'] ?? 0) + (int) ($techSummary['sent'] ?? 0) + (int) ($recapSummary['sent'] ?? 0),
                'failed' => (int) ($popSummary['failed'] ?? 0) + (int) ($techSummary['failed'] ?? 0) + (int) ($recapSummary['failed'] ?? 0),
                'skipped' => (int) ($popSummary['skipped'] ?? 0) + (int) ($techSummary['skipped'] ?? 0) + (int) ($recapSummary['skipped'] ?? 0),
            ],
        ];
    }

    private function sendPopUpdates(
        int $tenantId,
        array $rows,
        string $defaultGroupId,
        CarbonImmutable $today,
        string $baseUrl,
        int $sleepSec,
        bool $dryRun
    ): array {
        $summary = ['eligible' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

        $this->line('--- [1] Laporan POP ---');
        if (empty($rows)) {
            $this->line('Tidak ada item POP.');
            return $summary;
        }

        $grouped = [];
        foreach ($rows as $row) {
            $popName = trim((string) ($row['pop'] ?? ''));
            if ($popName === '') $popName = 'NON-AREA';
            $targetGroup = trim((string) ($row['pop_group_id'] ?? ''));
            if ($targetGroup === '') $targetGroup = $defaultGroupId;

            if (!isset($grouped[$popName])) {
                $grouped[$popName] = [
                    'group_id' => $targetGroup,
                    'items' => [],
                ];
            }
            $grouped[$popName]['items'][] = $row;
        }

        foreach ($grouped as $popName => $pack) {
            $targetGroup = trim((string) ($pack['group_id'] ?? ''));
            $items = $pack['items'] ?? [];

            if ($targetGroup === '') {
                $this->warn("POP {$popName}: skip (group_id kosong).");
                $summary['skipped']++;
                continue;
            }

            $this->line("POP {$popName}: " . count($items) . ' item');

            $header = "==================\n";
            $header .= "UPDATE DATA HARIAN\n";
            $header .= "POP: " . strtoupper($popName) . "\n";
            $header .= 'Waktu: ' . $today->format('d M Y H:i') . "\n";
            $header .= "==================";

            $summary['eligible']++;
            $headerOk = $this->dispatchGroup($tenantId, $targetGroup, $header, 'WA Group (Cron)', $dryRun, "header {$popName}");
            if ($headerOk) $summary['sent']++;
            else $summary['failed']++;
            if ($sleepSec > 0) sleep($sleepSec);

            foreach ($items as $item) {
                $status = strtoupper(trim((string) ($item['status'] ?? '')));
                $judul = $status === 'BARU' ? 'INFO PASANG BARU' : "STATUS: {$status}";
                $btn = $status === 'BARU' ? 'AMBIL' : 'DETAIL';
                $link = $this->buildReminderTaskLink($baseUrl, (int) ($item['id'] ?? 0));

                $msg = $judul . "\n";
                $msg .= $today->format('d F Y') . "\n\n";
                $msg .= 'Nama: ' . $this->orDash($item['customer_name'] ?? '') . "\n";
                $msg .= 'Wa: ' . $this->orDash($item['customer_phone'] ?? '') . "\n";
                $msg .= 'Alamat: ' . $this->orDash($item['address'] ?? '') . "\n";
                $msg .= 'Maps: ' . $this->orDash($item['coordinates'] ?? '') . "\n";
                $msg .= 'Paket: ' . $this->orDash($item['plan_name'] ?? '') . "\n";
                $msg .= 'Sales: ' . $this->orDash($item['sales_name'] ?? '') . "\n";
                $msg .= 'Teknisi: ' . $this->orDash($item['technician'] ?? '') . "\n\n";
                $msg .= $btn . ': ' . $link;

                $summary['eligible']++;
                $itemOk = $this->dispatchGroup($tenantId, $targetGroup, $msg, 'WA Group (Cron)', $dryRun, "item {$popName}");
                if ($itemOk) $summary['sent']++;
                else $summary['failed']++;
                if ($sleepSec > 0) sleep($sleepSec);
            }
        }

        return $summary;
    }

    private function sendTechnicianReminders(
        int $tenantId,
        array $rows,
        CarbonImmutable $today,
        int $sleepSec,
        bool $dryRun
    ): array {
        $summary = ['eligible' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

        $this->line('--- [2] Reminder Teknisi ---');
        if (empty($rows)) {
            $this->line('Tidak ada reminder teknisi.');
            return $summary;
        }

        $grouped = [];
        foreach ($rows as $row) {
            $phone = trim((string) ($row['phone'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($phone === '' || $name === '') {
                $summary['skipped']++;
                continue;
            }

            if (!isset($grouped[$name])) {
                $grouped[$name] = [
                    'phone' => $phone,
                    'list' => [],
                ];
            }
            $grouped[$name]['list'][] = $row;
        }

        foreach ($grouped as $name => $pack) {
            $phone = trim((string) ($pack['phone'] ?? ''));
            $list = $pack['list'] ?? [];
            if ($phone === '' || empty($list)) {
                $summary['skipped']++;
                continue;
            }

            $msg = "Halo {$name}, Briefing Pagi!\nSisa tugas pending Anda:\n\n";
            foreach ($list as $idx => $item) {
                $installDate = trim((string) ($item['installation_date'] ?? ''));
                $dateLabel = '-';
                $late = false;
                if ($installDate !== '') {
                    try {
                        $installAt = CarbonImmutable::parse($installDate, 'Asia/Jakarta');
                        $dateLabel = $installAt->format('d/m');
                        $late = $today->startOfDay()->gt($installAt->startOfDay());
                    } catch (\Throwable) {
                        $dateLabel = '-';
                    }
                }
                $alert = $late ? ' [TERLAMBAT]' : '';
                $status = trim((string) ($item['status'] ?? '-'));
                $customer = trim((string) ($item['customer_name'] ?? '-'));
                $msg .= ($idx + 1) . ". [{$status}] {$customer} ({$dateLabel}){$alert}\n";
            }
            $msg .= "\nSemangat!";

            if ($dryRun) {
                $this->line("Reminder {$name}: dry-run");
                $summary['eligible']++;
                $summary['sent']++;
            } else {
                $summary['eligible']++;
                $resp = $this->waSender->sendPersonal($tenantId, $phone, $msg, [
                    'log_platform' => 'WA Personal (Cron)',
                ]);
                if (($resp['status'] ?? '') === 'sent') {
                    $this->line("Reminder {$name}: terkirim");
                    $summary['sent']++;
                } else {
                    $errorMsg = (string) ($resp['error'] ?? 'unknown');
                    $this->error("Reminder {$name}: gagal ({$errorMsg})");
                    $summary['failed']++;
                }
            }

            if ($sleepSec > 0) sleep($sleepSec);
        }

        return $summary;
    }

    private function sendYesterdayRecap(
        int $tenantId,
        string $defaultGroupId,
        array $stats,
        array $topTech,
        CarbonImmutable $yesterday,
        bool $dryRun
    ): array {
        $summary = ['eligible' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

        $this->line('--- [3] Rekap Kinerja ---');
        if ($defaultGroupId === '') {
            $this->warn('Skip rekap: group default belum diset.');
            $summary['skipped']++;
            return $summary;
        }

        $topList = "Belum ada data.";
        if (!empty($topTech)) {
            $lines = [];
            foreach ($topTech as $row) {
                $tech = trim((string) ($row['technician'] ?? '-'));
                $jumlah = (int) ($row['jumlah'] ?? 0);
                $lines[] = "- {$tech}: {$jumlah}";
            }
            $topList = implode("\n", $lines);
        }

        $msg = "LAPORAN KINERJA (KEMARIN)\n";
        $msg .= 'Tgl: ' . $yesterday->format('d-m-Y') . "\n\n";
        $msg .= 'Selesai: ' . (int) ($stats['selesai'] ?? 0) . "\n";
        $msg .= 'Input Baru: ' . (int) ($stats['input'] ?? 0) . "\n";
        $msg .= 'Sisa Backlog: ' . (int) ($stats['backlog'] ?? 0) . "\n\n";
        $msg .= "Top Teknisi:\n{$topList}";

        $summary['eligible']++;
        $ok = $this->dispatchGroup($tenantId, $defaultGroupId, $msg, 'WA Group (Cron)', $dryRun, 'rekap');
        if ($ok) $summary['sent']++;
        else $summary['failed']++;

        return $summary;
    }

    private function dispatchGroup(
        int $tenantId,
        string $groupId,
        string $message,
        string $platform,
        bool $dryRun,
        string $label
    ): bool {
        if ($dryRun) {
            $this->line("Kirim {$label}: dry-run");
            return true;
        }

        $resp = $this->waSender->sendGroup($tenantId, $groupId, $message, [
            'log_platform' => $platform,
        ]);
        if (($resp['status'] ?? '') === 'sent') {
            $this->line("Kirim {$label}: terkirim");
            return true;
        } else {
            $errorMsg = (string) ($resp['error'] ?? 'unknown');
            $this->error("Kirim {$label}: gagal ({$errorMsg})");
            return false;
        }
    }

    private function defaultGroupId(int $tenantId): string
    {
        if (!$this->hasTable('noci_conf_wa') || !$this->hasColumn('noci_conf_wa', 'group_id')) {
            return '';
        }

        try {
            $query = DB::table('noci_conf_wa')->where('tenant_id', $tenantId);
            if ($this->hasColumn('noci_conf_wa', 'is_active')) {
                $query->where('is_active', 1);
            }
            $row = $query->first(['group_id']);
            return trim((string) ($row->group_id ?? ''));
        } catch (\Throwable) {
            return '';
        }
    }

    private function fetchPopRows(int $tenantId): array
    {
        $query = DB::table('noci_installations as i')
            ->where('i.tenant_id', $tenantId)
            ->whereIn('i.status', ['Baru', 'Survey', 'Proses'])
            ->orderBy('i.pop')
            ->orderByRaw("FIELD(i.status, 'Baru', 'Survey', 'Proses')")
            ->orderBy('i.id');

        if ($this->hasTable('noci_pops') && $this->hasColumn('noci_pops', 'group_id')) {
            $query->leftJoin('noci_pops as p', function ($join) {
                $join->on('i.pop', '=', 'p.pop_name')
                    ->on('i.tenant_id', '=', 'p.tenant_id');
            });
            $select = [
                'i.id',
                'i.pop',
                'i.status',
                'i.customer_name',
                'i.customer_phone',
                'i.address',
                'i.coordinates',
                'i.plan_name',
                'i.sales_name',
                'i.technician',
                DB::raw('p.group_id as pop_group_id'),
            ];
        } else {
            $select = [
                'i.id',
                'i.pop',
                'i.status',
                'i.customer_name',
                'i.customer_phone',
                'i.address',
                'i.coordinates',
                'i.plan_name',
                'i.sales_name',
                'i.technician',
                DB::raw("'' as pop_group_id"),
            ];
        }

        return $query->get($select)->map(fn ($row) => (array) $row)->all();
    }

    private function fetchTechRows(int $tenantId): array
    {
        if (!$this->hasTable('noci_users')) {
            return [];
        }

        return DB::table('noci_installations as i')
            ->join('noci_users as u', function ($join) {
                $join->on('i.technician', '=', 'u.name')
                    ->on('u.tenant_id', '=', 'i.tenant_id');
            })
            ->where('i.tenant_id', $tenantId)
            ->whereIn('i.status', ['Survey', 'Proses'])
            ->orderBy('i.installation_date')
            ->get([
                'i.customer_name',
                'i.status',
                'i.installation_date',
                'u.phone',
                'u.name',
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function fetchKpiStats(int $tenantId, string $yesterdayDate): array
    {
        $selesai = DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where('status', 'Selesai')
            ->whereDate('finished_at', $yesterdayDate)
            ->count();

        $input = DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->whereDate('created_at', $yesterdayDate)
            ->count();

        $backlog = DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['Selesai', 'Batal'])
            ->count();

        return [
            'selesai' => (int) $selesai,
            'input' => (int) $input,
            'backlog' => (int) $backlog,
        ];
    }

    private function fetchTopTech(int $tenantId, string $yesterdayDate): array
    {
        return DB::table('noci_installations')
            ->where('tenant_id', $tenantId)
            ->where('status', 'Selesai')
            ->whereDate('finished_at', $yesterdayDate)
            ->selectRaw('technician, COUNT(*) as jumlah')
            ->groupBy('technician')
            ->orderByDesc('jumlah')
            ->limit(5)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
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

    private function buildReminderTaskLink(string $baseUrl, int $taskId): string
    {
        $taskId = max(0, $taskId);
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '') {
            return '-';
        }

        if (!preg_match('#^https?://#i', $baseUrl)) {
            $baseUrl = 'https://' . ltrim($baseUrl, '/');
        }

        $parts = parse_url($baseUrl);
        if (!is_array($parts) || empty($parts['host'])) {
            return '-';
        }

        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $origin = $scheme . '://' . $host . $port;
        $path = (string) ($parts['path'] ?? '/');
        $path = $path !== '' ? $path : '/';

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        $pathLower = strtolower($path);
        $isLegacy = strpos($pathLower, 'dashboard.php') !== false
            || (isset($query['page']) && strtolower((string) $query['page']) === 'teknisi');

        if ($isLegacy) {
            if (strpos($pathLower, 'dashboard.php') === false) {
                $path = rtrim($path, '/') . '/dashboard.php';
            }
            $query['page'] = 'teknisi';
            $query['id'] = $taskId;
            unset($query['task_id']);

            return $origin . $path . '?' . http_build_query($query);
        }

        if (!preg_match('#(^|/)teknisi/?$#i', $path)) {
            $path = rtrim($path, '/');
            $path = $path === '' ? '/teknisi' : $path . '/teknisi';
        } else {
            $path = rtrim($path, '/');
            if ($path === '') {
                $path = '/teknisi';
            }
        }

        $query['task_id'] = $taskId;
        unset($query['id']);

        return $origin . $path . '?' . http_build_query($query);
    }

    private function orDash($value): string
    {
        $value = trim((string) $value);
        return $value === '' ? '-' : $value;
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
