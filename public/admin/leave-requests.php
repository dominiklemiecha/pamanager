<?php
/**
 * Gestione Richieste Ferie/Permessi - Admin Generale
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$user = Auth::getUser();
$message = '';
$error = '';

// Download allegato richiesta
if (isset($_GET['download_attachment'])) {
    $result = LeaveRequest::downloadAttachment((int) $_GET['download_attachment']);
    if (!$result['success']) {
        http_response_code(403);
        exit(htmlspecialchars($result['error']));
    }
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $result['filename']);
    if (function_exists('setDownloadHeaders')) {
        setDownloadHeaders($safeName, $result['mime'], filesize($result['file_path']));
    } else {
        header('Content-Type: ' . $result['mime']);
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Content-Length: ' . filesize($result['file_path']));
    }
    if (ob_get_level()) { ob_end_clean(); }
    readfile($result['file_path']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    $action = $_POST['action'] ?? '';
    $requestId = (int) ($_POST['request_id'] ?? 0);

    if ($action === 'admin_create') {
        // Inserimento manuale da parte dell'admin (auto-approvato).
        $leaveType  = $_POST['leave_type'] ?? '';
        $startDate  = $_POST['start_date'] ?? '';
        $endDate    = $_POST['end_date'] ?? $startDate;
        $isFullDay  = !empty($_POST['is_full_day']);
        $startTime  = $_POST['start_time'] ?? null;
        $endTime    = $_POST['end_time'] ?? null;
        $reason     = trim($_POST['reason'] ?? '') ?: ('Inserito da admin (' . ($user['name'] ?? $user['username']) . ')');
        $notes      = trim($_POST['notes'] ?? '');
        $applyAll   = !empty($_POST['apply_all']);
        $deptId     = !empty($_POST['target_department_id']) ? (int)$_POST['target_department_id'] : null;
        $empIdsArr  = [];

        if ($applyAll) {
            // Chiusura aziendale o assenza a tutti
            $__cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
            $sqlEmps = "SELECT id FROM employees WHERE is_active = TRUE AND company_id = ?";
            $params = [$__cid];
            if ($deptId) { $sqlEmps .= " AND department_id = ?"; $params[] = $deptId; }
            $empIdsArr = array_column(Database::fetchAll($sqlEmps, $params), 'id');
        } elseif (!empty($_POST['employee_id'])) {
            $empIdsArr = [(int)$_POST['employee_id']];
        }

        if (empty($empIdsArr)) {
            $error = 'Seleziona almeno un dipendente o spunta "applica a tutti".';
        } else {
            $created = 0; $skipped = 0; $errors = [];
            foreach ($empIdsArr as $eid) {
                $payload = [
                    'employee_id' => $eid,
                    'leave_type'  => $leaveType,
                    'start_date'  => $startDate,
                    'end_date'    => $endDate,
                    'is_full_day' => $isFullDay,
                    'start_time'  => $isFullDay ? null : $startTime,
                    'end_time'    => $isFullDay ? null : $endTime,
                    'reason'      => $reason,
                    'notes'       => $notes,
                ];
                $r = LeaveRequest::createByAdmin($payload, (int)$user['id']);
                if ($r['success']) $created++;
                else { $skipped++; $errors[] = $r['error']; }
            }
            $msg = "Create $created richieste";
            if ($skipped > 0) $msg .= " (saltate $skipped — sovrapposizione o errore)";
            header('Location: leave-requests.php?status=approved&message=' . urlencode($msg));
            exit;
        }
    }

    if ($requestId) {
        switch ($action) {
            case 'approve':
                $result = LeaveRequest::approve($requestId, $user['id']);
                if ($result['success']) { header('Location: leave-requests.php?message=approved'); exit; }
                $error = $result['error'];
                break;
            case 'reject':
                $reason = $_POST['rejection_reason'] ?? '';
                $result = LeaveRequest::reject($requestId, $user['id'], $reason);
                if ($result['success']) { header('Location: leave-requests.php?message=rejected'); exit; }
                $error = $result['error'];
                break;
            case 'admin_edit':
                $result = LeaveRequest::adminUpdate($requestId, [
                    'leave_type' => $_POST['leave_type'] ?? null,
                    'start_date' => $_POST['start_date'] ?? null,
                    'end_date'   => $_POST['end_date'] ?? null,
                    'is_full_day'=> isset($_POST['is_full_day']),
                    'start_time' => $_POST['start_time'] ?? null,
                    'end_time'   => $_POST['end_time'] ?? null,
                    'reason'     => $_POST['reason'] ?? null,
                    'notes'      => $_POST['notes'] ?? null,
                    'status'     => $_POST['status'] ?? null,
                ]);
                if ($result['success']) { header('Location: leave-requests.php?message=updated'); exit; }
                $error = $result['error'];
                break;
            case 'admin_delete':
                $result = LeaveRequest::delete($requestId);
                if ($result['success']) { header('Location: leave-requests.php?message=deleted'); exit; }
                $error = $result['error'];
                break;
        }
    }
}

if (isset($_GET['message'])) {
    $known = ['approved' => 'Richiesta approvata con successo', 'rejected' => 'Richiesta rifiutata', 'updated' => 'Richiesta aggiornata', 'deleted' => 'Richiesta eliminata'];
    $message = $known[$_GET['message']] ?? $_GET['message'];
}

// Lista dipendenti attivi per il form manuale (scoped per azienda corrente)
$__leaveCid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
$activeEmployees = Database::fetchAll(
    "SELECT id, first_name, last_name, department_id FROM employees WHERE is_active = TRUE AND company_id = ? ORDER BY last_name, first_name",
    [$__leaveCid]
);

$filterStatus = $_GET['status'] ?? 'pending';
$filterDept = !empty($_GET['department']) ? (int) $_GET['department'] : null;

$requests = LeaveRequest::getAll($filterStatus !== 'all' ? $filterStatus : null, $filterDept);
$departments = Department::getAll();

$pageTitle = 'Ferie e Permessi';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<style>
.lp { display: flex; flex-direction: column; gap: 1rem; }

.lp-filters {
    background: white; border-radius: 10px; padding: 0.75rem 1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center;
}
.lp-tabs { display: flex; gap: 0.4rem; flex-wrap: wrap; }
.lp-tab {
    padding: 0.4rem 0.85rem; border: 1px solid #e2e8f0; border-radius: 6px;
    background: white; color: #4a5568; font-size: 0.8rem; cursor: pointer;
    text-decoration: none; transition: all 0.15s;
}
.lp-tab:hover { background: #f7fafc; }
.lp-tab.active { background: #3182ce; color: white; border-color: #3182ce; }
.lp-tab .badge { background: rgba(255,255,255,0.3); padding: 0.05rem 0.35rem; border-radius: 8px; font-size: 0.65rem; margin-left: 0.3rem; }
.lp-dept { margin-left: auto; }
.lp-dept select { padding: 0.4rem 0.6rem; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.8rem; }

.lp-table-wrap {
    background: white; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    overflow: auto;
}
.lp-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.lp-table thead th {
    background: #f7fafc; padding: 0.6rem 0.75rem; text-align: left;
    font-size: 0.7rem; text-transform: uppercase; color: #718096; font-weight: 600;
    border-bottom: 1px solid #edf2f7; white-space: nowrap;
}
.lp-table tbody td {
    padding: 0.6rem 0.75rem; border-bottom: 1px solid #f7fafc; vertical-align: middle;
}
.lp-table tbody tr:hover { background: #fbfcfd; }
.lp-table tbody tr:last-child td { border-bottom: none; }

.lp-emp { display: flex; align-items: center; gap: 0.5rem; }
.lp-avatar {
    width: 30px; height: 30px; border-radius: 50%; background: #3182ce;
    color: white; display: flex; align-items: center; justify-content: center;
    font-weight: 600; font-size: 0.7rem; flex-shrink: 0;
}
.lp-emp-name { font-weight: 500; color: #2d3748; }
.lp-emp-dept { font-size: 0.7rem; color: #a0aec0; }

.lp-type {
    display: inline-block; padding: 0.15rem 0.5rem; border-radius: 4px;
    font-size: 0.7rem; font-weight: 600; white-space: nowrap;
}
.lp-type.ferie { background: #d4edda; color: #155724; }
.lp-type.permesso { background: #cce5ff; color: #004085; }
.lp-type.malattia { background: #f8d7da; color: #721c24; }
.lp-type.permesso_104 { background: #e2d5f1; color: #563d7c; }
.lp-type.congedo_parentale { background: #fce4ec; color: #880e4f; }
.lp-type.congedo_separazione { background: #edf2f7; color: #2d3748; }
.lp-type.congedo_mestruale { background: #ffe0e6; color: #b83a4b; }
.lp-type.altro { background: #e2e8f0; color: #4a5568; }

.lp-status {
    display: inline-block; padding: 0.15rem 0.5rem; border-radius: 4px;
    font-size: 0.7rem; font-weight: 600;
}
.lp-status.pending { background: #fef3cd; color: #856404; }
.lp-status.approved { background: #d4edda; color: #155724; }
.lp-status.rejected { background: #f8d7da; color: #721c24; }
.lp-status.cancelled { background: #e2e8f0; color: #4a5568; }

.lp-dates { white-space: nowrap; color: #2d3748; }
.lp-dates small { color: #a0aec0; display: block; font-size: 0.7rem; }

.lp-actions { display: flex; gap: 0.35rem; justify-content: flex-end; }
.lp-btn {
    padding: 0.3rem 0.6rem; font-size: 0.72rem; border-radius: 5px;
    border: 1px solid transparent; cursor: pointer; font-weight: 600;
}
.lp-btn-view { background: #edf2f7; color: #2d3748; }
.lp-btn-view:hover { background: #e2e8f0; }
.lp-btn-approve { background: #48bb78; color: white; }
.lp-btn-approve:hover { background: #38a169; }
.lp-btn-reject { background: #f56565; color: white; }
.lp-btn-reject:hover { background: #e53e3e; }

.lp-empty { text-align: center; padding: 3rem; color: #a0aec0; }

/* Modal dettaglio */
.lp-modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,0.5);
    display: none; align-items: center; justify-content: center; z-index: 1000;
    padding: 1rem;
}
.lp-modal-overlay.show { display: flex; }
.lp-modal {
    background: white; border-radius: 10px; padding: 1.5rem;
    width: 100%; max-width: 520px; max-height: 90vh; overflow: auto;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
}
.lp-modal h3 { margin: 0 0 1rem; color: #2d3748; }
.lp-modal-row { display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #f7fafc; font-size: 0.85rem; gap: 1rem; }
.lp-modal-row:last-of-type { border-bottom: none; }
.lp-modal-row strong { color: #4a5568; font-weight: 600; }
.lp-modal-block { margin: 0.75rem 0; padding: 0.75rem; background: #f7fafc; border-radius: 6px; font-size: 0.85rem; }
.lp-modal-block-title { font-size: 0.7rem; color: #a0aec0; text-transform: uppercase; font-weight: 600; margin-bottom: 0.25rem; }
.lp-modal textarea { width: 100%; padding: 0.6rem; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.85rem; resize: vertical; }
.lp-modal-actions { display: flex; gap: 0.5rem; margin-top: 1rem; justify-content: flex-end; flex-wrap: wrap; }

@media (max-width: 768px) {
    .lp-table thead { display: none; }
    .lp-table, .lp-table tbody, .lp-table tr, .lp-table td { display: block; width: 100%; }
    .lp-table tbody tr { padding: 0.5rem 0.75rem; border-bottom: 1px solid #f7fafc; }
    .lp-table tbody td { padding: 0.2rem 0; border: none; }
    .lp-table tbody td[data-label]:before { content: attr(data-label) ': '; font-weight: 600; color: #718096; font-size: 0.7rem; text-transform: uppercase; margin-right: 0.4rem; }
    .lp-actions { justify-content: flex-start; margin-top: 0.4rem; }
    .lp-dept { width: 100%; margin-left: 0; }
}

/* Form admin-create inserimento manuale */
.admin-create-leave {
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    border-radius: 12px; padding: 0;
    box-shadow: 0 4px 12px rgba(49,130,206,0.10);
    border: 1px solid #93c5fd;
}
.admin-create-leave > summary {
    display: flex; align-items: center; gap: 0.85rem;
    padding: 1rem 1.2rem; font-weight: 600; color: #0f172a;
    cursor: pointer; list-style: none; border-radius: 12px;
    transition: background .15s, transform .1s;
}
.admin-create-leave > summary::-webkit-details-marker,
.admin-create-leave > summary::marker { display: none; content: ''; }
.admin-create-leave > summary:hover { background: rgba(255,255,255,0.5); }
.admin-create-leave > summary:active { transform: scale(0.99); }
.admin-create-leave[open] > summary {
    border-bottom: 1px solid #bfdbfe;
    border-radius: 12px 12px 0 0;
    background: rgba(255,255,255,0.6);
}
.admin-create-leave .acl-toggle-icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 38px; height: 38px; flex-shrink: 0;
    background: #3182ce; color: #fff; border-radius: 10px;
    box-shadow: 0 2px 6px rgba(49,130,206,0.35);
}
.admin-create-leave .acl-toggle-text {
    flex: 1; display: flex; flex-direction: column; gap: 1px; min-width: 0;
}
.admin-create-leave .acl-toggle-text strong {
    color: #0f172a; font-size: 0.95rem; font-weight: 700;
    letter-spacing: -0.01em;
}
.admin-create-leave .acl-toggle-text em {
    font-style: normal; font-size: 0.78rem; color: #475569; font-weight: 500;
}
.admin-create-leave .acl-toggle-chevron {
    color: #3182ce; transition: transform .2s ease;
}
.admin-create-leave[open] .acl-toggle-chevron { transform: rotate(180deg); }
.admin-create-leave[open] { background: #ffffff; }
.admin-create-leave[open] .acl-toggle-icon { background: #15803d; box-shadow: 0 2px 6px rgba(21,128,61,0.35); }
.acl-form { padding: 1rem 1.1rem 1.1rem; display: flex; flex-direction: column; gap: 0.9rem; }
.acl-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; align-items: end; }
.acl-row label { display: flex; flex-direction: column; gap: 0.3rem; font-size: 0.82rem; color: #475569; font-weight: 500; }
.acl-row label > span { font-size: 0.72rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600; }
.acl-row .acl-full { grid-column: 1 / -1; }
.acl-row input[type="text"], .acl-row input[type="date"], .acl-row input[type="time"],
.acl-row select, .acl-row textarea {
    padding: 0.5rem 0.65rem; border: 1px solid #e2e8f0; border-radius: 6px;
    font-size: 0.88rem; font-family: inherit; background: #fff;
}
.acl-row textarea { resize: vertical; }
.acl-row .acl-check { flex-direction: row; align-items: center; gap: 0.5rem; }
.acl-row .acl-check span { text-transform: none; letter-spacing: 0; font-weight: 500; color: #0f172a; font-size: 0.85rem; }
.acl-actions { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; }
.acl-hint { margin: 0 0 0 auto; font-size: 0.78rem; color: #94a3b8; }
@media (max-width: 720px) {
    .acl-row { grid-template-columns: 1fr; }
    .acl-hint { margin-left: 0; }
}
</style>

<div class="lp">
    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <details class="admin-create-leave">
        <summary>
            <span class="acl-toggle-icon">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            </span>
            <span class="acl-toggle-text">
                <strong>Inserisci richiesta manualmente</strong>
                <em>Ferie, permessi, malattia o chiusura aziendale — viene gia approvata</em>
            </span>
            <span class="acl-toggle-chevron">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>
            </span>
        </summary>
        <form method="POST" class="acl-form">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="admin_create">

            <div class="acl-row">
                <label>
                    <span>Tipo *</span>
                    <select name="leave_type" required id="acl_type">
                        <?php foreach (LeaveRequest::LEAVE_TYPES as $k => $v): ?>
                            <option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Data inizio *</span>
                    <input type="date" name="start_date" required value="<?= date('Y-m-d') ?>">
                </label>
                <label>
                    <span>Data fine *</span>
                    <input type="date" name="end_date" required value="<?= date('Y-m-d') ?>">
                </label>
            </div>

            <div class="acl-row">
                <label class="acl-check">
                    <input type="checkbox" name="is_full_day" checked id="acl_full">
                    <span>Intera giornata</span>
                </label>
                <label class="acl-time" data-show-if="not_full">
                    <span>Dalle ore</span>
                    <input type="time" name="start_time">
                </label>
                <label class="acl-time" data-show-if="not_full">
                    <span>Alle ore</span>
                    <input type="time" name="end_time">
                </label>
            </div>

            <div class="acl-row">
                <label id="acl_emp_wrap">
                    <span>Dipendente *</span>
                    <select name="employee_id" id="acl_emp">
                        <option value="">-- Seleziona --</option>
                        <?php foreach ($activeEmployees as $emp): ?>
                            <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['last_name'] . ' ' . $emp['first_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="acl-check">
                    <input type="checkbox" name="apply_all" id="acl_all">
                    <span>Applica a tutti i dipendenti</span>
                </label>
                <label id="acl_dept_wrap" style="display:none;">
                    <span>Solo reparto (opzionale)</span>
                    <select name="target_department_id">
                        <option value="">-- Tutti i reparti --</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <div class="acl-row">
                <label class="acl-full">
                    <span>Motivo *</span>
                    <input type="text" name="reason" required maxlength="200" placeholder="Es. Dimenticato di registrare le ferie del 10 maggio">
                </label>
            </div>
            <div class="acl-row">
                <label class="acl-full">
                    <span>Note interne (opzionale)</span>
                    <textarea name="notes" rows="2" maxlength="500"></textarea>
                </label>
            </div>

            <div class="acl-actions">
                <button type="submit" class="btn btn-primary">Crea e approva</button>
                <button type="reset" class="btn btn-secondary">Reset</button>
                <p class="acl-hint">La richiesta verra creata gia approvata, senza passare per il workflow di approvazione.</p>
            </div>
        </form>
    </details>

    <form method="GET" class="lp-filters">
        <div class="lp-tabs">
            <?php
            $pendingCount = (int) Database::fetchColumn("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending' AND company_id = ?", [$__leaveCid]);
            $tabs = [
                'pending' => ['label' => 'In Attesa', 'count' => $pendingCount],
                'approved' => ['label' => 'Approvate', 'count' => null],
                'rejected' => ['label' => 'Rifiutate', 'count' => null],
                'all' => ['label' => 'Tutte', 'count' => null]
            ];
            $deptParam = $filterDept ? '&department=' . $filterDept : '';
            foreach ($tabs as $key => $tab): ?>
                <a href="?status=<?= $key ?><?= $deptParam ?>" class="lp-tab <?= $filterStatus === $key ? 'active' : '' ?>">
                    <?= $tab['label'] ?><?php if ($tab['count']): ?><span class="badge"><?= $tab['count'] ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="lp-dept">
            <input type="hidden" name="status" value="<?= e($filterStatus) ?>">
            <select name="department" onchange="this.form.submit()">
                <option value="">Tutti i reparti</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?= $dept['id'] ?>" <?= $filterDept === (int)$dept['id'] ? 'selected' : '' ?>>
                        <?= e($dept['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <div class="lp-table-wrap">
        <?php if (empty($requests)): ?>
            <div class="lp-empty">Nessuna richiesta trovata</div>
        <?php else: ?>
            <table class="lp-table">
                <thead>
                    <tr>
                        <th>Dipendente</th>
                        <th>Tipo</th>
                        <th>Periodo</th>
                        <th>Giorni</th>
                        <th>Stato</th>
                        <th>Inviata</th>
                        <th style="text-align:right;">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $req):
                    $workingDays = LeaveRequest::calculateWorkingDays($req['start_date'], $req['end_date']);
                    $sameDay = $req['start_date'] === $req['end_date'];
                ?>
                    <tr>
                        <td data-label="Dipendente">
                            <div class="lp-emp">
                                <?= employeeAvatarHtml($req, 'lp-avatar') ?>
                                <div>
                                    <div class="lp-emp-name"><?= e($req['last_name'] . ' ' . $req['first_name']) ?></div>
                                    <?php if (!empty($req['department_name'])): ?>
                                        <div class="lp-emp-dept"><?= e($req['department_name']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td data-label="Tipo">
                            <span class="lp-type <?= $req['leave_type'] ?>">
                                <?= e(LeaveRequest::LEAVE_TYPES[$req['leave_type']] ?? $req['leave_type']) ?>
                            </span>
                        </td>
                        <td data-label="Periodo" class="lp-dates">
                            <?= formatDate($req['start_date']) ?>
                            <?php if (!$sameDay): ?> → <?= formatDate($req['end_date']) ?><?php endif; ?>
                            <?php if (!$req['is_full_day'] && $req['start_time']): ?>
                                <small><?= substr($req['start_time'], 0, 5) ?> - <?= substr($req['end_time'], 0, 5) ?></small>
                            <?php endif; ?>
                        </td>
                        <td data-label="Giorni"><?= $workingDays ?></td>
                        <td data-label="Stato">
                            <span class="lp-status <?= $req['status'] ?>">
                                <?= e(LeaveRequest::STATUSES[$req['status']] ?? $req['status']) ?>
                            </span>
                        </td>
                        <td data-label="Inviata"><?= formatDateTime($req['created_at'], 'd/m H:i') ?></td>
                        <td data-label="Azioni">
                            <div class="lp-actions">
                                <?php
                                $detailPayload = [
                                    "id" => (int) $req["id"],
                                    "name" => $req["last_name"] . " " . $req["first_name"],
                                    "fc" => $req["fiscal_code"] ?? "",
                                    "type" => LeaveRequest::LEAVE_TYPES[$req["leave_type"]] ?? $req["leave_type"],
                                    "start" => formatDate($req["start_date"]),
                                    "end" => formatDate($req["end_date"]),
                                    "time" => (!$req["is_full_day"] && $req["start_time"]) ? substr($req["start_time"], 0, 5) . " - " . substr($req["end_time"], 0, 5) : "",
                                    "days" => $workingDays,
                                    "status" => LeaveRequest::STATUSES[$req["status"]] ?? $req["status"],
                                    "reason" => $req["reason"],
                                    "notes" => $req["notes"] ?? "",
                                    "rejection" => $req["rejection_reason"] ?? "",
                                    "approved_by" => $req["approved_by_name"] ?? "",
                                    "is_pending" => $req["status"] === "pending"
                                ];
                                ?>
                                <?php
                                $editPayload = [
                                    'id' => (int) $req['id'],
                                    'name' => $req['last_name'] . ' ' . $req['first_name'],
                                    'leave_type' => $req['leave_type'],
                                    'start_date' => $req['start_date'],
                                    'end_date' => $req['end_date'],
                                    'is_full_day' => (int) $req['is_full_day'],
                                    'start_time' => $req['start_time'] ? substr($req['start_time'], 0, 5) : '',
                                    'end_time' => $req['end_time'] ? substr($req['end_time'], 0, 5) : '',
                                    'reason' => $req['reason'] ?? '',
                                    'notes' => $req['notes'] ?? '',
                                    'status' => $req['status'],
                                ];
                                ?>
                                <button type="button" class="lp-btn lp-btn-view js-detail" data-detail="<?= htmlspecialchars(json_encode($detailPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">Dettagli</button>
                                <?php if (!empty($req['attachment_path'])): ?>
                                    <a class="lp-btn lp-btn-view" href="?download_attachment=<?= (int) $req['id'] ?>" title="Scarica allegato">Allegato</a>
                                <?php endif; ?>
                                <button type="button" class="lp-btn lp-btn-view js-edit" data-edit="<?= htmlspecialchars(json_encode($editPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>" style="background:#ed8936;color:white;">Modifica</button>
                                <form method="POST" style="display:inline;margin:0;" onsubmit="return confirm('Eliminare definitivamente questa richiesta? L\'operazione non e\' reversibile.');">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="admin_delete">
                                    <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                                    <button type="submit" class="lp-btn lp-btn-reject" title="Elimina richiesta">Elimina</button>
                                </form>
                                <?php if ($req['status'] === 'pending'): ?>
                                    <form method="POST" style="display:inline; margin:0;">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                        <button type="submit" class="lp-btn lp-btn-approve" onclick="return confirm('Approvare?')">Approva</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Dettaglio -->
<div class="lp-modal-overlay" id="detailModal">
    <div class="lp-modal">
        <h3 id="dmTitle">Dettaglio Richiesta</h3>
        <div class="lp-modal-row"><strong>Dipendente</strong><span id="dmName"></span></div>
        <div class="lp-modal-row"><strong>Codice Fiscale</strong><span id="dmFc" style="font-family:monospace;"></span></div>
        <div class="lp-modal-row"><strong>Tipo</strong><span id="dmType"></span></div>
        <div class="lp-modal-row"><strong>Periodo</strong><span id="dmPeriod"></span></div>
        <div class="lp-modal-row"><strong>Giorni lavorativi</strong><span id="dmDays"></span></div>
        <div class="lp-modal-row"><strong>Stato</strong><span id="dmStatus"></span></div>
        <div class="lp-modal-row" id="dmApprovedRow"><strong>Gestita da</strong><span id="dmApproved"></span></div>

        <div class="lp-modal-block">
            <div class="lp-modal-block-title">Motivazione</div>
            <div id="dmReason"></div>
        </div>
        <div class="lp-modal-block" id="dmNotesBlock">
            <div class="lp-modal-block-title">Note</div>
            <div id="dmNotes"></div>
        </div>
        <div class="lp-modal-block" id="dmRejBlock" style="background:#fff5f5;">
            <div class="lp-modal-block-title" style="color:#c53030;">Motivo rifiuto</div>
            <div id="dmRej"></div>
        </div>

        <form method="POST" id="dmRejectForm" style="display:none; margin-top:1rem;">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="request_id" id="dmRejectId">
            <label style="display:block; font-size:0.8rem; color:#4a5568; margin-bottom:0.4rem;">Motivo del rifiuto (opzionale)</label>
            <textarea name="rejection_reason" rows="3" placeholder="Inserisci il motivo..."></textarea>
            <div class="lp-modal-actions">
                <button type="button" class="lp-btn lp-btn-view" onclick="hideRejectForm()">Annulla rifiuto</button>
                <button type="submit" class="lp-btn lp-btn-reject">Conferma Rifiuto</button>
            </div>
        </form>

        <div class="lp-modal-actions" id="dmActions">
            <button type="button" class="lp-btn lp-btn-view" onclick="hideDetail()">Chiudi</button>
            <button type="button" class="lp-btn lp-btn-reject" id="dmBtnReject" onclick="showRejectForm()" style="display:none;">Rifiuta</button>
            <form method="POST" id="dmApproveForm" style="display:none; margin:0;">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="request_id" id="dmApproveId">
                <button type="submit" class="lp-btn lp-btn-approve" onclick="return confirm('Approvare?')">Approva</button>
            </form>
        </div>
    </div>
</div>

<script>
function showDetail(d) {
    document.getElementById('dmName').textContent = d.name;
    document.getElementById('dmFc').textContent = d.fc || '—';
    document.getElementById('dmType').textContent = d.type;
    document.getElementById('dmPeriod').textContent = d.start + (d.end !== d.start ? ' → ' + d.end : '') + (d.time ? ' (' + d.time + ')' : '');
    document.getElementById('dmDays').textContent = d.days;
    document.getElementById('dmStatus').textContent = d.status;
    document.getElementById('dmReason').textContent = d.reason || '—';

    document.getElementById('dmApprovedRow').style.display = d.approved_by ? 'flex' : 'none';
    document.getElementById('dmApproved').textContent = d.approved_by;

    document.getElementById('dmNotesBlock').style.display = d.notes ? 'block' : 'none';
    document.getElementById('dmNotes').textContent = d.notes;

    document.getElementById('dmRejBlock').style.display = d.rejection ? 'block' : 'none';
    document.getElementById('dmRej').textContent = d.rejection;

    document.getElementById('dmRejectForm').style.display = 'none';
    document.getElementById('dmActions').style.display = 'flex';
    document.getElementById('dmBtnReject').style.display = d.is_pending ? 'inline-block' : 'none';
    document.getElementById('dmApproveForm').style.display = d.is_pending ? 'inline-block' : 'none';
    document.getElementById('dmRejectId').value = d.id;
    document.getElementById('dmApproveId').value = d.id;

    document.getElementById('detailModal').classList.add('show');
}
function hideDetail() { document.getElementById('detailModal').classList.remove('show'); }
function showRejectForm() {
    document.getElementById('dmRejectForm').style.display = 'block';
    document.getElementById('dmActions').style.display = 'none';
}
function hideRejectForm() {
    document.getElementById('dmRejectForm').style.display = 'none';
    document.getElementById('dmActions').style.display = 'flex';
}
document.getElementById('detailModal').addEventListener('click', function(e) {
    if (e.target === this) hideDetail();
});

// Bind dei pulsanti "Dettagli" tramite data-attribute (sicuro contro escaping)
document.querySelectorAll('.js-detail').forEach(function(btn) {
    btn.addEventListener('click', function() {
        try {
            const data = JSON.parse(btn.getAttribute('data-detail'));
            showDetail(data);
        } catch (err) {
            console.error('Errore parsing detail:', err);
        }
    });
});

// Bind dei pulsanti "Modifica" — apre modale edit con i dati della richiesta
document.querySelectorAll('.js-edit').forEach(function(btn) {
    btn.addEventListener('click', function() {
        try {
            const d = JSON.parse(btn.getAttribute('data-edit'));
            const f = document.getElementById('editForm');
            f.querySelector('[name=request_id]').value = d.id;
            f.querySelector('[name=leave_type]').value = d.leave_type;
            f.querySelector('[name=start_date]').value = d.start_date;
            f.querySelector('[name=end_date]').value = d.end_date;
            f.querySelector('[name=is_full_day]').checked = !!d.is_full_day;
            f.querySelector('[name=start_time]').value = d.start_time || '';
            f.querySelector('[name=end_time]').value = d.end_time || '';
            f.querySelector('[name=reason]').value = d.reason || '';
            f.querySelector('[name=notes]').value = d.notes || '';
            f.querySelector('[name=status]').value = d.status;
            document.getElementById('editModalTitle').textContent = 'Modifica richiesta — ' + d.name;
            document.getElementById('editModal').classList.add('show');
        } catch (err) { console.error(err); }
    });
});
function hideEdit() { document.getElementById('editModal').classList.remove('show'); }
document.getElementById('editModal').addEventListener('click', function(e) { if (e.target === this) hideEdit(); });
</script>

<!-- Modal Modifica Admin -->
<div class="lp-modal-overlay" id="editModal">
    <div class="lp-modal" style="max-width:560px;">
        <h3 id="editModalTitle">Modifica richiesta</h3>
        <form method="POST" id="editForm">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="admin_edit">
            <input type="hidden" name="request_id" value="">

            <div class="lp-modal-row"><strong>Tipo</strong>
                <select name="leave_type" required style="flex:1;padding:.4rem;border:1px solid #ddd;border-radius:6px;">
                    <?php foreach (LeaveRequest::LEAVE_TYPES as $k => $label): ?>
                        <option value="<?= e($k) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="lp-modal-row"><strong>Stato</strong>
                <select name="status" required style="flex:1;padding:.4rem;border:1px solid #ddd;border-radius:6px;">
                    <?php foreach (LeaveRequest::STATUSES as $k => $label): ?>
                        <option value="<?= e($k) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="lp-modal-row"><strong>Dal</strong>
                <input type="date" name="start_date" required style="flex:1;padding:.4rem;border:1px solid #ddd;border-radius:6px;">
            </div>
            <div class="lp-modal-row"><strong>Al</strong>
                <input type="date" name="end_date" required style="flex:1;padding:.4rem;border:1px solid #ddd;border-radius:6px;">
            </div>
            <div class="lp-modal-row">
                <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;">
                    <input type="checkbox" name="is_full_day" value="1"> Giornata intera
                </label>
            </div>
            <div class="lp-modal-row"><strong>Dalle</strong>
                <input type="time" name="start_time" style="flex:1;padding:.4rem;border:1px solid #ddd;border-radius:6px;">
            </div>
            <div class="lp-modal-row"><strong>Alle</strong>
                <input type="time" name="end_time" style="flex:1;padding:.4rem;border:1px solid #ddd;border-radius:6px;">
            </div>
            <div class="lp-modal-block">
                <div class="lp-modal-block-title">Motivazione</div>
                <textarea name="reason" rows="2" style="width:100%;padding:.4rem;border:1px solid #ddd;border-radius:6px;"></textarea>
            </div>
            <div class="lp-modal-block">
                <div class="lp-modal-block-title">Note</div>
                <textarea name="notes" rows="2" style="width:100%;padding:.4rem;border:1px solid #ddd;border-radius:6px;"></textarea>
            </div>
            <div class="lp-modal-actions">
                <button type="button" class="lp-btn lp-btn-view" onclick="hideEdit()">Annulla</button>
                <button type="submit" class="lp-btn lp-btn-approve" style="background:#ed8936;">Salva modifiche</button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    var full = document.getElementById('acl_full');
    var all  = document.getElementById('acl_all');
    var empSel = document.getElementById('acl_emp');
    var deptWrap = document.getElementById('acl_dept_wrap');
    var typeSel = document.getElementById('acl_type');

    function applyVisibility() {
        // is_full_day -> nasconde orari
        document.querySelectorAll('[data-show-if="not_full"]').forEach(function(el){
            el.style.display = full.checked ? 'none' : '';
        });
        // apply_all -> dipendente facoltativo, mostra select reparto
        if (all.checked) {
            empSel.removeAttribute('required'); empSel.disabled = true;
            deptWrap.style.display = '';
        } else {
            empSel.setAttribute('required', 'required'); empSel.disabled = false;
            deptWrap.style.display = 'none';
        }
    }
    function autoToggleChiusura() {
        // Se scelgo "Chiusura aziendale" auto-spunta "Applica a tutti"
        if (typeSel.value === 'chiusura' && !all.checked) {
            all.checked = true; applyVisibility();
        }
    }
    [full, all, typeSel].forEach(function(el){ if (el) el.addEventListener('change', applyVisibility); });
    if (typeSel) typeSel.addEventListener('change', autoToggleChiusura);
    applyVisibility();
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
