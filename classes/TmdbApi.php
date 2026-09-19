<?php

/**
 * TMDB API client: search and image URL construction, the pieces the
 * TheTVDB fallback needs. Kept separate from TvdbApi (which stays purely
 * TVDB) and from PosterEnv (.env access); curl plumbing is shared via
 * Http. Static methods, mirroring TvdbApi's shape.
 *
 * Auth: TMDB accepts a v3 API key as the `api_key` query parameter, or a
 * v4 read access token as a Bearer header. This project uses the simpler
 * v3 key from .env (TMDB_API_KEY). Attribution is required by their API
 * Terms of Use — see TMDB_NOTICE in PosterCli and the README.
 */

class TmdbApi
{
    const API_URL = 'https://api.themoviedb.org/3';
    // Posters are assembled as IMAGE_URL . <size> . <poster_path>.
    const IMAGE_URL = 'https://image.tmdb.org/t/p/';

    /** The v3 API key from .env, or '' when it is not set. */
    public static function apiKey(): string
    {
        return PosterEnv::env()['TMDB_API_KEY'] ?? '';
    }

    /**
     * GET a TMDB path with the API key attached. Returns
     * [httpCode, responseBody] like TvdbApi::get(), and throws when curl
     * itself fails. There is no token to refresh, so no 401 retry —
     * a 401 here means the key is wrong, which callers report as such.
     */
    public static function get(string $path, array $query = []): array
    {
        $key = self::apiKey();
        if ($key === '') {
            throw new Exception('TMDB_API_KEY is empty in .env');
        }

        $url = self::API_URL . $path . '?' . http_build_query($query + ['api_key' => $key]);

        [$response, $httpCode, $curlError] = Http::request($url, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        if ($response === false) {
            throw new Exception("curl request failed: {$curlError}");
        }

        return [$httpCode, $response];
    }

    /**
     * Search TV shows or movies by title, newest API order preserved.
     * Returns the decoded `results` array — empty when TMDB has no
     * match. $kind is 'tv' or 'movie' (TMDB's own endpoint names).
     *
     * The year is deliberately NOT sent as TMDB's `year` /
     * `first_air_date_year` filter, matching the rule the TVDB side
     * follows: a folder year can be absent or wrong, and a server-side
     * filter would drop the right record entirely. Ranking applies it as
     * a hint instead.
     */
    public static function search(string $query, string $kind): array
    {
        if (!in_array($kind, ['tv', 'movie'], true)) {
            throw new Exception("Unknown TMDB search kind '{$kind}'");
        }

        [$httpCode, $body] = self::get('/search/' . $kind, [
            'query'    => $query,
            'language' => 'en-US',
        ]);

        $data = json_decode($body, true);

        if ($httpCode === 401) {
            // TMDB says exactly what is wrong here ("Invalid API key: You
            // must be granted a valid key."), so pass its wording through.
            $detail = $data['status_message'] ?? 'no message';
            throw new Exception("TMDB rejected the API key (HTTP 401): {$detail} — check TMDB_API_KEY in .env");
        }
        if ($httpCode !== 200) {
            $message = $data['status_message'] ?? $body;
            throw new Exception("TMDB search failed (HTTP {$httpCode}): {$message}");
        }

        return $data['results'] ?? [];
    }

    /**
     * Full image URL for a poster_path from a search result. Available
     * sizes come from /configuration; w500 is the usual poster size and
     * `original` is the untouched upload. Image requests need no key.
     */
    public static function posterUrl(string $path, string $size = 'w500'): string
    {
        return self::IMAGE_URL . $size . $path;
    }
}
