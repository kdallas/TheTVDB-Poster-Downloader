<?php

/**
 * CLI entry point class for the tvdb-posters tools. Parses $argv, then
 * runs the requested action: search by --title, or --login to force a
 * fresh token. Same shape as BatchEncoder: constructor takes $argv,
 * run() does the work, errors are exceptions caught as "Error: ...".
 */

class PosterCli
{
    // Inputs
    private $titleInput = "";   // --title="Star City"
    private $posterId = "";     // --poster=449146 (series id)
    private $scanPath = "";     // --scan=/path/to/dir
    private $postersFlag = false;  // --posters (with --scan, fetch posters for the library's shows)
    private $seasonsFlag = false;  // --seasons (with --scan --posters, fetch season posters too)
    private $movieFlag = false;    // --movie (movie mode: /movies/ endpoints, movie- id prefix)
    private $cleanFlag = false;    // --clean (with --scan --posters, tidy up after each poster)

    public function __construct($argv) {
        try {
            $this->parseArguments($argv);
            $this->validateInputs();
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    public function run() {
        try {
            if ($this->scanPath !== '') {
                $this->scanFolder();
            } elseif ($this->posterId !== '') {
                $this->posterLookup();
            } else {
                $this->search();
            }
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    private function parseArguments($argv) {
        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];

            if (str_starts_with($arg, '--title=')) {
                $this->titleInput = substr($arg, 8);
                // Stitch spaces if the title was unquoted: --title=Star City
                for ($j = $i + 1; $j < count($argv); $j++) {
                    if (str_starts_with($argv[$j], '-')) break;
                    $this->titleInput .= " " . $argv[$j];
                    $i++;
                }
            }
            elseif (str_starts_with($arg, '--poster=')) {
                $this->posterId = substr($arg, 9);
            }
            elseif (str_starts_with($arg, '--scan=')) {
                $this->scanPath = substr($arg, 7);
                // Stitch spaces if the path was unquoted: --scan=/c/My TV Shows
                for ($j = $i + 1; $j < count($argv); $j++) {
                    if (str_starts_with($argv[$j], '-')) break;
                    $this->scanPath .= " " . $argv[$j];
                    $i++;
                }
            }
            elseif ($arg === '--posters') {
                $this->postersFlag = true;
            }
            elseif ($arg === '--seasons') {
                $this->seasonsFlag = true;
            }
            elseif ($arg === '--movie') {
                $this->movieFlag = true;
            }
            elseif ($arg === '--clean') {
                $this->cleanFlag = true;
            }
            else {
                throw new Exception("Unknown argument: {$arg}");
            }
        }
    }

    private function validateInputs() {
        if (empty($this->titleInput) && empty($this->posterId) && empty($this->scanPath)) {
            throw new Exception('Missing --title, --poster, or --scan value.' . "\n" .
                                'USAGE: php run.php --title="Star City"' . "\n" .
                                '       php run.php --poster=449146' . "\n" .
                                '       php run.php --scan=/path/to/dir');
        }
        if ($this->posterId !== '') {
            // The mode's id prefix (series- / movie-) is accepted but
            // optional — search tables show it, users shouldn't have to
            // strip it themselves.
            $prefix = $this->movieFlag ? 'movie-' : 'series-';
            $posterCheck = str_replace($prefix, '', $this->posterId);
            if (!ctype_digit($posterCheck)) {
                throw new Exception('Invalid --poster value "' . $this->posterId . '" — expected a numeric id'
                                  . " (optionally with a {$prefix} prefix)");
            }
        }
        if ($this->postersFlag && empty($this->scanPath)) {
            throw new Exception('--posters can only be used together with --scan');
        }
        if ($this->seasonsFlag && !$this->postersFlag) {
            throw new Exception('--seasons can only be used together with --scan --posters');
        }
        if ($this->movieFlag && $this->seasonsFlag) {
            throw new Exception('--seasons cannot be used together with --movie');
        }
        if ($this->cleanFlag && !$this->postersFlag) {
            throw new Exception('--clean can only be used together with --scan --posters');
        }
    }

    /**
     * Split a title that may carry a year — the common folder-name
     * conventions "Lazarus (2025)" and release-style "The.Runner.2026.
     * 1080p.". The year (parenthesized, or a bare dot/space
     * separated token in 1900-2099) is kept as a ranking hint and marks
     * the end of the meaningful title: everything from it onwards is
     * dropped, and dots are treated as word separators. Returns
     * ['title' => 'Lazarus', 'year' => 2025]; year is 0 when absent.
     */
    private function splitYear(string $input): array
    {
        $input = trim($input);
        $year = 0;

        if (preg_match('/\((\d{4})\)/', $input, $m, PREG_OFFSET_CAPTURE)) {
            // Parenthesized year: "Lazarus (2025)".
            $year = (int) $m[1][0];
            $input = substr($input, 0, $m[0][1]);
        } elseif (preg_match('/[.\s](19\d{2}|20\d{2})(?=$|[.\s])/', $input, $m, PREG_OFFSET_CAPTURE)) {
            // Bare year token after a dot/space: "The.Runner.2026.1080p".
            // The delimiter requirement skips titles that START with a
            // number ("2001.A.Space.Odyssey.1968..."), and the 1900-2099
            // range skips titles like "The.4400".
            $year = (int) $m[1][0];
            $input = substr($input, 0, $m[0][1]);
        }

        // Release-style names use dots as word separators.
        return [
            'title' => trim(str_replace('.', ' ', $input)),
            'year'  => $year,
        ];
    }

    /**
     * Rank search results, best match first:
     *   1. The series' own name IS the term ("Solos")
     *   2. The own name CONTAINS the term ("Kaleidoscope (2023)")
     *   3. The English title IS the term (a translation match)
     *   4. The English title CONTAINS the term
     *   5. Everything else the search API matched (aliases, overviews, ...)
     * Titles are compared with parenthesized years stripped, mirroring
     * splitYear() on the input: "The Librarians (2014)" counts as an
     * exact match for "The Librarians", so the spin-off "The Librarians:
     * The Next Chapter" stays in the contains tier.
     * Within a tier: a parenthesized year hint ("Lazarus (2025)") is
     * preferred, then newest first by air date; no-date entries sink.
     * Own-title matches rank above translation matches — searching
     * "Kaleidoscope" should put the series actually titled
     * "Kaleidoscope (2023)" above a Vietnamese show whose English
     * translation happens to be exactly "Kaleidoscope".
     * PHP 8 sorts are stable, so ties keep the API's original order.
     */
    private function rankResults(array $results, string $needle, int $year = 0): array
    {
        // Canonical comparison form: lowercase, parenthesized years
        // stripped, and any punctuation runs collapsed to a single space
        // — "Face/Off" and "Face-Off" both compare equal to "Face Off".
        // Letters/numbers of any script survive (CJK titles intact).
        $canonical = function (string $s): string {
            $s = mb_strtolower(trim(preg_replace('/\(\d{4}\)/', '', $s)));
            $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
            return trim(preg_replace('/\s+/u', ' ', $s));
        };
        $needle = $canonical($needle);

        // Tier number for a single result; lower = better.
        $tier = function (array $r) use ($needle, $canonical): int {
            $name = $canonical($r['name'] ?? '');
            $en   = $canonical($r['_english'] ?? '');

            if ($name === $needle) return 1;
            if ($needle !== '' && str_contains($name, $needle)) return 2;
            if ($en === $needle) return 3;
            if ($needle !== '' && str_contains($en, $needle)) return 4;
            return 5;
        };

        usort($results, function ($a, $b) use ($tier, $year) {
            $tierDiff = $tier($a) - $tier($b);
            if ($tierDiff !== 0) {
                return $tierDiff;
            }

            // Some records only carry a year, no full air date — use it
            // as the fallback so new releases rank correctly.
            $aYear = (int) (substr($a['first_air_time'] ?? '', 0, 4) ?: ($a['year'] ?? 0));
            $bYear = (int) (substr($b['first_air_time'] ?? '', 0, 4) ?: ($b['year'] ?? 0));

            // Prefer the year the title carried — "Lazarus (2025)" — so
            // a remake or name-clash from that year wins over the rest.
            if ($year > 0) {
                $aYearMatch = $aYear === $year;
                $bYearMatch = $bYear === $year;
                if ($aYearMatch !== $bYearMatch) {
                    return $aYearMatch ? -1 : 1;
                }
            }

            return $bYear - $aYear; // newest first
        });

        return $results;
    }

    /**
     * Best-effort English title for a search result. When the series'
     * primary language is already English the name IS the English title,
     * so we skip the API call. Otherwise ask for the English translation
     * record (GET /series/{id}/translations/eng) and use its `name`
     * field. Returns '' when no English name is available.
     */
    private function englishTitle(array $result): string
    {
        if (($result['primary_language'] ?? '') === 'eng') {
            return $result['name'] ?? '';
        }

        $id = str_replace($this->movieFlag ? 'movie-' : 'series-', '', $result['id'] ?? '');
        if ($id === '') {
            return '';
        }

        [$code, $body] = TvdbApi::get(($this->movieFlag ? '/movies/' : '/series/') . $id . '/translations/eng');
        if ($code !== 200) {
            return '';
        }

        $data = json_decode($body, true);
        return $data['data']['name'] ?? '';
    }

    /**
     * Add each result's English title under the '_english' key (one API
     * call per non-English-primary result). Called once BEFORE ranking so
     * the ranker's exact-match tier can see English titles, and the same
     * values are reused for the search table's Title (EN) column.
     */
    private function enrichEnglish(array $results): array
    {
        foreach ($results as &$r) {
            $r['_english'] = $this->englishTitle($r);
        }
        unset($r);
        return $results;
    }

    private function search() {
        // A title may carry a year in parentheses ("Lazarus (2025)"):
        // search without it, but keep the year as a hint for ranking.
        $parsed = $this->splitYear($this->titleInput);
        $query  = $parsed['title'];
        $year   = $parsed['year'];

        // --movie switches the search to movies (same /search endpoint).
        $type = $this->movieFlag ? 'movie' : 'series';

        [$httpCode, $response] = TvdbApi::get('/search', ['query' => $query, 'type' => $type]);
        $data = json_decode($response, true);

        if ($httpCode === 401) {
            throw new Exception('Token rejected even after re-auth (HTTP 401)');
        }
        if ($httpCode !== 200) {
            throw new Exception("Search failed (HTTP {$httpCode}): {$response}");
        }

        $results = $this->enrichEnglish($data['data'] ?? []);
        $results = $this->rankResults($results, $query, $year);

        $yearNote = $year > 0 ? ", year {$year}" : '';
        printf("Search \"%s\" (%s%s): %d result(s)\n\n", $query, $type, $yearNote, count($results));

        if ($results === []) {
            printf("No results.\n");
            return;
        }

        $rows = [];
        foreach ($results as $r) {
            $english = $r['_english'] ?? '';
            $rows[] = [
                $r['id'] ?? '?',
                $r['name'] ?? '(no name)',
                $english !== '' ? $english : '—',
                !empty($r['first_air_time']) ? substr($r['first_air_time'], 0, 10)
                    : (!empty($r['year']) ? $r['year'] : '—'),
                !empty($r['network']) ? $r['network'] : '—',
            ];
        }

        echo $this->renderTable(['ID', 'Title', 'Title (EN)', 'First aired', 'Network'], $rows);
    }

    /**
     * Pick the series poster the way the TVDB website does — the first
     * Poster-type asset its own API returns — then download it.
     * Saved to ./artwork/<seriesId>-<basename of image URL>.
     */
    private function posterLookup() {
        $typeMap = $this->fetchArtworkTypes();

        // The mode's id prefix (series- / movie-) is optional; movie mode
        // reads /movies/{id}/extended (there is no /movies/{id}/artworks
        // endpoint).
        $id   = str_replace($this->movieFlag ? 'movie-' : 'series-', '', $this->posterId);
        $kind = $this->movieFlag ? 'movie' : 'series';
        $artPath = ($this->movieFlag ? '/movies/' . $id . '/extended' : '/series/' . $id . '/artworks');

        [$httpCode, $response] = TvdbApi::get($artPath);
        $data = json_decode($response, true);

        if ($httpCode === 404) {
            throw new Exception(ucfirst($kind) . " {$id} not found");
        }
        if ($httpCode !== 200) {
            throw new Exception("Artwork lookup failed (HTTP {$httpCode}): {$response}");
        }

        $name = $data['data']['name'] ?? $id;

        $selection = $this->selectPoster($data['data']['artworks'] ?? [], $typeMap);
        $winner = $selection['winner'];
        if ($winner === null) {
            throw new Exception("No poster artwork found for {$kind} {$id}");
        }

        printf("Poster lookup for %s %s \"%s\":\n", $kind, $id, $name);

        // Show the funnel narrowing: each tier's count, stopping at the
        // tier that produced the winner.
        $steps = [$selection['total'] . ' posters'];
        foreach ([['eng series-level', $selection['engSeries']],
                  ['eng', $selection['eng']],
                  ['series-level', $selection['series']]] as [$label, $count]) {
            $steps[] = $count . ' ' . $label;
            if ($selection['stage'] === $label) {
                break;
            }
        }
        $steps[] = 'score ' . ($winner['score'] ?? '—');
        printf("Selection: %s\n\n", implode(' → ', $steps));

        $rows = [[
            $typeMap[$winner['type'] ?? 0] ?? 'Poster',
            $winner['id'] ?? '?',
            $winner['language'] ?? '—',
            $winner['score'] ?? '—',
            (isset($winner['width']) && isset($winner['height'])) ? $winner['width'] . '×' . $winner['height'] : '—',
            $winner['image'] ?? '—',
        ]];
        echo $this->renderTable(['Type', 'Artwork ID', 'Language', 'Score', 'Size (W×H)', 'Image'], $rows);

        // Download to ./artwork/<id>-<basename of image URL>.
        $url = $winner['image'];
        $filename = $id . '-' . basename(parse_url($url, PHP_URL_PATH));
        $dir = __DIR__ . DIRECTORY_SEPARATOR . 'artwork';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $dest = $dir . DIRECTORY_SEPARATOR . $filename;

        TvdbApi::download($url, $dest);

        printf("\nSaved: artwork/%s (%s bytes)\n", $filename, number_format(filesize($dest)));
    }

    /**
     * Resolve artwork type ids -> names (e.g. 2 -> "Poster"). Empty map if
     * the types call fails — selectPoster() then falls back to URL detection.
     */
    private function fetchArtworkTypes(): array
    {
        [$typesCode, $typesResponse] = TvdbApi::get('/artwork/types');
        $typesData = json_decode($typesResponse, true);
        $typeMap = [];
        if ($typesCode === 200) {
            foreach (($typesData['data'] ?? []) as $t) {
                $typeMap[$t['id']] = $t['name'] ?? ('type ' . $t['id']);
            }
        }
        return $typeMap;
    }

    /**
     * The poster selection funnel:
     *   1. Keep Poster type only (URL-pattern fallback without a type map)
     *   2. Prefer English + series-level posters
     *   3. Else English posters of any level (English is preferred over
     *      other languages)
     *   4. Else series-level posters of any language
     *   5. Highest score wins
     * Series-level is judged by the explicit seasonId/episodeId fields —
     * the API only emits them when the artwork is attached to a season or
     * episode, so their absence means series-level. Legacy v3-era image
     * URLs predate the /series/ path layout, so the URL sniff is only a
     * fallback for responses that carry none of the fields.
     * Returns the winning artwork (or null when the series has no
     * poster-type artwork at all) plus the funnel counts for reporting.
     */
    private function selectPoster(array $artworks, array $typeMap): array
    {
        $posters = array_values(array_filter($artworks, function ($a) use ($typeMap) {
            if ($typeMap !== []) {
                return ($typeMap[$a['type'] ?? 0] ?? '') === 'Poster';
            }
            return str_contains($a['image'] ?? '', '/posters/');
        }));

        if ($posters === []) {
            // No poster-type artwork for this series — callers can use
            // winner === null to try the next best match instead.
            return [
                'winner'    => null,
                'total'     => 0,
                'engSeries' => 0,
                'eng'       => 0,
                'series'    => 0,
                'stage'     => 'none',
            ];
        }

        $total = count($posters);

        // If the response carries the attachment fields anywhere, trust
        // them (absent = zero = series-level); otherwise fall back to the
        // v4 URL layout for older responses.
        $hasAttachmentFields = false;
        foreach ($posters as $p) {
            if (array_key_exists('seasonId', $p) || array_key_exists('episodeId', $p)) {
                $hasAttachmentFields = true;
                break;
            }
        }
        if ($hasAttachmentFields) {
            $isSeriesLevel = fn(array $a) => empty($a['seasonId']) && empty($a['episodeId']);
        } else {
            $isSeriesLevel = fn(array $a) => str_contains($a['image'] ?? '', '/series/');
        }
        $isEng = fn(array $a) => strtolower($a['language'] ?? '') === 'eng';

        // Tiered candidates: English series-level first, then any
        // English, then series-level in any language.
        $engSeries = array_values(array_filter($posters, fn($a) => $isEng($a) && $isSeriesLevel($a)));
        $engAll    = array_values(array_filter($posters, $isEng));
        $seriesAll = array_values(array_filter($posters, $isSeriesLevel));

        $candidates = $posters;
        $stage = 'any poster';
        if ($engSeries !== []) {
            $candidates = $engSeries;
            $stage = 'eng series-level';
        } elseif ($engAll !== []) {
            $candidates = $engAll;
            $stage = 'eng';
        } elseif ($seriesAll !== []) {
            $candidates = $seriesAll;
            $stage = 'series-level';
        }

        usort($candidates, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        return [
            'winner'    => $candidates[0],
            'total'     => $total,   // posters before narrowing
            'engSeries' => count($engSeries),
            'eng'       => count($engAll),
            'series'    => count($seriesAll),
            'stage'     => $stage,
        ];
    }

    /**
     * --scan --posters mode. For each immediate child directory (a TV show
     * folder — or a movie folder with --movie):
     * folder): skip it if it already has poster.jpg/poster.png; otherwise
     * use the folder name as the series title, find the show, pick its
     * poster (same funnel as --poster), download it to ./artwork/, and copy
     * it into the show folder as poster.<ext> (a jpg source → poster.jpg).
     * With --seasons, a second pass then fetches posters for the folder's
     * "Season N"/"Specials" subfolders (downloadSeasonPosters()).
     * Per-folder problems print "Skip :" and move on to the next folder.
     */
    private function downloadForFolders(array $dirs) {
        // Fetch the artwork type map once for the whole run.
        $typeMap = $this->fetchArtworkTypes();

        // Movie mode decides the search type, the id prefix, and the
        // artwork endpoint — resolved once instead of per folder.
        $type      = $this->movieFlag ? 'movie' : 'series';
        $idPrefix  = $this->movieFlag ? 'movie-' : 'series-';
        $artBase   = $this->movieFlag ? '/movies/' : '/series/';
        $artSuffix = $this->movieFlag ? '/extended' : '/artworks';

        foreach ($dirs as $dir) {
            $title = basename($dir);
            $cleanDir = rtrim($dir, '/');

            // Folder names may carry a year ("Lazarus (2025)"): search
            // without it, keep the year as a hint for ranking.
            $parsed = $this->splitYear($title);
            $query  = $parsed['title'];
            $year   = $parsed['year'];

            try {
                $hasPoster = is_file($cleanDir . '/poster.jpg') || is_file($cleanDir . '/poster.png');

                // A folder that has its root poster is done... unless
                // --seasons is on, in which case we still need the series
                // id for the season pass, so the search below still runs.
                if ($hasPoster) {
                    printf("Skip   : %s (poster already exists)\n", $title);
                    if (!$this->seasonsFlag) {
                        continue;
                    }
                }

                // Folder name (minus any parenthesized year and trailing
                // junk) = title.
                [$httpCode, $response] = TvdbApi::get('/search', ['query' => $query, 'type' => $type]);
                $data = json_decode($response, true);
                if ($httpCode !== 200) {
                    throw new Exception("search failed (HTTP {$httpCode})");
                }

                $results = $this->enrichEnglish($data['data'] ?? []);
                $results = $this->rankResults($results, $query, $year);
                if ($results === []) {
                    printf("Skip   : %s (no match found)\n", $title);
                    continue;
                }

                // Walk the ranked results until one of them has a poster:
                // some top matches (an exact-name hit on an unrelated
                // series) have no artwork at all, in which case the next
                // best match is the one the user actually wants. The
                // attempt number goes on the Done line as "[2nd]" etc.
                $winner = null;
                $mediaId = null;
                $matchedRow = null;
                $attempt = 0;
                if (!$hasPoster) {
                    foreach ($results as $r) {
                        $candidateId = str_replace($idPrefix, '', $r['id']);
                        $attempt++;

                        $artPath = $artBase . $candidateId . $artSuffix;
                        [$httpCode, $response] = TvdbApi::get($artPath);
                        $data = json_decode($response, true);
                        if ($httpCode !== 200) {
                            throw new Exception("artwork lookup failed (HTTP {$httpCode})");
                        }
                        $winner = $this->selectPoster($data['data']['artworks'] ?? [], $typeMap)['winner'];
                        if ($winner !== null) {
                            $mediaId = $candidateId;
                            $matchedRow = $r;
                            break;
                        }
                    }
                    if ($winner === null) {
                        throw new Exception("no poster artwork found for any match (tried {$attempt})");
                    }
                } else {
                    // Root poster exists; with --seasons the season pass
                    // just uses the top-ranked match.
                    $mediaId = str_replace($idPrefix, '', $results[0]['id']);
                }

                // Root poster (skipped when one already exists).
                if (!$hasPoster) {
                    // Cached to artwork/ as <id>-<basename> by
                    // default, or downloaded straight into the folder
                    // when CACHE_ARTWORK=false (savePoster()).
                    $url = $winner['image'];
                    $cacheName = $mediaId . '-' . basename(parse_url($url, PHP_URL_PATH));
                    $target = $this->savePoster($url, $cacheName, $cleanDir);

                    // Note the attempt when a later match supplied the poster.
                    $nth = $attempt > 1 ? ' [' . $this->ordinal($attempt) . ']' : '';
                    printf("Done   : %s → %s (%s-%s)%s\n",
                        $title, Paths::sanitizePath($target, false),
                        $type, $mediaId, $nth);

                    // --clean: personal tidy-up, only after a successful
                    // poster download + copy.
                    if ($this->cleanFlag) {
                        $this->cleanFolder($cleanDir, $target, $matchedRow, $year);
                    }
                }

                // --seasons: go one nested level deeper for season posters.
                if ($this->seasonsFlag) {
                    $this->downloadSeasonPosters($cleanDir, $title, $mediaId);
                }
            } catch (Exception $e) {
                printf("Skip   : %s (%s)\n", $title, $e->getMessage());
            }
        }
    }

    /**
     * Save a poster image into a folder. Default: download to the artwork
     * cache first (<cacheName>) and copy it across, the way --poster
     * names its files. With CACHE_ARTWORK=false in .env the image is
     * downloaded straight into the folder as poster.<ext> and no cache
     * copy is kept. Returns the path of the poster inside the folder.
     */
    private function savePoster(string $url, string $cacheName, string $folderPath): string
    {
        $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
        if ($ext === '') {
            $ext = 'jpg';
        }
        $target = $folderPath . DIRECTORY_SEPARATOR . 'poster.' . $ext;

        if (!PosterEnv::envFlag('CACHE_ARTWORK', true)) {
            // Direct download — no ./artwork copy.
            TvdbApi::download($url, $target);
            return $target;
        }

        // Cache first, then copy into the folder.
        $artworkDir = __DIR__ . DIRECTORY_SEPARATOR . 'artwork';
        if (!is_dir($artworkDir)) {
            mkdir($artworkDir, 0777, true);
        }
        $dest = $artworkDir . DIRECTORY_SEPARATOR . $cacheName;
        TvdbApi::download($url, $dest);

        if (!copy($dest, $target)) {
            throw new Exception("could not copy poster into {$target}");
        }
        return $target;
    }

    /**
     * --clean pass, run only after a successful poster download + copy
     * (personal tidy-up, printed as "Clean :" lines):
     *   1. delete *.nfo and *.txt files (release-scene text files)
     *   2. strip the release tags listed in RELEASE_TAGS (.env, comma
     *      separated, e.g. "PSA,XYZ" — bare names, the script prepends
     *      the hyphen when checking) from the end of *.mkv/*.mp4
     *      filename bases
     *   3. save a copy of the poster next to the (first) video file,
     *      named <video name>-poster.<ext>
     *   4. rename the folder from the API title and year:
     *      "My Movie  (2026)" (two spaces before the year, colon →
     *      semicolon, other Windows-illegal characters stripped and
     *      logged), then a size tag (GiB: >8.2 "DL+", >4.5 "DL",
     *      >2 "SL", else "SLite") and, when the filename contains
     *      "2160p", ".4k" — e.g. "My Movie  (2026).SL.4k"
     */
    private function cleanFolder(string $folder, string $posterPath, ?array $matchedRow, int $folderYearHint)
    {
        // 1. Release-scene text files.
        $textFiles = array_merge(
            glob($folder . DIRECTORY_SEPARATOR . '*.nfo') ?: [],
            glob($folder . DIRECTORY_SEPARATOR . '*.txt') ?: []
        );
        foreach ($textFiles as $file) {
            if (unlink($file)) {
                printf("Clean : deleted %s\n", basename($file));
            }
        }

        // 2. Strip release tags from video filename bases.
        $tags = PosterEnv::envList('RELEASE_TAGS');
        $videos = array_merge(
            glob($folder . DIRECTORY_SEPARATOR . '*.mkv') ?: [],
            glob($folder . DIRECTORY_SEPARATOR . '*.mp4') ?: []
        );
        $posterBase = ''; // first video's (cleaned) base, for step 3
        $firstVideo = ''; // first video's final path, for step 4
        foreach ($videos as $i => $video) {
            $base = pathinfo($video, PATHINFO_FILENAME);
            $ext  = pathinfo($video, PATHINFO_EXTENSION);
            $newBase = $base;
            foreach ($tags as $tag) {
                $suffix = '-' . $tag; // tags are stored without the hyphen
                if ($tag !== '' && str_ends_with($newBase, $suffix)) {
                    $newBase = substr($newBase, 0, -strlen($suffix));
                }
            }
            $finalPath = $video;
            if ($newBase !== $base) {
                $newPath = $folder . DIRECTORY_SEPARATOR . $newBase . '.' . $ext;
                if (rename($video, $newPath)) {
                    printf("Clean : renamed %s → %s\n", basename($video), basename($newPath));
                    $finalPath = $newPath;
                }
            }
            if ($i === 0) {
                $posterBase = $newBase;
                $firstVideo = $finalPath;
            }
        }

        // 3. Poster copy named after the movie file.
        if ($posterBase !== '') {
            $posterExt = pathinfo($posterPath, PATHINFO_EXTENSION);
            $copyPath = $folder . DIRECTORY_SEPARATOR . $posterBase . '-poster.' . $posterExt;
            if (copy($posterPath, $copyPath)) {
                printf("Clean : saved %s\n", basename($copyPath));
            }
        }

        // 4. Rebuild the folder name from the API data: "My Movie
        // (2026)" (two spaces before the year, colon → semicolon), then
        // the size tag and, when the filename says 2160p, ".4k".
        if ($firstVideo !== '' && $matchedRow !== null) {
            $apiName = $matchedRow['name'] ?? '';
            $apiYear = (int) (substr($matchedRow['first_air_time'] ?? '', 0, 4) ?: ($matchedRow['year'] ?? 0));
            if ($apiYear === 0) {
                $apiYear = $folderYearHint; // folder-name year as a fallback
            }
            if ($apiName !== '' && $apiYear > 0) {
                $titlePart = str_replace(':', ';', trim($apiName));
                $titlePart = str_replace('/', '-', $titlePart); // "/" reads better as a hyphen
                // Windows forbids ? * / \ " < > | in names — strip them
                // (and log the encounter) rather than failing the rename.
                $sanitized = preg_replace('/[?*\/\\\\"<>|]/', '', $titlePart);
                if ($sanitized !== $titlePart) {
                    printf("Clean : stripped illegal characters from title \"%s\"\n", $titlePart);
                }

                $newName = $sanitized . '  (' . $apiYear . ')';
                $newName .= '.' . $this->sizeTag($firstVideo);
                if (stripos(basename($firstVideo), '2160p') !== false) {
                    $newName .= '.4k';
                }

                if ($sanitized !== '' && $newName !== basename($folder)) {
                    $parent = dirname($folder);
                    if (rename($folder, $parent . DIRECTORY_SEPARATOR . $newName)) {
                        printf("Clean : renamed folder → %s\n", $newName);
                    } else {
                        printf("Clean : could not rename folder to %s\n", $newName);
                    }
                }
            }
        }
    }

    /**
     * Quality tag from the movie file size, measured in gibibytes
     * (1 GiB = 1024^3 bytes):
     *   > 8.2 GiB  "DL+"
     *   > 4.5 GiB  "DL"
     *   > 2 GiB    "SL"
     *   ≤ 2 GiB    "SLite"
     */
    private function sizeTag(string $videoPath): string
    {
        $gib = filesize($videoPath) / (1024 ** 3);
        if ($gib > 8.2) return 'DL+';
        if ($gib > 4.5) return 'DL';
        if ($gib > 2)   return 'SL';
        return 'SLite';
    }

    /**
     * --seasons pass, run after the root poster: scan the show folder one
     * level deeper for "Season N" folders (Plex/Kodi style, "01" = 1) and
     * "Specials" (TVDB's season 0). Each season folder gets the poster
     * from the matching season record's `image` field (one
     * /series/{id}/extended call), saved to
     * artwork/<seriesId>-<nn>-<basename> — e.g. 101501-01-6116a0eb8f514.jpg
     * — and copied into the season folder as poster.<ext>.
     * Per-season problems print "Skip :" and move on.
     */
    private function downloadSeasonPosters(string $cleanDir, string $title, string $seriesId)
    {
        // Which immediate children are season folders?
        $found = Paths::scan($cleanDir);
        $seasonDirs = [];
        foreach (($found['dirs'] ?? []) as $child) {
            $number = $this->seasonNumber(basename($child));
            if ($number !== null) {
                $seasonDirs[] = ['path' => $child, 'name' => basename($child), 'number' => $number];
            }
        }
        if ($seasonDirs === []) {
            return;
        }

        // One extended call gives every season's poster URL at once.
        [$code, $body] = TvdbApi::get('/series/' . $seriesId . '/extended');
        if ($code !== 200) {
            printf("Skip   : %s (season lookup failed, HTTP %d)\n", $title, $code);
            return;
        }
        $data = json_decode($body, true);
        $seasons = $data['data']['seasons'] ?? [];
        $defaultTypeId = isset($data['data']['defaultSeasonType']) ? (int) $data['data']['defaultSeasonType'] : null;

        foreach ($seasonDirs as $seasonDir) {
            try {
                $seasonPath = rtrim($seasonDir['path'], '/\\');
                if (is_file($seasonPath . '/poster.jpg') || is_file($seasonPath . '/poster.png')) {
                    printf("Skip   : %s/%s (poster already exists)\n", $title, $seasonDir['name']);
                    continue;
                }

                $season = $this->findSeason($seasons, $defaultTypeId, $seasonDir['number']);
                if ($season === null || empty($season['image'])) {
                    printf("Skip   : %s/%s (no season artwork on TVDB)\n", $title, $seasonDir['name']);
                    continue;
                }

                // Cached to artwork/ as <seriesId>-<nn>-<basename> (nn is
                // zero-padded: "01", "00" for Specials = TVDB season 0),
                // or downloaded straight into the season folder when
                // CACHE_ARTWORK=false (savePoster()).
                $url = $season['image'];
                $nn = str_pad((string) $seasonDir['number'], 2, '0', STR_PAD_LEFT);
                $cacheName = $seriesId . '-' . $nn . '-' . basename(parse_url($url, PHP_URL_PATH));
                $target = $this->savePoster($url, $cacheName, $seasonPath);

                printf("Done   : %s/%s → %s (season %d)\n",
                    $title, $seasonDir['name'], Paths::sanitizePath($target, false), $seasonDir['number']);
            } catch (Exception $e) {
                printf("Skip   : %s/%s (%s)\n", $title, $seasonDir['name'], $e->getMessage());
            }
        }
    }

    /**
     * Map a folder name to its TVDB season number, or null when it is not
     * a season folder. Plex/Kodi convention: "Season 1", "Season 02", ...
     * (case-insensitive) and "Specials" — TVDB's season 0. Some libraries
     * also carry the air year and more ("Season 3 (2018) [1080p]");
     * everything from the parenthesized year onwards is dropped before
     * matching — the season number stays authoritative.
     */
    private function seasonNumber(string $name): ?int
    {
        $name = trim($name);
        // A parenthesized year ends the meaningful part of the name, the
        // same rule as splitYear() ("Season 3 (2018) [1080p]" → "Season 3").
        if (preg_match('/\(\d{4}\)/', $name, $m, PREG_OFFSET_CAPTURE)) {
            $name = substr($name, 0, $m[0][1]);
        }
        $name = trim($name);

        if (preg_match('/^season\s*(\d+)$/i', $name, $m)) {
            return (int) $m[1];
        }
        if (strcasecmp($name, 'specials') === 0) {
            return 0;
        }
        return null;
    }

    /**
     * Find the season record for a folder's season number. TVDB keeps
     * several season types per series (Aired/DVD/Absolute orders) that can
     * reuse the same numbers, so prefer the series' default season type
     * when the API declares one. Returns null when the season is unknown.
     */
    private function findSeason(array $seasons, ?int $defaultTypeId, int $number): ?array
    {
        if ($defaultTypeId !== null) {
            foreach ($seasons as $s) {
                if (isset($s['number']) && (int) $s['number'] === $number
                    && (int) ($s['type']['id'] ?? -1) === $defaultTypeId) {
                    return $s;
                }
            }
        }
        foreach ($seasons as $s) {
            if (isset($s['number']) && (int) $s['number'] === $number) {
                return $s;
            }
        }
        return null;
    }

    /**
     * 1 → "1st", 2 → "2nd", 3 → "3rd", 11 → "11th", ... Used for the
     * "[2nd]" note when a later-ranked match supplied the poster.
     */
    private function ordinal(int $n): string
    {
        $n = abs($n);
        if ($n % 100 >= 11 && $n % 100 <= 13) {
            return $n . 'th';
        }
        return match ($n % 10) {
            1 => $n . 'st',
            2 => $n . 'nd',
            3 => $n . 'rd',
            default => $n . 'th',
        };
    }

    /**
     * Scan a directory supplied via --scan, accepting unix and windows
     * style paths interchangeably (the Paths class mirrors BatchEncoder's
     * sanitizePath/toWinPath pair).
     */
    private function scanFolder() {
        // Normalize whatever the user typed into a forward-slash path.
        $cleanPath = Paths::sanitizePath($this->scanPath);

        // Robust existence check: try Unix path first, then Windows path.
        $existsDir = is_dir($cleanPath) || is_dir(Paths::toWinPath($cleanPath));
        if (!$existsDir) {
            throw new Exception("Directory not found: {$cleanPath}");
        }

        // Paths::scan() gets a Windows-style path, like BatchEncoder passes.
        $scanPath = Paths::toWinPath(rtrim($cleanPath, ' /\\'));
        echo "Scanning: {$scanPath}\n";

        // Paths::scan() returns forward-slash paths for both halves.
        $found = Paths::scan($scanPath);
        $foundDirs  = $found['dirs'];
        $foundFiles = $found['files'];

        if ($this->postersFlag) {
            // Single-folder detection. TV: season folders ("Season
            // N"/"Specials") among the children, or a flat folder of
            // episode files. Movies: movie files sit directly in the
            // folder, so ANY files at the root mean "this IS the movie" —
            // extra subfolders (Subs, Extras, ...) are normal and ignored.
            $seasonDirs = $this->movieFlag
                ? []
                : array_filter($foundDirs, fn($d) => $this->seasonNumber(basename($d)) !== null);
            $rootHasPoster = is_file(rtrim($scanPath, '/\\') . '/poster.jpg')
                          || is_file(rtrim($scanPath, '/\\') . '/poster.png');

            $isSingleShow = $seasonDirs !== [];
            if (!$isSingleShow && $foundFiles !== [] && ($this->movieFlag || $foundDirs === [])) {
                $isSingleShow = true;
            }
            if (!$isSingleShow && $rootHasPoster && $foundDirs === []) {
                $isSingleShow = true;
            }

            if ($isSingleShow) {
                printf("Processing the scan root as a single %s folder.\n", $this->movieFlag ? 'movie' : 'show');
                $this->downloadForFolders([$cleanPath]);
                return;
            }

            // Posters mode: only the immediate child directories matter.
            $this->downloadForFolders($foundDirs);
            return;
        }

        $nDirs = count($foundDirs);
        $nFiles = count($foundFiles);
        printf("\nFound %d %s and %d %s:\n\n",
            $nDirs, $nDirs === 1 ? 'directory' : 'directories',
            $nFiles, $nFiles === 1 ? 'file' : 'files');

        if ($foundDirs === [] && $foundFiles === []) {
            return;
        }

        // Directories first (e.g. TV show folders), then files.
        $rows = [];
        $n = 1;
        foreach ($foundDirs as $dir) {
            $rows[] = [$n++, 'Dir', basename($dir), $dir];
        }
        foreach ($foundFiles as $file) {
            $rows[] = [$n++, 'File', basename($file), $file];
        }

        echo $this->renderTable(['#', 'Type', 'Name', 'Path'], $rows);
    }

    /**
     * Render an ASCII table with box-drawing borders. Header cells are
     * centered, data cells are left-aligned. Column widths grow to fit the
     * widest cell. Display width (mb_strwidth) is used for padding so wide
     * characters (CJK, Thai, ...) line up correctly in the terminal.
     */
    private function renderTable(array $headers, array $rows): string
    {
        $widths = [];
        foreach ($headers as $i => $h) {
            $widths[$i] = mb_strwidth($h);
        }
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], mb_strwidth((string) $cell));
            }
        }

        // Each border segment covers: 1 pad space + cell + 1 pad space.
        $border = function (string $left, string $mid, string $right) use ($widths): string {
            return $left . implode($mid, array_map(
                fn($w) => str_repeat('─', $w + 2),
                $widths
            )) . $right;
        };

        $line = function (array $cells, bool $center) use ($widths): string {
            $parts = [];
            foreach ($cells as $i => $cell) {
                $pad = $widths[$i] - mb_strwidth((string) $cell);
                if ($center) {
                    $left = intdiv($pad, 2);
                    $parts[] = str_repeat(' ', $left + 1) . $cell . str_repeat(' ', $pad - $left + 1);
                } else {
                    $parts[] = ' ' . $cell . str_repeat(' ', $pad + 1);
                }
            }
            return '│' . implode('│', $parts) . '│';
        };

        $out = [];
        $out[] = $border('┌', '┬', '┐');
        $out[] = $line($headers, true);
        $out[] = $border('├', '┼', '┤');
        foreach ($rows as $row) {
            $out[] = $line($row, false);
        }
        $out[] = $border('└', '┴', '┘');

        return implode("\n", $out) . "\n";
    }
}
