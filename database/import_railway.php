<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

[$script, $host, $port, $database] = array_pad($argv, 4, null);
$user = 'root';
$password = getenv('RAILWAY_DB_PASSWORD');

if (!$host || !$port || !$database || $password === false || $password === '') {
    fwrite(STDERR, "Missing connection information. Run import-railway.ps1 instead.\n");
    exit(1);
}

$sqlFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'e_storedb.sql';
$sql = file_get_contents($sqlFile);
if ($sql === false) {
    fwrite(STDERR, "Could not read {$sqlFile}.\n");
    exit(1);
}

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
            PDO::ATTR_TIMEOUT => 20,
        ]
    );

    echo "Connected. Importing e_storedb.sql...\n";
    $pdo->exec($sql);
    $tableCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
    )->fetchColumn();
    echo "Import complete. {$tableCount} tables are available in {$database}.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Import failed: {$exception->getMessage()}\n");
    exit(1);
}
