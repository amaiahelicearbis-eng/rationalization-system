<?php

declare(strict_types=1);

return [
    'app_name' => 'Rationalization System',
    'timezone' => 'Asia/Manila',
    'db' => [
        'host' => 'localhost',
        'database' => 'rationalization-system',
        'username' => 'sa',
        'password' => 'CHANGE_ME',
    ],
    // PHP/web-server process must have read permission for the shared folder.
    // Add the UNC path here if Apache cannot see the mapped Y: drive.
    'allowed_csv_roots' => [
        'Y:\\MASTER DATA',
        __DIR__ . '/../sample_data',
        __DIR__ . '/../storage/uploads',
        // '\\\\FILESERVER\\Shared\\MASTER DATA',
    ],
];
