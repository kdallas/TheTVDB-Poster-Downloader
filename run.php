#!/usr/bin/php
<?php

/*
USAGE EXAMPLES:
    Search:   php run.php --title="Star City"
    Poster:   php run.php --poster=449146
    Scan:     php run.php --scan=/path/to/dir
    Posters:  php run.php --scan=/path/to/dir --posters
    Seasons:  php run.php --scan=/path/to/dir --posters --seasons
    Movie:    php run.php --title="Interstellar" --movie
    Movie:    php run.php --poster=131079 --movie
    Movie:    php run.php --scan=/x/Movies --posters --movie
    Clean:    php run.php --scan=/x/Movies --posters --clean
    Clean:    php run.php --scan=/x/Movies/Interstellar --clean
    TMDB:     php run.php --title="My Movie" --tmdb

NOTE: Login happens automatically (first run, or when the stored token is
within 1 day of expiry) — there is deliberately no manual login command.

TMDB: titles with no match on TheTVDB fall back to a TMDB lookup (needs
TMDB_API_KEY in .env); --title=... --tmdb searches TMDB directly. This
program uses TMDB and the TMDB APIs but is not endorsed, certified, or
otherwise approved by TMDB.
*/

spl_autoload_register(function ($class_name) {
    // Classes live in classes/ next to this file — resolving against
    // __DIR__ means run.php works from any directory, not just the
    // project dir.
    include __DIR__ . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . $class_name . '.php';
});

$app = new PosterCli($argv);
$app->run();
