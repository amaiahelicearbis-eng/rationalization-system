<?php
declare(strict_types=1); require __DIR__.'/includes/bootstrap.php'; require_login();
$key=process_key();$proc=PROCESS_LIST[$key];$notice='';$error='';
try { $record=process_record($key); if(!$record) throw new RuntimeException('This process has not been initialized. Run setup.php.'); }
catch(Throwable $e){$record=null;$error='Database error: '.$e->getMessage();}
// Lightweight endpoint used by the browser to detect changes to the shared file.
if (isset($_GET['check_source'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $checkRecord = process_record($key);
        $checkPath = trim((string)($checkRecord['link'] ?? ''));
        echo json_encode(csv_source_metadata($checkPath), JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode(['exists'=>false]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save_path') {
        try {
            save_process_path($key, (string)($_POST['csv_path'] ?? ''));
            $record = process_record($key);
            $notice = 'Shared CSV source saved for ' . $proc['label'] . '.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
try{$record=process_record($key);$path=trim((string)($record['link']??''));}catch(Throwable $e){$path='';$error=$error?:'Unable to read the process source from the database.';}
$search=trim((string)($_GET['q']??''));$page=max(1,(int)($_GET['page']??1));$perPage=50;$headers=[];$filteredRows=[];$allRows=[];$meta=null;$sourceState=null;
$sourceMoved = false;
if ($path !== '') {
    try {
        $sourceState = csv_source_metadata($path);
        $sourceMoved = empty($sourceState['exists']);
    } catch (Throwable $e) {
        $sourceMoved = true;
        $error = $e->getMessage();
    }
}
if ($sourceMoved) {
    if ($error === '') $error = 'File was moved or is unavailable. Select the CSV again from the NAS browser.';
} elseif ($path !== '') {
    try {
        $meta=read_csv_file($path);
        $headers=$meta['headers'];
        $allRows=$meta['rows'];
        if($search!==''){
            $needle=function_exists('mb_strtolower')?mb_strtolower($search,'UTF-8'):strtolower($search);
            foreach($allRows as $row){
                $hay=implode(' ',array_map('strval',$row));
                $hay=function_exists('mb_strtolower')?mb_strtolower($hay,'UTF-8'):strtolower($hay);
                if(str_contains($hay,$needle))$filteredRows[]=$row;
            }
        } else $filteredRows=$allRows;
    } catch (Throwable $e) {
        $error = 'The CSV could not be read from Synology File Station. ' . $e->getMessage();
    }
}
$totalRows=count($filteredRows);$totalPages=max(1,(int)ceil($totalRows/$perPage));if($page>$totalPages)$page=$totalPages;$visibleRows=array_slice($filteredRows,($page-1)*$perPage,$perPage);
function query_link(string $key,int $page,string $search):string{return '?process='.rawurlencode($key).'&page='.$page.'&q='.rawurlencode($search);}
$sourceName=$path!==''?csv_path_basename($path):'No CSV selected';
$displayPath=$path!==''?display_csv_path($path):'';
$isLiveServerSource=($path!=='' && !$sourceMoved && is_array($sourceState) && !empty($sourceState['exists']));
$allSources=[];try{$allSources=process_records();}catch(Throwable $e){}
?><?php $pageTitle=PROCESS_LIST[process_key()]['label']; $activePage='modeldata'; $currentProcess=process_key(); require __DIR__.'/includes/header.php'; ?><section class="content"><div class="page-intro"><div><h2>Process data explorer</h2></div><span class="read-only-pill"><span class="status-dot"></span> <?= $isLiveServerSource ? 'Live shared CSV · checks every second' : 'Shared CSV source' ?></span></div>
<?php if($notice):?><div class="notice notice-success"><strong>Saved</strong><span><?=h($notice)?></span></div><?php endif;?><?php if($error):?><div class="notice notice-error"><strong>Could not load data</strong><span><?=h($error)?></span></div><?php endif;?>
<section class="panel source-panel"><div class="panel-head"><div><h3>Shared CSV source</h3><p>Select the original CSV from <b>192.168.20.11/BarcodeTagPrint/MASTER DATA</b>. Changes saved to the original NAS file are reflected automatically.</p></div><span class="source-chip"><?=h($proc['label'])?></span></div><div class="upload-actions" style="padding:0 20px 18px;margin-top:0"><small>Only CSV files inside the NAS MASTER DATA folder can be selected.</small><a class="btn btn-primary" href="browse.php?process=<?=h($key)?>">Browse shared folder</a></div><?php if($sourceMoved):?><div class="notice notice-error" style="margin:0 20px 18px"><strong>File unavailable</strong><span>File was moved or is unavailable. Select a file again.</span><a class="btn btn-secondary" href="browse.php?process=<?=h($key)?>">Select a file</a></div><?php endif;?><div class="current-source-box"><div><b>Current source</b><div class="selected-path" title="<?=h($record['link']??'')?>"><?= $displayPath!=='' ? h($displayPath) : 'No file selected yet' ?></div></div><div class="source-meta"><span>Last updated by: <b><?=h($record['updated_by']?:'Not yet configured')?></b></span><span>Updated: <b><?=h($record['updated_at']?:'—')?></b></span><span>Source record ID: <b><?=h($record['id']??'—')?></b></span></div></div></section>
<div class="stats-grid stats-grid-csv"><article class="stat-card"><div class="stat-top"><span>Rows in file</span><span class="stat-icon blue">▤</span></div><div class="stat-value"><?=number_format($meta?(int)$meta['row_count']:0)?></div><div class="stat-foot">Data rows, excluding the header</div></article><article class="stat-card"><div class="stat-top"><span>Dynamic columns</span><span class="stat-icon purple">▥</span></div><div class="stat-value"><?=number_format(count($headers))?></div><div class="stat-foot">Read from CSV header row</div></article><article class="stat-card"><div class="stat-top"><span>File size</span><span class="stat-icon green">⇩</span></div><div class="stat-value"><?=$meta?h(number_format($meta['bytes']/1024,1).' KB'):'—'?></div><div class="stat-foot">Current source file</div></article><article class="stat-card"><div class="stat-top"><span>Last modified</span><span class="stat-icon amber">◷</span></div><div class="stat-value stat-date"><?=$meta?h(date('M d, Y',$meta['modified'])):'—'?></div><div class="stat-foot"><?=$meta?h(date('h:i:s A',$meta['modified'])):'No source loaded'?></div></article></div>
<section class="panel data-panel"><div class="panel-head"><div><h3>CSV records</h3><p class="file-subtitle"><?=h($sourceName)?> · source data is read-only</p></div><span class="record-count"><?=number_format($totalRows)?> matching rows</span></div><form class="filters csv-filters" method="get"><input type="hidden" name="process" value="<?=h($key)?>"><label class="field search-field"><span>Search all columns</span><div class="search-wrap"><span>⌕</span><input name="q" value="<?=h($search)?>" placeholder="Search any value in this CSV…"></div></label><button class="btn btn-secondary" type="submit">Search</button><a class="btn btn-ghost" href="?process=<?=h($key)?>">Clear</a><button class="btn btn-ghost" type="button" id="refreshBtn">↻ Refresh file</button></form><div class="table-wrap csv-table-wrap"><table class="csv-table"><thead><tr><th class="row-number-head">#</th><?php foreach($headers as $header):?><th title="<?=h($header)?>"><?=h($header)?></th><?php endforeach;?></tr></thead><tbody><?php if(!$path||!$meta):?><tr><td colspan="<?=max(1,count($headers)+1)?>" class="empty-state"><div class="empty-icon">▤</div><strong>No CSV file loaded</strong><p>Click Browse shared folder and select a CSV from the NAS MASTER DATA folder.</p></td></tr><?php elseif(!$visibleRows):?><tr><td colspan="<?=count($headers)+1?>" class="empty-state"><div class="empty-icon">⌕</div><strong>No matching rows</strong><p>Change the search text or clear the search.</p></td></tr><?php else:foreach($visibleRows as $idx=>$row):?><tr><td class="row-number"><?=number_format(($page-1)*$perPage+$idx+1)?></td><?php foreach($headers as $col=>$header):$cell=(string)($row[$col]??'');?><td title="<?=h($cell)?>"><?=$cell===''?'<span class="empty-cell">—</span>':h($cell)?></td><?php endforeach;?></tr><?php endforeach;endif;?></tbody></table></div><div class="table-footer csv-footer"><span>Showing <b><?=number_format($totalRows?(($page-1)*$perPage+1):0)?>–<?=number_format(min($page*$perPage,$totalRows))?></b> of <b><?=number_format($totalRows)?></b> matching rows</span><div class="pagination"><?php if($page>1):?><a class="btn btn-secondary" href="<?=h(query_link($key,$page-1,$search))?>">← Previous</a><?php endif;?><span>Page <b><?=$page?></b> of <b><?=$totalPages?></b></span><?php if($page<$totalPages):?><a class="btn btn-secondary" href="<?=h(query_link($key,$page+1,$search))?>">Next →</a><?php endif;?></div></div></section><div class="footnote"><span>Production Data Explorer</span><span>SQL Server settings · User audit trail · Dynamic CSV</span></div></section><script src="assets/csv.js"></script><script>
const liveSource = <?= $isLiveServerSource ? 'true' : 'false' ?>;
const sourceAlreadyUnavailable = <?= $sourceMoved ? 'true' : 'false' ?>;
const sourceProcess = <?= json_encode($key, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
const sourceUrl = new URL(window.location.href);
sourceUrl.searchParams.set('check_source', '1');
sourceUrl.searchParams.set('process', sourceProcess);
let lastSignature = <?= json_encode($meta ? ((int)$meta['modified'] . ':' . (int)$meta['bytes']) : '') ?>;
let refreshPending = false;
function refreshWhenSafe() {
  const active = document.activeElement;
  const editing = active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.tagName === 'SELECT');
  if (!editing && !document.querySelector('form:focus-within')) window.location.reload();
  else refreshPending = true;
}
document.addEventListener('focusout', () => {
  if (refreshPending) setTimeout(() => { if (refreshPending) refreshWhenSafe(); }, 150);
});
if (liveSource || <?= $path !== '' ? 'true' : 'false' ?>) {
  const pollSource = async () => {
    try {
      const response = await fetch(sourceUrl.toString(), {cache: 'no-store', headers: {'Accept': 'application/json'}});
      if (!response.ok) return;
      const state = await response.json();
      if (!state.exists) { if (!sourceAlreadyUnavailable) refreshWhenSafe(); return; }
      const signature = `${state.mtime}:${state.size}`;
      if (lastSignature !== '' && signature !== lastSignature) refreshWhenSafe();
      lastSignature = signature;
    } catch (_) { /* Retry on the next poll; the page remains usable. */ }
  };
  setInterval(pollSource, 1000);
}
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
