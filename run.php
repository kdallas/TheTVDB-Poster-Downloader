#!/usr/bin/php
<?php

/*
USAGE EXAMPLES:
    Search:   php run.php --title="Star City"
    Posters:  php run.php --poster=449146
    Scan:     php run.php --scan=/path/to/dir
    Posters:  php run.php --scan=/path/to/dir --posters
    Seasons:  php run.php --scan=/path/to/dir --posters --seasons

NOTE: Login happens automatically (first run, or when the stored token is
within 1 day of expiry) — there is deliberately no manual login command.
*/

spl_autoload_register(function ($class_name) {
    include $class_name . '.php';
});

$app = new PosterCli($argv);
$app->run();
