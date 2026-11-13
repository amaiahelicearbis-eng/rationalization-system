<?php

declare(strict_types=1);

return [
    'app_name' => 'Rationalization System',
    'timezone' => 'Asia/Manila',
    'db' => [
        'host' => '192.168.23.64',
        'database' => 'DB_DISP1',
        'username' => 'sa',
        'password' => 'Nbc12#',
    ],

    'allowed_csv_roots' => [
        'Y:\\MASTER DATA',
        __DIR__ . '/../sample_data',
        __DIR__ . '/../storage/uploads',
    ],
];
/*declare(strict_types=1);

$dbHost = 'localhost';//'192.168.20.230';
$dbName = 'rationalization-system';
$dbUser = 'sa';
$dbPass = 'Abc12#';

try {
    $pdo = new PDO(
        "sqlsrv:Server={$dbHost};Database={$dbName};TrustServerCertificate=1",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
        ]
    );
} catch (PDOException $exception) {
    http_response_code(500);
    exit('Database connection failed. Import database/schema.sql into SQL Server and check config/database.php.');
}*/