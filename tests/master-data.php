<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/bootstrap.php';

if (in_array('--live', $argv, true)) {
    foreach (array_keys(PROCESS_LIST) as $process) {
        $data = read_process_master_data($process);
        echo json_encode(['process'=>$process, 'files'=>count($data['paths']), 'unique_rows'=>$data['row_count'], 'duplicates_removed'=>$data['duplicates_removed']], JSON_UNESCAPED_SLASHES), "\n";
    }
    exit;
}

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$file = static fn($headers, $rows) => ['headers'=>$headers, 'rows'=>$rows, 'bytes'=>100, 'modified'=>1];
$merged = merge_master_data([
    $file(['ModelName','Branch','BarCode','PartName','WireType'], [
        ['A','1','BC1','Engineering','WIRE'],
        ['A','2','BC2','Distinct branch','WIRE'],
    ]),
    $file(['Barcode','PartName','ModelName','Branch','FGCode'], [
        ['BC1','Standard','A','1','FG1'],
        ['BC1','Standard','A','1','FG1'],
    ]),
]);
check($merged['row_count'] === 2, 'Duplicate identities must collapse while distinct branches remain.');
check($merged['duplicates_removed'] === 2, 'Duplicates within and across files must be counted.');
check($merged['rows'][0][3] === 'Standard', 'The later standard source must win.');
check($merged['rows'][0][4] === '' && $merged['rows'][0][5] === 'FG1', 'Columns must align across different source schemas.');
check($merged['rows'][1][4] === 'WIRE', 'Source-specific columns must survive for distinct records.');
$fallback = merge_master_data([$file(['PartName','ModelName'], [['One','A'],['Two','A'],['One','A']])]);
check($fallback['row_count'] === 2, 'Without branch/barcode, only equal rows may collapse.');
$case = merge_master_data([$file(['ModelName','Branch','BarCode'], [[' A ','1','bc1'],['a','1','BC1']])]);
check($case['row_count'] === 1, 'Identity comparisons must ignore case and surrounding spaces.');
$columns = merge_master_data([$file(['KitCode','KITCODE'], [['a','b']]), $file(['KITCODE','KitCode'], [['c','d']])]);
check(count($columns['headers']) === 2 && $columns['rows'][1] === ['c','d'], 'Repeated normalized headers must not drop columns.');
check(master_data_matches('OF_CNC CODE MASTER DATA -v2.csv', 'OF_CNC CODE MASTER DATA*.csv'), 'CSV patterns must match versions.');
check(!master_data_matches('copy.csv', 'OF_CNC CODE MASTER DATA*.csv'), 'Unrelated CSV files must be excluded.');

$testRoot = sys_get_temp_dir() . '/rationalization-master-data-' . bin2hex(random_bytes(6));
$folder = $testRoot . '/folder [ONE FILE]';
mkdir($testRoot);
mkdir($folder);
mkdir($folder . '/Backup');
$first = $folder . '/master-old.csv';
$second = $folder . '/master-new.csv';
$backup = $folder . '/Backup/master-backup.csv';
try {
    file_put_contents($first, "ModelName,Branch,Barcode\nA,1,BC1\n");
    file_put_contents($second, "ModelName,Branch,Barcode\nB,1,BC2\n");
    file_put_contents($backup, "ModelName,Branch,Barcode\nBACKUP,1,BC3\n");
    touch($first, time() - 120);
    $config['allowed_csv_roots'][] = $testRoot;
    $masterDataConfig = ['root'=>$testRoot, 'processes'=>['cut'=>[['folder'=>'folder [ONE FILE]', 'pattern'=>'master-*.csv']]]];
    $paths = master_data_sources('cut');
    check($paths === [$second], 'Use the newest top-level CSV and treat brackets literally.');
    $before = master_data_metadata($paths);
    file_put_contents($second, "ModelName,Branch,Barcode\nCHANGED,1,BC2\n");
    $after = master_data_metadata($paths);
    check($before['signature'] !== $after['signature'], 'Source changes must change the live-refresh signature.');
    unlink($second);
    check(master_data_sources('cut') === [$first], 'Discover the remaining source after a file is removed.');
    unlink($first);
    $failed = false;
    try { master_data_sources('cut'); } catch (RuntimeException $e) { $failed = true; }
    check($failed, 'Missing required sources must fail visibly instead of loading partial data.');
} finally {
    foreach ([$first,$second,$backup] as $path) if (is_file($path)) unlink($path);
    rmdir($folder . '/Backup');
    rmdir($folder);
    rmdir($testRoot);
}
echo "Master data checks passed.\n";
