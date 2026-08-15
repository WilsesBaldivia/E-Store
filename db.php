<?php
declare(strict_types=1);

/**
 * Return the shared PDO connection.
 *
 * XAMPP defaults are used only when environment variables are not set.
 * Set DB_HOST, DB_PORT, DB_NAME, DB_USER, and DB_PASSWORD in production.
 */
function db(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'e_storedb';
    $user = getenv('DB_USER') ?: 'root';
    $password = getenv('DB_PASSWORD');
    $password = $password === false ? '' : $password;

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $host,
        $port,
        $name
    );

    try {
        $connection = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $exception) {
        error_log('CSCQC database connection failed: ' . $exception->getMessage());
        throw new RuntimeException('The database connection is currently unavailable.');
    }

    return $connection;
}
