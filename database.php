
<?php

/*
|--------------------------------------------------------------------------
| MLFMS DATABASE CONFIGURATION
|--------------------------------------------------------------------------
| These credentials are for your local XAMPP development environment.
| Use a dedicated database user and password in production.
|--------------------------------------------------------------------------
*/

$DB_HOST = '127.0.0.1';
$DB_NAME = 'micro_logistics';
$DB_USER = 'root';
$DB_PASS = '';

/*
|--------------------------------------------------------------------------
| DATABASE CONNECTION
|--------------------------------------------------------------------------
*/

$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";

try {

    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [

        /*
        | Throw exceptions when database operations fail.
        */
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

        /*
        | Return database results as associative arrays.
        */
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

        /*
        | Use native MySQL prepared statements.
        */
        PDO::ATTR_EMULATE_PREPARES => false,

        /*
        | Preserve native database value types.
        */
        PDO::ATTR_STRINGIFY_FETCHES => false,

        /*
        | Do not reuse persistent connections.
        */
        PDO::ATTR_PERSISTENT => false,

        /*
        | Limit connection attempts to 5 seconds.
        */
        PDO::ATTR_TIMEOUT => 5,

    ]);

} catch (PDOException $e) {

    /*
    |--------------------------------------------------------------------------
    | PRIVATE ERROR LOGGING
    |--------------------------------------------------------------------------
    | Never expose database credentials or technical errors to users.
    |--------------------------------------------------------------------------
    */

    error_log(
        '[MLFMS DATABASE ERROR] ' . $e->getMessage()
    );

    http_response_code(500);

    exit(
        'Unable to connect to the database. Please contact the system administrator.'
    );

}
