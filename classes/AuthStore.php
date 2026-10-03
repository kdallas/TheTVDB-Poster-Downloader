<?php

/**
 * The login-session store: the TVDB bearer token and its expiry live in
 * .auth.json in the project root. Kept separate from PosterEnv because
 * .env is user config that the scripts only ever read now — the token is
 * runtime state the scripts own, and it rotates on every re-login. Losing
 * or corrupting .auth.json is harmless: the next run simply logs in again.
 *
 * Format (pretty-printed JSON):
 *
 *   {
 *       "token": "eyJhbGciOi...",
 *       "expiry": 1780000000
 *   }
 */

class AuthStore
{
    /** Parsed .auth.json, cached for the life of the process. */
    private static $cache = null;

    /** Absolute path to the session file (project root, like .env). */
    public static function authFile(): string
    {
        return Paths::projectRoot() . DIRECTORY_SEPARATOR . '.auth.json';
    }

    /**
     * Read the stored session: ['token' => string, 'expiry' => int].
     * A missing, unreadable or corrupt file reads as empty values — that
     * is what makes token() log in fresh, so it needs no error of its
     * own. Cached for the run; write() drops the cache.
     */
    public static function read(): array
    {
        if (self::$cache === null) {
            $token = '';
            $expiry = 0;
            $path = self::authFile();
            if (is_file($path)) {
                $data = json_decode(file_get_contents($path), true);
                if (is_array($data)) {
                    $token = (string) ($data['token'] ?? '');
                    $expiry = (int) ($data['expiry'] ?? 0);
                }
            }
            self::$cache = ['token' => $token, 'expiry' => $expiry];
        }
        return self::$cache;
    }

    /**
     * Store a fresh token + expiry (pretty-printed JSON so the file stays
     * human-readable). Throws when the file cannot be written.
     */
    public static function write(string $token, int $expiry): void
    {
        $json = json_encode(
            ['token' => $token, 'expiry' => $expiry],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
        if (!self::writeFileInPlace(self::authFile(), $json . PHP_EOL)) {
            throw new Exception('Could not write ' . self::authFile());
        }
        self::$cache = null; // the file changed — don't serve the old session
    }

    /**
     * Overwrite a file's contents in place: open it, truncate, write.
     * Deliberately NOT file_put_contents(), which asks Windows to *replace*
     * the file (CREATE_ALWAYS on the wire, SMB FILE_OVERWRITE_IF) — SMB
     * servers refuse that flavour of open for files carrying the hidden
     * (or system) attribute, unless the request re-declares the attribute.
     * That is how the old .env rewrite failed on the Samba share this
     * project lives on, so state files do not take the risk: opening the
     * existing file and truncating through the handle is always allowed.
     * A file that does not exist yet is simply created — creating is fine,
     * only *replacing* is refused. Nothing is error-suppressed: a
     * genuinely refused open prints its own warning before this returns
     * false.
     */
    private static function writeFileInPlace(string $path, string $contents): bool
    {
        if (!is_file($path)) {
            // No file yet — create it (always allowed).
            return file_put_contents($path, $contents) !== false;
        }

        $handle = fopen($path, 'r+b');
        if ($handle === false) {
            // Open refused — its warning explains why; the caller throws.
            return false;
        }

        $ok = ftruncate($handle, 0);
        if ($ok) {
            rewind($handle);
            $ok = fwrite($handle, $contents) === strlen($contents);
        }
        fclose($handle);

        return $ok;
    }
}
