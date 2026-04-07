<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

class RunOpsRemindersJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 3600;

    public int $uniqueFor = 86400;

    public function __construct(
        public int $tenantId,
        public string $baseUrl = '',
        public int $sleepSec = 10,
        public ?string $date = null,
        public bool $dryRun = false
    ) {
    }

    public function uniqueId(): string
    {
        return 'ops:reminders:' . $this->tenantId . ':' . ($this->date ?: now('Asia/Jakarta')->toDateString());
    }

    public function backoff(): array
    {
        return [300, 900];
    }

    public function handle(): void
    {
        $params = [
            '--tenant' => (string) $this->tenantId,
            '--sleep' => (string) max(0, $this->sleepSec),
        ];

        if (trim($this->baseUrl) !== '') {
            $params['--base-url'] = trim($this->baseUrl);
        }

        if ($this->date !== null && trim($this->date) !== '') {
            $params['--date'] = trim($this->date);
        }

        if ($this->dryRun) {
            $params['--dry-run'] = true;
        }

        $exitCode = Artisan::call('ops:send-reminders', $params);
        if ($exitCode !== 0) {
            throw new RuntimeException('ops:send-reminders failed: ' . trim(Artisan::output()));
        }
    }
}
