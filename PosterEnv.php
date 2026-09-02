<?php

/**
 * Access to this project's .env config file. Kept separate from TvdbApi
 * so that class stays purely about the TVDB API; both the CLI
 * (PosterCli) and the API client (TvdbApi) use these helpers.
 */

class PosterEnv
{
    public static function envFile(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . '.env';
    }

    /** Parse .env into KEY=VALUE pairs (skipping comments and blank lines). */
    public static function env(): array
    {
        $env = [];
        foreach (file(self::envFile(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $env[trim($key)] = trim($value);
            }
        }
        return $env;
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
}
