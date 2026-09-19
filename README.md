# TheTVDB Poster Downloader

A small PHP CLI tool that talks to the [TheTVDB v4 API](https://thetvdb.github.io/v4-api/) and fetches poster artwork for your TV library. Point it at a folder of TV shows and it matches each folder to a series, downloads the poster, and drops `poster.jpg` (or `poster.png`) into the folder — the convention Plex, Kodi, and Jellyfin all understand. With the `--seasons` flag it also fetches posters for `Season N` / `Specials` folders.

## Requirements

- PHP 8.0+ CLI with the `curl` extension enabled (developed on PHP 8.5, Windows + Git Bash)
- A [TheTVDB](https://thetvdb.com) API key (v4 API)

## Setup

1. Create a `.env` file in the project folder containing your API key:

   ```
   API_KEY=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
   ```

   The `AUTH_TOKEN` and `AUTH_EXPIRY` entries are written automatically by the scripts — you only provide the key.

   Optionally add `CACHE_ARTWORK=false` to skip the `artwork/` cache: posters are then downloaded straight into each folder and no extra copy is kept.

2. Run `php run.php` — you can invoke it from any directory; the scripts resolve the classes and `.env` relative to the project folder itself.

Login happens automatically on first run — there is deliberately no login command. The token is reused until one day before it expires, to avoid requesting more tokens than necessary. `.env` holds live secrets and is gitignored.

## Usage

Everything goes through the single entry point, `run.php`:

```
Search a series:   php run.php --title="Star City"
Poster by id:      php run.php --poster=449146
Scan a library:    php run.php --scan=/x/SciFi
Fetch posters:     php run.php --scan=/x/SciFi --posters
Fetch + seasons:   php run.php --scan=/x/SciFi --posters --seasons
Movies:            php run.php --title="Interstellar" --movie
Movies:            php run.php --poster=131079 --movie
Movies:            php run.php --scan=/x/Movies --posters --movie
Clean:             php run.php --scan=/x/Movies --posters --clean
Clean:             php run.php --scan=/x/Movies/Interstellar --clean
```

### Search a series — `--title=`

```sh
php run.php --title="My Hero Academia"
```

Prints a table of matches (ID, Title, Title (EN), First aired, Network; `—` for missing values). Records with only a year and no full air date (common for new releases) show just the year, and ranking treats it as the air year. English titles are fetched from the API's translation records. If the API finds nothing for a long title, the search retries with trailing words dropped until something matches, then re-ranks those results against the full original title to isolate the exact entry. Results are ranked by match quality: exact own title, own title containing the term, exact English title, English title containing the term — then newest first. A year in the search (`--title="Lazarus (2025)"`) is stripped from the query and used as a ranking hint — and a strong one: matches from that year form the head of the list, so the first installment wins over a sequel whose title merely contains the searched name (`My Movie` beats `My Movie 2` for a 2018 query).

### Fetch a poster by series ID — `--poster=`

```sh
php run.php --poster=449146
```

Picks the poster the way the TVDB website does — Poster type, then English series-level posters, then English, then series-level, highest score winning — and saves it to `artwork/`, named `449146-6a0e1f176a889.jpg` (`<seriesId>-<image basename>`). The `series-` prefix you see in search results is accepted too (`--poster=series-449146`) but never required.

### Scan a library — `--scan=`

```sh
php run.php --scan=/x/SciFi
```

Lists the folder contents: subdirectories first, then files. Windows (`G:\...`), unix (`/x/...`), and relative paths are accepted interchangeably.

### Fetch posters for a library — `--posters`

```sh
php run.php --scan=/x/SciFi --posters
```

For each show folder: if `poster.jpg`/`poster.png` already exists it is skipped; otherwise the folder name is searched against the API (a parenthesized year like `Lazarus (2025)` is used as a ranking hint) and the best match's poster is saved to `artwork/` and copied into the folder as `poster.<ext>`. If the best match has no poster artwork at all, the next best match is tried — the `Done   :` line then shows the attempt, e.g. `(series-410092) [2nd]`. A year ends the meaningful part of the folder name — parenthesized (`Interstellar (2014).abc.XYZ`) or a bare dot-separated token in release-style names (`Interstellar.2014.1080p...`) — so quality/format suffixes after it are dropped automatically. In movie mode, any video files at the folder root mark it as the movie itself, so subfolders like `Subs` are ignored — and the main video file (the largest root video, so a small `sample` sitting beside the feature is passed over) supplies a year when the folder name has none, as in `My Movie/` holding `My.Movie.2018.1080p.mkv`. The folder name wins when both carry a year.

A folder whose full title matches nothing on TVDB is skipped rather than guessed at — `Skip   : <folder> (no match for the full title on TVDB)` — and the closest titles the search did find are printed as a table, so you can rename the folder to one of them and run again. A title TVDB simply hasn't listed is the usual cause: a release too new for its database, or one it has no record of.

You can also point `--scan` directly at a single show folder rather than a library — a folder whose children are `Season N` folders, or a flat folder with the episode files sitting directly inside, is detected and processed on its own:

```sh
php run.php --scan="/x/SciFi/Star City" --posters
```

### Fetch season posters too — `--seasons`

```sh
php run.php --scan=/x/SciFi --posters --seasons
```

After the root poster pass, goes one level deeper per show folder. Folders named `Season N` (or zero-padded `Season NN`) and `Specials` (TVDB's season 0) each get their season's poster, saved to `artwork/` as `<seriesId>-<nn>-<basename>.jpg` (e.g. `101501-01-6116a0eb8f514.jpg`) and copied into the season folder as `poster.<ext>`. Season folders that already have a poster, or seasons with no artwork on TVDB, are skipped.

### Movies — `--movie`

TheTVDB serves movies through the same flow: add `--movie` to a search, an id lookup, or a library scan of movie folders:

```sh
php run.php --title="Interstellar" --movie
php run.php --poster=131079 --movie
php run.php --scan=/x/Movies --posters --movie
```

Movie ids are plain numbers to the user — the API's `movie-` prefix is handled behind the scenes, though a pasted `movie-131079` from search results is accepted too — artwork comes from the movie's extended record, and movie folders are flat — video files sit directly inside — so the single-folder detection works the same way. `--seasons` does not apply to movies.

### Tidy up after downloading — `--clean`

```sh
php run.php --scan=/x/Movies --clean
```

A personal clean-up pass. Combined with `--posters` it runs after each successful poster download; on its own it tidies folders without downloading anything. It deletes `*.nfo`/`*.txt` files in the folder, strips scene release tags (listed as `RELEASE_TAGS=PSA,XYZ` in `.env` — bare names, the hyphen is added by the script) from video filename suffixes, saves a `<video name>-poster.jpg` copy of the poster (the freshly downloaded one, or the folder's existing poster; `<video name>` is the main root video — the largest one in movie mode), and renames the folder to `Title  (Year)` — the title closest to the searched name, so an English-named folder keeps the English title while a Japanese-named one stays Japanese — with a size tag (`DL+`/`DL`/`SL`/`SLite` by GiB size) and `.4k` for 2160p files — e.g. `The Runner  (2026).DL.4k`. A folder whose full title matches nothing on TVDB is left as it is: the same skip-and-suggest rule as `--posters` applies, so nothing is renamed on a guess.

## Output and naming

- Downloads are cached in `artwork/` (gitignored) — unless `CACHE_ARTWORK=false` is set in `.env`, in which case posters are written directly into the folders and nothing is cached:
  - series posters: `<seriesId>-<image basename>.jpg` — e.g. `449146-6a0e1f176a889.jpg`
  - season posters: `<seriesId>-<nn>-<image basename>.jpg` — e.g. `101501-01-6116a0eb8f514.jpg`
- Each folder prints a `Matched:` line with the title and id it matched on, then `Done   :` for the saved poster; `Skip   : <reason>` lines report per-folder problems, and the run continues with the next folder. With `--clean`, `Clean  :` lines report each tidy-up step — deleted text files, video renames, the saved `<video name>-poster` copy, the folder rename.
- Tables pad by display width, so CJK and other wide characters line up, and non-spacing marks — combining accents, Indic vowel signs, zero-width joiners — are given no cell at all. A terminal that measures *rendered* glyphs rather than characters can still draw a complex-script row a cell narrower than that, so an Indic title may sit one space short in those. Nothing is trimmed: we measure by the Unicode rules, the terminal is measuring its font. If your terminal is one of those, set `TABLE_MARKS_ADJUST=-1` in `.env` — cells containing such marks are then measured a cell narrower and padded a space wider, lining the borders back up. Only cells with marks are nudged, so the rows that were already correct stay correct.

## Credits

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="assets/thetvdb-logo-inverse.png">
  <img alt="TheTVDB" src="assets/thetvdb-logo.png" height="100">
</picture>

Metadata provided by [TheTVDB](https://thetvdb.com). Please consider adding missing information or [subscribing](https://thetvdb.com/subscribe).

<br>

<picture>
  <img alt="TMDB" src="assets/tmdb-logo.svg" width="500">
</picture>

This program uses TMDB and the TMDB APIs but is not endorsed, certified, or otherwise approved by TMDB. Artwork and metadata fetched from TMDB remain © TMDB — their API is free for non-commercial use with attribution. See [themoviedb.org](https://www.themoviedb.org).
