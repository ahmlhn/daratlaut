<?php

namespace App\Jobs;

use App\Models\Olt;
use App\Models\OltLog;
use App\Services\OltService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class AutoRegisterOnuBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    /**
     * @param array<int, array{fsp:string,sn:string}> $batchItems
     * @param array<int, array{fsp:string,sn:string}> $remainingItems
     */
    public function __construct(
        public int $tenantId,
        public int $oltId,
        public int $runLogId,
        public array $batchItems,
        public array $remainingItems,
        public string $namePrefix,
        public ?string $actor,
        public int $batchNumber,
        public int $totalBatches,
        public int $batchSize,
        public int $totalCount,
        public string $queueConnectionName,
        public string $queueName
    ) {
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("olt:auto-register:{$this->tenantId}:{$this->oltId}"))
                ->shared()
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 300),
        ];
    }

    public function backoff(): array
    {
        return [300, 900];
    }

    public function handle(): void
    {
        $olt = Olt::forTenant($this->tenantId)->find($this->oltId);
        if (!$olt) {
            $this->markRunFailed('OLT tidak ditemukan atau tidak lagi aktif untuk tenant ini.');
            return;
        }

        $summary = $this->readRunSummary();
        $summary['mode'] = 'queued_batches';
        $summary['queue_connection'] = $this->queueConnectionName;
        $summary['queue'] = $this->queueName;
        $summary['batch_size'] = $this->batchSize;
        $summary['total_count'] = $this->totalCount;
        $summary['total_batches'] = $this->totalBatches;
        $summary['current_batch'] = $this->batchNumber;
        $summary['state_text'] = "Memproses batch {$this->batchNumber}/{$this->totalBatches}.";
        $summary['started_at'] = $summary['started_at'] ?? now()->toDateTimeString();
        OltLog::updateLog($this->runLogId, 'processing', $summary, null, $this->actor);
        $service = null;
        $batchSuccess = 0;
        $batchError = 0;
        $batchSuccessKeys = [];
        $batchFailures = [];
        $logExcerpt = '';

        try {
            $service = new OltService($this->tenantId);
            $service->setSuppressActionLog(true);
            $service->connect($olt);
            $service->startTrace();

            foreach ($this->batchItems as $item) {
                $fsp = trim((string) ($item['fsp'] ?? ''));
                $sn = strtoupper(trim((string) ($item['sn'] ?? '')));
                if ($fsp === '' || $sn === '') {
                    continue;
                }

                $onuName = $this->namePrefix !== '' ? "{$this->namePrefix}-{$sn}" : "ONU-{$sn}";
                $onuName = substr($onuName, 0, 32);

                try {
                    $service->registerOnu($fsp, $sn, $onuName, null);
                    $batchSuccess++;
                    $batchSuccessKeys[] = $this->makeItemKey($fsp, $sn);
                } catch (Throwable $e) {
                    $batchError++;
                    $batchFailures[] = [
                        'fsp' => $fsp,
                        'sn' => $sn,
                        'message' => $e->getMessage(),
                    ];
                }
            }

            $logExcerpt = $service->getTraceText(20000);
            $service->disconnect();
            if ($batchSuccess > 0) {
                $olt->markWriteConfigPending();
            }

            $summary = $this->readRunSummary();
            $summary['mode'] = 'queued_batches';
            $summary['queue_connection'] = $this->queueConnectionName;
            $summary['queue'] = $this->queueName;
            $summary['batch_size'] = $this->batchSize;
            $summary['total_count'] = $this->totalCount;
            $summary['total_batches'] = $this->totalBatches;
            $summary['processed_batches'] = min($this->batchNumber, (int) ($summary['processed_batches'] ?? 0) + 1);
            $summary['current_batch'] = $this->batchNumber;
            $summary['success'] = (int) ($summary['success'] ?? 0) + $batchSuccess;
            $summary['error'] = (int) ($summary['error'] ?? 0) + $batchError;
            $summary['processed_count'] = min(
                $this->totalCount,
                (int) ($summary['success'] ?? 0) + (int) ($summary['error'] ?? 0)
            );
            $summary['remaining_count'] = max(0, $this->totalCount - (int) $summary['processed_count']);
            $summary['last_batch'] = [
                'number' => $this->batchNumber,
                'count' => count($this->batchItems),
                'success' => $batchSuccess,
                'error' => $batchError,
            ];
            $summary['success_keys'] = $this->mergeSuccessKeys($summary['success_keys'] ?? [], $batchSuccessKeys);
            $summary['failed_items'] = $this->mergeFailures($summary['failed_items'] ?? [], $batchFailures);

            if (!empty($this->remainingItems)) {
                $summary['state_text'] = "Batch {$this->batchNumber}/{$this->totalBatches} selesai. Menunggu batch berikutnya.";
                $updatedLogText = $this->appendLogChunk(
                    $this->readLogText(),
                    "Batch {$this->batchNumber}/{$this->totalBatches}: success {$batchSuccess}, error {$batchError}.",
                    $logExcerpt
                );
                OltLog::updateLog(
                    $this->runLogId,
                    'queued',
                    $summary,
                    $updatedLogText,
                    $this->actor
                );
                $nextBatchItems = array_slice($this->remainingItems, 0, $this->batchSize);
                $nextRemaining = array_slice($this->remainingItems, $this->batchSize);

                static::dispatch(
                    $this->tenantId,
                    $this->oltId,
                    $this->runLogId,
                    $nextBatchItems,
                    $nextRemaining,
                    $this->namePrefix,
                    $this->actor,
                    $this->batchNumber + 1,
                    $this->totalBatches,
                    $this->batchSize,
                    $this->totalCount,
                    $this->queueConnectionName,
                    $this->queueName
                )
                    ->onConnection($this->queueConnectionName)
                    ->onQueue($this->queueName);

                return;
            }

            $summary['state_text'] = $summary['error'] > 0
                ? 'Auto register selesai dengan sebagian error.'
                : 'Auto register selesai.';
            $summary['finished_at'] = now()->toDateTimeString();
            $updatedLogText = $this->appendLogChunk(
                $this->readLogText(),
                "Batch {$this->batchNumber}/{$this->totalBatches}: success {$batchSuccess}, error {$batchError}.",
                $logExcerpt
            );
            OltLog::updateLog(
                $this->runLogId,
                'done',
                $summary,
                $updatedLogText,
                $this->actor
            );
        } catch (Throwable $e) {
            try {
                if ($service) {
                    $logExcerpt = $service->getTraceText(20000);
                    $service->disconnect();
                }
            } catch (Throwable) {
                // ignore disconnect errors
            }

            $summary = $this->readRunSummary();
            $summary['current_batch'] = $this->batchNumber;
            $summary['state_text'] = "Batch {$this->batchNumber}/{$this->totalBatches} gagal, menunggu retry queue.";
            $updatedLogText = $this->appendLogChunk(
                $this->readLogText(),
                "Batch {$this->batchNumber}/{$this->totalBatches} gagal pada attempt {$this->attempts()}: {$e->getMessage()}",
                $logExcerpt
            );
            OltLog::updateLog(
                $this->runLogId,
                'processing',
                $summary,
                $updatedLogText,
                $this->actor
            );
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $summary = $this->readRunSummary();
        $summary['current_batch'] = $this->batchNumber;
        $summary['state_text'] = "Batch {$this->batchNumber}/{$this->totalBatches} gagal permanen.";
        $summary['finished_at'] = now()->toDateTimeString();
        $summary['message'] = $e->getMessage();
        $updatedLogText = $this->appendLogChunk(
            $this->readLogText(),
            "Batch {$this->batchNumber}/{$this->totalBatches} gagal permanen: {$e->getMessage()}",
            null
        );

        OltLog::updateLog(
            $this->runLogId,
            'error',
            $summary,
            $updatedLogText,
            $this->actor
        );
    }

    private function markRunFailed(string $message): void
    {
        $summary = $this->readRunSummary();
        $summary['current_batch'] = $this->batchNumber;
        $summary['state_text'] = $message;
        $summary['finished_at'] = now()->toDateTimeString();
        $summary['message'] = $message;
        $updatedLogText = $this->appendLogChunk($this->readLogText(), $message, null);

        OltLog::updateLog(
            $this->runLogId,
            'error',
            $summary,
            $updatedLogText,
            $this->actor
        );
    }

    private function readRunSummary(): array
    {
        $table = (new OltLog())->getTable();
        $row = DB::table($table)->where('id', $this->runLogId)->first();
        $raw = data_get($row, 'summary_json') ?? data_get($row, 'command');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function readLogText(): string
    {
        $table = (new OltLog())->getTable();
        $row = DB::table($table)->where('id', $this->runLogId)->first();
        return trim((string) (data_get($row, 'log_text') ?? data_get($row, 'response') ?? ''));
    }

    /**
     * @param array<int, string> $existing
     * @param array<int, string> $newKeys
     * @return array<int, string>
     */
    private function mergeSuccessKeys(array $existing, array $newKeys): array
    {
        $map = [];
        foreach (array_merge($existing, $newKeys) as $key) {
            $normalized = strtoupper(trim((string) $key));
            if ($normalized !== '') {
                $map[$normalized] = $normalized;
            }
        }

        return array_values($map);
    }

    /**
     * @param array<int, array<string, mixed>> $existing
     * @param array<int, array<string, mixed>> $newFailures
     * @return array<int, array<string, mixed>>
     */
    private function mergeFailures(array $existing, array $newFailures): array
    {
        $items = [];
        foreach (array_merge($existing, $newFailures) as $failure) {
            if (!is_array($failure)) {
                continue;
            }
            $fsp = trim((string) ($failure['fsp'] ?? ''));
            $sn = strtoupper(trim((string) ($failure['sn'] ?? '')));
            $message = trim((string) ($failure['message'] ?? ''));
            if ($fsp === '' || $sn === '') {
                continue;
            }
            $items[$this->makeItemKey($fsp, $sn)] = [
                'fsp' => $fsp,
                'sn' => $sn,
                'message' => $message,
            ];
        }

        return array_values($items);
    }

    private function makeItemKey(string $fsp, string $sn): string
    {
        return trim($fsp) . ':' . strtoupper(trim($sn));
    }

    private function appendLogChunk(string $existing, string $message, ?string $excerpt): string
    {
        $parts = [];
        $existing = trim($existing);
        if ($existing !== '') {
            $parts[] = $existing;
        }

        $message = trim($message);
        if ($message !== '') {
            $parts[] = '[' . now()->format('Y-m-d H:i:s') . '] ' . $message;
        }

        $excerpt = trim((string) $excerpt);
        if ($excerpt !== '') {
            $parts[] = $excerpt;
        }

        $text = implode("\n\n", $parts);
        if (strlen($text) > 60000) {
            $text = substr($text, -60000);
        }

        return $text;
    }
}
