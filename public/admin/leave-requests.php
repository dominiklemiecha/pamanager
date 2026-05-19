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

// Download certificato malattia
if (isset($_GET['download_cert'])) {
    $result = LeaveRequest::downloadCertificate((int) $_GET['download_cert']);
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

// Stats per banner
$__lrPending = 0; $__lrApproved = 0; $__lrTotal = 0;
try {
    $__lrPending  = (int) Database::fetchColumn("SELECT COUNT(*) FROM leave_requests WHERE company_id = ? AND status = 'pending'", [$__leaveCid]);
    $__lrApproved = (int) Database::fetchColumn("SELECT COUNT(*) FROM leave_requests WHERE company_id = ? AND status = 'approved' AND end_date >= CURDATE()", [$__leaveCid]);
    $__lrTotal    = (int) Database::fetchColumn("SELECT COUNT(*) FROM leave_requests WHERE company_id = ? AND YEAR(created_at) = YEAR(CURDATE())", [$__leaveCid]);
} catch (Throwable $e) {}

$pageTitle = 'Ferie e Permessi';
include dirname(__DIR__) . '/includes/header-admin.php';
include dirname(__DIR__) . '/includes/_cd-tabs.inc.php';

// Malattie >24h senza protocollo o certificato (banner admin)
try {
    $__sickLate = LeaveRequest::sickPendingDocs(24);
} catch (Throwable $e) { $__sickLate = []; }
?>

<style>
/* === Hero banner === */
.lp-hero {
    margin-bottom: 1.25rem;
    display: flex; justify-content: space-between; align-items: center;
    gap: 24px; flex-wrap: wrap;
}
.lp-hero h2 {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 2rem; font-weight: 700;
    letter-spacing: -0.025em;
    margin: 0 0 4px;
    line-height: 1.1;
}
.lp-hero p { margin: 0; opacity: 0.85; max-width: 560px; }
.lp-hero-stats { display: flex; gap: 18px; flex-shrink: 0; }
.lp-hero-stat {
    text-align: right;
    padding: 10px 16px;
    background: rgba(11,58,164,0.06);
    border: 1px solid rgba(11,58,164,0.14);
    border-radius: 10px;
    min-width: 100px;
}
.lp-hero-stat .v {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 26px; font-weight: 700;
    line-height: 1;
    letter-spacing: -0.025em;
    color: #0b3aa4;
}
.lp-hero-stat .l {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #6e7191;
    margin-top: 4px;
    font-weight: 600;
}
@media (max-width: 700px) {
    .lp-hero { padding: 22px 24px !important; }
    .lp-hero h2 { font-size: 1.5rem; }
    .lp-hero-stats { width: 100%; }
    .lp-hero-stat { flex: 1; text-align: center; min-width: 0; padding: 10px 8px; }
    .lp-hero-stat .v { font-size: 20px; }
}

.lp { display: flex; flex-direction: column; gap: 1rem; }

.lp-filters {
    background: white;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 0.75rem 1rem;
    display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center;
}
.lp-tabs {
    display: inline-flex;
    background: #f1f5f9;
    border-radius: 999px;
    padding: 4px;
    gap: 2px;
    flex-wrap: wrap;
    max-width: 100%;
}
.lp-tab {
    padding: 7px 14px;
    border: 0;
    border-radius: 999px;
    background: transparent;
    color: var(--muted);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: all .15s ease;
    white-space: nowrap;
}
@media (max-width: 700px) {
    .lp-filters { padding: 0.5rem !important; flex-direction: column; align-items: stretch !important; gap: 8px; }
    .lp-tabs { display: grid !important; grid-template-columns: repeat(2, 1fr); width: 100%; gap: 4px; padding: 4px; border-radius: 12px; }
    .lp-tab {
        text-align: center; justify-content: center;
        padding: 9px 8px; font-size: 12px;
        display: inline-flex; align-items: center; justify-content: center;
        border-radius: 8px;
    }
    .lp-tab .badge { font-size: 9px; padding: 1px 5px; }
    .lp-dept { margin-left: 0 !important; width: 100%; }
    .lp-dept select { width: 100%; }
}
.lp-tab:hover { color: var(--ink); background: rgba(255,255,255,0.6); }
.lp-tab.active {
    background: white;
    color: #0b3aa4;
    box-shadow: 0 1px 3px rgba(15,23,42,0.08);
}
.lp-tab .badge {
    background: rgba(11,58,164,0.10);
    color: #0b3aa4;
    padding: 1px 7px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 700;
    margin-left: 4px;
}
.lp-tab.active .badge { background: rgba(11,58,164,0.12); }
.lp-dept { margin-left: auto; }
.lp-dept select {
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 13px;
    background: white;
    color: var(--ink);
    cursor: pointer;
    font-family: inherit;
}
.lp-dept select:focus { outline: none; border-color: #0b3aa4; box-shadow: 0 0 0 3px rgba(11,58,164,0.12); }

.lp-table-wrap {
    background: white;
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
}
.lp-table {
    width: 100%; border-collapse: collapse;
    font-size: 12.5px;
    table-layout: auto;
}
.lp-table th, .lp-table td { white-space: nowrap; }
.lp-table td[data-label="Azioni"] { padding-left: 8px; padding-right: 12px; }
.lp-table thead th {
    background: #fafbfc; padding: 11px 10px; text-align: left;
    font-size: 11px; text-transform: uppercase; color: var(--muted); font-weight: 600;
    border-bottom: 1px solid var(--border);
    letter-spacing: 0.06em;
}
.lp-table tbody td {
    padding: 10px; border-bottom: 1px solid var(--border); vertical-align: middle;
    overflow: hidden;
}
.lp-emp {
    min-width: 0;
    max-width: 240px;
    overflow: hidden;
}
.lp-emp > div { min-width: 0; overflow: hidden; }
.lp-emp-name, .lp-emp-dept { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* Nasconde colonne meno critiche progressivamente */
@media (max-width: 1280px) {
    .lp-table th:nth-child(6),
    .lp-table td[data-label="Inviata"] { display: none; }
}
@media (max-width: 1100px) {
    .lp-table th:nth-child(4),
    .lp-table td[data-label="Giorni"] { display: none; }
}
@media (max-width: 950px) {
    .lp-emp { max-width: 180px; }
    .lp-emp-dept { display: none; }
}
.lp-table tbody tr:hover { background: #fafbfc; }
.lp-table tbody tr:last-child td { border-bottom: none; }

.lp-emp { display: flex; align-items: center; gap: 0.6rem; }
.lp-avatar {
    width: 32px; height: 32px; border-radius: 50%;
    background: linear-gradient(135deg, #4fa1ff, #0b3aa4);
    color: white;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 11px;
    flex-shrink: 0;
    font-family: 'Space Grotesk', sans-serif;
    letter-spacing: -0.02em;
}
.lp-emp-name { font-weight: 600; color: var(--ink); font-size: 13px; }
.lp-emp-dept { font-size: 11px; color: var(--muted); }

.lp-type {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px; border-radius: 999px;
    font-size: 11px; font-weight: 600; white-space: nowrap;
    background: rgba(11,58,164,0.08); color: #0b3aa4;
}
.lp-type::before {
    content: ""; display: inline-block;
    width: 6px; height: 6px; border-radius: 50%;
    background: currentColor;
}
.lp-type.ferie { background: rgba(11,58,164,0.10); color: #0b3aa4; }
.lp-type.permesso { background: rgba(40,119,255,0.10); color: #2877ff; }
.lp-type.malattia { background: rgba(247,92,108,0.10); color: #f75c6c; }
.lp-type.permesso_104 { background: rgba(124,58,237,0.10); color: #7c3aed; }
.lp-type.congedo_parentale { background: rgba(236,72,153,0.10); color: #db2777; }
.lp-type.congedo_separazione { background: rgba(100,116,139,0.10); color: #475569; }
.lp-type.congedo_mestruale { background: rgba(225,29,72,0.10); color: #e11d48; }
.lp-type.altro { background: rgba(100,116,139,0.10); color: #475569; }
.lp-type.chiusura { background: rgba(255,187,85,0.10); color: #e09938; }

.lp-sick-meta {
    display: inline-block; margin-left: 6px;
    font-size: 10.5px; font-weight: 600;
    color: #475569; background: #f1f5f9;
    padding: 2px 8px; border-radius: 999px;
    vertical-align: middle;
}
.lp-sick-missing {
    display: inline-block; margin-left: 6px;
    font-size: 10px; font-weight: 700;
    color: #92400e; background: #fef3c7;
    padding: 2px 8px; border-radius: 999px;
    vertical-align: middle;
    border: 1px solid #fde68a;
}
.lp-sick-missing.is-late {
    color: #991b1b; background: #fee2e2;
    border-color: #fecaca;
}

.lp-status {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px; border-radius: 999px;
    font-size: 11px; font-weight: 600;
}
.lp-status::before {
    content: ""; display: inline-block;
    width: 6px; height: 6px; border-radius: 50%;
    background: currentColor;
}
.lp-status.pending { background: rgba(255,187,85,0.10); color: #e09938; }
.lp-status.approved { background: rgba(11,58,164,0.10); color: #0b3aa4; }
.lp-status.rejected { background: rgba(247,92,108,0.10); color: #f75c6c; }
.lp-status.cancelled { background: rgba(100,116,139,0.10); color: #475569; }

.lp-dates { white-space: nowrap; color: var(--ink); font-size: 13px; }
.lp-dates small { color: var(--muted); display: block; font-size: 11px; margin-top: 2px; }

.lp-actions {
    display: flex; gap: 3px; justify-content: flex-end;
    flex-wrap: nowrap;
}
.lp-btn {
    display: inline-flex; align-items: center; justify-content: center;
    padding: 5px 10px;
    font-size: 11px; font-weight: 600;
    border-radius: 6px;
    border: 1px solid var(--border);
    cursor: pointer;
    font-family: inherit;
    background: white;
    color: var(--ink-2);
    transition: all .12s ease;
    white-space: nowrap;
    line-height: 1.3;
}
.lp-btn:hover { border-color: #0b3aa4; color: #0b3aa4; background: rgba(11,58,164,0.04); }
.lp-btn-view {}
.lp-btn-approve { background: rgba(11,58,164,0.10); color: #0b3aa4; border-color: rgba(11,58,164,0.25); }
.lp-btn-approve:hover { background: #0b3aa4; color: white; border-color: #0b3aa4; }
.lp-btn-reject { background: rgba(247,92,108,0.10); color: #f75c6c; border-color: rgba(247,92,108,0.25); }
.lp-btn-reject:hover { background: #f75c6c; color: white; border-color: #f75c6c; }
.lp-btn-edit { background: rgba(11,58,164,0.08); color: #0b3aa4; border-color: rgba(11,58,164,0.20); }
.lp-btn-edit:hover { background: #0b3aa4; color: white; border-color: #0b3aa4; }

/* Icon-only buttons (compact, sempre visibili) */
.lp-ibtn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: white;
    color: var(--ink-2);
    cursor: pointer;
    font-family: inherit;
    padding: 0;
    transition: all .12s ease;
    flex-shrink: 0;
    text-decoration: none;
}
.lp-ibtn:hover { border-color: #0b3aa4; color: #0b3aa4; background: rgba(11,58,164,0.04); text-decoration: none; }
.lp-ibtn.lp-btn-approve { background: rgba(11,58,164,0.10); color: #0b3aa4; border-color: rgba(11,58,164,0.25); }
.lp-ibtn.lp-btn-approve:hover { background: #0b3aa4; color: white; border-color: #0b3aa4; }
.lp-ibtn.lp-btn-reject { background: rgba(247,92,108,0.10); color: #f75c6c; border-color: rgba(247,92,108,0.25); }
.lp-ibtn.lp-btn-reject:hover { background: #f75c6c; color: white; border-color: #f75c6c; }
.lp-ibtn.lp-btn-edit { background: rgba(11,58,164,0.08); color: #0b3aa4; border-color: rgba(11,58,164,0.20); }
.lp-ibtn.lp-btn-edit:hover { background: #0b3aa4; color: white; border-color: #0b3aa4; }

/* Inline form senza margin per non rompere flex */
.lp-actions form { margin: 0; padding: 0; display: inline-flex; }

/* Su tablet stretto, hide secondary actions per evitare scroll */
@media (max-width: 1200px) {
    .lp-actions .lp-btn-view[href*="download"] { display: none; }
}

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

/* Hero button */
.lp-hero-btn {
    background: #0b3aa4 !important;
    border: 1px solid #0b3aa4 !important;
    color: white !important;
    backdrop-filter: blur(10px);
    padding: 12px 20px !important;
    border-radius: 10px !important;
    font-weight: 600 !important;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px;
    font-family: inherit;
}
.lp-hero-btn:hover { background: #082b7b !important; color: white !important; }

/* Modale admin-create */
.acl-modal {
    position: fixed; inset: 0;
    background: rgba(15,23,42,0.55);
    display: none;
    align-items: center; justify-content: center;
    z-index: 1000;
    padding: 20px;
    backdrop-filter: blur(4px);
}
.acl-modal.open { display: flex; }
.acl-modal-box {
    background: white;
    border-radius: 14px;
    width: 100%;
    max-width: 640px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 24px 64px -16px rgba(15,23,42,0.4);
    animation: aclSlide .2s ease-out;
}
@keyframes aclSlide {
    from { transform: translateY(20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
.acl-modal-h {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 22px;
    border-bottom: 1px solid var(--border);
}
.acl-modal-h h3 {
    margin: 0;
    font-family: 'Host Grotesk', sans-serif;
    font-size: 17px; font-weight: 600;
    letter-spacing: -0.01em;
    color: var(--ink);
}
.acl-modal-close {
    background: transparent; border: 0;
    width: 32px; height: 32px;
    border-radius: 8px;
    cursor: pointer;
    color: var(--muted);
    display: inline-flex; align-items: center; justify-content: center;
}
.acl-modal-close:hover { background: var(--slate-100, #f1f5f9); color: var(--ink); }
.acl-modal-sub {
    padding: 12px 22px 0;
    margin: 0;
    font-size: 13px;
    color: var(--muted);
}

/* Form admin-create inserimento manuale (ora dentro modale) */
.admin-create-leave {
    background: linear-gradient(135deg, rgba(11,58,164,0.04) 0%, rgba(79,161,255,0.06) 100%);
    border-radius: 12px; padding: 0;
    border: 1px dashed #93c5fd;
}
.admin-create-leave > summary {
    display: flex; align-items: center; gap: 0.85rem;
    padding: 1rem 1.2rem; font-weight: 600; color: var(--ink);
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
    background: #0b3aa4; color: #fff; border-radius: 10px;
    box-shadow: 0 2px 6px rgba(11,58,164,0.35);
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
    color: #0b3aa4; transition: transform .2s ease;
}
.admin-create-leave[open] .acl-toggle-chevron { transform: rotate(180deg); }
.admin-create-leave[open] { background: #ffffff; }
.admin-create-leave[open] .acl-toggle-icon { background: #0b3aa4; box-shadow: 0 2px 6px rgba(21,128,61,0.35); }
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

<div class="welcome-card lp-hero">
    <div>
        <h2>Ferie e permessi</h2>
        <p>Approva, rifiuta o crea manualmente richieste di ferie, permessi e malattia.</p>
        <?php if ($__lrPending > 0): ?>
            <p style="margin-top: 6px;"><strong style="color:#d97706;">Hai <?= $__lrPending ?> richiest<?= $__lrPending === 1 ? 'a' : 'e' ?> in attesa di approvazione.</strong></p>
        <?php else: ?>
            <p style="margin-top: 6px;"><strong style="color:#0c8a8a;">Tutto in ordine, nessuna richiesta pending.</strong></p>
        <?php endif; ?>
    </div>
    <button type="button" class="btn btn-lg lp-hero-btn" onclick="document.getElementById('aclModal').classList.add('open')">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
        Inserisci richiesta manuale
    </button>
</div>

<div class="lp">
    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <div class="acl-modal" id="aclModal" onclick="if(event.target===this)this.classList.remove('open')">
      <div class="acl-modal-box">
        <div class="acl-modal-h">
            <h3>Inserisci richiesta manuale</h3>
            <button type="button" class="acl-modal-close" onclick="document.getElementById('aclModal').classList.remove('open')" aria-label="Chiudi">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <p class="acl-modal-sub">Ferie, permessi, malattia o chiusura aziendale — viene già approvata.</p>
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
      </div>
    </div>

    <?php if (!empty($__sickLate)): ?>
    <div class="lr-admin-sick-alert">
        <div class="lr-admin-sick-alert-ic">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
        <div class="lr-admin-sick-alert-body">
            <strong><?= count($__sickLate) ?> malatti<?= count($__sickLate) === 1 ? 'a' : 'e' ?> senza protocollo o certificato da più di 24 ore</strong>
            <div>
                <?php
                $__names = array_slice(array_map(fn($r) => trim($r['last_name'] . ' ' . $r['first_name']), $__sickLate), 0, 4);
                echo e(implode(', ', $__names));
                if (count($__sickLate) > 4) echo ' + ' . (count($__sickLate) - 4) . ' altr' . (count($__sickLate) - 4 === 1 ? 'o' : 'i');
                ?>
                · contatta il dipendente per sollecito.
            </div>
        </div>
    </div>
    <style>
    .lr-admin-sick-alert {
        display: flex; align-items: flex-start; gap: 12px;
        background: #fef2f2;
        border: 1px solid #fecaca; border-left: 4px solid #dc2626;
        border-radius: 12px;
        padding: 12px 16px;
        margin: 0 0 14px;
    }
    .lr-admin-sick-alert-ic {
        width: 36px; height: 36px; border-radius: 9px;
        background: rgba(220,38,38,0.10); color: #b91c1c;
        display: inline-flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .lr-admin-sick-alert-body strong { color: #991b1b; font-size: 13.5px; }
    .lr-admin-sick-alert-body div { color: #7f1d1d; font-size: 12px; margin-top: 2px; line-height: 1.4; }
    </style>
    <?php endif; ?>

    <form method="GET" class="lp-filters">
        <div class="cd-tabs">
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
                <a href="?status=<?= $key ?><?= $deptParam ?>" class="cd-tab <?= $filterStatus === $key ? 'active' : '' ?>">
                    <?= $tab['label'] ?><?php if ($tab['count']): ?><span class="cd-tab-badge"><?= $tab['count'] ?></span><?php endif; ?>
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
                            <?php if ($req['leave_type'] === 'malattia'):
                                $__missing = empty($req['protocol_number']) || empty($req['certificate_path']);
                                $__ageH = !empty($req['created_at']) ? (int) ((time() - strtotime($req['created_at'])) / 3600) : 0;
                            ?>
                                <?php if (!empty($req['protocol_number'])): ?>
                                    <span class="lp-sick-meta" title="Numero protocollo">Prot. <?= e($req['protocol_number']) ?></span>
                                <?php endif; ?>
                                <?php if ($__missing): ?>
                                    <span class="lp-sick-missing <?= $__ageH >= 24 ? 'is-late' : '' ?>" title="<?= $__ageH >= 24 ? 'Mancano documenti da oltre 24h' : 'Documenti malattia non ancora caricati' ?>">
                                        <?= $__ageH >= 24 ? '⚠ doc. mancanti da ' . max(1,(int)floor($__ageH/24)) . 'g' : 'doc. in attesa' ?>
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
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
                                <button type="button" class="lp-ibtn lp-btn-view js-detail" title="Dettagli" data-detail="<?= htmlspecialchars(json_encode($detailPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                </button>
                                <?php if (!empty($req['attachment_path'])): ?>
                                    <a class="lp-ibtn lp-btn-view" href="?download_attachment=<?= (int) $req['id'] ?>" title="Scarica allegato">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($req['certificate_path'])): ?>
                                    <a class="lp-ibtn lp-btn-view" href="?download_cert=<?= (int) $req['id'] ?>" title="Scarica certificato medico">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
                                    </a>
                                <?php endif; ?>
                                <button type="button" class="lp-ibtn lp-btn-edit js-edit" title="Modifica" data-edit="<?= htmlspecialchars(json_encode($editPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </button>
                                <form method="POST" onsubmit="return confirm('Eliminare definitivamente questa richiesta? L\'operazione non e\' reversibile.');">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="admin_delete">
                                    <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                                    <button type="submit" class="lp-ibtn lp-btn-reject" title="Elimina richiesta">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                                    </button>
                                </form>
                                <?php if ($req['status'] === 'pending'): ?>
                                    <form method="POST">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                        <button type="submit" class="lp-ibtn lp-btn-approve" title="Approva" onclick="return confirm('Approvare?')">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                        </button>
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

// ESC chiude tutti i modali
document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') {
        document.getElementById('aclModal')?.classList.remove('open');
        hideEdit();
    }
});
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
