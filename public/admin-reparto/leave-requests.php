<?php
/**
 * Gestione Richieste Ferie/Permessi - Admin Reparto
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin_reparto');

$user = Auth::getUser();
$departmentId = $user['department_id'] ?? null;

if (!$departmentId) {
    echo '<div style="padding: 2rem; text-align: center;">';
    echo '<h2>Nessun reparto assegnato</h2>';
    echo '<p>Contatta l\'amministratore per essere assegnato a un reparto.</p>';
    echo '</div>';
    exit;
}

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

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    $action = $_POST['action'] ?? '';
    $requestId = (int) ($_POST['request_id'] ?? 0);

    if ($requestId) {
        // Verifica che la richiesta appartenga a un dipendente del reparto
        $request = LeaveRequest::getById($requestId);
        if ($request && $request['department_id'] == $departmentId) {
            switch ($action) {
                case 'approve':
                    $result = LeaveRequest::approve($requestId, $user['id']);
                    if ($result['success']) {
                        header('Location: leave-requests.php?message=approved');
                        exit;
                    }
                    $error = $result['error'];
                    break;

                case 'reject':
                    $reason = $_POST['rejection_reason'] ?? '';
                    $result = LeaveRequest::reject($requestId, $user['id'], $reason);
                    if ($result['success']) {
                        header('Location: leave-requests.php?message=rejected');
                        exit;
                    }
                    $error = $result['error'];
                    break;

                case 'admin_sick_docs':
                    $protocol = $_POST['protocol_number'] ?? null;
                    $cert = $_FILES['certificate'] ?? null;
                    $result = LeaveRequest::adminSaveSickDocs($requestId, $protocol, $cert);
                    if ($result['success']) { header('Location: leave-requests.php?message=sick_docs'); exit; }
                    $error = $result['error'];
                    break;

                case 'admin_waive_cert':
                    $waived = !empty($_POST['waived']);
                    $result = LeaveRequest::setCertificateWaived($requestId, $waived);
                    if ($result['success']) {
                        header('Location: leave-requests.php?message=' . ($waived ? 'cert_waived' : 'cert_required'));
                        exit;
                    }
                    $error = $result['error'];
                    break;
            }
        } else {
            $error = 'Richiesta non trovata o non appartenente al tuo reparto';
        }
    }
}

// Messaggi
if (isset($_GET['message'])) {
    $messages = [
        'approved'      => 'Richiesta approvata con successo',
        'rejected'      => 'Richiesta rifiutata',
        'sick_docs'     => 'Documenti malattia aggiornati',
        'cert_waived'   => 'Certificato segnato come non richiesto',
        'cert_required' => 'Certificato di nuovo obbligatorio',
    ];
    $message = $messages[$_GET['message']] ?? '';
}

// Filtri
$filterStatus = $_GET['status'] ?? 'pending';

// Carica richieste
$requests = LeaveRequest::getByDepartment($departmentId, $filterStatus !== 'all' ? $filterStatus : null);

$department = Department::getById($departmentId);
$pageTitle = 'Ferie e Permessi - ' . htmlspecialchars($department['name']);
include dirname(__DIR__) . '/includes/header-admin-reparto.php';
include dirname(__DIR__) . '/includes/_cd-tabs.inc.php';

// Sollecito documenti malattia del proprio reparto (>24h)
try {
    $__sickLate = array_values(array_filter(
        LeaveRequest::sickPendingDocs(24),
        fn($r) => (int)($r['department_id'] ?? 0) === (int)$departmentId
    ));
} catch (Throwable $e) { $__sickLate = []; }
?>

<style>
.leave-page {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

/* Filters */
.filters-section {
    background: white;
    border-radius: 10px;
    padding: 1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.filters-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: center;
}

.filter-tabs {
    display: flex;
    gap: 0.5rem;
}

.filter-tab {
    padding: 0.5rem 1rem;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    background: white;
    color: #4a5568;
    font-size: 0.85rem;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
}

.filter-tab:hover {
    background: #f7fafc;
}

.filter-tab.active {
    background: var(--primary-color);
    color: white;
    border-color: var(--primary-color);
}

.filter-tab .badge {
    background: rgba(255,255,255,0.3);
    padding: 0.1rem 0.4rem;
    border-radius: 10px;
    font-size: 0.7rem;
    margin-left: 0.35rem;
}

/* Requests List */
.requests-section {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.requests-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #edf2f7;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.requests-header h2 {
    margin: 0;
    font-size: 1rem;
    color: #2d3748;
}

.requests-count {
    font-size: 0.75rem;
    color: #718096;
    background: #edf2f7;
    padding: 0.25rem 0.6rem;
    border-radius: 10px;
}

.request-card {
    padding: 1.25rem;
    border-bottom: 1px solid #f7fafc;
}

.request-card.is-missing-docs {
    background: #fef2f2;
    border-left: 4px solid #dc2626;
}

.request-card:last-child {
    border-bottom: none;
}

.request-card-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 0.75rem;
}

.request-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: #667eea;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 1rem;
    flex-shrink: 0;
}

.request-employee {
    flex: 1;
}

.request-employee-name {
    font-weight: 600;
    color: #2d3748;
    font-size: 1rem;
}

.request-employee-fc {
    font-size: 0.75rem;
    color: #a0aec0;
    font-family: monospace;
}

.request-type-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 15px;
    font-size: 0.75rem;
    font-weight: 600;
}

.request-type-badge.ferie { background: #d4edda; color: #155724; }
.request-type-badge.permesso { background: #cce5ff; color: #004085; }
.request-type-badge.malattia { background: #f8d7da; color: #721c24; }
.request-type-badge.permesso_104 { background: #e2d5f1; color: #563d7c; }
.request-type-badge.congedo_parentale { background: #fce4ec; color: #880e4f; }
.request-type-badge.congedo_separazione { background: #edf2f7; color: #2d3748; }
.request-type-badge.congedo_mestruale { background: #ffe0e6; color: #b83a4b; }
.request-type-badge.altro { background: #e2e8f0; color: #4a5568; }

.request-status-badge {
    padding: 0.35rem 0.75rem;
    border-radius: 15px;
    font-size: 0.75rem;
    font-weight: 600;
}

.request-status-badge.pending { background: #fef3cd; color: #856404; }
.request-status-badge.approved { background: #d4edda; color: #155724; }
.request-status-badge.rejected { background: #f8d7da; color: #721c24; }

.request-details {
    background: #f7fafc;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 0.75rem;
}

.request-detail-row {
    display: flex;
    gap: 2rem;
    margin-bottom: 0.5rem;
}

.request-detail-row:last-child {
    margin-bottom: 0;
}

.request-detail {
    display: flex;
    flex-direction: column;
}

.request-detail-label {
    font-size: 0.65rem;
    color: #a0aec0;
    text-transform: uppercase;
    font-weight: 600;
}

.request-detail-value {
    font-size: 0.85rem;
    color: #2d3748;
}

.request-reason {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 0.75rem;
    font-size: 0.85rem;
    color: #4a5568;
    margin-top: 0.5rem;
}

.request-actions {
    display: flex;
    gap: 0.75rem;
    margin-top: 1rem;
}

.request-actions .btn {
    flex: 1;
}

/* Rejection Modal */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal-overlay.show {
    display: flex;
}

.modal {
    background: white;
    border-radius: 10px;
    padding: 1.5rem;
    width: 90%;
    max-width: 400px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
}

.modal h3 {
    margin: 0 0 1rem;
    color: #2d3748;
}

.modal textarea {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 0.9rem;
    resize: vertical;
}

.modal-actions {
    display: flex;
    gap: 0.75rem;
    margin-top: 1rem;
}

.modal-actions .btn {
    flex: 1;
}

.empty-state {
    text-align: center;
    padding: 3rem;
    color: #a0aec0;
}

.empty-state svg {
    width: 48px;
    height: 48px;
    margin-bottom: 0.75rem;
    opacity: 0.5;
}

@media (max-width: 768px) {
    .filter-tabs {
        width: 100%;
        overflow-x: auto;
    }

    .request-card-header {
        flex-wrap: wrap;
    }

    .request-detail-row {
        flex-direction: column;
        gap: 0.5rem;
    }

    .request-actions {
        flex-direction: column;
    }
}
</style>

<div class="leave-page">
    <?php if ($message): ?>
        <div class="alert alert-success"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($__sickLate)): ?>
    <div class="lr-admin-sick-alert">
        <div class="lr-admin-sick-alert-ic">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
        <div class="lr-admin-sick-alert-body">
            <strong><?= count($__sickLate) ?> malatti<?= count($__sickLate) === 1 ? 'a' : 'e' ?> del reparto senza protocollo o certificato da oltre 24 ore</strong>
            <div>
                <?php
                $__names = array_slice(array_map(fn($r) => trim($r['last_name'] . ' ' . $r['first_name']), $__sickLate), 0, 4);
                echo e(implode(', ', $__names));
                if (count($__sickLate) > 4) echo ' + ' . (count($__sickLate) - 4) . ' altr' . (count($__sickLate) - 4 === 1 ? 'o' : 'i');
                ?>
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

    <!-- Filtri -->
    <div class="filters-section">
        <div class="filters-row">
            <div class="cd-tabs">
                <?php
                $pendingCount = LeaveRequest::countPendingByDepartment($departmentId);
                $tabs = [
                    'pending' => ['label' => 'In Attesa', 'count' => $pendingCount],
                    'approved' => ['label' => 'Approvate', 'count' => null],
                    'rejected' => ['label' => 'Rifiutate', 'count' => null],
                    'all' => ['label' => 'Tutte', 'count' => null]
                ];
                foreach ($tabs as $key => $tab):
                ?>
                    <a href="?status=<?= $key ?>"
                       class="cd-tab <?= $filterStatus === $key ? 'active' : '' ?>">
                        <?= $tab['label'] ?>
                        <?php if ($tab['count']): ?>
                            <span class="cd-tab-badge"><?= $tab['count'] ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Lista Richieste -->
    <div class="requests-section">
        <div class="requests-header">
            <h2>Richieste <?= LeaveRequest::STATUSES[$filterStatus] ?? '' ?></h2>
            <span class="requests-count"><?= count($requests) ?> richieste</span>
        </div>

        <?php if (empty($requests)): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/>
                </svg>
                <p>Nessuna richiesta trovata</p>
            </div>
        <?php else: ?>
            <?php foreach ($requests as $req):
                $workingDays = LeaveRequest::calculateWorkingDays($req['start_date'], $req['end_date']);
                $__cardMissing = $req['status'] === 'pending' && $req['leave_type'] === 'malattia' && (
                    empty($req['protocol_number']) || (empty($req['certificate_path']) && empty($req['certificate_waived']))
                );
            ?>
                <div class="request-card <?= $__cardMissing ? 'is-missing-docs' : '' ?>">
                    <div class="request-card-header">
                        <?= employeeAvatarHtml($req, 'request-avatar') ?>
                        <div class="request-employee">
                            <div class="request-employee-name">
                                <?= e($req['last_name'] . ' ' . $req['first_name']) ?>
                            </div>
                            <div class="request-employee-fc"><?= e($req['fiscal_code']) ?></div>
                        </div>
                        <span class="request-type-badge <?= $req['leave_type'] ?>">
                            <?= e(LeaveRequest::LEAVE_TYPES[$req['leave_type']] ?? $req['leave_type']) ?>
                        </span>
                        <span class="request-status-badge <?= $req['status'] ?>">
                            <?= e(LeaveRequest::STATUSES[$req['status']] ?? $req['status']) ?>
                        </span>
                    </div>

                    <div class="request-details">
                        <div class="request-detail-row">
                            <div class="request-detail">
                                <span class="request-detail-label">Data Inizio</span>
                                <span class="request-detail-value"><?= formatDate($req['start_date']) ?></span>
                            </div>
                            <div class="request-detail">
                                <span class="request-detail-label">Data Fine</span>
                                <span class="request-detail-value"><?= formatDate($req['end_date']) ?></span>
                            </div>
                            <div class="request-detail">
                                <span class="request-detail-label">Giorni Lavorativi</span>
                                <span class="request-detail-value"><?= $workingDays ?></span>
                            </div>
                            <?php if (!$req['is_full_day'] && $req['start_time']): ?>
                                <div class="request-detail">
                                    <span class="request-detail-label">Orario</span>
                                    <span class="request-detail-value">
                                        <?= substr($req['start_time'], 0, 5) ?> - <?= substr($req['end_time'], 0, 5) ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="request-detail-row">
                            <div class="request-detail">
                                <span class="request-detail-label">Richiesta il</span>
                                <span class="request-detail-value"><?= formatDateTime($req['created_at']) ?></span>
                            </div>
                            <?php if ($req['approved_by_name']): ?>
                                <div class="request-detail">
                                    <span class="request-detail-label"><?= $req['status'] === 'approved' ? 'Approvata da' : 'Gestita da' ?></span>
                                    <span class="request-detail-value"><?= e($req['approved_by_name']) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="request-reason">
                            <strong>Motivazione:</strong> <?= e($req['reason']) ?>
                        </div>
                        <?php if ($req['notes']): ?>
                            <div class="request-reason">
                                <strong>Note:</strong> <?= e($req['notes']) ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($req['rejection_reason']): ?>
                            <div class="request-reason" style="border-color: #f56565; background: #fff5f5;">
                                <strong style="color: #c53030;">Motivo rifiuto:</strong> <?= e($req['rejection_reason']) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($req['attachment_path'])): ?>
                            <div style="margin-top:.75rem;">
                                <a href="?download_attachment=<?= (int) $req['id'] ?>" class="btn btn-sm btn-info">
                                    Scarica allegato<?php if (!empty($req['attachment_name'])): ?>: <?= e($req['attachment_name']) ?><?php endif; ?>
                                </a>
                            </div>
                        <?php endif; ?>
                        <?php if ($req['leave_type'] === 'malattia'):
                            $__hasProto = !empty($req['protocol_number']);
                            $__hasCert  = !empty($req['certificate_path']);
                            $__waived   = !empty($req['certificate_waived']);
                            $__missingDocs = !$__hasProto || (!$__hasCert && !$__waived);
                            $__ageH = !empty($req['created_at']) ? (int) ((time() - strtotime($req['created_at'])) / 3600) : 0;
                        ?>
                            <div style="margin-top:.75rem; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                                <?php if ($__hasProto): ?>
                                    <span style="font-size:11px; font-weight:600; color:#475569; background:#f1f5f9; padding:3px 10px; border-radius:999px;">Prot. <?= e($req['protocol_number']) ?></span>
                                <?php endif; ?>
                                <?php if ($__hasCert): ?>
                                    <a href="?download_cert=<?= (int) $req['id'] ?>" class="btn btn-sm btn-info">Scarica certificato</a>
                                <?php endif; ?>
                                <?php if ($__waived): ?>
                                    <span style="font-size:11px; font-weight:600; color:#1e3a8a; background:#e0e7ff; padding:3px 10px; border-radius:999px;">Certificato non richiesto</span>
                                <?php endif; ?>
                                <?php if ($__missingDocs): ?>
                                    <span style="font-size:11px; font-weight:700; padding:3px 10px; border-radius:999px; <?= $__ageH >= 24 ? 'color:#991b1b; background:#fee2e2; border:1px solid #fecaca;' : 'color:#92400e; background:#fef3c7; border:1px solid #fde68a;' ?>">
                                        <?= $__ageH >= 24 ? '⚠ doc. mancanti da ' . max(1,(int)floor($__ageH/24)) . 'g' : 'doc. in attesa' ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <details style="margin-top:.75rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding: 10px 12px;">
                                <summary style="cursor:pointer; font-size:12.5px; font-weight:600; color:#0b3aa4;">Gestisci documenti malattia</summary>
                                <form method="POST" enctype="multipart/form-data" style="margin-top:10px;">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="admin_sick_docs">
                                    <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                                        <div>
                                            <label style="display:block; font-size:11px; font-weight:600; color:#475569; margin-bottom:4px;">Numero protocollo</label>
                                            <input type="text" name="protocol_number" maxlength="100" placeholder="<?= $__hasProto ? 'Attuale: ' . e($req['protocol_number']) : 'Es. 1234567890' ?>" style="width:100%; padding:7px 10px; border:1px solid #e2e8f0; border-radius:6px; font-size:13px;">
                                        </div>
                                        <div>
                                            <label style="display:block; font-size:11px; font-weight:600; color:#475569; margin-bottom:4px;">Certificato medico</label>
                                            <input type="file" name="certificate" accept=".pdf,.jpg,.jpeg,.png" style="width:100%; font-size:12px;">
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-primary" style="margin-top:10px;">Salva documenti</button>
                                </form>

                                <form method="POST" style="margin-top:10px; padding-top:10px; border-top:1px solid #e2e8f0;">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="admin_waive_cert">
                                    <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">
                                    <input type="hidden" name="waived" value="<?= $__waived ? '0' : '1' ?>">
                                    <button type="submit" class="btn btn-sm" style="<?= $__waived ? 'background:#e0e7ff; color:#1e3a8a; border:1px solid #c7d2fe;' : 'background:#fef3c7; color:#92400e; border:1px solid #fde68a;' ?>">
                                        <?= $__waived ? 'Ripristina obbligo certificato' : 'Marca certificato come non richiesto' ?>
                                    </button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </div>

                    <?php if ($req['status'] === 'pending'):
                        $__blockApprove = false;
                        if ($req['leave_type'] === 'malattia') {
                            $__blockApprove = empty($req['protocol_number'])
                                || (empty($req['certificate_path']) && empty($req['certificate_waived']));
                        }
                    ?>
                        <div class="request-actions">
                            <?php if ($__blockApprove): ?>
                                <button type="button" class="btn btn-success" style="flex:1; opacity:0.5; cursor:not-allowed;" disabled
                                        title="Documenti malattia mancanti — la richiesta resta in attesa">
                                    🔒 Approva
                                </button>
                            <?php else: ?>
                                <form method="POST" style="flex:1; margin:0;">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                    <button type="submit" class="btn btn-success" style="width:100%;"
                                            onclick="return confirm('Approvare questa richiesta?')">
                                        Approva
                                    </button>
                                </form>
                            <?php endif; ?>
                            <button type="button" class="btn btn-danger" style="flex:1;"
                                    onclick="showRejectModal(<?= $req['id'] ?>)">
                                Rifiuta
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Rifiuto -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal">
        <h3>Rifiuta Richiesta</h3>
        <form method="POST" id="rejectForm">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="request_id" id="rejectRequestId">
            <label style="display:block; margin-bottom:0.5rem; font-size:0.85rem; color:#4a5568;">
                Motivo del rifiuto (opzionale)
            </label>
            <textarea name="rejection_reason" rows="3"
                      placeholder="Inserisci il motivo del rifiuto..."></textarea>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="hideRejectModal()">
                    Annulla
                </button>
                <button type="submit" class="btn btn-danger">
                    Conferma Rifiuto
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function showRejectModal(requestId) {
    document.getElementById('rejectRequestId').value = requestId;
    document.getElementById('rejectModal').classList.add('show');
}

function hideRejectModal() {
    document.getElementById('rejectModal').classList.remove('show');
}

// Chiudi modal cliccando fuori
document.getElementById('rejectModal').addEventListener('click', function(e) {
    if (e.target === this) {
        hideRejectModal();
    }
});
</script>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
