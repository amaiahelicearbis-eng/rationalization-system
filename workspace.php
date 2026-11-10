<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_login();
$view=(string)($_GET['view']??'production');
$allowed=['dashboard','monthly','production','csv','history','users'];
if(!in_array($view,$allowed,true))$view='production';
$key=(string)($_GET['process']??'cut');
if(!isset(PROCESS_LIST[$key]))$key='cut';
$date=(string)($_GET['date']??date('Y-m-d'));
$targets=[
 'dashboard'=>'dashboard.php?embed=1',
 'monthly'=>'monthly.php?embed=1',
 'production'=>'production.php?process='.rawurlencode($key).'&date='.rawurlencode($date).'&embed=1',
 'csv'=>'ModelData.php?process='.rawurlencode($key).'&embed=1',
 'history'=>'history.php?embed=1',
 'users'=>'users.php?embed=1'
];
if($view==='users'&&!is_admin())$view='production';
$target=$targets[$view];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Rationalization Production System</title><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/csv.css"><link rel="stylesheet" href="assets/admin.css"><style>
.workspace-frame{width:100%;height:calc(100vh - 78px);border:0;display:block;background:#e8e9e7}.workspace-shell{display:flex;min-height:100vh}.workspace-main{flex:1;min-width:0}.workspace-sidebar{width:214px;flex:0 0 214px}.workspace-sidebar .nav-item.current{background:rgba(15,40,38,.42);color:#fff;border-radius:9px}.workspace-sidebar .process-links{margin-top:8px}.workspace-top{height:78px;display:flex;align-items:center;gap:14px;padding:0 24px;background:#fff;border-bottom:1px solid #e5e8eb}.workspace-top h1{font-size:19px;margin:0}.workspace-top .eyebrow{font-size:10px;color:#75818a;letter-spacing:.08em}.workspace-top .signout{margin-left:auto}@media(max-width:760px){.workspace-sidebar{width:62px;flex-basis:62px;overflow:hidden}.workspace-sidebar .brand span:not(.brand-mark),.workspace-sidebar .nav-label,.workspace-sidebar .nav-item{font-size:0}.workspace-sidebar .nav-item{padding:13px 8px}.workspace-sidebar .brand{padding:12px 8px}.workspace-frame{height:calc(100vh - 68px)}}
</style></head><body><div class="workspace-shell"><aside class="sidebar workspace-sidebar" id="sidebar"><a class="brand" href="workspace.php?view=dashboard"><span class="brand-mark">R</span><span><b>RATIONALIZATION</b><small>PRODUCTION SYSTEM</small></span></a><div class="nav-label">WORKSPACE</div><a class="nav-item <?= $view==='dashboard'?'current':''?>" href="workspace.php?view=dashboard">▦ Dashboard</a><a class="nav-item <?= $view==='monthly'?'current':''?>" href="workspace.php?view=monthly">▤ Monthly Schedule</a><a class="nav-item <?= $view==='production'?'current':''?>" href="workspace.php?view=production&process=cut">⚙ Production Planning</a><a class="nav-item <?= $view==='csv'?'current':''?>" href="workspace.php?view=csv&process=cut">▧ Live CSV / Model Data</a><a class="nav-item <?= $view==='history'?'current':''?>" href="workspace.php?view=history">◷ Change History</a><?php if(is_admin()):?><a class="nav-item <?= $view==='users'?'current':''?>" href="workspace.php?view=users">♙ User Management</a><?php endif;?><?php if(in_array($view,['production','csv'],true)):?><div class="nav-label">PROCESSES</div><div class="process-links"><?php foreach(PROCESS_LIST as $k=>$proc):?><a class="nav-item <?= $k===$key?'current':''?>" href="workspace.php?view=<?= $view==='csv'?'csv':'production'?>&process=<?=h($k)?>"><?=h($proc['label'])?></a><?php endforeach;?></div><?php endif;?></aside><main class="workspace-main"><header class="workspace-top"><button class="icon-button" id="menuToggle" type="button">☰</button><div><div class="eyebrow">RATIONALIZATION PRODUCTION SYSTEM</div><h1><?=h(['dashboard'=>'Dashboard','monthly'=>'Monthly Schedule','production'=>'Production Planning','csv'=>'Live CSV / Model Data','history'=>'Change History','users'=>'User Management'][$view])?></h1></div><a class="btn btn-ghost btn-small signout" href="logout.php">Sign out</a></header><iframe class="workspace-frame" id="workspaceFrame" name="workspaceFrame" src="<?=h($target)?>" title="<?=h($view)?>"></iframe></main></div><script>
(function(){const frame=document.getElementById('workspaceFrame');function patch(){try{const d=frame.contentDocument;if(!d)return;d.querySelectorAll('a[href]').forEach(a=>{const u=new URL(a.href,location.href);if(u.origin===location.origin&&!u.pathname.endsWith('logout.php')&&!u.searchParams.has('embed')){u.searchParams.set('embed','1');a.href=u.pathname+u.search;}});d.querySelectorAll('form').forEach(f=>{if(!f.action)f.action=frame.contentWindow.location.href;});}catch(e){}}frame.addEventListener('load',patch);document.getElementById('menuToggle').addEventListener('click',()=>document.getElementById('sidebar').classList.toggle('open'));})();
</script></body></html>