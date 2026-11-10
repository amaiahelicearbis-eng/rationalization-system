<?php
declare(strict_types=1);
$config = require __DIR__ . '/../config/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Manila');
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_start();
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

const PROCESS_LIST = [
    'cut' => ['label' => 'Cut & Crimp', 'icon' => '✂', 'hint' => 'CNC code master data'],
    'kitting' => ['label' => 'Kitting', 'icon' => '▦', 'hint' => 'Kitting CSV source'],
    'saki' => ['label' => 'Sub Assy (Saki)', 'icon' => '⚙', 'hint' => 'Saki CSV source'],
    'ato' => ['label' => 'Sub Assy (Ato)', 'icon' => '⚙', 'hint' => 'Ato CSV source'],
    'contact' => ['label' => 'Contact SW', 'icon' => '◉', 'hint' => 'Contact SW CSV source'],
    'mrsw' => ['label' => 'MR-SW', 'icon' => '◈', 'hint' => 'MR-SW CSV source'],
    'hotmelt' => ['label' => 'Hot Melt', 'icon' => '♨', 'hint' => 'Hot Melt CSV source'],
    'finished' => ['label' => 'Finished Goods', 'icon' => '▣', 'hint' => 'Finished Goods CSV source'],
    'assy' => ['label' => 'Assembly', 'icon' => '▤', 'hint' => 'Assembly CSV source'],
];
function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf'] ?? '').'">'; }
function verify_csrf(): void {
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(419); exit('Session token expired. Refresh the page and try again.');
    }
}
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    global $config;
    $d = $config['db'];
    $dsn = sprintf('sqlsrv:Server=%s;Database=%s;TrustServerCertificate=1', $d['host'], $d['database']);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    if (defined('PDO::SQLSRV_ATTR_ENCODING') && defined('PDO::SQLSRV_ENCODING_UTF8')) {
        $options[PDO::SQLSRV_ATTR_ENCODING] = PDO::SQLSRV_ENCODING_UTF8;
    }
    $pdo = new PDO($dsn, $d['username'], $d['password'], $options);
    return $pdo;
}
function current_user(): ?array { return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null; }
function require_login(): void { if (!current_user()) { header('Location: login.php'); exit; } }
function is_admin(): bool { return (current_user()['role'] ?? '') === 'admin'; }
function require_admin(): void { require_login(); if (!is_admin()) { http_response_code(403); exit('Administrator access required.'); } }
function audit_log(string $action, string $entityType, ?int $entityId, string $description, ?array $before = null, ?array $after = null): void {
    $u = current_user();
    $stmt = db()->prepare('INSERT INTO dbo.change_history (user_id, username, action, entity_type, entity_id, description, before_data, after_data, ip_address, created_at) VALUES (:uid,:username,:action,:etype,:eid,:description,:before_data,:after_data,:ip,SYSDATETIME())');
    $stmt->execute(['uid'=>$u['id'] ?? null, 'username'=>$u['username'] ?? 'system', 'action'=>$action, 'etype'=>$entityType, 'eid'=>$entityId, 'description'=>$description, 'before_data'=>$before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), 'after_data'=>$after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), 'ip'=>substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45)]);
}
function process_key(): string { $k=(string)($_GET['process'] ?? $_POST['process'] ?? 'cut'); return isset(PROCESS_LIST[$k]) ? $k : 'cut'; }
function process_record(string $key): ?array {
    $stmt=db()->prepare('SELECT TOP (1) id, process_key, process, link, updated_by, updated_at FROM dbo.process_csv_sources WHERE process_key=?');
    $stmt->execute([$key]); $r=$stmt->fetch(); return $r ?: null;
}
function process_records(): array {
    $rows=db()->query('SELECT id, process_key, process, link, updated_by, updated_at FROM dbo.process_csv_sources ORDER BY id')->fetchAll();
    $byKey=[]; foreach($rows as $r) $byKey[$r['process_key']]=$r; return $byKey;
}
/**
 * Synology credentials are deliberately kept outside the public web root.
 * Default private file: C:\xampp\private\production-data-nas.php
 */
$privateSynologyFile = (string)($config['synology_private_file'] ?? 'C:\\xampp\\private\\production-data-nas.php');
$GLOBALS['synology_private_file'] = $privateSynologyFile;
$GLOBALS['synology_config'] = [];
if (is_file($privateSynologyFile)) {
    $loadedSynologyConfig = require $privateSynologyFile;
    if (is_array($loadedSynologyConfig)) $GLOBALS['synology_config'] = $loadedSynologyConfig;
}

function synology_config(): array {
    return is_array($GLOBALS['synology_config'] ?? null) ? $GLOBALS['synology_config'] : [];
}
function synology_config_file(): string {
    return (string)($GLOBALS['synology_private_file'] ?? 'C:\\xampp\\private\\production-data-nas.php');
}
function is_synology_path(string $path): bool {
    return str_starts_with(strtolower(trim($path)), 'synology:');
}
function synology_normalize_path(string $path): string {
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '' || preg_match('/[\x00-\x1F]/', $path)) throw new RuntimeException('Invalid NAS path.');
    $parts = [];
    foreach (explode('/', '/' . ltrim($path, '/')) as $part) {
        if ($part === '' ) continue;
        if ($part === '.' || $part === '..') throw new RuntimeException('Relative path segments are not allowed in NAS paths.');
        $parts[] = $part;
    }
    return '/' . implode('/', $parts);
}
function synology_nas_path(string $virtualPath): string {
    if (!is_synology_path($virtualPath)) throw new RuntimeException('This is not a Synology source path.');
    return synology_normalize_path(substr(trim($virtualPath), strlen('synology:')));
}
function synology_virtual_path(string $nasPath): string {
    return 'synology:' . synology_normalize_path($nasPath);
}
function shared_csv_root(): string {
    $nas = synology_config();
    $root = trim((string)($nas['root_path'] ?? '/BarcodeTagPrint/MASTER DATA'));
    return synology_virtual_path($root);
}
function synology_base_url(): string {
    $nas = synology_config();
    $url = rtrim(trim((string)($nas['base_url'] ?? 'http://192.168.20.11:5000')), '/');
    if (!preg_match('#^https?://#i', $url)) throw new RuntimeException('The Synology base_url must begin with http:// or https://.');
    return $url;
}
function synology_require_config(): array {
    $nas = synology_config();
    if (empty($nas['username']) || empty($nas['password'])) {
        throw new RuntimeException('Synology credentials are not configured. Create ' . synology_config_file() . ' using the provided example configuration.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is not enabled. Enable the curl extension in the XAMPP PHP configuration and restart Apache.');
    }
    return $nas;
}
function synology_http_request(array $params, int $timeout = 25): string {
    $nas = synology_require_config();
    $url = synology_base_url() . '/webapi/entry.cgi';
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('Unable to initialize the Synology API connection.');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => ['Accept: application/json, */*'],
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    // Verify TLS certificates by default. If the NAS uses HTTPS, install its trusted certificate on this PC.
    $verifyTls = (bool)($nas['verify_ssl'] ?? true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifyTls);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifyTls ? 2 : 0);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false) throw new RuntimeException('Unable to connect to Synology file server: ' . ($curlError !== '' ? $curlError : 'network error') . '.');
    if ($status < 200 || $status >= 300) throw new RuntimeException('Synology file server returned HTTP ' . $status . '.');
    return (string)$response;
}
function synology_login(bool $force = false): string {
    if (!$force && !empty($_SESSION['synology_sid']) && !empty($_SESSION['synology_sid_time']) && (time() - (int)$_SESSION['synology_sid_time']) < 3600) {
        return (string)$_SESSION['synology_sid'];
    }
    $nas = synology_require_config();
    unset($_SESSION['synology_sid'], $_SESSION['synology_sid_time']);
    $response = synology_http_request([
        'api' => 'SYNO.API.Auth',
        'version' => 7,
        'method' => 'login',
        'account' => (string)$nas['username'],
        'passwd' => (string)$nas['password'],
        'session' => 'FileStation',
        'format' => 'sid',
    ]);
    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['success']) || empty($data['data']['sid'])) {
        $code = (int)($data['error']['code'] ?? 0);
        throw new RuntimeException('Synology login failed (API error ' . $code . '). Check the private configuration, account status, and File Station access permissions.');
    }
    $_SESSION['synology_sid'] = (string)$data['data']['sid'];
    $_SESSION['synology_sid_time'] = time();
    return (string)$_SESSION['synology_sid'];
}
function synology_json_request(array $params, bool $retryExpiredSession = true): array {
    $params['_sid'] = synology_login();
    $response = synology_http_request($params);
    $data = json_decode($response, true);
    if (!is_array($data)) throw new RuntimeException('The Synology API returned an unreadable response.');
    if (!($data['success'] ?? false)) {
        $code = (int)($data['error']['code'] ?? 0);
        if ($retryExpiredSession && in_array($code, [106, 119], true)) {
            unset($_SESSION['synology_sid'], $_SESSION['synology_sid_time']);
            return synology_json_request($params, false);
        }
        $message = match ($code) {
            105, 407 => 'The Synology account does not have permission to read this folder or file.',
            106, 119 => 'The Synology session expired. Refresh the page and try again.',
            400 => 'Synology rejected the request. Check the NAS path and configuration.',
            408 => 'The NAS folder or file was not found. Check the configured root path.',
            default => 'Synology API request failed (error ' . $code . '). Check the account permissions and NAS path.',
        };
        throw new RuntimeException($message);
    }
    return $data;
}
function synology_list_folder(string $virtualDir): array {
    if (!is_synology_path($virtualDir) || !path_is_allowed($virtualDir)) throw new RuntimeException('That folder is outside the allowed MASTER DATA folder.');
    $nasPath = synology_nas_path($virtualDir);
    $response = synology_json_request([
        'api' => 'SYNO.FileStation.List',
        'version' => 2,
        'method' => 'list',
        'folder_path' => $nasPath,
        'sort_by' => 'name',
        'sort_direction' => 'asc',
        'filetype' => 'all',
        'additional' => json_encode(['size', 'time'], JSON_UNESCAPED_SLASHES),
    ]);
    $files = $response['data']['files'] ?? [];
    if (!is_array($files)) return [];
    $result = [];
    foreach ($files as $file) {
        if (!is_array($file) || empty($file['path']) || empty($file['name'])) continue;
        $virtual = synology_virtual_path((string)$file['path']);
        if (!path_is_allowed($virtual)) continue;
        $additional = is_array($file['additional'] ?? null) ? $file['additional'] : [];
        $time = is_array($additional['time'] ?? null) ? $additional['time'] : [];
        $result[] = [
            'name' => (string)$file['name'],
            'path' => $virtual,
            'type' => !empty($file['isdir']) ? 'folder' : 'file',
            'size' => isset($additional['size']) ? (int)$additional['size'] : 0,
            'modified' => (int)($time['mtime'] ?? time()),
        ];
    }
    return $result;
}
function synology_file_info(string $virtualFile): array {
    if (!is_synology_path($virtualFile) || !path_is_allowed($virtualFile)) throw new RuntimeException('This file is outside the allowed MASTER DATA folder.');
    $nasPath = synology_nas_path($virtualFile);
    $response = synology_json_request([
        'api' => 'SYNO.FileStation.List',
        'version' => 2,
        'method' => 'getinfo',
        'path' => json_encode([$nasPath], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'additional' => json_encode(['size', 'time'], JSON_UNESCAPED_SLASHES),
    ]);
    $file = $response['data']['files'][0] ?? null;
    if (!is_array($file)) throw new RuntimeException('The NAS did not return information for the selected CSV file.');
    $additional = is_array($file['additional'] ?? null) ? $file['additional'] : [];
    $time = is_array($additional['time'] ?? null) ? $additional['time'] : [];
    return [
        'name' => (string)($file['name'] ?? basename($nasPath)),
        'path' => (string)($file['path'] ?? $nasPath),
        'isdir' => (bool)($file['isdir'] ?? false),
        'size' => (int)($additional['size'] ?? 0),
        'modified' => (int)($time['mtime'] ?? 0),
    ];
}
function synology_download_file(string $virtualFile): string {
    if (!is_synology_path($virtualFile) || !path_is_allowed($virtualFile)) throw new RuntimeException('This file is outside the allowed MASTER DATA folder.');
    $nasPath = synology_nas_path($virtualFile);
    $response = synology_http_request([
        'api' => 'SYNO.FileStation.Download',
        'version' => 2,
        'method' => 'download',
        'path' => json_encode([$nasPath], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'mode' => 'open',
        '_sid' => synology_login(),
    ], 60);
    $maybeJson = json_decode($response, true);
    if (is_array($maybeJson) && array_key_exists('success', $maybeJson) && !$maybeJson['success']) {
        $code = (int)($maybeJson['error']['code'] ?? 0);
        if (in_array($code, [106, 119], true)) {
            unset($_SESSION['synology_sid'], $_SESSION['synology_sid_time']);
            // Retry once using a fresh session.
            $response = synology_http_request([
                'api' => 'SYNO.FileStation.Download', 'version' => 2, 'method' => 'download',
                'path' => json_encode([$nasPath], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'mode' => 'open', '_sid' => synology_login(true),
            ], 60);
        } else {
            throw new RuntimeException('Synology could not download the CSV (error ' . $code . '). Check the file path and account permissions.');
        }
    }
    return $response;
}
function path_is_allowed(string $path): bool {
    global $config;
    if (is_synology_path($path)) {
        try {
            $candidate = strtolower(rtrim(synology_nas_path($path), '/'));
            $root = strtolower(rtrim(synology_nas_path(shared_csv_root()), '/'));
            return $candidate === $root || str_starts_with($candidate, $root . '/');
        } catch (Throwable $e) { return false; }
    }
    $candidate = realpath($path);
    if ($candidate === false) return false;
    foreach (($config['allowed_csv_roots'] ?? []) as $configuredRoot) {
        $root = realpath((string)$configuredRoot);
        if ($root === false) continue;
        $rootNorm = strtolower(rtrim(str_replace('/', DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR));
        $candidateNorm = strtolower(rtrim(str_replace('/', DIRECTORY_SEPARATOR, $candidate), DIRECTORY_SEPARATOR));
        if ($candidateNorm === $rootNorm || str_starts_with($candidateNorm, $rootNorm . DIRECTORY_SEPARATOR)) return true;
    }
    return false;
}
function validate_csv_path(string $path): string {
    $path = trim($path);
    if ($path === '') throw new RuntimeException('Select a CSV file first.');
    if (preg_match('/[\x00-\x1F]/', $path)) throw new RuntimeException('The file path contains invalid characters.');
    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'csv') throw new RuntimeException('The selected file must have a .csv extension.');
    if (is_synology_path($path)) {
        $virtual = synology_virtual_path(synology_nas_path($path));
        if (!path_is_allowed($virtual)) throw new RuntimeException('This file is outside the allowed MASTER DATA folder.');
        $info = synology_file_info($virtual);
        if (!empty($info['isdir'])) throw new RuntimeException('Select a CSV file, not a folder.');
        return $virtual;
    }
    $real = realpath($path);
    if ($real === false || !is_file($real)) throw new RuntimeException('CSV file not found. Select the file again from the browser.');
    if (!is_readable($real)) throw new RuntimeException('PHP cannot read this file.');
    if (!path_is_allowed($real)) throw new RuntimeException('This file is outside the allowed CSV folders.');
    return $real;
}
function csv_source_metadata(string $path): array {
    $path = trim($path);
    if ($path === '') return ['exists' => false, 'mtime' => 0, 'size' => 0];
    if (is_synology_path($path)) {
        $info = synology_file_info($path);
        $exists = empty($info['isdir']);
        return ['exists' => $exists, 'mtime' => (int)$info['modified'], 'size' => (int)$info['size']];
    }
    clearstatcache(true, $path);
    $exists = is_file($path) && is_readable($path) && path_is_allowed($path);
    return ['exists' => $exists, 'mtime' => $exists ? (int)(filemtime($path) ?: 0) : 0, 'size' => $exists ? (int)(filesize($path) ?: 0) : 0];
}
function csv_path_basename(string $path): string {
    if (is_synology_path($path)) return basename(synology_nas_path($path));
    return basename(str_replace('\\', '/', $path));
}
function display_csv_path(string $path): string {
    if (is_synology_path($path)) return rtrim(synology_base_url(), '/') . synology_nas_path($path);
    return ltrim(str_replace('\\', '/', $path), '/');
}
function display_shared_path(string $path): string {
    if (is_synology_path($path)) return display_csv_path($path);
    return ltrim(str_replace('\\', '/', $path), '/');
}
function decode_csv_contents(string $raw): string {
    if (str_starts_with($raw, "\xEF\xBB\xBF")) return substr($raw, 3);
    if (function_exists('mb_check_encoding') && mb_check_encoding($raw, 'UTF-8')) return $raw;
    if (function_exists('iconv')) {
        $converted = @iconv('CP932', 'UTF-8//IGNORE', $raw);
        if ($converted !== false && $converted !== '') return $converted;
        $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $raw);
        if ($converted !== false) return $converted;
    }
    return $raw;
}
function read_csv_file(string $path, bool $includeRows = true): array {
    $real = validate_csv_path($path);
    $raw = is_synology_path($real) ? synology_download_file($real) : @file_get_contents($real);
    if ($raw === false) throw new RuntimeException('Unable to read the CSV file.');
    if ($raw === '') throw new RuntimeException('The CSV file is empty.');
    $stream = fopen('php://temp', 'r+');
    if ($stream === false) throw new RuntimeException('Unable to parse CSV.');
    fwrite($stream, decode_csv_contents($raw));
    rewind($stream);
    $first = fgetcsv($stream, 0, ',', '"', '');
    if (!is_array($first) || count($first) === 0) { fclose($stream); throw new RuntimeException('The CSV has no header row.'); }
    $headers = array_map(static function ($value, $index) {
        $name = trim((string)$value);
        if ($index === 0) $name = preg_replace('/^\xEF\xBB\xBF/', '', $name) ?? $name;
        return $name !== '' ? $name : 'Column ' . ($index + 1);
    }, $first, array_keys($first));
    $rows = []; $maxCols = count($headers); $count = 0;
    while (($line = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        if ($line === [null] || (count($line) === 1 && trim((string)$line[0]) === '')) continue;
        $count++; $maxCols = max($maxCols, count($line));
        if ($includeRows) $rows[] = $line;
    }
    fclose($stream);
    for ($i = count($headers); $i < $maxCols; $i++) $headers[] = 'Column ' . ($i + 1);
    if ($includeRows) foreach ($rows as &$row) $row = array_pad(array_slice($row, 0, count($headers)), count($headers), '');
    unset($row);
    if (is_synology_path($real)) {
        $info = synology_file_info($real);
        $bytes = (int)$info['size'];
        $modified = (int)$info['modified'];
    } else {
        $bytes = (int)(filesize($real) ?: strlen($raw));
        $modified = (int)(filemtime($real) ?: time());
    }
    return ['headers' => $headers, 'rows' => $rows, 'row_count' => $count, 'path' => $real, 'bytes' => $bytes ?: strlen($raw), 'modified' => $modified ?: time()];
}
function save_process_path(string $key, string $path): void {
    if (!isset(PROCESS_LIST[$key])) throw new RuntimeException('Unknown process.');
    $validated = validate_csv_path($path);
    read_csv_file($validated, false);
    $before = process_record($key);
    $stmt = db()->prepare('UPDATE dbo.process_csv_sources SET link=:link, updated_by=:username, updated_at=SYSDATETIME() WHERE process_key=:key');
    $stmt->execute(['link' => $validated, 'username' => current_user()['username'] ?? 'unknown', 'key' => $key]);
    if ($stmt->rowCount() === 0 && !$before) throw new RuntimeException('Process configuration is missing. Run setup.php again.');
    $after = process_record($key);
    audit_log($before && $before['link'] ? 'UPDATE' : 'CREATE', 'process_csv_source', (int)($after['id'] ?? 0), 'CSV source link changed for ' . (PROCESS_LIST[$key]['label'] ?? $key), $before, $after);
}
