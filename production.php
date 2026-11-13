<?php
declare(strict_types=1); require __DIR__.'/includes/bootstrap.php'; require_login();

$key=(string)($_GET['process']??$_POST['process']??'cut'); if(!isset(PROCESS_LIST[$key]))$key='cut';
$date=(string)($_GET['date']??$_POST['plan_date']??date('Y-m-d')); if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))$date=date('Y-m-d');
// Every 24-hour production process uses the same process table as the original C# application.
// Cut & Crimp is handled separately below because it uses a different table/layout.
$processTableMap = [
    'kitting' => ['table' => 'TB_KITTING', 'location' => 'KIT'],
    'contact sw' => ['table' => 'TB_CONTACT_SW', 'location' => 'CSW'],
    'mr-sw' => ['table' => 'TB_MR_SW', 'location' => 'MR'],
    'hot melt' => ['table' => 'TB_HOT_MELT', 'location' => 'HM'],
    'finished goods' => ['table' => 'TB_FINISH_GOODS', 'location' => 'FGI'],
    'assembly' => ['table' => 'TB_ASSY', 'location' => 'ASSY'],
    'sub assy (saki)' => ['table' => 'TB_SUBASSY_SAKI', 'location' => 'SASAKI'],
    'sub assy (ato)' => ['table' => 'TB_SUBASSY_ATO', 'location' => 'SAATO'],
];
$processLabel = (string)(PROCESS_LIST[$key]['label'] ?? $key);
$processKeyAliases = [
    'contact_sw' => 'contact sw',
    'mr_sw' => 'mr-sw',
    'hot_melt' => 'hot melt',
    'finished_goods' => 'finished goods',
    'sub_assy_saki' => 'sub assy (saki)',
    'sub_assy_ato' => 'sub assy (ato)',
    'subassy_saki' => 'sub assy (saki)',
    'subassy_ato' => 'sub assy (ato)',
];
$processMapKey = strtolower(trim($processLabel));
if (!isset($processTableMap[$processMapKey]) && isset($processKeyAliases[$key])) {
    $processMapKey = $processKeyAliases[$key];
}
$processConfig = $processTableMap[$processMapKey] ?? null;

if ($key !== 'cut') {
    if ($processConfig === null) {
        http_response_code(400);
        exit('No original C# table mapping is configured for process: ' . h($processLabel));
    }
    $processTable = $processConfig['table'];
    $processLocation = $processConfig['location'];
    $kDate = $date;
    $kError = '';
    $kNotice = '';
    $kRows = [];
    $kEdit = null;
    $kSearch = trim((string)($_GET['search'] ?? ''));
    $kShowForm = isset($_GET['new']) || isset($_GET['edit']) || (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['action'] ?? '') === 'save_kitting');
    $kEditCode = trim((string)($_GET['edit'] ?? ''));
    $kModelSuggestions = [];
    $kHours = range(0, 23);
    $kPlanCols = array_map(static fn($i) => 'QTY_YOTEI_' . $i, $kHours);
    $kActualCols = array_map(static fn($i) => 'QTY_JISSEKI_' . $i, $kHours);

    // Use the same process-specific shared CSV source as ModelData.php.
    // ModelName is the Model Code; PartName is the descriptive Model Name.
    $kCsvModels = [];
    $kCsvError = '';
    try {
        $kCsv = read_process_master_data($key);
        $kNorm = static fn($v) => strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$v));
        $kModelCol = $kPartCol = null;
        foreach ($kCsv['headers'] as $i => $header) {
            $headerKey = $kNorm($header);
            if ($headerKey === 'modelname') $kModelCol = $i;
            elseif ($headerKey === 'partname') $kPartCol = $i;
        }
        if ($kModelCol === null) {
            throw new RuntimeException('The selected Model Data CSV must contain a ModelName column.');
        }
        foreach ($kCsv['rows'] as $csvRow) {
            $modelCode = trim((string)($csvRow[$kModelCol] ?? ''));
            if ($modelCode === '') continue;
            $kCsvModels[$modelCode] = [
                'ITEM_ID' => $modelCode,
                'ITEM_NAME' => $kPartCol !== null ? trim((string)($csvRow[$kPartCol] ?? '')) : ''
            ];
        }
    } catch (Throwable $csvEx) {
        $kCsvError = $csvEx->getMessage();
    }
    if ($kCsvError !== '' && $kError === '') {
        $kError = $kCsvError;
    }

    if (($_GET['ajax'] ?? '') === 'models') {
        header('Content-Type: application/json; charset=utf-8');
        if ($kCsvError !== '') {
            http_response_code(400);
            echo json_encode(['error' => $kCsvError], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $q = function_exists('mb_strtolower')
            ? mb_strtolower(trim((string)($_GET['q'] ?? '')), 'UTF-8')
            : strtolower(trim((string)($_GET['q'] ?? '')));
        $suggestions = [];
        foreach ($kCsvModels as $model) {
            $haystack = $model['ITEM_ID'] . ' ' . $model['ITEM_NAME'];
            $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
            if ($q === '' || str_contains($haystack, $q)) $suggestions[] = $model;
            if (count($suggestions) >= 30) break;
        }
        echo json_encode($suggestions, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    try {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
            $action = (string)($_POST['action'] ?? '');

            if ($action === 'save_kitting') {
                $modelCode = trim((string)($_POST['model_code'] ?? ''));
                if ($modelCode === '') {
                    throw new RuntimeException('Please select a Model Code.');
                }
                if ($kCsvError !== '') {
                    throw new RuntimeException($kCsvError);
                }
                if (!isset($kCsvModels[$modelCode])) {
                    throw new RuntimeException('Model Code was not found in the selected Model Data CSV. Please choose a Model Code from the suggestions.');
                }
                $model = $kCsvModels[$modelCode];
                $hourly = $_POST['hourly'] ?? [];
                $values = [];
                foreach ($kHours as $hour) {
                    $values[] = max(0, (int)($hourly[$hour] ?? 0));
                }
                $isEdit = (string)($_POST['is_edit'] ?? '') === '1';
                if ($isEdit) {
                    $set = implode(', ', array_map(static fn($c) => '[' . $c . '] = ?', $kPlanCols));
                    $sql = "UPDATE dbo.{$processTable} SET {$set}
                            WHERE MODELCD = ? AND YOTEI_DATE >= CAST(? AS date)
                              AND YOTEI_DATE < DATEADD(day, 1, CAST(? AS date))";
                    $values[] = $modelCode;
                    $values[] = $kDate;
                    $values[] = $kDate;
                    $upd = db()->prepare($sql);
                    $upd->execute($values);
                    $kNotice = $processLabel . ' schedule updated successfully.';
                    if (function_exists('audit_log')) audit_log('UPDATE', $processTable, null, 'Updated ' . $processLabel . ' schedule ' . $modelCode . ' for ' . $kDate);
                } else {
                    $check = db()->prepare("SELECT TOP 1 MODELCD FROM dbo.{$processTable}
                        WHERE MODELCD = ? AND YOTEI_DATE >= CAST(? AS date)
                          AND YOTEI_DATE < DATEADD(day, 1, CAST(? AS date))");
                    $check->execute([$modelCode, $kDate, $kDate]);
                    if ($check->fetchColumn()) {
                        throw new RuntimeException('Model already registered');
                    }
                    $columns = array_merge(['MODELCD', 'MODELNAME', 'YOTEI_DATE'], $kPlanCols, $kActualCols);
                    $placeholders = implode(',', array_fill(0, count($columns), '?'));
                    $insertValues = array_merge(
                        [$model['ITEM_ID'], $model['ITEM_NAME'], $kDate],
                        $values,
                        array_fill(0, 24, 0)
                    );
                    $ins = db()->prepare("INSERT INTO dbo.{$processTable} (" . implode(',', array_map(static fn($c) => '[' . $c . ']', $columns)) . ") VALUES ({$placeholders})");
                    $ins->execute($insertValues);
                    $kNotice = $processLabel . ' schedule registered successfully.';
                    if (function_exists('audit_log')) audit_log('CREATE', $processTable, null, 'Created ' . $processLabel . ' schedule ' . $modelCode . ' for ' . $kDate);
                }
                $kShowForm = false;
                $kEditCode = '';
            } elseif ($action === 'update_hourly_cell') {
                $modelCode = trim((string)($_POST['model_code'] ?? ''));
                $kind = (string)($_POST['cell_kind'] ?? '');
                $hourRaw = (string)($_POST['cell_hour'] ?? '');
                $valueRaw = trim((string)($_POST['cell_value'] ?? ''));
                if ($modelCode === '' || !in_array($kind, ['plan', 'actual'], true)
                    || !preg_match('/^(?:[0-9]|1[0-9]|2[0-3])$/', $hourRaw)
                    || !preg_match('/^\d+$/', $valueRaw)) {
                    throw new RuntimeException('Invalid hourly cell update. Enter a non-negative whole number.');
                }
                $hour = (int)$hourRaw;
                $column = $kind === 'plan' ? $kPlanCols[$hour] : $kActualCols[$hour];
                $cellUpdate = db()->prepare("UPDATE dbo.{$processTable} SET [{$column}] = ?
                    WHERE MODELCD = ? AND YOTEI_DATE >= CAST(? AS date)
                      AND YOTEI_DATE < DATEADD(day, 1, CAST(? AS date))");
                $cellUpdate->execute([(int)$valueRaw, $modelCode, $kDate, $kDate]);
                $kNotice = $processLabel . ' ' . ($kind === 'plan' ? 'Schedule' : 'Performance') . ' ' . sprintf('%02d:00', $hour) . ' updated.';
                if (function_exists('audit_log')) audit_log('UPDATE', $processTable, null, 'Updated ' . $column . ' for ' . $modelCode . ' on ' . $kDate);
            } elseif ($action === 'update_hourly_inline') {
                $modelCode = trim((string)($_POST['model_code'] ?? ''));
                if ($modelCode === '') throw new RuntimeException('Invalid Model Code for editing.');
                $planInput = $_POST['plan'] ?? [];
                $actualInput = $_POST['actual'] ?? [];
                $setParts = [];
                $updateValues = [];
                foreach ($kHours as $hour) {
                    $setParts[] = '[' . $kPlanCols[$hour] . '] = ?';
                    $updateValues[] = max(0, (int)($planInput[$hour] ?? 0));
                }
                foreach ($kHours as $hour) {
                    $setParts[] = '[' . $kActualCols[$hour] . '] = ?';
                    $updateValues[] = max(0, (int)($actualInput[$hour] ?? 0));
                }
                array_push($updateValues, $modelCode, $kDate, $kDate);
                $inlineUpdate = db()->prepare("UPDATE dbo.{$processTable} SET " . implode(', ', $setParts) . "
                    WHERE MODELCD = ? AND YOTEI_DATE >= CAST(? AS date)
                      AND YOTEI_DATE < DATEADD(day, 1, CAST(? AS date))");
                $inlineUpdate->execute($updateValues);
                $kNotice = $processLabel . ' hourly schedule and performance updated successfully.';
                if (function_exists('audit_log')) audit_log('UPDATE', $processTable, null, 'Updated all hourly values for ' . $modelCode . ' on ' . $kDate);
            } elseif ($action === 'delete_kitting') {
                $modelCode = trim((string)($_POST['model_code'] ?? ''));
                $del = db()->prepare("DELETE FROM dbo.{$processTable}
                    WHERE MODELCD = ? AND YOTEI_DATE >= CAST(? AS date)
                      AND YOTEI_DATE < DATEADD(day, 1, CAST(? AS date))");
                $del->execute([$modelCode, $kDate, $kDate]);
                $kNotice = $del->rowCount() ? $processLabel . ' record deleted.' : 'No matching ' . $processLabel . ' record was found.';
                if (function_exists('audit_log')) audit_log('DELETE', $processTable, null, 'Deleted ' . $processLabel . ' schedule ' . $modelCode . ' for ' . $kDate);
            } elseif ($action === 'handy_kitting') {
                throw new RuntimeException('Handy Data import is not configured. Performance values are read from the process table.');

            }
        }

        $selectColumns = array_merge(['MODELCD', 'MODELNAME', 'YOTEI_DATE'], $kPlanCols, $kActualCols);
        $sql = "SELECT " . implode(', ', array_map(static fn($c) => '[' . $c . ']', $selectColumns)) . "\n                FROM dbo.{$processTable}\n                WHERE YOTEI_DATE >= CAST(? AS date)\n                  AND YOTEI_DATE < DATEADD(day, 1, CAST(? AS date))";
        $params = [$kDate, $kDate];
        if ($kSearch !== '') {
            $sql .= ' AND (MODELCD LIKE ? OR MODELNAME LIKE ?)';
            $params[] = '%' . $kSearch . '%';
            $params[] = '%' . $kSearch . '%';
        }
        $sql .= ' ORDER BY MODELCD';
        $listSt = db()->prepare($sql);
        $listSt->execute($params);
        $kRows = $listSt->fetchAll(PDO::FETCH_ASSOC);

        if ($kEditCode !== '') {
            $editSt = db()->prepare("SELECT " . implode(', ', array_map(static fn($c) => '[' . $c . ']', $selectColumns)) . "\n                FROM dbo.{$processTable} WHERE MODELCD = ?\n                  AND YOTEI_DATE >= CAST(? AS date)\n                  AND YOTEI_DATE < DATEADD(day, 1, CAST(? AS date))");
            $editSt->execute([$kEditCode, $kDate, $kDate]);
            $kEdit = $editSt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($kEdit) $kShowForm = true;
        }
    } catch (Throwable $ex) {
        $kError = $ex->getMessage();
    }

    $kTotalPlan = 0;
    $kTotalActual = 0;
    foreach ($kRows as $row) {
        foreach ($kHours as $hour) {
            $kTotalPlan += (int)($row[$kPlanCols[$hour]] ?? 0);
            $kTotalActual += (int)($row[$kActualCols[$hour]] ?? 0);
        }
    }
    $pageTitle = $processLabel;
    $activePage = 'production';
    $currentProcess = $key;
    require __DIR__ . '/includes/header.php';
    ?>
    <section class="content kitting-page">
      <div class="page-intro">
        <div><h2><?=h($processLabel)?> Product Input</h2><p>Hourly schedule and performance records from dbo.<?=h($processTable)?>.</p></div>
      </div>
      <div class="modules"><?php foreach (PROCESS_LIST as $processKey => $processInfo): ?><a class="<?=$processKey === $key ? 'sel' : ''?>" href="?process=<?=h($processKey)?>&date=<?=h($kDate)?>"><?=h($processInfo['label'])?></a><?php endforeach; ?></div>
      <?php if ($kNotice): ?><div class="notice notice-success"><?=h($kNotice)?></div><?php endif; ?>
      <?php if ($kError): ?><div class="notice notice-error model-duplicate-error"><?=h($kError)?></div><?php endif; ?>

      <section class="panel kitting-list-panel">
        <div class="kitting-toolbar">
          <form method="get" class="kitting-search-form"><input type="hidden" name="process" value="<?=h($key)?>"><input type="hidden" name="date" value="<?=h($kDate)?>"><div class="kitting-search-field"><span class="kitting-search-icon" aria-hidden="true">⌕</span><input type="search" id="kitting_table_search" name="search" value="<?=h($kSearch)?>" placeholder="Search model code or model name..." aria-label="Search model code or model name"></div><button class="btn btn-secondary btn-small" type="submit"><span aria-hidden="true">⌕</span> Search</button></form>
          <div class="kitting-actions">
            <form method="get" class="kitting-date-form"><input type="hidden" name="process" value="<?=h($key)?>"><input id="kitting-plan-date" type="date" name="date" value="<?=h($kDate)?>" onchange="this.form.submit()" aria-label="Schedule date"></form>
            <a class="btn btn-primary btn-small" href="?process=<?=h($key)?>&date=<?=h($kDate)?>&new=1">＋ New Register</a>
          </div>
        </div>
        <div class="kitting-table-wrap"><table class="kitting-table" id="kitting_records_table">
          <thead><tr><th rowspan="2" class="kitting-model-col">Model Code</th><th rowspan="2" class="kitting-total-col">Total Qty</th><th colspan="24" class="kitting-time-title">Time Schedule</th><th rowspan="2" class="kitting-action-col">Action</th></tr>
          <tr><?php foreach ($kHours as $hour): ?><th><?=sprintf('%02d:00', $hour)?></th><?php endforeach; ?></tr></thead>
          <tbody>
          <?php foreach ($kRows as $row): $code = (string)$row['MODELCD']; $planSum = 0; $actualSum = 0; foreach ($kHours as $hour) { $planSum += (int)($row[$kPlanCols[$hour]] ?? 0); $actualSum += (int)($row[$kActualCols[$hour]] ?? 0); } ?>
            <tr class="kitting-schedule-row" data-search="<?=h(strtolower($code . ' ' . (string)$row['MODELNAME']))?>">
              <td class="kitting-model-cell" rowspan="2"><strong><?=h($code)?></strong><small><?=h((string)$row['MODELNAME'])?></small></td>
              <td class="kitting-total-cell"><?=number_format($planSum)?></td>
              <?php foreach ($kHours as $hour): $planValue=(int)($row[$kPlanCols[$hour]] ?? 0); ?><td class="kitting-editable-cell"><span class="kitting-cell-display"><?=number_format($planValue)?></span><input class="kitting-cell-input" type="number" min="0" step="1" name="plan[<?=$hour?>]" value="<?=$planValue?>" form="inline-edit-<?=h(substr(sha1($code),0,12))?>" aria-label="Schedule <?=sprintf('%02d:00',$hour)?>" disabled></td><?php endforeach; ?>
              <td class="kitting-row-actions" rowspan="2">
                <form method="post" id="inline-edit-<?=h(substr(sha1($code),0,12))?>" class="kitting-inline-edit-form"><?=csrf_field()?><input type="hidden" name="action" value="update_hourly_inline"><input type="hidden" name="process" value="<?=h($key)?>"><input type="hidden" name="plan_date" value="<?=h($kDate)?>"><input type="hidden" name="model_code" value="<?=h($code)?>">
                  <button type="button" class="kitting-icon-btn kitting-edit-btn" title="Edit all hourly cells" aria-label="Edit all hourly cells"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg></button>
                  <button type="submit" class="kitting-icon-btn kitting-save-btn" title="Save changes" aria-label="Save changes" hidden><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h12l4 4v14H3V3z"/><path d="M7 3v6h10V3M7 21v-8h10v8"/></svg></button>
                  <button type="button" class="kitting-icon-btn kitting-cancel-btn" title="Cancel editing" aria-label="Cancel editing" hidden><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
                </form>
                <form method="post" class="kitting-inline-delete-form" onsubmit="return confirm('Delete <?=h(addslashes($processLabel))?> record <?=h(addslashes($code))?>?')"><?=csrf_field()?><input type="hidden" name="action" value="delete_kitting"><input type="hidden" name="process" value="<?=h($key)?>"><input type="hidden" name="plan_date" value="<?=h($kDate)?>"><input type="hidden" name="model_code" value="<?=h($code)?>"><button type="submit" class="kitting-icon-btn kitting-delete-btn" title="Delete record" aria-label="Delete record"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2m3 0-1 15H6L5 6m4 4v7m6-7v7"/></svg></button></form>
              </td>
            </tr>
              <tr class="kitting-performance-row" data-search="<?=h(strtolower($code . ' ' . (string)$row['MODELNAME']))?>"><td class="kitting-total-cell"><span class="kitting-performance-label">Performance</span><?=number_format($actualSum)?></td><?php foreach ($kHours as $hour): $actualValue=(int)($row[$kActualCols[$hour]] ?? 0); ?><td class="kitting-editable-cell"><span class="kitting-cell-display"><?=number_format($actualValue)?></span><input class="kitting-cell-input" type="number" min="0" step="1" name="actual[<?=$hour?>]" value="<?=$actualValue?>" form="inline-edit-<?=h(substr(sha1($code),0,12))?>" aria-label="Performance <?=sprintf('%02d:00',$hour)?>" disabled></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
          <?php if (!$kRows): ?><tr><td colspan="27" class="kitting-empty">No <?=h($processLabel)?> records for this date.</td></tr><?php endif; ?>
          </tbody>
        </table></div>
        <div class="kitting-totals"><div><span>Total (Schedule)</span><strong><?=number_format($kTotalPlan)?></strong></div><div><span>Total (Performance)</span><strong><?=number_format($kTotalActual)?></strong></div></div>
      </section>

      <?php if ($kShowForm): $editHourly = []; foreach ($kHours as $hour) $editHourly[$hour] = (int)($kEdit[$kPlanCols[$hour]] ?? 0); ?>
      <div class="kitting-modal-backdrop" id="kitting-register-modal" role="dialog" aria-modal="true" aria-labelledby="kitting-register-title">
      <section class="panel kitting-register-panel" id="kitting-register">
        <div class="kitting-modal-heading"><div><h3 id="kitting-register-title"><?=$kEdit ? 'Edit Register (Schedule)' : 'Register (Schedule)'?> <span class="kitting-register-date"><?=h(date('M d, Y', strtotime($kDate)))?></span></h3><p>Enter the hourly planned quantity. The 24-hour values are saved in dbo.<?=h($processTable)?>.</p></div><a class="kitting-modal-close" href="?process=<?=h($key)?>&date=<?=h($kDate)?>" aria-label="Close registration modal" title="Close">&times;</a></div>
        <form method="post" id="kitting-register-form" class="kitting-register-form">
          <?=csrf_field()?>
          <input type="hidden" name="action" value="save_kitting"><input type="hidden" name="process" value="<?=h($key)?>"><input type="hidden" name="plan_date" value="<?=h($kDate)?>"><input type="hidden" name="is_edit" value="<?=$kEdit ? '1' : '0'?>">
          <div class="kitting-register-top">
            <label class="kitting-model-picker-label">Model Code
              <div class="kitting-model-picker">
                <input type="text" name="model_code" id="kitting_model_code" value="<?=h($kEdit['MODELCD'] ?? '')?>" placeholder="Select or search Model Code" autocomplete="off" aria-autocomplete="list" aria-expanded="false" aria-controls="kitting-model-options" required <?=$kEdit ? 'readonly' : ''?>>
                <button type="button" class="kitting-model-picker-toggle" id="kitting-model-picker-toggle" aria-label="Show Model Codes" <?=$kEdit ? 'hidden' : ''?>>▾</button>
                <div class="kitting-model-options" id="kitting-model-options" role="listbox" hidden></div>
              </div>
              <small class="kitting-model-help">Choose a Model Code from the CSV configured in Model Data.</small>
            </label>
            <label>Start Time<select id="kitting_start_hour"><?php foreach ($kHours as $hour): ?><option value="<?=$hour?>" <?=$hour === 8 ? 'selected' : ''?>><?=sprintf('%02d:00', $hour)?></option><?php endforeach; ?></select></label>
            <label>Total Qty<input type="number" id="kitting_total_qty" min="0" value="100"></label>
            <label>Qty per Hour<input type="number" id="kitting_qty_per_hour" min="0" value="100"></label>
            <button type="button" class="btn btn-secondary" id="kitting_set_qty">Set Qty</button>
          </div>
          <div class="kitting-input-grid-wrap"><table class="kitting-input-grid"><thead><tr><th colspan="24">Time Schedule</th></tr><tr><?php foreach ($kHours as $hour): ?><th><?=sprintf('%02d:00', $hour)?></th><?php endforeach; ?></tr></thead><tbody><tr><?php foreach ($kHours as $hour): ?><td><input type="number" min="0" name="hourly[<?=$hour?>]" value="<?=$editHourly[$hour]?>" aria-label="<?=sprintf('%02d:00', $hour)?> quantity"></td><?php endforeach; ?></tr></tbody></table></div>
          <div class="kitting-register-footer"><span class="muted">Date: <?=h($kDate)?></span><div><a class="btn btn-ghost" href="?process=<?=h($key)?>&date=<?=h($kDate)?>">Cancel</a> <button class="btn btn-primary">Save / Register</button></div></div>
        </form>
      </section>
      </div>
      <?php endif; ?>
    </section>
    <style>
      .model-duplicate-error{background:#ffe7ec!important;color:#b42335!important;border:1px solid #f3a5b2!important;border-radius:8px;padding:11px 14px;font-weight:700}
      .kitting-page{max-width:100%;min-width:0}.kitting-date-form input{min-width:150px}.kitting-list-panel{padding:14px}.kitting-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px}.kitting-search-form,.kitting-actions{display:flex;align-items:center;gap:8px}.kitting-search-form input{width:min(340px,65vw)}.kitting-actions form{margin:0}.kitting-table-wrap{width:100%;overflow:auto;border:1px solid var(--border,#d8ded6);border-radius:8px;max-height:590px}.kitting-table{border-collapse:collapse;border-spacing:0;min-width:1450px;width:100%;font-size:12px}.kitting-table th,.kitting-table td{border-right:1px solid #dce2da;border-bottom:1px solid #dce2da;padding:8px 7px;text-align:center;white-space:nowrap}.kitting-table thead th{position:sticky;top:0;z-index:2;background:#e8eee5;font-weight:700}.kitting-table thead tr:first-child th{top:0;height:34px;padding-top:7px;padding-bottom:7px}.kitting-table thead tr:nth-child(2) th{top:34px;height:34px;padding-top:7px;padding-bottom:7px}.kitting-table .kitting-time-title{background:#f7f8f6}.kitting-model-col,.kitting-model-cell{position:sticky;left:0;z-index:3;min-width:180px;text-align:left!important;background:#fff}.kitting-total-col,.kitting-total-cell{position:sticky;left:180px;z-index:3;min-width:90px;background:#fff}.kitting-table thead .kitting-model-col,.kitting-table thead .kitting-total-col{z-index:5;background:#e8eee5}.kitting-model-cell strong,.kitting-model-cell small{display:block}.kitting-model-cell small{margin-top:3px;color:#738076;max-width:220px;overflow:hidden;text-overflow:ellipsis}.kitting-schedule-row{background:#fff}.kitting-performance-row{background:#f7f9f6}.kitting-performance-row td{color:#657267}.kitting-performance-row .kitting-model-cell{background:#f7f9f6}.kitting-performance-label{display:block;font-size:10px;color:#7b887c}.kitting-row-actions{position:sticky;right:0;z-index:3;background:#fff;min-width:100px}.kitting-row-actions form{margin-top:4px}.kitting-row-actions .kitting-inline-edit-form{display:flex;justify-content:center;gap:5px;margin:0 0 5px}.kitting-row-actions .kitting-inline-delete-form{margin:0}.kitting-icon-btn{display:inline-grid;place-items:center;width:30px;height:30px;padding:5px;border:1px solid #d5ded2;border-radius:7px;background:#fff;color:#425a43;cursor:pointer}.kitting-icon-btn:hover{background:#edf4e9;border-color:#9aaf92}.kitting-delete-btn{color:#bd4141}.kitting-delete-btn:hover{background:#fff0f0;border-color:#e5aaaa}.kitting-icon-btn svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}.kitting-icon-btn[hidden]{display:none!important}.kitting-cell-input{display:none;width:100%;min-width:42px;max-width:76px;box-sizing:border-box;padding:5px 2px;text-align:center;border:1px solid #8da486;border-radius:5px;font:inherit;background:#fff}.kitting-table tr.kitting-inline-editing .kitting-cell-display{display:none}.kitting-table tr.kitting-inline-editing .kitting-cell-input{display:inline-block}.kitting-table tr.kitting-inline-editing td{background:#f0f7ed}.kitting-table tr.kitting-inline-editing .kitting-total-cell{background:#eaf2e6}.kitting-empty{padding:28px!important;color:#758176}.kitting-totals{display:flex;justify-content:flex-end;gap:26px;flex-wrap:wrap;padding:14px 4px 2px}.kitting-totals div{display:flex;align-items:center;gap:12px}.kitting-totals span{font-weight:600;color:#536255}.kitting-totals strong{font-size:22px;min-width:70px}.kitting-register-date{display:inline-block;margin-left:8px;padding:4px 9px;border-radius:7px;background:#edf3e9;color:#526b4e;font-size:12px;font-weight:700;vertical-align:middle}
      .kitting-model-picker-label{min-width:0}
      .kitting-model-picker{position:relative;width:100%;min-width:0}
      .kitting-model-picker>input{width:100%;padding-right:42px}
      .kitting-model-picker-toggle{position:absolute;right:2px;top:2px;width:38px;height:calc(100% - 4px);border:0;border-radius:6px;background:transparent;color:#405044;cursor:pointer}
      .kitting-model-picker-toggle:hover{background:#edf3e9}
      .kitting-model-options{position:absolute;top:calc(100% + 6px);left:0;right:0;z-index:100010;max-height:280px;overflow:auto;padding:5px;border:1px solid #cbd6c7;border-radius:9px;background:#fff;box-shadow:0 12px 28px rgba(20,35,25,.18)}
      .kitting-model-options[hidden]{display:none!important}
      .kitting-model-option{display:flex;width:100%;flex-direction:column;align-items:flex-start;gap:3px;padding:9px 11px;border:0;border-radius:6px;background:#fff;color:#263329;text-align:left;cursor:pointer}
      .kitting-model-option:hover,.kitting-model-option:focus{background:#edf3e9;outline:none}
      .kitting-model-option strong{font-size:13px}
      .kitting-model-option span{font-size:11px;color:#718076;white-space:normal}
      .kitting-model-empty{padding:11px;color:#718076;font-size:12px}
      .kitting-model-help{display:block;margin-top:5px;color:#718076;font-size:11px;font-weight:400}
      .kitting-modal-backdrop{position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;padding:24px;background:rgba(18,28,20,.58);backdrop-filter:blur(3px)}.kitting-modal-backdrop[hidden]{display:none!important}.kitting-register-panel{width:min(1500px,calc(100vw - 32px));max-height:calc(100vh - 48px);overflow:auto;margin:0;padding:24px;border:1px solid #dce5d8;border-radius:16px;box-shadow:0 24px 80px rgba(0,0,0,.28);background:#fff}.kitting-modal-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin:0 0 22px;padding:0 0 16px;border-bottom:1px solid #e6ece3}.kitting-modal-heading h3{margin:0 0 6px;font-size:19px;color:#263329}.kitting-modal-heading p{margin:0;color:#718076;font-size:13px;line-height:1.5}.kitting-modal-close{flex:0 0 36px;display:grid;place-items:center;width:36px;height:36px;border:1px solid #dce5d8;border-radius:10px;color:#526252;text-decoration:none;font-size:26px;line-height:1}.kitting-modal-close:hover{background:#f1f5ef;color:#243326}.kitting-register-top{display:grid;grid-template-columns:minmax(220px,2fr) repeat(3,minmax(130px,1fr)) auto;gap:16px;align-items:end;padding:2px 0 4px}.kitting-register-top label{display:flex;flex-direction:column;gap:8px;font-size:13px;font-weight:600;color:#405044}.kitting-register-top input,.kitting-register-top select{width:100%;min-width:0;min-height:42px;box-sizing:border-box;padding:9px 11px;border:1px solid #cbd6c7;border-radius:8px;background:#fff;color:#263329;font:inherit}.kitting-register-top input:focus,.kitting-register-top select:focus,.kitting-input-grid td input:focus{outline:none;border-color:#78966e;box-shadow:0 0 0 3px rgba(120,150,110,.14)}.kitting-register-top>button{min-height:42px;padding:0 18px;border-radius:8px;white-space:nowrap}.kitting-input-grid-wrap{overflow-x:auto;margin-top:22px;border:1px solid #dce2da;border-radius:10px;background:#fff}.kitting-input-grid{border-collapse:collapse;min-width:1100px;width:100%;table-layout:fixed}.kitting-input-grid th,.kitting-input-grid td{border:1px solid #dce2da;text-align:center;padding:10px 5px}.kitting-input-grid thead th{background:#edf3e9;color:#405044;font-size:11px;font-weight:700;padding:11px 4px}.kitting-input-grid thead tr:first-child th{font-size:12px;letter-spacing:.02em}.kitting-input-grid td input{min-height:38px;box-sizing:border-box;border:1px solid #cbd6c7;border-radius:6px;background:#fbfcfa;font:inherit}.kitting-input-grid td input{width:100%;min-width:0;text-align:center;padding:7px 2px}.kitting-register-footer{display:flex;justify-content:space-between;align-items:center;margin-top:22px;padding-top:16px;border-top:1px solid #e6ece3;gap:12px}.kitting-register-footer>div{display:flex;gap:10px}.kitting-register-footer .btn{min-height:40px;padding:9px 18px;border-radius:8px}@media(max-width:850px){.kitting-modal-backdrop{padding:12px}.kitting-register-panel{width:calc(100vw - 24px);max-height:calc(100vh - 24px);padding:18px}.kitting-register-top{grid-template-columns:repeat(2,minmax(0,1fr))}.kitting-register-top label:first-child{grid-column:1/-1}.kitting-register-top button{align-self:end}.kitting-search-form{flex:1}.kitting-search-form input{flex:1;min-width:120px}}@media(max-width:520px){.kitting-modal-backdrop{align-items:flex-start;padding:8px}.kitting-register-panel{width:100%;max-height:calc(100vh - 16px);padding:15px;border-radius:12px}.kitting-modal-heading{margin-bottom:16px}.kitting-register-top{grid-template-columns:1fr}.kitting-register-top label:first-child{grid-column:auto}.kitting-register-footer{align-items:flex-start;flex-direction:column}}
      /* Refined date and search controls */
      .kitting-toolbar{display:flex;align-items:center;gap:16px;margin-bottom:14px}
      .kitting-search-form{display:flex;flex:1 1 0;min-width:220px;gap:9px}
      .kitting-search-field{position:relative;flex:1 1 auto;max-width:none;min-width:0}
      .kitting-search-icon{position:absolute;left:13px;top:50%;transform:translateY(-52%);font-size:21px;line-height:1;color:#849286;pointer-events:none}
      .kitting-search-field input[type="search"]{display:block;width:100%;height:40px;box-sizing:border-box;padding:9px 13px 9px 39px;border:1px solid #cbd6c7;border-radius:9px;background:#fbfcfa;color:#263329;font:inherit;font-size:13px;outline:none;transition:border-color .15s,box-shadow .15s,background .15s}
      .kitting-search-field input[type="search"]:focus{border-color:#78966e;background:#fff;box-shadow:0 0 0 3px rgba(120,150,110,.14)}
      .kitting-search-field input[type="search"]::placeholder{color:#98a398}
      .kitting-search-form>button{height:40px;min-width:88px;padding:0 15px;border-radius:9px;white-space:nowrap}
      .kitting-search-form>button span{font-size:15px;margin-right:4px}
      .kitting-actions{display:flex;align-items:center;justify-content:flex-end;flex:0 0 auto;gap:10px;flex-wrap:nowrap}
      /* Cut & Crimp date: align to the far right and match the other process date control */
      .cut-date-form{display:flex;align-items:center;justify-content:center;flex:0 0 214px;width:214px;min-width:214px;margin:0 0 0 auto;padding:9px 10px;border:1px solid #dce5d8;border-radius:13px;background:#fff;box-shadow:0 2px 8px rgba(31,49,34,.06);box-sizing:border-box}
      .cut-register-panel-head{display:flex;align-items:center;justify-content:space-between;gap:16px}
      .cut-register-panel-head>div{min-width:0}
      .cut-register-panel-head .cut-date-form{margin-left:auto}
      @media(max-width:520px){.cut-register-panel-head{align-items:flex-start;flex-wrap:wrap}.cut-register-panel-head .cut-date-form{width:214px;max-width:100%;flex:0 1 214px}}
      .cut-date-form input[type="date"]{display:block;width:100%;height:40px;min-height:40px;box-sizing:border-box;padding:7px 10px;border:1px solid #cbd6c7;border-radius:9px;background:#fbfcfa;color:#263329;font:inherit;font-size:13px;font-weight:500;cursor:pointer}
      .cut-date-form input[type="date"]:focus{outline:none;border-color:#78966e;box-shadow:0 0 0 3px rgba(120,150,110,.14)}
      .kitting-date-form{display:flex;flex-direction:column;gap:4px;width:172px;min-width:172px;padding:7px 9px;border:1px solid #dce5d8;border-radius:9px;background:#fff;box-shadow:0 2px 8px rgba(31,49,34,.04)}
      .kitting-date-form label{font-size:9px;font-weight:700;letter-spacing:.045em;text-transform:uppercase;color:#718076}
      .kitting-date-form input[type="date"]{display:block;width:100%;min-width:0;min-height:32px;padding:4px 7px;border:1px solid #cbd6c7;border-radius:7px;background:#fbfcfa;color:#263329;font:inherit;font-size:12px;font-weight:600;box-sizing:border-box;cursor:pointer}
      .kitting-date-form input[type="date"]:focus{outline:none;border-color:#78966e;box-shadow:0 0 0 3px rgba(120,150,110,.14)}
      .kitting-actions>.btn{white-space:nowrap;min-height:40px;display:inline-flex;align-items:center}
      @media(max-width:850px){.kitting-toolbar{align-items:stretch;flex-wrap:wrap}.kitting-search-form{flex:1 1 100%;width:100%}.kitting-actions{width:100%;justify-content:flex-end}}
      @media(max-width:520px){.kitting-search-form{flex-wrap:wrap}.kitting-search-field{flex:1 1 100%}.kitting-search-form>button{width:100%}.kitting-actions{justify-content:space-between}.kitting-date-form{flex:1 1 150px;width:auto;min-width:0}.cut-date-form{flex:0 1 172px;width:172px;min-width:0}.cut-date-form input[type="date"]{width:100%}}
    </style>
    <script>
    (function(){
      const modelInput=document.getElementById('kitting_model_code');
      const modelOptions=document.getElementById('kitting-model-options');
      const modelToggle=document.getElementById('kitting-model-picker-toggle');
      const registerForm=document.getElementById('kitting-register-form');
      let modelTimer=null;
      let modelRequest=0;
      const closeModelOptions=()=>{if(modelOptions){modelOptions.hidden=true;modelInput?.setAttribute('aria-expanded','false');}};
      const renderModelOptions=(items)=>{
        if(!modelOptions)return;
        modelOptions.innerHTML='';
        if(!Array.isArray(items)||items.length===0){
          const empty=document.createElement('div');empty.className='kitting-model-empty';
          empty.textContent='No matching Model Code found in the selected CSV.';
          modelOptions.appendChild(empty);
        }else{
          items.forEach(item=>{
            const option=document.createElement('button');
            option.type='button';option.className='kitting-model-option';option.setAttribute('role','option');
            const code=document.createElement('strong');code.textContent=item.ITEM_ID||'';
            const description=document.createElement('span');description.textContent=item.ITEM_NAME||'';
            option.append(code,description);
            option.addEventListener('click',()=>{
              modelInput.value=item.ITEM_ID||'';
              modelInput.dataset.csvSelected='1';
              modelInput.setCustomValidity('');
              closeModelOptions();
            });
            modelOptions.appendChild(option);
          });
        }
        modelOptions.hidden=false;
        modelInput?.setAttribute('aria-expanded','true');
      };
      const loadModelOptions=async(query='')=>{
        if(!modelInput||!modelOptions||modelInput.readOnly)return;
        const request=++modelRequest;
        try{
          const url=new URL(window.location.href);url.search='';url.searchParams.set('process', <?=json_encode($key)?>);url.searchParams.set('ajax','models');url.searchParams.set('q',query);
          const response=await fetch(url.toString(),{headers:{'Accept':'application/json'},cache:'no-store'});
          const items=await response.json();
          if(request!==modelRequest)return;
          if(!response.ok)throw new Error(items.error||'Unable to load Model Codes from Model Data.');
          renderModelOptions(items);
        }catch(error){
          if(request!==modelRequest)return;
          modelOptions.innerHTML='';
          const empty=document.createElement('div');empty.className='kitting-model-empty';
          empty.textContent=error.message||'Unable to load Model Codes.';
          modelOptions.appendChild(empty);modelOptions.hidden=false;
          modelInput.setAttribute('aria-expanded','true');
        }
      };
      if(modelInput&&!modelInput.readOnly){
        modelInput.dataset.csvSelected='0';
        modelInput.addEventListener('focus',()=>loadModelOptions(modelInput.value.trim()));
        modelInput.addEventListener('click',()=>{if(modelOptions?.hidden)loadModelOptions(modelInput.value.trim());});
        modelInput.addEventListener('input',()=>{
          modelInput.dataset.csvSelected='0';
          modelInput.setCustomValidity('');
          clearTimeout(modelTimer);
          const query=modelInput.value.trim();
          modelTimer=setTimeout(()=>loadModelOptions(query),160);
        });
        modelToggle?.addEventListener('click',()=>{
          if(modelOptions.hidden)loadModelOptions(modelInput.value.trim());
          else closeModelOptions();
        });
        document.addEventListener('click',event=>{
          if(!event.target.closest('.kitting-model-picker'))closeModelOptions();
        });
      }else if(modelInput){
        modelInput.dataset.csvSelected='1';
      }
      if(registerForm&&modelInput){
        registerForm.addEventListener('submit',event=>{
          const code=modelInput.value.trim();
          if(!code){
            event.preventDefault();modelInput.setCustomValidity('Please select a Model Code before registering.');modelInput.reportValidity();modelInput.focus();return;
          }
          if(modelInput.dataset.csvSelected!=='1'){
            event.preventDefault();modelInput.setCustomValidity('Please choose a Model Code from the Model Data CSV suggestions.');modelInput.reportValidity();modelInput.focus();loadModelOptions(code);return;
          }
        });
      }
      const setButton=document.getElementById('kitting_set_qty');
      if(setButton){setButton.addEventListener('click',()=>{
        const start=parseInt(document.getElementById('kitting_start_hour').value||'0',10);
        let total=Math.max(0,parseInt(document.getElementById('kitting_total_qty').value||'0',10));
        const perHour=Math.max(0,parseInt(document.getElementById('kitting_qty_per_hour').value||'0',10));
        const cells=Array.from(document.querySelectorAll('.kitting-input-grid input[name^="hourly["]'));
        if(perHour<=0)return;
        for(let hour=start;hour<24&&total>0;hour++){
          if(total-perHour>0){if(cells[hour])cells[hour].value=perHour;total-=perHour;}
          else {if(cells[hour])cells[hour].value=total;break;}
        }
      });}
      const search=document.getElementById('kitting_table_search');
      if(search){search.addEventListener('input',()=>{
        const q=search.value.trim().toLowerCase();
        document.querySelectorAll('#kitting_records_table tbody tr[data-search]').forEach(row=>{row.hidden=!row.dataset.search.includes(q);});
      });}
      // Double-click a single hourly cell to edit only that value.
      // The normal pencil icon continues to edit all 48 hourly cells.
      document.querySelectorAll('#kitting_records_table .kitting-editable-cell').forEach(cell=>{
        cell.title='Double-click to edit this cell';
        cell.addEventListener('dblclick',()=>{
          const input=cell.querySelector('.kitting-cell-input');
          const form=input ? document.getElementById(input.getAttribute('form')) : null;
          if(!input||!form)return;
          const scheduleRow=form.closest('.kitting-schedule-row');
          if(scheduleRow?.classList.contains('kitting-inline-editing'))return;
          if(cell.querySelector('.kitting-single-editor'))return;
          const original=input.value;
          const display=cell.querySelector('.kitting-cell-display');
          const editor=document.createElement('input');
          editor.type='number';editor.min='0';editor.step='1';
          editor.className='kitting-cell-input kitting-single-editor';
          editor.value=original;
          editor.style.display='inline-block';
          if(display)display.style.display='none';
          cell.appendChild(editor);
          let finished=false;
          const close=()=>{if(finished)return;finished=true;editor.remove();if(display)display.style.display='';};
          const save=()=>{
            if(finished)return;
            const value=editor.value.trim();
            if(!/^\d+$/.test(value)){editor.setCustomValidity('Enter a non-negative whole number');editor.reportValidity();return;}
            editor.setCustomValidity('');
            if(value===original){close();return;}
            const name=input.name;
            const match=/^(plan|actual)\[(\d{1,2})\]$/.exec(name);
            if(!match){close();return;}
            const post=document.createElement('form');post.method='post';post.action=window.location.pathname+window.location.search;
            const add=(name,value)=>{const field=document.createElement('input');field.type='hidden';field.name=name;field.value=value;post.appendChild(field);};
            form.querySelectorAll('input[type="hidden"]').forEach(field=>{if(field.name!=='action')add(field.name,field.value);});
            add('action','update_hourly_cell');add('cell_kind',match[1]);add('cell_hour',match[2]);add('cell_value',value);
            document.body.appendChild(post);finished=true;post.submit();
          };
          editor.addEventListener('keydown',e=>{
            if(e.key==='Enter'){e.preventDefault();save();}
            if(e.key==='Escape'){e.preventDefault();close();}
          });
          editor.addEventListener('blur',()=>{if(!finished)save();});
          editor.focus();editor.select();
        });
      });
      document.querySelectorAll('.kitting-inline-edit-form').forEach(editForm=>{
        const actionCell=editForm.closest('.kitting-row-actions');
        const scheduleRow=actionCell?.closest('tr');
        const performanceRow=scheduleRow?.nextElementSibling;
        const editBtn=editForm.querySelector('.kitting-edit-btn');
        const saveBtn=editForm.querySelector('.kitting-save-btn');
        const cancelBtn=editForm.querySelector('.kitting-cancel-btn');
        if(!scheduleRow||!performanceRow||!editBtn||!saveBtn||!cancelBtn)return;
        const rows=[scheduleRow,performanceRow];
        const inputs=rows.flatMap(row=>Array.from(row.querySelectorAll('.kitting-cell-input')));
        let original=[];
        function setEditing(editing){
          rows.forEach(row=>row.classList.toggle('kitting-inline-editing',editing));
          inputs.forEach(input=>{input.disabled=!editing;});
          editBtn.hidden=editing;saveBtn.hidden=!editing;cancelBtn.hidden=!editing;
          if(editing&&inputs.length)inputs[0].focus();
        }
        editBtn.addEventListener('click',()=>{original=inputs.map(input=>input.value);setEditing(true);});
        cancelBtn.addEventListener('click',()=>{inputs.forEach((input,index)=>{input.value=original[index]??input.value;});setEditing(false);});
        editForm.addEventListener('submit',event=>{if(!confirm('Save all Schedule and Performance hourly values for this model?'))event.preventDefault();});
      });
      const modal=document.getElementById('kitting-register-modal');
      if(modal){document.body.classList.add('kitting-modal-open');document.body.style.overflow='hidden';const firstField=modal.querySelector('input:not([type=hidden]),select');if(firstField)firstField.focus();modal.addEventListener('click',event=>{if(event.target===modal){window.location.href='?process='+encodeURIComponent(<?=json_encode($key)?>)+'&date='+encodeURIComponent(<?=json_encode($kDate)?>);}});document.addEventListener('keydown',event=>{if(event.key==='Escape'){window.location.href='?process='+encodeURIComponent(<?=json_encode($key)?>)+'&date='+encodeURIComponent(<?=json_encode($kDate)?>);}});}
    })();
    </script>
    <?php require __DIR__ . '/includes/footer.php'; exit;
}

$label=PROCESS_LIST[$key]['label'];
$error=$notice=''; $rows=[]; $models=[]; $machines=[]; $edit=null; $machineSelection=''; $cutKeyColumns=[];
try {
 {
  $csv=read_process_master_data($key);
  $headers=$csv['headers'];
  $norm=fn($v)=>strtolower(preg_replace('/[^a-z0-9]/i','',(string)$v));
  $modelNameCol=$partNameCol=$branchCol=$barCodeCol=null;
  $modelCodeCol=$tubeTypeCol=$wireTypeCol=$corrTubeCol=null;
  $tubeColorCol=$wireColorCol=$vinylLengthCol=$wireDimensionCol=$tubeLengthCol=null;
  foreach($headers as $i=>$h){
   $n=$norm($h);
   if($n==='modelname') $modelNameCol=$i;
   elseif($n==='partname') $partNameCol=$i;
   elseif($n==='branch') $branchCol=$i;
   elseif($n==='barcode') $barCodeCol=$i;
   elseif($n==='modelcode') $modelCodeCol=$i;
   elseif($n==='tubetype') $tubeTypeCol=$i;
   elseif($n==='wiretype') $wireTypeCol=$i;
   elseif($n==='corrtube') $corrTubeCol=$i;
   elseif($n==='tubecolor') $tubeColorCol=$i;
   elseif($n==='wirecolor') $wireColorCol=$i;
   elseif($n==='vinyllength') $vinylLengthCol=$i;
   elseif($n==='wiredimension') $wireDimensionCol=$i;
   elseif($n==='tubelength') $tubeLengthCol=$i;
  }
  if($modelNameCol===null || $partNameCol===null || $branchCol===null || $barCodeCol===null){
   throw new RuntimeException('CSV headers required: ModelName, PartName, Branch, BarCode. Found: '.implode(', ', $headers));
  }
  foreach($csv['rows'] as $r){
   $modelName=trim((string)($r[$modelNameCol]??''));
   if($modelName==='') continue;
   $partName=trim((string)($r[$partNameCol]??''));
   $branch=trim((string)($r[$branchCol]??''));
   $barCode=trim((string)($r[$barCodeCol]??''));
   $modelCode=trim((string)($modelCodeCol!==null?($r[$modelCodeCol]??''):''));
   $tubeType=trim((string)($tubeTypeCol!==null?($r[$tubeTypeCol]??''):''));
   $wireType=trim((string)($wireTypeCol!==null?($r[$wireTypeCol]??''):''));
   $corrTube=trim((string)($corrTubeCol!==null?($r[$corrTubeCol]??''):''));
   $type=$tubeType!==''?'TUBE':($wireType!==''?'WIRE':($corrTube!==''?'Corr Tube':''));
   $color=$type==='TUBE'
    ? trim((string)($tubeColorCol!==null?($r[$tubeColorCol]??''):''))
    : ($type==='WIRE'
      ? trim((string)($wireColorCol!==null?($r[$wireColorCol]??''):''))
      : ($type==='Corr Tube'?'N/A':''));
   $length=$type==='TUBE'
    ? trim((string)($vinylLengthCol!==null?($r[$vinylLengthCol]??''):''))
    : ($type==='WIRE'
      ? trim((string)($wireDimensionCol!==null?($r[$wireDimensionCol]??''):''))
      : ($type==='Corr Tube'
        ? trim((string)($tubeLengthCol!==null?($r[$tubeLengthCol]??''):''))
        : ''));
   $choiceKey=$modelName."\\x1F".$partName."\\x1F".$branch."\\x1F".$barCode;
   $models[$choiceKey]=[
    'model_name'=>$modelName,
    'part_name'=>$partName,
    'branch'=>$branch,
    'barcode'=>$barCode,
    'model_code'=>$modelCode,
    'type'=>$type,
    'color'=>$color,
    'length'=>$length
   ];
  }
 }
 // Discover a real primary/unique key so Cut & Crimp rows can be edited/deleted
 // individually, including when two production rows otherwise look identical.
 if ($key === 'cut') {
  $keyMeta = db()->query("SELECT i.name AS index_name, i.is_primary_key, i.index_id,
      ic.key_ordinal, c.name AS column_name
    FROM sys.indexes i
    INNER JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id
    INNER JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id
    WHERE i.object_id=OBJECT_ID(N'dbo.TB_CUTPRESSURE')
      AND i.is_unique=1 AND i.is_disabled=0 AND i.is_hypothetical=0
      AND i.has_filter=0 AND ic.is_included_column=0 AND ic.key_ordinal>0
    ORDER BY i.is_primary_key DESC, i.index_id, ic.key_ordinal")->fetchAll(PDO::FETCH_ASSOC);
  if ($keyMeta) {
   $chosenIndex = (string)$keyMeta[0]['index_name'];
   foreach ($keyMeta as $metaRow) {
    if ((string)$metaRow['index_name'] !== $chosenIndex) break;
    $cutKeyColumns[] = (string)$metaRow['column_name'];
   }
  }
 }
 if($_SERVER['REQUEST_METHOD']==='POST'){verify_csrf();$a=(string)($_POST['action']??'');if($a==='add_machine'){
  $newMachine=trim((string)($_POST['new_machine']??''));
  if($newMachine==='') throw new RuntimeException('Enter a machine name first.');
  if((function_exists('mb_strlen')?mb_strlen($newMachine,'UTF-8'):strlen($newMachine))>100) throw new RuntimeException('Machine name must not exceed 100 characters.');
  $check=db()->prepare('SELECT TOP 1 id FROM dbo.machine WHERE Machine=? AND Process=?');
  $check->execute([$newMachine,$key]);
  if($check->fetch()) { $notice='This machine already exists for this process.'; $machineSelection=$newMachine; }
  else {
   $ins=db()->prepare('INSERT INTO dbo.machine(Machine,Process) VALUES(?,?)');
   $ins->execute([$newMachine,$key]);
   $notice='Machine added successfully.';
   $machineSelection=$newMachine;
   audit_log('CREATE','machine',null,'Added machine '.$newMachine.' for process '.$key);
  }
 }elseif($a==='delete_machine'){
  $machineId=(int)($_POST['machine_id']??0);
  if($machineId<=0) throw new RuntimeException('Invalid machine selected for deletion.');
  $del=db()->prepare('DELETE FROM dbo.machine WHERE id=? AND Process=?');
  $del->execute([$machineId,$key]);
  if($del->rowCount()>0){
   $notice='Machine deleted successfully.';
   audit_log('DELETE','machine',$machineId,'Deleted machine ID '.$machineId.' for process '.$key);
  } else {
   $notice='Machine was not found for this process.';
  }
 } elseif ($a === 'edit_cut_details') {
  if ($key !== 'cut' || !$cutKeyColumns) throw new RuntimeException('Cut & Crimp record editing is unavailable because no unique row key is configured.');
  $rowToken=trim((string)($_POST['cut_row_token']??''));
  $decoded=base64_decode(strtr($rowToken,'-_','+/'),true);
  $keyValues=$decoded===false?null:json_decode($decoded,true);
  if(!is_array($keyValues)||count($keyValues)!==count($cutKeyColumns)) throw new RuntimeException('Invalid Cut & Crimp row selected. Please reopen the Edit window.');
  $machine=trim((string)($_POST['machine_no']??''));
  $shift=trim((string)($_POST['shift_name']??''));
  $start=trim((string)($_POST['start_time']??''));
  $end=trim((string)($_POST['end_time']??''));
  $plannedRaw=trim((string)($_POST['planned_qty']??''));
  $actualRaw=trim((string)($_POST['actual_qty']??''));
  if($machine==='') throw new RuntimeException('Select a Machine No.');
  $machineCheck=db()->prepare('SELECT TOP 1 id FROM dbo.machine WHERE Machine=? AND Process=?');
  $machineCheck->execute([$machine,'cut']);
  if(!$machineCheck->fetchColumn()) throw new RuntimeException('Please choose a Machine No. from the registered machine list.');
  if(!in_array($shift,['Day','Night'],true)) throw new RuntimeException('Select Day or Night.');
  foreach([$start,$end] as $time) if($time!=='' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$time)) throw new RuntimeException('Enter valid Start Time and End Time.');
  if($plannedRaw===''||!ctype_digit($plannedRaw)||$actualRaw===''||!ctype_digit($actualRaw)) throw new RuntimeException('Planned Qty and Actual Qty must be non-negative whole numbers.');
  $start=$start?:'00:00'; $end=$end?:'00:00';
  [$sh,$sm]=array_map('intval',explode(':',$start)); [$eh,$em]=array_map('intval',explode(':',$end));
  $updateValues=['MACHINE_NO'=>$machine,'DAY_NIGHT'=>$shift==='Night'?1:0,'START_TIME_H'=>$sh,'START_TIME_M'=>$sm,'ENDY_TIME_H'=>$eh,'ENDY_TIME_M'=>$em,'END_TIME_H'=>$eh,'END_TIME_M'=>$em,'QTY_YOTEI'=>(int)$plannedRaw,'QTY_JISSEKI'=>(int)$actualRaw];
  $setParts=[];$params=[];
  foreach($updateValues as $column=>$value){$setParts[]='['.str_replace(']',']]',$column).']=?';$params[]=$value;}
  $whereParts=[];
  foreach($cutKeyColumns as $index=>$column){$whereParts[]='['.str_replace(']',']]',$column).']=?';$params[]=$keyValues[$index];}
  $upd=db()->prepare('UPDATE dbo.TB_CUTPRESSURE SET '.implode(', ',$setParts).' WHERE '.implode(' AND ',$whereParts));
  $upd->execute($params);
  if($upd->rowCount()<1) throw new RuntimeException('The selected Cut & Crimp record was not updated. It may have changed or been deleted.');
  audit_log('UPDATE','TB_CUTPRESSURE',null,'Updated Cut & Crimp production details');
  $notice='Cut & Crimp record updated successfully.';
 } elseif ($a === 'save') {
  $id=(int)($_POST['id']??0);
  $code=trim((string)($_POST['model_code']??''));
  $name=trim((string)($_POST['model_name']??'')); // Part Name from the selected CSV row
  $planned=max(0,(int)($_POST['planned_qty']??0));
  $actual=max(0,(int)($_POST['actual_qty']??0));
  if($code==='') throw new RuntimeException('Please select a Model Name from the CSV list.');
  $machine=trim((string)($_POST['machine_no']??''));
  $shift=trim((string)($_POST['shift_name']??''));
  $start=trim((string)($_POST['start_time']??''));
  $end=trim((string)($_POST['end_time']??''));
  $notes=trim((string)($_POST['notes']??''));
  if($key==='cut') {
   if (!$cutKeyColumns) throw new RuntimeException('TB_CUTPRESSURE has no primary key or unfiltered unique index. Add a unique row identifier before editing records.');
   // Build MODELNAME from the selected CSV record, not from editable browser values.
   $selectedModel = null;
   foreach ($models as $modelDetails) {
    if ($modelDetails['model_name'] === $code && $modelDetails['part_name'] === $name) {
     $selectedModel = $modelDetails;
     break;
    }
   }
   if ($selectedModel === null) {
    throw new RuntimeException('The selected Model Name and Part Name were not found in the configured CSV. Please select the model again.');
   }
   $name = 'Model: ' . $selectedModel['model_code']
    . '| PartName: ' . $selectedModel['part_name']
    . '| Type: ' . $selectedModel['type']
    . '| Color: 000' . $selectedModel['color']
    . '| Length: 0' . $selectedModel['length'];
   if(!in_array($shift,['Day','Night'],true)) throw new RuntimeException('Select Day or Night.');
   if($machine==='') throw new RuntimeException('Select a machine.');
   $machineCheck=db()->prepare('SELECT TOP 1 id FROM dbo.machine WHERE Machine=? AND Process=?');
   $machineCheck->execute([$machine,$key]);
   if(!$machineCheck->fetchColumn()) throw new RuntimeException('Please choose a Machine No. from the registered machine list.');
   foreach([$start,$end] as $time) {
    if($time!=='' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$time)) throw new RuntimeException('Invalid time.');
   }
   $start=$start?:'00:00'; $end=$end?:'00:00';
   [$sh,$sm]=array_map('intval',explode(':',$start));
   [$eh,$em]=array_map('intval',explode(':',$end));
   // The original SQL table has ENDY_TIME_* and END_TIME_* columns.
   // Both are populated so the original application can read the record.
   $cutValues = [
    'MODELCD'=>$code, 'MODELNAME'=>$name, 'YOTEI_DATE'=>$date,
    'MACHINE_NO'=>$machine, 'DAY_NIGHT'=>$shift==='Night'?1:0,
    'START_TIME_H'=>$sh, 'START_TIME_M'=>$sm,
    'ENDY_TIME_H'=>$eh, 'ENDY_TIME_M'=>$em,
    'END_TIME_H'=>$eh, 'END_TIME_M'=>$em,
    'QTY_YOTEI'=>$planned, 'QTY_JISSEKI'=>$actual
   ];
   $rowToken = trim((string)($_POST['cut_row_token'] ?? ''));
   if ($rowToken !== '') {
    $decoded = base64_decode(strtr($rowToken, '-_', '+/'), true);
    $keyValues = $decoded === false ? null : json_decode($decoded, true);
    if (!is_array($keyValues) || count($keyValues) !== count($cutKeyColumns)) throw new RuntimeException('Invalid row selected for editing. Please reopen the record.');
    $setParts=[]; $updateParams=[];
    foreach ($cutValues as $column=>$value) {
     if (in_array($column,$cutKeyColumns,true)) continue;
     $setParts[]='['.$column.']=?'; $updateParams[]=$value;
    }
    $whereParts=[];
    foreach ($cutKeyColumns as $index=>$column) {
     $whereParts[]='['.$column.']=?'; $updateParams[]=$keyValues[$index];
    }
    $upd=db()->prepare('UPDATE dbo.TB_CUTPRESSURE SET '.implode(', ',$setParts).' WHERE '.implode(' AND ',$whereParts));
    $upd->execute($updateParams);
    if ($upd->rowCount() < 1) throw new RuntimeException('The selected Cut & Crimp record could not be updated. It may have been changed or deleted.');
    audit_log('UPDATE','TB_CUTPRESSURE',null,'Updated Cut & Crimp plan '.$code);
   } else {
    // Prevent registering the same Model Code twice for the same production date.
    $duplicateCheck = db()->prepare("SELECT TOP 1 1 FROM dbo.TB_CUTPRESSURE
      WHERE MODELCD = ?
        AND YOTEI_DATE >= CAST(? AS date)
        AND YOTEI_DATE < DATEADD(day, 1, CAST(? AS date))");
    $duplicateCheck->execute([$code, $date, $date]);
    if ($duplicateCheck->fetchColumn()) {
     throw new RuntimeException('Model already registered');
    }
    $insertColumns=array_keys($cutValues);
    $sql='INSERT INTO dbo.TB_CUTPRESSURE ('.implode(',',array_map(static fn($c)=>'['.$c.']',$insertColumns)).') VALUES ('.implode(',',array_fill(0,count($insertColumns),'?')).')';
    db()->prepare($sql)->execute(array_values($cutValues));
    audit_log('CREATE','TB_CUTPRESSURE',null,'Created Cut & Crimp plan '.$code);
   }
  } elseif ($id) {
   $st=db()->prepare('UPDATE dbo.web_production_plan SET model_code=?,model_name=?,machine_no=?,shift_name=?,start_time=?,end_time=?,planned_qty=?,actual_qty=?,notes=?,updated_by=?,updated_at=SYSDATETIME() WHERE id=? AND process_key=?');
   $st->execute([$code,$name,$machine===''?null:$machine,$shift?:null,$start?:null,$end?:null,$planned,$actual,$notes?:null,current_user()['username'],$id,$key]);
   audit_log('UPDATE','production_plan',$id,'Updated '.$label.' plan '.$code);
  } else {
   $st=db()->prepare('INSERT INTO dbo.web_production_plan(process_key,plan_date,model_code,model_name,machine_no,shift_name,start_time,end_time,planned_qty,actual_qty,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
   $st->execute([$key,$date,$code,$name,$machine===''?null:$machine,$shift?:null,$start?:null,$end?:null,$planned,$actual,$notes?:null,current_user()['username']]);
   audit_log('CREATE','production_plan',null,'Created '.$label.' plan '.$code);
  }
  $notice='Plan saved.';
 } elseif ($a==='delete') {
  if($key==='cut') {
   if (!$cutKeyColumns) throw new RuntimeException('TB_CUTPRESSURE has no primary key or unfiltered unique index. Add a unique row identifier before deleting records.');
   $rowToken=trim((string)($_POST['cut_row_token']??''));
   $decoded=base64_decode(strtr($rowToken,'-_','+/'),true);
   $keyValues=$decoded===false?null:json_decode($decoded,true);
   if(!is_array($keyValues)||count($keyValues)!==count($cutKeyColumns)) throw new RuntimeException('Invalid Cut & Crimp row selected for deletion.');
   $whereParts=[];$params=[];
   foreach($cutKeyColumns as $index=>$column){$whereParts[]='['.$column.']=?';$params[]=$keyValues[$index];}
   $del=db()->prepare('DELETE FROM dbo.TB_CUTPRESSURE WHERE '.implode(' AND ',$whereParts));
   $del->execute($params);
   if($del->rowCount()<1) throw new RuntimeException('The selected Cut & Crimp record was not found.');
   audit_log('DELETE','TB_CUTPRESSURE',null,'Deleted Cut & Crimp record');
  } else {
   $id=(int)($_POST['id']??0);
   db()->prepare('DELETE FROM dbo.web_production_plan WHERE id=? AND process_key=?')->execute([$id,$key]);
   audit_log('DELETE','production_plan',$id,'Deleted '.$label.' plan');
  }
  $notice='Plan deleted.';
 }
}
 $machineSt=db()->prepare('SELECT id, Machine FROM dbo.machine WHERE Process=? ORDER BY Machine ASC');$machineSt->execute([$key]);$machines=$machineSt->fetchAll(PDO::FETCH_ASSOC);
 if($key==='cut') {
  $keySelect = '';
  foreach ($cutKeyColumns as $keyIndex=>$keyColumn) {
   $safeColumn = str_replace(']', ']]', $keyColumn);
   $keySelect .= ', ['.$safeColumn.'] AS [__rowkey_'.$keyIndex.']';
  }
  $st=db()->prepare("SELECT
   MODELCD AS model_code,
   MODELNAME AS model_name
   {$keySelect}
   , MACHINE_NO AS machine_no,
   CASE WHEN DAY_NIGHT=0 THEN 'Day' ELSE 'Night' END AS shift_name,
   CONCAT(RIGHT(CONCAT('0',COALESCE(START_TIME_H,0)),2),':',RIGHT(CONCAT('0',COALESCE(START_TIME_M,0)),2)) AS start_time,
   CONCAT(RIGHT(CONCAT('0',COALESCE(END_TIME_H,ENDY_TIME_H,0)),2),':',RIGHT(CONCAT('0',COALESCE(END_TIME_M,ENDY_TIME_M,0)),2)) AS end_time,
   COALESCE(QTY_YOTEI,0) AS planned_qty,
   COALESCE(QTY_JISSEKI,0) AS actual_qty
   FROM dbo.TB_CUTPRESSURE
   WHERE YOTEI_DATE >= CAST(? AS date) AND YOTEI_DATE < DATEADD(day,1,CAST(? AS date))
   ORDER BY START_TIME_H,START_TIME_M,MODELCD");
  $st->execute([$date,$date]);
 } else {
  $st=db()->prepare('SELECT * FROM dbo.web_production_plan WHERE process_key=? AND plan_date=? ORDER BY start_time,model_code');
  $st->execute([$key,$date]);
 }
 $rows=$st->fetchAll(PDO::FETCH_ASSOC);
 if($key==='cut' && $cutKeyColumns) {
  foreach($rows as &$cutRow) {
   $keyValues=[];
   foreach($cutKeyColumns as $keyIndex=>$keyColumn) $keyValues[]=$cutRow['__rowkey_'.$keyIndex]??null;
   $json=json_encode($keyValues,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
   $cutRow['row_token']=rtrim(strtr(base64_encode((string)$json),'+/','-_'),'=');
   foreach(array_keys($cutRow) as $colName) if(str_starts_with((string)$colName,'__rowkey_')) unset($cutRow[$colName]);
  }
  unset($cutRow);
 }
 if($key!=='cut' && isset($_GET['edit'])) foreach($rows as $r) if((int)$r['id']===(int)$_GET['edit']) $edit=$r;
} catch(Throwable $ex){$error=$ex->getMessage();}
$e=$edit??[]; $planned=array_sum(array_map(fn($r)=>(int)$r['planned_qty'],$rows));$actual=array_sum(array_map(fn($r)=>(int)$r['actual_qty'],$rows));
?><?php $pageTitle=PROCESS_LIST[process_key()]['label']; $activePage='production'; $currentProcess=process_key(); require __DIR__.'/includes/header.php'; ?><section class="content"><div class="page-intro"><div><h2>Daily production plan</h2><p>Model suggestions are read from this process's live CSV source.</p></div></div><div class="modules"><?php foreach(PROCESS_LIST as $k=>$p):?><a class="<?=$k===$key?'sel':''?>" href="?process=<?=h($k)?>&date=<?=h($date)?>"><?=h($p['label'])?></a><?php endforeach;?></div><?php if($notice):?><div class="notice notice-success"><?=h($notice)?></div><?php endif;?><?php if($error):?><div class="notice notice-error model-duplicate-error"><?=h($error)?></div><?php endif;?><?php if($key==='cut' && !$cutKeyColumns):?><div class="notice notice-error">Edit/Delete are unavailable because dbo.TB_CUTPRESSURE has no primary key or unfiltered unique index. Add a unique row identifier to enable safe record-level actions.</div><?php endif;?><section class="panel"><div class="panel-head cut-register-panel-head"><div><h3><?= $edit?'Edit plan':'Register plan'?></h3><p><?=count($models)?> models/parts available from CSV</p></div><form method="get" class="cut-date-form"><input type="hidden" name="process" value="<?=h($key)?>"><input type="date" name="date" value="<?=h($date)?>" onchange="this.form.submit()" aria-label="Schedule date"></form></div><form method="post" class="grid production-plan-grid"><?=csrf_field()?><input type="hidden" name="action" value="save"><input type="hidden" name="process" value="<?=h($key)?>"><input type="hidden" name="plan_date" value="<?=h($date)?>"><input type="hidden" name="id" value="<?=h($e['id']??'')?>"><?php if($key==='cut' && $edit):?><input type="hidden" name="cut_row_token" value="<?=h($e['row_token']??'')?>"><?php endif;?><label class="model-combo-label">Model Name<div class="model-combo-wrap"><input id="model_name_combo" type="text" required autocomplete="off" aria-autocomplete="list" aria-expanded="false" aria-controls="model-options" placeholder="Select or search a model name" value="<?=h($e['model_code']??'')?>"><input type="hidden" id="model_code" name="model_code" value="<?=h($e['model_code']??'')?>"><input type="hidden" id="model_name" name="model_name" value="<?=h($e['model_name']??'')?>"><button type="button" class="model-combo-toggle" id="model_combo_toggle" aria-label="Show model names">▾</button><div class="model-combo-options" id="model-options" role="listbox" hidden><?php foreach($models as $details):?><button type="button" class="model-combo-option" data-model-name="<?=h($details['model_name'])?>" data-part-name="<?=h($details['part_name'])?>" data-branch="<?=h($details['branch'])?>" data-barcode="<?=h($details['barcode'])?>" data-model-code="<?=h($details['model_code'])?>" data-type="<?=h($details['type'])?>" data-color="<?=h($details['color'])?>" data-length="<?=h($details['length'])?>"><strong><?=h($details['model_name'])?></strong><span><?=h($details['part_name'])?> - Branch <?=h($details['branch'])?></span></button><?php endforeach;?></div></div></label><label>Part Name<input id="part_name_display" type="text" value="" readonly tabindex="-1"></label><label>Branch<input id="branch_display" type="text" value="" readonly tabindex="-1"></label><label>BarCode<input id="barcode_display" type="text" value="" readonly tabindex="-1"></label><label>Model<input id="model_code_display" type="text" value="" readonly tabindex="-1"></label><label>Type<input id="type_display" type="text" value="" readonly tabindex="-1"></label><label>Color<input id="color_display" type="text" value="" readonly tabindex="-1"></label><label>Length<input id="length_display" type="text" value="" readonly tabindex="-1"></label><label class="machine-picker-label">Machine No.<div class="machine-picker-wrap"><input type="text" id="machine_no_display" name="machine_no" required autocomplete="off" placeholder="Select Machine" value="<?=h($e['machine_no']??$machineSelection)?>" aria-expanded="false" aria-controls="machine-options"><button type="button" class="machine-picker-toggle" id="machine_picker_toggle" aria-label="Show machines">▾</button><div class="machine-picker-options" id="machine-options" hidden><div class="machine-picker-list" id="machine-picker-list"><button type="button" class="machine-picker-option machine-picker-placeholder" data-machine="">Select Machine</button><?php foreach($machines as $machineOption):?><div class="machine-picker-row"><button type="button" class="machine-picker-option" data-machine="<?=h($machineOption['Machine'])?>"><?=h($machineOption['Machine'])?></button><button type="button" class="machine-picker-delete" data-machine-id="<?=(int)$machineOption['id']?>" data-machine-name="<?=h($machineOption['Machine'])?>" aria-label="Delete <?=h($machineOption['Machine'])?>" title="Delete machine">−</button></div><?php endforeach;?></div><div class="machine-picker-add"><input type="text" id="new_machine" name="new_machine" maxlength="100" placeholder="Type new machine name"><button type="button" class="btn btn-primary btn-small" id="add_machine_btn">Add</button></div></div></div><input type="hidden" name="machine_id" id="machine_id_to_delete" value=""></label><label>Shift<select name="shift_name" required><option value="">Select</option><?php foreach(['Day','Night'] as $v):?><option <?=($e['shift_name']??'')===$v?'selected':''?>><?=h($v)?></option><?php endforeach;?></select></label><label>Start Time<input type="time" name="start_time" value="<?=h(isset($e['start_time'])?substr((string)$e['start_time'],0,5):'')?>"></label><label>End Time<input type="time" name="end_time" value="<?=h(isset($e['end_time'])?substr((string)$e['end_time'],0,5):'')?>"></label><label>Planned Qty<input type="number" min="0" name="planned_qty" value="<?=h($e['planned_qty']??0)?>"></label><label>Actual Qty<input type="number" min="0" name="actual_qty" value="<?=h($e['actual_qty']??0)?>"></label><?php if($key!=='cut'):?><label class="wide">Remarks<input name="notes" value="<?=h($e['notes']??'')?>"></label><?php endif;?><div class="wide"><button class="btn btn-primary" id="save_plan_btn" type="submit" disabled><?= $edit ? 'Update Plan' : 'Save Plan' ?></button> <?php if($edit):?><a class="btn btn-ghost" href="?process=<?=h($key)?>&date=<?=h($date)?>">Cancel</a><?php endif;?></div></form></section><div class="stats-grid stats-grid-csv"><article class="stat-card"><span>Records</span><div class="stat-value"><?=count($rows)?></div></article><article class="stat-card"><span>Planned</span><div class="stat-value"><?=number_format($planned)?></div></article><article class="stat-card"><span>Actual</span><div class="stat-value"><?=number_format($actual)?></div></article><article class="stat-card"><span>Remaining</span><div class="stat-value"><?=number_format(max(0,$planned-$actual))?></div></article></div><section class="panel data-panel"><div class="panel-head daily-records-head"><h3>Daily records — <?=h($date)?></h3><div class="table-search-wrap"><input type="search" id="daily_records_search" class="table-search-input" placeholder="Search model code, model name, machine..." autocomplete="off" aria-label="Search daily records"><span class="table-search-count" id="daily_records_search_count"><?=count($rows)?> records</span></div></div><div class="table-wrap"><table class="admin-table" id="daily_records_table"><thead><tr><th>Model Code</th><th>Model Name</th><th>Machine</th><th>Shift</th><th>Time</th><th>Planned</th><th>Actual</th><th>Remaining</th><th>Action</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=h($r['model_code'])?></td><td><?php if($key==='cut'){$displayPart='';$displayModel='';if(preg_match('/Model:\s*([^|]*)/u',(string)$r['model_name'],$dm))$displayModel=trim($dm[1]);if(preg_match('/PartName:\s*([^|]*)/u',(string)$r['model_name'],$dp))$displayPart=trim($dp[1]);echo h('PartName: '.$displayPart.' | Model: '.$displayModel);}else{echo h($r['model_name']);}?></td><td><?=h($r['machine_no'])?></td><td><?=h($r['shift_name'])?></td><td><?=h(substr((string)$r['start_time'],0,5))?>–<?=h(substr((string)$r['end_time'],0,5))?></td><td><?=number_format((int)$r['planned_qty'])?></td><td><?=number_format((int)$r['actual_qty'])?></td><td><?=number_format(max(0,(int)$r['planned_qty']-(int)$r['actual_qty']))?></td><td><?php if($key==='cut'):?><?php if($cutKeyColumns):?><button type="button" class="btn btn-secondary btn-small action-icon-btn cut-edit-open" data-row-token="<?=h($r['row_token'])?>" data-machine="<?=h($r['machine_no'])?>" data-shift="<?=h($r['shift_name'])?>" data-start="<?=h(substr((string)$r['start_time'],0,5))?>" data-end="<?=h(substr((string)$r['end_time'],0,5))?>" data-planned="<?=(int)$r['planned_qty']?>" data-actual="<?=(int)$r['actual_qty']?>" title="Edit" aria-label="Edit"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg></button> <form method="post" style="display:inline" onsubmit="return confirm('Delete this Cut & Crimp record?')"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="process" value="<?=h($key)?>"><input type="hidden" name="plan_date" value="<?=h($date)?>"><input type="hidden" name="cut_row_token" value="<?=h($r['row_token'])?>"><button class="btn btn-ghost btn-small action-icon-btn delete-action-btn" title="Delete" aria-label="Delete"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="m19 6-1 14H6L5 6"/><path d="M10 11v5M14 11v5"/></svg></button></form><?php else:?>—<?php endif;?><?php else:?><a class="btn btn-secondary btn-small action-icon-btn" href="?process=<?=h($key)?>&date=<?=h($date)?>&edit=<?=(int)$r['id']?>" title="Edit" aria-label="Edit"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg></a><form method="post" style="display:inline" onsubmit="return confirm('Delete this plan?')"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="process" value="<?=h($key)?>"><input type="hidden" name="plan_date" value="<?=h($date)?>"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="btn btn-ghost btn-small action-icon-btn delete-action-btn" title="Delete" aria-label="Delete"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="m19 6-1 14H6L5 6"/><path d="M10 11v5M14 11v5"/></svg></button></form><?php endif;?></td></tr><?php endforeach;?><?php if(!$rows):?><tr><td colspan="9">No plan records for this date.</td></tr><?php endif;?></tbody></table></div></section><?php if($key==='cut' && $cutKeyColumns):?><div class="cut-edit-modal-backdrop" id="cut_edit_modal" hidden><section class="cut-edit-modal" role="dialog" aria-modal="true" aria-labelledby="cut_edit_title"><div class="cut-edit-modal-head"><div><h3 id="cut_edit_title">Edit Cut &amp; Crimp Record</h3><p>Only production details can be changed. Model and Part Name remain locked.</p></div><button type="button" class="cut-edit-close" aria-label="Close">&times;</button></div><form method="post" id="cut_edit_form"><?=csrf_field()?><input type="hidden" name="action" value="edit_cut_details"><input type="hidden" name="process" value="cut"><input type="hidden" name="plan_date" value="<?=h($date)?>"><input type="hidden" name="cut_row_token" id="cut_edit_row_token"><div class="cut-edit-grid"><label>Machine No.<select name="machine_no" id="cut_edit_machine" required><option value="">Select Machine</option><?php foreach($machines as $machineOption):?><option value="<?=h($machineOption['Machine'])?>"><?=h($machineOption['Machine'])?></option><?php endforeach;?></select></label><label>Shift<select name="shift_name" id="cut_edit_shift" required><option value="">Select</option><option value="Day">Day</option><option value="Night">Night</option></select></label><label>Start Time<input type="time" name="start_time" id="cut_edit_start"></label><label>End Time<input type="time" name="end_time" id="cut_edit_end"></label><label>Planned Qty<input type="number" min="0" step="1" name="planned_qty" id="cut_edit_planned" required></label><label>Actual Qty<input type="number" min="0" step="1" name="actual_qty" id="cut_edit_actual" required></label></div><div class="cut-edit-actions"><button type="submit" class="btn btn-primary">Update Record</button></div></form></section></div><?php endif;?><p class="muted">Cut & Crimp records are read from and registered in TB_CUTPRESSURE; machine choices remain in dbo.machine. Cut & Crimp Edit/Delete uses the table primary key or an unfiltered unique index; Machine No. and Shift are required.</p></section><style>
.action-icon-btn{display:inline-flex!important;align-items:center;justify-content:center;width:36px;height:36px;padding:0!important;vertical-align:middle}.action-icon-btn svg{display:block}.action-icon-btn.delete-action-btn{width:36px;height:36px;padding:0!important;border:1px solid #f0b7bd!important;border-radius:9px!important;background:#fff!important;color:#dc3545!important}.action-icon-btn.delete-action-btn:hover{border-color:#dc3545!important;background:#fff1f2!important;color:#b42335!important}
.model-duplicate-error{background:#ffe7ec!important;color:#b42335!important;border:1px solid #f3a5b2!important;border-radius:8px;padding:11px 14px;font-weight:700}
.cut-edit-modal-backdrop{position:fixed;inset:0;z-index:200000;display:flex;align-items:center;justify-content:center;padding:18px;background:rgba(15,25,18,.48)}
.cut-edit-modal-backdrop[hidden]{display:none!important}
.cut-edit-modal{width:min(760px,100%);max-height:calc(100vh - 36px);overflow:auto;padding:22px;border-radius:15px;background:#fff;box-shadow:0 20px 60px rgba(0,0,0,.24)}
.cut-edit-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:20px}
.cut-edit-modal-head h3{margin:0 0 5px;font-size:19px}.cut-edit-modal-head p{margin:0;color:#6b786d;font-size:13px}
.cut-edit-close{width:34px;height:34px;border:0;border-radius:8px;background:#f1f4ef;color:#455345;font-size:24px;cursor:pointer;line-height:1}
.cut-edit-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px 12px}
.cut-edit-grid label{display:flex;flex-direction:column;gap:6px;min-width:0;font-size:13px;font-weight:600}
.cut-edit-grid input,.cut-edit-grid select{width:100%;min-width:0;box-sizing:border-box;height:43px;padding:9px 11px;border:1px solid #cbd6c7;border-radius:9px;background:#fff;color:#263329;font:inherit;font-weight:400}
.cut-edit-wide{grid-column:1/-1}
.cut-edit-actions{display:flex;justify-content:flex-end;gap:9px;margin-top:22px}
@media(max-width:560px){.cut-edit-modal{padding:16px}.cut-edit-grid{grid-template-columns:1fr}.cut-edit-wide{grid-column:auto}}
/* Cut & Crimp Register plan header date picker */
.cut-register-panel-head{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:16px}
.cut-register-panel-head>div{min-width:0}
.cut-register-panel-head .cut-date-form{display:flex!important;align-items:center;justify-content:center;flex:0 0 214px;width:214px;min-width:214px;margin:0 0 0 auto;padding:9px 10px;border:1px solid #dce5d8;border-radius:13px;background:#fff;box-shadow:0 2px 8px rgba(31,49,34,.06);box-sizing:border-box}
.cut-register-panel-head .cut-date-form input[type="date"]{display:block;width:100%;height:40px;min-height:40px;box-sizing:border-box;padding:7px 10px;border:1px solid #cbd6c7;border-radius:9px;background:#fbfcfa;color:#263329;font:inherit;font-size:13px;font-weight:500;cursor:pointer}
.cut-register-panel-head .cut-date-form input[type="date"]:focus{outline:none;border-color:#78966e;box-shadow:0 0 0 3px rgba(120,150,110,.14)}
@media(max-width:520px){.cut-register-panel-head{align-items:flex-start!important;flex-wrap:wrap}.cut-register-panel-head .cut-date-form{width:214px;max-width:100%;flex:0 1 214px}}
.model-combo-label{min-width:0}
.model-combo-wrap{position:relative;width:100%;min-width:0}
.model-combo-wrap>input{width:100%!important;padding-right:42px!important}
.model-combo-toggle{position:absolute;right:2px;top:2px;height:calc(100% - 4px);width:38px;border:0;background:transparent;color:#35463d;cursor:pointer;border-radius:6px;font-size:15px}
.model-combo-toggle:hover{background:#edf3e9}
.model-combo-options{position:fixed;z-index:99999;width:auto;max-height:280px;overflow-y:auto;overflow-x:hidden;background:#fff;border:1px solid #cbd6c7;border-radius:9px;box-shadow:0 10px 25px rgba(20,35,25,.18);padding:5px;box-sizing:border-box}
.model-combo-options[hidden]{display:none}
.model-combo-option[hidden]{display:none!important}
.model-combo-option{display:flex!important;width:100%;min-height:0;flex-direction:column;align-items:flex-start;justify-content:center;gap:4px;padding:9px 11px;border:0;border-radius:6px;background:#fff;color:#263329;text-align:left;white-space:normal;cursor:pointer;box-sizing:border-box}
.model-combo-option[hidden]{display:none!important}
.model-combo-option strong{display:block;font-size:13px;line-height:1.35;font-weight:700;color:#263329}
.model-combo-option span{display:block;font-size:12px;line-height:1.35;color:#65766b}
.model-combo-option:hover,.model-combo-option.active{background:#edf3e9}
.model-combo-empty{padding:12px;color:#718078;font-size:12px}
#part_name_display,#branch_display,#barcode_display,#model_code_display,#type_display,#color_display,#length_display{background:#eef1ed!important;color:#46534a!important;cursor:not-allowed}
.production-plan-grid{
  display:grid;
  grid-template-columns:repeat(4,minmax(0,1fr));
  gap:14px 10px;
  align-items:start;
}
.production-plan-grid>label{min-width:0;width:100%}
.production-plan-grid>label input,
.production-plan-grid>label select{width:100%;min-width:0;box-sizing:border-box}
.production-plan-grid>.wide{grid-column:1/-1}
.production-plan-grid>.model-combo-label{grid-column:auto}
@media(max-width:900px){
  .production-plan-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:560px){
  .production-plan-grid{grid-template-columns:minmax(0,1fr)}
  .production-plan-grid>.wide,
  .production-plan-grid>.model-combo-label{grid-column:1/-1}
} 

.machine-picker-label{min-width:0}
.machine-picker-wrap{position:relative;width:100%;min-width:0}
.machine-picker-wrap>input{width:100%!important;padding-right:42px!important}
.machine-picker-toggle{position:absolute;right:2px;top:2px;height:calc(100% - 4px);width:38px;border:0;background:transparent;color:#35463d;cursor:pointer;border-radius:6px}
.machine-picker-options{position:fixed;z-index:100000;width:auto;max-height:280px;overflow:auto;background:#fff;border:1px solid #cbd6c7;border-radius:9px;box-shadow:0 10px 25px rgba(20,35,25,.18);padding:5px;box-sizing:border-box}
.machine-picker-options[hidden]{display:none}
.machine-picker-row{display:flex;align-items:center;gap:5px;width:100%}
.machine-picker-option[hidden],.machine-picker-row[hidden]{display:none!important}
.machine-picker-option{display:block;flex:1;min-width:0;width:100%;padding:9px 11px;border:0;border-radius:6px;background:#fff;text-align:left;color:#263329;cursor:pointer}
.machine-picker-option:hover{background:#edf3e9}
.machine-picker-placeholder{color:#849087;font-style:italic;border-bottom:1px solid #e6ebe5;border-radius:4px;margin-bottom:3px}
.machine-picker-placeholder:hover{background:#f4f6f3}
.machine-picker-delete{flex:0 0 28px;width:28px;height:28px;border:0;border-radius:50%;background:#fff0f0;color:#c43c3c;font-size:20px;line-height:1;cursor:pointer}
.machine-picker-delete:hover{background:#c43c3c;color:#fff}

.machine-picker-add{display:flex;gap:7px;align-items:center;padding:8px 5px 5px;border-top:1px solid #e3e9e2;margin-top:5px}
.machine-picker-add input{flex:1;min-width:0;width:100%;padding:8px 10px}
.daily-records-head{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}
.table-search-wrap{display:flex;align-items:center;gap:10px;min-width:min(100%,320px)}
.table-search-input{width:min(360px,55vw);min-width:190px;box-sizing:border-box;padding:9px 12px;border:1px solid #cbd6c7;border-radius:8px;background:#fff;color:#263329;font:inherit;outline:none}
.table-search-input:focus{border-color:#78966e;box-shadow:0 0 0 3px rgba(120,150,110,.14)}
.table-search-count{font-size:12px;color:#718078;white-space:nowrap}
@media(max-width:560px){.table-search-wrap{width:100%;min-width:0}.table-search-input{width:100%;min-width:0}}
</style>
<script>
(function(){
 const modal=document.getElementById('cut_edit_modal');
 if(!modal)return;
 const form=document.getElementById('cut_edit_form');
 const closeButtons=modal.querySelectorAll('.cut-edit-close');
 const fields={token:document.getElementById('cut_edit_row_token'),machine:document.getElementById('cut_edit_machine'),shift:document.getElementById('cut_edit_shift'),start:document.getElementById('cut_edit_start'),end:document.getElementById('cut_edit_end'),planned:document.getElementById('cut_edit_planned'),actual:document.getElementById('cut_edit_actual')};
 function close(){modal.hidden=true;document.body.style.overflow='';}
 document.querySelectorAll('.cut-edit-open').forEach(button=>button.addEventListener('click',()=>{
  fields.token.value=button.dataset.rowToken||'';fields.machine.value=button.dataset.machine||'';fields.shift.value=button.dataset.shift||'';fields.start.value=button.dataset.start||'';fields.end.value=button.dataset.end||'';fields.planned.value=button.dataset.planned||'0';fields.actual.value=button.dataset.actual||'0';
  modal.hidden=false;document.body.style.overflow='hidden';fields.machine.focus();
 }));
 closeButtons.forEach(button=>button.addEventListener('click',close));
 modal.addEventListener('click',event=>{if(event.target===modal)close();});
 document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!modal.hidden)close();});
 form.addEventListener('submit',event=>{
  if(!fields.machine.value){event.preventDefault();fields.machine.setCustomValidity('Select a Machine No.');fields.machine.reportValidity();return;}fields.machine.setCustomValidity('');
  if(!fields.shift.value){event.preventDefault();fields.shift.setCustomValidity('Select Day or Night.');fields.shift.reportValidity();return;}fields.shift.setCustomValidity('');
  for(const field of [fields.planned,fields.actual]){if(!field.value.trim()||!/^\d+$/.test(field.value)){event.preventDefault();field.setCustomValidity('Enter a non-negative whole number.');field.reportValidity();return;}field.setCustomValidity('');}
 });
})();
</script><script>
(function () {
    const combo = document.getElementById('model_name_combo');
    const modelCode = document.getElementById('model_code');
    const modelName = document.getElementById('model_name');
    const partName = document.getElementById('part_name_display');
    const branch = document.getElementById('branch_display');
    const barcode = document.getElementById('barcode_display');
    const modelCodeDisplay = document.getElementById('model_code_display');
    const typeDisplay = document.getElementById('type_display');
    const colorDisplay = document.getElementById('color_display');
    const lengthDisplay = document.getElementById('length_display');
    const menu = document.getElementById('model-options');
    const toggle = document.getElementById('model_combo_toggle');
    const saveButton = document.getElementById('save_plan_btn');
    const planForm = combo ? combo.closest('form') : null;
    if (!combo || !modelCode || !modelName || !partName || !branch || !barcode ||
        !modelCodeDisplay || !typeDisplay || !colorDisplay || !lengthDisplay || !menu || !toggle || !saveButton || !planForm) return;

    // Saving is allowed only after choosing an actual CSV suggestion.
    function setModelSelected(selected) {
        saveButton.disabled = !selected;
        saveButton.setAttribute('aria-disabled', selected ? 'false' : 'true');
    }
    setModelSelected(false);

    const options = Array.from(menu.querySelectorAll('.model-combo-option'));
    let activeIndex = -1;

    // Render the popup at document level so panel/container overflow cannot crop it.
    document.body.appendChild(menu);

    function positionMenu() {
        if (menu.hidden) return;
        const rect = combo.getBoundingClientRect();
        const gap = 5;
        const viewportPadding = 8;
        const below = window.innerHeight - rect.bottom - gap - viewportPadding;
        const above = rect.top - gap - viewportPadding;
        const openBelow = below >= 150 || below >= above;
        const available = Math.max(110, Math.min(280, openBelow ? below : above));
        menu.style.left = Math.max(viewportPadding, rect.left) + 'px';
        menu.style.width = Math.min(rect.width, window.innerWidth - Math.max(viewportPadding, rect.left) - viewportPadding) + 'px';
        menu.style.maxHeight = available + 'px';
        if (openBelow) {
            menu.style.top = (rect.bottom + gap) + 'px';
        } else {
            menu.style.top = Math.max(viewportPadding, rect.top - gap - Math.min(280, above)) + 'px';
        }
    }

    function closeMenu() {
        menu.hidden = true;
        combo.setAttribute('aria-expanded', 'false');
        activeIndex = -1;
        options.forEach(o => o.classList.remove('active'));
    }

    function showMenu(query = '') {
        const q = query.trim().toLowerCase();
        activeIndex = -1;
        options.forEach(o => o.classList.remove('active'));
        let count = 0;
        options.forEach(o => {
            const haystack = [
                o.dataset.modelName, o.dataset.partName,
                'branch ' + o.dataset.branch, o.dataset.barcode
            ].join(' ').toLowerCase();
            o.hidden = !haystack.includes(q);
            if (!o.hidden) count++;
        });
        const empty = menu.querySelector('.model-combo-empty');
        if (empty) empty.remove();
        if (!count) {
            const msg = document.createElement('div');
            msg.className = 'model-combo-empty';
            msg.textContent = 'No matching model found';
            menu.appendChild(msg);
        }
        menu.hidden = false;
        combo.setAttribute('aria-expanded', 'true');
        positionMenu();
    }

    function choose(o) {
        const m = o.dataset.modelName || '';
        const p = o.dataset.partName || '';
        const b = o.dataset.branch || '';
        combo.value = m;
        modelCode.value = m;
        modelName.value = p;
        partName.value = p;
        branch.value = b ? 'Branch ' + b : '';
        barcode.value = o.dataset.barcode || '';
        modelCodeDisplay.value = o.dataset.modelCode || '';
        typeDisplay.value = o.dataset.type || '';
        colorDisplay.value = o.dataset.color || '';
        lengthDisplay.value = o.dataset.length || '';
        // A CSV choice is valid only when it has a real model code.
        setModelSelected(Boolean(modelCode.value.trim()));
        closeMenu();
    }

    options.forEach(o => {
        o.addEventListener('mousedown', e => e.preventDefault());
        o.addEventListener('click', () => choose(o));
    });
    combo.addEventListener('focus', () => showMenu(combo.value));
    combo.addEventListener('input', () => {
        modelCode.value = '';
        modelName.value = '';
        partName.value = '';
        branch.value = '';
        barcode.value = '';
        modelCodeDisplay.value = '';
        typeDisplay.value = '';
        colorDisplay.value = '';
        lengthDisplay.value = '';
        setModelSelected(false);
        showMenu(combo.value);
    });
    combo.addEventListener('keydown', e => {
        const visible = options.filter(o => !o.hidden);
        if (e.key === 'Escape') { closeMenu(); return; }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (menu.hidden) showMenu(combo.value);
            const nowVisible = options.filter(o => !o.hidden);
            if (!nowVisible.length) return;
            activeIndex = Math.max(0, Math.min(nowVisible.length - 1,
                activeIndex + (e.key === 'ArrowDown' ? 1 : -1)));
            nowVisible.forEach((o, i) => o.classList.toggle('active', i === activeIndex));
            nowVisible[activeIndex]?.scrollIntoView({block:'nearest'});
        }
        if (e.key === 'Enter' && !menu.hidden && activeIndex >= 0 && visible[activeIndex]) {
            e.preventDefault();
            choose(visible[activeIndex]);
        }
    });
    toggle.addEventListener('click', () => menu.hidden ? showMenu(combo.value) : closeMenu());
    planForm.addEventListener('submit', event => {
        if (!modelCode.value.trim() || !options.some(o => o.dataset.modelName === combo.value && o.dataset.modelCode === modelCodeDisplay.value)) {
            event.preventDefault();
            setModelSelected(false);
            combo.setCustomValidity('Please select a Model Name from the CSV list.');
            combo.reportValidity();
            combo.focus();
            showMenu(combo.value);
            return;
        }
        combo.setCustomValidity('');
    });
    combo.addEventListener('input', () => combo.setCustomValidity(''));
    document.addEventListener('click', e => {
        if (!e.target.closest('.model-combo-wrap') && !menu.contains(e.target)) closeMenu();
    });
    window.addEventListener('resize', positionMenu);
    document.addEventListener('scroll', positionMenu, true);

    const current = options.find(o => o.dataset.modelName === modelCode.value);
    if (current) choose(current);
})();
</script><script>
(function(){
 const input=document.getElementById('machine_no_display');
 const menu=document.getElementById('machine-options');
 const toggle=document.getElementById('machine_picker_toggle');
 const addInput=document.getElementById('new_machine');
 const addBtn=document.getElementById('add_machine_btn');
 const action=document.querySelector('input[name="action"]');
 const planForm=input?input.closest('form'):null;
 const deleteId=document.getElementById('machine_id_to_delete');
 if(!input||!menu||!toggle||!addInput||!addBtn||!action||!planForm||!deleteId)return;
 const options=Array.from(menu.querySelectorAll('.machine-picker-option'));
 document.body.appendChild(menu);
 function position(){
  if(menu.hidden)return;
  const r=input.getBoundingClientRect(), gap=5, pad=8;
  const below=window.innerHeight-r.bottom-gap-pad, above=r.top-gap-pad;
  const openBelow=below>=130||below>=above;
  const h=Math.max(110,Math.min(280,openBelow?below:above));
  menu.style.left=Math.max(pad,r.left)+'px';
  menu.style.width=Math.min(r.width,window.innerWidth-Math.max(pad,r.left)-pad)+'px';
  menu.style.maxHeight=h+'px';
  menu.style.top=(openBelow?r.bottom+gap:Math.max(pad,r.top-gap-h))+'px';
 }
 function show(){
  const q=input.value.trim().toLowerCase();
  options.forEach(o=>{
   const match=(o.dataset.machine||'').toLowerCase().includes(q);
   o.hidden=!match;
   const row=o.closest('.machine-picker-row');
   if(row) row.hidden=!match;
  });
  menu.hidden=false;
  input.setAttribute('aria-expanded','true');
  position();
 }
 function close(){menu.hidden=true;input.setAttribute('aria-expanded','false');}
 options.forEach(o=>{
  o.addEventListener('mousedown',e=>e.preventDefault());
  o.addEventListener('click',()=>{
   input.value=o.dataset.machine||'';
   close();
  });
 });
 menu.querySelectorAll('.machine-picker-delete').forEach(btn=>{
  btn.addEventListener('mousedown',e=>e.preventDefault());
  btn.addEventListener('click',e=>{
   e.preventDefault();
   e.stopPropagation();
   const machineName=btn.dataset.machineName||'this machine';
   if(!window.confirm('Delete '+machineName+' from this process?'))return;
   action.value='delete_machine';
   deleteId.value=btn.dataset.machineId;
   planForm.noValidate=true;
   planForm.requestSubmit();
  });
 });
 input.addEventListener('focus',show);
 input.addEventListener('input',show);
 toggle.addEventListener('click',()=>menu.hidden?show():close());
 addBtn.addEventListener('click',()=>{
  const value=addInput.value.trim();
  if(!value){addInput.focus();return;}
  action.value='add_machine';
  const form=planForm;
  let submittedValue=form.querySelector('input[name="new_machine"][type="hidden"]');
  if(!submittedValue){
   submittedValue=document.createElement('input');
   submittedValue.type='hidden';
   submittedValue.name='new_machine';
   form.appendChild(submittedValue);
  }
  submittedValue.value=value;
  form.noValidate=true;
  form.requestSubmit();
 });
 addInput.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();addBtn.click();}});
 document.addEventListener('click',e=>{
  if(!e.target.closest('.machine-picker-wrap')&&!menu.contains(e.target))close();
 });
 window.addEventListener('resize',position);
 document.addEventListener('scroll',position,true);
})();
</script>
<script>
(function(){
 const form=document.querySelector('form.production-plan-grid');
 const machine=document.getElementById('machine_no_display');
 const shift=form?.querySelector('select[name="shift_name"]');
 const save=document.getElementById('save_plan_btn');
 if(!form||!machine||!shift)return;
 const allowedMachines=new Set(Array.from(document.querySelectorAll('.machine-picker-option[data-machine]')).map(x=>x.dataset.machine).filter(Boolean));
 machine.required=true; shift.required=true;
 form.addEventListener('submit',function(e){
  if(machine.value.trim()===''){e.preventDefault();machine.setCustomValidity('Please select a Machine No.');machine.reportValidity();machine.focus();return;}
  if(!allowedMachines.has(machine.value.trim())){e.preventDefault();machine.setCustomValidity('Please choose a Machine No. from the registered list.');machine.reportValidity();machine.focus();return;}
  machine.setCustomValidity('');
  if(!['Day','Night'].includes(shift.value)){e.preventDefault();shift.setCustomValidity('Please select Day or Night.');shift.reportValidity();shift.focus();return;}
  shift.setCustomValidity('');
 });
 machine.addEventListener('input',()=>machine.setCustomValidity(''));
 machine.addEventListener('change',()=>machine.setCustomValidity(''));
 shift.addEventListener('change',()=>shift.setCustomValidity(''));
})();
</script>
<script>
(function(){
    const input = document.getElementById('daily_records_search');
    const table = document.getElementById('daily_records_table');
    const count = document.getElementById('daily_records_search_count');
    if (!input || !table || !count) return;
    const body = table.tBodies[0];
    if (!body) return;
    const rows = Array.from(body.rows).filter(row => !row.querySelector('td[colspan]'));
    function filterRows(){
        const query = input.value.trim().toLocaleLowerCase();
        let visible = 0;
        rows.forEach(row => {
            const matches = row.innerText.toLocaleLowerCase().includes(query);
            row.hidden = !matches;
            if (matches) visible++;
        });
        count.textContent = query ? visible + ' of ' + rows.length + ' records' : rows.length + ' records';
    }
    input.addEventListener('input', filterRows);
})();
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
