<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
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

    private function installedPath(): string
    {
        return $this->baseDir() . DIRECTORY_SEPARATOR . 'installed.json';
    }

    private function githubTokenPath(): string
    {
        return $this->baseDir() . DIRECTORY_SEPARATOR . 'github_token.enc';
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

    public function logError(string $context, \Throwable $e): void
    {
        $msg = trim((string) $e->getMessage());
        if ($msg === '') $msg = get_class($e);
        $this->appendLog('ERROR ' . $context . ': ' . $msg);
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

    private function readInstalled(): ?array
    {
        $path = $this->installedPath();
        if (!is_file($path)) return null;

        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') return null;

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function writeInstalled(array $installed): void
    {
        $this->ensureDirs();
        $installed['installed_at'] = $installed['installed_at'] ?? now()->toIso8601String();
        @file_put_contents(
            $this->installedPath(),
            json_encode($installed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private function githubEnabled(): bool
    {
        return (bool) $this->cfg('github.enabled', false);
    }

    private function githubOwner(): string
    {
        return trim((string) $this->cfg('github.owner', ''));
    }

    private function githubRepo(): string
    {
        return trim((string) $this->cfg('github.repo', ''));
    }

    private function githubBranch(): string
    {
        $b = trim((string) $this->cfg('github.branch', 'main'));
        return $b !== '' ? $b : 'main';
    }

    private function githubReleaseTag(): string
    {
        $t = trim((string) $this->cfg('github.release_tag', 'panel-main-latest'));
        return $t !== '' ? $t : 'panel-main-latest';
    }

    private function githubReleaseAssetName(): string
    {
        $n = trim((string) $this->cfg('github.release_asset', 'update-package.zip'));
        return $n !== '' ? $n : 'update-package.zip';
    }

    private function githubApiBase(): string
    {
        $base = trim((string) $this->cfg('github.api_base', 'https://api.github.com'));
        return rtrim($base !== '' ? $base : 'https://api.github.com', '/');
    }

    private function githubApiVersion(): string
    {
        $v = trim((string) $this->cfg('github.api_version', '2022-11-28'));
        return $v !== '' ? $v : '2022-11-28';
    }

    private function githubTokenFromFile(): ?string
    {
        $path = $this->githubTokenPath();
        if (!is_file($path)) return null;

        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') return null;

        try {
            return Crypt::decryptString($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    private function githubToken(): ?string
    {
        // Prefer env (ops-managed), then fallback to encrypted file set via UI.
        $env = trim((string) $this->cfg('github.token', ''));
        if ($env !== '') return $env;

        $fileToken = $this->githubTokenFromFile();
        $fileToken = is_string($fileToken) ? trim($fileToken) : '';
        return $fileToken !== '' ? $fileToken : null;
    }

    private function githubTokenHint(): ?string
    {
        $t = $this->githubToken();
        if (!is_string($t) || $t === '') return null;
        $len = strlen($t);
        if ($len <= 4) return str_repeat('*', $len);
        return str_repeat('*', max(0, $len - 4)) . substr($t, -4);
    }

    private function githubRequest(): PendingRequest
    {
        $req = Http::timeout(90)
            ->connectTimeout(15)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => $this->githubApiVersion(),
                // GitHub API expects a User-Agent.
                'User-Agent' => 'DaratLaut-SystemUpdate/1.0',
            ])
            ->withOptions([
                'allow_redirects' => true,
            ]);

        $token = $this->githubToken();
        if (is_string($token) && $token !== '') {
            $req = $req->withToken($token);
        }

        return $req;
    }

    private function githubRequestForDownload(): PendingRequest
    {
        // Downloads of vendor-included packages can take longer on shared hosting.
        $req = Http::timeout(600)
            ->connectTimeout(30)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => $this->githubApiVersion(),
                'User-Agent' => 'DaratLaut-SystemUpdate/1.0',
            ])
            ->withOptions([
                'allow_redirects' => true,
            ]);

        $token = $this->githubToken();
        if (is_string($token) && $token !== '') {
            $req = $req->withToken($token);
        }

        return $req;
    }

    private function sniffFilePrefix(string $path, int $len = 64): array
    {
        $len = max(1, min(4096, (int) $len));
        $h = @fopen($path, 'rb');
        if (!is_resource($h)) {
            return ['hex' => null, 'ascii' => null];
        }

        $raw = @fread($h, $len);
        @fclose($h);

        if (!is_string($raw) || $raw === '') {
            return ['hex' => null, 'ascii' => null];
        }

        $hex = bin2hex(substr($raw, 0, min(16, strlen($raw))));
        $ascii = preg_replace('/[^\\x20-\\x7E]/', '.', $raw);
        $ascii = is_string($ascii) ? substr($ascii, 0, 120) : null;

        return ['hex' => $hex, 'ascii' => $ascii];
    }

    private function looksLikeZip(string $path): bool
    {
        $h = @fopen($path, 'rb');
        if (!is_resource($h)) {
            return false;
        }
        $prefix = @fread($h, 2);
        @fclose($h);
        return is_string($prefix) && $prefix === 'PK';
    }

    private function githubDownloadAssetToFile(string $assetApiUrl, string $destPath, ?int $expectedSize = null): void
    {
        $this->ensureDirs();

        $tmp = $destPath . '.tmp';
        @unlink($tmp);

        // 1) Hit the GitHub API asset endpoint but do NOT follow redirects automatically.
        // Some HTTP clients strip Authorization on cross-host redirects; manual follow is more reliable.
        $res = $this->githubRequestForDownload()
            ->withHeaders(['Accept' => 'application/octet-stream'])
            ->withOptions(['allow_redirects' => false])
            ->sink($tmp)
            ->get($assetApiUrl);

        $status = (int) $res->status();

        if ($status >= 300 && $status < 400) {
            $location = trim((string) ($res->header('Location') ?? ''));
            @unlink($tmp);

            if ($location === '') {
                throw new RuntimeException('GitHub asset download redirect missing Location header.');
            }

            $host = strtolower((string) (parse_url($location, PHP_URL_HOST) ?? ''));

            $req2 = Http::timeout(600)
                ->connectTimeout(30)
                ->withHeaders([
                    'User-Agent' => 'DaratLaut-SystemUpdate/1.0',
                    'Accept' => '*/*',
                ])
                ->withOptions([
                    'allow_redirects' => true,
                ])
                ->sink($tmp);

            // Only send token to GitHub hosts (avoid leaking to arbitrary redirects).
            if ($host === 'github.com' || $host === 'api.github.com' || str_ends_with($host, '.github.com')) {
                $token = $this->githubToken();
                if (is_string($token) && $token !== '') {
                    $req2 = $req2->withToken($token);
                }
            }

            $res2 = $req2->get($location);
            if (!$res2->successful()) {
                $msg = (string) ($res2->json('message') ?? $res2->body() ?? '');
                $msg = trim($msg);
                @unlink($tmp);
                throw new RuntimeException('GitHub asset redirect download error (' . $res2->status() . '): ' . ($msg !== '' ? $msg : 'request failed'));
            }
        } elseif (!$res->successful()) {
            $msg = (string) ($res->json('message') ?? $res->body() ?? '');
            $msg = trim($msg);
            @unlink($tmp);
            throw new RuntimeException('GitHub asset API error (' . $res->status() . '): ' . ($msg !== '' ? $msg : 'request failed'));
        }

        $size = @filesize($tmp);
        if (!is_numeric($size) || (int) $size <= 0) {
            @unlink($tmp);
            throw new RuntimeException('Downloaded release asset is empty.');
        }

        // If GitHub gave us an expected size, enforce it to detect partial/HTML downloads early.
        if (is_int($expectedSize) && $expectedSize > 0 && (int) $size !== $expectedSize) {
            $sniff = $this->sniffFilePrefix($tmp, 128);
            @unlink($tmp);
            throw new RuntimeException(
                'Downloaded asset size mismatch. expected=' . $expectedSize . ' got=' . (int) $size .
                ($sniff['hex'] ? (' prefix_hex=' . $sniff['hex']) : '') .
                ($sniff['ascii'] ? (' prefix_ascii=' . $sniff['ascii']) : '')
            );
        }

        // Validate ZIP signature. If we got HTML/JSON instead, show a helpful error.
        if (!$this->looksLikeZip($tmp)) {
            $sniff = $this->sniffFilePrefix($tmp, 256);
            @unlink($tmp);
            throw new RuntimeException(
                'Downloaded release asset is not a ZIP.' .
                ($sniff['hex'] ? (' prefix_hex=' . $sniff['hex']) : '') .
                ($sniff['ascii'] ? (' prefix_ascii=' . $sniff['ascii']) : '')
            );
        }

        @unlink($destPath);
        if (!@rename($tmp, $destPath)) {
            // Cross-device rename fallback.
            if (!@copy($tmp, $destPath)) {
                @unlink($tmp);
                throw new RuntimeException('Unable to move downloaded asset into place.');
            }
            @unlink($tmp);
        }
    }

    private function githubParseReleaseMeta(?string $body): ?array
    {
        if (!is_string($body)) return null;
        $raw = trim($body);
        if ($raw === '') return null;

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return $decoded;

        // Fallback: try to extract a JSON object from a longer body.
        if (preg_match('/\\{.*\\}/s', $raw, $m) === 1) {
            $decoded2 = json_decode((string) $m[0], true);
            if (is_array($decoded2)) return $decoded2;
        }

        return null;
    }

    private function githubGetBuiltRelease(): array
    {
        if (!$this->githubEnabled()) {
            throw new RuntimeException('GitHub update is disabled.');
        }

        $owner = $this->githubOwner();
        $repo = $this->githubRepo();
        if ($owner === '' || $repo === '') {
            throw new RuntimeException('GitHub repo is not configured. Set SYSTEM_UPDATE_GITHUB_OWNER and SYSTEM_UPDATE_GITHUB_REPO.');
        }
        if ($this->githubToken() === null) {
            throw new RuntimeException('GitHub token is missing. Set SYSTEM_UPDATE_GITHUB_TOKEN or save token from the panel.');
        }

        $tag = $this->githubReleaseTag();
        $url = $this->githubApiBase() . "/repos/{$owner}/{$repo}/releases/tags/{$tag}";
        $res = $this->githubRequest()->get($url);
        if (!$res->successful()) {
            if ($res->status() === 404) {
                throw new RuntimeException(
                    "GitHub release tag '{$tag}' not found. " .
                    "Run the GitHub Actions workflow to publish the built package, " .
                    "or create a Release with asset '{$this->githubReleaseAssetName()}'."
                );
            }
            $msg = (string) ($res->json('message') ?? $res->body() ?? '');
            $msg = trim($msg);
            throw new RuntimeException('GitHub release API error (' . $res->status() . '): ' . ($msg !== '' ? $msg : 'request failed'));
        }

        $json = $res->json();
        if (!is_array($json)) {
            throw new RuntimeException('GitHub release API returned invalid response.');
        }

        return $json;
    }

    private function githubBuiltInfo(): array
    {
        $release = $this->githubGetBuiltRelease();
        $meta = $this->githubParseReleaseMeta((string) ($release['body'] ?? ''));

        $assetName = $this->githubReleaseAssetName();
        $assets = $release['assets'] ?? [];
        if (!is_array($assets)) $assets = [];

        $asset = null;
        foreach ($assets as $a) {
            if (!is_array($a)) continue;
            if ((string) ($a['name'] ?? '') === $assetName) {
                $asset = $a;
                break;
            }
        }

        return [
            'release' => [
                'tag' => (string) ($release['tag_name'] ?? $this->githubReleaseTag()),
                'name' => (string) ($release['name'] ?? ''),
                'published_at' => (string) ($release['published_at'] ?? ''),
                'html_url' => (string) ($release['html_url'] ?? ''),
            ],
            'meta' => $meta,
            'asset' => $asset ? [
                'id' => $asset['id'] ?? null,
                'name' => (string) ($asset['name'] ?? ''),
                'size' => $asset['size'] ?? null,
                'url' => (string) ($asset['url'] ?? ''), // API URL (download via octet-stream)
                'browser_download_url' => (string) ($asset['browser_download_url'] ?? ''),
            ] : null,
        ];
    }

    public function status(): array
    {
        $state = $this->withLockedState(function (array $state) {
            return $state;
        });

        if (!is_array($state)) $state = $this->defaultState();
        $state['log_tail'] = $this->logTail();
        $state['installed'] = $this->readInstalled();
        $state['github'] = [
            'enabled' => $this->githubEnabled(),
            'owner' => $this->githubOwner(),
            'repo' => $this->githubRepo(),
            'branch' => $this->githubBranch(),
            'release_tag' => $this->githubReleaseTag(),
            'release_asset' => $this->githubReleaseAssetName(),
            'configured' => ($this->githubOwner() !== '' && $this->githubRepo() !== ''),
            'token_present' => $this->githubToken() !== null,
            'token_hint' => $this->githubTokenHint(),
        ];
        $state['config'] = [
            'enabled' => (bool) $this->cfg('enabled', false),
            'chunk_size' => (int) $this->cfg('chunk_size', 200),
            'max_package_mb' => (int) $this->cfg('max_package_mb', 300),
            'allow_download' => (bool) $this->cfg('allow_download', false),
            'package_url_set' => (string) $this->cfg('package_url', '') !== '',
        ];

        return $state;
    }

    public function githubSaveToken(string $token): array
    {
        if (!$this->githubEnabled()) {
            throw new RuntimeException('GitHub update is disabled.');
        }

        $token = trim($token);
        if ($token === '') {
            throw new RuntimeException('Token is empty.');
        }
        if (strlen($token) < 10) {
            throw new RuntimeException('Token is too short.');
        }

        $this->ensureDirs();
        @file_put_contents($this->githubTokenPath(), Crypt::encryptString($token));
        $this->appendLog('GitHub token updated.');
        return $this->status();
    }

    public function githubClearToken(): array
    {
        if (!$this->githubEnabled()) {
            throw new RuntimeException('GitHub update is disabled.');
        }

        $this->ensureDirs();
        @unlink($this->githubTokenPath());
        $this->appendLog('GitHub token cleared (file-based).');
        return $this->status();
    }

    public function githubCheckLatest(): array
    {
        if (!$this->githubEnabled()) {
            throw new RuntimeException('GitHub update is disabled.');
        }

        $owner = $this->githubOwner();
        $repo = $this->githubRepo();
        $branch = $this->githubBranch();
        if ($owner === '' || $repo === '') {
            throw new RuntimeException('GitHub repo is not configured. Set SYSTEM_UPDATE_GITHUB_OWNER and SYSTEM_UPDATE_GITHUB_REPO.');
        }
        if ($this->githubToken() === null) {
            throw new RuntimeException('GitHub token is missing. Set SYSTEM_UPDATE_GITHUB_TOKEN or save token from the panel.');
        }

        $url = $this->githubApiBase() . "/repos/{$owner}/{$repo}/commits/{$branch}";
        $res = $this->githubRequest()->get($url);
        if (!$res->successful()) {
            $msg = (string) ($res->json('message') ?? $res->body() ?? '');
            $msg = trim($msg);
            throw new RuntimeException('GitHub API error (' . $res->status() . '): ' . ($msg !== '' ? $msg : 'request failed'));
        }

        $sha = (string) ($res->json('sha') ?? '');
        if ($sha === '') {
            throw new RuntimeException('GitHub API response missing sha.');
        }

        $message = (string) ($res->json('commit.message') ?? '');
        $firstLine = trim(strtok($message, "\n") ?: $message);
        $date = (string) ($res->json('commit.committer.date') ?? $res->json('commit.author.date') ?? '');
        $html = (string) ($res->json('html_url') ?? '');

        $installed = $this->readInstalled();
        $installedSha = is_array($installed) ? (string) (($installed['github']['sha'] ?? '') ?: '') : '';
        $upToDate = ($installedSha !== '' && strtolower($installedSha) === strtolower($sha));

        return [
            'latest' => [
                'sha' => $sha,
                'short' => substr($sha, 0, 7),
                'message' => $firstLine,
                'date' => $date,
                'html_url' => $html !== '' ? $html : null,
                'branch' => $branch,
            ],
            'installed' => $installed,
            'update_available' => ($installedSha === '' ? null : !$upToDate),
        ];
    }

    public function githubDownloadLatest(): array
    {
        $check = $this->githubCheckLatest();
        $latest = $check['latest'] ?? null;
        if (!is_array($latest) || empty($latest['sha'])) {
            throw new RuntimeException('Latest commit info is missing.');
        }

        $owner = $this->githubOwner();
        $repo = $this->githubRepo();
        $sha = (string) $latest['sha'];

        $id = now()->format('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $zipName = 'github-' . substr($sha, 0, 7) . '-' . $id . '.zip';
        $zipPath = $this->packagesDir() . DIRECTORY_SEPARATOR . $zipName;

        $zipUrl = $this->githubApiBase() . "/repos/{$owner}/{$repo}/zipball/{$sha}";
        $this->appendLog("Downloading GitHub zipball: {$owner}/{$repo}@{$sha}");

        $res = $this->githubRequestForDownload()->sink($zipPath)->get($zipUrl);
        if (!$res->successful()) {
            @unlink($zipPath);
            $msg = (string) ($res->json('message') ?? $res->body() ?? '');
            $msg = trim($msg);
            throw new RuntimeException('GitHub download error (' . $res->status() . '): ' . ($msg !== '' ? $msg : 'request failed'));
        }

        $size = @filesize($zipPath);
        if (!is_numeric($size) || (int) $size <= 0) {
            @unlink($zipPath);
            throw new RuntimeException('Downloaded zipball is empty.');
        }

        $sha256 = @hash_file('sha256', $zipPath) ?: null;
        $this->appendLog("Downloaded GitHub package: {$zipName}" . ($sha256 ? " (sha256 {$sha256})" : ''));

        $this->withLockedState(function (array $state) use ($id, $zipName, $zipPath, $sha256, $size, $latest) {
            $state = $this->defaultState();
            $state['stage'] = 'uploaded';
            $state['package'] = [
                'id' => $id,
                'filename' => $zipName,
                'zip_path' => $zipPath,
                'sha256' => $sha256,
                'size_bytes' => is_numeric($size) ? (int) $size : null,
                'uploaded_at' => now()->toIso8601String(),
                'source' => 'github',
                'github' => [
                    'owner' => $this->githubOwner(),
                    'repo' => $this->githubRepo(),
                    'branch' => (string) ($latest['branch'] ?? $this->githubBranch()),
                    'sha' => (string) ($latest['sha'] ?? ''),
                ],
            ];
            $state['_save_state'] = true;
            return $state;
        });

        return $this->status();
    }

    public function githubCheckBuiltLatest(): array
    {
        $latest = $this->githubCheckLatest();
        $built = $this->githubBuiltInfo();

        $latestSha = (string) (($latest['latest']['sha'] ?? '') ?: '');
        $installed = $this->readInstalled();
        $installedSha = is_array($installed) ? (string) (($installed['github']['sha'] ?? '') ?: '') : '';

        $builtSha = '';
        $meta = $built['meta'] ?? null;
        if (is_array($meta) && isset($meta['sha'])) {
            $builtSha = (string) $meta['sha'];
        }

        $buildReady = ($latestSha !== '' && $builtSha !== '' && strtolower($latestSha) === strtolower($builtSha));

        $updateAvailable = null;
        if ($installedSha !== '' && $buildReady) {
            $updateAvailable = (strtolower($installedSha) !== strtolower($builtSha));
        }

        return [
            'latest' => $latest['latest'] ?? null,
            'built' => $built,
            'installed' => $installed,
            'build_ready' => $buildReady,
            'update_available' => $updateAvailable,
        ];
    }

    public function githubDownloadBuiltLatest(): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension is not available on this server.');
        }

        $check = $this->githubCheckBuiltLatest();

        $latest = $check['latest'] ?? null;
        $built = $check['built'] ?? null;
        if (!is_array($latest) || empty($latest['sha'])) {
            throw new RuntimeException('Latest commit info is missing.');
        }
        if (!is_array($built)) {
            throw new RuntimeException('Built release info is missing.');
        }

        $buildReady = (bool) ($check['build_ready'] ?? false);
        if (!$buildReady) {
            $metaSha = is_array($built['meta'] ?? null) ? (string) (($built['meta']['sha'] ?? '') ?: '') : '';
            throw new RuntimeException(
                'Build artifact is not ready for latest main commit. ' .
                'Latest: ' . substr((string) $latest['sha'], 0, 7) .
                ($metaSha !== '' ? ('; built: ' . substr($metaSha, 0, 7)) : '; built: unknown')
            );
        }

        $asset = $built['asset'] ?? null;
        if (!is_array($asset) || empty($asset['url']) || empty($asset['name'])) {
            throw new RuntimeException('Release asset not found. Expected: ' . $this->githubReleaseAssetName());
        }

        $sha = (string) $latest['sha'];
        $short = substr($sha, 0, 7);
        $id = now()->format('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $zipName = 'github-release-' . $short . '-' . $id . '.zip';
        $zipPath = $this->packagesDir() . DIRECTORY_SEPARATOR . $zipName;

        $this->appendLog('Downloading GitHub release asset: ' . (string) ($asset['name'] ?? '') . ' (sha ' . $short . ')');

        // Download via GitHub API asset endpoint (octet-stream), with manual redirect follow and ZIP validation.
        $assetUrl = (string) $asset['url'];
        $expectedSize = is_numeric($asset['size'] ?? null) ? (int) $asset['size'] : null;
        $this->githubDownloadAssetToFile($assetUrl, $zipPath, $expectedSize);

        $size = @filesize($zipPath);

        $sha256 = @hash_file('sha256', $zipPath) ?: null;
        $this->appendLog("Downloaded GitHub release package: {$zipName}" . ($sha256 ? " (sha256 {$sha256})" : ''));

        $meta = is_array($built['meta'] ?? null) ? $built['meta'] : [];

        $this->withLockedState(function (array $state) use ($id, $zipName, $zipPath, $sha256, $size, $latest, $meta) {
            $state = $this->defaultState();
            $state['stage'] = 'uploaded';
            $state['package'] = [
                'id' => $id,
                'filename' => $zipName,
                'zip_path' => $zipPath,
                'sha256' => $sha256,
                'size_bytes' => is_numeric($size) ? (int) $size : null,
                'uploaded_at' => now()->toIso8601String(),
                'source' => 'github_release',
                'github' => [
                    'owner' => $this->githubOwner(),
                    'repo' => $this->githubRepo(),
                    'branch' => (string) ($latest['branch'] ?? $this->githubBranch()),
                    'sha' => (string) ($latest['sha'] ?? ''),
                    'release_tag' => $this->githubReleaseTag(),
                    'meta' => $meta,
                ],
            ];
            $state['_save_state'] = true;
            return $state;
        });

        return $this->status();
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
                'source' => 'upload',
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

        $this->withLockedState(function (array $state) use ($id, $zipName, $zipPath, $sha, $size, $url) {
            $state = $this->defaultState();
            $state['stage'] = 'uploaded';
            $state['package'] = [
                'id' => $id,
                'filename' => $zipName,
                'zip_path' => $zipPath,
                'sha256' => $sha,
                'size_bytes' => is_numeric($size) ? (int) $size : null,
                'uploaded_at' => now()->toIso8601String(),
                'source' => 'url',
                'source_url' => $url,
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
                    $size = @filesize($zipPath);
                    $sniff = $this->sniffFilePrefix($zipPath, 256);
                    throw new RuntimeException(
                        'Unable to open ZIP (code: ' . (string) $open . ').' .
                        (is_numeric($size) ? (' size=' . (int) $size) : '') .
                        ($sniff['hex'] ? (' prefix_hex=' . $sniff['hex']) : '') .
                        ($sniff['ascii'] ? (' prefix_ascii=' . $sniff['ascii']) : '')
                    );
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

            // Record installed version (persists even if the update state is reset).
            $pkg = $state['package'] ?? null;
            if (is_array($pkg)) {
                $installed = [
                    'source' => (string) ($pkg['source'] ?? 'unknown'),
                    'package' => [
                        'filename' => $pkg['filename'] ?? null,
                        'sha256' => $pkg['sha256'] ?? null,
                        'size_bytes' => $pkg['size_bytes'] ?? null,
                    ],
                ];
                if (isset($pkg['source_url'])) {
                    $installed['package']['source_url'] = (string) $pkg['source_url'];
                }
                if (isset($pkg['github']) && is_array($pkg['github'])) {
                    $installed['github'] = $pkg['github'];
                }

                $this->writeInstalled($installed);
            }

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
            'package:discover',
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
