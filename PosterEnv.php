<?php

/**
 * Access to this project's .env config file. Kept separate from TvdbApi
 * so that class stays purely about the TVDB API; both the CLI
 * (PosterCli) and the API client (TvdbApi) use these helpers.
 */

class PosterEnv
{
    /** Parsed .env, cached for the life of the process. */
    private static $cache = null;

    public static function envFile(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . '.env';
    }

    /**
     * Parse .env into KEY=VALUE pairs (skipping comments and blank
     * lines). Read from disk once per run, then served from memory —
     * TvdbApi::login() calls refresh() after rewriting the file so the
     * cache always reflects a fresh token.
     */
    public static function env(): array
    {
        if (self::$cache === null) {
            $envFile = self::envFile();
            if (!is_file($envFile)) {
                // Fail with a clear message instead of file() warnings
                // followed by a confusing "API_KEY is empty".
                throw new Exception($envFile . ' not found — it must hold at least API_KEY');
            }
            $env = [];
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                if (strpos($line, '=') !== false) {
                    [$key, $value] = explode('=', $line, 2);
                    $env[trim($key)] = trim($value);
                }
            }
            self::$cache = $env;
        }
        return self::$cache;
    }

    /** Drop the cached parse so the next env() reads the file afresh. */
    public static function refresh(): void
    {
        self::$cache = null;
    }

    /**
     * Read an on/off flag from .env (case-insensitive). Missing or empty
     * values fall back to $default; 1/true/yes/on count as true.
     */
    public static function envFlag(string $key, bool $default = true): bool
    {
        $value = strtolower(trim(self::env()[$key] ?? ''));
        if ($value === '') {
            return $default;
        }
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Read a comma-separated list from .env, e.g. RELEASE_TAGS=PSA,XYZ —
     * bare tag names; cleanFolder() prepends the hyphen when checking.
     * Returns the trimmed, non-empty entries; [] when the key is missing.
     */
    public static function envList(string $key): array
    {
        $raw = self::env()[$key] ?? '';
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn($t) => $t !== ''));
    }
}
