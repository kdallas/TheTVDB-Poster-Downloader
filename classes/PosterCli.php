<?php

/**
 * CLI entry point class for the tvdb-posters tools. Parses $argv, then
 * runs the requested action: search by --title, a poster lookup by
 * --poster, or a library scan by --scan. Same shape as BatchEncoder:
 * constructor takes $argv, run() does the work, errors are exceptions
 * caught as "Error: ...".
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
    private $tmdbFlag = false;     // --tmdb (with --title=, search TMDB instead of TheTVDB)
    private $englishCache = [];    // search-result id -> English title (enrichEnglish() fills it)
    private $tmdbCreditShown = false; // TMDB notice already printed (tmdbCredit())

    // Standalone roman numerals up to X — canonicalTitle() turns them
    // into digits so "Arc II" and "Arc 2" compare equal.
    private const ROMAN = ['i' => 1, 'ii' => 2, 'iii' => 3, 'iv' => 4, 'v' => 5,
                           'vi' => 6, 'vii' => 7, 'viii' => 8, 'ix' => 9, 'x' => 10];

    // TMDB attribution, fixed by their API Terms of Use (paragraph 3).
    // Printed once per run by tmdbCredit() — keep the wording verbatim.
    private const TMDB_NOTICE = 'This program uses TMDB and the TMDB APIs but is not endorsed, certified, or otherwise approved by TMDB.';

    // Columns of the search-result tables: the --title listing and the
    // "did you mean?" block in the folder flows (resultRows()).
    private const RESULT_HEADERS = ['ID', 'Title', 'Title (EN)', 'First aired', 'Network'];

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
            } elseif ($this->tmdbFlag) {
                $this->tmdbTitleSearch();
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
                // Stitch spaces if the title was unquoted: --title=Star City
                $this->titleInput = $this->stitchWords($argv, $i, substr($arg, 8));
            }
            elseif (str_starts_with($arg, '--poster=')) {
                $this->posterId = substr($arg, 9);
            }
            elseif (str_starts_with($arg, '--scan=')) {
                // Stitch spaces if the path was unquoted: --scan=/c/My TV Shows
                $this->scanPath = $this->stitchWords($argv, $i, substr($arg, 7));
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
            elseif ($arg === '--tmdb') {
                $this->tmdbFlag = true;
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
            $posterCheck = $this->bareId($this->posterId);
            if (!ctype_digit($posterCheck)) {
                throw new Exception('Invalid --poster value "' . $this->posterId . '" — expected a numeric id'
                                  . " (optionally with a {$this->idPrefix()} prefix)");
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
        if ($this->cleanFlag && empty($this->scanPath)) {
            throw new Exception('--clean can only be used together with --scan');
        }
        if ($this->tmdbFlag && ($this->titleInput === '' || $this->scanPath !== '' || $this->posterId !== '')) {
            throw new Exception('--tmdb applies to a --title search only: php run.php --title="Star City" --tmdb');
        }
    }

    /**
     * The mode's id prefix ("series-" / "movie-") and media kind, both
     * derived from --movie — resolved here so the rest of the class
     * doesn't repeat the ternary.
     */
    private function idPrefix(): string
    {
        return $this->movieFlag ? 'movie-' : 'series-';
    }

    private function mediaKind(): string
    {
        return $this->movieFlag ? 'movie' : 'series';
    }

    /**
     * Strip a LEADING series-/movie- prefix from an id (--poster= and
     * search results may carry it). str_replace would also strip the
     * token from anywhere inside the string, so match the head only.
     */
    private function bareId(string $id): string
    {
        $prefix = $this->idPrefix();
        return str_starts_with($id, $prefix) ? substr($id, strlen($prefix)) : $id;
    }

    /**
     * Stitch the words after a --title= / --scan= value into it, for
     * when the value was left unquoted: "--title=Star City" arrives as
     * two $argv entries. Stops at the next flag and advances $i past
     * the words consumed.
     */
    private function stitchWords(array $argv, int &$i, string $value): string
    {
        for ($j = $i + 1; $j < count($argv); $j++) {
            if (str_starts_with($argv[$j], '-')) break;
            $value .= " " . $argv[$j];
            $i++;
        }
        return $value;
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
     * Canonical comparison form: lowercase, parenthesized years
     * stripped, any punctuation runs collapsed to a single space —
     * "Face/Off" and "Face-Off" both compare equal to "Face Off" — and
     * roman numerals as standalone tokens become arabic digits, so the
     * API's "Arc II" equals a scene name's "Arc 2". Both sides of any
     * comparison use this form, so titles are never distorted
     * asymmetrically. Letters/numbers of any script survive (CJK
     * titles intact).
     */
    private function canonicalTitle(string $s): string
    {
        $s = mb_strtolower(trim(preg_replace('/\(\d{4}\)/', '', $s)));
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
        $s = preg_replace_callback(
            '/(?<![a-z])(iii|viii|vii|vi|iv|ix|ii|i|v|x)(?![a-z])/',
            fn($m) => (string) self::ROMAN[$m[1]],
            $s
        );
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * Match tier for one title against the needle; lower = better.
     * $exactTier/$exactTier+1 are returned for exact/contains matches,
     * so the caller can rank own-name matches above English ones.
     */
    private function titleTier(string $title, string $needle, int $exactTier): int
    {
        $title = $this->canonicalTitle($title);
        if ($title === $needle) return $exactTier;
        if ($needle !== '' && str_contains($title, $needle)) return $exactTier + 1;
        return 5;
    }

    /**
     * Tier number for one result against a canonicalised needle; lower =
     * better. The own name is tried first, then the English title, so
     * own-title matches beat translation matches.
     */
    private function resultTier(array $r, string $needle): int
    {
        $nameTier = $this->titleTier($r['name'] ?? '', $needle, 1);
        if ($nameTier !== 5) {
            return $nameTier;
        }
        return $this->titleTier($r['_english'] ?? '', $needle, 3);
    }

    /**
     * Does any result's own or English title match $needle (tiers 1-4)?
     * Lets a shortened search check, before re-ranking, that the
     * ORIGINAL query matches something — see searchTitle().
     */
    private function hasTitleMatch(array $results, string $needle): bool
    {
        $needle = $this->canonicalTitle($needle);
        foreach ($results as $r) {
            if ($this->resultTier($r, $needle) < 5) {
                return true;
            }
        }
        return false;
    }

    /**
     * The row's title closest to the searched folder name — the API's
     * own name normally, but the English title when that is what the
     * folder name actually matched (a folder named "Berserk: The Golden
     * Age..." keeps English; a "ベルセルク..." folder keeps Japanese).
     */
    private function closestTitle(array $row, string $query): string
    {
        $needle = $this->canonicalTitle($query);
        $name = $row['name'] ?? '';
        $en   = $row['_english'] ?? '';

        $nameTier = $this->titleTier($name, $needle, 1);
        $enTier   = $this->titleTier($en, $needle, 3);

        // Lower tier wins; ties keep the API's own name.
        return $enTier < $nameTier ? $en : $name;
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
     * Own-title matches rank above translation matches — searching
     * "Kaleidoscope" should put the series actually titled
     * "Kaleidoscope (2023)" above a Vietnamese show whose English
     * translation happens to be exactly "Kaleidoscope".
     * A year hint from the caller (a folder name, --title) forms a
     * LEADING subset instead of a tie-breaker: rows from that year come
     * first, so the first installment beats a sequel. A folder named
     * "My.Movie.2018..." must find "My Movie", not "My Movie 2" — the
     * sequel's own name merely CONTAINS the searched title, and without
     * the hint it would sit a tier higher than the 2018 film, whose own
     * name is in another script and whose exact match is the English
     * title. Only rows that matched the title (tiers 1-4)
     * qualify: a coincidental year on a loosely matched record must not
     * ride the hint to the top, or the poster walk would fall through to
     * it. Within each part: tier order, then newest first by air date;
     * no-date entries sink. PHP 8 sorts are stable, so ties keep the
     * API's original order.
     */
    private function rankResults(array $results, string $needle, int $year = 0): array
    {
        $needle = $this->canonicalTitle($needle);

        // Tier number for a single result; lower = better.
        $tier = fn(array $r) => $this->resultTier($r, $needle);
        // Some records only carry a year, no full air date — use it as
        // the fallback so new releases rank correctly.
        $yearOf = fn(array $r) => (int) (substr($r['first_air_time'] ?? '', 0, 4) ?: ($r['year'] ?? 0));

        // Decorate-sort-undecorate: compute each row's rank keys once,
        // then sort on the precomputed values. Comparing rows directly
        // would re-run the canonicalTitle() regexes on BOTH rows for
        // EVERY comparison (O(n log n) times).
        $scored = array_map(fn($r) => [
            'tier' => $tier($r),
            'year' => $yearOf($r),
            'row'  => $r,
        ], $results);

        usort($scored, function ($a, $b) use ($year) {
            // A year supplied by the caller is the strongest signal we
            // have, so its rows form the head of the list — ahead of
            // sequels that only contain the searched name. Tiers 1-4
            // only: a tier-5 row (an alias/overview match) must not be
            // promoted on a coincidental year.
            if ($year > 0) {
                $aMatch = $a['tier'] < 5 && $a['year'] === $year;
                $bMatch = $b['tier'] < 5 && $b['year'] === $year;
                if ($aMatch !== $bMatch) {
                    return $aMatch ? -1 : 1;
                }
            }

            $tierDiff = $a['tier'] - $b['tier'];
            if ($tierDiff !== 0) {
                return $tierDiff;
            }

            return $b['year'] - $a['year']; // newest first
        });

        return array_column($scored, 'row');
    }

    /**
     * Best-effort English title for a search result. When the series'
     * primary language is already English the name IS the English title,
     * so we skip the API call. Otherwise ask for the English translation
     * record (GET /series/{id}/translations/eng) and use its `name`
     * field. Returns '' when no English name is available. Lookups are
     * cached per id — shortened-query retries can ask about the same
     * series more than once in a run.
     */
    private function englishTitle(array $result): string
    {
        if (($result['primary_language'] ?? '') === 'eng') {
            return $result['name'] ?? '';
        }

        $id = $result['id'] ?? '';
        if ($id === '') {
            return '';
        }
        if (array_key_exists($id, $this->englishCache)) {
            return $this->englishCache[$id];
        }

        [$code, $body] = TvdbApi::get(($this->movieFlag ? '/movies/' : '/series/') . $this->bareId($id) . '/translations/eng');
        $english = '';
        if ($code === 200) {
            $data = json_decode($body, true);
            $english = $data['data']['name'] ?? '';
        }

        $this->englishCache[$id] = $english;
        return $english;
    }

    /**
     * Add each result's English title under the '_english' key — one
     * API call per non-English-primary result, cached per id for the
     * whole run. Called once BEFORE ranking so the ranker's
     * exact-match tier can see English titles, and the same values are
     * reused for the search table's Title (EN) column.
     */
    private function enrichEnglish(array $results): array
    {
        foreach ($results as &$r) {
            $r['_english'] = $this->englishTitle($r);
        }
        unset($r);
        return $results;
    }

    /**
     * --title --tmdb: search TMDB instead of TheTVDB. Same year hint and
     * ranking as the TVDB search, so the two can be compared side by
     * side; this is also the lookup half of the planned fallback for
     * titles TheTVDB does not have. TMDB returns the localised (en-US)
     * title directly and has no translation records, so `_english` stays
     * empty and only the own-name tiers of resultTier() apply.
     */
    private function tmdbTitleSearch(): void
    {
        $parsed = $this->splitYear($this->titleInput);
        $query  = $parsed['title'];
        $year   = $parsed['year'];
        $kind   = $this->movieFlag ? 'movie' : 'tv';

        // Call first, print after: a rejected key should surface as the
        // error, not as a half-written header line.
        $results = TmdbApi::search($query, $kind);

        printf("TMDB search \"%s\" (%s%s): %d result(s)\n\n",
            $query, $kind, $year > 0 ? ", year {$year}" : '', count($results));

        if ($results === []) {
            return;
        }

        // Normalise to the shape rankResults() expects, then reuse it, so
        // the ordering is the one the TVDB search would have produced.
        $rows = [];
        foreach ($results as $r) {
            $rows[] = [
                'id'             => 'tmdb-' . $kind . '-' . ($r['id'] ?? '?'),
                'name'           => $r['title'] ?? $r['name'] ?? '',
                '_english'       => '',
                'first_air_time' => $r['release_date'] ?? $r['first_air_date'] ?? '',
                'year'           => 0,
                '_original'      => $r['original_title'] ?? $r['original_name'] ?? '',
                '_poster'        => $r['poster_path'] ?? null,
            ];
        }
        $rows = $this->rankResults($rows, $query, $year);

        $table = [];
        foreach ($rows as $r) {
            $table[] = [
                $r['id'],
                $r['name'] !== '' ? $r['name'] : '(no title)',
                $r['_original'] !== '' ? $r['_original'] : '—',
                $r['first_air_time'] !== '' ? $r['first_air_time'] : '—',
                $r['_poster'] !== null ? 'yes' : '—',
            ];
        }
        echo $this->renderTable(['ID', 'Title', 'Original title', 'Released', 'Poster'], $table);

        // The top match's poster URL proves the image host and path shape
        // work before anything downloads through them.
        if ($rows[0]['_poster'] !== null) {
            printf("\nTop match poster: %s\n", TmdbApi::posterUrl($rows[0]['_poster']));
        }

        $this->tmdbCredit();
    }

    private function search() {
        // A title may carry a year in parentheses ("Lazarus (2025)"):
        // search without it, but keep the year as a hint for ranking.
        $parsed = $this->splitYear($this->titleInput);
        $query  = $parsed['title'];
        $year   = $parsed['year'];

        // --movie switches the search to movies (same /search endpoint).
        $type = $this->mediaKind();

        $found = $this->searchTitle($query, $year);
        $results = $found['results'];
        $finalQuery = $found['query'];

        $yearNote = $year > 0 ? ", year {$year}" : '';
        if ($results === []) {
            printf("Search \"%s\" (%s%s): 0 result(s)\n\nNo results.\n", $query, $type, $yearNote);
            return;
        }

        if ($finalQuery !== $query) {
            printf("Search \"%s\" (%s%s): %d result(s) — shortened from \"%s\"\n\n",
                $finalQuery, $type, $yearNote, count($results), $query);
        } else {
            printf("Search \"%s\" (%s%s): %d result(s)\n\n", $finalQuery, $type, $yearNote, count($results));
        }

        echo $this->renderTable(self::RESULT_HEADERS, $this->resultRows($results));
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
        $id   = $this->bareId($this->posterId);
        $kind = $this->mediaKind();
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
        $filename = $id . '-' . basename(parse_url($url, PHP_URL_PATH) ?? '');
        $dir = Paths::projectRoot() . DIRECTORY_SEPARATOR . 'artwork';
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
            throw new Exception("Could not create {$dir}");
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
     * Search the API for a title; returns ['results' => ranked hits,
     * 'query' => the query that actually matched, 'titleMatched' =>
     * whether the full title matched a title on TVDB] (throws when the
     * API call itself fails). If the full query finds nothing, trailing
     * words are dropped one at a time — the API's index can miss long
     * titles. titleMatched is false when the search had to be shortened
     * AND the full original title matches nothing: the hits are then
     * the closest neighbours rather than the title itself, and the
     * folder flows skip with a "did you mean?" list instead of
     * guessing. Shared by the --posters, --clean, and --title flows.
     */
    private function searchTitle(string $query, int $year): array
    {
        $results = [];
        $finalQuery = $query;
        $titleMatched = false;
        $queryTokens = preg_split('/\s+/', trim($query)) ?: [];
        while ($queryTokens !== []) {
            $tryQuery = implode(' ', $queryTokens);

            [$httpCode, $response] = TvdbApi::get('/search', ['query' => $tryQuery, 'type' => $this->mediaKind()]);
            $data = json_decode($response, true);
            if ($httpCode !== 200) {
                throw new Exception("search failed (HTTP {$httpCode})");
            }

            $results = $this->enrichEnglish($data['data'] ?? []);
            $results = $this->rankResults($results, $tryQuery, $year);
            if ($results !== []) {
                if ($tryQuery === $query) {
                    // Nothing was dropped — the API matched the title as
                    // asked, so the hits are trusted as they stand.
                    $titleMatched = true;
                } else {
                    // The query had to be shortened. Re-rank the
                    // (greedier) results against the ORIGINAL query —
                    // the later words may isolate the exact entry
                    // ("...Arc 2 The Battle For Doldrey" among the three
                    // Golden Age movies). Only when the original query
                    // matches a title at all: if it matches nothing (a
                    // release TVDB hasn't listed yet), re-ranking would
                    // flatten every row to tier 5 and collapse the list
                    // to newest-first, handing the poster to an
                    // unrelated recent title.
                    $titleMatched = $this->hasTitleMatch($results, $query);
                    if ($titleMatched) {
                        $results = $this->rankResults($results, $query, $year);
                    }
                }
                $finalQuery = $tryQuery;
                break;
            }
            array_pop($queryTokens);
        }

        return ['results' => $results, 'query' => $finalQuery, 'titleMatched' => $titleMatched];
    }

    /**
     * The folder's existing poster (poster.jpg preferred over
     * poster.png), or '' when there is none.
     */
    private function existingPoster(string $dir): string
    {
        if (is_file($dir . '/poster.jpg')) return $dir . '/poster.jpg';
        if (is_file($dir . '/poster.png')) return $dir . '/poster.png';
        return '';
    }

    /**
     * "Did you mean?" block for a folder whose full title matched nothing
     * on TVDB: the closest titles the shortened search did find, printed
     * as the same table the --title search uses, so the user can rename
     * the folder to one of them and run again. Only tier 1-4 hits against
     * the shortened query ($needle) are listed — the unrelated fuzzy
     * matches the API returns for any query are counted in the lead-in,
     * not dumped, which also keeps a library scan's output readable.
     * The lead-in and the closing line are indented to sit under the
     * caller's "Skip   :" line.
     */
    private function suggestTitles(array $results, string $needle): void
    {
        $needle = $this->canonicalTitle($needle);

        $relevant = [];
        foreach ($results as $r) {
            if ($this->resultTier($r, $needle) < 5) {
                $relevant[] = $r;
            }
        }
        if ($relevant === []) {
            return;
        }

        printf("         Did you mean one of these? (%d of %d result%s match the title)\n\n",
            count($relevant), count($results), count($results) === 1 ? '' : 's');
        echo $this->renderTable(self::RESULT_HEADERS, $this->resultRows($relevant));
        printf("\n         Rename the folder to one of those titles and run again.\n");
    }

    /**
     * --scan --posters mode. For each immediate child directory (a TV show
     * folder — or a movie folder with --movie): skip it if it already has
     * poster.jpg/poster.png; otherwise use the folder name as the title,
     * find the show, pick its poster (same funnel as --poster), download
     * it to ./artwork/, and copy it into the folder as poster.<ext> (a
     * jpg source → poster.jpg). With --seasons, a second pass then
     * fetches posters for the folder's "Season N"/"Specials" subfolders
     * (downloadSeasonPosters()); with --clean the tidy-up pass runs after
     * a successful download (cleanFolder()). Per-folder problems print
     * "Skip :" and move on to the next folder.
     */
    private function downloadForFolders(array $dirs) {
        // Fetch the artwork type map once for the whole run.
        $typeMap = $this->fetchArtworkTypes();

        // Movie mode decides the search type and the artwork endpoint —
        // resolved once instead of per folder.
        $type      = $this->mediaKind();
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

            // Movie folders sometimes omit the year from the folder name
            // — fall back to the main video file's name
            // ("My.Movie.2018.1080p..."). The folder name wins when both
            // carry one.
            if ($year === 0 && $this->movieFlag) {
                $year = $this->videoYear($cleanDir);
            }

            try {
                $hasPoster = $this->existingPoster($cleanDir) !== '';

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
                $found = $this->searchTitle($query, $year);
                $results = $found['results'];
                $matchedQuery = $found['query'];
                if ($results === []) {
                    printf("Skip   : %s (no match found)\n", $title);
                    continue;
                }

                // The full folder title matched nothing on TVDB — the
                // search only found anything by dropping words. Don't
                // guess: report it and offer the closest titles.
                if (!$found['titleMatched']) {
                    printf("Skip   : %s (no match for the full title on TVDB)\n", $title);
                    $this->suggestTitles($results, $matchedQuery);
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
                        $candidateId = $this->bareId($r['id']);
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
                    $mediaId = $this->bareId($results[0]['id']);
                    $matchedRow = $results[0];
                }

                // Report what the folder matched on (the title closest to
                // the query that actually matched, plus the id).
                printf("Matched: \"%s\" (%s-%s)\n",
                    $this->closestTitle($matchedRow, $matchedQuery), $type, $mediaId);

                // Root poster (skipped when one already exists).
                if (!$hasPoster) {
                    // Cached to artwork/ as <id>-<basename> by
                    // default, or downloaded straight into the folder
                    // when CACHE_ARTWORK=false (savePoster()).
                    $url = $winner['image'];
                    $cacheName = $mediaId . '-' . basename(parse_url($url, PHP_URL_PATH) ?? '');
                    $target = $this->savePoster($url, $cacheName, $cleanDir);

                    // Note the attempt when a later match supplied the poster.
                    $nth = $attempt > 1 ? ' [' . $this->ordinal($attempt) . ']' : '';
                    printf("Done   : %s → %s (%s-%s)%s\n",
                        $title, Paths::sanitizePath($target, false),
                        $type, $mediaId, $nth);

                    // --clean: personal tidy-up, only after a successful
                    // poster download + copy.
                    if ($this->cleanFlag) {
                        $this->cleanFolder($cleanDir, $target, $matchedRow, $year, $matchedQuery);
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
        $ext = pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);
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
        $artworkDir = Paths::projectRoot() . DIRECTORY_SEPARATOR . 'artwork';
        if (!is_dir($artworkDir) && !@mkdir($artworkDir, 0777, true)) {
            throw new Exception("Could not create {$artworkDir}");
        }
        $dest = $artworkDir . DIRECTORY_SEPARATOR . $cacheName;
        TvdbApi::download($url, $dest);

        if (!copy($dest, $target)) {
            throw new Exception("could not copy poster into {$target}");
        }
        return $target;
    }

    /**
     * --scan --clean mode without --posters: run the tidy-up pass over
     * each folder directly — no downloads. The -poster copy uses the
     * folder's existing poster (when there is one), and the rename step
     * uses the top search match's title and year.
     */
    private function cleanFolders(array $dirs)
    {
        foreach ($dirs as $dir) {
            $title = basename($dir);
            $cleanDir = rtrim($dir, '/');

            $parsed = $this->splitYear($title);
            $query  = $parsed['title'];
            $year   = $parsed['year'];

            // Same second source as downloadForFolders(): the folder name
            // wins, the main video file's name fills in when it has no
            // year. Keeps the standalone --clean rename in step with the
            // --posters flow.
            if ($year === 0 && $this->movieFlag) {
                $year = $this->videoYear($cleanDir);
            }

            try {
                // The top match supplies the API title + year for the
                // rename step.
                $found = $this->searchTitle($query, $year);
                $results = $found['results'];
                if ($results === []) {
                    printf("Skip   : %s (no match found)\n", $title);
                    continue;
                }

                // Same rule as downloadForFolders(): a title TVDB hasn't
                // listed is not renamed on a guess.
                if (!$found['titleMatched']) {
                    printf("Skip   : %s (no match for the full title on TVDB)\n", $title);
                    $this->suggestTitles($results, $found['query']);
                    continue;
                }

                // Poster copy source: the folder's existing poster, if any.
                $poster = $this->existingPoster($cleanDir);

                $this->cleanFolder($cleanDir, $poster, $results[0], $year, $found['query']);
            } catch (Exception $e) {
                printf("Skip   : %s (%s)\n", $title, $e->getMessage());
            }
        }
    }

    /**
     * Root-level files in $dir with the given extension (case-
     * insensitive), in directory order. scandir()-based because glob()
     * would read the [ and ] that scene folder names carry — "My Movie
     * Test (1999) [1080p] [BluRay] [5.1]" — as character classes,
     * so a pattern built from such a path matches nothing.
     */
    private function folderFiles(string $dir, string $ext): array
    {
        $files = [];
        $entries = scandir($dir);
        if ($entries === false) {
            return $files;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (strcasecmp(pathinfo($entry, PATHINFO_EXTENSION), $ext) === 0
                && is_file($dir . DIRECTORY_SEPARATOR . $entry)) {
                $files[] = $dir . DIRECTORY_SEPARATOR . $entry;
            }
        }
        return $files;
    }

    /**
     * The folder's root video files (.mkv and .mp4), largest first in
     * movie mode — scene folders sometimes carry a small "sample" video
     * beside the feature, and the biggest file is the best candidate for
     * the size tag, the 2160p check and the -poster copy's name. TV
     * folders keep directory order: their files are episode releases,
     * where the biggest episode means nothing in particular.
     */
    private function videoFiles(string $dir): array
    {
        $videos = array_merge(
            $this->folderFiles($dir, 'mkv'),
            $this->folderFiles($dir, 'mp4')
        );
        if ($this->movieFlag) {
            usort($videos, fn($a, $b) => (@filesize($b) ?: 0) <=> (@filesize($a) ?: 0));
        }
        return $videos;
    }

    /**
     * Year carried by the folder's main video file (videoFiles()), or 0
     * when there is none. Movie folders sometimes omit the year from the
     * folder name ("My Movie/" holding My.Movie.2018.1080p.mkv) — fold
     * that in as a second source for the rank hint. Movie mode only: a
     * flat TV folder's files are episode releases, where a year token
     * may be an air year rather than the show's.
     */
    private function videoYear(string $dir): int
    {
        $videos = $this->videoFiles($dir);
        if ($videos === []) {
            return 0;
        }
        return $this->splitYear(pathinfo($videos[0], PATHINFO_FILENAME))['year'];
    }

    /**
     * --clean pass, run only after a successful poster download + copy
     * (personal tidy-up, printed as "Clean  :" lines):
     *   1. delete *.nfo and *.txt files (release-scene text files)
     *   2. strip the release tags listed in RELEASE_TAGS (.env, comma
     *      separated, e.g. "PSA,XYZ" — bare names, the script prepends
     *      the hyphen when checking) from the end of *.mkv/*.mp4
     *      filename bases
     *   3. save a copy of the poster next to the main video file
     *      (largest first in movie mode — videoFiles()), named
     *      <video name>-poster.<ext>
     *   4. rename the folder from the matched title closest to the
     *      searched name (own name or English title) and the year:
     *      "My Movie  (2026)" (two spaces before the year, colon →
     *      semicolon, other Windows-illegal characters stripped and
     *      logged), then a size tag (GiB: >8.2 "DL+", >4.5 "DL",
     *      >2 "SL", else "SLite") and, when the filename contains
     *      "2160p", ".4k" — e.g. "My Movie  (2026).SL.4k"
     */
    private function cleanFolder(string $folder, string $posterPath, ?array $matchedRow, int $folderYearHint, string $query)
    {
        // 1. Release-scene text files.
        $textFiles = array_merge(
            $this->folderFiles($folder, 'nfo'),
            $this->folderFiles($folder, 'txt')
        );
        foreach ($textFiles as $file) {
            if (unlink($file)) {
                printf("Clean  : deleted %s\n", basename($file));
            }
        }

        // 2. Strip release tags from video filename bases.
        $tags = PosterEnv::envList('RELEASE_TAGS');
        $videos = $this->videoFiles($folder);
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
                    printf("Clean  : renamed %s → %s\n", basename($video), basename($newPath));
                    $finalPath = $newPath;
                }
            }
            if ($i === 0) {
                $posterBase = $newBase;
                $firstVideo = $finalPath;
            }
        }

        // 3. Poster copy named after the movie file (only when there is
        // a poster to copy — --clean alone may find none).
        if ($posterBase !== '' && $posterPath !== '') {
            $posterExt = pathinfo($posterPath, PATHINFO_EXTENSION);
            $copyPath = $folder . DIRECTORY_SEPARATOR . $posterBase . '-poster.' . $posterExt;
            if (copy($posterPath, $copyPath)) {
                printf("Clean  : saved %s\n", basename($copyPath));
            }
        }

        // 4. Rebuild the folder name from the API data: "My Movie
        // (2026)" (two spaces before the year, colon → semicolon), then
        // the size tag and, when the filename says 2160p, ".4k".
        if ($firstVideo !== '' && $matchedRow !== null) {
            $apiYear = (int) (substr($matchedRow['first_air_time'] ?? '', 0, 4) ?: ($matchedRow['year'] ?? 0));
            if ($apiYear === 0) {
                $apiYear = $folderYearHint; // folder-name year as a fallback
            }
            $apiName = $this->closestTitle($matchedRow, $query);
            if ($apiName !== '' && $apiYear > 0) {
                $titlePart = str_replace(':', ';', trim($apiName));
                $titlePart = str_replace('/', '-', $titlePart); // "/" reads better as a hyphen
                // Windows forbids ? * / \ " < > | in names — strip them
                // (and log the encounter) rather than failing the rename.
                $sanitized = preg_replace('/[?*\/\\\\"<>|]/', '', $titlePart);
                if ($sanitized !== $titlePart) {
                    printf("Clean  : stripped illegal characters from title \"%s\"\n", $titlePart);
                }

                $newName = $sanitized . '  (' . $apiYear . ')';
                $newName .= '.' . $this->sizeTag($firstVideo);
                if (stripos(basename($firstVideo), '2160p') !== false) {
                    $newName .= '.4k';
                }

                if ($sanitized !== '' && $newName !== basename($folder)) {
                    $parent = dirname($folder);
                    if (rename($folder, $parent . DIRECTORY_SEPARATOR . $newName)) {
                        printf("Clean  : renamed folder → %s\n", $newName);
                    } else {
                        printf("Clean  : could not rename folder to %s\n", $newName);
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
                if ($this->existingPoster($seasonPath) !== '') {
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
                $cacheName = $seriesId . '-' . $nn . '-' . basename(parse_url($url, PHP_URL_PATH) ?? '');
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

        if ($this->postersFlag || $this->cleanFlag) {
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
                $targets = [$cleanPath];
            } else {
                // Only the immediate child directories matter.
                $targets = $foundDirs;
            }

            if ($this->postersFlag) {
                $this->downloadForFolders($targets);
            } else {
                // --clean on its own: tidy up without downloading.
                $this->cleanFolders($targets);
            }
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
     * Search results as rows for the result table (RESULT_HEADERS) —
     * shared by the --title listing and the folder flows' "did you
     * mean?" block. Missing values render as an em dash; a record with
     * only a year and no full air date shows the bare year.
     */
    private function resultRows(array $results): array
    {
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
        return $rows;
    }

    /**
     * Display width of a string, for terminal padding. mb_strwidth()
     * measures East Asian width per character, but a terminal gives no
     * cell to a combining mark — a Devanagari conjunct such as "क्ष"
     * (ka + virama + ssa) is three code points and one cell — so
     * non-spacing and enclosing marks (Mn/Me) and the zero-width
     * joiners are subtracted. Without this, a title in an Indic, Arabic
     * or Hebrew script pushes its row's borders out of line with the
     * rest. Falls back to the plain mb_strwidth() value if the string
     * isn't valid UTF-8 (preg_match_all returns false, which casts to 0).
     *
     * TABLE_MARKS_ADJUST (.env, default 0) nudges that measurement, once
     * per cell holding any such mark. Whether a terminal spends one cell
     * or two on a conjunct depends on how its font shapes the glyphs,
     * not on the characters, so no rule reading the string alone can
     * match it: a terminal that draws conjuncts as single glyphs
     * measures a cell narrower than the Unicode rule and needs -1 here
     * to line up. Only cells with marks are nudged — applying it to
     * every cell would shift the rows that were already right, since the
     * border widths are computed from these same measurements.
     */
    private function displayWidth(string $s): int
    {
        $marks = (int) preg_match_all('/\p{Mn}|\p{Me}|\x{200B}|\x{200C}|\x{200D}/u', $s);
        $width = mb_strwidth($s) - $marks;

        if ($marks > 0) {
            $width += (int) (PosterEnv::env()['TABLE_MARKS_ADJUST'] ?? 0);
        }

        return $width;
    }

    /**
     * TMDB attribution — printed once per run, the first time a poster
     * comes from the TMDB fallback, so a library scan credits TMDB once
     * rather than once per folder. The sentence is fixed by TMDB's API
     * Terms of Use (paragraph 3) and must stay verbatim; the same notice
     * appears in README.md and run.php's usage block, the program's other
     * attribution surfaces. Called wherever TMDB supplies data — the
     * --title --tmdb search today, the folder fallback next.
     */
    private function tmdbCredit(): void
    {
        if ($this->tmdbCreditShown) {
            return;
        }
        $this->tmdbCreditShown = true;

        printf("\n%s\n", self::TMDB_NOTICE);
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
            $widths[$i] = $this->displayWidth($h);
        }
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], $this->displayWidth((string) $cell));
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
                $pad = $widths[$i] - $this->displayWidth((string) $cell);
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
