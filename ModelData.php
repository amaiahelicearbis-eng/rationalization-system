<?php
declare(strict_types=1); require __DIR__.'/includes/bootstrap.php'; require_login();
$key=process_key();$proc=PROCESS_LIST[$key];$notice='';$error='';
if (isset($_GET['check_source'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        echo json_encode(master_data_metadata(master_data_sources($key)), JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode(['exists'=>false]);
    }
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if (automatic_master_data($key)) throw new RuntimeException('Master data sources are assigned automatically for this process.');
        if (($_POST['action'] ?? '') === 'save_path') save_process_path($key, (string)($_POST['csv_path'] ?? ''));
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$search=trim((string)($_GET['q']??''));$page=max(1,(int)($_GET['page']??1));$perPage=50;
$headers=[];$filteredRows=[];$allRows=[];$meta=null;$sourceState=null;$paths=[];$path='';$sourceMoved=false;
try {
    $paths=master_data_sources($key);
    $sourceState=master_data_metadata($paths);
    $meta=read_process_master_data($key, $paths);
    $path=implode('; ', $paths);
    $headers=$meta['headers'];
    $allRows=$meta['rows'];
    if ($search !== '') {
        $lower=static fn($v)=>function_exists('mb_strtolower')?mb_strtolower($v,'UTF-8'):strtolower($v);
        $needle=$lower($search);
        foreach ($allRows as $row) if (str_contains($lower(implode(' ', array_map('strval',$row))),$needle)) $filteredRows[]=$row;
    } else $filteredRows=$allRows;
} catch (Throwable $e) {
    $sourceMoved=true;
    $error=$e->getMessage();
}
$totalRows=count($filteredRows);$totalPages=max(1,(int)ceil($totalRows/$perPage));if($page>$totalPages)$page=$totalPages;$visibleRows=array_slice($filteredRows,($page-1)*$perPage,$perPage);
function query_link(string $key,int $page,string $search):string{return '?process='.rawurlencode($key).'&page='.$page.'&q='.rawurlencode($search);}
$sourceName=count($paths) . ' automatic master data files';
$isLiveServerSource=$meta !== null;
?><?php $pageTitle=PROCESS_LIST[process_key()]['label']; $activePage='modeldata'; $currentProcess=process_key(); require __DIR__.'/includes/header.php'; ?><section class="content"><div class="page-intro"><div><h2>Process data explorer</h2></div><span class="read-only-pill"><span class="status-dot"></span> <?= $isLiveServerSource ? 'Live master data - checks every 10 seconds' : 'Automatic master data' ?></span></div>
<?php if($notice):?><div class="notice notice-success"><strong>Saved</strong><span><?=h($notice)?></span></div><?php endif;?><?php if($error):?><div class="notice notice-error"><strong>Could not load data</strong><span><?=h($error)?></span></div><?php endif;?>
<div class="stats-grid stats-grid-csv"><article class="stat-card"><div class="stat-top"><span>Unique master records</span><span class="stat-icon blue">▤</span></div><div class="stat-value"><?=number_format($meta?(int)$meta['row_count']:0)?></div><div class="stat-foot">Duplicates removed across all sources</div></article><article class="stat-card"><div class="stat-top"><span>Dynamic columns</span><span class="stat-icon purple">▥</span></div><div class="stat-value"><?=number_format(count($headers))?></div><div class="stat-foot">Read from CSV header row</div></article><article class="stat-card"><div class="stat-top"><span>File size</span><span class="stat-icon green">⇩</span></div><div class="stat-value"><?=$meta?h(number_format($meta['bytes']/1024,1).' KB'):'—'?></div><div class="stat-foot">Combined source files</div></article><article class="stat-card"><div class="stat-top"><span>Last modified</span><span class="stat-icon amber">◷</span></div><div class="stat-value stat-date"><?=$meta?h(date('M d, Y',$meta['modified'])):'—'?></div><div class="stat-foot"><?=$meta?h(date('h:i:s A',$meta['modified'])):'No source loaded'?></div></article></div>
<section class="panel data-panel"><div class="panel-head"><div><h3>Master data records</h3><p class="file-subtitle"><?=h($sourceName)?> · source data is read-only</p></div><span class="record-count"><?=number_format($totalRows)?> matching rows</span></div><form class="filters csv-filters" method="get"><input type="hidden" name="process" value="<?=h($key)?>"><label class="field search-field"><span>Search all columns</span><div class="search-wrap"><span>⌕</span><input name="q" value="<?=h($search)?>" placeholder="Search any value in this CSV…"></div></label><button class="btn btn-secondary" type="submit">Search</button><a class="btn btn-ghost" href="?process=<?=h($key)?>">Clear</a><button class="btn btn-ghost" type="button" id="refreshBtn">↻ Refresh file</button></form><div class="table-wrap csv-table-wrap"><table class="csv-table"><thead><tr><th class="row-number-head">#</th><?php foreach($headers as $header):?><th title="<?=h($header)?>"><?=h($header)?></th><?php endforeach;?></tr></thead><tbody><?php if(!$path||!$meta):?><tr><td colspan="<?=max(1,count($headers)+1)?>" class="empty-state"><div class="empty-icon">▤</div><strong>No CSV file loaded</strong><p>Master data loads automatically when the NAS share is accessible to the web server.</p></td></tr><?php elseif(!$visibleRows):?><tr><td colspan="<?=count($headers)+1?>" class="empty-state"><div class="empty-icon">⌕</div><strong>No matching rows</strong><p>Change the search text or clear the search.</p></td></tr><?php else:foreach($visibleRows as $idx=>$row):?><tr><td class="row-number"><?=number_format(($page-1)*$perPage+$idx+1)?></td><?php foreach($headers as $col=>$header):$cell=(string)($row[$col]??'');?><td title="<?=h($cell)?>"><?=$cell===''?'<span class="empty-cell">—</span>':h($cell)?></td><?php endforeach;?></tr><?php endforeach;endif;?></tbody></table></div><div class="table-footer csv-footer"><span>Showing <b><?=number_format($totalRows?(($page-1)*$perPage+1):0)?>–<?=number_format(min($page*$perPage,$totalRows))?></b> of <b><?=number_format($totalRows)?></b> matching rows</span><div class="pagination"><?php if($page>1):?><a class="btn btn-secondary" href="<?=h(query_link($key,$page-1,$search))?>">← Previous</a><?php endif;?><span>Page <b><?=$page?></b> of <b><?=$totalPages?></b></span><?php if($page<$totalPages):?><a class="btn btn-secondary" href="<?=h(query_link($key,$page+1,$search))?>">Next →</a><?php endif;?></div></div></section><div class="footnote"><span>Production Data Explorer</span><span>SQL Server settings · User audit trail · Dynamic CSV</span></div></section><script src="assets/csv.js"></script><script>
const sourceAlreadyUnavailable = <?= $sourceMoved ? 'true' : 'false' ?>;
const sourceProcess = <?= json_encode($key, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
const sourceUrl = new URL(window.location.href);
sourceUrl.searchParams.set('check_source', '1');
sourceUrl.searchParams.set('process', sourceProcess);
let lastSignature = <?= json_encode($sourceState['signature'] ?? '') ?>;
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
{
  const pollSource = async () => {
    try {
      const response = await fetch(sourceUrl.toString(), {cache: 'no-store', headers: {'Accept': 'application/json'}});
      if (!response.ok) return;
      const state = await response.json();
      if (!state.exists) { if (!sourceAlreadyUnavailable) refreshWhenSafe(); return; }
      const signature = state.signature;
      if (sourceAlreadyUnavailable) { refreshWhenSafe(); return; }
      if (lastSignature !== '' && signature !== lastSignature) refreshWhenSafe();
      lastSignature = signature;
    } catch (_) { /* Retry on the next poll; the page remains usable. */ }
  };
  setInterval(pollSource, 10000);
}
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
