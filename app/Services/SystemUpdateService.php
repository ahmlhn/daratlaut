<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use ZipArchive;

final class SystemUpdateService
{
    private function cfg(string $key, mixed $default = null): mixed
    {
        return config('system_update.' . $key, $default);
    }

    private function baseDir(): string
    {
        return storage_path('app/system_update');
    }

    private function packagesDir(): string
    {
        return $this->baseDir() . DIRECTORY_SEPARATOR . 'packages';
    }

    private function workDir(): string
    {
        return $this->baseDir() . DIRECTORY_SEPARATOR . 'work';
    }

    private function statePath(): string
    {
        return $this->baseDir() . DIRECTORY_SEPARATOR . 'state.json';
    }

    private function logPath(): string
    {
        return $this->baseDir() . DIRECTORY_SEPARATOR . 'log.txt';
    }

    private function ensureDirs(): void
    {
        foreach ([$this->baseDir(), $this->packagesDir(), $this->workDir()] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException("Unable to create directory: {$dir}");
            }
        }
    }

    private function defaultState(): array
    {
        return [
            'stage' => 'idle', // idle|uploaded|ready|copying|finalize|done|error
            'error' => null,
            'package' => null,
            'extracted_path' => null,
            'package_root' => null,
            'manifest_path' => null,
            'manifest' => null,
            'finalize' => null,
            'updated_at' => null,
        ];
    }

    private function withLockedState(callable $fn): mixed
    {
        $this->ensureDirs();

        $statePath = $this->statePath();
        $fh = @fopen($statePath, 'c+');
        if (!is_resource($fh)) {
            throw new RuntimeException('Unable to open system update state file.');
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                throw new RuntimeException('Unable to lock system update state file.');
            }

            rewind($fh);
            $raw = stream_get_contents($fh);
            $state = $this->defaultState();
            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $state = array_replace_recursive($state, $decoded);
                }
            }

            $result = $fn($state);

            // Persist if state was changed inside callback.
            if (is_array($result) && isset($result['_save_state']) && $result['_save_state'] === true) {
                unset($result['_save_state']);
                $state = $result;
                $state['updated_at'] = now()->toIso8601String();

                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                fflush($fh);

                // Return the persisted state (includes updated_at).
                $result = $state;
            }

            return $result;
        } finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }

    private function appendLog(string $message): void
    {
        $this->ensureDirs();
        $line = '[' . now()->format('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        @file_put_contents($this->logPath(), $line, FILE_APPEND);
    }

    private function logTail(int $maxLines = 200): array
    {
        $path = $this->logPath();
        if (!is_file($path)) return [];

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') return [];

        $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
        $lines = array_values(array_filter($lines, fn ($l) => $l !== ''));
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, -$maxLines);
        }
        return $lines;
    }

    public function status(): array
    {
        $state = $this->withLockedState(function (array $state) {
            return $state;
        });

        if (!is_array($state)) $state = $this->defaultState();
        $state['log_tail'] = $this->logTail();
        $state['config'] = [
            'enabled' => (bool) $this->cfg('enabled', false),
            'chunk_size' => (int) $this->cfg('chunk_size', 200),
            'max_package_mb' => (int) $this->cfg('max_package_mb', 300),
            'allow_download' => (bool) $this->cfg('allow_download', false),
            'package_url_set' => (string) $this->cfg('package_url', '') !== '',
        ];

        return $state;
    }

    public function reset(): array
    {
        $this->ensureDirs();
        $this->appendLog('Reset requested.');

        // Clear state + working directories (keep log for audit).
        @unlink($this->statePath());
        $this->rmrf($this->workDir());
        @mkdir($this->workDir(), 0775, true);

        return $this->status();
    }

    public function upload(UploadedFile $file): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension is not available on this server.');
        }

        $maxMb = max(1, (int) $this->cfg('max_package_mb', 300));
        $maxBytes = $maxMb * 1024 * 1024;
        if ($file->getSize() !== null && (int) $file->getSize() > $maxBytes) {
            throw new RuntimeException("Package is too large. Max: {$maxMb} MB.");
        }

        $id = now()->format('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $zipName = 'update-' . $id . '.zip';
        $zipPath = $this->packagesDir() . DIRECTORY_SEPARATOR . $zipName;

        $file->move($this->packagesDir(), $zipName);

        $sha = @hash_file('sha256', $zipPath) ?: null;
        $size = @filesize($zipPath);

        $this->appendLog("Uploaded package: {$zipName}" . ($sha ? " (sha256 {$sha})" : ''));

        $this->withLockedState(function (array $state) use ($id, $zipName, $zipPath, $sha, $size) {
            $state = $this->defaultState();
            $state['stage'] = 'uploaded';
            $state['package'] = [
                'id' => $id,
                'filename' => $zipName,
                'zip_path' => $zipPath,
                'sha256' => $sha,
                'size_bytes' => is_numeric($size) ? (int) $size : null,
                'uploaded_at' => now()->toIso8601String(),
            ];
            $state['_save_state'] = true;
            return $state;
        });

        return $this->status();
    }

    public function downloadConfiguredPackage(): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension is not available on this server.');
        }

        if (!(bool) $this->cfg('allow_download', false)) {
            throw new RuntimeException('Download is disabled.');
        }

        $url = trim((string) $this->cfg('package_url', ''));
        if ($url === '') {
            throw new RuntimeException('SYSTEM_UPDATE_PACKAGE_URL is not set.');
        }
        if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) {
            throw new RuntimeException('Package URL must be http(s).');
        }

        $id = now()->format('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $zipName = 'update-' . $id . '.zip';
        $zipPath = $this->packagesDir() . DIRECTORY_SEPARATOR . $zipName;

        $this->appendLog("Downloading package from: {$url}");

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 60,
                'follow_location' => 1,
                'user_agent' => 'DaratLaut-SystemUpdate/1.0',
            ],
        ]);
        $bytes = @file_put_contents($zipPath, @file_get_contents($url, false, $ctx));
        if (!is_int($bytes) || $bytes <= 0) {
            @unlink($zipPath);
            throw new RuntimeException('Failed to download the package. Check URL and allow_url_fopen settings.');
        }

        $sha = @hash_file('sha256', $zipPath) ?: null;
        $size = @filesize($zipPath);

        $this->appendLog("Downloaded package: {$zipName}" . ($sha ? " (sha256 {$sha})" : ''));

        $this->withLockedState(function (array $state) use ($id, $zipName, $zipPath, $sha, $size) {
            $state = $this->defaultState();
            $state['stage'] = 'uploaded';
            $state['package'] = [
                'id' => $id,
                'filename' => $zipName,
                'zip_path' => $zipPath,
                'sha256' => $sha,
                'size_bytes' => is_numeric($size) ? (int) $size : null,
                'uploaded_at' => now()->toIso8601String(),
            ];
            $state['_save_state'] = true;
            return $state;
        });

        return $this->status();
    }

    public function start(): array
    {
        return $this->withLockedState(function (array $state) {
            try {
                $pkg = $state['package'] ?? null;
                if (!is_array($pkg) || empty($pkg['zip_path']) || !is_file((string) $pkg['zip_path'])) {
                    throw new RuntimeException('No uploaded package found.');
                }

                $zipPath = (string) $pkg['zip_path'];
                $extractDir = $this->workDir() . DIRECTORY_SEPARATOR . 'extract-' . ($pkg['id'] ?? bin2hex(random_bytes(4)));

                // Fresh extract dir.
                $this->rmrf($extractDir);
                if (!@mkdir($extractDir, 0775, true) && !is_dir($extractDir)) {
                    throw new RuntimeException('Unable to create extraction directory.');
                }

                $zip = new ZipArchive();
                $open = $zip->open($zipPath);
                if ($open !== true) {
                    throw new RuntimeException('Unable to open ZIP (code: ' . (string) $open . ').');
                }
                if (!$zip->extractTo($extractDir)) {
                    $zip->close();
                    throw new RuntimeException('Failed to extract ZIP.');
                }
                $zip->close();

                $root = $this->findLaravelRoot($extractDir);
                if ($root === null) {
                    throw new RuntimeException('Invalid package: cannot find Laravel root (artisan + bootstrap/app.php).');
                }

                $vendorIncluded = is_file($root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php');
                $buildIncluded = is_file($root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'manifest.json');

                $manifest = $this->buildManifest($root);
                $manifestPath = $extractDir . DIRECTORY_SEPARATOR . 'manifest.json';
                file_put_contents($manifestPath, json_encode($manifest['files'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                $total = count($manifest['files']);

                $this->appendLog('Prepared package root: ' . $root);
                $this->appendLog('Manifest files: ' . $total . ' (vendor ' . ($vendorIncluded ? 'YES' : 'NO') . ', build ' . ($buildIncluded ? 'YES' : 'NO') . ')');

                $state['stage'] = 'ready';
                $state['error'] = null;
                $state['extracted_path'] = $extractDir;
                $state['package_root'] = $root;
                $state['manifest_path'] = $manifestPath;
                $state['manifest'] = [
                    'total_files' => $total,
                    'index' => 0,
                    'copied' => 0,
                    'skipped' => 0,
                    'chunk_size' => (int) $this->cfg('chunk_size', 200),
                    'vendor_included' => $vendorIncluded,
                    'build_included' => $buildIncluded,
                    'excluded_count' => (int) ($manifest['excluded_count'] ?? 0),
                ];
                $state['finalize'] = [
                    'index' => 0,
                    'commands' => $this->finalizePlan(),
                ];

                $state['_save_state'] = true;
                return $state;
            } catch (\Throwable $e) {
                $this->appendLog('ERROR start: ' . $e->getMessage());
                $state['stage'] = 'error';
                $state['error'] = $e->getMessage();
                $state['_save_state'] = true;
                return $state;
            }
        });
    }

    public function step(): array
    {
        return $this->withLockedState(function (array $state) {
            try {
                $stage = (string) ($state['stage'] ?? 'idle');

                if ($stage === 'idle') {
                    throw new RuntimeException('No update in progress.');
                }
                if ($stage === 'uploaded') {
                    throw new RuntimeException('Package uploaded but not prepared. Click Prepare first.');
                }
                if ($stage === 'error' || $stage === 'done') {
                    return $state;
                }

                if ($stage === 'ready' || $stage === 'copying') {
                    return $this->copyNextChunk($state);
                }

                if ($stage === 'finalize') {
                    return $this->runNextFinalize($state);
                }

                throw new RuntimeException('Unknown stage: ' . $stage);
            } catch (\Throwable $e) {
                $this->appendLog('ERROR step: ' . $e->getMessage());
                $state['stage'] = 'error';
                $state['error'] = $e->getMessage();
                $state['_save_state'] = true;
                return $state;
            }
        });
    }

    private function copyNextChunk(array $state): array
    {
        $root = (string) ($state['package_root'] ?? '');
        $manifestPath = (string) ($state['manifest_path'] ?? '');
        $m = $state['manifest'] ?? null;
        if ($root === '' || !is_dir($root) || $manifestPath === '' || !is_file($manifestPath) || !is_array($m)) {
            throw new RuntimeException('Update state is incomplete. Reset and start again.');
        }

        $files = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($files)) {
            throw new RuntimeException('Manifest is invalid.');
        }

        $total = (int) ($m['total_files'] ?? count($files));
        $index = (int) ($m['index'] ?? 0);
        $chunkSize = max(10, (int) ($m['chunk_size'] ?? (int) $this->cfg('chunk_size', 200)));
        $end = min($total, $index + $chunkSize);

        $this->appendLog("Copy chunk: {$index}..{$end} / {$total}");

        $copied = (int) ($m['copied'] ?? 0);
        $skipped = (int) ($m['skipped'] ?? 0);

        for ($i = $index; $i < $end; $i++) {
            $rel = (string) ($files[$i] ?? '');
            if ($rel === '') {
                $skipped++;
                continue;
            }
            if (!$this->isSafeRelPath($rel)) {
                throw new RuntimeException('Unsafe path in manifest: ' . $rel);
            }

            $from = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
            $to = base_path(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel));

            if (!is_file($from)) {
                $skipped++;
                continue;
            }

            $dir = dirname($to);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Unable to create target directory: ' . $dir);
            }

            if (!@copy($from, $to)) {
                throw new RuntimeException('Failed to copy: ' . $rel);
            }

            $copied++;
        }

        $state['stage'] = ($end >= $total) ? 'finalize' : 'copying';
        $state['manifest']['index'] = $end;
        $state['manifest']['copied'] = $copied;
        $state['manifest']['skipped'] = $skipped;

        if ($state['stage'] === 'finalize') {
            $this->appendLog('Copy finished. Moving to finalize stage.');
        }

        $state['_save_state'] = true;
        return $state;
    }

    private function runNextFinalize(array $state): array
    {
        $f = $state['finalize'] ?? null;
        if (!is_array($f)) {
            throw new RuntimeException('Finalize plan missing.');
        }

        $commands = $f['commands'] ?? null;
        if (!is_array($commands) || empty($commands)) {
            throw new RuntimeException('Finalize plan is empty.');
        }

        $idx = (int) ($f['index'] ?? 0);
        if ($idx >= count($commands)) {
            $this->appendLog('Finalize done.');
            $state['stage'] = 'done';

            // Cleanup extracted work dir to save disk (package zip is kept).
            $extractDir = (string) ($state['extracted_path'] ?? '');
            if ($extractDir !== '' && str_starts_with($extractDir, $this->workDir()) && is_dir($extractDir)) {
                $this->rmrf($extractDir);
            }

            $state['_save_state'] = true;
            return $state;
        }

        $cmd = (string) $commands[$idx];
        $this->appendLog('Finalize: ' . $cmd);

        // Special-case: storage:link with shared-hosting fallback sync.
        if ($cmd === 'storage:link') {
            $code = Artisan::call('storage:link');
            $out = trim((string) Artisan::output());
            if ($out !== '') $this->appendLog($out);

            if ($code !== 0) {
                $this->appendLog('storage:link failed. Trying fallback sync to public/storage ...');
                if (!$this->syncStoragePublic()) {
                    throw new RuntimeException('storage:link failed and fallback sync failed.');
                }
                $this->appendLog('Fallback storage sync completed.');
            }
        } else {
            [$name, $params] = $this->parseArtisan($cmd);
            $code = Artisan::call($name, $params);
            $out = trim((string) Artisan::output());
            if ($out !== '') $this->appendLog($out);
            if ($code !== 0) {
                throw new RuntimeException("Artisan failed: {$cmd} (exit {$code})");
            }
        }

        $state['finalize']['index'] = $idx + 1;
        $state['_save_state'] = true;
        return $state;
    }

    private function finalizePlan(): array
    {
        return [
            'optimize:clear',
            'migrate --force',
            'config:cache',
            'route:cache',
            'view:cache',
            'storage:link',
        ];
    }

    private function parseArtisan(string $cmd): array
    {
        $cmd = trim($cmd);
        if ($cmd === 'migrate --force') {
            return ['migrate', ['--force' => true]];
        }
        return [$cmd, []];
    }

    private function findLaravelRoot(string $dir): ?string
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $it->setMaxDepth(4);

        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo) continue;
            if (!$file->isFile()) continue;
            if ($file->getFilename() !== 'artisan') continue;

            $root = $file->getPath();
            $bootstrap = $root . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
            if (is_file($bootstrap)) {
                return $root;
            }
        }

        return null;
    }

    private function buildManifest(string $root): array
    {
        $exclude = $this->cfg('exclude_paths', []);
        if (!is_array($exclude)) $exclude = [];

        $files = [];
        $excludedCount = 0;

        $rootNorm = rtrim(str_replace('\\', '/', $root), '/');

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo) continue;
            if (!$file->isFile()) continue;

            $path = (string) $file->getPathname();
            $pathNorm = str_replace('\\', '/', $path);

            if (!str_starts_with($pathNorm, $rootNorm . '/')) {
                continue;
            }

            $rel = substr($pathNorm, strlen($rootNorm) + 1);
            if ($rel === '') continue;

            if ($this->isExcluded($rel, $exclude)) {
                $excludedCount++;
                continue;
            }

            $files[] = $rel;
        }

        sort($files);

        return [
            'files' => array_values($files),
            'excluded_count' => $excludedCount,
        ];
    }

    private function isExcluded(string $rel, array $exclude): bool
    {
        $rel = str_replace('\\', '/', $rel);

        foreach ($exclude as $ex) {
            $ex = (string) $ex;
            if ($ex === '') continue;

            $ex = str_replace('\\', '/', $ex);
            if (str_ends_with($ex, '/')) {
                if (str_starts_with($rel . '/', $ex)) return true;
                continue;
            }
            if ($rel === $ex) return true;
            if (str_starts_with($rel, $ex . '/')) return true;
        }

        return false;
    }

    private function isSafeRelPath(string $rel): bool
    {
        $rel = str_replace('\\', '/', $rel);
        if ($rel === '' || str_starts_with($rel, '/')) return false;

        $parts = explode('/', $rel);
        foreach ($parts as $p) {
            if ($p === '' || $p === '.' || $p === '..') return false;
        }

        return true;
    }

    private function syncStoragePublic(): bool
    {
        $source = storage_path('app/public');
        $target = public_path('storage');

        if (!is_dir($source)) {
            return false;
        }

        if (is_link($target) || is_file($target)) {
            @unlink($target);
        }

        if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
            return false;
        }

        return $this->copyDirectory($source, $target);
    }

    private function copyDirectory(string $source, string $target): bool
    {
        $items = @scandir($source);
        if (!is_array($items)) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;

            $from = $source . DIRECTORY_SEPARATOR . $item;
            $to = $target . DIRECTORY_SEPARATOR . $item;

            if (is_dir($from)) {
                if (!is_dir($to) && !@mkdir($to, 0775, true) && !is_dir($to)) {
                    return false;
                }
                if (!$this->copyDirectory($from, $to)) {
                    return false;
                }
                continue;
            }

            if (!@copy($from, $to)) {
                return false;
            }
        }

        return true;
    }

    private function rmrf(string $path): void
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        if ($path === '' || $path === DIRECTORY_SEPARATOR) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $items = @scandir($path);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $this->rmrf($path . DIRECTORY_SEPARATOR . $item);
        }

        @rmdir($path);
    }
}
