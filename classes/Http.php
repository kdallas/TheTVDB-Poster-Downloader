<?php

/**
 * Shared curl plumbing for the API clients (TvdbApi, TmdbApi) and for
 * the artwork downloads that follow a lookup: run one request and return
 * [result, httpCode, curlError]. $result is the response body (a
 * string), or true/false when the caller set CURLOPT_FILE. curl_close()
 * is deliberately absent — deprecated and a no-op since PHP 8; the
 * handle frees itself.
 */

class Http
{
    public static function request(string $url, array $options): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, $options);

        $result = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        return [$result, $httpCode, $curlError];
    }

    /**
     * Download a URL straight to a file. The image comes from whichever
     * API supplied it — a TVDB artwork URL or a TMDB one — so this lives
     * here rather than in either client. curl writes into the file handle
     * as it goes, so nothing large is ever held in memory.
     */
    public static function download(string $url, string $dest): void
    {
        $fp = fopen($dest, 'wb');
        if ($fp === false) {
            throw new Exception("Could not open {$dest} for writing");
        }

        [$ok, $httpCode, $curlError] = self::request($url, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
        ]);
        fclose($fp);

        if ($ok === false) {
            // Don't leave a partial file behind — it could end up
            // copied as poster.jpg later.
            @unlink($dest);
            throw new Exception("Download failed: {$curlError}");
        }
        if ($httpCode !== 200) {
            @unlink($dest); // don't leave a partial file behind
            throw new Exception("Download failed (HTTP {$httpCode}): {$url}");
        }
    }
}
