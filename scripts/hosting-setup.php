#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Shared-hosting deploy helper for backend-laravel.
 *
 * Usage:
 *   php scripts/hosting-setup.php
 */
final class HostingSetup
{
    private string $rootPath;
    private string $envPath;
    private string $envExamplePath;
    private bool $envOnly;
    /** @var string[] */
    private array $envLines = [];
    private bool $envChanged = false;
    /** @var object|null */
    private $laravelKernel = null;

    public function __construct(string $rootPath, bool $envOnly = false)
    {
        $this->rootPath = rtrim($rootPath, DIRECTORY_SEPARATOR);
        $this->envPath = $this->rootPath . DIRECTORY_SEPARATOR . '.env';
        $this->envExamplePath = $this->rootPath . DIRECTORY_SEPARATOR . '.env.example';
        $this->envOnly = $envOnly;
    }

    public function run(): int
    {
        $this->printHeader('Hosting setup started');

        if (!$this->prepareEnvFile()) {
            return 1;
        }

        $this->loadEnvLines();
        $this->normalizeEnv();
        $this->saveEnvIfChanged();

        if ($this->envOnly) {
            $this->printHeader('Done');
            $this->printLine('Environment preflight completed.');
            return 0;
        }

        $this->printHeader('Running artisan tasks');

        $commands = [
            'php artisan optimize:clear',
            'php artisan migrate --force',
            'php artisan config:cache',
            'php artisan route:cache',
            'php artisan view:cache',
        ];

        foreach ($commands as $command) {
            if ($this->runCommand($command) !== 0) {
                $this->printLine("Failed: {$command}");
                return 1;
            }
        }

        $storageLinked = $this->runCommand('php artisan storage:link') === 0;

        if (!$storageLinked) {
            $this->printLine('`storage:link` failed. Trying fallback sync to `public/storage` ...');
            if (!$this->syncStoragePublic()) {
                $this->printLine('Storage fallback sync failed. Files under /storage/* may be unavailable.');
                return 1;
            }
            $this->printLine('Fallback storage sync completed.');
        }

        $this->printHeader('Done');
        $this->printLine('Hosting setup completed successfully.');
        $this->printLine('If uploads are added and symlink is unavailable, rerun this script to resync public/storage.');

        return 0;
    }

    private function prepareEnvFile(): bool
    {
        if (is_file($this->envPath)) {
            return true;
        }

        if (!is_file($this->envExamplePath)) {
            $this->printLine('Missing both `.env` and `.env.example`.');
            return false;
        }

        if (!@copy($this->envExamplePath, $this->envPath)) {
            $this->printLine('Unable to create `.env` from `.env.example`.');
            return false;
        }

        $this->printLine('Created `.env` from `.env.example`.');
        return true;
    }

    private function loadEnvLines(): void
    {
        $content = file_get_contents($this->envPath);
        if ($content === false) {
            throw new RuntimeException('Unable to read `.env`.');
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        $this->envLines = explode("\n", $normalized);
    }

    private function normalizeEnv(): void
    {
        $appName = $this->getEnvValue('APP_NAME');
        if ($appName !== null && $appName !== '') {
            $trimmed = trim($appName);
            if (!$this->isQuoted($trimmed) && preg_match('/\s/', $trimmed) === 1) {
                $this->setEnvValue('APP_NAME', $this->quoteValue($trimmed), true);
            }
        }

        $this->setIfEmpty('APP_ENV', 'production');
        $this->setIfEmpty('APP_DEBUG', 'false');

        // Shared-hosting safe defaults.
        // If `.env` was created from `.env.example`, these are often set to "database"
        // which fails on many cPanel setups (missing sessions/cache/jobs tables).
        $this->forceIfIn('SESSION_DRIVER', ['', 'database'], 'file');
        $this->forceIfIn('CACHE_STORE', ['', 'database'], 'file');
        $this->forceIfIn('QUEUE_CONNECTION', ['', 'database'], 'sync');
        $this->forceIfIn('APP_MAINTENANCE_STORE', ['', 'database'], 'file');

        // This project uses MySQL. Avoid SQLite file path failures when `.env` is still the default template.
        $this->forceIfIn('DB_CONNECTION', ['', 'sqlite'], 'mysql');

        $appKey = trim((string) $this->getEnvValue('APP_KEY'));
        if ($appKey === '') {
            $generatedKey = 'base64:' . base64_encode(random_bytes(32));
            $this->setEnvValue('APP_KEY', $generatedKey);
            $this->printLine('Generated missing APP_KEY.');
        }
    }

    private function forceIfIn(string $key, array $badValues, string $value): void
    {
        $currentRaw = $this->getEnvValue($key);
        $current = strtolower(trim((string) $currentRaw));

        foreach ($badValues as $bad) {
            $badNorm = strtolower(trim((string) $bad));
            if ($badNorm === '' && ($currentRaw === null || trim((string) $currentRaw) === '')) {
                $this->setEnvValue($key, $value);
                return;
            }
            if ($badNorm !== '' && $current === $badNorm) {
                $this->setEnvValue($key, $value);
                return;
            }
        }
    }

    private function saveEnvIfChanged(): void
    {
        if (!$this->envChanged) {
            return;
        }

        $backupPath = $this->envPath . '.bak-' . date('Ymd-His');
        @copy($this->envPath, $backupPath);

        $out = implode("\n", $this->envLines);
        if (!str_ends_with($out, "\n")) {
            $out .= "\n";
        }

        file_put_contents($this->envPath, $out);
        $this->printLine("Updated `.env` (backup: " . basename($backupPath) . ').');
    }

    private function runCommand(string $command): int
    {
        // Many shared hostings disable process execution functions (passthru/exec/shell_exec/system).
        // Run artisan tasks internally via the Laravel Console Kernel so setup works even with strict
        // `disable_functions` policies.
        $command = trim($command);
        if (preg_match('/^php\s+artisan\s+(.+)$/', $command, $matches) === 1) {
            $artisanCommand = trim($matches[1]);

            // Special-case the only command in this script that uses options.
            if ($artisanCommand === 'migrate --force') {
                return $this->runArtisan('migrate', ['--force' => true]);
            }

            return $this->runArtisan($artisanCommand);
        }

        throw new RuntimeException("Unsupported command in hosting-setup: {$command}");
    }

    private function runArtisan(string $command, array $parameters = []): int
    {
        $this->printLine("> php artisan {$command}");

        $cwd = getcwd();
        chdir($this->rootPath);

        try {
            $kernel = $this->getLaravelKernel();

            // If symfony/console isn't available for some reason, Laravel will still run the command.
            $output = null;
            if (class_exists('Symfony\\Component\\Console\\Output\\ConsoleOutput')) {
                $output = new Symfony\Component\Console\Output\ConsoleOutput();
            }

            return (int) $kernel->call($command, $parameters, $output);
        } finally {
            if ($cwd !== false) {
                chdir($cwd);
            }
        }
    }

    private function getLaravelKernel(): object
    {
        if (is_object($this->laravelKernel)) {
            return $this->laravelKernel;
        }

        // If the deployment previously cached config/routes with bad env values (e.g. sqlite),
        // artisan commands like `optimize:clear` can fail before they get a chance to clear them.
        // Clear the cached bootstrap files first using plain file ops (works even on strict hosting).
        $this->preClearBootstrapCache();

        $autoloadPath = $this->rootPath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (!is_file($autoloadPath)) {
            throw new RuntimeException('Missing `vendor/autoload.php`. Run `composer install` first.');
        }

        require_once $autoloadPath;

        $bootstrapPath = $this->rootPath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
        if (!is_file($bootstrapPath)) {
            throw new RuntimeException('Missing `bootstrap/app.php`.');
        }

        $app = require $bootstrapPath;
        if (!is_object($app)) {
            throw new RuntimeException('Laravel bootstrap did not return an application instance.');
        }

        $kernel = $app->make('Illuminate\\Contracts\\Console\\Kernel');
        if (!is_object($kernel)) {
            throw new RuntimeException('Unable to resolve Laravel Console Kernel.');
        }

        $this->laravelKernel = $kernel;
        return $kernel;
    }

    private function preClearBootstrapCache(): void
    {
        $cacheDir = $this->rootPath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'cache';
        if (!is_dir($cacheDir)) {
            return;
        }

        $paths = [
            $cacheDir . DIRECTORY_SEPARATOR . 'config.php',
            $cacheDir . DIRECTORY_SEPARATOR . 'packages.php',
            $cacheDir . DIRECTORY_SEPARATOR . 'services.php',
            $cacheDir . DIRECTORY_SEPARATOR . 'events.php',
            // Laravel 11 route cache filename:
            $cacheDir . DIRECTORY_SEPARATOR . 'routes-v7.php',
            // Older variants (safe to attempt):
            $cacheDir . DIRECTORY_SEPARATOR . 'routes.php',
        ];

        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function syncStoragePublic(): bool
    {
        $source = $this->rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'public';
        $target = $this->rootPath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'storage';

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
            if ($item === '.' || $item === '..') {
                continue;
            }

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

    private function setIfEmpty(string $key, string $value): void
    {
        $current = $this->getEnvValue($key);
        if ($current === null || trim($current) === '') {
            $this->setEnvValue($key, $value);
        }
    }

    private function getEnvValue(string $key): ?string
    {
        foreach ($this->envLines as $line) {
            if (!preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/', $line, $matches)) {
                continue;
            }
            if ($matches[1] !== $key) {
                continue;
            }

            return $matches[2];
        }
        return null;
    }

    private function setEnvValue(string $key, string $value, bool $valueIsRaw = false): void
    {
        $serialized = $valueIsRaw ? $value : $this->serializeEnvValue($value);
        $replacement = $key . '=' . $serialized;

        foreach ($this->envLines as $index => $line) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', $line) === 1) {
                if ($line !== $replacement) {
                    $this->envLines[$index] = $replacement;
                    $this->envChanged = true;
                }
                return;
            }
        }

        $this->envLines[] = $replacement;
        $this->envChanged = true;
    }

    private function serializeEnvValue(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if ($this->isQuoted($trimmed)) {
            return $trimmed;
        }

        if (preg_match('/\s|#/', $trimmed) === 1) {
            return $this->quoteValue($trimmed);
        }

        return $trimmed;
    }

    private function quoteValue(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private function isQuoted(string $value): bool
    {
        $length = strlen($value);
        if ($length < 2) {
            return false;
        }
        $first = $value[0];
        $last = $value[$length - 1];
        return ($first === '"' && $last === '"') || ($first === "'" && $last === "'");
    }

    private function printHeader(string $label): void
    {
        $this->printLine('');
        $this->printLine('== ' . $label . ' ==');
    }

    private function printLine(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }
}

try {
    $envOnly = in_array('--env-only', $argv, true);
    $setup = new HostingSetup(dirname(__DIR__), $envOnly);
    exit($setup->run());
} catch (Throwable $exception) {
    fwrite(STDERR, 'Fatal: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
