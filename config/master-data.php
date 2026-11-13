<?php
declare(strict_types=1);

// Use the UNC share instead of O:, which may not exist for the Apache account.
// For File Station access, change root to 'synology:/BarcodeTagPrint/MASTER DATA'
// and supply the private Synology credentials described in bootstrap.php.
$cnc = ['folder' => '000_NEW 2022 CNC Code [ONE FILE] -', 'pattern' => 'OF_CNC CODE MASTER DATA*.csv'];
$sub = ['folder' => '001_NEW 2022 SUB-assy [ONE FILE]', 'pattern' => 'OF_SUB-ASSY MASTER DATA*.csv'];
$mirror = ['folder' => '001_NEW 2022 SUB-assy [ONE FILE]', 'pattern' => 'OF_DOOR-MIRROR MASTER DATA*.csv'];
$qc = ['folder' => '002_NEW 2022 QC Master Data [ONE FILE]', 'pattern' => 'OF_QC MASTER DATA*.csv'];
$engineeringCnc = ['folder' => '003_ENGINEERING MASTER DATA_ONLY', 'pattern' => 'OF_CNC CODE MASTER DATA*.csv'];
$engineeringSub = ['folder' => '003_ENGINEERING MASTER DATA_ONLY', 'pattern' => 'OF_SUB-ASSY MASTER DATA*.csv'];
$assemblySources = [$engineeringSub, $mirror, $sub];

return [
    'root' => '\\\\192.168.20.11\\BarcodeTagPrint\\MASTER DATA',
    // Later sources win when ModelName + Branch + Barcode are duplicated.
    // Only the newest matching CSV in each folder is used; no subfolders are scanned.
    'processes' => [
        'cut' => [$engineeringCnc, $cnc],
        'kitting' => [$engineeringCnc, $engineeringSub, $cnc, $mirror, $sub, $qc],
        'finished' => [$qc],
        'saki' => $assemblySources,
        'ato' => $assemblySources,
        'contact' => $assemblySources,
        'mrsw' => $assemblySources,
        'hotmelt' => $assemblySources,
        'assy' => $assemblySources,
    ],
];
