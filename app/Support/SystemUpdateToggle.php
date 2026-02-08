<?php

namespace App\Support;

/**
 * System Update feature toggle that still works when Laravel config is cached.
 *
 * Problem:
 * - When config is cached, Laravel may skip loading .env at runtime.
 * - Ops often change SYSTEM_UPDATE_ENABLED in .env on shared hosting without CLI access.
 *
 * Solution:
 * - Prefer real environment variables (getenv/$_ENV/$_SERVER) when present.
 * - Fallback to reading the ".env" file directly.
 * - Finally fallback to config('system_update.enabled').
 */
final class SystemUpdateToggle
{
    public static function enabled(): bool
    {
        $env = self::readBoolFromEnvironment('SYSTEM_UPDATE_ENABLED');
        if (is_bool($env)) return $env;

        $file = self::readBoolFromDotEnv(base_path('.env'), 'SYSTEM_UPDATE_ENABLED');
        if (is_bool($file)) return $file;

        return (bool) config('system_update.enabled', false);
    }

    private static function readBoolFromEnvironment(string $key): ?bool
    {
        $val = getenv($key);
        if ($val === false) {
            $val = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }

        return self::normalizeBool($val);
    }

    private static function readBoolFromDotEnv(string $path, string $key): ?bool
    {
        if (!is_file($path)) return null;

        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) return null;

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) continue;

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, strlen('export ')));
            }

            $pos = strpos($line, '=');
            if ($pos === false) continue;

            $k = trim(substr($line, 0, $pos));
            if ($k !== $key) continue;

            $v = trim(substr($line, $pos + 1));

            // Strip surrounding quotes.
            if ($v !== '' && (
                (str_starts_with($v, '"') && str_ends_with($v, '"')) ||
                (str_starts_with($v, "'") && str_ends_with($v, "'"))
            )) {
                $v = substr($v, 1, -1);
            } else {
                // Remove inline comments (good enough for boolean flags).
                $hash = strpos($v, '#');
                if ($hash !== false) {
                    $v = trim(substr($v, 0, $hash));
                }
            }

            return self::normalizeBool($v);
        }

        return null;
    }

    private static function normalizeBool(mixed $val): ?bool
    {
        if (is_bool($val)) return $val;
        if (is_int($val)) return $val !== 0;
        if (is_float($val)) return $val !== 0.0;

        if (!is_string($val)) return null;

        $v = strtolower(trim($val));
        if ($v === '') return false;

        // Laravel-style boolean strings.
        if (in_array($v, ['true', '(true)', '1', 'yes', 'y', 'on'], true)) return true;
        if (in_array($v, ['false', '(false)', '0', 'no', 'n', 'off', 'null', '(null)'], true)) return false;

        return null;
    }
}

