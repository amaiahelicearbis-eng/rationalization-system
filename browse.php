<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_login();

$key = process_key();
$process = PROCESS_LIST[$key];
$root = shared_csv_root();
$requested = trim((string)($_GET['dir'] ?? ''));
$currentDir = $requested === '' ? $root : $requested;
$error = '';
$items = [];

if (!is_synology_path($currentDir) || !path_is_allowed($currentDir)) {
    $error = 'That folder is unavailable or outside the allowed MASTER DATA folder.';
    $currentDir = $root;
}
try {
    $entries = synology_list_folder($currentDir);
    foreach ($entries as $entry) {
        if (($entry['type'] ?? '') === 'folder') {
            $items[] = $entry;
        } elseif (($entry['type'] ?? '') === 'file' && strtolower(pathinfo((string)$entry['name'], PATHINFO_EXTENSION)) === 'csv') {
            $items[] = $entry;
        }
    }
    usort($items, static function (array $a, array $b): int {
        if ($a['type'] !== $b['type']) return $a['type'] === 'folder' ? -1 : 1;
        return strnatcasecmp($a['name'], $b['name']);
    });
} catch (Throwable $e) {
    $error = $e->getMessage();
    $currentDir = null;
}
function browse_url(string $key, string $dir): string {
    return 'browse.php?process=' . rawurlencode($key) . '&dir=' . rawurlencode($dir);
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Browse shared CSV · <?=h($process['label'])?></title><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/csv.css"><style>
.browser-wrap{max-width:1050px;margin:32px auto;padding:0 20px}.browser-head{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:20px}.browser-head h1{margin:4px 0 6px}.browser-card{background:#fff;border:1px solid #e4e9f1;border-radius:14px;overflow:hidden;box-shadow:0 8px 28px rgba(22,34,55,.06)}.browser-path{padding:15px 20px;background:#f6f8fc;border-bottom:1px solid #e6ebf2;overflow-wrap:anywhere;font-family:Consolas,monospace;font-size:12px;color:#344054}.browser-list{max-height:65vh;overflow:auto}.browser-item{display:flex;align-items:center;gap:13px;padding:13px 20px;border-bottom:1px solid #edf0f5}.browser-item:last-child{border-bottom:0}.browser-item:hover{background:#f7f9ff}.browser-icon{width:36px;height:36px;display:grid;place-items:center;background:#eef3ff;border-radius:9px;font-size:18px;flex-shrink:0}.browser-name{flex:1;min-width:0;overflow-wrap:anywhere}.browser-name b{display:block;color:#253047;font-size:13px}.browser-name small{color:#8b95a7;font-size:11px}.browser-item a{text-decoration:none}.browser-empty{padding:36px;text-align:center;color:#778399}.browser-warning{padding:13px 16px;margin-bottom:16px;border-radius:8px;background:#fff4e5;color:#875300;border:1px solid #ffe0ad;font-size:12px;line-height:1.6}.browser-footer{padding:15px 20px;border-top:1px solid #edf0f5;display:flex;justify-content:space-between;gap:10px;align-items:center}.browser-footer small{color:#8792a2}@media(max-width:650px){.browser-head{align-items:flex-start;flex-direction:column}.browser-item{padding:12px}.browser-footer{align-items:flex-start;flex-direction:column}}
</style><style id="embedded-page-mode">body.embedded-page .sidebar,body.embedded-page .topbar{display:none!important}body.embedded-page .app-shell{display:block!important;min-height:0!important}body.embedded-page .main{width:100%!important;margin:0!important;min-width:0!important}body.embedded-page .content{padding:18px!important;max-width:none!important}body.embedded-page{overflow-x:hidden!important}body.embedded-page .page-intro{margin-top:0!important}</style><script>if(new URLSearchParams(location.search).get('embed')==='1'){document.documentElement.classList.add('embedded-page');document.addEventListener('DOMContentLoaded',()=>document.body.classList.add('embedded-page'));}</script></head><body><div class="browser-wrap"><div class="browser-head"><div><div class="eyebrow">SHARED CSV SOURCE</div><h1>Choose a CSV file</h1><p style="margin:0;color:#788398">Process: <b><?=h($process['label'])?></b>. Browse only inside <b>MASTER DATA</b> and select the original CSV file.</p></div><a class="btn btn-secondary" href="ModelData.php?process=<?=h($key)?>">← Back to process</a></div>
<?php if($error):?><div class="browser-warning"><?=h($error)?></div><?php endif;?>
<div class="browser-card">
<?php if($currentDir===null):?><div class="browser-path">Shared folder: <?=h(display_shared_path(shared_csv_root()))?></div><div class="browser-empty"><div style="font-size:30px;margin-bottom:8px">📂</div><b>Shared folder not available</b><p>Check the private Synology configuration, NAS path, network connection, and File Station permissions.</p></div>
<?php else:?><div class="browser-path">📁 <?=h(display_shared_path($currentDir))?></div><div class="browser-list">
<?php $parentNas=dirname(synology_nas_path($currentDir)); $parent=synology_virtual_path($parentNas); $canGoParent=$currentDir!==$root && path_is_allowed($parent); if($canGoParent):?><a class="browser-item" href="<?=h(browse_url($key,$parent))?>"><span class="browser-icon">↰</span><span class="browser-name"><b>.. (Parent folder)</b><small>Go up one folder</small></span></a><?php endif;?>
<?php foreach($items as $item):if($item['type']==='folder'):?><a class="browser-item" href="<?=h(browse_url($key,$item['path']))?>"><span class="browser-icon">📁</span><span class="browser-name"><b><?=h($item['name'])?></b><small>Folder · Modified <?=h(date('Y-m-d H:i',$item['modified']))?></small></span><span>Open →</span></a><?php else:?><div class="browser-item"><span class="browser-icon">📄</span><span class="browser-name"><b><?=h($item['name'])?></b><small><?=h(number_format(($item['size']??0)/1024,1))?> KB · Modified <?=h(date('Y-m-d H:i',$item['modified']))?></small><small><?=h(display_shared_path($item['path']))?></small></span><form method="post" action="ModelData.php?process=<?=rawurlencode($key)?>"><?=csrf_field()?><input type="hidden" name="process" value="<?=h($key)?>"><input type="hidden" name="action" value="save_path"><input type="hidden" name="csv_path" value="<?=h($item['path'])?>"><button class="btn btn-primary" type="submit">Select file</button></form></div><?php endif;endforeach;?>
<?php if(!$items):?><div class="browser-empty"><div style="font-size:30px;margin-bottom:8px">📂</div><b>No CSV files or subfolders found</b><p>Only CSV files can be selected. Open a folder to browse its contents.</p></div><?php endif;?></div><div class="browser-footer"><small><?=count($items)?> item(s) · Only CSV files are selectable</small><?php if($currentDir!==$root):?><a class="btn btn-secondary" href="<?=h(browse_url($key,$root))?>">Back to MASTER DATA</a><?php endif;?></div><?php endif;?></div><p style="font-size:11px;color:#8a94a5;margin-top:14px">The original NAS CSV path is saved in SQL Server. The file is read directly through Synology File Station; it is not uploaded or copied.</p></div></body></html>
