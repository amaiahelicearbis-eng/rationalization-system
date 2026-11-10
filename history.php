<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_login();

$q = trim((string)($_GET['q'] ?? ''));
$action = trim((string)($_GET['action'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;

$where = ["action NOT IN ('LOGIN', 'LOGOUT')"];
$params = [];

if ($q !== '') {
    $where[] = '(username LIKE ? OR description LIKE ? OR entity_type LIKE ?)';
    array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
}

$allowedActions = ['CREATE', 'UPDATE', 'DELETE', 'DEACTIVATE', 'ACTIVATE'];
if ($action !== '' && in_array($action, $allowedActions, true)) {
    $where[] = 'action = ?';
    $params[] = $action;
}

$sql = ' FROM dbo.change_history WHERE ' . implode(' AND ', $where);

$st = db()->prepare('SELECT COUNT(*)' . $sql);
$st->execute($params);
$total = (int)$st->fetchColumn();

$pages = max(1, (int)ceil($total / $limit));
$page = min($page, $pages);

$offset = ($page - 1) * $limit;
$st = db()->prepare(
    'SELECT created_at, username, action, entity_type, entity_id, description
     ' . $sql . '
     ORDER BY id DESC
     OFFSET ' . $offset . ' ROWS FETCH NEXT ' . $limit . ' ROWS ONLY'
);
$st->execute($params);
$logs = $st->fetchAll();

$pageTitle = 'Change History';
$activePage = 'history';
require __DIR__ . '/includes/header.php';
?>

<style>
.history-filter-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 18px 20px;
}
.history-search {
    flex: 1 1 420px;
    min-width: 180px;
}
.history-search .search-wrap {
    width: 100%;
}
.history-search input {
    width: 100%;
}
.admin-table .action-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 76px;
    padding: 6px 11px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .35px;
}
.admin-table .action-pill.action-create {
    color: #187245 !important;
    background-color: #e5f6ec !important;
}
.admin-table .action-pill.action-update {
    color: #2457b8 !important;
    background-color: #e8efff !important;
}
.admin-table .action-pill.action-delete {
    color: #b42335 !important;
    background-color: #ffe7ec !important;
}
.admin-table .action-pill.action-deactivate {
    color: #a65b12 !important;
    background-color: #fff1dc !important;
}
.admin-table .action-pill.action-activate {
    color: #087e83 !important;
    background-color: #ddf6f3 !important;
}
.history-action-select {
    appearance: none !important;
    -webkit-appearance: none !important;
    min-width: 190px;
    height: 42px;
    padding: 0 42px 0 15px !important;
    border: 1px solid #cfdccd !important;
    border-radius: 9px !important;
    background-color: #fff !important;
    color: #344b3b !important;
    font: inherit !important;
    font-size: 14px !important;
    font-weight: 600 !important;
    cursor: pointer;
    outline: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%235b765e' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: right 14px center !important;
}
.history-action-select:focus {
    border-color: #7c9b72 !important;
    box-shadow: 0 0 0 3px rgba(124,155,114,.15);
}
.history-filter-bar .btn {
    min-height: 42px;
}
@media (max-width: 700px) {
    .history-filter-bar { flex-wrap: wrap; }
    .history-search { flex-basis: 100%; }
    .history-action-select { flex: 1; min-width: 0; }
}
</style>

<section class="content">
    <div class="page-intro">
        <div>
            <h2>Audit trail</h2>
            <p>Records who performed an action, what changed, and when it happened.</p>
        </div>
        <span class="record-count"><?= number_format($total) ?> events</span>
    </div>

    <section class="panel data-panel">
        <form method="get" class="history-filter-bar">
            <div class="field search-field history-search">
                <div class="search-wrap">
                    <span>⌕</span>
                    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Username, process, description…" aria-label="Search history">
                </div>
            </div>
            <select name="action" class="history-action-select" aria-label="Filter by action">
                <option value="">All actions</option>
                <?php foreach ($allowedActions as $a): ?>
                    <option value="<?= h($a) ?>" <?= $action === $a ? 'selected' : '' ?>>
                        <?= h($a) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary" type="submit">Filter</button>
            <a class="btn btn-ghost" href="history.php">Clear</a>
        </form>

        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Date / Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Item</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$logs): ?>
                        <tr>
                            <td colspan="5" class="empty-state">No history records found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $r): ?>
                            <tr>
                                <td><?= h($r['created_at']) ?></td>
                                <td><b><?= h($r['username']) ?></b></td>
                                <td>
                                    <span class="action-pill action-<?= h(strtolower($r['action'])) ?>">
                                        <?= h($r['action']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= h($r['entity_type']) ?>
                                    <?= $r['entity_id'] ? ' #' . h($r['entity_id']) : '' ?>
                                </td>
                                <td><?= h($r['description']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="table-footer">
            <span>Showing <?= count($logs) ?> of <?= number_format($total) ?> events</span>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a class="btn btn-secondary" href="?q=<?= rawurlencode($q) ?>&action=<?= rawurlencode($action) ?>&page=<?= $page - 1 ?>">← Previous</a>
                <?php endif; ?>
                <span>Page <?= $page ?> of <?= $pages ?></span>
                <?php if ($page < $pages): ?>
                    <a class="btn btn-secondary" href="?q=<?= rawurlencode($q) ?>&action=<?= rawurlencode($action) ?>&page=<?= $page + 1 ?>">Next →</a>
                <?php endif; ?>
            </div>
        </div>
    </section>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
