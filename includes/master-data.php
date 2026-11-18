<?php
declare(strict_types=1);

$masterDataConfig = require __DIR__ . '/../config/master-data.php';
$config['allowed_csv_roots'][] = $masterDataConfig['root'];

function automatic_master_data(string $key): bool {
    global $masterDataConfig;
    return !empty($masterDataConfig['processes'][$key]);
}

function master_data_matches(string $name, string $pattern): bool {
    // Only * and ? are wildcards. Square brackets in NAS folder names are literal.
    $regex = str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($pattern, '~'));
    return (bool)preg_match('~^' . $regex . '$~i', $name);
}

function master_data_sources(string $key): array {
    global $masterDataConfig;
    if (!isset(PROCESS_LIST[$key])) throw new RuntimeException('Unknown process.');
    if (!automatic_master_data($key)) {
        $record = process_record($key);
        $path = trim((string)($record['link'] ?? ''));
        if ($path === '') throw new RuntimeException('No master data source is configured for this process.');
        return [$path];
    }
    $root = rtrim((string)$masterDataConfig['root'], '/\\');
    $sources = [];
    $folders = [];
    foreach ($masterDataConfig['processes'][$key] as $rule) {
        $folder = $root . '/' . $rule['folder'];
        if (!isset($folders[$folder])) {
            if (!path_is_allowed($folder)) {
                throw new RuntimeException('Master data folder is unavailable: ' . $folder . '. Give the Apache Windows account read access to the NAS share.');
            }
            if (is_synology_path($folder)) {
                $folders[$folder] = synology_list_folder($folder);
            } else {
                $names = @scandir($folder);
                if ($names === false) throw new RuntimeException('Cannot read master data folder: ' . $folder . '. Check NAS permissions for Apache.');
                $folders[$folder] = [];
                foreach ($names as $name) {
                    $path = $folder . '/' . $name;
                    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv' || !is_file($path)) continue;
                    $folders[$folder][] = ['name' => $name, 'path' => $path, 'type' => 'file', 'modified' => (int)filemtime($path)];
                }
            }
        }
        $matches = array_values(array_filter($folders[$folder], static fn($file) =>
            $file['type'] === 'file' && master_data_matches($file['name'], $rule['pattern'])));
        usort($matches, static fn($a, $b) => ($b['modified'] <=> $a['modified']) ?: strnatcasecmp($a['name'], $b['name']));
        if (!$matches) throw new RuntimeException('No matching master data CSV: ' . $folder . '/' . $rule['pattern']);
        $sources[] = $matches[0]['path'];
    }
    return array_values(array_unique($sources));
}

function master_data_metadata(array $paths): array {
    $states = [];
    $size = $mtime = 0;
    foreach ($paths as $path) {
        $state = csv_source_metadata($path);
        if (!$state['exists']) throw new RuntimeException('Master data CSV is unavailable: ' . $path);
        $states[] = [$path, $state['mtime'], $state['size']];
        $size += $state['size'];
        $mtime = max($mtime, $state['mtime']);
    }
    return ['exists' => (bool)$paths, 'mtime' => $mtime, 'size' => $size, 'signature' => hash('sha256', serialize($states))];
}

function master_data_header(string $name): string {
    return strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
}

function merge_master_data(array $files): array {
    $headers = $headerIndexes = $merged = [];
    $duplicates = $bytes = $modified = 0;
    foreach ($files as $file) {
        $occurrences = $mapping = $identityColumns = [];
        foreach ($file['headers'] as $index => $header) {
            $normalized = master_data_header($header);
            $occurrence = ($occurrences[$normalized] ?? 0) + 1;
            $occurrences[$normalized] = $occurrence;
            $columnKey = $normalized . ':' . $occurrence;
            if (!isset($headerIndexes[$columnKey])) {
                $headerIndexes[$columnKey] = count($headers);
                $headers[] = $header;
            }
            $mapping[$index] = $headerIndexes[$columnKey];
            if ($occurrence === 1 && in_array($normalized, ['modelname', 'branch', 'barcode'], true)) $identityColumns[$normalized] = $index;
        }
        foreach ($file['rows'] as $row) {
            $values = [];
            foreach ($mapping as $from => $to) $values[$to] = (string)($row[$from] ?? '');
            $identity = [];
            foreach (['modelname', 'branch', 'barcode'] as $name) {
                $value = isset($identityColumns[$name]) ? trim((string)($row[$identityColumns[$name]] ?? '')) : '';
                $identity[] = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
            }
            if ($identity[0] !== '' && ($identity[1] !== '' || $identity[2] !== '')) {
                $key = 'identity:' . json_encode($identity, JSON_INVALID_UTF8_SUBSTITUTE);
            } else {
                // Without a usable record identity, remove only equal complete rows.
                $canonical = [];
                foreach ($values as $index => $value) if (trim($value) !== '') $canonical[$headers[$index]] = trim($value);
                ksort($canonical);
                $key = 'row:' . serialize($canonical);
            }
            if (isset($merged[$key])) $duplicates++;
            $merged[$key] = $values;
        }
        $bytes += $file['bytes'];
        $modified = max($modified, $file['modified']);
    }
    $rows = [];
    foreach ($merged as $values) {
        $row = array_fill(0, count($headers), '');
        foreach ($values as $index => $value) $row[$index] = $value;
        $rows[] = $row;
    }
    return ['headers' => $headers, 'rows' => $rows, 'row_count' => count($rows), 'bytes' => $bytes, 'modified' => $modified, 'duplicates_removed' => $duplicates];
}

function read_process_master_data(string $key, ?array $paths = null): array {
    $paths ??= master_data_sources($key);
    $files = [];
    foreach ($paths as $path) $files[] = read_csv_file($path);
    $result = merge_master_data($files);
    $result['paths'] = $paths;
    return $result;
}
