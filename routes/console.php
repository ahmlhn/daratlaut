<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

/*
|--------------------------------------------------------------------------
| Billing Scheduler
|--------------------------------------------------------------------------
|
| These scheduled tasks handle automated billing operations.
| Run `php artisan schedule:run` every minute via cron:
|   * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
|
*/

// Generate monthly invoices on the 1st of each month at 00:05
Schedule::command('billing:generate-invoices')
    ->monthlyOn(1, '00:05')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/billing-generate.log'));

// Check for overdue invoices daily at 08:00 and auto-suspend
Schedule::command('billing:check-overdue --auto-suspend')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/billing-overdue.log'));

// Send reminder 3 days before due date at 09:00
Schedule::command('billing:send-reminders --type=upcoming --days=3')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/billing-reminders.log'));

// Send reminder on due date at 09:00
Schedule::command('billing:send-reminders --type=due')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/billing-reminders.log'));

// Send overdue reminder at 10:00
Schedule::command('billing:send-reminders --type=overdue')
    ->dailyAt('10:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/billing-reminders.log'));

/*
|--------------------------------------------------------------------------
| Ops Daily Scheduler (Native Cron Parity)
|--------------------------------------------------------------------------
|
| Fitur parity untuk cron native:
| - ops:nightly-closing (parity cron_night.php)
| - ops:send-reminders (parity cron_reminders.php)
|
| Disabled by default. Enable via:
| OPS_NIGHTLY_SCHEDULE_ENABLED=true
| OPS_REMINDERS_SCHEDULE_ENABLED=true
|
*/
$opsNightlyEnabled = filter_var((string) env('OPS_NIGHTLY_SCHEDULE_ENABLED', 'false'), FILTER_VALIDATE_BOOL);
if ($opsNightlyEnabled) {
    $opsNightlyTime = env('OPS_NIGHTLY_SCHEDULE_TIME', '21:30');

    Schedule::command('ops:nightly-closing')
        ->dailyAt($opsNightlyTime)
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/ops-nightly-closing.log'));
}

$opsRemindersEnabled = filter_var((string) env('OPS_REMINDERS_SCHEDULE_ENABLED', 'false'), FILTER_VALIDATE_BOOL);
if ($opsRemindersEnabled) {
    $opsRemindersTime = env('OPS_REMINDERS_SCHEDULE_TIME', '07:00');

    Schedule::command('ops:send-reminders')
        ->dailyAt($opsRemindersTime)
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/ops-reminders.log'));
}

/*
|--------------------------------------------------------------------------
| Tenant Scheduler (From Settings Page)
|--------------------------------------------------------------------------
|
| Reads per-tenant schedules from table `noci_cron_settings`:
| - ops:nightly-closing
| - ops:send-reminders
| - olt:queue-daily-sync (daily at tenant `olt_time`, server clock)
| Enable/disable via env OPS_TENANT_SCHEDULE_ENABLED (default: true).
|
*/
$normalizeClock = static function (?string $value, string $fallback): string {
    $value = trim((string) $value);
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value)) {
        return $value;
    }

    return $fallback;
};

$opsTenantScheduleEnabled = filter_var((string) env('OPS_TENANT_SCHEDULE_ENABLED', 'true'), FILTER_VALIDATE_BOOL);
$tenantOltScheduled = 0;

if ($opsTenantScheduleEnabled && Schema::hasTable('noci_cron_settings')) {
    try {
        $hasOltEnabledColumn = Schema::hasColumn('noci_cron_settings', 'olt_enabled');
        $hasOltTimeColumn = Schema::hasColumn('noci_cron_settings', 'olt_time');
        $selectColumns = ['tenant_id', 'nightly_enabled', 'nightly_time', 'reminders_enabled', 'reminders_time', 'reminder_base_url'];
        if ($hasOltEnabledColumn) {
            $selectColumns[] = 'olt_enabled';
        }
        if ($hasOltTimeColumn) {
            $selectColumns[] = 'olt_time';
        }

        $rows = DB::table('noci_cron_settings')
            ->where(function ($q) use ($hasOltEnabledColumn) {
                $q->where('nightly_enabled', 1)
                    ->orWhere('reminders_enabled', 1);
                if ($hasOltEnabledColumn) {
                    $q->orWhere('olt_enabled', 1);
                }
            })
            ->orderBy('tenant_id')
            ->get($selectColumns);

        foreach ($rows as $row) {
            $tenantId = (int) ($row->tenant_id ?? 0);
            if ($tenantId <= 0) continue;

            if ((int) ($row->nightly_enabled ?? 0) === 1) {
                $nightlyTime = $normalizeClock((string) ($row->nightly_time ?? ''), '21:30');

                Schedule::command('ops:nightly-closing', ['--tenant' => (string) $tenantId])
                    ->dailyAt($nightlyTime)
                    ->withoutOverlapping()
                    ->appendOutputTo(storage_path('logs/ops-nightly-closing.log'));
            }

            if ((int) ($row->reminders_enabled ?? 0) === 1) {
                $remindersTime = $normalizeClock((string) ($row->reminders_time ?? ''), '07:00');
                $params = ['--tenant' => (string) $tenantId];

                $baseUrl = trim((string) ($row->reminder_base_url ?? ''));
                if ($baseUrl !== '') {
                    $params['--base-url'] = $baseUrl;
                }

                Schedule::command('ops:send-reminders', $params)
                    ->dailyAt($remindersTime)
                    ->withoutOverlapping()
                    ->appendOutputTo(storage_path('logs/ops-reminders.log'));
            }

            if ($hasOltEnabledColumn && (int) ($row->olt_enabled ?? 0) === 1) {
                $oltTime = $normalizeClock($hasOltTimeColumn ? (string) ($row->olt_time ?? '') : null, '02:15');
                Schedule::command('olt:queue-daily-sync', ['--tenant' => (string) $tenantId, '--sync' => true])
                    ->dailyAt($oltTime)
                    ->withoutOverlapping()
                    ->appendOutputTo(storage_path('logs/olt-daily-sync.log'));
                $tenantOltScheduled++;
            }
        }
    } catch (\Throwable $e) {
        Log::warning('Failed loading tenant cron schedules', ['error' => $e->getMessage()]);
    }
}

/*
|--------------------------------------------------------------------------
| OLT Daily Sync Scheduler
|--------------------------------------------------------------------------
|
| Prioritas utama: jadwal per-tenant dari `noci_cron_settings`.
| Fallback global (env) tetap disediakan untuk kompatibilitas deployment lama
| atau saat jadwal tenant belum dikonfigurasi.
| OLT sync berjalan harian dari `OLT_DAILY_SYNC_TIME` (jam server).
|
*/
$oltDailySyncEnabled = filter_var((string) env('OLT_DAILY_SYNC_SCHEDULE_ENABLED', 'true'), FILTER_VALIDATE_BOOL);
$oltDailySyncLegacyEnabled = filter_var((string) env('OLT_DAILY_SYNC_ON_ACCESS', 'true'), FILTER_VALIDATE_BOOL);
$oltDailySyncTime = (string) env('OLT_DAILY_SYNC_TIME', '02:15');

if (($oltDailySyncEnabled || $oltDailySyncLegacyEnabled) && $tenantOltScheduled === 0) {
    $oltDailySyncAt = $normalizeClock($oltDailySyncTime, '02:15');
    Schedule::command('olt:queue-daily-sync', ['--sync' => true])
        ->dailyAt($oltDailySyncAt)
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/olt-daily-sync.log'));
}
