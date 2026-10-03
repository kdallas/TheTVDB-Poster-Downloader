<?php

/**
 * TVDB API client: login, token management and GET requests. Config and
 * session access live in PosterEnv/AuthStore, so this class stays purely
 * about the API. Static methods.
 */

class TvdbApi
{
    const API_URL = 'https://api4.thetvdb.com/v4';
    // Re-auth instead of reusing the token once less than this remains.
    const TOKEN_MIN_LIFETIME = 86400; // 1 day

    /** Extract the exp claim from a JWT, or null if it has none. */
    public static function jwtExpiry(string $token): ?int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $b64 = strtr($parts[1], '-_', '+/');
        $payload = json_decode(base64_decode(str_pad($b64, strlen($b64) % 4, '='), true), true);
        return isset($payload['exp']) ? (int) $payload['exp'] : null;
    }

    /**
     * Exchange the API key in .env for a bearer token and store it (plus its
     * expiry timestamp) in .auth.json via AuthStore. Returns [token, expiry].
     */
    public static function login(): array
    {
        $env = PosterEnv::env();
        $apiKey = $env['API_KEY'] ?? '';
        if ($apiKey === '') {
            throw new Exception('API_KEY is empty in .env');
        }

        $options = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['apikey' => $apiKey]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ];

        // DISABLED: php.ini now sets curl.cainfo globally, so curl finds the CA
        // bundle by itself (C:\Users\KDallas2007\.php\php-8.5.1-...\cacert.pem).
        // Kept as a reference — if you ever run this script on a machine where
        // curl.cainfo is NOT configured (SSL verification fails with "unable to
        // get local issuer certificate"), put a cacert.pem next to this file
        // and uncomment:
        // $caFile = __DIR__ . DIRECTORY_SEPARATOR . 'cacert.pem';
        // if (is_file($caFile)) {
        //     $options[CURLOPT_CAINFO] = $caFile;
        // }

        [$response, $httpCode, $curlError] = Http::request(self::API_URL . '/login', $options);

        if ($response === false) {
            throw new Exception("curl request failed: {$curlError}");
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200 || !isset($data['data']['token'])) {
            $status = $data['status'] ?? 'unknown';
            $message = $data['message'] ?? $response;
            throw new Exception("Login failed (HTTP {$httpCode}): {$status} — {$message}");
        }

        $token = $data['data']['token'];
        $expiry = self::jwtExpiry($token) ?? time() + 30 * 24 * 60 * 60;

        // Persist the session for later runs. .env is left alone — it is
        // user config and the scripts only ever read it now.
        AuthStore::write($token, $expiry);

        return [$token, $expiry];
    }

    /**
     * Get a usable bearer token. Reuses the one stored in .auth.json, but
     * logs in fresh when it is missing, expired, or within
     * TOKEN_MIN_LIFETIME (1 day) of expiring.
     */
    public static function token(): string
    {
        $auth = AuthStore::read();
        $token = $auth['token'];
        $expiry = $auth['expiry'];

        if ($token === '' || $expiry === 0 || time() >= $expiry - self::TOKEN_MIN_LIFETIME) {
            [$token, $expiry] = self::login();
            printf("Logged in — token expires %s.\n", date('Y-m-d H:i:s T', $expiry));
        }

        return $token;
    }

    /**
     * GET a path from the TVDB API with the bearer token attached. If the
     * token is rejected (401) it re-authenticates once and retries.
     * Returns [httpCode, responseBody].
     */
    public static function get(string $path, array $query = []): array
    {
        $url = self::API_URL . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $token = self::token();

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            [$response, $httpCode, $curlError] = Http::request($url, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                    'Authorization: Bearer ' . $token,
                ],
            ]);

            if ($response === false) {
                throw new Exception("curl request failed: {$curlError}");
            }

            if ($httpCode === 401 && $attempt === 1) {
                // Token rejected early — log in fresh and try once more.
                [$token] = self::login();
                printf("Token was rejected; refreshed and retrying.\n");
                continue;
            }

            return [$httpCode, $response];
        }

        return [0, '']; // unreachable; keeps static analysis happy
    }
}
