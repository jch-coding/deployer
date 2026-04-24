<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PHPUnit bootstrap
|--------------------------------------------------------------------------
|
| Runs after phpunit.xml <env> values are applied. Chooses DB_HOST so tests
| hit PostgreSQL: the Sail service name from inside containers, or the
| forwarded Postgres port on the host (127.0.0.1).
|
*/

require __DIR__.'/../vendor/autoload.php';

$forced = getenv('TEST_DB_HOST');
if (is_string($forced) && $forced !== '') {
    $dbHost = $forced;
} else {
    $sail = filter_var($_SERVER['LARAVEL_SAIL'] ?? $_ENV['LARAVEL_SAIL'] ?? false, FILTER_VALIDATE_BOOL);
    $dbHost = $sail ? 'pgsql' : '127.0.0.1';
}

putenv('DB_HOST='.$dbHost);
$_ENV['DB_HOST'] = $dbHost;
$_SERVER['DB_HOST'] = $dbHost;
