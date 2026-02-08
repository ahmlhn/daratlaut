<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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


