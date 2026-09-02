<?php

/**
 * Path helpers replicating BatchEncoder's sanitizePath()/toWinPath() so
 * this project accepts unix and windows style paths interchangeably.
 */

class Paths
{
    /**
     * CENTRAL PATH CLEANER
     * Converts everything to Forward Slashes (/) for internal consistency
     * and Git Bash compatibility.
     */
    public static function sanitizePath($path, $isDir = false) {
        // Unify Slashes to /
        $clean = str_replace('\\', '/', $path);

        // Remove surrounding quotes (Single or Double)
        $clean = trim($clean, ' "\'');

        // Fix Git Bash "Drive Letter" paths (e.g., /c/Windows -> C:/Windows)
        // Only apply if it looks like a drive path, NOT a UNC path (//Server)
        if (!str_starts_with($clean, '//') && preg_match('/^\/([a-zA-Z])\/(.*)/', $clean, $matches)) {
            $drive = strtoupper($matches[1]);
            $clean = $drive . ':/' . $matches[2];
        }

        // (BatchEncoder's version also resolves relative paths for Phar
        // builds here; this project has no phar, so that block is omitted.)

        // Trailing Slash Logic (only for directories)
        // We use a robust check: is_dir(Unix) OR is_dir(Win)
        if (!$isDir) {
            $isDir = is_dir($clean) || is_dir(self::toWinPath($clean));
        }

        if ($isDir && !str_ends_with($clean, '/')) {
            $clean .= '/';
        }

        if (!$isDir && str_ends_with($clean, '/')) {
            $clean = rtrim($clean, '/');
        }

        return $clean;
    }

    /**
     * OUTPUT HELPER
     * Converts internal forward slashes to Windows backslashes.
     */
    public static function toWinPath($path) {
        return str_replace('/', '\\', $path);
    }
}
