<?php

/**
 * Shared curl plumbing for the API clients (TvdbApi, TmdbApi): run one
 * request and return [result, httpCode, curlError]. $result is the
 * response body (a string), or true/false when the caller set
 * CURLOPT_FILE. curl_close() is deliberately absent — deprecated and a
 * no-op since PHP 8; the handle frees itself.
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
}
