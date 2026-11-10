<?php
$pageTitle = $pageTitle ?? 'Rationalization System';
$activePage = $activePage ?? basename($_SERVER['PHP_SELF'], '.php');
$currentProcess = $currentProcess ?? (function_exists('process_key') ? process_key() : 'cut');
$showProcesses = in_array($activePage, ['production','modeldata'], true);
?>

<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($config['app_name'] ?? 'Rationalization System')?> · <?=h($pageTitle)?></title>
<link rel="stylesheet" href="assets/style.css?v=<?=filemtime(__DIR__.'/../assets/style.css')?>">
<link rel="stylesheet" href="assets/csv.css?v=<?=filemtime(__DIR__.'/../assets/csv.css')?>">
<link rel="stylesheet" href="assets/admin.css?v=<?=filemtime(__DIR__.'/../assets/admin.css')?>">
<link rel="stylesheet" href="assets/layout-fix.css?v=<?=filemtime(__DIR__.'/../assets/layout-fix.css')?>">

<style>
.app-shell{min-height:calc(100vh - 44px)}
.sidebar .nav-label{display:block}
.sidebar .nav-item.active,.sidebar .nav-item.selected{background:rgba(35,75,68,.55);color:#fff;border-left:3px solid #c6d1b4}
.sidebar .workspace-links{margin-bottom:18px}
.sidebar .process-links{display:<?= $showProcesses ? 'block' : 'none' ?>}
.topbar{display:flex;align-items:center;gap:16px}
.topbar h1{margin:0}
.content{min-width:0}
@media(max-width:760px){.topbar .top-actions{margin-left:auto}}
</style>

</head><body>
<div class="app-shell"><aside class="sidebar" id="sidebar">
<a class="brand" href="dashboard.php"><span class="brand-mark">R</span><span><b>RATIONALIZATION</b><small>PRODUCTION SYSTEM</small></span></a>
<div class="nav-label">WORKSPACE</div><div class="workspace-links">
<a class="nav-item <?= $activePage==='modeldata'?'active':'' ?>" href="ModelData.php?process=<?=h($currentProcess)?>"><span>▧</span> MASTER DATA</a>
<a class="nav-item <?= $activePage==='production'?'active':'' ?>" href="production.php?process=<?=h($currentProcess)?>""><span>⚙</span> Production Planning</a>
<a class="nav-item <?= $activePage==='history'?'active':'' ?>" href="history.php"><span>◷</span> Change History</a>
<?php if(is_admin()): ?><a class="nav-item <?= $activePage==='users'?'active':'' ?>" href="users.php"><span>♙</span> User Management</a><?php endif; ?>
</div>
<div class="process-links"><div class="nav-label"><?= $activePage === 'modeldata' ? 'MODEL PROCESSES' : 'PLAN PROCESSES' ?></div>
<?php foreach(PROCESS_LIST as $k=>$p): ?>
<a class="nav-item <?= $activePage === ($activePage === 'modeldata' ? 'modeldata' : 'production') && $k === $currentProcess ? 'selected' : '' ?>" href="<?= $activePage === 'modeldata' ? 'ModelData.php' : 'production.php' ?>?process=<?=h($k)?>"><span><?=h($p['icon'])?></span><?=h($p['label'])?></a>
<?php endforeach; ?>
</div><div class="sidebar-bottom"><span class="status-dot"></span> <?=h(current_user()['full_name'] ?: current_user()['username'])?><small><?=h(current_user()['role'])?></small></div>
</aside><main class="main"><header class="topbar"><button class="icon-button mobile-menu" id="menuToggle" aria-label="Toggle menu">☰</button><div><div class="eyebrow">RATIONALIZATION / PRODUCTION SYSTEM</div><h1><?=h($pageTitle)?></h1></div><div class="top-actions"><span class="today-pill">● <?=h(date('D, d M Y'))?></span><a class="btn btn-ghost btn-small" href="logout.php">Sign out</a></div></header>
