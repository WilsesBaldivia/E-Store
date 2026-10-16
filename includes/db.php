<?php
declare(strict_types=1);

function db(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    // Prefer the application variables used in Railway. The MYSQL* fallbacks
    // also let the app connect when Railway's MySQL variables are attached
    // directly to this service.
    $host = getenv('DB_HOST') ?: (getenv('MYSQLHOST') ?: '127.0.0.1');
    $port = getenv('DB_PORT') ?: (getenv('MYSQLPORT') ?: '3306');
    $name = getenv('DB_NAME') ?: (getenv('MYSQLDATABASE') ?: 'e_storedb');
    $user = getenv('DB_USER') ?: (getenv('MYSQLUSER') ?: 'root');
    $password = getenv('DB_PASSWORD');
    if ($password === false) {
        $password = getenv('MYSQLPASSWORD');
    }
    $password = $password === false ? '' : $password;

    try {
        $connection = new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 10,
            ]
        );
    } catch (PDOException $exception) {
        error_log('CSCQC database connection failed: ' . $exception->getMessage());
        throw new RuntimeException('The database is temporarily unavailable.');
    }

    return $connection;
}
